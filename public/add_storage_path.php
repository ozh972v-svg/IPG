<?php
require __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');
try {
    $pdo = get_db();
    $pdo->exec("ALTER TABLE photos ADD COLUMN IF NOT EXISTS storage_path VARCHAR(500)");
    echo "✅ Поле storage_path добавлено\n";
    $stmt = $pdo->query("SELECT column_name FROM information_schema.columns WHERE table_name = 'photos'");
    echo "Поля в photos: " . implode(', ', $stmt->fetchAll(PDO::FETCH_COLUMN)) . "\n";
} catch (Throwable $e) {
    echo "❌ Ошибка: " . $e->getMessage();
}
