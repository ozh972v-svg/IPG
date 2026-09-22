<?php
header('Content-Type: text/plain; charset=utf-8');
echo "=== Проверка окружения для ИИ ===\n\n";

echo "PHP: " . PHP_VERSION . "\n\n";

$need = ['pdo', 'pdo_pgsql', 'curl', 'mbstring', 'json'];
foreach ($need as $ext) {
    echo "ext/$ext: " . (extension_loaded($ext) ? "✓ есть" : "✗ НЕТ") . "\n";
}
echo "\n";

// БД
require __DIR__ . '/db.php';
try {
    $pdo = get_db();
    echo "Подключение к БД: ✓\n";
    $r = $pdo->query("SELECT name FROM pg_available_extensions WHERE name = 'vector'")->fetch();
    echo "pgvector: " . ($r ? "✓" : "✗ НЕ ДОСТУПЕН") . "\n";
    $v = $pdo->query("SHOW server_version")->fetchColumn();
    echo "PostgreSQL: $v\n";
} catch (Throwable $e) {
    echo "БД: ✗ ошибка — " . $e->getMessage() . "\n";
}
echo "\n";

// ==== ГЛАВНОЕ: переменные для ИИ ====
echo "=== Переменные окружения для ИИ ===\n";
$aiVars = ['AI_API_KEY', 'AI_BASE_URL', 'AI_MODEL'];
foreach ($aiVars as $name) {
    $val = getenv($name);
    if ($val === false || $val === '') {
        echo "$name: ✗ НЕ ЗАДАН\n";
    } else {
        // Показываем только начало ключа, чтобы не утекло в скриншот
        if ($name === 'AI_API_KEY') {
            $masked = mb_substr($val, 0, 10) . '...' . mb_substr($val, -4);
            echo "$name: ✓ задан ($masked)\n";
        } else {
            echo "$name: ✓ $val\n";
        }
    }
}
echo "\n";

// Тестовый вызов LLM
$apiKey  = getenv('AI_API_KEY');
$baseUrl = rtrim((string)getenv('AI_BASE_URL'), '/');
$model   = getenv('AI_MODEL');

if ($apiKey && $baseUrl && $model) {
    echo "=== Тестовый вызов модели ===\n";
    $payload = [
        'model'      => $model,
        'messages'   => [['role' => 'user', 'content' => 'Ответь одним словом: работает?']],
        'max_tokens' => 20,
    ];
    $ch = curl_init($baseUrl . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
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
        echo "Ошибка curl: $err\n";
    } elseif ($code !== 200) {
        echo "HTTP $code\n";
        echo "Ответ: " . mb_substr((string)$resp, 0, 600) . "\n";
    } else {
        $data = json_decode((string)$resp, true);
        $txt  = $data['choices'][0]['message']['content'] ?? '—';
        echo "Модель ответила: $txt\n";
        echo "✓ Всё работает — можно открывать ai_assistant.php\n";
    }
} else {
    echo "Тестовый вызов пропущен: не заданы переменные AI_API_KEY / AI_BASE_URL / AI_MODEL.\n";
    echo "Добавьте их в ENV хостинга RelaxDev и обновите страницу.\n";
}
