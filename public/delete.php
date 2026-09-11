<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$backKeyType = trim($_POST['back_key_type'] ?? '');
$backKeyValue = trim($_POST['back_key_value'] ?? '');

// Куда возвращать после удаления
$backUrl = 'gallery.php?deleted=1';
if ($backKeyType && $backKeyValue) {
    $backUrl = 'gallery.php?key_type=' . urlencode($backKeyType)
             . '&key_value=' . urlencode($backKeyValue)
             . '&deleted=1';
}

if ($id <= 0) {
    header('Location: gallery.php?error=' . urlencode('Неверный ID фото'));
    exit;
}

$pdo = get_db();
$stmt = $pdo->prepare('SELECT * FROM photos WHERE id = :id');
$stmt->execute([':id' => $id]);
$photo = $stmt->fetch();

if (!$photo) {
    header('Location: ' . $backUrl);
    exit;
}

// Удалять может только автор
if ((int)$photo['user_id'] !== (int)$user['id']) {
    header('Location: gallery.php?error=' . urlencode('Можно удалять только свои фото'));
    exit;
}

// Если в POST не передали ключ — берём из самого фото
if (!$backKeyType || !$backKeyValue) {
    $backKeyType = $photo['key_type'];
    $backKeyValue = $photo['key_value'];
    $backUrl = 'gallery.php?key_type=' . urlencode($backKeyType)
             . '&key_value=' . urlencode($backKeyValue)
             . '&deleted=1';
}

// Удаляем файл
$path = __DIR__ . '/' . $photo['file_path'];
if (file_exists($path)) {
    @unlink($path);
}

// Удаляем запись из базы
$stmt = $pdo->prepare('DELETE FROM photos WHERE id = :id');
$stmt->execute([':id' => $id]);

header('Location: ' . $backUrl);
exit;
