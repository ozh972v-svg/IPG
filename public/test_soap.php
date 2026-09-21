<?php
/**
 * Тестовый скрипт для проверки SOAP-сервиса 1С:ГОА
 * Открыть в браузере: https://ipg.relaxdev.ru/test_soap.php
 * Удалить после проверки!
 */
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) { header('Location: login.php'); exit; }

header('Content-Type: text/html; charset=utf-8');

$WSDL  = 'https://web-1c.kamaz.ru/GOA/ws/ws2.1cws?wsdl';
$LOGIN = getenv('ONEC_LOGIN')    ?: ($_ENV['ONEC_LOGIN']    ?? '');
$PASS  = getenv('ONEC_PASSWORD') ?: ($_ENV['ONEC_PASSWORD'] ?? '');

echo "<html><head><meta charset='utf-8'><title>Тест SOAP 1С</title>";
echo "<style>body{font-family:monospace;padding:20px;background:#f5f5f5;}
      pre{background:#fff;padding:14px;border-radius:8px;overflow:auto;
          border:1px solid #ddd;max-height:600px;}
      h2{margin-top:24px;} .ok{color:#16a34a;} .err{color:#dc2626;}</style></head><body>";
echo "<h1>Тест SOAP 1С:ГОА</h1>";

/* --- Проверка окружения --- */
echo "<h2>1. Проверка окружения</h2><pre>";
echo "PHP версия:        " . PHP_VERSION . "\n";
echo "Расширение soap:   " . (extension_loaded('soap')   ? "<span class='ok'>OK</span>" : "<span class='err'>НЕТ</span>") . "\n";
echo "Расширение curl:   " . (extension_loaded('curl')   ? "<span class='ok'>OK</span>" : "<span class='err'>НЕТ</span>") . "\n";
echo "Расширение openssl:" . (extension_loaded('openssl')? "<span class='ok'>OK</span>" : "<span class='err'>НЕТ</span>") . "\n";
echo "ONEC_LOGIN:        " . ($LOGIN !== '' ? "задан (" . substr($LOGIN,0,3) . "***)" : "<span class='err'>НЕ ЗАДАН</span>") . "\n";
echo "ONEC_PASSWORD:     " . ($PASS  !== '' ? "задан" : "<span class='err'>НЕ ЗАДАН</span>") . "\n";
echo "allow_url_fopen:   " . (ini_get('allow_url_fopen') ? 'On' : 'Off') . "\n";
echo "</pre>";

if (!extension_loaded('soap')) {
    echo "<p class='err'>Без расширения soap работать не будет. Напишите хостеру RelaxDev: «нужно расширение soap для PHP».</p>";
    echo "</body></html>";
    exit;
}

/* --- Попытка подключения --- */
echo "<h2>2. Подключение к WSDL</h2><pre>";
$wsdlOk = false;
try {
    $opts = [
        'login'    => $LOGIN,
        'password' => $PASS,
        'trace'    => 1,
        'exceptions' => 1,
        'connection_timeout' => 15,
        'cache_wsdl' => WSDL_CACHE_NONE,
        'stream_context' => stream_context_create([
            'ssl' => ['verify_peer' => false, 'verify_peer_name' => false],
        ]),
    ];
    $client = new SoapClient($WSDL, $opts);
    $wsdlOk = true;
    echo "<span class='ok'>OK — WSDL загружен, клиент создан.</span>\n\n";

    echo "Доступные методы сервиса:\n";
    $funcs = $client->__getFunctions();
    foreach ($funcs as $f) {
        echo "  • " . htmlspecialchars($f) . "\n";
    }
    echo "\nТипы данных: " . count($client->__getTypes()) . " шт.\n";
} catch (Throwable $e) {
    echo "<span class='err'>ОШИБКА: " . htmlspecialchars($e->getMessage()) . "</span>\n";
}
echo "</pre>";

/* --- Тестовый вызов метода --- */
if ($wsdlOk) {
    echo "<h2>3. Тестовый вызов UnloadInstallationWorkloads</h2><pre>";

    $startDate   = date('Y-m-d', strtotime('-30 days'));
    $endDate     = date('Y-m-d');
    $modelShassis = trim($_GET['model'] ?? '54901-0070014-CA'); // комплектация по умолчанию

    echo "Параметры запроса:\n";
    echo "  StartDate    = {$startDate}\n";
    echo "  EndDate      = {$endDate}\n";
    echo "  ModelShassis = {$modelShassis}\n\n";

    try {
        $resp = $client->UnloadInstallationWorkloads([
            'StartDate'    => $startDate,
            'EndDate'      => $endDate,
            'ModelShassis' => $modelShassis,
        ]);

        echo "<span class='ok'>Ответ получен.</span>\n\n";
        echo "=== Структура ответа (верхний уровень) ===\n";
        echo htmlspecialchars(print_r(array_slice((array)$resp, 0, 3, true), true));
        echo "\n\n=== Сырой XML ответа (первые 3000 символов) ===\n";
        echo htmlspecialchars(substr($client->__getLastResponse() ?: '(пусто)', 0, 3000));
    } catch (Throwable $e) {
        echo "<span class='err'>ОШИБКА вызова: " . htmlspecialchars($e->getMessage()) . "</span>\n\n";
        echo "=== Последний запрос ===\n";
        echo htmlspecialchars($client->__getLastRequest() ?: '(пусто)');
        echo "\n\n=== Последний ответ ===\n";
        echo htmlspecialchars(substr($client->__getLastResponse() ?: '(пусто)', 0, 2000));
    }
    echo "</pre>";
}

echo "<p style='color:#888;margin-top:30px;'>После проверки удалите этот файл (test_soap.php) — он не должен висеть на продакшене.</p>";
echo "</body></html>";
