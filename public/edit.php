<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    header('Location: index.php');
    exit;
}

$pdo = get_db();
$stmt = $pdo->prepare('SELECT * FROM photos WHERE id = :id');
$stmt->execute([':id' => $id]);
$photo = $stmt->fetch();

if (!$photo || (int)$photo['user_id'] !== (int)$user['id']) {
    header('Location: index.php?error=' . urlencode('Фото не найдено или нет прав'));
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $keyValue = trim($_POST['key_value'] ?? '');
    $photoType = $_POST['photo_type'] ?? '';
    $comment = trim($_POST['comment'] ?? '');

    if ($keyValue === '' || !isset($PHOTO_TYPES[$photoType])) {
        $error = 'Заполните все поля';
    } else {
        $stmt = $pdo->prepare('UPDATE photos SET key_value = :kv, photo_type = :pt, comment = :c WHERE id = :id');
        $stmt->execute([':kv' => $keyValue, ':pt' => $photoType, ':c' => $comment ?: null, ':id' => $id]);
        header('Location: index.php?edited=1');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Редактирование фото</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; margin: 0; padding: 20px; line-height: 1.5; }
  .card { max-width: 600px; margin: 20px auto; background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
  h1 { font-size: 20px; margin: 0 0 16px; }
  img { width: 100%; border-radius: 10px; margin-bottom: 16px; }
  label { display: block; font-size: 13px; font-weight: 600; color: #666; margin: 12px 0 6px; }
  input, select, textarea { width: 100%; padding: 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 15px; box-sizing: border-box; font-family: inherit; }
  textarea { min-height: 60px; resize: vertical; }
  input:focus, select:focus, textarea:focus { outline: none; border-color: #2563eb; }
  .btn-row { display: flex; gap: 10px; margin-top: 16px; }
  button, a.btn { flex: 1; padding: 14px; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; text-align: center; text-decoration: none; }
  button { background: #2563eb; color: #fff; }
  a.btn { background: #fff; color: #2563eb; border: 1.5px solid #2563eb; }
  .error { background: #fef2f2; color: #dc2626; padding: 12px; border-radius: 8px; font-size: 14px; margin-bottom: 12px; }
</style>
</head>
<body>
<div class="card">
  <h1>Редактирование фото</h1>
  <?php if (!empty($error)): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
  <img src="<?= e($photo['file_path']) ?>" alt="Фото">
  <form method="post">
    <input type="hidden" name="id" value="<?= (int)$photo['id'] ?>">
    <label>Номер РА / VIN</label>
    <input type="text" name="key_value" value="<?= e($photo['key_value']) ?>" required>
    <label>Тип фото</label>
    <select name="photo_type">
      <?php foreach ($PHOTO_TYPES as $id2 => $name): ?>
        <option value="<?= e($id2) ?>" <?= $photo['photo_type'] === $id2 ? 'selected' : '' ?>><?= e($name) ?></option>
      <?php endforeach; ?>
    </select>
    <label>Комментарий</label>
    <textarea name="comment"><?= e($photo['comment'] ?? '') ?></textarea>
    <div class="btn-row">
      <a href="index.php" class="btn">Отмена</a>
      <button type="submit">Сохранить</button>
    </div>
  </form>
</div>
</body>
</html>
