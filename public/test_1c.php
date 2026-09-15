<?php
header('Content-Type: text/plain; charset=utf-8');

$login = getenv('ONEC_LOGIN');
$password = getenv('ONEC_PASSWORD');

echo "=== ПРОВЕРКА ПЕРЕМЕННЫХ ===\n";
echo "ONEC_LOGIN: " . ($login ? '✅ ' . $login : '❌ НЕ НАЙДЕН') . "\n";
echo "ONEC_PASSWORD: " . ($password ? '✅ (скрыт)' : '❌ НЕ НАЙДЕН') . "\n";
echo "RELAXDEV_PROXY_URL: " . (getenv('RELAXDEV_PROXY_URL') ?: '—') . "\n\n";

if (!$login || !$password) {
    echo "❌ Переменные не настроены. Добавь ONEC_LOGIN и ONEC_PASSWORD в RelaxDev → Переменные → Редeploy.\n";
    exit;
}

// === Тестовый запрос к 1С ===
$number = $_GET['number'] ?? '1433081';
$url = 'https://web-1c.kamaz.ru/GOA/hs/CarData/V1/NumberChassis?Number=' . urlencode($number);

echo "=== ЗАПРОС К 1С ===\n";
echo "URL: $url\n\n";

$ch = curl_init($url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    CURLOPT_USERPWD => $login . ':' . $password,
    CURLOPT_TIMEOUT => 30,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
    CURLOPT_HTTPHEADER => [
        'Accept: application/json',
    ],
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

echo "HTTP код: $httpCode\n";
if ($curlError) {
    echo "Ошибка curl: $curlError\n\n";
    echo "Возможные причины:\n";
    echo "  1. Сервер 1С недоступен из интернета (закрыт корпоративной сетью)\n";
    echo "  2. Нужно использовать прокси RelaxDev (RELAXDEV_PROXY_URL)\n";
    echo "  3. Блокировка по IP\n";
    exit;
}

echo "\n=== ОТВЕТ ===\n";
echo substr($response, 0, 3000) . "\n";
