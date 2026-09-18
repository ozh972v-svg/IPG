<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$id      = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$key     = trim($_GET['key'] ?? '');
$keyType = trim($_GET['key_type'] ?? '');
$all     = !empty($_GET['all']);

$pdo = get_db();

/* Карта mime → расширение */
$MIME_EXT = [
    'image/jpeg' => 'jpg',
    'image/jpg'  => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
    'image/gif'  => 'gif',
];

/**
 * Скачивает файл по URL или читает локально.
 * Возвращает бинарное содержимое или null.
 */
function fetch_file(string $path): ?string {
    if (preg_match('~^https?://~i', $path)) {
        $ch = curl_init($path);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);
        if ($err || $code < 200 || $code >= 300) return null;
        return $body === false ? null : $body;
    }
    // Локальный путь
    $local = __DIR__ . '/' . ltrim($path, '/');
    if (is_file($local)) return file_get_contents($local);
    return null;
}

/**
 * Определяет расширение по mime и/или URL.
 */
function detect_ext(?string $mime, string $url): string {
    global $MIME_EXT;
    if ($mime && isset($MIME_EXT[strtolower($mime)])) return $MIME_EXT[strtolower($mime)];
    $path = parse_url($url, PHP_URL_PATH) ?: '';
    $ext  = strtolower(pathinfo($path, PATHINFO_EXTENSION));
    if ($ext && preg_match('/^[a-z0-9]{2,5}$/', $ext)) return $ext;
    return 'jpg';
}

/* ============================================================
   ОДИНОЧНОЕ ФОТО
   ============================================================ */
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM photos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $photo = $stmt->fetch();
    if (!$photo) { http_response_code(404); exit('Фото не найдено'); }

    $body = fetch_file($photo['file_path']);
    if ($body === null) { http_response_code(404); exit('Файл не найден в хранилище'); }

    $ext = detect_ext($photo['mime_type'] ?? null, $photo['file_path']);
    $filename = 'photo-' . $photo['id'] . '.' . $ext;

    header('Content-Type: ' . ($photo['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="' . $filename . '"');
    header('Content-Length: ' . strlen($body));
    echo $body;
    exit;
}

/* ============================================================
   ZIP
   ============================================================ */
$where = [];
$params = [];
if ($key) {
    $where[] = 'key_value = :key';
    $params[':key'] = $key;
}
if ($keyType) {
    $where[] = 'key_type = :kt';
    $params[':kt'] = $keyType;
}
if (!$key && !$all) {
    header('Location: index.php');
    exit;
}

$sql = 'SELECT * FROM photos';
if ($where) $sql .= ' WHERE ' . implode(' AND ', $where);
$sql .= ' ORDER BY created_at DESC';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$photos = $stmt->fetchAll();

if (empty($photos)) {
    http_response_code(404);
    exit('Нет фото для скачивания');
}

$PHOTO_TYPES = [
    'general' => 'Общий вид',
    'vin' => 'VIN',
    'odometer' => 'Одометр',
    'before_dismount' => 'Дефект до демонтажа',
    'after_dismount' => 'Дефект после демонтажа',
    'marking' => 'Маркировка',
    'manifestation' => 'Проявление дефекта',
    'numbered_unit' => 'Номерной агрегат'
];

/* Временная папка для скачанных фото */
$tmpDir = sys_get_temp_dir() . '/kamaz_dl_' . bin2hex(random_bytes(6));
if (!is_dir($tmpDir)) @mkdir($tmpDir, 0775, true);

$zip = new ZipArchive();
$tmpZip = tempnam(sys_get_temp_dir(), 'kamaz_photos_') . '.zip';

if ($zip->open($tmpZip, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Не удалось создать архив');
}

$added = 0;
foreach ($photos as $p) {
    $body = fetch_file($p['file_path']);
    if ($body === null) continue;

    $ext = detect_ext($p['mime_type'] ?? null, $p['file_path']);
    $typeName = $PHOTO_TYPES[$p['photo_type']] ?? $p['photo_type'];
    $safeType = preg_replace('/[^a-zA-Zа-яА-Я0-9_\-]/u', '_', $typeName);
    $safeKey  = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $p['key_value']);
    $zipName  = $p['key_type'] . '-' . $safeKey . '/' . $safeType . '-' . $p['id'] . '.' . $ext;

    $tmpFile = $tmpDir . '/' . $p['id'] . '.' . $ext;
    if (file_put_contents($tmpFile, $body) === false) continue;

    $zip->addFile($tmpFile, $zipName);
    $added++;
}

$zip->close();

if ($added === 0) {
    @unlink($tmpZip);
    foreach (glob($tmpDir . '/*') ?: [] as $f) @unlink($f);
    @rmdir($tmpDir);
    http_response_code(404);
    exit('Не удалось скачать ни одного файла из хранилища');
}

$zipNameOut = 'kamaz-photos';
if ($key) $zipNameOut .= '-' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
$zipNameOut .= '-' . date('Y-m-d') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipNameOut . '"');
header('Content-Length: ' . filesize($tmpZip));
readfile($tmpZip);

/* Чистим временные файлы */
@unlink($tmpZip);
foreach (glob($tmpDir . '/*') ?: [] as $f) @unlink($f);
@rmdir($tmpDir);
exit;
