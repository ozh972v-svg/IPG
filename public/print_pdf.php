<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$pdo = get_db();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$keyType = trim($_GET['key_type'] ?? '');
$keyValue = trim($_GET['key_value'] ?? '');

if ($id > 0) {
    $stmt = $pdo->prepare("SELECT file_path FROM photos WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $photos = $stmt->fetchAll();
    $backUrl = 'gallery.php';
} elseif ($keyType && $keyValue) {
    $stmt = $pdo->prepare("SELECT file_path FROM photos WHERE key_type = :kt AND key_value = :kv ORDER BY created_at ASC");
    $stmt->execute([':kt' => $keyType, ':kv' => $keyValue]);
    $photos = $stmt->fetchAll();
    $backUrl = 'gallery.php?key_type=' . urlencode($keyType) . '&key_value=' . urlencode($keyValue);
} else {
    header('Location: gallery.php');
    exit;
}

if (empty($photos)) {
    header('Location: gallery.php?error=' . urlencode('Нет фото'));
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Фото — PDF</title>
<style>
  @page { size: A4; margin: 8mm; }
  * { box-sizing: border-box; }
  body { font-family: Arial, sans-serif; margin: 0; padding: 0; background: #f0f2f5; }

  .toolbar {
    position: fixed; top: 0; left: 0; right: 0;
    background: #fff; padding: 10px 16px;
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

  .pages { padding-top: 70px; }

  .page {
    page-break-after: always;
    background: #fff;
    max-width: 210mm;
    margin: 0 auto 16px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.1);
    display: flex;
    align-items: center;
    justify-content: center;
    min-height: 280mm;
    padding: 8mm;
  }
  .page:last-child { page-break-after: auto; }

  .photo {
    max-width: 100%;
    max-height: 100%;
    object-fit: contain;
    display: block;
  }

  @media print {
    body { background: #fff; }
    .toolbar { display: none !important; }
    .pages { padding-top: 0; }
    .page {
      box-shadow: none;
      margin: 0;
      padding: 0;
      max-width: none;
      min-height: 0;
      height: 100vh;
      page-break-after: always;
    }
    .page:last-child { page-break-after: auto; }
  }
</style>
</head>
<body>

<div class="toolbar">
  <a href="<?= e($backUrl) ?>" class="btn-back">← Назад</a>
  <button class="btn-print" onclick="window.print()">🖨️ Сохранить PDF</button>
</div>

<div class="pages">
  <?php foreach ($photos as $p): ?>
    <div class="page">
      <img class="photo" src="<?= e($p['file_path']) ?>" alt="">
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
