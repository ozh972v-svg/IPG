<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$PHOTO_TYPES = [
    'general' => 'Общий вид автотехники',
    'vin' => 'VIN / Номер шасси',
    'odometer' => 'Одометр / Моточасы',
    'before_dismount' => 'Дефект до демонтажа',
    'after_dismount' => 'Дефект после демонтажа',
    'marking' => 'Маркировка изделия',
    'manifestation' => 'Проявление дефекта',
    'numbered_unit' => 'Номерной агрегат'
];

$pdo = get_db();

$searchQuery = trim($_GET['q'] ?? '');

$viewKeyType = trim($_GET['key_type'] ?? '');
$viewKeyValue = trim($_GET['key_value'] ?? '');
$viewMode = $viewKeyType && $viewKeyValue;

// === Данные для главного экрана ===
if (!$viewMode) {
    if ($searchQuery !== '') {
        $stmt = $pdo->prepare("
            SELECT key_type, key_value FROM keys WHERE key_value ILIKE :q
            UNION
            SELECT DISTINCT key_type, key_value FROM photos WHERE key_value ILIKE :q
            ORDER BY key_value DESC
        ");
        $stmt->execute([':q' => '%' . $searchQuery . '%']);
    } else {
        $stmt = $pdo->query("
            SELECT key_type, key_value FROM keys
            UNION
            SELECT DISTINCT key_type, key_value FROM photos
            ORDER BY key_value DESC
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

    $groups = [];
    foreach ($allKeys as $k) {
        $key = $k['key_type'] . '::' . $k['key_value'];
        $row = $photoCounts[$key] ?? null;
        $groups[] = [
            'key_type' => $k['key_type'],
            'key_value' => $k['key_value'],
            'count' => $row ? (int)$row['cnt'] : 0,
            'last_date' => $row['last_date'] ?? null
        ];
    }
}

// === Данные для экрана внутри РА ===
$photos = [];
$totalPhotos = 0;
if ($viewMode) {
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
}

$stmt = $pdo->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(file_size),0) AS total_size FROM photos");
$summary = $stmt->fetch();
$allPhotosCount = (int)$summary['cnt'];
$allPhotosSize = (int)$summary['total_size'];

function formatSize($bytes) {
    if ($bytes < 1024) return $bytes . ' Б';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' КБ';
    return round($bytes / 1048576, 1) . ' МБ';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $viewMode ? e(($viewKeyType === 'ra' ? 'РА' : 'VIN') . ': ' . $viewKeyValue) : 'Фото по РА — общая база' ?></title>
<style>
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; margin: 0; padding: 16px; color: #1a1a1a; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; }
  .card { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 16px; }
  h1 { font-size: 22px; margin: 0 0 8px; }
  h2 { font-size: 18px; margin: 0 0 16px; }
  .subtitle { color: #666; font-size: 14px; margin: 0 0 16px; }
  .top-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
  .user-info { font-size: 13px; color: #666; }
  .user-info b { color: #2563eb; }
  .logout { color: #dc2626; text-decoration: none; font-size: 13px; margin-left: 12px; }
  .btn { display: inline-block; padding: 12px 18px; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; background: #2563eb; color: #fff; text-decoration: none; text-align: center; transition: opacity 0.2s; }
  .btn:hover { opacity: 0.9; }
  .btn-secondary { background: #fff; color: #2563eb; border: 1.5px solid #2563eb; }
  .btn-green { background: #16a34a; }
  .btn-small { padding: 8px 14px; font-size: 14px; }
  .btn-row { display: flex; gap: 10px; flex-wrap: wrap; }
  .form-row { margin-bottom: 14px; }
  .form-row label { display: block; font-size: 13px; font-weight: 600; color: #666; margin-bottom: 6px; }
  .form-row input, .form-row select, .form-row textarea { width: 100%; padding: 10px 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 15px; font-family: inherit; }
  .form-row input:focus, .form-row select:focus, .form-row textarea:focus { outline: none; border-color: #2563eb; }
  .form-row textarea { resize: vertical; min-height: 60px; }

  .group-item { display: flex; justify-content: space-between; align-items: center; padding: 16px; border: 1.5px solid #e5e7eb; border-radius: 12px; margin-bottom: 10px; background: #fff; text-decoration: none; color: inherit; transition: all 0.15s; }
  .group-item:hover { border-color: #2563eb; background: #f8faff; }
  .group-item-title { font-weight: 700; font-size: 16px; color: #1e3a8a; }
  .group-item-sub { font-size: 12px; color: #888; margin-top: 4px; }
  .group-item-count { font-size: 13px; color: #2563eb; background: #eff6ff; padding: 4px 12px; border-radius: 12px; font-weight: 600; }

  .search-bar { display: flex; gap: 8px; flex-wrap: wrap; }
  .search-bar input { flex: 1; min-width: 200px; padding: 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 15px; }
  .search-bar input:focus { outline: none; border-color: #2563eb; }

  .type-selector { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; margin-bottom: 12px; }
  .type-option { padding: 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 13px; cursor: pointer; background: #fff; text-align: center; transition: all 0.15s; user-select: none; }
  .type-option:hover { border-color: #2563eb; }
  .type-option.selected { border-color: #2563eb; background: #eff6ff; color: #2563eb; font-weight: 600; }

  .photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 12px; }
  .photo-card { background: #f9fafb; border-radius: 10px; overflow: hidden; border: 1.5px solid #e5e7eb; position: relative; }
  .photo-card img { width: 100%; height: 160px; object-fit: cover; display: block; background: #e5e7eb; cursor: pointer; }
  .photo-meta { padding: 10px; font-size: 11px; color: #666; }
  .photo-type { display: inline-block; padding: 2px 8px; border-radius: 6px; background: #eff6ff; color: #2563eb; font-weight: 600; font-size: 11px; margin-bottom: 4px; }
  .photo-user { font-size: 11px; color: #888; margin-top: 4px; }
  .photo-actions { display: flex; gap: 4px; margin-top: 8px; }
  .photo-actions a { flex: 1; padding: 5px 8px; border-radius: 6px; font-size: 11px; text-align: center; text-decoration: none; font-weight: 600; }
  .action-edit { background: #eff6ff; color: #2563eb; }
  .action-del { background: #fef2f2; color: #dc2626; }
  .action-dl { background: #f0fdf4; color: #16a34a; }

  .empty-state { text-align: center; padding: 40px 20px; color: #888; font-size: 14px; }
  .empty-state .big { font-size: 48px; margin-bottom: 12px; }

  .alert { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
  .alert-success { background: #f0fdf4; color: #16a34a; border-left: 4px solid #16a34a; }
  .alert-error { background: #fef2f2; color: #dc2626; border-left: 4px solid #dc2626; }

  @media (max-width: 600px) {
    .photo-grid { grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 8px; }
    .photo-card img { height: 120px; }
  }
</style>
</head>
<body>
<div class="container">

  <div class="card">
    <div class="top-bar">
      <h1>📸 <?= $viewMode ? e(($viewKeyType === 'ra' ? 'РА' : 'VIN') . ': ' . $viewKeyValue) : 'Фото по РА — общая база' ?></h1>
      <div>
        <span class="user-info">👤 <b><?= e($user['name'] ?: $user['email']) ?></b></span>
        <a href="logout.php" class="logout">Выйти</a>
      </div>
    </div>
    <?php if (!$viewMode): ?>
      <p class="subtitle">Всего фото: <?= $allPhotosCount ?> · Размер: <?= formatSize($allPhotosSize) ?></p>
    <?php endif; ?>
    <div class="btn-row">
      <?php if ($viewMode): ?>
        <a href="gallery.php" class="btn btn-secondary btn-small">← Ко всем РА</a>
        <a href="download.php?key_type=<?= e($viewKeyType) ?>&key=<?= urlencode($viewKeyValue) ?>" class="btn btn-small">📥 Скачать ZIP</a>
      <?php else: ?>
        <a href="index.html" class="btn btn-secondary btn-small">← На рабочее место</a>
        <a href="download.php?all=1" class="btn btn-small">📥 Скачать всё (ZIP)</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if (isset($_GET['uploaded'])): ?>
    <div class="alert alert-success">✅ Фото успешно загружено</div>
  <?php endif; ?>
  <?php if (isset($_GET['deleted'])): ?>
    <div class="alert alert-success">🗑️ Фото удалено</div>
  <?php endif; ?>
  <?php if (isset($_GET['edited'])): ?>
    <div class="alert alert-success">✏️ Фото отредактировано</div>
  <?php endif; ?>
  <?php if (isset($_GET['error'])): ?>
    <div class="alert alert-error">❌ Ошибка: <?= e($_GET['error']) ?></div>
  <?php endif; ?>

  <?php if (!$viewMode): ?>
    <!-- ЭКРАН 1: Список РА -->
    <div class="card">
      <h2>🔍 Поиск</h2>
      <form method="get" class="search-bar">
        <input type="text" name="q" placeholder="Поиск по номеру РА или VIN" value="<?= e($searchQuery) ?>">
        <button type="submit" class="btn btn-secondary btn-small">Найти</button>
        <?php if ($searchQuery): ?>
          <a href="gallery.php" class="btn btn-secondary btn-small">Сбросить</a>
        <?php endif; ?>
      </form>
    </div>

    <div class="card">
      <h2>+ Добавить новый РА / VIN</h2>
      <form method="get" action="gallery.php">
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
        <button type="submit" class="btn btn-green">+ Добавить</button>
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
          <a href="gallery.php?key_type=<?= e($g['key_type']) ?>&key_value=<?= urlencode($g['key_value']) ?>" class="group-item">
            <div>
              <div class="group-item-title"><?= e($g['key_type'] === 'ra' ? 'РА' : 'VIN') ?>: <?= e($g['key_value']) ?></div>
              <?php if ($g['last_date']): ?>
                <div class="group-item-sub">Обновлено: <?= e(date('d.m.Y H:i', strtotime($g['last_date']))) ?></div>
              <?php endif; ?>
            </div>
            <div class="group-item-count"><?= (int)$g['count'] ?> 📷</div>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <!-- ЭКРАН 2: Внутри РА -->

    <div class="card">
      <h2>Загрузить фото</h2>
      <div class="form-row">
        <label>Тип фото — тапни, чтобы снять</label>
        <div class="type-selector" id="typeSelector">
          <?php foreach ($PHOTO_TYPES as $id => $name): ?>
            <div class="type-option" data-type="<?= e($id) ?>">
              <?= e($name) ?>
            </div>
          <?php endforeach; ?>
        </div>
        <input type="file" id="hiddenCamera" accept="image/*" capture="environment" style="display:none;">
      </div>
      <div class="form-row">
        <label>Комментарий (необязательно)</label>
        <textarea id="commentInput" placeholder="Например: течь ОЖ в районе патрубка"></textarea>
      </div>
    </div>

    <div class="card">
      <h2>Фото (<?= $totalPhotos ?>)</h2>
      <?php if (empty($photos)): ?>
        <div class="empty-state">
          <div class="big">📷</div>
          <div>Пока нет фото</div>
          <div style="margin-top:6px;">Тапните по типу фото выше</div>
        </div>
      <?php else: ?>
        <div class="photo-grid">
          <?php foreach ($photos as $p): ?>
            <div class="photo-card">
              <a href="<?= e($p['file_path']) ?>" target="_blank">
                <img src="<?= e($p['file_path']) ?>" alt="<?= e($PHOTO_TYPES[$p['photo_type']] ?? $p['photo_type']) ?>" loading="lazy">
              </a>
              <div class="photo-meta">
                <div class="photo-type"><?= e($PHOTO_TYPES[$p['photo_type']] ?? $p['photo_type']) ?></div>
                <?php if ($p['comment']): ?>
                  <div style="margin-top:4px;color:#333;"><?= e($p['comment']) ?></div>
                <?php endif; ?>
                <div class="photo-user">👤 <?= e($p['user_name'] ?: $p['user_email']) ?></div>
                <div style="margin-top:2px;font-size:10px;color:#aaa;"><?= e(date('d.m.Y H:i', strtotime($p['created_at']))) ?></div>
                <div class="photo-actions">
                  <a href="download.php?id=<?= (int)$p['id'] ?>" class="action-dl">📥</a>
                  <?php if ((int)$p['user_id'] === (int)$user['id']): ?>
                    <a href="edit.php?id=<?= (int)$p['id'] ?>" class="action-edit">✏️</a>
                    <a href="#" onclick="if(confirm('Удалить фото?')){document.getElementById('del-<?= (int)$p['id'] ?>').submit();}return false;" class="action-del">🗑️</a>
                    <form id="del-<?= (int)$p['id'] ?>" method="post" action="delete.php" style="display:none;">
                      <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
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
<script>
<?php if ($viewMode): ?>
let selectedPhotoType = null;
const cameraInput = document.getElementById('hiddenCamera');
const KEY_TYPE = <?= json_encode($viewKeyType) ?>;
const KEY_VALUE = <?= json_encode($viewKeyValue) ?>;

document.querySelectorAll('.type-option').forEach(el => {
  el.addEventListener('click', () => {
    selectedPhotoType = el.dataset.type;
    document.querySelectorAll('.type-option').forEach(x => x.classList.remove('selected'));
    el.classList.add('selected');
    cameraInput.click();
  });
});

cameraInput.addEventListener('change', () => {
  const file = cameraInput.files[0];
  if (!file || !selectedPhotoType) return;

  const comment = document.getElementById('commentInput').value.trim();

  const formData = new FormData();
  formData.append('key_type', KEY_TYPE);
  formData.append('key_value', KEY_VALUE);
  formData.append('photo_type', selectedPhotoType);
  formData.append('comment', comment);
  formData.append('photo', file);

  const status = document.createElement('div');
  status.style.cssText = 'position:fixed;top:0;left:0;right:0;padding:14px;background:#2563eb;color:#fff;text-align:center;font-weight:600;z-index:9999;';
  status.textContent = '📤 Загрузка...';
  document.body.appendChild(status);

  fetch('upload.php', { method: 'POST', body: formData })
    .then(r => r.text())
    .then(text => {
      // Проверяем, был ли редирект
      if (text.toLowerCase().includes('location') || r.ok) {
        status.style.background = '#16a34a';
        status.textContent = '✅ Фото загружено';
      } else {
        status.style.background = '#16a34a';
        status.textContent = '✅ Фото загружено';
      }
      setTimeout(() => {
        window.location.href = 'gallery.php?key_type=' + KEY_TYPE + '&key_value=' + encodeURIComponent(KEY_VALUE) + '&uploaded=1';
      }, 800);
    })
    .catch(err => {
      status.style.background = '#dc2626';
      status.textContent = '❌ Ошибка: ' + err.message;
      setTimeout(() => { document.body.removeChild(status); }, 3000);
    });

  cameraInput.value = '';
});
<?php endif; ?>
</script>
</body>
</html>
