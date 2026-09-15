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

$running = !empty($_GET['run']);
$messages = [];
$error = null;

/**
 * Отправляет SOAP-запрос к 1С:ГОА.
 */
function onec_call(string $operation, array $params = []): string {
    $login    = getenv('ONEC_LOGIN');
    $password = getenv('ONEC_PASSWORD');
    if (!$login || !$password) {
        throw new RuntimeException('Не заданы ONEC_LOGIN / ONEC_PASSWORD');
    }

    $endpoint = getenv('ONEC_SOAP_URL') ?: 'https://web-1c.kamaz.ru/GOA/ws/ws2.1cws';
    $ns = 'http://1c.kamaz.ru/zakaz';

    $paramsXml = '';
    foreach ($params as $k => $v) {
        $paramsXml .= '<zak:' . $k . '>' . htmlspecialchars((string)$v, ENT_XML1) . '</zak:' . $k . '>';
    }

    $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:zak="' . $ns . '">'
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
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: "' . $ns . '#' . $operation . '"',
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) throw new RuntimeException('Ошибка соединения: ' . $curlErr);
    if ($httpCode === 401) throw new RuntimeException('Неверный логин/пароль 1С');
    if ($httpCode === 403) throw new RuntimeException('Нет прав на операцию ' . $operation);
    if ($httpCode !== 200) {
        $preview = substr(preg_replace('/\s+/', ' ', trim((string)$response)), 0, 700);
        throw new RuntimeException('1С вернул код ' . $httpCode . '. Ответ: ' . $preview);
    }
    return (string)$response;
}

/**
 * Проверяет SOAP Fault.
 */
function check_soap_fault(string $xml): void {
    if (stripos($xml, 'Fault') !== false && stripos($xml, '<faultcode') !== false) {
        if (preg_match('/<faultstring[^>]*>(.*?)<\/faultstring>/is', $xml, $m)) {
            throw new RuntimeException('1С: ' . trim($m[1]));
        }
        throw new RuntimeException('SOAP Fault без описания');
    }
}

/**
 * Разбирает SOAP-ответ.
 */
function parse_soap(string $xml): SimpleXMLElement {
    $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
    $xml = preg_replace('/<\?xml[^>]*\?>/i', '', $xml, 1);

    $prev = libxml_use_internal_errors(true);
    libxml_clear_errors();
    $sx = simplexml_load_string($xml);
    $errs = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if (!$sx) {
        $preview = substr(preg_replace('/\s+/', ' ', trim($xml)), 0, 700);
        $errMsg  = $errs ? (' | libxml: ' . trim($errs[0]->message)) : '';
        throw new RuntimeException('Не удалось разобрать ответ 1С.' . $errMsg . ' Ответ: ' . $preview);
    }
    return $sx;
}

/**
 * Текст элемента (без учёта namespace).
 */
function first_text(SimpleXMLElement $el, string $tag): ?string {
    $nodes = $el->xpath('./*[local-name()="' . $tag . '"]');
    if (!$nodes || !isset($nodes[0])) return null;
    $v = trim((string)$nodes[0]);
    return $v === '' ? null : $v;
}

/**
 * Достаёт Description из ответа.
 */
function extract_description(SimpleXMLElement $sx): ?string {
    $nodes = $sx->xpath('//*[local-name()="Description"]');
    if (!$nodes || !isset($nodes[0])) return null;
    $v = trim((string)$nodes[0]);
    return $v === '' ? null : $v;
}

function do_sync_works(PDO $pdo): array {
    $xml = onec_call('UnloadWorkOperations', [
        'StartDate' => '2000-01-01T00:00:00',
        'EndDate'   => '2099-12-31T23:59:59',
    ]);
    check_soap_fault($xml);
    $sx = parse_soap($xml);

    $nodes = $sx->xpath('//*[local-name()="WorkOperation"]');
    if ($nodes === false) $nodes = [];

    $description = extract_description($sx);

    if (count($nodes) === 0) {
        return [
            'total'   => 0,
            'message' => '1С вернула 0 записей. ' . ($description ? 'Сообщение: ' . $description : '(без описания)'),
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
    foreach ($nodes as $n) {
        $code = first_text($n, 'Code');
        if (!$code) continue;

        $parentCode = null;
        if (isset($n->Parent)) {
            $pc = trim((string)$n->Parent->Code);
            if ($pc === '') $pc = trim((string)$n->Parent);
            if ($pc !== '') $parentCode = $pc;
        }

        $stmt->execute([
            ':code'           => $code,
            ':parent_code'    => $parentCode,
            ':it_is_group'    => strtolower(first_text($n, 'ItIsGroup') ?? 'false') === 'true' ? 1 : 0,
            ':name'           => first_text($n, 'Name'),
            ':operation_code' => first_text($n, 'OperationCode'),
            ':name_work'      => first_text($n, 'NameWork'),
            ':eng_name'       => first_text($n, 'EngName'),
            ':description'    => first_text($n, 'Description'),
            ':guard_work'     => strtolower(first_text($n, 'GuardWork') ?? 'false') === 'true' ? 1 : 0,
            ':fact_work'      => strtolower(first_text($n, 'FactWork') ?? 'false') === 'true' ? 1 : 0,
            ':deleted'        => strtolower(first_text($n, 'Deleted') ?? 'false') === 'true' ? 1 : 0,
        ]);
        $total++;
    }
    return ['total' => $total, 'message' => null];
}

function do_sync_nomenclature(PDO $pdo): array {
    $xml = onec_call('UnloadNomenclature', [
        'StartDate' => '2000-01-01T00:00:00',
        'EndDate'   => '2099-12-31T23:59:59',
    ]);
    check_soap_fault($xml);
    $sx = parse_soap($xml);

    $nodes = $sx->xpath('//*[local-name()="Nomenclature"]');
    if ($nodes === false) $nodes = [];

    $description = extract_description($sx);

    if (count($nodes) === 0) {
        return [
            'total'   => 0,
            'message' => '1С вернула 0 записей. ' . ($description ? 'Сообщение: ' . $description : '(без описания)'),
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
    foreach ($nodes as $n) {
        $code = first_text($n, 'Code');
        if (!$code) continue;

        $baseMeasure = null;
        if (isset($n->BaseMeasure)) {
            $baseMeasure = trim((string)$n->BaseMeasure->Name);
            if ($baseMeasure === '') $baseMeasure = trim((string)$n->BaseMeasure->Code);
            if ($baseMeasure === '') $baseMeasure = null;
        }

        $stmt->execute([
            ':code'         => $code,
            ':name'         => first_text($n, 'Name'),
            ':full_name'    => first_text($n, 'FullName'),
            ':eng_name'     => first_text($n, 'EngName'),
            ':base_measure' => $baseMeasure,
            ':code_1c'      => first_text($n, 'Code1C'),
            ':parent'       => first_text($n, 'Parent'),
            ':deleted'      => strtolower(first_text($n, 'Deleted') ?? 'false') === 'true' ? 1 : 0,
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
        $msg = 'Синхронизация выполнена. Записей: ' . ($r['total'] ?? '—');
        if (!empty($r['message'])) $msg .= '. ' . $r['message'];
        $messages[] = $msg;
    } else {
        $error = $r['error'] ?? 'Ошибка синхронизации';
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
  .err-cell { color:#dc2626; font-size:11px; word-break:break-all; max-width:400px; }
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
      Используется операция выгрузки за весь период (2000 – 2099). Может занять до нескольких минут.
    </p>
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
            <th>Ошибка / Сообщение</th>
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

  <div class="card">
    <h2>Диагностика окружения</h2>
    <table class="doc-table">
      <thead><tr><th>Переменная</th><th>Значение</th></tr></thead>
      <tbody>
        <tr><td><code>ONEC_LOGIN</code></td><td><?= getenv('ONEC_LOGIN') ? '✅ задана' : '❌ не задана' ?></td></tr>
        <tr><td><code>ONEC_PASSWORD</code></td><td><?= getenv('ONEC_PASSWORD') ? '✅ задана' : '❌ не задана' ?></td></tr>
        <tr><td><code>ONEC_INN</code></td><td><?= getenv('ONEC_INN') ? '✅ ' . e(getenv('ONEC_INN')) : '❌ не задана' ?></td></tr>
        <tr><td><code>ONEC_KPP</code></td><td><?= getenv('ONEC_KPP') ? '✅ ' . e(getenv('ONEC_KPP')) : '❌ не задана' ?></td></tr>
        <tr><td><code>ONEC_SOAP_URL</code></td><td><?= getenv('ONEC_SOAP_URL') ? e(getenv('ONEC_SOAP_URL')) : '— (используется стандартный)' ?></td></tr>
      </tbody>
    </table>
  </div>

</div>
</body>
</html>
