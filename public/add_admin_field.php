<?php
require __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();

    // 1. Добавляем поле is_admin
    $pdo->exec("ALTER TABLE users ADD COLUMN IF NOT EXISTS is_admin BOOLEAN DEFAULT FALSE");
    echo "✅ Поле is_admin добавлено\n\n";

    // 2. Показываем всех пользователей
    $stmt = $pdo->query("SELECT id, email, name, is_admin FROM users ORDER BY id");
    $users = $stmt->fetchAll();
    echo "Пользователи в БД:\n";
    foreach ($users as $u) {
        echo "  #{$u['id']} | логин: {$u['email']} | имя: " . ($u['name'] ?: '—') . " | admin: " . ($u['is_admin'] ? 'ДА' : 'нет') . "\n";
    }
    echo "\n";

    // 3. Назначаем oleg@ya.ru админом
    $stmt = $pdo->prepare("UPDATE users SET is_admin = TRUE WHERE email = :email");
    $stmt->execute([':email' => 'oleg@ya.ru']);
    echo "Назначено админом: " . $stmt->rowCount() . " пользователей\n\n";

    // 4. Проверяем
    $stmt = $pdo->query("SELECT id, email, is_admin FROM users ORDER BY id");
    echo "После обновления:\n";
    foreach ($stmt as $u) {
        echo "  #{$u['id']} | логин: {$u['email']} | admin: " . ($u['is_admin'] ? 'ДА' : 'нет') . "\n";
    }

} catch (Throwable $e) {
    echo "❌ Ошибка: " . $e->getMessage();
}
