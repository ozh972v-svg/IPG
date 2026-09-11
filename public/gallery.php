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

// Фильтры
$filterKey = trim($_GET['key'] ?? '');
$filterType = trim($_GET['type'] ?? '');

$where = [];
$params = [];
if ($filterKey) { $where[] = '(key_value ILIKE :key OR comment ILIKE :key)'; $params[':key'] = "%$filterKey%"; }
if ($filterType) { $where[] = 'photo_type = :type'; $params[':type'] = $filterType; }
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$pdo = get_db();
$stmt = $pdo->prepare("
    SELECT p.*, u.name AS user_name, u.email AS user_email
    FROM photos p
    JOIN users u ON u.id = p.user_id
    $whereSql
    ORDER BY p.key_value ASC, p.created_at DESC
    LIMIT 500
");
$stmt->execute($params);
$photos = $stmt->fetchAll();

// Группируем по ключу (РА или VIN)
$groups = [];
foreach ($photos as $p) {
    $key = $p['key_type'] . '::' . $p['key_value'];
    if (!isset($groups[$key])) {
        $groups[$key] = [
            'key_type' => $p['key_type'],
            'key_value' => $p['key_value'],
            'photos' => []
        ];
    }
    $groups[$key]['photos'][] = $p;
}

$totalPhotos = count($photos);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Фото по РА — общая база</title>
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
  .btn-red { background: #dc2626; }
  .btn-small { padding: 8px 14px; font-size: 14px; }
  .btn-xs { padding: 5px 10px; font-size: 12px; border-radius: 8px; }
  .btn-row { display: flex; gap: 10px; flex-wrap: wrap; }
  .form-row { margin-bottom: 14px; }
  .form-row label { display: block; font-size: 13px; font-weight: 600; color: #666; margin-bottom: 6px; }
  .form-row input, .form-row select, .form-row textarea { width: 100%; padding: 10px 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 15px; font-family: inherit; }
  .form-row input:focus, .form-row select:focus, .form-row textarea:focus { outline: none; border-color: #2563eb; }
  .form-row textarea { resize: vertical; min-height: 60px; }
  .type-selector { display: grid; grid-template-columns: repeat(auto-fill, minmax(180px, 1fr)); gap: 8px; margin-bottom: 12px; }
  .type-option { padding: 10px 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 13px; cursor: pointer; background: #fff; text-align: center; transition: all 0.15s; }
  .type-option:hover { border-color: #2563eb; }
  .type-option input { display: none; }
  .type-option.selected { border-color: #2563eb; background: #eff6ff; color: #2563eb; font-weight: 600; }
  .group-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; padding: 14px 16px; background: #eff6ff; border-radius: 10px; margin-bottom: 12px; }
  .group-title { font-size: 16px; font-weight: 700; color: #1e3a8a; }
  .group-title small { display: block; font-size: 12px; font-weight: 400; color: #666; margin-top: 2px; }
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
  .search-bar { display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px; }
  .search-bar input, .search-bar select { padding: 10px 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 14px; }
  .search-bar input { flex: 1; min-width: 200px; }
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
      <h1>📸 Фото по РА — общая база</h1>
      <div>
        <span class="user-info">👤 <b><?= e($user['name'] ?: $user['email']) ?></b></span>
        <a href="logout.php" class="logout">Выйти</a>
      </div>
    </div>
    <p class="subtitle">Загружайте фото дефектов — их видят все зарегистрированные</p>
    <div class="btn-row">
      <a href="index.html" class="btn btn-secondary btn-small">← На рабочее место</a>
      <a href="#upload" class="btn btn-green btn-small">📷 Загрузить фото</a>
      <?php if ($totalPhotos > 0): ?>
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

  <div class="card" id="upload">
    <h2>Загрузить фото</h2>
    <form action="upload.php" method="post" enctype="multipart/form-data">
      <div class="form-row">
        <label>Привязка к</label>
        <select name="key_type" id="keyType" onchange="updateKeyPlaceholder()">
          <option value="ra">РА (номер рекламационного акта)</option>
          <option value="vin">VIN / Номер шасси</option>
        </select>
      </div>
      <div class="form-row">
        <label id="keyLabel">Номер РА</label>
        <input type="text" name="key_value" id="keyValue" required placeholder="Например: 12345">
      </div>
      <div class="form-row">
        <label>Тип фото</label>
          <div class="form-row">
            <label>Тип фото — тапни, чтобы снять</label>
            <div class="type-selector">
              <?php foreach ($PHOTO_TYPES as $id => $name): ?>
                <form action="upload.php" method="post" enctype="multipart/form-data" style="display:contents;">
                  <input type="hidden" name="key_type" value="<?= e($keyType) ?>">
                  <input type="hidden" name="key_value" id="keyHidden" value="">
                  <input type="hidden" name="photo_type" value="<?= e($id) ?>">
                  <input type="hidden" name="comment" id="commentHidden" value="">
                  <label class="type-option" data-type="<?= e($id) ?>">
                    <input type="file" name="photo" accept="image/*" capture="environment" onchange="submitTypePhoto(this)" required>
                    <?= e($name) ?>
                  </label>
                </form>
              <?php endforeach; ?>
            </div>
          </div>
          <div class="form-row">
            <label>Комментарий (необязательно)</label>
            <textarea id="commentInput" placeholder="Например: течь ОЖ в районе патрубка"></textarea>
          </div>
  </div>

  <div class="card">
    <h2>Все фото (<?= $totalPhotos ?>)</h2>
    <form method="get" class="search-bar">
      <input type="text" name="key" placeholder="Поиск по номеру РА, VIN или комментарию" value="<?= e($filterKey) ?>">
      <select name="type">
        <option value="">Все типы</option>
        <?php foreach ($PHOTO_TYPES as $id => $name): ?>
          <option value="<?= e($id) ?>" <?= $filterType === $id ? 'selected' : '' ?>><?= e($name) ?></option>
        <?php endforeach; ?>
      </select>
      <button type="submit" class="btn btn-secondary btn-small">Найти</button>
      <?php if ($filterKey || $filterType): ?>
        <a href="index.php" class="btn btn-secondary btn-small">Сбросить</a>
      <?php endif; ?>
    </form>

    <?php if (empty($groups)): ?>
      <div class="empty-state">
        <div class="big">📷</div>
        <div>Пока нет фото</div>
        <div style="margin-top:6px;">Загрузите первое фото через форму выше</div>
      </div>
    <?php else: ?>
      <?php foreach ($groups as $g): ?>
        <div style="margin-bottom:24px;">
          <div class="group-header">
            <div class="group-title">
              <?= e($g['key_type'] === 'ra' ? 'РА' : 'VIN') ?>: <?= e($g['key_value']) ?>
              <small>Фото: <?= count($g['photos']) ?></small>
            </div>
            <a href="download.php?key_type=<?= e($g['key_type']) ?>&key=<?= urlencode($g['key_value']) ?>" class="btn btn-small">📥 Скачать ZIP</a>
          </div>
          <div class="photo-grid">
            <?php foreach ($g['photos'] as $p): ?>
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
        </div>
      <?php endforeach; ?>
    <?php endif; ?>
  </div>

</div>
<script>
function updateKeyPlaceholder() {
  const t = document.getElementById('keyType').value;
  document.getElementById('keyLabel').textContent = t === 'ra' ? 'Номер РА' : 'VIN / Номер шасси';
  document.getElementById('keyValue').placeholder = t === 'ra' ? 'Например: 12345' : 'Например: XTC65115...';
}

function submitTypePhoto(input) {
  // Перед отправкой формы копируем значения key_value и comment в скрытые поля
  const keyValue = document.getElementById('keyValue').value.trim();
  const comment = document.getElementById('commentInput').value.trim();

  if (!keyValue) {
    alert('Сначала укажите номер РА или VIN');
    input.value = '';
    return false;
  }

  const form = input.closest('form');
  form.querySelector('#keyHidden').value = keyValue;
  form.querySelector('#commentHidden').value = comment;
  form.submit();
}
</script>
