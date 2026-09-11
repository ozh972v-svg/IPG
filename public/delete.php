<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if ($id <= 0) {
    header('Location: gallery.php?error=' . urlencode('Неверный ID фото'));
    exit;
}

$pdo = get_db();
$stmt = $pdo->prepare('SELECT * FROM photos WHERE id = :id');
$stmt->execute([':id' => $id]);
$photo = $stmt->fetch();

if (!$photo) {
    header('Location: gallery.php?error=' . urlencode('Фото не найдено'));
    exit;
}

// Удалять может только автор
if ((int)$photo['user_id'] !== (int)$user['id']) {
    header('Location: gallery.php?error=' . urlencode('Можно удалять только свои фото'));
    exit;
}

// Удаляем файл
$path = __DIR__ . '/' . $photo['file_path'];
if (file_exists($path)) {
    @unlink($path);
}

// Удаляем запись
$stmt = $pdo->prepare('DELETE FROM photos WHERE id = :id');
$stmt->execute([':id' => $id]);

header('Location: gallery.php?deleted=1');
exit;
