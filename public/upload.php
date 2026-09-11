<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Location: gallery.php');
    exit;
}

function redirect_error(string $msg): void {
    header('Location: gallery.php?error=' . urlencode($msg));
    exit;
}

// Проверяем поля
$keyType = $_POST['key_type'] ?? '';
$keyValue = trim($_POST['key_value'] ?? '');
$photoType = $_POST['photo_type'] ?? '';
$comment = trim($_POST['comment'] ?? '');

if (!in_array($keyType, ['ra', 'vin'], true)) {
    redirect_error('Неверный тип привязки');
}
if ($keyValue === '') {
    redirect_error('Укажите номер РА или VIN');
}

$allowedTypes = [
    'general', 'vin', 'odometer', 'before_dismount',
    'after_dismount', 'marking', 'manifestation', 'numbered_unit'
];
if (!in_array($photoType, $allowedTypes, true)) {
    redirect_error('Неверный тип фото');
}

// Проверяем файл
if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    redirect_error('Файл не загружен или ошибка загрузки');
}

$file = $_FILES['photo'];
$maxSize = 10 * 1024 * 1024; // 10 МБ
if ($file['size'] > $maxSize) {
    redirect_error('Файл больше 10 МБ');
}

// Проверяем MIME
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
if (!in_array($mime, $allowedMimes, true)) {
    redirect_error('Разрешены только изображения (JPG, PNG, WEBP, HEIC)');
}

// Формируем имя файла
$ext = match ($mime) {
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
    default => 'jpg'
};

$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $keyValue);
$fileName = date('Ymd_His') . '_' . $user['id'] . '_' . $safeKey . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
$targetPath = $uploadDir . '/' . $fileName;

if (!move_uploaded_file($file['tmp_name'], $targetPath)) {
    redirect_error('Не удалось сохранить файл');
}

// Записываем в базу
try {
    $pdo = get_db();
    $stmt = $pdo->prepare('
        INSERT INTO photos (user_id, key_type, key_value, photo_type, comment, file_path, file_size, mime_type)
        VALUES (:user_id, :key_type, :key_value, :photo_type, :comment, :file_path, :file_size, :mime_type)
    ');
    $stmt->execute([
        ':user_id'    => $user['id'],
        ':key_type'   => $keyType,
        ':key_value'  => $keyValue,
        ':photo_type' => $photoType,
        ':comment'    => $comment ?: null,
        ':file_path'  => 'uploads/' . $fileName,
        ':file_size'  => $file['size'],
        ':mime_type'  => $mime,
    ]);
} catch (Throwable $e) {
    @unlink($targetPath);
    redirect_error('Ошибка базы: ' . $e->getMessage());
}

header('Location: gallery.php?uploaded=1');
exit;
