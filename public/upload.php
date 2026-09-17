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

function redirect_error(string $msg, string $kt = '', string $kv = ''): void {
    $back = 'gallery.php';
    if ($kt && $kv) {
        $back = 'gallery.php?key_type=' . urlencode($kt) . '&key_value=' . urlencode($kv);
        $back .= '&error=' . urlencode($msg);
    } else {
        $back .= '?error=' . urlencode($msg);
    }
    header('Location: ' . $back);
    exit;
}

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

if (empty($_FILES['photo']) || $_FILES['photo']['error'] !== UPLOAD_ERR_OK) {
    redirect_error('Файл не загружен', $keyType, $keyValue);
}

$file = $_FILES['photo'];
$maxSize = 20 * 1024 * 1024;
if ($file['size'] > $maxSize) {
    redirect_error('Файл больше 20 МБ', $keyType, $keyValue);
}

$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
if (!in_array($mime, $allowedMimes, true)) {
    redirect_error('Разрешены только изображения (JPG, PNG, WEBP, HEIC)', $keyType, $keyValue);
}

// === Токен хранилища ===
$storageKey = getenv('STORAGE_API_KEY');
if (!$storageKey) {
    redirect_error('STORAGE_API_KEY не настроен. Запустите реплой проекта.', $keyType, $keyValue);
}

// === Загрузка в IzIPost ===
$safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $keyValue);
$subPath = 'photos/' . $keyType . '-' . $safeKey;

$cfile = new CURLFile($file['tmp_name'], $mime, $file['name']);

$ch = curl_init('https://relaxdev.ru/api/v1/storage/upload');
curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => [
        'file' => $cfile,
        'path' => $subPath,
        'webp' => 'false', // сохраняем оригинал как есть
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER => [
        'Authorization: Bearer ' . $storageKey,
    ],
    CURLOPT_TIMEOUT => 60,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    redirect_error('Ошибка хранилища: ' . $curlError, $keyType, $keyValue);
}

$data = json_decode($response, true);
if ($httpCode < 200 || $httpCode >= 300 || empty($data['url'])) {
    redirect_error('Хранилище вернуло ошибку: ' . substr((string)$response, 0, 200), $keyType, $keyValue);
}

$fileUrl = $data['url'];
$storagePath = $data['path'] ?? '';

// === Сохранение в БД ===
try {
    $pdo = get_db();

        // Гос. номер и номер заказ-наряда (приходят из формы)
    $gosNumber   = trim($_POST['gos_number'] ?? '');
    $orderNumber = trim($_POST['order_number'] ?? '');

    // Ключ (РА/VIN) в таблицу keys
    $stmt = $pdo->prepare("
        INSERT INTO keys (key_type, key_value, gos_number, order_number, user_id, created_at, updated_by, updated_at)
        VALUES (:kt, :kv, :gos, :ord, :uid, NOW(), :uid2, NOW())
        ON CONFLICT (key_type, key_value) DO UPDATE
            SET gos_number   = COALESCE(NULLIF(EXCLUDED.gos_number, ''),   keys.gos_number),
                order_number = COALESCE(NULLIF(EXCLUDED.order_number, ''), keys.order_number),
                updated_by   = EXCLUDED.updated_by,
                updated_at   = NOW()
    ");
    $stmt->execute([
        ':kt'  => $keyType,
        ':kv'  => $keyValue,
        ':gos' => $gosNumber ?: null,
        ':ord' => $orderNumber ?: null,
        ':uid' => $user['id'],
        ':uid2'=> $user['id'],
    ]);

    // Фото
    $stmt = $pdo->prepare('
        INSERT INTO photos
            (user_id, key_type, key_value, photo_type, comment, file_path, storage_path, file_size, mime_type)
        VALUES
            (:user_id, :key_type, :key_value, :photo_type, :comment, :file_path, :storage_path, :file_size, :mime_type)
    ');
    $stmt->execute([
        ':user_id'      => $user['id'],
        ':key_type'     => $keyType,
        ':key_value'    => $keyValue,
        ':photo_type'   => $photoType,
        ':comment'      => $comment ?: null,
        ':file_path'    => $fileUrl,
        ':storage_path' => $storagePath,
        ':file_size'    => $file['size'],
        ':mime_type'    => $mime,
    ]);
} catch (Throwable $e) {
    redirect_error('Ошибка базы: ' . $e->getMessage(), $keyType, $keyValue);
}

header('Location: gallery.php?key_type=' . urlencode($keyType) . '&key_value=' . urlencode($keyValue) . '&uploaded=1');
exit;
