<?php
// public/sync_works_soap.php — СОХРАНИТЬ XML

declare(strict_types=1);
ini_set('display_errors', '1');
error_reporting(E_ALL);
ini_set('memory_limit', '512M');

require_once __DIR__ . '/db.php';
start_session();
if (!current_user()) { header('Location: index.html'); exit; }

$wsdlUrl    = 'https://web-1c.kamaz.ru/GOA/ws/ws2.1cws';
$soapAction = 'http://1c.kamaz.ru/zakaz#zakaz:UnloadInstallationWorkloads';
$targetNs   = 'http://1c.kamaz.ru/zakaz';
$login      = getenv('ONEC_LOGIN')    ?: '';
$password   = getenv('ONEC_PASSWORD') ?: '';

$start  = $_REQUEST['start']  ?? '2021-01-01';
$end    = $_REQUEST['end']    ?? date('Y-m-d');
$model  = $_REQUEST['model']  ?? '54901-0070014-CA';
$run    = isset($_REQUEST['run']) && $_REQUEST['run'] == '1';

$msg = '';

if ($run) {
    $xmlBody = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"'
        . ' xmlns:zak="' . $targetNs . '">'
        . '<soap:Body>'
        . '<zak:UnloadInstallationWorkloads>'
        . '<zak:StartDate>'   . htmlspecialchars($start, ENT_XML1) . '</zak:StartDate>'
        . '<zak:EndDate>'     . htmlspecialchars($end,   ENT_XML1) . '</zak:EndDate>'
        . '<zak:ModelShassis>' . htmlspecialchars($model, ENT_XML1) . '</zak:ModelShassis>'
        . '</zak:UnloadInstallationWorkloads>'
        . '</soap:Body>'
        . '</soap:Envelope>';

    $ch = curl_init($wsdlUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xmlBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: ' . $soapAction,
            'Authorization: Basic ' . base64_encode($login . ':' . $password),
        ],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    $msg = 'HTTP ' . $httpCode . ', размер: ' . strlen((string)$response) . ' байт';

    // Красиво форматируем для чтения
    $dom = new DOMDocument();
    $dom->preserveWhiteSpace = false;
    $dom->formatOutput = true;
    $dom->loadXML((string)$response);

    $pretty = $dom->saveXML();

    // Сохраняем в public/soap_last_response.xml
    $path = __DIR__ . '/soap_last_response.xml';
    file_put_contents($path, $pretty);

    $msg .= '. Сохранено в public/soap_last_response.xml (' . strlen($pretty) . ' байт)';
    $msg .= '. Открой ' . htmlspecialchars('https://ipg.relaxdev.ru/soap_last_response.xml');
}
?><!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><title>Сохранить XML</title>
<style>
body{font-family:system-ui,sans-serif;margin:20px;background:#f5f5f5}
form{background:#fff;padding:16px;border-radius:8px;max-width:680px}
label{display:block;margin:8px 0 4px;font-weight:600}
input{width:100%;padding:8px;box-sizing:border-box}
button{margin-top:12px;padding:10px 16px;font-size:15px;cursor:pointer}
.msg{margin-top:16px;padding:12px;background:#e6f7e6;border:1px solid #7ac77a;border-radius:6px}
</style></head>
<body>
<h1>Сохранить XML-ответ 1С в файл</h1>
<form method="post">
  <label>StartDate</label><input type="date" name="start" value="<?= htmlspecialchars($start) ?>">
  <label>EndDate</label><input type="date" name="end" value="<?= htmlspecialchars($end) ?>">
  <label>ModelShassis</label><input type="text" name="model" value="<?= htmlspecialchars($model) ?>">
  <input type="hidden" name="run" value="1">
  <button type="submit">💾 Сохранить XML</button>
</form>
<?php if ($msg): ?>
  <div class="msg"><?= $msg ?></div>
<?php endif; ?>
</body></html>
