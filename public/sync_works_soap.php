<?php
// public/sync_works_soap.php — ДИАГНОСТИКА СТРУКТУРЫ

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

$log = [];

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

    $log[] = 'HTTP ' . $httpCode . ', размер: ' . strlen((string)$response);

    $dom = new DOMDocument();
    $dom->loadXML((string)$response, LIBXML_NONET | LIBXML_NOCDATA);
    $xml = simplexml_import_dom($dom);
    $xml->registerXPathNamespace('m', $targetNs);

    // Дамп структуры: путь → имя тега
    $dump = [];
    function walk(SimpleXMLElement $node, string $path, array &$dump, int $depth = 0, int $maxDepth = 5)
    {
        if ($depth > $maxDepth) return;
        $name = $node->getName();
        $fullPath = $path . '/' . $name;
        $text = trim((string)$node);
        if ($text !== '') {
            $text = mb_substr($text, 0, 80);
        }
        $dump[] = sprintf('%s%s%s',
            str_repeat('  ', $depth),
            $name,
            ($text !== '') ? " = \"$text\"" : ''
        );
        foreach ($node->children() as $child) {
            walk($child, $fullPath, $dump, $depth + 1, $maxDepth);
        }
    }

    // Найдём все InstallationWorkloads и посмотрим каждый
    $iwNodes = $xml->xpath('//m:InstallationWorkloads');
    $log[] = 'Всего //m:InstallationWorkloads: ' . count($iwNodes);

    foreach ($iwNodes as $i => $iw) {
        $log[] = '';
        $log[] = '===== InstallationWorkloads #' . $i . ' =====';
        $dump = [];
        walk($iw, '', $dump, 0, 6);
        // Отрежем до 4-х уровней, чтобы не раздувать
        foreach ($dump as $line) {
            // Считаем отступы
            $leading = strlen($line) - strlen(ltrim($line, ' '));
            if ($leading <= 12) { // до 6 уровней
                $log[] = $line;
            }
        }
        $log[] = '  ... (структура урезана для обзора)';

        // Дополнительно: сколько Workload внутри
        $wlCount = count($iw->xpath('.//m:Workloads/m:Workload'));
        $log[] = 'Workload-узлов внутри: ' . $wlCount;

        // TimeRate
        $tr = $iw->xpath('./m:TimeRate');
        if ($tr) {
            $log[] = 'TimeRate/Code = ' . (string)($tr[0]->Code ?? '');
            $log[] = 'TimeRate/Name = ' . (string)($tr[0]->Name ?? '');
        } else {
            $log[] = 'TimeRate НЕТ в этом узле';
        }
    }
}
?><!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><title>Диагностика структуры</title>
<style>
body{font-family:system-ui,sans-serif;margin:20px;background:#f5f5f5}
form{background:#fff;padding:16px;border-radius:8px;max-width:680px}
label{display:block;margin:8px 0 4px;font-weight:600}
input{width:100%;padding:8px;box-sizing:border-box}
button{margin-top:12px;padding:10px 16px;font-size:15px;cursor:pointer}
pre{background:#111;color:#eee;padding:12px;border-radius:6px;overflow:auto;max-height:700px;font-size:12px;white-space:pre-wrap}
</style></head>
<body>
<h1>Диагностика структуры SOAP-ответа</h1>
<form method="post">
  <label>StartDate</label><input type="date" name="start" value="<?= htmlspecialchars($start) ?>">
  <label>EndDate</label><input type="date" name="end" value="<?= htmlspecialchars($end) ?>">
  <label>ModelShassis</label><input type="text" name="model" value="<?= htmlspecialchars($model) ?>">
  <input type="hidden" name="run" value="1">
  <button type="submit">🔎 Показать структуру</button>
</form>
<?php if ($run): ?>
<pre><?= htmlspecialchars(implode("\n", $log)) ?></pre>
<?php endif; ?>
</body></html>
