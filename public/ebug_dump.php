<?php
/**
 * Отладочный скрипт: скачивает сырой XML от 1С и показывает его в браузере.
 * Открой ?type=works или ?type=nomenclature
 * Доступ только админу.
 */
set_time_limit(0);
ini_set('memory_limit', '512M');

require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user || !$user['is_admin']) {
    http_response_code(403);
    exit('Только для админа');
}

$type = $_GET['type'] ?? 'works';
$limit = (int)($_GET['limit'] ?? 30000); // сколько символов показать

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

$xml = '';
$err = '';
$tz = getenv('ONEC_DATE_TZ') ?: '+05:00';

try {
    if ($type === 'works') {
        $xml = onec_call('UnloadWorkOperations', [
            'OperationCode' => null,
            'StartDate'     => '2000-01-01' . $tz,
            'EndDate'       => '2099-12-31' . $tz,
        ]);
    } elseif ($type === 'nomenclature') {
        $inn = getenv('ONEC_INN'); $kpp = getenv('ONEC_KPP');
        if (!$inn || !$kpp) throw new RuntimeException('Нужны ONEC_INN и ONEC_KPP');
        $xml = onec_call('UnloadNomenclatureUpdates', ['INN' => $inn, 'KPP' => $kpp]);
    } else {
        $err = 'Неверный type. Используй ?type=works или ?type=nomenclature';
    }
} catch (Throwable $e) {
    $err = $e->getMessage();
}

// Быстрая аналитика по XML
$stats = [];
if ($xml !== '') {
    $stats['length'] = strlen($xml);
    $stats['has_WorkOperations'] = (substr_count($xml, '<WorkOperations>') + substr_count($xml, ':WorkOperations>'));
    $stats['has_Parent_open']    = substr_count($xml, '<Parent>') + substr_count($xml, ':Parent>');
    $stats['has_Code_open']      = substr_count($xml, '<Code>') + substr_count($xml, ':Code>');
    $stats['has_ItIsGroup']      = substr_count($xml, '<ItIsGroup>') + substr_count($xml, ':ItIsGroup>');
    $stats['has_GuardWork']      = substr_count($xml, '<GuardWork>') + substr_count($xml, ':GuardWork>');
    $stats['has_FactWork']       = substr_count($xml, '<FactWork>') + substr_count($xml, ':FactWork>');
    $stats['has_OperationCode']  = substr_count($xml, '<OperationCode>') + substr_count($xml, ':OperationCode>');

    // Пробуем SimpleXML
    $clean = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
    $prev = libxml_use_internal_errors(true);
    $sx = simplexml_load_string($clean, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    $sxErrs = libxml_get_errors();
    libxml_clear_errors();
    libxml_use_internal_errors($prev);

    if ($sx) {
        $stats['simplexml'] = '✅ распарсился';
        $containers = $sx->xpath('//*[local-name()="WorkOperations"]');
        $stats['simplexml_containers'] = $containers ? count($containers) : 0;
        if ($containers) {
            $stats['simplexml_children'] = count($containers[0]->children());
        }
    } else {
        $stats['simplexml'] = '❌ не распарсился';
        $stats['simplexml_error'] = $sxErrs ? trim($sxErrs[0]->message) : 'без подробностей';
    }
}

?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dump XML от 1С</title>
<style>
  body { font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; background:#f0f2f5; padding:16px; color:#1a1a1a; line-height:1.5; }
  .container { max-width:1300px; margin:0 auto; }
  .card { background:#fff; border-radius:14px; padding:20px; box-shadow:0 2px 12px rgba(0,0,0,0.06); margin-bottom:16px; }
  h1 { font-size:20px; margin:0 0 12px; }
  .btn { display:inline-block; padding:10px 16px; border-radius:8px; background:#2563eb; color:#fff; text-decoration:none; font-weight:600; margin-right:8px; margin-bottom:8px; }
  .btn-secondary { background:#fff; color:#2563eb; border:1.5px solid #2563eb; }
  .alert-err { background:#fef2f2; color:#dc2626; padding:12px 16px; border-left:4px solid #dc2626; border-radius:10px; margin-bottom:16px; }
  .stats { display:grid; grid-template-columns: repeat(auto-fit, minmax(220px, 1fr)); gap:8px; }
  .stat { padding:8px 12px; background:#f9fafb; border-radius:8px; font-size:13px; }
  .stat b { color:#2563eb; }
  pre { background:#1a1a1a; color:#d1d5db; padding:16px; border-radius:10px; font-size:11px; overflow:auto; max-height:600px; white-space:pre-wrap; word-break:break-all; line-height:1.4; }
  code { background:#f3f4f6; padding:2px 6px; border-radius:4px; font-size:12px; }
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <h1>🔬 Сырой XML от 1С</h1>
    <a href="?type=works" class="btn">Скачать works</a>
    <a href="?type=nomenclature" class="btn">Скачать nomenclature</a>
    <a href="?type=works&limit=60000" class="btn btn-secondary">works, 60k символов</a>
    <a href="?type=works&limit=999999" class="btn btn-secondary">works, все</a>
  </div>

  <?php if ($err): ?>
    <div class="alert-err">❌ Ошибка: <?= htmlspecialchars($err) ?></div>
  <?php endif; ?>

  <?php if ($stats): ?>
    <div class="card">
      <h2>Статистика ответа</h2>
      <div class="stats">
        <?php foreach ($stats as $k => $v): ?>
          <div class="stat"><b><?= htmlspecialchars($k) ?>:</b> <?= htmlspecialchars((string)$v) ?></div>
        <?php endforeach; ?>
      </div>
    </div>
  <?php endif; ?>

  <?php if ($xml !== ''): ?>
    <div class="card">
      <h2>Сырой XML (первые <?= (int)$limit ?> символов, всего <?= strlen($xml) ?>)</h2>
      <pre><?= htmlspecialchars(substr($xml, 0, $limit)) ?></pre>
    </div>
  <?php endif; ?>
</div>
</body>
</html>
