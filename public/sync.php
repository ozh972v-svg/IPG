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

function xml_blocks(string $xml, string $tag): array {
    $t = preg_quote($tag, '~');
    $re = '~<[^:>\s]+:' . $t . '(?:\s[^>]*)?>(.*?)</[^:>\s]+:' . $t . '>~is';
    if (preg_match_all($re, $xml, $m)) return $m[1];
    $re = '~<' . $t . '(?:\s[^>]*)?>(.*?)</' . $t . '>~is';
    if (preg_match_all($re, $xml, $m)) return $m[1];
    return [];
}

function block_field(string $block, string $tag): ?string {
    return xml_tag($block, $tag);
}

function block_parent_code(string $block): ?string {
    if (!preg_match('~<(?:[^:>\s]+:)?Parent(?:\s[^>]*)?>(.*?)</(?:[^:>\s]+:)?Parent>~is', $block, $m)) {
        return null;
    }
    $parentBlock = $m[1];
    $code = xml_tag($parentBlock, 'Code');
    if ($code === null) {
        $code = trim(strip_tags($parentBlock));
        $code = $code === '' ? null : $code;
    }
    return $code;
}

function date_tz(): string {
    return getenv('ONEC_DATE_TZ') ?: '+05:00';
}

/**
 * Формирует даты в формате xs:date (БЕЗ времени, только дата + таймзона).
 */
function date_params(): array {
    $tz = date_tz();
    return [
        'StartDate' => '2000-01-01' . $tz,
        'EndDate'   => '2099-12-31' . $tz,
    ];
}

function raw_preview(string $xml): string {
    $v = preg_replace('/\s+/', ' ', $xml);
    return substr(trim($v), 0, 400);
}

function do_sync_works(PDO $pdo): array {
    $dates = date_params();
    $params = array_merge(['OperationCode' => ''], $dates);

    $xml = onec_call('UnloadWorkOperations', $params);
    check_soap_fault($xml);

    $description = xml_tag($xml, 'Description');
    $blocks = xml_blocks($xml, 'WorkOperation');

    if (count($blocks) === 0) {
        return [
            'total'   => 0,
            'message' => 'Записей 0. ' . ($description ? 'Описание от 1С: ' . $description : '(описание отсутствует)')
                       . ' | Отправлено: OperationCode="", StartDate=' . $dates['StartDate'] . ', EndDate=' . $dates['EndDate']
                       . ' | Ответ: ' . raw_preview($xml),
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
    foreach ($blocks as $b) {
        $code = block_field($b, 'Code');
        if (!$code) continue;
        if (preg_match('~^.*?<(?:[^:>\s]+:)?Code(?:\s[^>]*)?>(.*?)</(?:[^:>\s]+:)?Code>~is', $b, $cm)) {
            $code = trim(html_entity_decode($cm[1], ENT_XML1, 'UTF-8'));
        }

        $stmt->execute([
            ':code'           => $code,
            ':parent_code'    => block_parent_code($b),
            ':it_is_group'    => (strtolower(block_field($b, 'ItIsGroup') ?? 'false') === 'true') ? 1 : 0,
            ':name'           => block_field($b, 'Name'),
            ':operation_code' => block_field($b, 'OperationCode'),
            ':name_work'      => block_field($b, 'NameWork'),
            ':eng_name'       => block_field($b, 'EngName'),
            ':description'    => block_field($b, 'Description'),
            ':guard_work'     => (strtolower(block_field($b, 'GuardWork') ?? 'false') === 'true') ? 1 : 0,
            ':fact_work'      => (strtolower(block_field($b, 'FactWork') ?? 'false') === 'true') ? 1 : 0,
            ':deleted'        => (strtolower(block_field($b, 'Deleted') ?? 'false') === 'true') ? 1 : 0,
        ]);
        $total++;
    }
    return ['total' => $total, 'message' => null];
}

function do_sync_nomenclature(PDO $pdo): array {
    $dates = date_params();
    $params = array_merge(['OperationCode' => ''], $dates);

    $xml = onec_call('UnloadNomenclature', $params);
    check_soap_fault($xml);

    $description = xml_tag($xml, 'Description');
    $blocks = xml_blocks($xml, 'Nomenclature');

    if (count($blocks) === 0) {
        return [
            'total'   => 0,
            'message' => 'Записей 0. ' . ($description ? 'Описание от 1С: ' . $description : '(описание отсутствует)')
                       . ' | Ответ: ' . raw_preview($xml),
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
    foreach ($blocks as $b) {
        $code = block_field($b, 'Code');
        if (!$code) continue;

        $baseMeasure = null;
        if (preg_match('~<(?:[^:>\s]+:)?BaseMeasure(?:\s[^>]*)?>(.*?)</(?:[^:>\s]+:)?BaseMeasure>~is', $b, $bm)) {
            $inner = $bm[1];
            $baseMeasure = xml_tag($inner, 'Name') ?: xml_tag($inner, 'Code');
        }

        $stmt->execute([
            ':code'         => $code,
            ':name'         => block_field($b, 'Name'),
            ':full_name'    => block_field($b, 'FullName'),
            ':eng_name'     => block_field($b, 'EngName'),
            ':base_measure' => $baseMeasure,
            ':code_1c'      => block_field($b, 'Code1C'),
            ':parent'       => block_field($b, 'Parent'),
            ':deleted'      => (strtolower(block_field($b, 'Deleted') ?? 'false') === 'true') ? 1 : 0,
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
  .err-cell { color:#555; font-size:11px; word-break:break-all; max-width:480px; }
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
      Формат дат — xs:date (без времени), с таймзоной <code>+05:00</code>.
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

  <div class="card">
    <h2>Диагностика окружения</h2>
    <table class="doc-table">
      <thead><tr><th>Параметр</th><th>Значение</th></tr></thead>
      <tbody>
        <tr><td><code>PHP</code></td><td><?= e(PHP_VERSION) ?></td></tr>
        <tr><td><code>ONEC_LOGIN</code></td><td><?= getenv('ONEC_LOGIN') ? '✅' : '❌' ?></td></tr>
        <tr><td><code>ONEC_INN</code></td><td><?= getenv('ONEC_INN') ? '✅ ' . e(getenv('ONEC_INN')) : '— (не нужен)' ?></td></tr>
        <tr><td><code>ONEC_KPP</code></td><td><?= getenv('ONEC_KPP') ? '✅ ' . e(getenv('ONEC_KPP')) : '— (не нужен)' ?></td></tr>
        <tr><td><code>ONEC_DATE_TZ</code></td><td><?= getenv('ONEC_DATE_TZ') ? e(getenv('ONEC_DATE_TZ')) : '— (+05:00)' ?></td></tr>
      </tbody>
    </table>
  </div>

</div>
</body>
</html>
