<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$PHOTO_TYPES = [
    'general'          => 'Общий вид автотехники',
    'vin'              => 'VIN / Номер шасси',
    'odometer'         => 'Одометр / Моточасы',
    'before_dismount'  => 'Дефект до демонтажа',
    'after_dismount'   => 'Дефект после демонтажа',
    'marking'          => 'Маркировка изделия',
    'manifestation'    => 'Проявление дефекта',
    'numbered_unit'    => 'Номерной агрегат',
    'other'            => 'Прочие фотографии',
    'video_defect'     => 'Видео дефекта',
];

$BRANDS = ['КАМАЗ', 'КОМПАС', 'ФОТОН', 'СИТРАК', 'ПРИЦЕПЫ'];

$KEY_TYPES = [
    'ra'    => 'РА (номер рекламационного акта)',
    'order' => 'Заказ-наряд',
    'vin'   => 'VIN / Номер шасси',
    'gos'   => 'Гос. номер',
];

$KEY_LABELS = [
    'ra'    => 'РА',
    'order' => 'Заказ-наряд',
    'vin'   => 'VIN',
    'gos'   => 'Гос.номер',
];

$pdo = get_db();

$searchQuery = trim($_GET['q'] ?? '');

$viewKeyType  = trim($_GET['key_type'] ?? '');
$viewKeyValue = trim($_GET['key_value'] ?? '');
$viewMode     = $viewKeyType && $viewKeyValue;

/* === Если пришли «+ Добавить» — сохраняем === */
if (!empty($_GET['save'])
    && $_SERVER['REQUEST_METHOD'] === 'GET'
    && !empty($_GET['key_value'])
    && !empty($_GET['key_type'])
    && array_key_exists($_GET['key_type'], $KEY_TYPES)
) {
    $newKeyValue = trim($_GET['key_value']);
    $newGos      = trim($_GET['gos_number'] ?? '');
    $newOrder    = trim($_GET['order_number'] ?? '');
    $newDescr    = trim($_GET['description'] ?? '');
    $newBrand    = trim($_GET['brand'] ?? '');
    if ($newBrand !== '' && !in_array($newBrand, $BRANDS, true)) $newBrand = '';

    if ($newKeyValue !== '') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO keys (key_type, key_value, brand, gos_number, order_number, description, user_id, created_at, updated_by, updated_at)
                VALUES (:kt, :kv, :brand, :gos, :ord, :descr, :uid, NOW(), :uid2, NOW())
                ON CONFLICT (key_type, key_value) DO UPDATE
                    SET brand        = COALESCE(NULLIF(EXCLUDED.brand, ''),        keys.brand),
                        gos_number   = COALESCE(NULLIF(EXCLUDED.gos_number, ''),   keys.gos_number),
                        order_number = COALESCE(NULLIF(EXCLUDED.order_number, ''), keys.order_number),
                        description  = COALESCE(NULLIF(EXCLUDED.description, ''),  keys.description),
                        updated_by   = EXCLUDED.updated_by,
                        updated_at   = NOW()
            ");
            $stmt->execute([
                ':kt'    => $_GET['key_type'],
                ':kv'    => $newKeyValue,
                ':brand' => $newBrand ?: null,
                ':gos'   => $newGos ?: null,
                ':ord'   => $newOrder ?: null,
                ':descr' => $newDescr ?: null,
                ':uid'   => $user['id'],
                ':uid2'  => $user['id'],
            ]);
        } catch (Throwable $e) {}
    }

    header('Location: gallery.php?key_type=' . urlencode($_GET['key_type']) . '&key_value=' . urlencode($newKeyValue));
    exit;
}

/* === Данные для главного экрана === */
$groups = [];
$groupsByBrand = [];
if (!$viewMode) {
    if ($searchQuery !== '') {
        $stmt = $pdo->prepare("
            SELECT k.key_type, k.key_value, k.brand, k.gos_number, k.order_number, k.description,
                   k.user_id, k.created_at, k.updated_by, k.updated_at
              FROM keys k
             WHERE k.key_value ILIKE :q
                OR COALESCE(k.brand, '')        ILIKE :q
                OR COALESCE(k.gos_number, '')   ILIKE :q
                OR COALESCE(k.order_number, '') ILIKE :q
                OR COALESCE(k.description, '')  ILIKE :q
             ORDER BY k.key_value DESC
        ");
        $stmt->execute([':q' => '%' . $searchQuery . '%']);
    } else {
        $stmt = $pdo->query("
            SELECT k.key_type, k.key_value, k.brand, k.gos_number, k.order_number, k.description,
                   k.user_id, k.created_at, k.updated_by, k.updated_at
              FROM keys k
             ORDER BY k.key_value DESC
        ");
    }
    $allKeys = $stmt->fetchAll();

    $stmt = $pdo->query("
        SELECT key_type, key_value, COUNT(*) AS cnt, MAX(created_at) AS last_date
        FROM photos GROUP BY key_type, key_value
    ");
    $photoCounts = [];
    foreach ($stmt->fetchAll() as $row) {
        $photoCounts[$row['key_type'] . '::' . $row['key_value']] = $row;
    }

    $thumbs = [];
    try {
        $stmt = $pdo->query("
            SELECT DISTINCT ON (key_type, key_value, photo_type)
                   key_type, key_value, photo_type, file_path
              FROM photos
             WHERE photo_type IN ('general', 'before_dismount')
             ORDER BY key_type, key_value, photo_type, created_at DESC
        ");
        foreach ($stmt->fetchAll() as $t) {
            $thumbs[$t['key_type'] . '::' . $t['key_value']][$t['photo_type']] = $t['file_path'];
        }
    } catch (Throwable $e) {}

    $usersById = [];
    try {
        foreach ($pdo->query("SELECT id, name, email FROM users")->fetchAll() as $u) {
            $usersById[(int)$u['id']] = $u;
        }
    } catch (Throwable $e) {}

    foreach ($allKeys as $k) {
        $key = $k['key_type'] . '::' . $k['key_value'];
        $row = $photoCounts[$key] ?? null;
        $brand = $k['brand'] ?? null;
        if ($brand === null || $brand === '') $brand = 'Без бренда';

        $groups[] = [
            'key_type'     => $k['key_type'],
            'key_value'    => $k['key_value'],
            'brand'        => $brand,
            'gos_number'   => $k['gos_number']   ?? null,
            'order_number' => $k['order_number'] ?? null,
            'description'  => $k['description']  ?? null,
            'user_id'      => $k['user_id']      ?? null,
            'created_at'   => $k['created_at']   ?? null,
            'updated_by'   => $k['updated_by']   ?? null,
            'updated_at'   => $k['updated_at']   ?? null,
            'count'        => $row ? (int)$row['cnt'] : 0,
            'last_date'    => $row['last_date'] ?? null,
            'thumb_general'  => $thumbs[$key]['general']         ?? null,
            'thumb_defect'   => $thumbs[$key]['before_dismount'] ?? null,
            'creator_name'   => null,
            'updater_name'   => null,
        ];
    }

    foreach ($groups as &$g) {
        $g['creator_name'] = $g['user_id']    && isset($usersById[(int)$g['user_id']])
            ? ($usersById[(int)$g['user_id']]['name'] ?: $usersById[(int)$g['user_id']]['email'])
            : null;
        $g['updater_name'] = $g['updated_by'] && isset($usersById[(int)$g['updated_by']])
            ? ($usersById[(int)$g['updated_by']]['name'] ?: $usersById[(int)$g['updated_by']]['email'])
            : null;
    }
    unset($g);

    /* Группировка по бренду, с фиксированным порядком */
    $order = array_merge($BRANDS, ['Без бренда']);
    foreach ($order as $b) $groupsByBrand[$b] = [];
    foreach ($groups as $g) {
        $b = $g['brand'] ?: 'Без бренда';
        if (!isset($groupsByBrand[$b])) $groupsByBrand[$b] = [];
        $groupsByBrand[$b][] = $g;
    }
    /* Убираем пустые разделы */
    $groupsByBrand = array_filter($groupsByBrand, function($list) { return !empty($list); });
}

/* === Данные для экрана внутри РА === */
$photos = [];
$totalPhotos = 0;
$currentKey = null;
$photosByType = [];

if ($viewMode) {
    try {
        $stmt = $pdo->prepare("SELECT * FROM keys WHERE key_type = :kt AND key_value = :kv");
        $stmt->execute([':kt' => $viewKeyType, ':kv' => $viewKeyValue]);
        $currentKey = $stmt->fetch() ?: null;
    } catch (Throwable $e) {
        $currentKey = null;
    }

    $stmt = $pdo->prepare("
        SELECT p.*, u.name AS user_name, u.email AS user_email
        FROM photos p
        JOIN users u ON u.id = p.user_id
        WHERE p.key_type = :kt AND p.key_value = :kv
        ORDER BY p.created_at DESC
    ");
    $stmt->execute([':kt' => $viewKeyType, ':kv' => $viewKeyValue]);
    $photos = $stmt->fetchAll();
    $totalPhotos = count($photos);

    foreach ($photos as $p) {
        $photosByType[$p['photo_type']] = true;
    }
}

$stmt = $pdo->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(file_size),0) AS total_size FROM photos");
$summary = $stmt->fetch();
$allPhotosCount = (int)$summary['cnt'];
$allPhotosSize  = (int)$summary['total_size'];

function formatSize($bytes) {
    if ($bytes < 1024) return $bytes . ' Б';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' КБ';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' МБ';
    return round($bytes / 1073741824, 2) . ' ГБ';
}
function isVideoMime(?string $mime): bool {
    return $mime !== null && strpos($mime, 'video/') === 0;
}

$keyLabel  = $KEY_LABELS[$viewKeyType] ?? 'Документ';
$viewTitle = '';
if ($viewMode) {
    $viewTitle = $keyLabel . ': ' . $viewKeyValue;
    if (!empty($currentKey['description'])) {
        $viewTitle .= ' — ' . $currentKey['description'];
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $viewMode ? e($viewTitle) : 'Фото по РА — общая база' ?></title>
<link rel="stylesheet" href="app.css">
<style>
  /* === Локальные стили для галереи === */

  body { padding: 24px 18px; }
  .container { max-width: 1200px; }

  .key-description {
    font-size: 15px;
    color: var(--ink);
    font-weight: 600;
    background: linear-gradient(135deg, #fffbeb, #fef3c7);
    border-left: 4px solid #f59e0b;
    padding: 12px 16px;
    border-radius: 12px;
    margin: 0 0 14px;
  }

  .page-subtitle {
    color: var(--ink-soft);
    font-size: 14px;
    margin: 0 0 14px;
  }

  .form-row { margin-bottom: 14px; }
  .form-row .form-label { margin-bottom: 6px; }

  /* === Брендовая секция === */
  .brand-section { margin-bottom: 22px; }
  .brand-title {
    display: flex;
    align-items: center;
    gap: 12px;
    font-size: 15px;
    font-weight: 800;
    letter-spacing: 0.04em;
    text-transform: uppercase;
    color: #fff;
    padding: 12px 20px;
    border-radius: 14px 14px 0 0;
    margin-bottom: 0;
    box-shadow: 0 4px 12px -4px rgba(15,23,42,0.2);
  }
  .brand-title .cnt {
    background: rgba(255,255,255,0.28);
    padding: 3px 12px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0;
  }

  .brand-body {
    background: rgba(255,255,255,0.55);
    backdrop-filter: blur(8px);
    -webkit-backdrop-filter: blur(8px);
    padding: 14px;
    border-radius: 0 0 14px 14px;
    border: 1px solid rgba(255,255,255,0.7);
    border-top: none;
  }

  /* Цвета брендовых секций */
  .brand-kamaz    .brand-title { background: linear-gradient(135deg, #2563eb, #06b6d4); }
  .brand-compass  .brand-title { background: linear-gradient(135deg, #7c3aed, #c026d3); }
  .brand-foton    .brand-title { background: linear-gradient(135deg, #dc2626, #f97316); }
  .brand-sitrak   .brand-title { background: linear-gradient(135deg, #059669, #14b8a6); }
  .brand-pritsep  .brand-title { background: linear-gradient(135deg, #d97706, #eab308); }
  .brand-nobrand  .brand-title { background: linear-gradient(135deg, #64748b, #94a3b8); }

  /* === Карточка записи === */
  .group-item {
    display: flex; align-items: center; gap: 14px;
    padding: 16px 18px;
    border: 1.5px solid rgba(15,23,42,0.08);
    border-radius: 14px;
    margin-bottom: 10px;
    background: #fff;
    transition: all 0.18s;
  }
  .group-item:last-child { margin-bottom: 0; }
  .group-item:hover {
    border-color: #6ee7b7;
    background: #f0fdf4;
    transform: translateY(-1px);
    box-shadow: 0 8px 20px -10px rgba(5,150,105,0.3);
  }
  .group-link {
    flex: 1; min-width: 0; text-decoration: none; color: inherit;
    display: flex; align-items: center; gap: 16px;
  }
  .group-main { flex: 1; min-width: 0; }
  .group-item-title {
    font-weight: 700; font-size: 15.5px;
    color: #065f46;
    letter-spacing: -0.01em;
  }
  .group-item-title span { font-weight: 500; color: #64748b; }
  .group-item-sub { font-size: 13px; color: #64748b; margin-top: 4px; }
  .group-item-meta { font-size: 11.5px; color: #94a3b8; margin-top: 4px; }

  .group-thumbs { display: flex; gap: 8px; flex-shrink: 0; }
  .group-thumb {
    width: 68px; height: 68px; border-radius: 10px;
    border: 1.5px solid rgba(15,23,42,0.08);
    background: #f8fafc;
    object-fit: cover; display: block;
    transition: transform 0.18s;
  }
  .group-item:hover .group-thumb { transform: scale(1.04); }
  .group-thumb-empty {
    width: 68px; height: 68px; border-radius: 10px;
    border: 1.5px dashed rgba(15,23,42,0.12);
    background: #fafbfc;
    display: flex; align-items: center; justify-content: center;
    color: #cbd5e1; font-size: 22px;
  }
  .group-thumb-label {
    display: block; font-size: 9.5px; color: #94a3b8;
    text-align: center; margin-top: 3px;
    letter-spacing: 0.02em;
  }

  .group-actions {
    display: flex; align-items: center; gap: 8px;
    flex-shrink: 0;
  }
  .group-item-count {
    font-size: 13px; color: #047857;
    background: #d1fae5;
    padding: 5px 12px; border-radius: 12px;
    font-weight: 700;
    white-space: nowrap;
  }
  .group-del-btn {
    color: #dc2626; font-size: 18px;
    text-decoration: none;
    padding: 8px 10px; border-radius: 9px;
    cursor: pointer;
    transition: all 0.15s;
    line-height: 1;
  }
  .group-del-btn:hover { background: #fef2f2; }

  /* === Плитки типов === */
  .type-selector {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(200px, 1fr));
    gap: 10px;
    margin-bottom: 14px;
  }
  .type-option {
    padding: 14px 16px;
    border: 1.5px solid rgba(15,23,42,0.1);
    border-radius: 12px;
    font-size: 13px;
    cursor: pointer;
    background: #fff;
    text-align: center;
    transition: all 0.18s;
    user-select: none;
    display: flex; align-items: center; justify-content: center;
    min-height: 52px;
    font-weight: 500;
    color: #475569;
    position: relative;
    line-height: 1.3;
  }
  .type-option:hover {
    border-color: #10b981;
    transform: translateY(-1px);
    box-shadow: 0 6px 14px -6px rgba(16,185,129,0.3);
  }

  .type-option.is-empty {
    border-color: #fca5a5;
    background: linear-gradient(135deg, #fef2f2, #fee2e2);
    color: #991b1b;
    font-weight: 600;
  }
  .type-option.is-empty::before {
    content: '●';
    color: #dc2626; font-size: 11px;
    position: absolute; top: 7px; right: 9px;
  }

  .type-option.has-photos {
    border-color: #6ee7b7;
    background: linear-gradient(135deg, #f0fdf4, #d1fae5);
    color: #065f46;
    font-weight: 700;
  }
  .type-option.has-photos::before {
    content: '✓';
    color: #10b981; font-size: 15px; font-weight: 800;
    position: absolute; top: 5px; right: 9px;
  }

  .type-option[data-type="video_defect"] { border-left: 4px solid #7c3aed; }
  .type-option[data-type="video_defect"].is-empty { border-left-color: #dc2626; }
  .type-option[data-type="video_defect"].has-photos { border-left-color: #10b981; }

  /* === Сетка фото === */
  .photo-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(210px, 1fr));
    gap: 14px;
  }
  .photo-card {
    background: #f8fafc;
    border-radius: 12px;
    overflow: hidden;
    border: 1.5px solid rgba(15,23,42,0.08);
    position: relative;
    transition: all 0.2s;
  }
  .photo-card:hover {
    transform: translateY(-3px);
    box-shadow: 0 14px 30px -12px rgba(15,23,42,0.25);
    border-color: #6ee7b7;
  }
  .photo-card img, .photo-card video {
    width: 100%; height: 170px;
    object-fit: cover; display: block;
    background: #e2e8f0; cursor: pointer;
  }
  .photo-meta { padding: 11px 12px; font-size: 11px; color: #64748b; background: #fff; }
  .photo-type {
    display: inline-block;
    padding: 3px 10px; border-radius: 7px;
    background: #d1fae5; color: #065f46;
    font-weight: 700; font-size: 11px;
    margin-bottom: 6px;
  }
  .photo-type.is-video { background: #ede9fe; color: #5b21b6; }
  .photo-user { font-size: 11px; color: #94a3b8; margin-top: 6px; }
  .photo-actions { display: flex; gap: 5px; margin-top: 8px; }
  .photo-actions a {
    flex: 1; padding: 6px 8px; border-radius: 7px;
    font-size: 12px; text-align: center;
    text-decoration: none; font-weight: 600;
    transition: all 0.15s;
  }
  .photo-actions a:hover { transform: translateY(-1px); }
  .action-edit { background: #eff6ff; color: #1d4ed8; }
  .action-del  { background: #fef2f2; color: #b91c1c; }
  .action-dl   { background: #d1fae5; color: #065f46; }
  .action-pdf  { background: #fef3c7; color: #b45309; }

  .empty-state {
    text-align: center; padding: 50px 20px;
    color: #94a3b8; font-size: 14px;
  }
  .empty-state .big { font-size: 52px; margin-bottom: 14px; }

  .card-head {
    display: flex; justify-content: space-between;
    align-items: center; flex-wrap: wrap;
    gap: 12px; margin-bottom: 16px;
  }
  .card-head h2 { margin: 0; }

  .photos-toolbar {
    display: flex; gap: 8px; flex-wrap: wrap;
    align-items: center;
  }

  .upload-status {
    position: fixed; top: 0; left: 0; right: 0;
    padding: 16px; text-align: center;
    font-weight: 700; z-index: 9999;
    color: #fff; font-size: 15px;
    box-shadow: 0 6px 20px rgba(15,23,42,0.2);
  }

  .modal-small { max-width: 440px; padding: 28px; }
  .modal-small h3 {
    margin: 0 0 10px; font-size: 19px;
    text-align: center; color: var(--ink);
  }
  .modal-small p {
    text-align: center; color: #64748b;
    margin: 0 0 20px; font-size: 14px;
  }
  .modal-small .btn {
    display: flex; width: 100%;
    margin-bottom: 10px; padding: 15px;
    font-size: 15px;
    justify-content: center;
  }
  .modal-small .btn:last-child { margin-bottom: 0; }
  .btn-cancel {
    background: #f1f5f9; color: #475569;
    border: 1.5px solid rgba(15,23,42,0.08);
    padding: 13px;
    font-size: 14px;
  }
  .btn-cancel:hover { background: #e2e8f0; }

  .pdf-editor-shell {
    background: #fff; width: 100%; height: 100%;
    display: flex; flex-direction: column; overflow: hidden;
  }
  .pdf-editor-head {
    display: flex; align-items: center; justify-content: space-between;
    padding: 16px 24px;
    border-bottom: 1.5px solid rgba(15,23,42,0.08);
    flex-shrink: 0;
  }
  .pdf-editor-head h3 {
    margin: 0; font-size: 18px;
    color: #5b21b6; font-weight: 700;
  }
  .pdf-editor-toolbar {
    display: flex; gap: 20px; align-items: center;
    padding: 12px 24px;
    background: #f8fafc;
    border-bottom: 1.5px solid rgba(15,23,42,0.06);
    flex-shrink: 0; flex-wrap: wrap;
    font-size: 13px;
  }
  .pdf-editor-body {
    flex: 1; overflow-y: auto;
    padding: 22px; background: #f8fafc;
  }
  .pdf-editor-foot {
    padding: 14px 24px;
    border-top: 1.5px solid rgba(15,23,42,0.08);
    display: flex; gap: 14px; flex-wrap: wrap;
    align-items: center; flex-shrink: 0;
    background: #fff;
  }
  #pdfStatus { font-size: 14px; color: #64748b; font-weight: 600; }

  .viewer-shell {
    background: #0f172a; width: 100%; height: 100%;
    display: flex; flex-direction: column;
  }
  .viewer-head {
    display: flex; justify-content: space-between; align-items: center;
    padding: 14px 22px; background: #020617;
    color: #fff; flex-shrink: 0;
    gap: 12px; flex-wrap: wrap;
  }
  .viewer-body {
    flex: 1; display: flex; align-items: center; justify-content: center;
    overflow: auto; background: #0f172a; padding: 20px;
  }
  .viewer-body img {
    max-width: 100%; max-height: 100%;
    transition: transform 0.15s;
    transform-origin: center center;
    border-radius: 4px;
  }
  .viewer-btn {
    background: #1e293b; color: #e2e8f0;
    padding: 9px 16px; border-radius: 9px;
    font-size: 13px; font-weight: 600;
    border: none; cursor: pointer;
    font-family: inherit;
    transition: all 0.15s;
  }
  .viewer-btn:hover { background: #334155; }
  .viewer-btn.red { background: #dc2626; color: #fff; }
  .viewer-btn.red:hover { background: #b91c1c; }

  @media (max-width: 700px) {
    body { padding: 14px 12px; }
    .group-thumbs { display: none; }
    .group-item { padding: 12px 14px; }
    .group-item-title { font-size: 14px; }
    .photo-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 10px; }
    .photo-card img, .photo-card video { height: 130px; }
    .type-selector { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
    .type-option { padding: 11px 12px; font-size: 12px; min-height: 46px; }
    .brand-title { font-size: 13px; padding: 10px 14px; }
    .pdf-editor-head h3 { font-size: 15px; }
    .pdf-editor-head, .pdf-editor-toolbar, .pdf-editor-body, .pdf-editor-foot { padding-left: 14px; padding-right: 14px; }
  }
</style>
</head>
<body>
<div class="container">

  <?php
    $pageTitle    = $viewMode ? $viewTitle : 'Фото по РА';
    $pageSubtitle = $viewMode ? 'галерея по документу' : 'общая база · IPG';
    $backLink     = $viewMode ? 'gallery.php' : 'index.html';
    $backLabel    = $viewMode ? 'Ко всем РА' : 'На рабочее место';
    include __DIR__ . '/header.php';
  ?>

  <div class="card">
    <?php if ($viewMode && $currentKey): ?>
      <?php if (!empty($currentKey['brand'])): ?>
        <div style="margin-bottom:10px;">
          <span class="badge badge-blue" style="font-size:13px;padding:5px 14px;"><?= e($currentKey['brand']) ?></span>
        </div>
      <?php endif; ?>
      <?php if (!empty($currentKey['description'])): ?>
        <div class="key-description">📝 <?= e($currentKey['description']) ?></div>
      <?php endif; ?>
      <?php if (!empty($currentKey['gos_number']) || !empty($currentKey['order_number'])): ?>
        <p class="page-subtitle">
          <?php if (!empty($currentKey['gos_number'])): ?>🚗 Гос. номер: <b><?= e($currentKey['gos_number']) ?></b><?php endif; ?>
          <?php if (!empty($currentKey['gos_number']) && !empty($currentKey['order_number'])): ?> · <?php endif; ?>
          <?php if (!empty($currentKey['order_number'])): ?>📋 Заказ-наряд: <b><?= e($currentKey['order_number']) ?></b><?php endif; ?>
        </p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (!$viewMode): ?>
      <p class="page-subtitle">Всего файлов: <b><?= $allPhotosCount ?></b> · Размер: <b><?= formatSize($allPhotosSize) ?></b></p>
    <?php endif; ?>

    <?php if ($viewMode): ?>
      <div class="row-flex">
        <a href="gallery.php" class="btn btn-secondary btn-small">← Ко всем РА</a>
      </div>
    <?php endif; ?>
  </div>

  <?php if (isset($_GET['uploaded'])): ?>
    <div class="flash flash-success">✅ Файл успешно загружен</div>
  <?php endif; ?>
  <?php if (isset($_GET['deleted'])): ?>
    <div class="flash flash-success">🗑️ Файл удалён</div>
  <?php endif; ?>
  <?php if (isset($_GET['deleted_all_photos'])): ?>
    <div class="flash flash-success">🗑️ Все файлы по <?= e($_GET['deleted_all_photos'] === 'ra' ? 'РА' : 'VIN') ?> удалены</div>
  <?php endif; ?>
  <?php if (isset($_GET['deleted_all_key'])): ?>
    <div class="flash flash-success">🗑️ Запись и все её файлы удалены</div>
  <?php endif; ?>
  <?php if (isset($_GET['edited'])): ?>
    <div class="flash flash-success">✏️ Изменения сохранены</div>
  <?php endif; ?>
  <?php if (isset($_GET['error'])): ?>
    <div class="flash flash-error">❌ Ошибка: <?= e($_GET['error']) ?></div>
  <?php endif; ?>

  <?php if (!$viewMode): ?>
    <!-- ЭКРАН 1: Список РА -->

    <div class="card">
      <h2>+ Добавить новую запись</h2>
      <form method="get" action="gallery.php">
        <input type="hidden" name="save" value="1">

        <div class="form-row">
          <label class="form-label">Бренд автомобиля</label>
          <select name="brand" class="form-select" required>
            <option value="">— Выберите бренд —</option>
            <?php foreach ($BRANDS as $b): ?>
              <option value="<?= e($b) ?>"><?= e($b) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row">
          <label class="form-label">Тип привязки</label>
          <select name="key_type" class="form-select">
            <?php foreach ($KEY_TYPES as $k => $label): ?>
              <option value="<?= e($k) ?>" <?= $k === 'ra' ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-row">
          <label class="form-label">Номер</label>
          <input type="text" name="key_value" class="form-input" required placeholder="Например: 12345">
        </div>

        <div class="form-row">
          <label class="form-label">Описание дефекта (обязательно)</label>
          <input type="text" name="description" class="form-input" required placeholder="Например: течь гидроцилиндра подъёма кабины" maxlength="250">
        </div>

        <button type="submit" class="btn btn-green">+ Добавить</button>
      </form>
    </div>

    <div class="card">
      <h2>🔍 Поиск</h2>
      <form method="get" class="row-flex" style="flex-wrap:nowrap;">
        <input type="text" name="q" class="form-input" style="flex:1;min-width:0;" placeholder="Поиск по номеру, бренду, гос.номеру, заказ-наряду, описанию" value="<?= e($searchQuery) ?>">
        <button type="submit" class="btn btn-primary">Найти</button>
        <?php if ($searchQuery): ?>
          <a href="gallery.php" class="btn btn-secondary">Сбросить</a>
        <?php endif; ?>
      </form>
    </div>

    <div class="card">
      <h2>Все записи (<?= count($groups) ?>)</h2>

      <?php if (empty($groups)): ?>
        <div class="empty-state">
          <div class="big">📷</div>
          <div>Пока нет ни одной записи</div>
        </div>
      <?php else: ?>

        <?php foreach ($groupsByBrand as $brandName => $list): ?>
          <?php
            $brandClass = 'brand-nobrand';
            if ($brandName === 'КАМАЗ')         $brandClass = 'brand-kamaz';
            elseif ($brandName === 'КОМПАС')    $brandClass = 'brand-compass';
            elseif ($brandName === 'ФОТОН')     $brandClass = 'brand-foton';
            elseif ($brandName === 'СИТРАК')    $brandClass = 'brand-sitrak';
            elseif ($brandName === 'ПРИЦЕПЫ')   $brandClass = 'brand-pritsep';
          ?>
          <div class="brand-section <?= $brandClass ?>">
            <div class="brand-title">
              <span><?= e($brandName) ?></span>
              <span class="cnt"><?= count($list) ?></span>
            </div>
            <div class="brand-body">

              <?php foreach ($list as $g): ?>
                <?php $delId = 'delAll-' . md5($g['key_type'] . $g['key_value']); ?>
                <div class="group-item">
                  <a href="gallery.php?key_type=<?= e($g['key_type']) ?>&key_value=<?= urlencode($g['key_value']) ?>" class="group-link">
                    <div class="group-main">
                      <div class="group-item-title">
                        <?= e($KEY_LABELS[$g['key_type']] ?? 'Документ') ?>: <?= e($g['key_value']) ?>
                        <?php if (!empty($g['description'])): ?><span> — <?= e($g['description']) ?></span><?php endif; ?>
                      </div>
                      <?php if ($g['gos_number'] || $g['order_number']): ?>
                        <div class="group-item-sub">
                          <?php if ($g['gos_number']): ?>🚗 <?= e($g['gos_number']) ?><?php endif; ?>
                          <?php if ($g['gos_number'] && $g['order_number']): ?> · <?php endif; ?>
                          <?php if ($g['order_number']): ?>📋 ЗН: <?= e($g['order_number']) ?><?php endif; ?>
                        </div>
                      <?php endif; ?>
                      <?php if ($g['creator_name'] || $g['created_at']): ?>
                        <div class="group-item-meta">
                          ✏️ Создал:
                          <?= $g['creator_name'] ? e($g['creator_name']) : '—' ?>
                          <?php if ($g['created_at']): ?>, <?= e(date('d.m.Y H:i', strtotime($g['created_at']))) ?><?php endif; ?>
                        </div>
                      <?php endif; ?>
                      <?php if ($g['last_date']): ?>
                        <div class="group-item-meta">🕐 Обновлено: <?= e(date('d.m.Y H:i', strtotime($g['last_date']))) ?></div>
                      <?php endif; ?>
                    </div>

                    <div class="group-thumbs">
                      <div>
                        <?php if ($g['thumb_general']): ?>
                          <img class="group-thumb" src="<?= e($g['thumb_general']) ?>" alt="Общий вид" loading="lazy">
                        <?php else: ?>
                          <div class="group-thumb-empty">📷</div>
                        <?php endif; ?>
                        <span class="group-thumb-label">Общий вид</span>
                      </div>
                      <div>
                        <?php if ($g['thumb_defect']): ?>
                          <img class="group-thumb" src="<?= e($g['thumb_defect']) ?>" alt="Дефект" loading="lazy">
                        <?php else: ?>
                          <div class="group-thumb-empty">🔍</div>
                        <?php endif; ?>
                        <span class="group-thumb-label">Дефект</span>
                      </div>
                    </div>
                  </a>
                  <div class="group-actions">
                    <div class="group-item-count"><?= (int)$g['count'] ?> 📁</div>
                    <a href="#" class="group-del-btn" title="Удалить всю запись и все файлы"
                       onclick="if(confirm('Удалить <?= e($KEY_LABELS[$g['key_type']] ?? 'запись') ?>: <?= e($g['key_value']) ?> и ВСЕ её файлы?')){document.getElementById('<?= $delId ?>').submit();}return false;">🗑️</a>
                    <form id="<?= $delId ?>" method="post" action="delete_all.php" style="display:none;">
                      <input type="hidden" name="key_type" value="<?= e($g['key_type']) ?>">
                      <input type="hidden" name="key_value" value="<?= e($g['key_value']) ?>">
                      <input type="hidden" name="remove_key" value="1">
                    </form>
                  </div>
                </div>
              <?php endforeach; ?>

            </div>
          </div>
        <?php endforeach; ?>

      <?php endif; ?>
    </div>

  <?php else: ?>
    <!-- ЭКРАН 2: Внутри РА -->

    <div class="card">
      <h2>Загрузить фото / видео</h2>
      <div class="step-hint" style="font-size:13px;color:#475569;margin-bottom:14px;padding:12px 16px;background:#f8fafc;border-radius:10px;border-left:3px solid #10b981;line-height:1.55;">
        <b>Красные</b> плитки — по этому типу ещё ничего нет. <b>Зелёные</b> — уже загружено.
        Нажми на плитку — выберешь: снять на камеру или взять из галереи.
      </div>

      <div class="type-selector" id="typeSelector">
        <?php foreach ($PHOTO_TYPES as $id => $name): ?>
          <?php $has = !empty($photosByType[$id]); ?>
          <div class="type-option <?= $has ? 'has-photos' : 'is-empty' ?>" data-type="<?= e($id) ?>">
            <?= e($name) ?>
          </div>
        <?php endforeach; ?>
      </div>

      <input type="file" id="photoCamera"  accept="image/*" capture="environment" style="display:none;">
      <input type="file" id="photoGallery" accept="image/*" style="display:none;">
      <input type="file" id="videoCamera"  accept="video/*" capture="environment" style="display:none;">
      <input type="file" id="videoGallery" accept="video/*" style="display:none;">

      <div class="form-row" style="margin-top:16px;">
        <label class="form-label">Комментарий (необязательно)</label>
        <textarea id="commentInput" class="form-textarea" placeholder="Например: течь ОЖ в районе патрубка"></textarea>
      </div>
    </div>

    <div class="card">
      <div class="card-head">
        <h2>Фото и видео (<?= $totalPhotos ?>)</h2>
        <div class="photos-toolbar">
          <button type="button" class="btn btn-ai btn-small" onclick="openPdfEditor()">✏️ Редактировать PDF</button>
          <?php if ($totalPhotos > 0): ?>
            <a href="download.php?key_type=<?= e($viewKeyType) ?>&key=<?= urlencode($viewKeyValue) ?>" class="btn btn-primary btn-small">📥 Скачать ZIP</a>
            <a href="#" onclick="if(confirm('Удалить ВСЕ <?= $totalPhotos ?> файлов по этому <?= e($keyLabel) ?>?')){document.getElementById('deleteAllForm').submit();}return false;" class="btn btn-red btn-small">🗑️ Удалить все</a>
            <form id="deleteAllForm" method="post" action="delete_all.php" style="display:none;">
              <input type="hidden" name="key_type" value="<?= e($viewKeyType) ?>">
              <input type="hidden" name="key_value" value="<?= e($viewKeyValue) ?>">
            </form>
          <?php endif; ?>
        </div>
      </div>

      <?php if (empty($photos)): ?>
        <div class="empty-state">
          <div class="big">📷</div>
          <div>Пока ничего нет</div>
          <div style="margin-top:6px;">Нажмите на тип файла выше</div>
        </div>
      <?php else: ?>
        <div class="photo-grid">
          <?php foreach ($photos as $p): ?>
            <?php
              $canEdit  = ((int)$p['user_id'] === (int)$user['id']) || $user['is_admin'];
              $isVideo  = isVideoMime($p['mime_type'] ?? null);
            ?>
            <div class="photo-card">
              <?php if ($isVideo): ?>
                <video src="<?= e($p['file_path']) ?>" controls preload="metadata"></video>
              <?php else: ?>
                <a href="<?= e($p['file_path']) ?>" target="_blank">
                  <img src="<?= e($p['file_path']) ?>" alt="<?= e($PHOTO_TYPES[$p['photo_type']] ?? $p['photo_type']) ?>" loading="lazy">
                </a>
              <?php endif; ?>
              <div class="photo-meta">
                <div class="photo-type <?= $isVideo ? 'is-video' : '' ?>">
                  <?= $isVideo ? '🎥' : '🖼' ?>
                  <?= e($PHOTO_TYPES[$p['photo_type']] ?? $p['photo_type']) ?>
                </div>
                <?php if ($p['comment']): ?>
                  <div style="margin-top:4px;color:#334155;"><?= e($p['comment']) ?></div>
                <?php endif; ?>
                <div class="photo-user">👤 <?= e($p['user_name'] ?: $p['user_email']) ?></div>
                <div style="margin-top:2px;font-size:10px;color:#cbd5e1;"><?= e(date('d.m.Y H:i', strtotime($p['created_at']))) ?></div>
                <div class="photo-actions">
                  <a href="download.php?id=<?= (int)$p['id'] ?>" class="action-dl" title="Скачать">📥</a>
                  <?php if (!$isVideo): ?>
                    <a href="print_pdf.php?id=<?= (int)$p['id'] ?>" target="_blank" class="action-pdf" title="Открыть PDF">📄</a>
                  <?php endif; ?>
                  <?php if ($canEdit): ?>
                    <a href="edit.php?id=<?= (int)$p['id'] ?>" class="action-edit" title="Редактировать">✏️</a>
                    <a href="#" onclick="if(confirm('Удалить?')){document.getElementById('del-<?= (int)$p['id'] ?>').submit();}return false;" class="action-del" title="Удалить">🗑️</a>
                    <form id="del-<?= (int)$p['id'] ?>" method="post" action="delete.php" style="display:none;">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                      <input type="hidden" name="back_key_type" value="<?= e($viewKeyType) ?>">
                      <input type="hidden" name="back_key_value" value="<?= e($viewKeyValue) ?>">
                    </form>
                  <?php endif; ?>
                </div>
              </div>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>
    </div>

  <?php endif; ?>

</div>

<!-- Модальное окно: выбор источника -->
<div class="modal-overlay" id="sourceModal">
  <div class="modal modal-small">
    <h3>Откуда взять файл?</h3>
    <p id="sourceModalTitle">Выбери источник</p>
    <button type="button" class="btn btn-green" onclick="chooseSource('camera')">📷 Снять на камеру</button>
    <button type="button" class="btn btn-secondary" onclick="chooseSource('gallery')">🖼 Из галереи телефона</button>
    <button type="button" class="btn btn-cancel" onclick="closeSourceModal()">Отмена</button>
  </div>
</div>

<!-- Модальное окно: сохранить в галерею телефона -->
<div class="modal-overlay" id="saveModal">
  <div class="modal modal-small">
    <h3>✅ Файл загружен</h3>
    <p>Сохранить копию в галерею телефона?</p>
    <button type="button" class="btn btn-green" onclick="saveToPhone()">💾 Сохранить в телефон</button>
    <button type="button" class="btn btn-cancel" onclick="finishUpload()">Пропустить</button>
  </div>
</div>

<!-- PDF-редактор (на весь экран) -->
<div class="modal-overlay" id="pdfEditorModal" style="padding:0;">
  <div class="pdf-editor-shell">

    <div class="pdf-editor-head">
      <h3>✏️ PDF-редактор — <?= e($viewTitle) ?></h3>
      <button type="button" class="btn btn-secondary btn-small" onclick="closePdfEditor()">✕ Закрыть</button>
    </div>

    <div class="pdf-editor-toolbar">
      <label style="font-size:13px;font-weight:600;color:#475569;">
        Качество PDF:
        <select id="pdfQuality" class="form-select" style="display:inline-block;width:auto;padding:7px 12px;font-size:13px;margin-left:8px;">
          <option value="original">Оригинал (большой файл)</option>
          <option value="good" selected>Хорошее (рекомендую)</option>
          <option value="medium">Среднее</option>
          <option value="small">Малое</option>
        </select>
      </label>
      <span style="color:#94a3b8;">
        Клик по фото — увеличить и повернуть. Перетаскивайте карточки для смены порядка.
      </span>
    </div>

    <div class="pdf-editor-body">
      <div id="pdfPagesGrid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:14px;"></div>

      <div style="margin-top:22px; padding:18px; background:#fff; border-radius:14px; border:1.5px dashed #cbd5e1; text-align:center;">
        <input type="file" id="pdfAppendInput" accept="application/pdf" multiple style="display:none;">
        <button type="button" class="btn btn-secondary btn-small" onclick="document.getElementById('pdfAppendInput').click()">+ Добавить PDF-файл</button>
        <div id="pdfAppendList" style="margin-top:14px; font-size:14px; color:#475569;"></div>
      </div>
    </div>

    <div class="pdf-editor-foot">
      <button type="button" class="btn btn-green" onclick="buildPdf()">📄 Собрать PDF</button>
      <span id="pdfStatus"></span>
    </div>
  </div>
</div>

<!-- Просмотрщик одного фото -->
<div class="modal-overlay" id="photoViewer" style="padding:0;z-index:11000;">
  <div class="viewer-shell">
    <div class="viewer-head">
      <div style="font-size:14px;" id="photoViewerTitle">—</div>
      <div style="display:flex;gap:8px;flex-wrap:wrap;">
        <button type="button" class="viewer-btn" onclick="viewerRotate()">↻ Повернуть</button>
        <button type="button" class="viewer-btn" onclick="viewerReset()">⟲ Сброс</button>
        <button type="button" class="viewer-btn red" onclick="viewerClose()">✕ Закрыть</button>
      </div>
    </div>
    <div class="viewer-body">
      <img id="photoViewerImg" src="" alt="">
    </div>
  </div>
</div>

<!-- pdf-lib для сборки PDF -->
<script src="https://cdn.jsdelivr.net/npm/pdf-lib@1.17.1/dist/pdf-lib.min.js"></script>

<?php if ($viewMode): ?>
<script>
window.IPG_PHOTOS = <?= json_encode(array_values(array_map(function($p) use ($PHOTO_TYPES) {
    return [
        'id'         => (int)$p['id'],
        'path'       => $p['file_path'],
        'type'       => $p['photo_type'],
        'type_label' => $PHOTO_TYPES[$p['photo_type']] ?? $p['photo_type'],
        'comment'    => $p['comment'] ?? '',
        'is_video'   => (strpos((string)($p['mime_type'] ?? ''), 'video/') === 0),
    ];
}, $photos)), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?>;
window.IPG_KEY_LABEL = <?= json_encode($keyLabel) ?>;
window.IPG_KEY_VALUE = <?= json_encode($viewKeyValue) ?>;
</script>
<?php endif; ?>

<?php if ($viewMode): ?>
<script>
/* ========== ЗАГРУЗКА ФОТО/ВИДЕО ========== */
(function() {
  const KEY_TYPE  = <?= json_encode($viewKeyType) ?>;
  const KEY_VALUE = <?= json_encode($viewKeyValue) ?>;

  let selectedPhotoType = null;
  let lastUploadedFile  = null;
  let lastSource        = null;

  const photoCamera  = document.getElementById('photoCamera');
  const photoGallery = document.getElementById('photoGallery');
  const videoCamera  = document.getElementById('videoCamera');
  const videoGallery = document.getElementById('videoGallery');

  const sourceModal = document.getElementById('sourceModal');
  const saveModal   = document.getElementById('saveModal');
  const sourceTitle = document.getElementById('sourceModalTitle');

  document.querySelectorAll('.type-option').forEach(function(el) {
    el.addEventListener('click', function() {
      selectedPhotoType = el.dataset.type;
      document.querySelectorAll('.type-option').forEach(function(x) { x.classList.remove('selected'); });
      el.classList.add('selected');

      const isVideo = selectedPhotoType === 'video_defect';
      sourceTitle.textContent = isVideo
        ? 'Записать видео дефекта — как?'
        : 'Загрузить: «' + el.textContent.trim() + '» — откуда?';
      sourceModal.classList.add('active');
    });
  });

  window.closeSourceModal = function() {
    sourceModal.classList.remove('active');
  };

  window.chooseSource = function(source) {
    sourceModal.classList.remove('active');
    lastSource = source;
    const isVideo = selectedPhotoType === 'video_defect';
    let input;
    if (isVideo) input = (source === 'camera') ? videoCamera  : videoGallery;
    else         input = (source === 'camera') ? photoCamera  : photoGallery;
    input.click();
  };

  [photoCamera, photoGallery, videoCamera, videoGallery].forEach(function(input) {
    input.addEventListener('change', function() {
      const file = input.files && input.files[0];
      if (!file || !selectedPhotoType) return;
      lastUploadedFile = file;
      uploadFile(file);
      input.value = '';
    });
  });

  function uploadFile(file) {
    const comment = document.getElementById('commentInput').value.trim();
    const formData = new FormData();
    formData.append('key_type',   KEY_TYPE);
    formData.append('key_value',  KEY_VALUE);
    formData.append('photo_type', selectedPhotoType);
    formData.append('comment',    comment);
    formData.append('photo', file, file.name || ('upload_' + Date.now() + '.jpg'));

    const status = document.createElement('div');
    status.className = 'upload-status';
    status.style.background = 'linear-gradient(135deg, #2563eb, #4f46e5)';
    status.textContent = '📤 Загрузка...';
    document.body.appendChild(status);

    fetch('upload.php', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) {
        return r.text().then(function(text) {
          return { ok: r.ok, status: r.status, text: text || '' };
        });
      })
      .then(function(res) {
        let serverError = null;
        if (res.text) {
          try {
            const j = JSON.parse(res.text);
            if (j && j.error) serverError = j.error;
          } catch (e) {
            if (!res.ok) serverError = 'HTTP ' + res.status + ': ' + res.text.slice(0, 200);
          }
        }
        if (serverError) throw new Error(serverError);
        if (!res.ok)     throw new Error('HTTP ' + res.status);

        status.style.background = 'linear-gradient(135deg, #059669, #10b981)';
        status.textContent = '✅ Загружено';
        setTimeout(function() {
          if (document.body.contains(status)) document.body.removeChild(status);
        }, 500);

        if (lastSource === 'camera' && navigator.share) {
          saveModal.classList.add('active');
        } else {
          finishUpload();
        }
      })
      .catch(function(err) {
        status.style.background = 'linear-gradient(135deg, #dc2626, #ef4444)';
        status.textContent = '❌ ' + (err.message || 'ошибка загрузки');
        console.error('upload error:', err);
        setTimeout(function() {
          if (document.body.contains(status)) document.body.removeChild(status);
        }, 5000);
      });
  }

  window.saveToPhone = async function() {
    const file = lastUploadedFile;
    if (!file) { finishUpload(); return; }

    if (navigator.share && navigator.canShare) {
      try {
        if (navigator.canShare({ files: [file] })) {
          await navigator.share({
            files: [file],
            title: 'Файл по ' + KEY_VALUE,
          });
          finishUpload();
          return;
        }
      } catch (e) {
        if (e && e.name === 'AbortError') { finishUpload(); return; }
      }
    }
    try {
      const url = URL.createObjectURL(file);
      const a = document.createElement('a');
      a.href = url;
      a.download = file.name || ('photo_' + Date.now() + '.jpg');
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(function() { URL.revokeObjectURL(url); }, 3000);
    } catch (e) {}
    finishUpload();
  };

  window.finishUpload = function() {
    saveModal.classList.remove('active');
    window.location.href = 'gallery.php?key_type=' + encodeURIComponent(KEY_TYPE)
      + '&key_value=' + encodeURIComponent(KEY_VALUE) + '&uploaded=1';
  };
})();
</script>
<?php endif; ?>

<script>
/* ========== PDF-РЕДАКТОР ========== */
(function() {
  if (!window.IPG_PHOTOS) return;

  const modal     = document.getElementById('pdfEditorModal');
  const grid      = document.getElementById('pdfPagesGrid');
  const statusEl  = document.getElementById('pdfStatus');
  const appendInp = document.getElementById('pdfAppendInput');
  const appendLst = document.getElementById('pdfAppendList');

  const blobCache  = new Map();
  let pages        = [];
  let appendedPdfs = [];

  window.openPdfEditor = function() {
    pages = window.IPG_PHOTOS
      .filter(function(p) { return !p.is_video; })
      .map(function(p) {
        return { kind: 'img', id: p.id, path: p.path, label: p.type_label, comment: p.comment, rotation: 0 };
      });
    render();
    statusEl.textContent = '';
    modal.classList.add('active');
    preloadAll();
  };
  window.closePdfEditor = function() {
    modal.classList.remove('active');
  };

  async function preloadAll() {
    const jobs = pages.filter(function(p) { return p.kind === 'img' && !blobCache.has(p.id); });
    await Promise.all(jobs.map(async function(p) {
      try {
        const res = await fetch('download.php?id=' + p.id, { credentials: 'same-origin' });
        if (!res.ok) return;
        blobCache.set(p.id, await res.blob());
      } catch (e) {}
    }));
  }

  function render() {
    if (pages.length === 0) {
      grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; color:#94a3b8; padding:40px;">Нет фото. Добавьте PDF-файл ниже.</div>';
    } else {
      grid.innerHTML = '';
      pages.forEach(function(p, idx) {
        const card = document.createElement('div');
        card.draggable = true;
        card.dataset.idx = idx;
        card.style.cssText = 'background:#fff; border:1.5px solid rgba(15,23,42,0.08); border-radius:14px; padding:12px; position:relative; cursor:grab; transition:all 0.15s;';

        let previewHtml = '';
        if (p.kind === 'img') {
          const rot = p.rotation || 0;
          previewHtml = '<img src="' + p.path + '" data-idx="' + idx + '" ' +
            'style="width:100%; height:200px; object-fit:cover; border-radius:10px; display:block; cursor:zoom-in; transform:rotate(' + rot + 'deg);" ' +
            'loading="lazy" onclick="viewerOpen(' + idx + ')">';
        } else {
          previewHtml = '<div style="width:100%; height:200px; display:flex; align-items:center; justify-content:center; background:#f1f5f9; border-radius:10px; font-size:56px;">📎</div>';
        }

        card.innerHTML = previewHtml +
          '<div style="font-size:13px; color:#475569; margin-top:10px; line-height:1.4; font-weight:600;">' +
            (idx + 1) + '. ' + escapeHtml(p.label || '') +
            (p.rotation ? ' <span style="color:#7c3aed;">(' + p.rotation + '°)</span>' : '') +
          '</div>' +
          '<div style="display:flex; gap:6px; margin-top:10px;">' +
            (p.kind === 'img' ? '<button type="button" class="btn btn-secondary btn-small" style="flex:1; padding:6px;" onclick="rotateCard(' + idx + ')">↻</button>' : '') +
            '<button type="button" class="btn btn-secondary btn-small" style="flex:1; padding:6px;" onclick="viewerOpen(' + idx + ')">⤢</button>' +
            '<button type="button" class="btn btn-red btn-small" style="flex:1; padding:6px;" onclick="removeCard(' + idx + ')">✕</button>' +
          '</div>';

        card.addEventListener('dragstart', function(e) {
          e.dataTransfer.setData('text/plain', idx);
        });
        card.addEventListener('dragover', function(e) { e.preventDefault(); });
        card.addEventListener('drop', function(e) {
          e.preventDefault();
          const from = parseInt(e.dataTransfer.getData('text/plain'), 10);
          if (from === idx) return;
          const moved = pages.splice(from, 1)[0];
          pages.splice(idx, 0, moved);
          render();
        });

        grid.appendChild(card);
      });
    }
    appendLst.innerHTML = appendedPdfs.map(function(f, i) {
      return '📎 ' + escapeHtml(f.name) + ' <a href="#" data-i="' + i + '" style="color:#dc2626; margin-left:8px;">убрать</a>';
    }).join('<br>');
    appendLst.querySelectorAll('a[data-i]').forEach(function(a) {
      a.onclick = function(e) {
        e.preventDefault();
        const i = parseInt(a.dataset.i, 10);
        const removed = appendedPdfs[i];
        appendedPdfs.splice(i, 1);
        pages = pages.filter(function(p) { return !(p.kind === 'pdf' && p.file === removed); });
        render();
      };
    });
  }

  window.rotateCard = function(idx) {
    if (pages[idx] && pages[idx].kind === 'img') {
      pages[idx].rotation = ((pages[idx].rotation || 0) + 90) % 360;
      render();
    }
  };
  window.removeCard = function(idx) {
    pages.splice(idx, 1);
    render();
  };

  function escapeHtml(s) {
    return String(s).replace(/[&<>"']/g, function(c) {
      return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
    });
  }

  appendInp.addEventListener('change', function() {
    for (let i = 0; i < appendInp.files.length; i++) {
      const f = appendInp.files[i];
      if (f.type === 'application/pdf') {
        appendedPdfs.push(f);
        pages.push({ kind: 'pdf', file: f, label: 'PDF: ' + f.name, rotation: 0 });
      }
    }
    appendInp.value = '';
    render();
  });

  const viewer      = document.getElementById('photoViewer');
  const viewerImg   = document.getElementById('photoViewerImg');
  const viewerTitle = document.getElementById('photoViewerTitle');
  let viewerIdx = -1, viewerRotation = 0, viewerZoom = 1;

  window.viewerOpen = function(idx) {
    const p = pages[idx];
    if (!p || p.kind !== 'img') return;
    viewerIdx = idx;
    viewerRotation = p.rotation || 0;
    viewerZoom = 1;
    viewerImg.src = p.path;
    viewerTitle.textContent = (idx + 1) + '. ' + (p.label || '');
    applyViewerTransform();
    viewer.classList.add('active');
  };
  window.viewerRotate = function() { viewerRotation = (viewerRotation + 90) % 360; applyViewerTransform(); };
  window.viewerReset  = function() { viewerRotation = 0; viewerZoom = 1; applyViewerTransform(); };
  function applyViewerTransform() {
    viewerImg.style.transform = 'rotate(' + viewerRotation + 'deg) scale(' + viewerZoom + ')';
  }
  viewer.addEventListener('wheel', function(e) {
    if (!viewer.classList.contains('active')) return;
    e.preventDefault();
    viewerZoom += (e.deltaY < 0 ? 0.1 : -0.1);
    viewerZoom = Math.max(0.3, Math.min(4, viewerZoom));
    applyViewerTransform();
  }, { passive: false });
  viewer.addEventListener('click', function(e) {
    if (e.target === viewer) window.viewerClose();
  });
  window.viewerClose = function() {
    if (viewerIdx >= 0 && pages[viewerIdx]) {
      pages[viewerIdx].rotation = viewerRotation;
    }
    viewer.classList.remove('active');
    render();
  };

  const QUALITY = {
    original: { maxW: null, q: null },
    good:     { maxW: 1600, q: 0.85 },
    medium:   { maxW: 1200, q: 0.7 },
    small:    { maxW: 900,  q: 0.55 },
  };

  async function getBlobForPdf(p) {
    if (p.kind === 'img') {
      if (blobCache.has(p.id)) return blobCache.get(p.id);
      const res = await fetch('download.php?id=' + p.id, { credentials: 'same-origin' });
      if (!res.ok) throw new Error('Фото id=' + p.id + ' → HTTP ' + res.status);
      const b = await res.blob();
      blobCache.set(p.id, b);
      return b;
    }
    return null;
  }

  async function compressImage(blob, rotation, qualityKey) {
    const q = QUALITY[qualityKey];
    const url = URL.createObjectURL(blob);
    try {
      const img = await new Promise(function(res, rej) {
        const i = new Image();
        i.onload = function() { res(i); };
        i.onerror = function() { rej(new Error('image load')); };
        i.src = url;
      });

      const w = img.width, h = img.height;
      const rotated = (rotation % 180) !== 0;

      let targetW = w, targetH = h;
      if (q.maxW && w > q.maxW) {
        const k = q.maxW / w;
        targetW = Math.round(w * k);
        targetH = Math.round(h * k);
      }

      const canvas = document.createElement('canvas');
      canvas.width  = rotated ? targetH : targetW;
      canvas.height = rotated ? targetW : targetH;
      const ctx = canvas.getContext('2d');

      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);

      ctx.save();
      ctx.translate(canvas.width / 2, canvas.height / 2);
      ctx.rotate(rotation * Math.PI / 180);
      ctx.drawImage(img, -targetW / 2, -targetH / 2, targetW, targetH);
      ctx.restore();

      const outBlob = await new Promise(function(res) {
        canvas.toBlob(res, 'image/jpeg', q.q || 0.9);
      });
      return outBlob;
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  window.buildPdf = async function() {
    if (pages.length === 0) { statusEl.textContent = '⚠️ Нет страниц'; return; }
    statusEl.textContent = '⏳ Собираю PDF...';
    try {
      const { PDFDocument } = PDFLib;
      const out = await PDFDocument.create();
      const qualityKey = document.getElementById('pdfQuality').value;
      const A4_W = 595.28, A4_H = 841.89;

      for (let i = 0; i < pages.length; i++) {
        const p = pages[i];
        statusEl.textContent = '⏳ Страница ' + (i + 1) + ' из ' + pages.length + '...';

        if (p.kind === 'img') {
          const raw  = await getBlobForPdf(p);
          const comp = await compressImage(raw, p.rotation || 0, qualityKey);
          const buf  = await comp.arrayBuffer();

          let img;
          try {
            img = await out.embedJpg(buf);
          } catch (e) {
            img = await out.embedPng(buf);
          }

          const scale = Math.min(A4_W / img.width, A4_H / img.height);
          const w = img.width  * scale;
          const h = img.height * scale;
          const page = out.addPage([A4_W, A4_H]);
          page.drawImage(img, {
            x: (A4_W - w) / 2,
            y: (A4_H - h) / 2,
            width: w, height: h,
          });
        } else if (p.kind === 'pdf') {
          const bytes    = await p.file.arrayBuffer();
          const srcDoc   = await PDFDocument.load(bytes);
          const srcPages = await out.copyPages(srcDoc, srcDoc.getPageIndices());
          srcPages.forEach(function(sp) { out.addPage(sp); });
        }
      }

      const pdfBytes = await out.save();
      const blob     = new Blob([pdfBytes], { type: 'application/pdf' });
      const url      = URL.createObjectURL(blob);
      const a        = document.createElement('a');
      a.href = url;
      a.download = window.IPG_KEY_VALUE + '.pdf';
      document.body.appendChild(a);
      a.click();
      document.body.removeChild(a);
      setTimeout(function() { URL.revokeObjectURL(url); }, 5000);

      const mb = (blob.size / 1048576).toFixed(1);
      statusEl.textContent = '✅ Готово! Размер: ' + mb + ' МБ';
    } catch (err) {
      console.error(err);
      statusEl.textContent = '❌ Ошибка: ' + err.message;
    }
  };
})();
</script>

</body>
</html>
