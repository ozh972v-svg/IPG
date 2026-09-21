<?php
// public/sync_works_soap.php  — ВРЕМЕННАЯ ДИАГНОСТИКА

declare(strict_types=1);

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

$response = '';
$httpCode = 0;
$curlErr  = '';
$diag     = [];

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
    $curlErr  = curl_error($ch);
    curl_close($ch);

    $diag[] = 'httpCode = ' . $httpCode;
    $diag[] = 'curlErr  = ' . ($curlErr ?: '(нет)');
    $diag[] = 'strlen(response) = ' . strlen((string)$response);

    // Первые 16 байт в hex — увидим BOM или мусор в начале
    $head = substr((string)$response, 0, 16);
    $diag[] = 'первые 16 байт (hex): ' . bin2hex($head);
    $diag[] = 'первые 500 символов:';
    $diag[] = substr((string)$response, 0, 500);

    // Пробуем распарсить
    libxml_use_internal_errors(true);
    libxml_clear_errors();
    $xml = simplexml_load_string((string)$response);
    if ($xml === false) {
        $diag[] = 'simplexml_load_string вернул FALSE';
        $errs = libxml_get_errors();
        if (!$errs) {
            $diag[] = 'libxml_get_errors() пуст — значит ошибка не от libxml (возможно, память или BOM)';
        } else {
            foreach ($errs as $e) {
                $diag[] = sprintf('libxml: [%d] уровень %d, строка %d: %s',
                    $e->code, $e->level, $e->line, trim($e->message));
            }
        }
    } else {
        $diag[] = 'simplexml_load_string OK, корневой элемент: ' . $xml->getName();
        $xml->registerXPathNamespace('m', $targetNs);
        $nodes = $xml->xpath('//m:InstallationWorkload');
        $diag[] = 'Найдено InstallationWorkload: ' . ($nodes ? count($nodes) : 0);
    }
}
?><!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><title>Диагностика sync_works_soap</title>
<style>
body{font-family:system-ui,sans-serif;margin:20px;background:#f5f5f5}
form{background:#fff;padding:16px;border-radius:8px;max-width:680px}
label{display:block;margin:8px 0 4px;font-weight:600}
input{width:100%;padding:8px;box-sizing:border-box}
button{margin-top:12px;padding:10px 16px;font-size:15px;cursor:pointer}
pre{background:#111;color:#eee;padding:12px;border-radius:6px;overflow:auto;max-height:700px;font-size:12px;white-space:pre-wrap}
</style></head>
<body>
<h1>Диагностика sync_works_soap.php</h1>
<form method="post">
  <label>StartDate</label><input type="date" name="start" value="<?= htmlspecialchars($start) ?>">
  <label>EndDate</label><input type="date" name="end" value="<?= htmlspecialchars($end) ?>">
  <label>ModelShassis</label><input type="text" name="model" value="<?= htmlspecialchars($model) ?>">
  <input type="hidden" name="run" value="1">
  <button type="submit">🔎 Диагностика</button>
</form>
<?php if ($run): ?>
<pre><?= htmlspecialchars(implode("\n", $diag)) ?></pre>
<?php endif; ?>
</body></html>
