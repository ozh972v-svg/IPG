<?php
require __DIR__ . '/db.php';
start_session();
$user = current_user();
if (!$user || empty($user['is_admin'])) die('Только для администратора');
header('Content-Type: text/plain; charset=utf-8');

$pdo = get_db();

echo "=== Текущие VARCHAR-поля в work_operations ===\n";
$stmt = $pdo->query("
    SELECT column_name, character_maximum_length
      FROM information_schema.columns
     WHERE table_name = 'work_operations'
       AND data_type LIKE 'character%'
     ORDER BY column_name
");
$cols = $stmt->fetchAll();
foreach ($cols as $c) {
    echo "  {$c['column_name']}: " . ($c['character_maximum_length'] ?? '∞') . "\n";
}

echo "\n=== Расширяю поля до 500 символов ===\n";
foreach ($cols as $c) {
    $name = $c['column_name'];
    $maxLen = (int)$c['character_maximum_length'];
    if ($maxLen > 0 && $maxLen < 500) {
        try {
            $pdo->exec('ALTER TABLE work_operations ALTER COLUMN "' . $name . '" TYPE VARCHAR(500)');
            echo "  ✓ $name: $maxLen → 500\n";
        } catch (Throwable $e) {
            echo "  ✗ $name: " . $e->getMessage() . "\n";
        }
    }
}

echo "\n=== Новая структура ===\n";
$stmt = $pdo->query("
    SELECT column_name, character_maximum_length
      FROM information_schema.columns
     WHERE table_name = 'work_operations'
       AND data_type LIKE 'character%'
     ORDER BY column_name
");
foreach ($stmt->fetchAll() as $c) {
    echo "  {$c['column_name']}: " . ($c['character_maximum_length'] ?? '∞') . "\n";
}

echo "\n=== Готово. Удалите этот файл. ===\n";
