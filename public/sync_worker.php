<?php
/**
 * HTTP-версия воркера. Запускается браузером через <img src>.
 * Работает сколько нужно — браузеру всё равно, сколько ждать картинку.
 * Возвращает 1x1 прозрачный GIF в самом конце.
 */

// Убираем любые лимиты
set_time_limit(0);
ignore_user_abort(true);
ini_set('memory_limit', '512M');

require __DIR__ . '/db.php';

// Защита: только админ (по сессии)
start_session();
$user = current_user();
if (!$user || !$user['is_admin']) {
    http_response_code(403);
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

$type = $_GET['type'] ?? '';
if (!in_array($type, ['works', 'nomenclature', 'all'], true)) {
    header('Content-Type: image/gif');
    echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
    exit;
}

// Отправляем заголовки сразу — браузер получит картинку позже, но не будет ждать
header('Content-Type: image/gif');
header('Content-Length: 43');
header('Connection: close');

// === Логика ===

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
        foreach ($container->children() as $n) {
            $code = '';
            foreach ($n->children() as $child) {
                if ($child->getName() === 'Code') { $code = trim((string)$child); break; }
            }
            if ($code === '') continue;

            $parentCode = null;
            foreach ($n->children() as $child) {
                if ($child->getName() === 'Parent') {
                    foreach ($child->children() as $c2) {
                        if ($c2->getName() === 'Code') {
                            $pc = trim((string)$c2);
                            if ($pc !== '' && $pc !== $code) $parentCode = $pc;
                            break;
                        }
                    }
                    break;
                }
            }

            $get = function(string $tag) use ($n) {
                foreach ($n->children() as $c) {
                    if ($c->getName() === $tag) {
                        $v = trim((string)$c);
                        return $v === '' ? null : $v;
                    }
                }
                return null;
            };

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

function parse_works(string $xml, ?string &$method = null): array {
    $viaSx = parse_works_simplexml($xml);
    if (!empty($viaSx)) { $method = 'SimpleXML'; return $viaSx; }
    $method = 'empty';
    return [];
}

function date_tz(): string { return getenv('ONEC_DATE_TZ') ?: '+05:00'; }

function do_sync_works(PDO $pdo): array {
    $tz = date_tz();
    $xml = onec_call('UnloadWorkOperations', [
        'OperationCode' => null, 'StartDate' => '2000-01-01' . $tz, 'EndDate' => '2099-12-31' . $tz,
    ]);
    $method = null;
    $parsed = parse_works($xml, $method);

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

    if (count($filtered) === 0) {
        return ['total' => 0, 'message' => 'Записей 0. Парсер: ' . $method];
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
        'message' => 'Парсер: ' . $method
            . '. Удалено: ' . $skippedDeleted
            . '. Дублей отсеяно: ' . $skippedDup,
    ];
}

function do_sync_nomenclature(PDO $pdo): array {
    $inn = getenv('ONEC_INN'); $kpp = getenv('ONEC_KPP');
    if (!$inn || !$kpp) throw new RuntimeException('Нужны ONEC_INN и ONEC_KPP');
    $xml = onec_call('UnloadNomenclatureUpdates', ['INN' => $inn, 'KPP' => $kpp]);
    $method = null;
    $parsed = parse_works($xml, $method);
    if (count($parsed) === 0) return ['total' => 0, 'message' => 'Записей 0. Парсер: ' . $method];

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
    return ['total' => $total, 'message' => 'Парсер: ' . $method];
}

function run_sync(PDO $pdo, string $t, callable $fn): array {
    $stmt = $pdo->prepare("INSERT INTO sync_log (sync_type, status) VALUES (:t, 'running') RETURNING id");
    $stmt->execute([':t' => $t]); $logId = (int)$stmt->fetchColumn();
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

$pdo = get_db();
try {
    if ($type === 'works') run_sync($pdo, 'works', 'do_sync_works');
    elseif ($type === 'nomenclature') run_sync($pdo, 'nomenclature', 'do_sync_nomenclature');
    else {
        run_sync($pdo, 'works', 'do_sync_works');
        run_sync($pdo, 'nomenclature', 'do_sync_nomenclature');
    }
} catch (Throwable $e) {
    // Тихо — лог уже в БД
}

// Возвращаем 1x1 прозрачный GIF — браузер закончит запрос
echo base64_decode('R0lGODlhAQABAIAAAAAAAP///yH5BAEAAAAALAAAAAABAAEAAAIBRAA7');
