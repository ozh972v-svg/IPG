<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$pdo = get_db();
$success = '';
$error = '';

// === Смена профиля ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update_profile') {
    $newName = trim($_POST['name'] ?? '');
    $newLogin = trim($_POST['login'] ?? '');

    if (mb_strlen($newLogin) < 3) {
        $error = 'Логин должен быть не короче 3 символов';
    } else {
        // Проверяем, не занят ли логин другим
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :login AND id != :id');
        $stmt->execute([':login' => $newLogin, ':id' => $user['id']]);
        if ($stmt->fetch()) {
            $error = 'Этот логин уже занят';
        } else {
            $stmt = $pdo->prepare('UPDATE users SET name = :name, email = :login WHERE id = :id');
            $stmt->execute([':name' => $newName, ':login' => $newLogin, ':id' => $user['id']]);
            $success = 'Профиль обновлён';
            $user['name'] = $newName;
            $user['email'] = $newLogin;
        }
    }
}

// === Смена пароля ===
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $old = $_POST['old_password'] ?? '';
    $new1 = $_POST['new_password'] ?? '';
    $new2 = $_POST['new_password2'] ?? '';

    if (!password_verify($old, $pdo->query("SELECT password_hash FROM users WHERE id = " . (int)$user['id'])->fetchColumn())) {
        $error = 'Старый пароль неверный';
    } elseif (mb_strlen($new1) < 6) {
        $error = 'Новый пароль должен быть не короче 6 символов';
    } elseif ($new1 !== $new2) {
        $error = 'Новые пароли не совпадают';
    } else {
        $hash = password_hash($new1, PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('UPDATE users SET password_hash = :hash WHERE id = :id');
        $stmt->execute([':hash' => $hash, ':id' => $user['id']]);
        $success = 'Пароль изменён';
    }
}

// === Статистика пользователя ===
$stmt = $pdo->prepare('SELECT COUNT(*) AS cnt, COALESCE(SUM(file_size),0) AS total_size FROM photos WHERE user_id = :id');
$stmt->execute([':id' => $user['id']]);
$stats = $stmt->fetch();

// === Последние 20 фото пользователя ===
$stmt = $pdo->prepare('SELECT id, key_type, key_value, photo_type, file_path, created_at FROM photos WHERE user_id = :id ORDER BY created_at DESC LIMIT 20');
$stmt->execute([':id' => $user['id']]);
$myPhotos = $stmt->fetchAll();

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
<title>Личный кабинет</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; margin: 0; padding: 16px; color: #1a1a1a; line-height: 1.5; }
  .container { max-width: 900px; margin: 0 auto; }
  .card { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 16px; }
  h1 { font-size: 22px; margin: 0 0 8px; }
  h2 { font-size: 18px; margin: 0 0 16px; }
  .top-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
  .user-info { font-size: 13px; color: #666; }
  .user-info b { color: #2563eb; }
  .logout { color: #dc2626; text-decoration: none; font-size: 13px; margin-left: 12px; }
  .btn { display: inline-block; padding: 10px 16px; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; background: #2563eb; color: #fff; text-decoration: none; text-align: center; }
  .btn:hover { opacity: 0.9; }
  .btn-secondary { background: #fff; color: #2563eb; border: 1.5px solid #2563eb; }
  .btn-small { padding: 8px 14px; font-size: 14px; }
  .btn-row { display: flex; gap: 10px; flex-wrap: wrap; }
  .form-row { margin-bottom: 14px; }
  .form-row label { display: block; font-size: 13px; font-weight: 600; color: #666; margin-bottom: 6px; }
  .form-row input { width: 100%; padding: 10px 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 15px; }
  .form-row input:focus { outline: none; border-color: #2563eb; }
  .alert { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
  .alert-success { background: #f0fdf4; color: #16a34a; border-left: 4px solid #16a34a; }
  .alert-error { background: #fef2f2; color: #dc2626; border-left: 4px solid #dc2626; }
  .stats { display: flex; gap: 16px; flex-wrap: wrap; }
  .stat-item { background: #eff6ff; padding: 12px 20px; border-radius: 10px; }
  .stat-item b { display: block; font-size: 22px; color: #2563eb; }
  .photo-grid { display: grid; grid-template-columns: repeat(auto-fill, minmax(140px, 1fr)); gap: 10px; }
  .photo-card { background: #f9fafb; border-radius: 10px; overflow: hidden; border: 1.5px solid #e5e7eb; }
  .photo-card img { width: 100%; height: 120px; object-fit: cover; display: block; background: #e5e7eb; cursor: pointer; }
  .photo-meta { padding: 8px; font-size: 11px; color: #666; }
</style>
</head>
<body>
<div class="container">

  <div class="card">
    <div class="top-bar">
      <h1>👤 Личный кабинет</h1>
      <div>
        <span class="user-info">Вы вошли как <b><?= e($user['name'] ?: $user['email']) ?></b></span>
        <a href="logout.php" class="logout">Выйти</a>
      </div>
    </div>
    <div class="btn-row">
      <a href="gallery.php" class="btn btn-secondary btn-small">← К галерее</a>
      <a href="index.html" class="btn btn-secondary btn-small">← На рабочее место</a>
      <?php if ($user['is_admin']): ?>
        <a href="admin.php" class="btn btn-small" style="background:#dc2626;">🛡️ Админ-панель</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($success): ?><div class="alert alert-success">✅ <?= e($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error">❌ <?= e($error) ?></div><?php endif; ?>

  <div class="card">
    <h2>Моя статистика</h2>
    <div class="stats">
      <div class="stat-item">
        <b><?= (int)$stats['cnt'] ?></b>
        Фото загружено
      </div>
      <div class="stat-item">
        <b><?= formatSize((int)$stats['total_size']) ?></b>
        Объём
      </div>
      <div class="stat-item">
        <b><?= $user['is_admin'] ? 'Админ' : 'Инженер' ?></b>
        Роль
      </div>
    </div>
  </div>

  <div class="card">
    <h2>Профиль</h2>
    <form method="post">
      <input type="hidden" name="action" value="update_profile">
      <div class="form-row">
        <label>Логин</label>
        <input type="text" name="login" required value="<?= e($user['email']) ?>">
      </div>
      <div class="form-row">
        <label>Имя</label>
        <input type="text" name="name" value="<?= e($user['name'] ?? '') ?>" placeholder="Иван Иванов">
      </div>
      <button type="submit" class="btn">Сохранить профиль</button>
    </form>
  </div>

  <div class="card">
    <h2>Смена пароля</h2>
    <form method="post">
      <input type="hidden" name="action" value="change_password">
      <div class="form-row">
        <label>Старый пароль</label>
        <input type="password" name="old_password" required>
      </div>
      <div class="form-row">
        <label>Новый пароль</label>
        <input type="password" name="new_password" required>
      </div>
      <div class="form-row">
        <label>Повторите новый пароль</label>
        <input type="password" name="new_password2" required>
      </div>
      <button type="submit" class="btn">Сменить пароль</button>
    </form>
  </div>

  <div class="card">
    <h2>Мои последние фото (<?= count($myPhotos) ?>)</h2>
    <?php if (empty($myPhotos)): ?>
      <p style="color:#888;">Вы ещё не загружали фото.</p>
    <?php else: ?>
      <div class="photo-grid">
        <?php foreach ($myPhotos as $p): ?>
          <div class="photo-card">
            <a href="<?= e($p['file_path']) ?>" target="_blank">
              <img src="<?= e($p['file_path']) ?>" alt="" loading="lazy">
            </a>
            <div class="photo-meta">
              <div><b><?= $p['key_type'] === 'ra' ? 'РА' : 'VIN' ?>:</b> <?= e($p['key_value']) ?></div>
              <div style="margin-top:2px;font-size:10px;color:#aaa;"><?= e(date('d.m.Y H:i', strtotime($p['created_at']))) ?></div>
            </div>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

</div>
</body>
</html>
