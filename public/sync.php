<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user || !$user['is_admin']) {
    header('Location: index.html');
    exit;
}

$pdo  = get_db();
$type = $_GET['type'] ?? '';
if (!in_array($type, ['works', 'nomenclature', 'all'], true)) $type = '';

$running   = !empty($_GET['run']);
$diagN     = isset($_GET['diag']) ? (int)$_GET['diag'] : 0;
$messages  = [];
$error     = null;

function soap_action(string $operation): string {
    return 'http://1c.kamaz.ru/zakaz#Zakaz:' . $operation;
}

function onec_call(string $operation, array $params = []): string {
    $login    = getenv('ONEC_SOAP_LOGIN')    ?: getenv('ONEC_LOGIN');
    $password = getenv('ONEC_SOAP_PASSWORD') ?: getenv('ONEC_PASSWORD');
    if (!$login || !$password) {
        throw new RuntimeException('Не заданы ONEC_LOGIN / ONEC_PASSWORD');
    }

    $endpoint = getenv('ONEC_SOAP_URL') ?: 'https://web-1c.kamaz.ru/GOA/ws/Zakaz';
    $ns  = 'http://1c.kamaz.ru/zakaz';
    $xsi = 'http://www.w3.org/2001/XMLSchema-instance';

    $paramsXml = '';
    foreach ($params as $k => $v) {
        if ($v === null) {
            $paramsXml .= '<zak:' . $k . ' xsi:nil="true"/>';
        } else {
            $paramsXml .= '<zak:' . $k . '>' . htmlspecialchars((string)$v, ENT_XML1) . '</zak:' . $k . '>';
        }
    }

    $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" '
        . 'xmlns:zak="' . $ns . '" xmlns:xsi="' . $xsi . '">'
        . '<soapenv:Header/>'
        . '<soapenv:Body>'
        . '<zak:' . $operation . '>' . $paramsXml . '</zak:' . $operation . '>'
        . '</soapenv:Body>'
        . '</soapenv:Envelope>';

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $envelope,
        CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
        CURLOPT_USERPWD        => $login . ':' . $password,
        CURLOPT_TIMEOUT        => 45,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . soap_action($operation) . '"',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) throw new RuntimeException('Ошибка соединения: ' . $curlErr);
    if ($httpCode === 401 || $httpCode === 402) {
        throw new RuntimeException('Неверный логин/пароль (HTTP ' . $httpCode . '): ' . substr(preg_replace('/\s+/', ' ', (string)$response), 0, 300));
    }
    if ($httpCode === 403) throw new RuntimeException('Нет прав на операцию ' . $operation);
    if ($httpCode !== 200) {
        $preview = substr(preg_replace('/\s+/', ' ', trim((string)$response)), 0, 700);
        throw new RuntimeException('1С вернул код ' . $httpCode . '. Ответ: ' . $preview);
    }
    return (string)$response;
}

function check_soap_fault(string $xml): void {
    if (stripos($xml, '<faultcode') !== false || stripos($xml, ':Fault>') !== false) {
        if (preg_match('/<faultstring[^>]*>(.*?)<\/faultstring>/is', $xml, $m)) {
            throw new RuntimeException('1С: ' . trim(html_entity_decode($m[1], ENT_XML1, 'UTF-8')));
        }
        throw new RuntimeException('SOAP Fault без описания');
    }
}

function xml_tag(string $xml, string $tag): ?string {
    $t = preg_quote($tag, '~');
    $re = '~(?:<' . $t . '(?:\s[^>]*)?>(.*?)</' . $t . '>'
        . '|<[^:>\s]+:' . $t . '(?:\s[^>]*)?>(.*?)</[^:>\s]+:' . $t . '>)~is';
    if (preg_match($re, $xml, $m)) {
        $val = isset($m[1]) && $m[1] !== '' ? $m[1] : ($m[2] ?? '');
        $val = trim(html_entity_decode($val, ENT_XML1, 'UTF-8'));
        return $val === '' ? null : $val;
    }
    return null;
}

function raw_preview(string $xml, int $len = 2000): string {
    $v = preg_replace('/\s+/', ' ', $xml);
    return substr(trim($v), 0, $len);
}

function cut(?string $s, int $max): ?string {
    if ($s === null) return null;
    return mb_substr($s, 0, $max, 'UTF-8');
}

function parse_work_operations(string $xml): array {
    $items = [];

    $re = '~<(?:[^:>\s]+:)?Code(?:\s[^>]*)?>([^<]*)</(?:[^:>\s]+:)?Code>~i';
    if (!preg_match_all($re, $xml, $m, PREG_OFFSET_CAPTURE)) {
        return $items;
    }

    $codes = $m[1];
    $positions = $m[0];
    $n = count($codes);

    $containerStart = 0;
    $containerEnd = strlen($xml);
    if (preg_match('~<(?:[^:>\s]+:)?WorkOperations(?:\s[^>]*)?>~i', $xml, $cm, PREG_OFFSET_CAPTURE)) {
        $containerStart = $cm[0][1];
    }

    for ($i = 0; $i < $n; $i++) {
        $code = trim(html_entity_decode($codes[$i][0], ENT_XML1, 'UTF-8'));
        if ($code === '') continue;
        if (mb_strlen($code, 'UTF-8') > 50) continue;
        if (!preg_match('/^[A-Za-zА-Яа-я0-9]/u', $code)) continue;

        $startPos = $positions[$i][1];
        $endPos   = ($i + 1 < $n) ? $positions[$i + 1][1] : min($containerEnd, strlen($xml));

        $windowStart = max($containerStart, $startPos - 800);
        $window = substr($xml, $windowStart, $endPos - $windowStart + 500);

        $getLast = function(string $tag) use ($window) {
            $t = preg_quote($tag, '~');
            $re = '~<(?:[^:>\s]+:)?' . $t . '(?:\s[^>]*)?>([^<]*)</(?:[^:>\s]+:)?' . $t . '>~i';
            if (preg_match_all($re, $window, $mm)) {
                $v = trim(html_entity_decode(end($mm[1]), ENT_XML1, 'UTF-8'));
                return $v === '' ? null : $v;
            }
            return null;
        };

        $itIsGroup  = strtolower($getLast('ItIsGroup') ?? 'false') === 'true';
        $guardWork  = strtolower($getLast('GuardWork') ?? 'false') === 'true';
        $factWork   = strtolower($getLast('FactWork') ?? 'false') === 'true';
        $deleted    = strtolower($getLast('Deleted') ?? 'false') === 'true';

        $parentCode = null;
        if (preg_match('~<(?:[^:>\s]+:)?Parent(?:\s[^>]*)?>(.*?)</(?:[^:>\s]+:)?Parent>~is', $window, $pm)) {
            $pc = xml_tag($pm[1], 'Code');
            if ($pc !== null && $pc !== $code && mb_strlen($pc, 'UTF-8') <= 50) $parentCode = $pc;
        }

        $items[$code] = [
            'code'           => cut($code, 50),
            'parent_code'    => cut($parentCode, 50),
            'it_is_group'    => $itIsGroup,
            'name'           => cut($getLast('Name'), 250),
            'operation_code' => cut($getLast('OperationCode'), 50),
            'name_work'      => cut($getLast('NameWork'), 250),
            'eng_name'       => cut($getLast('EngName'), 250),
            'description'    => cut($getLast('Description'), 5000),
            'guard_work'     => $guardWork,
            'fact_work'      => $factWork,
            'deleted'        => $deleted,
        ];
    }

    return array_values($items);
}

function date_tz(): string {
    return getenv('ONEC_DATE_TZ') ?: '+05:00';
}

function diag_variant(int $n): ?array {
    $tz  = date_tz();
    $inn = getenv('ONEC_INN') ?: '';
    $kpp = getenv('ONEC_KPP') ?: '';

    $variants = [
        1 => [
            'label' => 'UnloadWorkOperations, OperationCode=null, даты с tz',
            'op' => 'UnloadWorkOperations',
            'params' => ['OperationCode' => null, 'StartDate' => '2000-01-01' . $tz, 'EndDate' => '2099-12-31' . $tz],
        ],
        2 => [
            'label' => 'UnloadWorkOperations, OperationCode=null, даты без tz',
            'op' => 'UnloadWorkOperations',
            'params' => ['OperationCode' => null, 'StartDate' => '2000-01-01', 'EndDate' => '2099-12-31'],
        ],
        3 => [
            'label' => 'UnloadWorkOperations, все null',
            'op' => 'UnloadWorkOperations',
            'params' => ['OperationCode' => null, 'StartDate' => null, 'EndDate' => null],
        ],
        4 => [
            'label' => 'UnloadWorkOperationsUpdates с INN/KPP',
            'op' => 'UnloadWorkOperationsUpdates',
            'params' => ['INN' => $inn, 'KPP' => $kpp],
        ],
        5 => [
            'label' => 'СЫРОЙ XML — UnloadWorkOperations (посмотреть сырой ответ)',
            'op' => 'UnloadWorkOperations',
            'params' => ['OperationCode' => null, 'StartDate' => '2000-01-01' . $tz, 'EndDate' => '2099-12-31' . $tz],
            'raw_only' => true,
        ],
        6 => [
            'label' => 'СЫРОЙ XML — UnloadWorkOperationsUpdates с INN/KPP',
            'op' => 'UnloadWorkOperationsUpdates',
            'params' => ['INN' => $inn, 'KPP' => $kpp],
            'raw_only' => true,
        ],
    ];

    return $variants[$n] ?? null;
}

function do_sync_works(PDO $pdo): array {
    $tz = date_tz();

    $xml = onec_call('UnloadWorkOperations', [
        'OperationCode' => null,
        'StartDate'     => '2000-01-01' . $tz,
        'EndDate'       => '2099-12-31' . $tz,
    ]);
    check_soap_fault($xml);

    $description = xml_tag($xml, 'Description');
    $parsed = parse_work_operations($xml);

    if (count($parsed) === 0) {
        return [
            'total'   => 0,
            'message' => 'Записей 0. ' . ($description ? 'Сообщение: ' . $description : '(без описания)')
                       . ' | Длина ответа: ' . strlen($xml) . ' байт'
                       . ' | Ответ: ' . raw_preview($xml, 400),
        ];
    }

    $stmt = $pdo->prepare("
        INSERT INTO work_operations (code, parent_code, it_is_group, name, operation_code, name_work, eng_name, description, guard_work, fact_work, deleted, updated_at)
        VALUES (:code, :parent_code, :it_is_group, :name, :operation_code, :name_work, :eng_name, :description, :guard_work, :fact_work, :deleted, NOW())
        ON CONFLICT (code) DO UPDATE SET
            parent_code    = EXCLUDED.parent_code,
            it_is_group    = EXCLUDED.it_is_group,
            name           = EXCLUDED.name,
            operation_code = EXCLUDED.operation_code,
            name_work      = EXCLUDED.name_work,
            eng_name       = EXCLUDED.eng_name,
            description    = EXCLUDED.description,
            guard_work     = EXCLUDED.guard_work,
            fact_work      = EXCLUDED.fact_work,
            deleted        = EXCLUDED.deleted,
            updated_at     = NOW()
    ");

    $total = 0;
    foreach ($parsed as $p) {
        $stmt->execute([
            ':code'           => $p['code'],
            ':parent_code'    => $p['parent_code'],
            ':it_is_group'    => $p['it_is_group'] ? 1 : 0,
            ':name'           => $p['name'],
            ':operation_code' => $p['operation_code'],
            ':name_work'      => $p['name_work'],
            ':eng_name'       => $p['eng_name'],
            ':description'    => $p['description'],
            ':guard_work'     => $p['guard_work'] ? 1 : 0,
            ':fact_work'      => $p['fact_work'] ? 1 : 0,
            ':deleted'        => $p['deleted'] ? 1 : 0,
        ]);
        $total++;
    }
    return ['total' => $total, 'message' => null];
}

function do_sync_nomenclature(PDO $pdo): array {
    $inn = getenv('ONEC_INN');
    $kpp = getenv('ONEC_KPP');
    if (!$inn || !$kpp) {
        throw new RuntimeException('Для выгрузки номенклатуры нужны ONEC_INN и ONEC_KPP');
    }

    $xml = onec_call('UnloadNomenclatureUpdates', ['INN' => $inn, 'KPP' => $kpp]);
    check_soap_fault($xml);

    $description = xml_tag($xml, 'Description');
    $parsed = parse_work_operations($xml);

    if (count($parsed) === 0) {
        return [
            'total'   => 0,
            'message' => 'Записей 0. ' . ($description ? 'Сообщение: ' . $description : '(без описания)')
                       . ' | Ответ: ' . raw_preview($xml, 400),
        ];
    }

    $stmt = $pdo->prepare("
        INSERT INTO nomenclatures (code, name, full_name, eng_name, base_measure, code_1c, parent, deleted, updated_at)
        VALUES (:code, :name, :full_name, :eng_name, :base_measure, :code_1c, :parent, :deleted, NOW())
        ON CONFLICT (code) DO UPDATE SET
            name         = EXCLUDED.name,
            full_name    = EXCLUDED.full_name,
            eng_name     = EXCLUDED.eng_name,
            base_measure = EXCLUDED.base_measure,
            code_1c      = EXCLUDED.code_1c,
            parent       = EXCLUDED.parent,
            deleted      = EXCLUDED.deleted,
            updated_at   = NOW()
    ");

    $total = 0;
    foreach ($parsed as $p) {
        $stmt->execute([
            ':code'         => $p['code'],
            ':name'         => $p['name'],
            ':full_name'    => null,
            ':eng_name'     => $p['eng_name'],
            ':base_measure' => null,
            ':code_1c'      => null,
            ':parent'       => $p['parent_code'],
            ':deleted'      => $p['deleted'] ? 1 : 0,
        ]);
        $total++;
    }
    return ['total' => $total, 'message' => null];
}

function run_sync(PDO $pdo, string $syncType, callable $fn): array {
    $stmt = $pdo->prepare("INSERT INTO sync_log (sync_type, status) VALUES (:t, 'running') RETURNING id");
    $stmt->execute([':t' => $syncType]);
    $logId = (int)$stmt->fetchColumn();

    try {
        $result = $fn($pdo);
        $pdo->prepare("UPDATE sync_log SET status='ok', finished_at=NOW(), items_total=:n, error_message=:m WHERE id=:id")
            ->execute([':n' => $result['total'], ':m' => $result['message'] ?: null, ':id' => $logId]);
        return ['ok' => true, 'total' => $result['total'], 'message' => $result['message']];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE sync_log SET status='error', finished_at=NOW(), error_message=:m WHERE id=:id")
            ->execute([':m' => $e->getMessage(), ':id' => $logId]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

if ($running && $type) {
    if ($type === 'works') {
        $r = run_sync($pdo, 'works', 'do_sync_works');
    } elseif ($type === 'nomenclature') {
        $r = run_sync($pdo, 'nomenclature', 'do_sync_nomenclature');
    } else {
        $r1 = run_sync($pdo, 'works', 'do_sync_works');
        $r2 = run_sync($pdo, 'nomenclature', 'do_sync_nomenclature');
        $r = ['ok' => ($r1['ok'] && $r2['ok']), 'total' => ($r1['total'] ?? 0) + ($r2['total'] ?? 0)];
    }
    if (!empty($r['ok'])) {
        $msg = 'Готово. Записей: ' . ($r['total'] ?? '—');
        if (!empty($r['message'])) $msg .= '. ' . $r['message'];
        $messages[] = $msg;
    } else {
        $error = $r['error'] ?? 'Ошибка синхронизации';
    }
}

$diagResult = null;
if ($diagN > 0) {
    $cfg = diag_variant($diagN);
    if ($cfg) {
        $diagResult = [
            'n' => $diagN,
            'label' => $cfg['label'],
            'op' => $cfg['op'],
            'params' => $cfg['params'],
            'soap_action' => soap_action($cfg['op']),
            'raw_only' => !empty($cfg['raw_only']),
        ];
        try {
            $xml = onec_call($cfg['op'], $cfg['params']);
            $diagResult['raw_len']  = strlen($xml);
            $diagResult['desc']     = xml_tag($xml, 'Description');
            $diagResult['preview']  = raw_preview($xml, 20000);
            $parsed = parse_work_operations($xml);
            $diagResult['parsed_count']  = count($parsed);
            $diagResult['parsed_sample'] = array_slice($parsed, 0, 3);
        } catch (Throwable $e) {
            $diagResult['error'] = $e->getMessage();
        }
    }
}

$history = $pdo->query("
    SELECT * FROM sync_log ORDER BY started_at DESC LIMIT 20
")->fetchAll();

function fmtTs($ts) { return $ts ? date('d.m.Y H:i:s', strtotime($ts)) : '—'; }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Синхронизация с 1С</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background:#f0f2f5; margin:0; padding:16px; color:#1a1a1a; line-height:1.5; }
  .container { max-width:900px; margin:0 auto; }
  .card { background:#fff; border-radius:14px; padding:20px; box-shadow:0 2px 12px rgba(0,0,0,0.06); margin-bottom:16px; }
  h1 { font-size:22px; margin:0 0 12px; }
  h2 { font-size:18px; margin:0 0 12px; }
  h3 { font-size:14px; margin:14px 0 6px; }
  .btn { display:inline-block; padding:12px 18px; border:none; border-radius:10px; font-size:15px; font-weight:600; cursor:pointer; background:#2563eb; color:#fff; text-decoration:none; text-align:center; }
  .btn:hover { opacity:0.9; }
  .btn-secondary { background:#fff; color:#2563eb; border:1.5px solid #2563eb; }
  .btn-green { background:#16a34a; }
  .btn-row { display:flex; gap:10px; flex-wrap:wrap; margin-bottom:12px; }
  .alert { padding:12px 16px; border-radius:10px; font-size:14px; margin-bottom:16px; }
  .alert-success { background:#f0fdf4; color:#16a34a; border-left:4px solid #16a34a; }
  .alert-error { background:#fef2f2; color:#dc2626; border-left:4px solid #dc2626; }
  table.doc-table { width:100%; border-collapse:collapse; font-size:13px; }
  table.doc-table th { background:#f9fafb; color:#666; font-weight:600; text-align:left; padding:10px 12px; border-bottom:1px solid #e5e7eb; font-size:12px; text-transform:uppercase; }
  table.doc-table td { padding:10px 12px; border-bottom:1px solid #f0f0f0; vertical-align:top; }
  .badge { display:inline-block; padding:3px 10px; border-radius:6px; font-size:12px; font-weight:600; }
  .badge-green { background:#f0fdf4; color:#16a34a; }
  .badge-red { background:#fef2f2; color:#dc2626; }
  .badge-blue { background:#eff6ff; color:#2563eb; }
  code { background:#f3f4f6; padding:2px 6px; border-radius:4px; font-size:12px; }
  .err-cell { color:#555; font-size:11px; word-break:break-all; max-width:480px; }
  pre { white-space:pre-wrap; word-break:break-all; }
</style>
</head>
<body>
<div class="container">

  <div class="card">
    <h1>🔄 Синхронизация с 1С:ГОА</h1>
    <div class="btn-row">
      <a href="index.html" class="btn btn-secondary">← На рабочее место</a>
      <a href="works.php" class="btn btn-secondary">К справочнику работ</a>
      <a href="nomenclature.php" class="btn btn-secondary">К справочнику номенклатуры</a>
    </div>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">❌ <?= e($error) ?></div>
  <?php endif; ?>
  <?php foreach ($messages as $m): ?>
    <div class="alert alert-success">✅ <?= e($m) ?></div>
  <?php endforeach; ?>

  <?php if ($diagResult): ?>
    <div class="card">
      <h2>🔬 Диагностика — вариант <?= (int)$diagResult['n'] ?></h2>
      <div style="font-weight:700;margin-bottom:6px;"><?= e($diagResult['label']) ?></div>
      <div style="font-size:11px;color:#666;margin-bottom:10px;">
        Метод: <code><?= e($diagResult['op']) ?></code><br>
        SOAPAction: <code><?= e($diagResult['soap_action']) ?></code><br>
        Параметры: <code><?= e(json_encode($diagResult['params'], JSON_UNESCAPED_UNICODE)) ?></code>
      </div>
      <?php if (isset($diagResult['error'])): ?>
        <div style="background:#fef2f2;color:#dc2626;padding:10px;border-radius:6px;font-size:12px;word-break:break-all;">
          ❌ Ошибка: <?= e($diagResult['error']) ?>
        </div>
      <?php else: ?>
        <div style="background:<?= ($diagResult['parsed_count'] ?? 0) > 0 ? '#f0fdf4;color:#16a34a' : '#fffbeb;color:#b45309' ?>;padding:10px;border-radius:6px;font-size:14px;font-weight:700;">
          ✅ HTTP 200 · Ответ <?= (int)$diagResult['raw_len'] ?> байт · Распознано записей: <?= (int)$diagResult['parsed_count'] ?>
          <?php if ($diagResult['desc']): ?> · Описание: <?= e(substr($diagResult['desc'], 0, 300)) ?><?php endif; ?>
        </div>

        <h3>Сырой ответ 1С (первые 20000 символов, пробелы сохранены)</h3>
        <pre style="background:#fffbe6;padding:12px;border-radius:6px;font-size:11px;overflow:auto;max-height:700px;border:1px solid #fcd34d;white-space:pre-wrap;word-break:break-all;"><?= e($diagResult['preview']) ?></pre>

        <?php if (!empty($diagResult['parsed_sample'])): ?>
          <h3>Примеры распознанных записей</h3>
          <pre style="background:#f9fafb;padding:10px;border-radius:6px;font-size:11px;overflow:auto;max-height:500px;"><?= e(json_encode($diagResult['parsed_sample'], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
        <?php endif; ?>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Что синхронизировать</h2>
    <div class="btn-row">
      <a href="sync.php?type=works&run=1" class="btn btn-green"
         onclick="return confirm('Запустить синхронизацию справочника работ?')">
        🔧 Обновить работы
      </a>
      <a href="sync.php?type=nomenclature&run=1" class="btn btn-green"
         onclick="return confirm('Запустить синхронизацию номенклатуры?')">
        📦 Обновить номенклатуру
      </a>
      <a href="sync.php?type=all&run=1" class="btn"
         onclick="return confirm('Обновить всё?')">
        🔄 Обновить всё
      </a>
    </div>
    <p style="font-size:13px;color:#666;margin-top:12px;">
      Работает через <code>UnloadWorkOperations</code> с SOAPAction из WSDL.
    </p>
  </div>

  <div class="card">
    <h2>🔬 Диагностика (по одному варианту за клик)</h2>
    <p style="font-size:13px;color:#666;">Nginx рубит долгие запросы — делаем по одному. Нажми по очереди.</p>
    <div class="btn-row">
      <a href="sync.php?diag=1" class="btn" style="background:#b45309;">Вариант 1: nillable + даты с tz</a>
      <a href="sync.php?diag=2" class="btn" style="background:#b45309;">Вариант 2: nillable + даты без tz</a>
      <a href="sync.php?diag=3" class="btn" style="background:#b45309;">Вариант 3: всё null</a>
      <a href="sync.php?diag=4" class="btn" style="background:#b45309;">Вариант 4: Updates с INN/KPP</a>
      <a href="sync.php?diag=5" class="btn" style="background:#dc2626;">🔥 Вариант 5: СЫРОЙ XML (работы)</a>
      <a href="sync.php?diag=6" class="btn" style="background:#dc2626;">🔥 Вариант 6: СЫРОЙ XML (Updates)</a>
    </div>
  </div>

  <div class="card">
    <h2>История синхронизаций</h2>
    <?php if (!$history): ?>
      <p style="color:#888;">Пока нет ни одной синхронизации.</p>
    <?php else: ?>
      <table class="doc-table">
        <thead>
          <tr>
            <th>Тип</th>
            <th>Статус</th>
            <th>Начало</th>
            <th>Окончание</th>
            <th>Записей</th>
            <th>Детали</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($history as $h): ?>
            <tr>
              <td><code><?= e($h['sync_type']) ?></code></td>
              <td>
                <?php if ($h['status'] === 'ok'): ?>
                  <span class="badge badge-green">успех</span>
                <?php elseif ($h['status'] === 'error'): ?>
                  <span class="badge badge-red">ошибка</span>
                <?php else: ?>
                  <span class="badge badge-blue">работает</span>
                <?php endif; ?>
              </td>
              <td><?= e(fmtTs($h['started_at'])) ?></td>
              <td><?= e(fmtTs($h['finished_at'])) ?></td>
              <td><?= (int)$h['items_total'] ?></td>
              <td class="err-cell"><?= e($h['error_message'] ?: '') ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
