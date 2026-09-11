<?php
require __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();
    echo "✅ Подключение к базе: OK\n\n";

    $pdo->exec("
        CREATE TABLE IF NOT EXISTS keys (
            id SERIAL PRIMARY KEY,
            key_type VARCHAR(10) NOT NULL,
            key_value VARCHAR(255) NOT NULL,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            UNIQUE(key_type, key_value)
        );
    ");
    echo "✅ Таблица keys создана\n";

    $pdo->exec("CREATE INDEX IF NOT EXISTS idx_keys_type ON keys(key_type);");
    echo "✅ Индекс создан\n\n";

    $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Таблицы в базе: " . implode(', ', $tables) . "\n";

} catch (Throwable $e) {
    echo "❌ Ошибка: " . $e->getMessage();
}
