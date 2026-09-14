<?php
require __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');

try {
    $pdo = get_db();

    // Меняем логин
    $stmt = $pdo->prepare("UPDATE users SET email = :new WHERE email = :old");
    $stmt->execute([':new' => 'Oleg Zhukov', ':old' => 'oleg@ya.ru']);
    echo "Обновлено строк: " . $stmt->rowCount() . "\n\n";

    // Показываем всех
    $stmt = $pdo->query("SELECT id, email, name, is_admin FROM users ORDER BY id");
    echo "Пользователи после изменения:\n";
    foreach ($stmt as $u) {
        echo "  #{$u['id']} | логин: {$u['email']} | имя: " . ($u['name'] ?: '—') . " | admin: " . ($u['is_admin'] ? 'ДА' : 'нет') . "\n";
    }
} catch (Throwable $e) {
    echo "❌ Ошибка: " . $e->getMessage();
}
