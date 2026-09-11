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

// === Получить список всех РА/VIN (из таблицы keys + photos) ===
if ($searchQuery !== '') {
    $stmt = $pdo->prepare("
        SELECT key_type, key_value FROM keys WHERE key_value ILIKE :q
        UNION
        SELECT DISTINCT key_type, key_value FROM photos WHERE key_value ILIKE :q
        ORDER BY key_value DESC
    ");
    $stmt->execute([':q' => '%' . $searchQuery . '%']);
    $allKeys = $stmt->fetchAll();
} else {
    $stmt = $pdo->query("
        SELECT key_type, key_value FROM keys
        UNION
        SELECT DISTINCT key_type, key_value FROM photos
        ORDER BY key_value DESC
    ");
    $allKeys = $stmt->fetchAll();
}

// Количество фото и последняя дата для каждого ключа
$stmt = $pdo->query("
    SELECT key_type, key_value, COUNT(*) AS cnt, MAX(created_at) AS last_date
    FROM photos
    GROUP BY key_type, key_value
");
$photoCounts = [];
foreach ($stmt->fetchAll() as $row) {
    $k = $row['key_type'] . '::' . $row['key_value'];
    $photoCounts[$k] = $row;
}

$groups = [];
foreach ($allKeys as $k) {
    $key = $k['key_type'] . '::' . $k['key_value'];
    $count = $photoCounts[$key]['cnt'] ?? 0;
    $lastDate = $photoCounts[$key]['last_date'] ?? null;
    $groups[] = [
        'key_type' => $k['key_type'],
        'key_value' => $k['key_value'],
        'count' => $count,
        'last_date' => $lastDate
    ];
}

// Общее число фото и размер
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

  .empty-state { text-align: center; padding: 40px 20px; color: #888; font-size: 14px; }
  .empty-state .big { font-size: 48px; margin-bottom: 12px; }
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
    <p class="subtitle">Всего фото: <?= $allPhotosCount ?> · Размер: <?= formatSize($allPhotosSize) ?></p>
    <div class="btn-row">
      <a href="index.html" class="btn btn-secondary btn-small">← На рабочее место</a>
      <a href="download.php?all=1" class="btn btn-small">📥 Скачать всё (ZIP)</a>
    </div>
  </div>

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
        <div style="margin-top:6px;">Добавьте первый через форму выше</div>
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

</div>
</body>
</html>
