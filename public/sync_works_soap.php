<?php
// public/sync_works_soap.php — ДИАГНОСТИКА #2

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

    $iwNodes = $xml->xpath('//m:InstallationWorkloads');
    $log[] = 'Всего //m:InstallationWorkloads: ' . count($iwNodes);

    foreach ($iwNodes as $i => $iw) {
        $log[] = '';
        $log[] = '===== InstallationWorkloads #' . $i . ' =====';

        // 1. Прямые дети верхнего уровня (первые 30)
        $log[] = '--- Прямые дети (первые 30 тегов) ---';
        $childCount = 0;
        foreach ($iw->children() as $child) {
            $name = $child->getName();
            $text = trim((string)$child);
            if (mb_strlen($text) > 100) $text = mb_substr($text, 0, 100) . '…';
            $log[] = "  <$name>" . ($text !== '' ? " = \"$text\"" : '');
            $childCount++;
            if ($childCount >= 30) { $log[] = '  …'; break; }
        }

        // 2. Ищем TimeRate где угодно в поддереве
        $log[] = '';
        $log[] = '--- Поиск TimeRate в поддереве ---';
        $trNodes = $iw->xpath('.//m:TimeRate');
        if (!$trNodes) {
            $log[] = 'TimeRate НЕ НАЙДЕН в поддереве #' . $i;
        } else {
            foreach ($trNodes as $j => $tr) {
                $log[] = "TimeRate #$j: Code=\"" . (string)$tr->Code . "\", Name=\"" . (string)$tr->Name . "\"";
            }
        }

        // 3. Показываем структуру глубже (до 7 уровней) — только имена тегов, без повторов
        $log[] = '';
        $log[] = '--- Дерево (до 7 уровней) ---';
        $seen = [];
        $walk = function(SimpleXMLElement $node, int $depth, array &$seen, array &$out) use (&$walk) {
            if ($depth > 7) return;
            $name = $node->getName();
            $path = str_repeat('  ', $depth) . $name;
            // Показываем только первые 3 раза каждый путь, чтобы не раздувать
            $key = $depth . ':' . $name;
            if (!isset($seen[$key])) $seen[$key] = 0;
            if ($seen[$key] < 2) {
                $out[] = $path;
            }
            $seen[$key]++;
            foreach ($node->children() as $child) {
                $walk($child, $depth + 1, $seen, $out);
            }
        };
        $treeOut = [];
        foreach ($iw->children() as $child) {
            $walk($child, 1, $seen, $treeOut);
        }
        // Ограничим вывод до 80 строк
        $treeOut = array_slice($treeOut, 0, 80);
        foreach ($treeOut as $line) { $log[] = $line; }
    }
}
?><!doctype html>
<html lang="ru">
<head><meta charset="utf-8"><title>Диагностика структуры #2</title>
<style>
body{font-family:system-ui,sans-serif;margin:20px;background:#f5f5f5}
form{background:#fff;padding:16px;border-radius:8px;max-width:680px}
label{display:block;margin:8px 0 4px;font-weight:600}
input{width:100%;padding:8px;box-sizing:border-box}
button{margin-top:12px;padding:10px 16px;font-size:15px;cursor:pointer}
pre{background:#111;color:#eee;padding:12px;border-radius:6px;overflow:auto;max-height:700px;font-size:12px;white-space:pre-wrap}
</style></head>
<body>
<h1>Диагностика #2 — структура вокруг TimeRate</h1>
<form method="post">
  <label>StartDate</label><input type="date" name="start" value="<?= htmlspecialchars($start) ?>">
  <label>EndDate</label><input type="date" name="end" value="<?= htmlspecialchars($end) ?>">
  <label>ModelShassis</label><input type="text" name="model" value="<?= htmlspecialchars($model) ?>">
  <input type="hidden" name="run" value="1">
  <button type="submit">🔎 Показать</button>
</form>
<?php if ($run): ?>
<pre><?= htmlspecialchars(implode("\n", $log)) ?></pre>
<?php endif; ?>
</body></html>
