<?php
/**
 * Тест SOAP через curl (без расширения soap).
 * Открыть: https://ipg.relaxdev.ru/test_soap_curl.php
 * Удалить после проверки!
 */
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) { header('Location: login.php'); exit; }

header('Content-Type: text/html; charset=utf-8');

$ENDPOINT = 'https://web-1c.kamaz.ru/GOA/ws/ws2.1cws';
$LOGIN    = getenv('ONEC_LOGIN')    ?: ($_ENV['ONEC_LOGIN']    ?? '');
$PASS     = getenv('ONEC_PASSWORD') ?: ($_ENV['ONEC_PASSWORD'] ?? '');

$startDate    = $_GET['start'] ?? date('Y-m-d', strtotime('-30 days'));
$endDate      = $_GET['end']   ?? date('Y-m-d');
$modelShassis = $_GET['model'] ?? '54901-0070014-CA';

echo "<html><head><meta charset='utf-8'><title>Тест SOAP (curl)</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#f5f5f5;}
      pre{background:#fff;padding:14px;border-radius:8px;overflow:auto;border:1px solid #ddd;max-height:600px;}
      h2{margin-top:24px;} .ok{color:#16a34a;} .err{color:#dc2626;}
      form{background:#fff;padding:14px;border-radius:8px;margin-bottom:16px;}
      input{margin-right:12px;padding:6px;} </style></head><body>";
echo "<h1>Тест SOAP 1С:ГОА через curl</h1>";

/* --- Форма --- */
echo "<form method='get'>";
echo "StartDate:    <input type='date' name='start' value='" . htmlspecialchars($startDate) . "'>";
echo "EndDate:      <input type='date' name='end'   value='" . htmlspecialchars($endDate) . "'>";
echo "ModelShassis: <input type='text' name='model' value='" . htmlspecialchars($modelShassis) . "' size='30'>";
echo "<button type='submit'>Отправить</button>";
echo "</form>";

/* --- SOAP-конверт --- */
$envelope = '<?xml version="1.0" encoding="UTF-8"?>
<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"
               xmlns:zak="http://1c.kamaz.ru/zakaz">
  <soap:Body>
    <zak:UnloadInstallationWorkloads>
      <zak:StartDate>'   . htmlspecialchars($startDate)   . '</zak:StartDate>
      <zak:EndDate>'     . htmlspecialchars($endDate)     . '</zak:EndDate>
      <zak:ModelShassis>'. htmlspecialchars($modelShassis). '</zak:ModelShassis>
    </zak:UnloadInstallationWorkloads>
  </soap:Body>
</soap:Envelope>';

$soapAction = 'http://1c.kamaz.ru/zakaz#zakaz:UnloadInstallationWorkloads';

echo "<h2>Отправляемый XML</h2><pre>" . htmlspecialchars($envelope) . "</pre>";

/* --- curl-запрос --- */
$ch = curl_init($ENDPOINT);
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => $envelope,
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT        => 30,
    CURLOPT_HTTPHEADER     => [
        'Content-Type: text/xml; charset=utf-8',
        'SOAPAction: "' . $soapAction . '"',
        'Authorization: Basic ' . base64_encode($LOGIN . ':' . $PASS),
    ],
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlErr  = curl_error($ch);
curl_close($ch);

echo "<h2>Результат</h2><pre>";
echo "HTTP-код: " . $httpCode . "\n";
if ($curlErr) echo "<span class='err'>curl error: " . htmlspecialchars($curlErr) . "</span>\n";

if ($response) {
    // Показываем первые 5000 символов ответа
    echo "\n=== Ответ 1С (первые 5000 символов) ===\n";
    echo htmlspecialchars(substr($response, 0, 5000));
    echo "\n\n=== Размер ответа: " . strlen($response) . " байт ===\n";

    // Пробуем распарсить
    echo "\n=== Парсинг XML ===\n";
    libxml_use_internal_errors(true);
    $xml = simplexml_load_string($response);
    if ($xml === false) {
        echo "<span class='err'>Не удалось распарсить XML.</span>\n";
        foreach (libxml_get_errors() as $e) {
            echo "  - " . htmlspecialchars(trim($e->message)) . "\n";
        }
    } else {
        $xml->registerXPathNamespace('soap', 'http://schemas.xmlsoap.org/soap/envelope/');
        $xml->registerXPathNamespace('zak',  'http://1c.kamaz.ru/zakaz');
        $body = $xml->xpath('//soap:Body');
        if ($body) {
            echo "<span class='ok'>XML распарсен. Корень Body найден.</span>\n\n";
            echo "Структура Body (первые уровни):\n";
            echo htmlspecialchars(substr(print_r($body[0], true), 0, 3000));
        } else {
            echo "<span class='err'>Body не найден в ответе.</span>\n";
        }
    }
}
echo "</pre>";

echo "<p style='color:#888;margin-top:30px;'>После проверки удалите test_soap_curl.php — он не должен висеть на продакшене.</p>";
echo "</body></html>";
