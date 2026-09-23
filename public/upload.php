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

/* Определяем, это AJAX-запрос (fetch) или обычная форма */
$isAjax = (
    ($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'fetch'
    || strpos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false
);

/* Универсальный ответ об успехе */
function respond_ok(string $fileUrl, string $kt, string $kv): void {
    global $isAjax;
    $redirect = 'gallery.php?key_type=' . urlencode($kt) . '&key_value=' . urlencode($kv) . '&uploaded=1';

    if ($isAjax) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok'       => true,
            'url'      => $fileUrl,
            'redirect' => $redirect,
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: ' . $redirect);
    exit;
}

/* Универсальный ответ об ошибке */
function redirect_error(string $msg, string $kt = '', string $kv = ''): void {
    global $isAjax;

    if ($isAjax) {
        http_response_code(400);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'error' => $msg], JSON_UNESCAPED_UNICODE);
        exit;
    }

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

$keyType   = $_POST['key_type'] ?? '';
$keyValue  = trim($_POST['key_value'] ?? '');
$photoType = $_POST['photo_type'] ?? '';
$comment   = trim($_POST['comment'] ?? '');

/* Расширенный whitelist типов привязки */
if (!in_array($keyType, ['ra', 'vin', 'order', 'gos'], true)) {
    redirect_error('Неверный тип привязки: ' . htmlspecialchars($keyType));
}
if ($keyValue === '') {
    redirect_error('Укажите номер РА, VIN, заказ-наряд или гос.номер');
}

$allowedTypes = [
    'general', 'vin', 'odometer', 'before_dismount',
    'after_dismount', 'marking', 'manifestation', 'numbered_unit',
    'other', 'video_defect'
];
if (!in_array($photoType, $allowedTypes, true)) {
    redirect_error('Неверный тип фото: ' . htmlspecialchars($photoType));
}

/* Явные проверки ошибок $_FILES */
if (empty($_FILES['photo'])) {
    redirect_error('Файл не пришёл на сервер (поле photo пустое)', $keyType, $keyValue);
}

$errCode = $_FILES['photo']['error'] ?? -1;
if ($errCode !== UPLOAD_ERR_OK) {
    $iniMax = ini_get('upload_max_filesize');
    $postMax = ini_get('post_max_size');
    $msg = match($errCode) {
        UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE
            => "Файл больше лимита хостинга. Лимит upload_max_filesize = {$iniMax}, post_max_size = {$postMax}. Сожмите фото перед загрузкой.",
        UPLOAD_ERR_PARTIAL
            => 'Файл загружен частично (обрыв соединения). Попробуйте ещё раз.',
        UPLOAD_ERR_NO_FILE
            => 'Файл не был выбран.',
        UPLOAD_ERR_NO_TMP_DIR
            => 'На сервере нет временной папки для загрузки.',
        UPLOAD_ERR_CANT_WRITE
            => 'Сервер не может записать файл на диск.',
        UPLOAD_ERR_EXTENSION
            => 'Загрузка заблокирована расширением PHP.',
        default
            => 'Неизвестная ошибка загрузки (код ' . $errCode . ').',
    };
    redirect_error($msg, $keyType, $keyValue);
}

$file = $_FILES['photo'];

/* MIME через finfo */
$finfo = finfo_open(FILEINFO_MIME_TYPE);
$mime = finfo_file($finfo, $file['tmp_name']);
finfo_close($finfo);

$allowedImageMimes = ['image/jpeg', 'image/png', 'image/webp', 'image/heic', 'image/heif'];
$allowedVideoMimes = ['video/mp4', 'video/quicktime', 'video/webm', 'video/x-m4v', 'video/3gpp'];

/* Fallback: если MIME = octet-stream или пустой — определяем по расширению файла */
$ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
$imageExts = ['jpg','jpeg','png','webp','heic','heif'];
$videoExts = ['mp4','mov','webm','m4v','3gp'];

$mimeOkImage = in_array($mime, $allowedImageMimes, true);
$mimeOkVideo = in_array($mime, $allowedVideoMimes, true);

if (!$mimeOkImage && !$mimeOkVideo) {
    if (in_array($ext, $imageExts, true)) {
        $mime = ($ext === 'jpg') ? 'image/jpeg' : ('image/' . $ext);
        $mimeOkImage = true;
    } elseif (in_array($ext, $videoExts, true)) {
        $mime = ($ext === 'mov') ? 'video/quicktime' : ('video/' . $ext);
        $mimeOkVideo = true;
    }
}

$isVideo = $mimeOkVideo || (strpos($mime, 'video/') === 0);

if ($isVideo) {
    if (!$mimeOkVideo) {
        redirect_error('Разрешены только видео MP4, MOV, WEBM, M4V, 3GP. Определён тип: ' . htmlspecialchars($mime), $keyType, $keyValue);
    }
    $maxSize = 200 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        redirect_error('Видео больше 200 МБ (' . round($file['size'] / 1048576, 1) . ' МБ)', $keyType, $keyValue);
    }
} else {
    if (!$mimeOkImage) {
        redirect_error('Разрешены только изображения (JPG, PNG, WEBP, HEIC). Определён тип: ' . htmlspecialchars($mime) . ', расширение: ' . htmlspecialchars($ext), $keyType, $keyValue);
    }
    $maxSize = 20 * 1024 * 1024;
    if ($file['size'] > $maxSize) {
        redirect_error('Файл больше 20 МБ (' . round($file['size'] / 1048576, 1) . ' МБ). Сожмите фото перед загрузкой.', $keyType, $keyValue);
    }
}

/* Токен хранилища */
$storageKey = getenv('STORAGE_API_KEY');
if (!$storageKey) {
    redirect_error('STORAGE_API_KEY не настроен.', $keyType, $keyValue);
}

/* Загрузка в хранилище RelaxDev */
$safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $keyValue);
$subPath = 'photos/' . $keyType . '-' . $safeKey;

$cfile = new CURLFile($file['tmp_name'], $mime, $file['name']);

$ch = curl_init('https://relaxdev.ru/api/v1/storage/upload');
curl_setopt_array($ch, [
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => [
        'file' => $cfile,
        'path' => $subPath,
        'webp' => 'false',
    ],
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_HTTPHEADER     => ['Authorization: Bearer ' . $storageKey],
    CURLOPT_TIMEOUT        => 120,
]);
$response  = curl_exec($ch);
$httpCode  = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$curlError = curl_error($ch);
curl_close($ch);

if ($curlError) {
    redirect_error('Ошибка хранилища: ' . $curlError, $keyType, $keyValue);
}

$data = json_decode($response, true);
if ($httpCode < 200 || $httpCode >= 300 || empty($data['url'])) {
    redirect_error('Хранилище вернуло ошибку ' . $httpCode . ': ' . substr((string)$response, 0, 300), $keyType, $keyValue);
}

$fileUrl     = $data['url'];
$storagePath = $data['path'] ?? '';

/* Сохранение в БД */
try {
    $pdo = get_db();

    $gosNumber   = trim($_POST['gos_number'] ?? '');
    $orderNumber = trim($_POST['order_number'] ?? '');

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
        ':gos' => $gosNumber   ?: null,
        ':ord' => $orderNumber ?: null,
        ':uid' => $user['id'],
        ':uid2'=> $user['id'],
    ]);

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
    redirect_error('Ошибка базы данных: ' . $e->getMessage(), $keyType, $keyValue);
}

respond_ok($fileUrl, $keyType, $keyValue);
