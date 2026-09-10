<?php
/**
 * Одноразовый скрипт — создаёт таблицы в базе.
 * После использования УДАЛИТЬ!
 */

require __DIR__ . '/db.php';

header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();
    echo "✅ Подключение к базе: OK\n\n";

    $sql = "
CREATE TABLE IF NOT EXISTS users (
    id SERIAL PRIMARY KEY,
    email VARCHAR(255) UNIQUE NOT NULL,
    password_hash VARCHAR(255) NOT NULL,
    name VARCHAR(255),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE TABLE IF NOT EXISTS photos (
    id SERIAL PRIMARY KEY,
    user_id INT NOT NULL REFERENCES users(id) ON DELETE CASCADE,
    key_type VARCHAR(10) NOT NULL,
    key_value VARCHAR(255) NOT NULL,
    photo_type VARCHAR(50) NOT NULL,
    comment TEXT,
    file_path VARCHAR(500) NOT NULL,
    file_size INT,
    mime_type VARCHAR(100),
    created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);

CREATE INDEX IF NOT EXISTS idx_photos_key ON photos(key_type, key_value);
CREATE INDEX IF NOT EXISTS idx_photos_user ON photos(user_id);
CREATE INDEX IF NOT EXISTS idx_photos_created ON photos(created_at DESC);
";

    $pdo->exec($sql);
    echo "✅ Таблицы созданы:\n";
    echo "   - users\n";
    echo "   - photos\n\n";

    $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Таблицы в базе: " . implode(', ', $tables) . "\n\n";

    echo "🎉 Установка завершена. УДАЛИ ЭТОТ ФАЙЛ (install.php) ПОСЛЕ УСПЕШНОГО ЗАПУСКА!\n";

} catch (Throwable $e) {
    echo "❌ Ошибка:\n";
    echo $e->getMessage() . "\n\n";
    echo "Стек:\n" . $e->getTraceAsString();
}
