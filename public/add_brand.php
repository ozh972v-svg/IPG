<?php
require __DIR__ . '/db.php';
start_session();
$user = current_user();
if (!$user) { die('Не авторизован'); }
if (!$user['is_admin']) { die('Только для администратора'); }

header('Content-Type: text/plain; charset=utf-8');
$pdo = get_db();

echo "=== Проверка и создание колонки brand ===\n\n";

// Проверяем, есть ли уже колонка
$stmt = $pdo->prepare("
    SELECT column_name
      FROM information_schema.columns
     WHERE table_name = 'keys' AND column_name = 'brand'
");
$stmt->execute();
$exists = $stmt->fetchColumn();

if ($exists) {
    echo "✓ Колонка brand уже существует в таблице keys\n";
} else {
    echo "→ Создаю колонку brand...\n";
    try {
        $pdo->exec("ALTER TABLE keys ADD COLUMN IF NOT EXISTS brand VARCHAR(20)");
        echo "✓ Колонка brand успешно добавлена\n";
    } catch (Throwable $e) {
        echo "✗ Ошибка: " . $e->getMessage() . "\n";
        exit;
    }
}

// Индекс
echo "\n→ Создаю индекс idx_keys_brand...\n";
try {
    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_keys_brand ON keys(brand)");
    echo "✓ Индекс создан\n";
} catch (Throwable $e) {
    echo "⚠ Индекс: " . $e->getMessage() . "\n";
}

// Финальная проверка структуры таблицы
echo "\n=== Текущая структура таблицы keys ===\n";
$stmt = $pdo->query("
    SELECT column_name, data_type
      FROM information_schema.columns
     WHERE table_name = 'keys'
     ORDER BY ordinal_position
");
foreach ($stmt->fetchAll() as $c) {
    echo "  " . $c['column_name'] . " — " . $c['data_type'] . "\n";
}

echo "\n=== Готово! ===\n";
echo "Теперь можно удалить этот файл (add_brand.php) из GitHub.\n";
