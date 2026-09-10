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

    // Читаем schema.sql из корня проекта
    $schema_path = __DIR__ . '/../schema.sql';
    if (!file_exists($schema_path)) {
        throw new RuntimeException('Не найден файл schema.sql в корне проекта');
    }

    $sql = file_get_contents($schema_path);
    if ($sql === false || trim($sql) === '') {
        throw new RuntimeException('schema.sql пустой');
    }

    // Выполняем все команды
    $pdo->exec($sql);
    echo "✅ Таблицы созданы:\n";
    echo "   - users\n";
    echo "   - photos\n\n";

    // Проверяем, что таблицы реально есть
    $stmt = $pdo->query("SELECT tablename FROM pg_tables WHERE schemaname = 'public' ORDER BY tablename");
    $tables = $stmt->fetchAll(PDO::FETCH_COLUMN);
    echo "Таблицы в базе: " . implode(', ', $tables) . "\n\n";

    echo "🎉 Установка завершена. УДАЛИ ЭТОТ ФАЙЛ (install.php) ПОСЛЕ УСПЕШНОГО ЗАПУСКА!\n";

} catch (Throwable $e) {
    http_response_code(500);
    echo "❌ Ошибка:\n";
    echo $e->getMessage() . "\n\n";
    echo "Стек:\n" . $e->getTraceAsString();
}
