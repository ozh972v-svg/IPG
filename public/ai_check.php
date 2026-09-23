<?php
header('Content-Type: text/plain; charset=utf-8');
echo "=== Проверка окружения для ИИ (GigaChat) ===\n\n";

echo "PHP: " . PHP_VERSION . "\n\n";

$need = ['pdo', 'pdo_pgsql', 'curl', 'mbstring', 'json'];
foreach ($need as $ext) {
    echo "ext/$ext: " . (extension_loaded($ext) ? "✓ есть" : "✗ НЕТ") . "\n";
}
echo "\n";

require __DIR__ . '/db.php';
try {
    $pdo = get_db();
    echo "Подключение к БД: ✓\n";
} catch (Throwable $e) {
    echo "БД: ✗ ошибка — " . $e->getMessage() . "\n";
}
echo "\n";

echo "=== Переменные окружения ===\n";
$vars = ['GIGACHAT_AUTH_KEY', 'GIGACHAT_SCOPE', 'GIGACHAT_MODEL'];
foreach ($vars as $name) {
    $val = getenv($name);
    if ($val === false || $val === '') {
        echo "$name: ✗ НЕ ЗАДАН\n";
    } else {
        if ($name === 'GIGACHAT_AUTH_KEY') {
            $masked = mb_substr($val, 0, 8) . '...' . mb_substr($val, -6);
            echo "$name: ✓ задан ($masked)\n";
        } else {
            echo "$name: ✓ $val\n";
        }
    }
}
echo "\n";

/* === Тестовый вызов GigaChat === */
$authKey = getenv('GIGACHAT_AUTH_KEY');
$scope   = getenv('GIGACHAT_SCOPE') ?: 'GIGACHAT_API_PERS';
$model   = getenv('GIGACHAT_MODEL') ?: 'GigaChat';

if ($authKey) {
    echo "=== Тестовый вызов GigaChat ===\n";

    /* 1. Получаем токен */
    $rquid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $ch = curl_init('https://ngw.devices.sberbank.ru:9443/api/v2/oauth');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'scope=' . urlencode($scope),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'RqUID: ' . $rquid,
            'Authorization: Basic ' . $authKey,
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo "Ошибка curl при получении токена: $err\n";
        exit;
    }
    if ($code !== 200) {
        echo "HTTP $code при получении токена\n";
        echo "Ответ: " . mb_substr((string)$resp, 0, 600) . "\n";
        exit;
    }

    $tokenData = json_decode((string)$resp, true);
    $token = $tokenData['access_token'] ?? null;
    if (!$token) {
        echo "Токен не получен\n";
        exit;
    }
    echo "✓ Токен получен\n";

    /* 2. Отправляем тестовый запрос */
    $payload = [
        'model' => $model,
        'messages' => [
            ['role' => 'user', 'content' => 'Ответь одним словом: работает?'],
        ],
        'max_tokens' => 20,
    ];

    $ch = curl_init('https://gigachat.devices.sberbank.ru/api/v1/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Accept: application/json',
            'Authorization: Bearer ' . $token,
        ],
        CURLOPT_TIMEOUT        => 60,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo "Ошибка curl при запросе: $err\n";
    } elseif ($code !== 200) {
        echo "HTTP $code при запросе\n";
        echo "Ответ: " . mb_substr((string)$resp, 0, 600) . "\n";
    } else {
        $data = json_decode((string)$resp, true);
        $txt  = $data['choices'][0]['message']['content'] ?? '—';
        echo "Модель ответила: $txt\n";
        echo "✓ Всё работает — можно пользоваться ИИ в works.php и works_compass.php\n";
    }
} else {
    echo "Тестовый вызов пропущен: не задан GIGACHAT_AUTH_KEY.\n";
}
