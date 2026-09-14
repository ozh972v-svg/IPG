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

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$keyType = trim($_GET['key_type'] ?? '');
$keyValue = trim($_GET['key_value'] ?? '');

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT p.*, u.name AS user_name FROM photos p JOIN users u ON u.id = p.user_id WHERE p.id = :id");
    $stmt->execute([':id' => $id]);
    $photos = $stmt->fetchAll();
    $title = 'Фото #' . $id;
    $backUrl = 'gallery.php';
} elseif ($keyType && $keyValue) {
    $stmt = $pdo->prepare("SELECT p.*, u.name AS user_name FROM photos p JOIN users u ON u.id = p.user_id WHERE p.key_type = :kt AND p.key_value = :kv ORDER BY p.created_at ASC");
    $stmt->execute([':kt' => $keyType, ':kv' => $keyValue]);
    $photos = $stmt->fetchAll();
    $title = ($keyType === 'ra' ? 'РА' : 'VIN') . ': ' . $keyValue;
    $backUrl = 'gallery.php?key_type=' . urlencode($keyType) . '&key_value=' . urlencode($keyValue);
} else {
    header('Location: gallery.php');
    exit;
}

if (empty($photos)) {
    header('Location: gallery.php?error=' . urlencode('Нет фото для печати'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= e($title) ?> — PDF</title>
<style>
  @page { size: A4; margin: 10mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f0f2f5; }
  .toolbar {
    position: fixed; top: 0; left: 0; right: 0;
    background: #fff; padding: 12px 16px;
    box-shadow: 0 2px 8px rgba(0,0,0,0.1);
    display: flex; gap: 10px; justify-content: center;
    z-index: 1000;
  }
  .toolbar a, .toolbar button {
    padding: 10px 18px; border: none; border-radius: 10px;
    font-size: 15px; font-weight: 600; cursor: pointer;
    text-decoration: none; text-align: center;
  }
  .btn-back { background: #fff; color: #2563eb; border: 1.5px solid #2563eb; }
  .btn-print { background: #2563eb; color: #fff; }
  .pages { padding-top: 80px; }
  .page {
    page-break-after: always;
    padding: 10mm;
    background: #fff;
    max-width: 210mm;
    margin: 0 auto 20px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.1);
  }
  .page:last-child { page-break-after: auto; }
  .header { border-bottom: 2px solid #2563eb; padding-bottom: 8px; margin-bottom: 14px; }
  .header h1 { font-size: 16pt; margin: 0 0 4px; color: #1e3a8a; }
  .header .sub { font-size: 10pt; color: #666; }
  .photo-wrap { text-align: center; margin-bottom: 14px; }
  .photo { max-width: 100%; max-height: 190mm; object-fit: contain; display: block; margin: 0 auto; border: 1px solid #e5e7eb; }
  .info { font-size: 10pt; }
  .info-row { margin-bottom: 5px; }
  .info-row b { color: #333; }
  @media print {
    body { background: #fff; }
    .toolbar { display: none !important; }
    .pages { padding-top: 0; }
    .page { box-shadow: none; margin: 0; padding: 0; max-width: none; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <a href="<?= e($backUrl) ?>" class="btn-back">← Назад</a>
  <button class="btn-print" onclick="window.print()">🖨️ Сохранить PDF</button>
</div>

<div class="pages">
  <?php foreach ($photos as $i => $p): ?>
    <div class="page">
      <div class="header">
        <h1><?= e($title) ?></h1>
        <div class="sub">Фото <?= $i + 1 ?> из <?= count($photos) ?> · <?= e(date('d.m.Y H:i', strtotime($p['created_at']))) ?></div>
      </div>
      <div class="photo-wrap">
        <img class="photo" src="<?= e($p['file_path']) ?>" alt="">
      </div>
      <div class="info">
        <div class="info-row"><b>Тип фото:</b> <?= e($PHOTO_TYPES[$p['photo_type']] ?? $p['photo_type']) ?></div>
        <div class="info-row"><b><?= $p['key_type'] === 'ra' ? 'РА' : 'VIN' ?>:</b> <?= e($p['key_value']) ?></div>
        <?php if ($p['comment']): ?>
          <div class="info-row"><b>Комментарий:</b> <?= e($p['comment']) ?></div>
        <?php endif; ?>
        <div class="info-row"><b>Загрузил:</b> <?= e($p['user_name'] ?: 'Пользователь') ?></div>
      </div>
    </div>
  <?php endforeach; ?>
</div>

<script>
  window.addEventListener('load', function() {
    let loaded = 0;
    const imgs = document.querySelectorAll('.photo');
    const total = imgs.length;

    function tryPrint() {
      if (loaded >= total) {
        setTimeout(function() { window.print(); }, 500);
      }
    }

    if (total === 0) {
      setTimeout(function() { window.print(); }, 500);
      return;
    }

    imgs.forEach(function(img) {
      if (img.complete) { loaded++; tryPrint(); }
      else {
        img.addEventListener('load', function() { loaded++; tryPrint(); });
        img.addEventListener('error', function() { loaded++; tryPrint(); });
      }
    });

    setTimeout(function() { window.print(); }, 3000);
  });
</script>
</body>
</html>
