<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user || !$user['is_admin']) {
    http_response_code(403);
    exit('Только для админа');
}

$pdo = get_db();

echo "<pre style='font-family:monospace;font-size:14px;padding:20px;'>";

/* 1. Добавляем колонку */
try {
    $pdo->exec("ALTER TABLE keys ADD COLUMN IF NOT EXISTS description TEXT");
    echo "✅ Шаг 1: ALTER TABLE keys ADD COLUMN description — выполнено.\n\n";
} catch (Throwable $e) {
    echo "❌ Шаг 1 ошибка: " . $e->getMessage() . "\n\n";
}

/* 2. Удаляем запись о миграции, чтобы при следующем запуске она применилась правильно */
try {
    $stmt = $pdo->prepare("DELETE FROM schema_migrations WHERE filename = :f");
    $stmt->execute([':f' => '009_add_keys_description.sql']);
    echo "✅ Шаг 2: запись о миграции 009 удалена (было удалено строк: " . $stmt->rowCount() . ").\n\n";
} catch (Throwable $e) {
    echo "⚠️ Шаг 2: не удалось удалить запись (таблица schema_migrations может называться иначе): " . $e->getMessage() . "\n\n";
}

/* 3. Проверяем, что колонка появилась */
echo "=== Колонки таблицы keys ===\n\n";
try {
    $cols = $pdo->query("
        SELECT column_name, data_type
          FROM information_schema.columns
         WHERE table_name = 'keys'
         ORDER BY ordinal_position
    ")->fetchAll();
    foreach ($cols as $c) {
        echo str_pad($c['column_name'], 20) . $c['data_type'] . "\n";
    }
} catch (Throwable $e) {
    echo "Ошибка проверки: " . $e->getMessage();
}

echo "\n=== Готово. Удали этот файл apply_description.php после проверки. ===\n";
echo "</pre>";
