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

// Проверяем файлы
if (empty($_FILES['photos']) || empty($_FILES['photos']['name'][0])) {
    redirect_error('Файлы не загружены');
}

$pdo = get_db();

// Сохраняем РА/VIN в таблицу keys (если его ещё нет)
try {
    $stmt = $pdo->prepare("
        INSERT INTO keys (key_type, key_value)
        VALUES (:kt, :kv)
        ON CONFLICT (key_type, key_value) DO NOTHING
    ");
    $stmt->execute([':kt' => $keyType, ':kv' => $keyValue]);
} catch (Throwable $e) {
    // не критично — просто пропускаем
}

$uploadDir = __DIR__ . '/uploads';
if (!is_dir($uploadDir)) {
    mkdir($uploadDir, 0777, true);
}

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
$maxSize = 20 * 1024 * 1024;

$savedCount = 0;
$errors = [];

$fileCount = count($_FILES['photos']['name']);

for ($i = 0; $i < $fileCount; $i++) {
    $err = $_FILES['photos']['error'][$i];
    if ($err !== UPLOAD_ERR_OK) {
        $errors[] = "Файл #" . ($i + 1) . ": ошибка загрузки (код $err)";
        continue;
    }

    $tmpPath = $_FILES['photos']['tmp_name'][$i];
    $origName = $_FILES['photos']['name'][$i];
    $fileSize = $_FILES['photos']['size'][$i];

    if ($fileSize > $maxSize) {
        $errors[] = "Файл #" . ($i + 1) . ": больше 20 МБ";
        continue;
    }

    $finfo = finfo_open(FILEINFO_MIME_TYPE);
    $mime = finfo_file($finfo, $tmpPath);
    finfo_close($finfo);

    if (!in_array($mime, $allowedMimes, true)) {
        $errors[] = "Файл #" . ($i + 1) . ": не изображение ($mime)";
        continue;
    }

    $ext = match ($mime) {
        'image/jpeg' => 'jpg',
        'image/png'  => 'png',
        'image/webp' => 'webp',
        'image/heic' => 'heic',
        'image/heif' => 'heif',
        default => 'jpg'
    };

    $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $keyValue);
    $fileName = date('Ymd_His') . '_' . $user['id'] . '_' . $safeKey . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $targetPath = $uploadDir . '/' . $fileName;

    if (!move_uploaded_file($tmpPath, $targetPath)) {
        $errors[] = "Файл #" . ($i + 1) . ": не удалось сохранить";
        continue;
    }

    try {
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
            ':file_size'  => $fileSize,
            ':mime_type'  => $mime,
        ]);
        $savedCount++;
    } catch (Throwable $e) {
        @unlink($targetPath);
        $errors[] = "Файл #" . ($i + 1) . ": ошибка базы";
    }
}

if ($savedCount === 0) {
    redirect_error('Не удалось загрузить ни одного файла. ' . implode('; ', $errors));
}

$msg = "Загружено файлов: $savedCount";
if ($errors) {
    $msg .= '. Ошибки: ' . implode('; ', $errors);
}

header('Location: gallery.php?uploaded=' . urlencode($msg));
exit;
