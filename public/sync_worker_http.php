<?php
/**
 * HTTP-воркер синхронизации с 1С:ГОА.
 * Отдаёт клиенту 1x1 GIF сразу (fastcgi_finish_request), продолжает работу в фоне.
 * Режимы:
 *   ?type=works          — обычная синхронизация справочника работ
 *   ?type=nomenclature   — синхронизация номенклатуры
 *   ?type=all            — обе
 *   ?dump=works          — СОХРАНИТЬ сырой XML от 1С в файл (для отладки)
 */

set_time_limit(0);
ignore_user_abort(true);
ini_set('memory_limit', '512M');

require __DIR__ . '/db.php';
start_session();

$user = current_user();
$type = $_GET['type'] ?? '';
$dump = $_GET['dump'] ?? '';

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

// === Дальше без клиента ===
if (!$user || !$user['is_admin']) exit;

// ============ РЕЖИМ ДАМПА ============
if ($dump === 'works') {
    try {
        $xml = onec_call('UnloadWorkOperations', [
            'OperationCode' => null,
            'StartDate'     => '2000-01-01' . date_tz(),
            'EndDate'       => '2099-12-31' . date_tz(),
        ]);
        $dir = __DIR__ . '/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/1c_response_works.xml', $xml);
        @file_put_contents($dir . '/1c_response_works.txt', $xml); // дубль как .txt
    } catch (Throwable $e) {
        $dir = __DIR__ . '/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @file_put_contents($dir . '/1c_response_works.txt', 'ОШИБКА: ' . $e->getMessage());
    }
    exit(0);
}

if (!in_array($type, ['works', 'nomenclature', 'all'], true)) exit;

// Защита от параллельных запусков
try {
    $pdoChk = get_db();
    $stmt = $pdoChk->query("SELECT COUNT(*) FROM sync_log WHERE status='running' AND started_at > NOW() - INTERVAL '15 minutes'");
    if ((int)$stmt->fetchColumn() > 0) exit;
} catch (Throwable $e) { /* ignore */ }

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
 * SAX-парсер. Самый надёжный способ пройти по XML и собрать данные.
 * Работает на любом XML, включая очень вложенный.
 */
function parse_works_sax(string $xml): array {
    // Убираем BOM и xml-декларацию (SAX их не любит в виде строки)
    $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
    $xml = preg_replace('/<\?xml[^>]*\?>/i', '', $xml, 1);

    $parser = xml_parser_create('UTF-8');
    xml_parser_set_option($parser, XML_OPTION_CASE_FOLDING, 0);
    xml_parser_set_option($parser, XML_OPTION_SKIP_WHITE, 1);

    $items = [];
    $current = null;
    $tagStack = [];
    $currentText = '';

    xml_set_element_handler($parser,
        function($parser, $name, $attrs) use (&$items, &$current, &$tagStack, &$currentText) {
            $tagStack[] = $name;
            $currentText = '';

            // Начало новой работы/группы — тег WorkOperations > (Parent | WorkOperation)
            // Структура: <WorkOperations><Parent>...</Parent><Parent>...</Parent></WorkOperations>
            // Где "Parent" — это не родитель, а тег самого элемента.
            $depth = count($tagStack);
            if ($depth >= 2) {
                $parentTag = $tagStack[$depth - 2] ?? '';
                $tag = $name;
                // Ищем прямых детей WorkOperations, имя не важно — берём любой тег на 2-м уровне под WorkOperations
                if ($parentTag === 'WorkOperations') {
                    // Начинаем новый item
                    $current = ['_tag' => $tag];
                }
            }
        },
        function($parser, $name) use (&$items, &$current, &$tagStack, &$currentText) {
            array_pop($tagStack);

            // Закрытие тега внутри элемента — сохраняем текстовое значение
            if ($current !== null && count($tagStack) > 0) {
                $depth = count($tagStack);
                // Если мы на 3-м уровне (внутри WorkOperations > Parent > поле)
                $parentTag = $tagStack[$depth - 1] ?? '';
                if ($parentTag === 'WorkOperations' || ($depth >= 1 && ($tagStack[$depth - 1] ?? '') === $current['_tag'])) {
                    // Закрытие самого элемента
                    if ($name === $current['_tag'] && ($tagStack[$depth - 1] ?? '') === 'WorkOperations') {
                        // Заканчиваем текущий item
                        $items[] = $current;
                        $current = null;
                    } else {
                        // Это поле внутри item
                        $val = trim($currentText);
                        // Если поле уже задано и это Parent — пропускаем (там вложенный объект)
                        if (!isset($current[$name])) {
                            $current[$name] = $val;
                        }
                    }
                }
            }
            $currentText = '';
        },
        function($parser, $data) use (&$currentText) {
            $currentText .= $data;
        }
    );

    xml_parse($parser, $xml, true);
    xml_parser_free($parser);

    // Нормализуем результат
    $result = [];
    foreach ($items as $item) {
        $code = trim($item['Code'] ?? '');
        if ($code === '') continue;

        // Parent вложенный — ищем в сыром виде
        $parentCode = null;
        if (isset($item['Parent_Code'])) {
            $parentCode = trim($item['Parent_Code']);
        }

        $result[] = [
            'code'           => cut($code, 50),
            'parent_code'    => cut($parentCode, 50),
            'it_is_group'    => strtolower($item['ItIsGroup'] ?? 'false') === 'true',
            'name'           => cut($item['Name'] ?? null, 250),
            'operation_code' => cut($item['OperationCode'] ?? null, 50),
            'name_work'      => cut($item['NameWork'] ?? null, 250),
            'eng_name'       => cut($item['EngName'] ?? null, 250),
            'description'    => cut($item['Description'] ?? null, 5000),
            'guard_work'     => strtolower($item['GuardWork'] ?? 'false') === 'true',
            'fact_work'      => strtolower($item['FactWork'] ?? 'false') === 'true',
            'deleted'        => strtolower($item['Deleted'] ?? 'false') === 'true',
        ];
    }
    return $result;
}

function do_sync_works(PDO $pdo): array {
    $xml = onec_call('UnloadWorkOperations', [
        'OperationCode' => null,
        'StartDate'     => '2000-01-01' . date_tz(),
        'EndDate'       => '2099-12-31' . date_tz(),
    ]);

    // Сохраним сырой XML для отладки
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/1c_response_works.txt', $xml);

    $parsed = parse_works_sax($xml);

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
        return ['total' => 0, 'message' => 'Записей 0. SAX-парсер вернул 0. Длина XML: ' . strlen($xml)];
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
        'message' => 'Парсер: SAX. Удалено: ' . $skippedDeleted . '. Дублей отсеяно: ' . $skippedDup,
    ];
}

function do_sync_nomenclature(PDO $pdo): array {
    $inn = getenv('ONEC_INN'); $kpp = getenv('ONEC_KPP');
    if (!$inn || !$kpp) throw new RuntimeException('Нужны ONEC_INN и ONEC_KPP');
    $xml = onec_call('UnloadNomenclatureUpdates', ['INN' => $inn, 'KPP' => $kpp]);
    $dir = __DIR__ . '/uploads';
    if (!is_dir($dir)) @mkdir($dir, 0775, true);
    @file_put_contents($dir . '/1c_response_nomenclature.txt', $xml);

    $parsed = parse_works_sax($xml);
    if (count($parsed) === 0) return ['total' => 0, 'message' => 'Записей 0. Длина XML: ' . strlen($xml)];

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
    return ['total' => $total, 'message' => 'Парсер: SAX'];
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
