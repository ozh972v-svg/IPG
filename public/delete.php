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

if ((int)$photo['user_id'] !== (int)$user['id']) {
    header('Location: gallery.php?error=' . urlencode('Можно удалять только свои фото'));
    exit;
}

if (!$backKeyType || !$backKeyValue) {
    $backKeyType = $photo['key_type'];
    $backKeyValue = $photo['key_value'];
    $backUrl = 'gallery.php?key_type=' . urlencode($backKeyType)
             . '&key_value=' . urlencode($backKeyValue)
             . '&deleted=1';
}

// === 1. Удалить из хранилища IzIPost (если есть storage_path) ===
$storageKey = getenv('STORAGE_API_KEY');
if ($storageKey && !empty($photo['storage_path'])) {
    // НЕ кодируем слэши — только проблемные символы
    $pathForUrl = str_replace(
        [' ', '&', '#', '?', '+'],
        ['%20', '%26', '%23', '%3F', '%2B'],
        $photo['storage_path']
    );
    $apiUrl = 'https://relaxdev.ru/api/v1/storage/files?path=' . $pathForUrl;

    $ch = curl_init($apiUrl);
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST => 'DELETE',
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER => [
            'Authorization: Bearer ' . $storageKey,
        ],
        CURLOPT_TIMEOUT => 30,
    ]);
    curl_exec($ch);
    curl_close($ch);
}

// === 2. Удалить старый файл с диска (для фото, загруженных до интеграции) ===
if (empty($photo['storage_path'])) {
    $path = __DIR__ . '/' . $photo['file_path'];
    if (file_exists($path)) {
        @unlink($path);
    }
}

// === 3. Удалить запись из БД ===
$stmt = $pdo->prepare('DELETE FROM photos WHERE id = :id');
$stmt->execute([':id' => $id]);

header('Location: ' . $backUrl);
exit;
