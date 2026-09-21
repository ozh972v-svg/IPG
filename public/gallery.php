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

<script>
<?php if ($viewMode): ?>
(function() {
  const KEY_TYPE  = <?= json_encode($viewKeyType) ?>;
  const KEY_VALUE = <?= json_encode($viewKeyValue) ?>;

  let selectedPhotoType = null;
  let lastUploadedFile  = null;
  let lastSource        = null;   // 'camera' | 'gallery'

  const photoCamera  = document.getElementById('photoCamera');
  const photoGallery = document.getElementById('photoGallery');
  const videoCamera  = document.getElementById('videoCamera');
  const videoGallery = document.getElementById('videoGallery');

  const sourceModal = document.getElementById('sourceModal');
  const saveModal   = document.getElementById('saveModal');
  const sourceTitle = document.getElementById('sourceModalTitle');

  // === Тап по плитке ===
  document.querySelectorAll('.type-option').forEach(function(el) {
    el.addEventListener('click', function() {
      selectedPhotoType = el.dataset.type;
      document.querySelectorAll('.type-option').forEach(function(x) { x.classList.remove('selected'); });
      el.classList.add('selected');

      const isVideo = selectedPhotoType === 'video_defect';
      sourceTitle.textContent = isVideo
        ? 'Записать видео дефекта — как?'
        : 'Загрузить: «' + el.textContent.trim() + '» — откуда?';
      sourceModal.classList.add('is-open');
    });
  });

  window.closeSourceModal = function() {
    sourceModal.classList.remove('is-open');
  };

  window.chooseSource = function(source) {
    sourceModal.classList.remove('is-open');
    lastSource = source;                 // запоминаем источник
    const isVideo = selectedPhotoType === 'video_defect';
    let input;
    if (isVideo) input = (source === 'camera') ? videoCamera  : videoGallery;
    else         input = (source === 'camera') ? photoCamera  : photoGallery;
    input.click();
  };

  // === Обработка выбранного файла ===
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
    // Если у файла нет имени (бывает на iPhone с HEIC) — подставляем
    formData.append('photo', file, file.name || ('upload_' + Date.now() + '.jpg'));

    const status = document.createElement('div');
    status.className = 'upload-status';
    status.style.background = '#2563eb';
    status.textContent = '📤 Загрузка...';
    document.body.appendChild(status);

    fetch('upload.php', { method: 'POST', body: formData, credentials: 'same-origin' })
      .then(function(r) {
        return r.text().then(function(text) {
          return { ok: r.ok, status: r.status, text: text || '' };
        });
      })
      .then(function(res) {
        // Пытаемся вытащить ошибку из ответа сервера
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

        status.style.background = '#16a34a';
        status.textContent = '✅ Загружено';
        setTimeout(function() {
          if (document.body.contains(status)) document.body.removeChild(status);
        }, 500);

        // «Сохранить в телефон» — ТОЛЬКО если снимали на камеру
        if (lastSource === 'camera' && navigator.share) {
          saveModal.classList.add('is-open');
        } else {
          finishUpload();
        }
      })
      .catch(function(err) {
        // Показываем реальную ошибку 5 секунд
        status.style.background = '#dc2626';
        status.textContent = '❌ ' + (err.message || 'ошибка загрузки');
        console.error('upload error:', err);
        setTimeout(function() {
          if (document.body.contains(status)) document.body.removeChild(status);
        }, 5000);
      });
  }

  // === Сохранение в галерею телефона (только после съёмки) ===
  window.saveToPhone = async function() {
    const file = lastUploadedFile;
    if (!file) { finishUpload(); return; }

    if (navigator.share && navigator.canShare) {
      try {
        if (navigator.canShare({ files: [file] })) {
          await navigator.share({
            files: [file],
            title: 'Файл по ' + (KEY_TYPE === 'ra' ? 'РА' : 'VIN') + ' ' + KEY_VALUE,
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
    saveModal.classList.remove('is-open');
    window.location.href = 'gallery.php?key_type=' + encodeURIComponent(KEY_TYPE)
      + '&key_value=' + encodeURIComponent(KEY_VALUE) + '&uploaded=1';
  };
})();
<?php endif; ?>
</script>
</body>
</html>
