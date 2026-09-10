<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$key = trim($_GET['key'] ?? '');
$keyType = trim($_GET['key_type'] ?? '');
$all = !empty($_GET['all']);

$pdo = get_db();

// Одиночное фото
if ($id > 0) {
    $stmt = $pdo->prepare('SELECT * FROM photos WHERE id = :id');
    $stmt->execute([':id' => $id]);
    $photo = $stmt->fetch();
    if (!$photo) { http_response_code(404); exit('Фото не найдено'); }

    $path = __DIR__ . '/' . $photo['file_path'];
    if (!file_exists($path)) { http_response_code(404); exit('Файл не найден'); }

    header('Content-Type: ' . ($photo['mime_type'] ?: 'application/octet-stream'));
    header('Content-Disposition: attachment; filename="photo-' . $photo['id'] . '.' . pathinfo($path, PATHINFO_EXTENSION) . '"');
    header('Content-Length: ' . filesize($path));
    readfile($path);
    exit;
}

// Выборка
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

// Формируем ZIP
$zip = new ZipArchive();
$tmpFile = tempnam(sys_get_temp_dir(), 'kamaz_photos_') . '.zip';

if ($zip->open($tmpFile, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    http_response_code(500);
    exit('Не удалось создать архив');
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

foreach ($photos as $p) {
    $path = __DIR__ . '/' . $p['file_path'];
    if (!file_exists($path)) continue;
    $ext = pathinfo($path, PATHINFO_EXTENSION);
    $typeName = $PHOTO_TYPES[$p['photo_type']] ?? $p['photo_type'];
    $safeType = preg_replace('/[^a-zA-Zа-яА-Я0-9_\-]/u', '_', $typeName);
    $safeKey = preg_replace('/[^a-zA-Z0-9_\-]/', '_', $p['key_value']);
    $zipName = $p['key_type'] . '-' . $safeKey . '/' . $safeType . '-' . $p['id'] . '.' . $ext;
    $zip->addFile($path, $zipName);
}

$zip->close();

$zipNameOut = 'kamaz-photos';
if ($key) $zipNameOut .= '-' . preg_replace('/[^a-zA-Z0-9_\-]/', '_', $key);
$zipNameOut .= '-' . date('Y-m-d') . '.zip';

header('Content-Type: application/zip');
header('Content-Disposition: attachment; filename="' . $zipNameOut . '"');
header('Content-Length: ' . filesize($tmpFile));
readfile($tmpFile);
@unlink($tmpFile);
exit;
