<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$keyType = trim($_POST['key_type'] ?? '');
$keyValue = trim($_POST['key_value'] ?? '');
$removeKey = !empty($_POST['remove_key']);

if (!$keyType || !$keyValue) {
    header('Location: gallery.php?error=' . urlencode('Не указан РА/VIN'));
    exit;
}

$pdo = get_db();

$stmt = $pdo->prepare('SELECT id, file_path, user_id FROM photos WHERE key_type = :kt AND key_value = :kv');
$stmt->execute([':kt' => $keyType, ':kv' => $keyValue]);
$photos = $stmt->fetchAll();

foreach ($photos as $p) {
    if ((int)$p['user_id'] !== (int)$user['id']) continue;
    $path = __DIR__ . '/' . $p['file_path'];
    if (file_exists($path)) @unlink($path);
    $stmt = $pdo->prepare('DELETE FROM photos WHERE id = :id');
    $stmt->execute([':id' => $p['id']]);
}

if ($removeKey) {
    $stmt = $pdo->prepare('DELETE FROM keys WHERE key_type = :kt AND key_value = :kv');
    $stmt->execute([':kt' => $keyType, ':kv' => $keyValue]);
    header('Location: gallery.php?deleted_all_key=1');
    exit;
}

header('Location: gallery.php?key_type=' . urlencode($keyType)
     . '&key_value=' . urlencode($keyValue)
     . '&deleted_all_photos=' . urlencode($keyType));
exit;
