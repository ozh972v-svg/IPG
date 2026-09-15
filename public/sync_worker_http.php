<?php
/**
 * HTTP-воркер синхронизации с 1С:ГОА.
 * Построчный парсер — не зависит от libxml, не падает на мусорных символах.
 */

set_time_limit(0);
ignore_user_abort(true);
ini_set('memory_limit', '1024M');

require __DIR__ . '/db.php';
start_session();

$user = current_user();
$type = $_GET['type'] ?? '';
$dry  = $_GET['dry'] ?? '';

$GIF = base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
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

if (!$user || !$user['is_admin']) exit;

function soap_action(string $op): string {
    return 'http://1c.kamaz.ru/zakaz#Zakaz:' . $op;
}

function onec_call(string $operation, array $params = []): string {
    $login    = getenv('ONEC_SOAP_LOGIN')    ?: getenv('ONEC_LOGIN');
    $password = getenv('ONEC_SOAP_PASSWORD') ?: getenv('ONEC_PASSWORD');
    if (!$login || !$password) throw new RuntimeException('Не заданы ONEC_LOGIN / ONEC_PASSWORD');
    $endpoint = getenv('ONEC_SOAP_URL') ?: 'https://web-1c.kamaz.ru/GOA/ws/Zakaz';
    $ns  = 'http://1c.kamaz.ru/zakaz';
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
 * ПОСТРОЧНЫЙ ПАРСЕР. Не использует xml_parser (не падает на мусоре).
 *
 * Структура ответа 1С:
 *   <WorkOperations>
 *     <m:Parent>              ← ЗАПИСЬ (уровень 1)
 *       <m:Parent>            ← ССЫЛКА на родителя (уровень 2)
 *         <m:Code>10</m:Code> ← Code родителя → записываем как _parent_code
 *         ...
 *       </m:Parent>
 *       <m:ItIsGroup>...</m:ItIsGroup>  ← поля САМОЙ записи (уровень 1)
 *       <m:Code>1002</m:Code>
 *       ...
 *     </m:Parent>
 *   </WorkOperations>
 */
function parse_works_lines(string $xml, ?string &$err = null): array {
    // ========== ОЧИСТКА ==========
    $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
    $xml = preg_replace('/<\?xml[^>]*\?>/i', '', $xml, 1);
    // Control-символы (кроме tab/LF/CR)
    $xml = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F]/', '', $xml);
    // BOM в середине, surrogate-пары
    $xml = str_replace("\xEF\xBB\xBF", '', $xml);
    $xml = preg_replace('/\xED[\xA0-\xBF][\x80-\xBF]/', '', $xml);

    // ========== НОРМАЛИЗАЦИЯ ==========
    // Превращаем любой XML в построчный: между > и < ставим перевод строки.
    // Внутри текстовых значений > и < не трогаем, потому что они там редки.
    $xml = preg_replace('/>\s*</', ">\n<", $xml);

    // ========== ПАРСИНГ ==========
    $items = [];
    $current = null;
    $level = 0;
    $lines = explode("\n", $xml);

    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') continue;

        // Открытие <Parent> (с любым namespace-префиксом)
        if (preg_match('~^<(?:[a-zA-Z0-9]+:)?Parent>$~i', $line)) {
            $level++;
            if ($level === 1) {
                $current = [];
            }
            continue;
        }

        // Закрытие </Parent>
        if (preg_match('~^</(?:[a-zA-Z0-9]+:)?Parent>$~i', $line)) {
            if ($level === 1 && $current !== null) {
                if (!empty($current['Code'])) {
                    $items[] = $current;
                }
                $current = null;
            }
            $level--;
            if ($level < 0) $level = 0;
            continue;
        }

        // Поле <m:Tag>значение</m:Tag>
        if ($current !== null
            && preg_match('~^<(?:[a-zA-Z0-9]+:)?([A-Za-z][A-Za-z0-9_]*)(?:\s[^>]*)?>(.*?)</(?:[a-zA-Z0-9]+:)?\1>$~s', $line, $m)
        ) {
            $fieldName = $m[1];
            $value = trim($m[2]);
            if ($value === '') continue;

            if ($level === 1) {
                // Поле самой записи
                $current[$fieldName] = $value;
            } elseif ($level === 2 && $fieldName === 'Code') {
                // Code вложенного Parent = ссылка на настоящего родителя
                if (empty($current['_parent_code'])) {
                    $current['_parent_code'] = $value;
                }
            }
        }
    }

    // ========== НОРМАЛИЗАЦИЯ ==========
    $result = [];
    foreach ($items as $item) {
        $code = trim((string)($item['Code'] ?? ''));
        if ($code === '') continue;

        $parentCode = null;
        if (!empty($item['_parent_code'])) {
            $pc = trim((string)$item['_parent_code']);
            if ($pc !== '' && $pc !== $code) $parentCode = $pc;
        }

        $result[] = [
            'code'           => cut($code, 50),
            'parent_code'    => cut($parentCode, 50),
            'it_is_group'    => strtolower(trim((string)($item['ItIsGroup'] ?? 'false'))) === 'true',
            'name'           => cut(trim((string)($item['Name'] ?? '')) ?: null, 250),
            'operation_code' => cut(trim((string)($item['OperationCode'] ?? '')) ?: null, 50),
            'name_work'      => cut(trim((string)($item['NameWork'] ?? '')) ?: null, 250),
            'eng_name'       => cut(trim((string)($item['EngName'] ?? '')) ?: null, 250),
            'description'    => cut(trim((string)($item['Description'] ?? '')) ?: null, 5000),
            'guard_work'     => strtolower(trim((string)($item['GuardWork'] ?? 'false'))) === 'true',
            'fact_work'      => strtolower(trim((string)($item['FactWork'] ?? 'false'))) === 'true',
            'deleted'        => strtolower(trim((string)($item['Deleted'] ?? 'false'))) === 'true',
        ];
    }

    if (empty($result)) {
        $err = 'Построчный парсер вернул 0 записей (XML ' . number_format(strlen($xml), 0, '.', ' ') . ' байт)';
    }
    return $result;
}

/**
 * Если у элемента parent_code пустой — пробуем вычислить по коду.
 */
function compute_parents(array $items): array {
    $groupCodes = [];
    foreach ($items as $item) {
        if (!empty($item['it_is_group'])) {
            $groupCodes[$item['code']] = true;
        }
    }

    foreach ($items as &$item) {
        if (!empty($item['parent_code'])) continue;

        if (!empty($item['it_is_group'])) {
            $code = $item['code'];
            if (preg_match('/^\d+$/', $code) && strlen($code) > 2) {
                $candidate = substr($code, 0, -2);
                if (isset($groupCodes[$candidate])) {
                    $item['parent_code'] = $candidate;
                }
            }
        } else {
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

// ========== DRY-режим ==========
if ($dry === 'works') {
    $result = ['error' => null, 'parse_error' => null, 'stats' => [], 'items' => [], 'works_sample' => [], 'raw_xml' => ''];
    try {
        $xml = onec_call('UnloadWorkOperations', [
            'OperationCode' => null,
            'StartDate' => '2000-01-01' . date_tz(),
            'EndDate'   => '2099-12-31' . date_tz(),
        ]);
        $result['xml_len'] = strlen($xml);
        $result['raw_xml'] = substr($xml, 0, 100000);

        $parseErr = null;
        $parsed = parse_works_lines($xml, $parseErr);
        $result['simplexml_ok'] = !empty($parsed);
        $result['parse_error']  = $parseErr;

        if (!empty($parsed)) {
            $parsed = compute_parents($parsed);

            $groups = array_filter($parsed, fn($x) => $x['it_is_group']);
            $works  = array_filter($parsed, fn($x) => !$x['it_is_group']);

            $result['stats'] = [
                'total'  => count($parsed),
                'groups' => count($groups),
                'works'  => count($works),
            ];

            $result['items'] = array_slice($parsed, 0, 50);
            $result['works_sample'] = array_slice(array_values($works), 0, 50);
        }
    } catch (Throwable $e) {
        $result['error'] = $e->getMessage();
    }
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/dry_result.json', json_encode($result, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
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

    $parseErr = null;
    $parsed = parse_works_lines($xml, $parseErr);
    if (empty($parsed)) {
        throw new RuntimeException('Парсер вернул 0 записей. ' . ($parseErr ?: ''));
    }

    $parsed = compute_parents($parsed);

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
        'message' => 'Построчный парсер. Удалено: ' . $skippedDeleted . '. Дублей: ' . $skippedDup,
    ];
}

function do_sync_nomenclature(PDO $pdo): array {
    $inn = getenv('ONEC_INN'); $kpp = getenv('ONEC_KPP');
    if (!$inn || !$kpp) throw new RuntimeException('Нужны ONEC_INN и ONEC_KPP');
    $xml = onec_call('UnloadNomenclatureUpdates', ['INN' => $inn, 'KPP' => $kpp]);
    $parseErr = null;
    $parsed = parse_works_lines($xml, $parseErr);
    if (empty($parsed)) return ['total' => 0, 'message' => '0 записей. ' . ($parseErr ?: '')];
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
    return ['total' => $total, 'message' => 'Построчный парсер'];
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
