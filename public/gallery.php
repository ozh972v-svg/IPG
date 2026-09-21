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

$VIDEO_TYPES = ['video_defect'];

$pdo = get_db();

$searchQuery = trim($_GET['q'] ?? '');

$viewKeyType = trim($_GET['key_type'] ?? '');
$viewKeyValue = trim($_GET['key_value'] ?? '');
$viewMode = $viewKeyType && $viewKeyValue;

/* === Если пришли «+ Добавить» — сохраняем === */
if (!empty($_GET['save'])
    && $_SERVER['REQUEST_METHOD'] === 'GET'
    && !empty($_GET['key_value'])
    && !empty($_GET['key_type'])
    && in_array($_GET['key_type'], ['ra', 'vin'], true)
) {
    $newKeyValue = trim($_GET['key_value']);
    $newGos      = trim($_GET['gos_number'] ?? '');
    $newOrder    = trim($_GET['order_number'] ?? '');
    $newDescr    = trim($_GET['description'] ?? '');

    if ($newKeyValue !== '') {
        try {
            $stmt = $pdo->prepare("
                INSERT INTO keys (key_type, key_value, gos_number, order_number, description, user_id, created_at, updated_by, updated_at)
                VALUES (:kt, :kv, :gos, :ord, :descr, :uid, NOW(), :uid2, NOW())
                ON CONFLICT (key_type, key_value) DO UPDATE
                    SET gos_number   = COALESCE(NULLIF(EXCLUDED.gos_number, ''),   keys.gos_number),
                        order_number = COALESCE(NULLIF(EXCLUDED.order_number, ''), keys.order_number),
                        description  = COALESCE(NULLIF(EXCLUDED.description, ''),  keys.description),
                        updated_by   = EXCLUDED.updated_by,
                        updated_at   = NOW()
            ");
            $stmt->execute([
                ':kt'    => $_GET['key_type'],
                ':kv'    => $newKeyValue,
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
if (!$viewMode) {
    if ($searchQuery !== '') {
        $stmt = $pdo->prepare("
            SELECT k.key_type, k.key_value, k.gos_number, k.order_number, k.description,
                   k.user_id, k.created_at, k.updated_by, k.updated_at
              FROM keys k
             WHERE k.key_value ILIKE :q
                OR COALESCE(k.gos_number, '')   ILIKE :q
                OR COALESCE(k.order_number, '') ILIKE :q
                OR COALESCE(k.description, '')  ILIKE :q
             ORDER BY k.key_value DESC
        ");
        $stmt->execute([':q' => '%' . $searchQuery . '%']);
    } else {
        $stmt = $pdo->query("
            SELECT k.key_type, k.key_value, k.gos_number, k.order_number, k.description,
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
        $groups[] = [
            'key_type'     => $k['key_type'],
            'key_value'    => $k['key_value'],
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
$allPhotosSize = (int)$summary['total_size'];

function formatSize($bytes) {
    if ($bytes < 1024) return $bytes . ' Б';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' КБ';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' МБ';
    return round($bytes / 1073741824, 2) . ' ГБ';
}
function isVideoMime(?string $mime): bool {
    return $mime !== null && strpos($mime, 'video/') === 0;
}

/* Заголовки с описанием через дефис */
$keyLabel = $viewKeyType === 'ra' ? 'РА' : 'VIN';
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
<style>
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; margin: 0; padding: 16px; color: #1a1a1a; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; }
  .card { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 16px; }
  h1 { font-size: 22px; margin: 0 0 8px; }
  h2 { font-size: 18px; margin: 0 0 16px; }
  .subtitle { color: #666; font-size: 14px; margin: 0 0 16px; }
  .key-description {
    font-size: 15px; color: #1a1a1a; font-weight: 600;
    background: #fffbeb; border-left: 4px solid #f59e0b;
    padding: 10px 14px; border-radius: 10px; margin: 0 0 14px;
  }
  .top-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
  .user-info { font-size: 13px; color: #666; }
  .user-info b { color: #2563eb; }
  .logout { color: #dc2626; text-decoration: none; font-size: 13px; margin-left: 12px; }
  .btn { display: inline-block; padding: 12px 18px; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; background: #2563eb; color: #fff; text-decoration: none; text-align: center; transition: opacity 0.2s; }
  .btn:hover { opacity: 0.9; }
  .btn-secondary { background: #fff; color: #2563eb; border: 1.5px solid #2563eb; }
  .btn-green { background: #16a34a; }
  .btn-red { background: #dc2626; }
  .btn-small { padding: 8px 14px; font-size: 14px; }
  .btn-row { display: flex; gap: 10px; flex-wrap: wrap; }
  .form-row { margin-bottom: 14px; }
  .form-row label { display: block; font-size: 13px; font-weight: 600; color: #666; margin-bottom: 6px; }
  .form-row input, .form-row select, .form-row textarea { width: 100%; padding: 10px 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 15px; font-family: inherit; }
  .form-row input:focus, .form-row select:focus, .form-row textarea:focus { outline: none; border-color: #2563eb; }
  .form-row textarea { resize: vertical; min-height: 60px; }

  /* === Список записей === */
  .group-item {
    display: flex; align-items: center; gap: 12px;
    padding: 14px 16px; border: 1.5px solid #e5e7eb; border-radius: 12px;
    margin-bottom: 10px; background: #fff; transition: all 0.15s;
  }
  .group-item:hover { border-color: #2563eb; background: #f8faff; }
  .group-link {
    flex: 1; min-width: 0; text-decoration: none; color: inherit;
    display: flex; align-items: center; gap: 14px;
  }
  .group-main { flex: 1; min-width: 0; }
  .group-item-title { font-weight: 700; font-size: 16px; color: #1e3a8a; }
  .group-item-desc { font-size: 13px; color: #1a1a1a; margin-top: 4px; font-weight: 500; }
  .group-item-sub { font-size: 12px; color: #888; margin-top: 4px; }
  .group-item-meta { font-size: 11px; color: #999; margin-top: 4px; }

  .group-thumbs { display: flex; gap: 6px; flex-shrink: 0; }
  .group-thumb { width: 64px; height: 64px; border-radius: 8px; border: 1.5px solid #e5e7eb; background: #f9fafb; object-fit: cover; display: block; }
  .group-thumb-empty {
    width: 64px; height: 64px; border-radius: 8px;
    border: 1.5px dashed #e5e7eb; background: #fafafa;
    display: flex; align-items: center; justify-content: center;
    color: #cbd5e1; font-size: 20px;
  }
  .group-thumb-label { display: block; font-size: 9px; color: #999; text-align: center; margin-top: 2px; }

  .group-actions { display: flex; align-items: center; gap: 8px; flex-shrink: 0; }
  .group-item-count { font-size: 13px; color: #2563eb; background: #eff6ff; padding: 4px 12px; border-radius: 12px; font-weight: 600; }
  .group-del-btn { color: #dc2626; font-size: 18px; text-decoration: none; padding: 6px 10px; border-radius: 8px; cursor: pointer; }
  .group-del-btn:hover { background: #fef2f2; }

  .search-bar { display: flex; gap: 8px; flex-wrap: wrap; }
  .search-bar input { flex: 1; min-width: 200px; padding: 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 15px; }
  .search-bar input:focus { outline: none; border-color: #2563eb; }

  /* === Плитки типов фото === */
  .type-selector {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(190px, 1fr));
    gap: 8px;
    margin-bottom: 12px;
  }
  .type-option {
    padding: 12px 14px;
    border: 1.5px solid #e5e7eb;
    border-radius: 10px;
    font-size: 13px;
    cursor: pointer;
    background: #fff;
    text-align: center;
    transition: all 0.15s;
    user-select: none;
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    min-height: 46px;
    font-weight: 500;
    color: #444;
    position: relative;
  }
  .type-option:hover { border-color: #2563eb; }

  /* Красная — фоток этого типа нет */
  .type-option.is-empty {
    border-color: #fca5a5;
    background: #fef2f2;
    color: #991b1b;
  }
  .type-option.is-empty::before {
    content: '●';
    color: #dc2626;
    font-size: 10px;
    position: absolute;
    top: 6px; right: 8px;
  }

  /* Зелёная — уже есть фото этого типа */
  .type-option.has-photos {
    border-color: #86efac;
    background: #f0fdf4;
    color: #166534;
    font-weight: 600;
  }
  .type-option.has-photos::before {
    content: '✓';
    color: #16a34a;
    font-size: 14px;
    font-weight: 800;
    position: absolute;
    top: 4px; right: 8px;
  }

  /* Видео-плитка — с иконкой */
  .type-option[data-type="video_defect"] {
    border-left: 4px solid #7c3aed;
  }
  .type-option[data-type="video_defect"].is-empty { border-left-color: #dc2626; }
  .type-option[data-type="video_defect"].has-photos { border-left-color: #16a34a; }

  /* === Сетка фото/видео === */
  .photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(200px, 1fr)); gap: 12px; }
  .photo-card { background: #f9fafb; border-radius: 10px; overflow: hidden; border: 1.5px solid #e5e7eb; position: relative; }
  .photo-card img, .photo-card video {
    width: 100%; height: 160px; object-fit: cover; display: block;
    background: #e5e7eb; cursor: pointer;
  }
  .photo-meta { padding: 10px; font-size: 11px; color: #666; }
  .photo-type { display: inline-block; padding: 2px 8px; border-radius: 6px; background: #eff6ff; color: #2563eb; font-weight: 600; font-size: 11px; margin-bottom: 4px; }
  .photo-type.is-video { background: #ede9fe; color: #6d28d9; }
  .photo-user { font-size: 11px; color: #888; margin-top: 4px; }
  .photo-actions { display: flex; gap: 4px; margin-top: 8px; }
  .photo-actions a { flex: 1; padding: 5px 8px; border-radius: 6px; font-size: 11px; text-align: center; text-decoration: none; font-weight: 600; }
  .action-edit { background: #eff6ff; color: #2563eb; }
  .action-del { background: #fef2f2; color: #dc2626; }
  .action-dl { background: #f0fdf4; color: #16a34a; }
  .action-pdf { background: #fef3c7; color: #b45309; }

  .empty-state { text-align: center; padding: 40px 20px; color: #888; font-size: 14px; }
  .empty-state .big { font-size: 48px; margin-bottom: 12px; }

  .alert { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
  .alert-success { background: #f0fdf4; color: #16a34a; border-left: 4px solid #16a34a; }
  .alert-error { background: #fef2f2; color: #dc2626; border-left: 4px solid #dc2626; }
  .alert-info { background: #eff6ff; color: #2563eb; border-left: 4px solid #2563eb; }

  .upload-status { position: fixed; top: 0; left: 0; right: 0; padding: 14px; text-align: center; font-weight: 600; z-index: 9999; color: #fff; }

  /* === Модальные окна === */
  .modal-backdrop {
    display: none;
    position: fixed; inset: 0; background: rgba(0,0,0,0.55);
    z-index: 10000; align-items: center; justify-content: center; padding: 20px;
  }
  .modal-backdrop.is-open { display: flex; }
  .modal-box {
    background: #fff; border-radius: 16px; padding: 24px;
    max-width: 420px; width: 100%;
    box-shadow: 0 20px 60px rgba(0,0,0,0.3);
  }
  .modal-box h3 { margin: 0 0 8px; font-size: 18px; text-align: center; }
  .modal-box p { text-align: center; color: #666; margin: 0 0 18px; font-size: 14px; }
  .modal-box .btn { display: block; width: 100%; margin-bottom: 10px; padding: 16px; font-size: 16px; }
  .modal-box .btn-cancel { background: #e5e7eb; color: #333; padding: 12px; font-size: 15px; margin-bottom: 0; }

  @media (max-width: 700px) {
    .group-thumbs { display: none; }
    .photo-grid { grid-template-columns: repeat(auto-fill, minmax(150px, 1fr)); gap: 8px; }
    .photo-card img, .photo-card video { height: 130px; }
  }
</style>
</head>
<body>
<div class="container">

  <div class="card">
    <div class="top-bar">
      <h1>📸 <?= $viewMode ? e($viewTitle) : 'Фото по РА — общая база' ?></h1>
      <div>
        <span class="user-info">👤 <b><?= e($user['name'] ?: $user['email']) ?></b></span>
        <a href="logout.php" class="logout">Выйти</a>
      </div>
    </div>

    <?php if ($viewMode && $currentKey): ?>
      <?php if (!empty($currentKey['description'])): ?>
        <div class="key-description">📝 <?= e($currentKey['description']) ?></div>
      <?php endif; ?>
      <?php if (!empty($currentKey['gos_number']) || !empty($currentKey['order_number'])): ?>
        <p class="subtitle">
          <?php if (!empty($currentKey['gos_number'])): ?>🚗 Гос. номер: <b><?= e($currentKey['gos_number']) ?></b><?php endif; ?>
          <?php if (!empty($currentKey['gos_number']) && !empty($currentKey['order_number'])): ?> · <?php endif; ?>
          <?php if (!empty($currentKey['order_number'])): ?>📋 Заказ-наряд: <b><?= e($currentKey['order_number']) ?></b><?php endif; ?>
        </p>
      <?php endif; ?>
    <?php endif; ?>

    <?php if (!$viewMode): ?>
      <p class="subtitle">Всего файлов: <?= $allPhotosCount ?> · Размер: <?= formatSize($allPhotosSize) ?></p>
    <?php endif; ?>

    <div class="btn-row">
      <?php if ($viewMode): ?>
        <a href="gallery.php" class="btn btn-secondary btn-small">← Ко всем РА</a>
        <a href="download.php?key_type=<?= e($viewKeyType) ?>&key=<?= urlencode($viewKeyValue) ?>" class="btn btn-small">📥 Скачать ZIP</a>
        <a href="print_pdf.php?key_type=<?= e($viewKeyType) ?>&key_value=<?= urlencode($viewKeyValue) ?>" target="_blank" class="btn btn-small" style="background:#b45309;">📄 PDF по <?= e($keyLabel) ?></a>
        <button type="button" class="btn btn-small" style="background:#7c3aed;" onclick="openPdfEditor()">✏️ Редактировать PDF</button>
        <?php if ($totalPhotos > 0): ?>
          <a href="#" onclick="if(confirm('Удалить ВСЕ <?= $totalPhotos ?> файлов по этому <?= e($keyLabel) ?>?')){document.getElementById('deleteAllForm').submit();}return false;" class="btn btn-red btn-small">🗑️ Удалить все</a>
          <form id="deleteAllForm" method="post" action="delete_all.php" style="display:none;">
            <input type="hidden" name="key_type" value="<?= e($viewKeyType) ?>">
            <input type="hidden" name="key_value" value="<?= e($viewKeyValue) ?>">
          </form>
        <?php endif; ?>
      <?php else: ?>
        <a href="index.html" class="btn btn-secondary btn-small">← На рабочее место</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (isset($_GET['uploaded'])): ?>
    <div class="alert alert-success">✅ Файл успешно загружен</div>
  <?php endif; ?>
  <?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">🗑️ Файл удалён</div>
  <?php endif; ?>
  <?php if (isset($_GET['deleted_all_photos'])): ?>
    <div class="alert alert-success">🗑️ Все файлы по <?= e($_GET['deleted_all_photos'] === 'ra' ? 'РА' : 'VIN') ?> удалены</div>
  <?php endif; ?>
  <?php if (isset($_GET['deleted_all_key'])): ?>
    <div class="alert alert-success">🗑️ Запись и все её файлы удалены</div>
  <?php endif; ?>
  <?php if (isset($_GET['edited'])): ?>
    <div class="alert alert-success">✏️ Изменения сохранены</div>
  <?php endif; ?>
  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error">❌ Ошибка: <?= e($_GET['error']) ?></div>
  <?php endif; ?>

  <?php if (!$viewMode): ?>
    <!-- ЭКРАН 1: Список РА -->

    <div class="card">
      <h2>+ Добавить новый РА / VIN</h2>
      <form method="get" action="gallery.php">
        <input type="hidden" name="save" value="1">
        <div class="form-row">
          <label>Тип привязки</label>
          <select name="key_type">
            <option value="ra">РА (номер рекламационного акта)</option>
            <option value="vin">VIN / Номер шасси</option>
          </select>
        </div>
        <div class="form-row">
          <label>Номер</label>
          <input type="text" name="key_value" required placeholder="Например: 12345">
        </div>
        <div class="form-row">
          <label>Краткое описание (обязательно)</label>
          <input type="text" name="description" required placeholder="Например: течь гидроцилиндра подъёма кабины" maxlength="250">
        </div>
        <div class="form-row">
          <label>Гос. номер (необязательно)</label>
          <input type="text" name="gos_number" placeholder="Например: А123БВ 116">
        </div>
        <div class="form-row">
          <label>Номер заказ-наряда (необязательно)</label>
          <input type="text" name="order_number" placeholder="Например: ЗН-00456">
        </div>
        <button type="submit" class="btn btn-green">+ Добавить</button>
      </form>
    </div>

    <div class="card">
      <h2>🔍 Поиск</h2>
      <form method="get" class="search-bar">
        <input type="text" name="q" placeholder="Поиск по РА, VIN, гос. номеру, заказ-наряду, описанию" value="<?= e($searchQuery) ?>">
        <button type="submit" class="btn btn-secondary btn-small">Найти</button>
        <?php if ($searchQuery): ?>
          <a href="gallery.php" class="btn btn-secondary btn-small">Сбросить</a>
        <?php endif; ?>
      </form>
    </div>

    <div class="card">
      <h2>Все записи (<?= count($groups) ?>)</h2>
      <?php if (empty($groups)): ?>
        <div class="empty-state">
          <div class="big">📷</div>
          <div>Пока нет ни одного РА или VIN</div>
        </div>
      <?php else: ?>
        <?php foreach ($groups as $g): ?>
          <?php $delId = 'delAll-' . md5($g['key_type'] . $g['key_value']); ?>
          <div class="group-item">
            <a href="gallery.php?key_type=<?= e($g['key_type']) ?>&key_value=<?= urlencode($g['key_value']) ?>" class="group-link">
              <div class="group-main">
                <div class="group-item-title"><?= e($g['key_type'] === 'ra' ? 'РА' : 'VIN') ?>: <?= e($g['key_value']) ?><?php if (!empty($g['description'])): ?><span style="font-weight:500;color:#555;"> — <?= e($g['description']) ?></span><?php endif; ?></div>
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
              <a href="#" class="group-del-btn" title="Удалить весь РА и все файлы"
                 onclick="if(confirm('Удалить <?= e($g['key_type'] === 'ra' ? 'РА' : 'VIN') ?>: <?= e($g['key_value']) ?> и ВСЕ его файлы?')){document.getElementById('<?= $delId ?>').submit();}return false;">🗑️</a>
              <form id="<?= $delId ?>" method="post" action="delete_all.php" style="display:none;">
                <input type="hidden" name="key_type" value="<?= e($g['key_type']) ?>">
                <input type="hidden" name="key_value" value="<?= e($g['key_value']) ?>">
                <input type="hidden" name="remove_key" value="1">
              </form>
            </div>
          </div>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <!-- ЭКРАН 2: Внутри РА -->

    <div class="card">
      <h2>Загрузить фото / видео</h2>
      <div class="step-hint" style="font-size:13px;color:#666;margin-bottom:12px;padding:8px 12px;background:#f9fafb;border-radius:8px;border-left:3px solid #2563eb;">
        <b>Красные</b> плитки — по этому типу ещё ничего нет. <b>Зелёные</b> — уже загружено.
        Нажми на плитку — выберешь: снять на камеру или взять из галереи.
      </div>
      <div class="form-row">
        <label>Тип файла</label>
        <div class="type-selector" id="typeSelector">
          <?php foreach ($PHOTO_TYPES as $id => $name): ?>
            <?php $has = !empty($photosByType[$id]); ?>
            <div class="type-option <?= $has ? 'has-photos' : 'is-empty' ?>" data-type="<?= e($id) ?>">
              <?= e($name) ?>
            </div>
          <?php endforeach; ?>
        </div>
        <!-- Фото: камера и галерея -->
        <input type="file" id="photoCamera"  accept="image/*" capture="environment" style="display:none;">
        <input type="file" id="photoGallery" accept="image/*" style="display:none;">
        <!-- Видео: камера и галерея -->
        <input type="file" id="videoCamera"  accept="video/*" capture="environment" style="display:none;">
        <input type="file" id="videoGallery" accept="video/*" style="display:none;">
      </div>
      <div class="form-row">
        <label>Комментарий (необязательно)</label>
        <textarea id="commentInput" placeholder="Например: течь ОЖ в районе патрубка"></textarea>
      </div>
    </div>

    <div class="card">
      <h2>Фото и видео (<?= $totalPhotos ?>)</h2>
      <?php if (empty($photos)): ?>
        <div class="empty-state">
          <div class="big">📷</div>
          <div>Пока ничего нет</div>
          <div style="margin-top:6px;">Тапните по типу файла выше</div>
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
                  <div style="margin-top:4px;color:#333;"><?= e($p['comment']) ?></div>
                <?php endif; ?>
                <div class="photo-user">👤 <?= e($p['user_name'] ?: $p['user_email']) ?></div>
                <div style="margin-top:2px;font-size:10px;color:#aaa;"><?= e(date('d.m.Y H:i', strtotime($p['created_at']))) ?></div>
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
<div class="modal-backdrop" id="sourceModal">
  <div class="modal-box">
    <h3>Откуда взять файл?</h3>
    <p id="sourceModalTitle">Выбери источник</p>
    <button type="button" class="btn btn-green" onclick="chooseSource('camera')">📷 Снять на камеру</button>
    <button type="button" class="btn btn-secondary" onclick="chooseSource('gallery')">🖼 Из галереи телефона</button>
    <button type="button" class="btn btn-cancel" onclick="closeSourceModal()">Отмена</button>
  </div>
</div>

<!-- Модальное окно: сохранить в галерею телефона -->
<div class="modal-backdrop" id="saveModal">
  <div class="modal-box">
    <h3>✅ Файл загружен</h3>
    <p>Сохранить копию в галерею телефона?</p>
    <button type="button" class="btn btn-green" onclick="saveToPhone()">💾 Сохранить в телефон</button>
    <button type="button" class="btn btn-cancel" onclick="finishUpload()">Пропустить</button>
  </div>
</div>

<!-- Модальное окно: PDF-редактор -->
<div class="modal-backdrop" id="pdfEditorModal" style="align-items:flex-start; padding:0;">
  <div style="background:#fff; width:100%; height:100%; display:flex; flex-direction:column; overflow:hidden;">

    <div style="display:flex; align-items:center; justify-content:space-between; padding:12px 20px; border-bottom:1.5px solid #e5e7eb; flex-shrink:0;">
      <h3 style="margin:0; font-size:18px;">✏️ PDF-редактор — <?= e($viewTitle) ?></h3>
      <button type="button" class="btn btn-secondary btn-small" onclick="closePdfEditor()">✕ Закрыть</button>
    </div>

    <div style="display:flex; gap:20px; align-items:center; padding:10px 20px; background:#f9fafb; border-bottom:1.5px solid #e5e7eb; flex-shrink:0; flex-wrap:wrap;">
      <label style="font-size:14px; font-weight:600; color:#444;">
        Качество PDF:
        <select id="pdfQuality" style="padding:6px 10px; border-radius:8px; border:1.5px solid #e5e7eb; font-size:14px; margin-left:8px;">
          <option value="original">Оригинал (большой файл)</option>
          <option value="good" selected>Хорошее (рекомендую)</option>
          <option value="medium">Среднее</option>
          <option value="small">Малое</option>
        </select>
      </label>
      <span style="font-size:13px; color:#666;">
        Клик по фото — увеличить и повернуть. Перетаскивайте карточки для смены порядка.
      </span>
    </div>

    <div style="flex:1; overflow-y:auto; padding:20px; background:#f9fafb;">
      <div id="pdfPagesGrid" style="display:grid; grid-template-columns:repeat(auto-fill, minmax(240px, 1fr)); gap:14px;"></div>

      <div style="margin-top:20px; padding:16px; background:#fff; border-radius:12px; border:1.5px dashed #cbd5e1; text-align:center;">
        <input type="file" id="pdfAppendInput" accept="application/pdf" multiple style="display:none;">
        <button type="button" class="btn btn-secondary btn-small" onclick="document.getElementById('pdfAppendInput').click()">+ Добавить PDF-файл</button>
        <div id="pdfAppendList" style="margin-top:12px; font-size:14px; color:#555;"></div>
      </div>
    </div>

    <div style="padding:14px 20px; border-top:1.5px solid #e5e7eb; display:flex; gap:14px; flex-wrap:wrap; align-items:center; flex-shrink:0; background:#fff;">
      <button type="button" class="btn btn-green" onclick="buildPdf()">📄 Собрать PDF</button>
      <span id="pdfStatus" style="font-size:14px; color:#666; font-weight:600;"></span>
    </div>
  </div>
</div>

<!-- Просмотрщик одного фото -->
<div class="modal-backdrop" id="photoViewer" style="z-index:11000;">
  <div style="background:#111; width:100%; height:100%; display:flex; flex-direction:column;">
    <div style="display:flex; justify-content:space-between; align-items:center; padding:12px 20px; background:#000; color:#fff;">
      <div style="font-size:14px;" id="photoViewerTitle">—</div>
      <div style="display:flex; gap:8px;">
        <button type="button" class="btn btn-small" style="background:#333;" onclick="viewerRotate()">↻ Повернуть</button>
        <button type="button" class="btn btn-small" style="background:#333;" onclick="viewerReset()">⟲ Сброс</button>
        <button type="button" class="btn btn-small btn-red" onclick="viewerClose()">✕ Закрыть</button>
      </div>
    </div>
    <div style="flex:1; display:flex; align-items:center; justify-content:center; overflow:auto; background:#111;">
      <img id="photoViewerImg" src="" style="max-width:100%; max-height:100%; transition:transform 0.15s; transform-origin:center center;">
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

<script>
/* ========== PDF-РЕДАКТОР ========== */
(function() {
  if (!window.IPG_PHOTOS) return;

  const modal     = document.getElementById('pdfEditorModal');
  const grid      = document.getElementById('pdfPagesGrid');
  const statusEl  = document.getElementById('pdfStatus');
  const appendInp = document.getElementById('pdfAppendInput');
  const appendLst = document.getElementById('pdfAppendList');

  const blobCache  = new Map();  // id -> Blob
  let pages        = [];
  let appendedPdfs = [];

  // === Модалка редактора ===
  window.openPdfEditor = function() {
    pages = window.IPG_PHOTOS
      .filter(function(p) { return !p.is_video; })
      .map(function(p) {
        return { kind: 'img', id: p.id, path: p.path, label: p.type_label, comment: p.comment, rotation: 0 };
      });
    render();
    statusEl.textContent = '';
    modal.classList.add('is-open');
    // Заранее прогреваем кэш — грузим всё параллельно в фоне
    preloadAll();
  };
  window.closePdfEditor = function() {
    modal.classList.remove('is-open');
  };

  async function preloadAll() {
    const jobs = pages.filter(function(p) { return p.kind === 'img' && !blobCache.has(p.id); });
    await Promise.all(jobs.map(async function(p) {
      try {
        const res = await fetch('download.php?id=' + p.id, { credentials: 'same-origin' });
        if (!res.ok) return;
        blobCache.set(p.id, await res.blob());
      } catch (e) { /* тихо */ }
    }));
  }

  function render() {
    if (pages.length === 0) {
      grid.innerHTML = '<div style="grid-column:1/-1; text-align:center; color:#888; padding:40px;">Нет фото. Добавьте PDF-файл ниже.</div>';
    } else {
      grid.innerHTML = '';
      pages.forEach(function(p, idx) {
        const card = document.createElement('div');
        card.draggable = true;
        card.dataset.idx = idx;
        card.style.cssText = 'background:#fff; border:1.5px solid #e5e7eb; border-radius:12px; padding:10px; position:relative; cursor:grab;';

        let previewHtml = '';
        if (p.kind === 'img') {
          const rot = p.rotation || 0;
          previewHtml = '<img src="' + p.path + '" data-idx="' + idx + '" ' +
            'style="width:100%; height:200px; object-fit:cover; border-radius:8px; display:block; cursor:zoom-in; transform:rotate(' + rot + 'deg);" ' +
            'loading="lazy" onclick="viewerOpen(' + idx + ')">';
        } else {
          previewHtml = '<div style="width:100%; height:200px; display:flex; align-items:center; justify-content:center; background:#f1f5f9; border-radius:8px; font-size:56px;">📎</div>';
        }

        card.innerHTML = previewHtml +
          '<div style="font-size:13px; color:#555; margin-top:8px; line-height:1.35; font-weight:600;">' +
            (idx + 1) + '. ' + escapeHtml(p.label || '') +
            (p.rotation ? ' <span style="color:#7c3aed;">(' + p.rotation + '°)</span>' : '') +
          '</div>' +
          '<div style="display:flex; gap:6px; margin-top:8px;">' +
            (p.kind === 'img' ? '<button type="button" class="btn btn-small btn-secondary" style="flex:1; padding:6px;" onclick="rotateCard(' + idx + ')">↻</button>' : '') +
            '<button type="button" class="btn btn-small btn-secondary" style="flex:1; padding:6px;" onclick="viewerOpen(' + idx + ')">⤢</button>' +
            '<button type="button" class="btn btn-small btn-red" style="flex:1; padding:6px;" onclick="removeCard(' + idx + ')">✕</button>' +
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
        appendedPdfs.splice(i, 1);
        // удаляем и соответствующую страницу
        pages = pages.filter(function(p) { return !(p.kind === 'pdf' && p.file === appendedPdfs[i]); });
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

  // === Просмотрщик фото ===
  const viewer       = document.getElementById('photoViewer');
  const viewerImg    = document.getElementById('photoViewerImg');
  const viewerTitle  = document.getElementById('photoViewerTitle');
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
    viewer.classList.add('is-open');
  };
  window.viewerClose = function() { viewer.classList.remove('is-open'); };
  window.viewerRotate = function() { viewerRotation = (viewerRotation + 90) % 360; applyViewerTransform(); };
  window.viewerReset  = function() { viewerRotation = 0; viewerZoom = 1; applyViewerTransform(); };
  function applyViewerTransform() {
    viewerImg.style.transform = 'rotate(' + viewerRotation + 'deg) scale(' + viewerZoom + ')';
  }
  // Зум колесом
  viewer.addEventListener('wheel', function(e) {
    if (!viewer.classList.contains('is-open')) return;
    e.preventDefault();
    viewerZoom += (e.deltaY < 0 ? 0.1 : -0.1);
    viewerZoom = Math.max(0.3, Math.min(4, viewerZoom));
    applyViewerTransform();
  }, { passive: false });
  // Сохранить поворот при закрытии
  viewer.addEventListener('click', function(e) {
    if (e.target === viewer) viewerClose();
  });
  // По кнопке «Закрыть» применяем поворот к карточке
  const origViewerClose = window.viewerClose;
  window.viewerClose = function() {
    if (viewerIdx >= 0 && pages[viewerIdx]) {
      pages[viewerIdx].rotation = viewerRotation;
    }
    origViewerClose();
    render();
  };

  // === Сборка PDF ===
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

      // Размер исходника
      let w = img.width, h = img.height;

      // Если поворот 90 или 270 — размеры меняются местами
      const rotated = (rotation % 180) !== 0;

      // Уменьшение по ширине
      let targetW = w, targetH = h;
      if (q.maxW && w > q.maxW) {
        const k = q.maxW / w;
        targetW = Math.round(w * k);
        targetH = Math.round(h * k);
      }

      // Итоговый canvas уже с учётом поворота
      const canvas = document.createElement('canvas');
      canvas.width  = rotated ? targetH : targetW;
      canvas.height = rotated ? targetW : targetH;
      const ctx = canvas.getContext('2d');

      // Заливаем белым (на случай прозрачности)
      ctx.fillStyle = '#fff';
      ctx.fillRect(0, 0, canvas.width, canvas.height);

      // Поворот вокруг центра
      ctx.save();
      ctx.translate(canvas.width / 2, canvas.height / 2);
      ctx.rotate(rotation * Math.PI / 180);
      ctx.drawImage(img, -targetW / 2, -targetH / 2, targetW, targetH);
      ctx.restore();

      // Возвращаем JPEG (или PNG-оригинал, если просили «Оригинал» без уменьшения)
      if (qualityKey === 'original' && w <= 2400 && blob.type === 'image/jpeg') {
        return { blob: blob, width: w, height: h, original: true };
      }
      const outBlob = await new Promise(function(res) {
        canvas.toBlob(res, 'image/jpeg', q.q || 0.9);
      });
      return { blob: outBlob, width: canvas.width, height: canvas.height };
    } finally {
      URL.revokeObjectURL(url);
    }
  }

  window.buildPdf = async function() {
    if (pages.length === 0) { statusEl.textContent = '⚠️ Нет страниц'; return; }
    statusEl.textContent = '⏳ Собираю PDF...';
    try {
      const { PDFDocument, degrees } = PDFLib;
      const out = await PDFDocument.create();
      const qualityKey = document.getElementById('pdfQuality').value;
      const A4_W = 595.28, A4_H = 841.89;

      for (let i = 0; i < pages.length; i++) {
        const p = pages[i];
        statusEl.textContent = '⏳ Страница ' + (i + 1) + ' из ' + pages.length + '...';

        if (p.kind === 'img') {
          const raw = await getBlobForPdf(p);
          const out2 = await compressImage(raw, p.rotation || 0, qualityKey);
          const buf  = await out2.blob.arrayBuffer();

          // pdf-lib: пробуем JPEG, если не получится — PNG
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
      a.download = (window.IPG_KEY_LABEL === 'РА' ? 'RA_' : 'VIN_') + window.IPG_KEY_VALUE + '.pdf';
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
