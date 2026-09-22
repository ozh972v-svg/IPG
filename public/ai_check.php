<?php
header('Content-Type: text/plain; charset=utf-8');
echo "=== Проверка окружения для ИИ ===\n\n";

// 1. PHP
echo "PHP: " . PHP_VERSION . "\n\n";

// 2. Расширения PHP
$need = ['pdo', 'pdo_pgsql', 'curl', 'mbstring', 'json'];
foreach ($need as $ext) {
    echo "ext/$ext: " . (extension_loaded($ext) ? "✓ есть" : "✗ НЕТ") . "\n";
}
echo "\n";

// 3. pgvector — расширение PostgreSQL
require __DIR__ . '/db.php';
try {
    $pdo = get_db();
    echo "Подключение к БД: ✓\n";

    // Проверяем, доступно ли расширение vector
    $r = $pdo->query("SELECT name, default_version, installed_version
                        FROM pg_available_extensions
                       WHERE name = 'vector'")->fetch();
    if ($r) {
        echo "pgvector доступен: ✓ (версия {$r['default_version']})\n";
        if ($r['installed_version']) {
            echo "  установлен в БД: ✓ ({$r['installed_version']})\n";
        } else {
            echo "  установлен в БД: ✗ (нужно CREATE EXTENSION)\n";
        }
    } else {
        echo "pgvector: ✗ НЕ ДОСТУПЕН\n";
    }

    // Версия PostgreSQL
    $v = $pdo->query("SHOW server_version")->fetchColumn();
    echo "PostgreSQL: $v\n";
} catch (Throwable $e) {
    echo "БД: ✗ ошибка — " . $e->getMessage() . "\n";
}
echo "\n";

// 4. Composer
$composerVendor = __DIR__ . '/vendor/autoload.php';
echo "Composer vendor/: " . (is_file($composerVendor) ? "✓ есть" : "✗ нет") . "\n";
echo "shell_exec доступен: " . (function_exists('shell_exec') && shell_exec('echo ok') === "ok\n" ? "✓" : "✗") . "\n\n";

// 5. LLM-ключи
echo "OPENAI_API_KEY: " . (getenv('OPENAI_API_KEY') ? "✓ задан" : "✗ не задан") . "\n";
echo "ANTHROPIC_API_KEY: " . (getenv('ANTHROPIC_API_KEY') ? "✓ задан" : "✗ не задан") . "\n";

// 6. Сводка
echo "\n=== Готовность к ИИ ===\n";
echo "PHP + curl + pdo_pgsql: " . (extension_loaded('curl') && extension_loaded('pdo_pgsql') ? "✓" : "✗") . "\n";
