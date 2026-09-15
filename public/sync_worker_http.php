<?php
/**
 * HTTP-воркер синхронизации с 1С:ГОА.
 * Отдаёт клиенту 1x1 GIF сразу (fastcgi_finish_request), продолжает в фоне.
 *
 * Режимы:
 *   ?type=works|nomenclature|all  — синхронизация
 *   ?dry=works                    — БЕЗ записи в БД, только показать, что распарсилось
 */

set_time_limit(0);
ignore_user_abort(true);
ini_set('memory_limit', '512M');

require __DIR__ . '/db.php';
start_session();

$user = current_user();
$type = $_GET['type'] ?? '';
$dry  = $_GET['dry'] ?? '';

$GIF = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');

// Режим dry — отдаём HTML, а не GIF
if ($dry === '') {
    header('Content-Type: image/gif');
    header('Content-Length: ' . strlen($GIF));
    header('Cache-Control: no-store');
    header('Connection: close');
    if (function_exists('fastcgi_finish_request')) {
        echo $GIF;
        fastcgi_finish_request();
    } else {
        echo $GIF;
        @ob_end_flush();
        @flush();
    }
}

if (!$user || !$user['is_admin']) exit;

function soap_action(string $op): string {
    return 'http://1c.kamaz.ru/zakaz#Zakaz:' . $op;
}

function onec_call(string $operation, array $params = []): string {
    $login    = getenv('ONEC_SOAP_LOGIN')    ?: getenv('ONEC_LOGIN');
    $password = getenv('ONEC_SOAP_PASSWORD') ?: getenv('ONEC_PASSWORD');
    if (!$login || !$password) throw new RuntimeException('Не заданы ONEC_LOGIN / ONEC_PASSWORD');
    $endpoint = getenv('ONEC_SOAP_URL') ?: 'https://web-1c.kamaz.ru/GOA/ws/Zakaz';
    $ns = 'http://1c.kamaz.ru/zakaz';
    $xsi = 'http://www.w3.org/2001/XMLSchema-instance';
    $paramsXml = '';
    foreach ($params as $k => $v) {
        $paramsXml .= ($v === null)
            ? '<zak:' . $k . ' xsi:nil="true"/>'
            : '<zak:' . $k . '>' . htmlspecialchars((string)$v, ENT_XML1) . '</zak:' . $k . '>';
    }
    $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:zak="' . $ns . '" xmlns:xsi="' . $xsi . '">'
        . '<soapenv:Header/><soapenv:Body><zak:' . $operation . '>' . $paramsXml . '</zak:' . $operation . '></soapenv:Body></soapenv:Envelope>';
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $envelope,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => $login . ':' . $password,
        CURLOPT_TIMEOUT => 300, CURLOPT_CONNECTTIMEOUT => 20,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: "' . soap_action($operation) . '"'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if ($curlErr) throw new RuntimeException('Ошибка соединения: ' . $curlErr);
    if ($httpCode === 401 || $httpCode === 402) throw new RuntimeException('Неверный логин/пароль (HTTP ' . $httpCode . ')');
    if ($httpCode === 403) throw new RuntimeException('Нет прав на операцию ' . $operation);
    if ($httpCode !== 200) throw new RuntimeException('1С вернул код ' . $httpCode);
    return (string)$response;
}

function cut(?string $s, int $max): ?string {
    if ($s === null) return null;
    return mb_substr($s, 0, $max, 'UTF-8');
}

function date_tz(): string { return getenv('ONEC_DATE_TZ') ?: '+05:00'; }

/**
 * Парсер через SimpleXML с использованием XPath (local-name игнорирует namespace).
 */
function parse_works_simplexml(string $xml): array {
    if (!function_exists('simplexml_load_string')) return [];
    $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
    $prev = libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$sx) return [];

    $containers = $sx->xpath('//*[local-name()="WorkOperations"]');
    if (!$containers) return [];

    $items = [];
    foreach ($containers as $container) {
        // Прямые дети через xpath (игнорирует namespace)
        $children = $container->xpath('./*');
        if (!$children) continue;

        foreach ($children as $n) {
            $get = function(string $tag) use ($n) {
                $found = $n->xpath('./*[local-name()="' . $tag . '"]');
                if (!$found || !isset($found[0])) return null;
                $v = trim((string)$found[0]);
                return $v === '' ? null : $v;
            };

            $code = $get('Code');
            if ($code === null) continue;

            // Вложенный <Parent><Code>...</Code></Parent> — если есть
            $parentCode = null;
            $parentNodes = $n->xpath('./*[local-name()="Parent"]');
            if ($parentNodes && isset($parentNodes[0])) {
                $pc = $parentNodes[0]->xpath('./*[local-name()="Code"]');
                if ($pc && isset($pc[0])) {
                    $pcVal = trim((string)$pc[0]);
                    if ($pcVal !== '' && $pcVal !== $code) $parentCode = $pcVal;
                }
            }

            $items[] = [
                'code'           => cut($code, 50),
                'parent_code'    => cut($parentCode, 50),
                'it_is_group'    => strtolower($get('ItIsGroup') ?? 'false') === 'true',
                'name'           => cut($get('Name'), 250),
                'operation_code' => cut($get('OperationCode'), 50),
                'name_work'      => cut($get('NameWork'), 250),
                'eng_name'       => cut($get('EngName'), 250),
                'description'    => cut($get('Description'), 5000),
                'guard_work'     => strtolower($get('GuardWork') ?? 'false') === 'true',
                'fact_work'      => strtolower($get('FactWork') ?? 'false') === 'true',
                'deleted'        => strtolower($get('Deleted') ?? 'false') === 'true',
            ];
        }
    }
    return $items;
}

/**
 * Вычисляет parent_code для групп и работ на основе кодов.
 */
function compute_parents(array $items): array {
    // Собираем все коды групп
    $groupCodes = [];
    foreach ($items as $item) {
        if (!empty($item['it_is_group'])) {
            $groupCodes[$item['code']] = true;
        }
    }

    foreach ($items as &$item) {
        // Если parent уже вычислен (вложенный Parent был) — не трогаем
        if (!empty($item['parent_code'])) continue;

        if (!empty($item['it_is_group'])) {
            // Группа: родитель = код без последних 2 цифр
            $code = $item['code'];
            if (preg_match('/^\d+$/', $code) && strlen($code) > 2) {
                $candidate = substr($code, 0, -2);
                if (isset($groupCodes[$candidate])) {
                    $item['parent_code'] = $candidate;
                }
            }
        } else {
            // Работа: префикс кода операции = группа
            $opc = $item['operation_code'] ?? '';
            if ($opc && preg_match('/^[A-Za-zА-Яа-я]*?(\d{2})/u', $opc, $m)) {
                $candidate = $m[1];
                if (isset($groupCodes[$candidate])) {
                    $item['parent_code'] = $candidate;
                }
            }
        }
    }
    return $items;
}

// ========== DRY-режим — показать что распарсилось ==========
if ($dry === 'works') {
    header('Content-Type: text/html; charset=utf-8');
    try {
        $xml = onec_call('UnloadWorkOperations', [
            'OperationCode' => null,
            'StartDate' => '2000-01-01' . date_tz(),
            'EndDate'   => '2099-12-31' . date_tz(),
        ]);
        $parsed = parse_works_simplexml($xml);
        $parsed = compute_parents($parsed);

        $groups = array_filter($parsed, fn($x) => $x['it_is_group']);
        $works  = array_filter($parsed, fn($x) => !$x['it_is_group']);

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>Dry-разбор</title>';
        echo '<style>body{font-family:-apple-system,sans-serif;background:#f0f2f5;padding:16px;line-height:1.5}';
        echo '.card{background:#fff;border-radius:12px;padding:16px;margin-bottom:12px;box-shadow:0 2px 12px rgba(0,0,0,0.06)}';
        echo 'h1{font-size:20px;margin:0 0 12px}h2{font-size:16px;margin:12px 0 8px;color:#1e3a8a}';
        echo 'table{width:100%;border-collapse:collapse;font-size:12px}';
        echo 'th{background:#f9fafb;text-align:left;padding:6px 8px;font-size:10px;color:#666;text-transform:uppercase}';
        echo 'td{padding:6px 8px;border-bottom:1px solid #f0f0f0}';
        echo 'code{background:#eff6ff;padding:1px 5px;border-radius:3px;color:#1e3a8a}';
        echo '.err{color:#dc2626}</style></head><body>';

        echo '<div class="card"><h1>🔬 DRY-разбор XML от 1С (без записи в БД)</h1>';
        echo '<p>Длина XML: <b>' . strlen($xml) . '</b> байт</p>';
        echo '<p>SimpleXML распарсил: <b class="' . (empty($parsed) ? 'err' : '') . '">' . (empty($parsed) ? '❌ НЕТ' : '✅ да') . '</b></p>';
        echo '<p>Всего элементов: <b>' . count($parsed) . '</b> '
            . '(групп: <b>' . count($groups) . '</b>, работ: <b>' . count($works) . '</b>)</p>';
        echo '</div>';

        if (!empty($parsed)) {
            echo '<div class="card"><h2>Первые 30 элементов</h2><table>';
            echo '<tr><th>Code</th><th>Parent</th><th>Тип</th><th>Имя</th><th>OperationCode</th></tr>';
            $i = 0;
            foreach ($parsed as $p) {
                if (++$i > 30) break;
                echo '<tr>';
                echo '<td><code>' . htmlspecialchars($p['code']) . '</code></td>';
                echo '<td><code>' . htmlspecialchars($p['parent_code'] ?? '—') . '</code></td>';
                echo '<td>' . ($p['it_is_group'] ? '📁 группа' : '🔧 работа') . '</td>';
                echo '<td>' . htmlspecialchars(mb_substr($p['name'] ?? '', 0, 60)) . '</td>';
                echo '<td>' . htmlspecialchars($p['operation_code'] ?? '—') . '</td>';
                echo '</tr>';
            }
            echo '</table></div>';

            // Примеры работ и их вычисленного родителя
            echo '<div class="card"><h2>Примеры работ → вычисленный родитель</h2><table>';
            echo '<tr><th>Code</th><th>OperationCode</th><th>→ группа</th><th>Имя</th></tr>';
            $i = 0;
            foreach ($works as $w) {
                if (++$i > 30) break;
                echo '<tr>';
                echo '<td><code>' . htmlspecialchars($w['code']) . '</code></td>';
                echo '<td><code>' . htmlspecialchars($w['operation_code'] ?? '—') . '</code></td>';
                echo '<td><code>' . htmlspecialchars($w['parent_code'] ?? '—') . '</code></td>';
                echo '<td>' . htmlspecialchars(mb_substr($w['name'] ?? '', 0, 60)) . '</td>';
                echo '</tr>';
            }
            echo '</table></div>';
        }

        echo '</body></html>';
    } catch (Throwable $e) {
        echo '<div class="card" style="color:#dc2626">❌ Ошибка: ' . htmlspecialchars($e->getMessage()) . '</div>';
    }
    exit(0);
}

// ========== Обычная синхронизация ==========
if (!in_array($type, ['works', 'nomenclature', 'all'], true)) exit;

try {
    $pdoChk = get_db();
    $stmt = $pdoChk->query("SELECT COUNT(*) FROM sync_log WHERE status='running' AND started_at > NOW() - INTERVAL '15 minutes'");
    if ((int)$stmt->fetchColumn() > 0) exit;
} catch (Throwable $e) { /* ignore */ }

function do_sync_works(PDO $pdo): array {
    $xml = onec_call('UnloadWorkOperations', [
        'OperationCode' => null,
        'StartDate' => '2000-01-01' . date_tz(),
        'EndDate'   => '2099-12-31' . date_tz(),
    ]);

    $parsed = parse_works_simplexml($xml);
    if (empty($parsed)) throw new RuntimeException('SimpleXML не смог разобрать ответ');

    $parsed = compute_parents($parsed);

    // === ФИЛЬТРАЦИЯ ===
    $filtered = [];
    $seenOpCodes = [];
    $seenGroupCodes = [];
    $skippedDeleted = 0;
    $skippedDup = 0;

    foreach ($parsed as $p) {
        if (!empty($p['deleted'])) { $skippedDeleted++; continue; }

        if ($p['it_is_group']) {
            if (isset($seenGroupCodes[$p['code']])) continue;
            $seenGroupCodes[$p['code']] = true;
            $filtered[] = $p;
            continue;
        }

        $opc = $p['operation_code'] ?? '';
        if ($opc !== '') {
            if (isset($seenOpCodes[$opc])) { $skippedDup++; continue; }
            $seenOpCodes[$opc] = true;
        }
        $filtered[] = $p;
    }

    $pdo->exec("TRUNCATE work_operations");

    $stmt = $pdo->prepare("
        INSERT INTO work_operations (code, parent_code, it_is_group, name, operation_code, name_work, eng_name, description, guard_work, fact_work, deleted, updated_at)
        VALUES (:code, :parent_code, :it_is_group, :name, :operation_code, :name_work, :eng_name, :description, :guard_work, :fact_work, :deleted, NOW())
    ");
    $total = 0;
    foreach ($filtered as $p) {
        $stmt->execute([
            ':code' => $p['code'], ':parent_code' => $p['parent_code'],
            ':it_is_group' => $p['it_is_group'] ? 1 : 0, ':name' => $p['name'],
            ':operation_code' => $p['operation_code'], ':name_work' => $p['name_work'],
            ':eng_name' => $p['eng_name'], ':description' => $p['description'],
            ':guard_work' => $p['guard_work'] ? 1 : 0, ':fact_work' => $p['fact_work'] ? 1 : 0,
            ':deleted' => 0,
        ]);
        $total++;
    }
    return [
        'total' => $total,
        'message' => 'SimpleXML. Удалено: ' . $skippedDeleted . '. Дублей: ' . $skippedDup,
    ];
}

function do_sync_nomenclature(PDO $pdo): array {
    $inn = getenv('ONEC_INN'); $kpp = getenv('ONEC_KPP');
    if (!$inn || !$kpp) throw new RuntimeException('Нужны ONEC_INN и ONEC_KPP');
    $xml = onec_call('UnloadNomenclatureUpdates', ['INN' => $inn, 'KPP' => $kpp]);
    $parsed = parse_works_simplexml($xml);
    if (empty($parsed)) return ['total' => 0, 'message' => 'SimpleXML 0'];
    $stmt = $pdo->prepare("
        INSERT INTO nomenclatures (code, name, full_name, eng_name, base_measure, code_1c, parent, deleted, updated_at)
        VALUES (:code, :name, :full_name, :eng_name, :base_measure, :code_1c, :parent, :deleted, NOW())
        ON CONFLICT (code) DO UPDATE SET name=EXCLUDED.name, full_name=EXCLUDED.full_name, eng_name=EXCLUDED.eng_name,
            base_measure=EXCLUDED.base_measure, code_1c=EXCLUDED.code_1c, parent=EXCLUDED.parent, deleted=EXCLUDED.deleted, updated_at=NOW()
    ");
    $total = 0;
    foreach ($parsed as $p) {
        $stmt->execute([':code' => $p['code'], ':name' => $p['name'], ':full_name' => null,
            ':eng_name' => $p['eng_name'], ':base_measure' => null, ':code_1c' => null,
            ':parent' => $p['parent_code'], ':deleted' => $p['deleted'] ? 1 : 0]);
        $total++;
    }
    return ['total' => $total, 'message' => 'SimpleXML'];
}

function run_sync(PDO $pdo, string $t, callable $fn): array {
    $stmt = $pdo->prepare("INSERT INTO sync_log (sync_type, status) VALUES (:t, 'running') RETURNING id");
    $stmt->execute([':t' => $t]);
    $logId = (int)$stmt->fetchColumn();
    try {
        $r = $fn($pdo);
        $pdo->prepare("UPDATE sync_log SET status='ok', finished_at=NOW(), items_total=:n, error_message=:m WHERE id=:id")
            ->execute([':n' => $r['total'], ':m' => $r['message'] ?: null, ':id' => $logId]);
        return ['ok' => true, 'total' => $r['total'], 'message' => $r['message']];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE sync_log SET status='error', finished_at=NOW(), error_message=:m WHERE id=:id")
            ->execute([':m' => $e->getMessage(), ':id' => $logId]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

try {
    $pdo = get_db();
    if ($type === 'works') run_sync($pdo, 'works', 'do_sync_works');
    elseif ($type === 'nomenclature') run_sync($pdo, 'nomenclature', 'do_sync_nomenclature');
    else {
        run_sync($pdo, 'works', 'do_sync_works');
        run_sync($pdo, 'nomenclature', 'do_sync_nomenclature');
    }
} catch (Throwable $e) { /* тихо */ }

exit(0);
