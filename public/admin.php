<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}
if (!$user['is_admin']) {
    header('Location: profile.php');
    exit;
}

$pdo = get_db();
$success = '';
$error = '';

// === Действия ===
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $targetId = (int)($_POST['user_id'] ?? 0);

    // Нельзя менять самого себя
    if ($targetId === (int)$user['id'] && $action !== 'reset_password_self') {
        $error = 'Нельзя менять свою роль или удалять себя';
    } elseif ($targetId > 0) {
        try {
            if ($action === 'make_admin') {
                $pdo->prepare('UPDATE users SET is_admin = TRUE WHERE id = :id')->execute([':id' => $targetId]);
                $success = 'Пользователь назначен админом';
            } elseif ($action === 'remove_admin') {
                $pdo->prepare('UPDATE users SET is_admin = FALSE WHERE id = :id')->execute([':id' => $targetId]);
                $success = 'Права админа сняты';
            } elseif ($action === 'delete_user') {
                // Удаляем все фото пользователя из хранилища
                $stmt = $pdo->prepare('SELECT storage_path FROM photos WHERE user_id = :id');
                $stmt->execute([':id' => $targetId]);
                $storageKey = getenv('STORAGE_API_KEY');
                foreach ($stmt as $row) {
                    if ($storageKey && !empty($row['storage_path'])) {
                        $pathForUrl = str_replace([' ', '&', '#', '?', '+'], ['%20', '%26', '%23', '%3F', '%2B'], $row['storage_path']);
                        $ch = curl_init('https://relaxdev.ru/api/v1/storage/files?path=' . $pathForUrl);
                        curl_setopt_array($ch, [
                            CURLOPT_CUSTOMREQUEST => 'DELETE',
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_HTTPHEADER => ['Authorization: Bearer ' . $storageKey],
                            CURLOPT_TIMEOUT => 30,
                        ]);
                        curl_exec($ch);
                        curl_close($ch);
                    }
                }
                // Удаляем из БД
                $pdo->prepare('DELETE FROM photos WHERE user_id = :id')->execute([':id' => $targetId]);
                $pdo->prepare('DELETE FROM users WHERE id = :id')->execute([':id' => $targetId]);
                $success = 'Пользователь и все его фото удалены';
            } elseif ($action === 'reset_password') {
                $newPass = trim($_POST['new_password'] ?? '');
                if (mb_strlen($newPass) < 6) {
                    $error = 'Пароль должен быть не короче 6 символов';
                } else {
                    $hash = password_hash($newPass, PASSWORD_DEFAULT);
                    $pdo->prepare('UPDATE users SET password_hash = :h WHERE id = :id')->execute([':h' => $hash, ':id' => $targetId]);
                    $success = 'Пароль сброшен';
                }
            }
        } catch (Throwable $e) {
            $error = 'Ошибка: ' . $e->getMessage();
        }
    }
}

// === Статистика ===
$stmt = $pdo->query('
    SELECT u.id, u.email, u.name, u.is_admin, u.created_at,
           COUNT(p.id) AS photo_count,
           COALESCE(SUM(p.file_size), 0) AS total_size,
           MAX(p.created_at) AS last_upload
    FROM users u
    LEFT JOIN photos p ON p.user_id = u.id
    GROUP BY u.id, u.email, u.name, u.is_admin, u.created_at
    ORDER BY u.id
');
$users = $stmt->fetchAll();

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
<title>Админ-панель</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; margin: 0; padding: 16px; color: #1a1a1a; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; }
  .card { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 16px; }
  h1 { font-size: 22px; margin: 0 0 8px; }
  h2 { font-size: 18px; margin: 0 0 16px; }
  .top-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
  .user-info { font-size: 13px; color: #666; }
  .user-info b { color: #dc2626; }
  .logout { color: #dc2626; text-decoration: none; font-size: 13px; margin-left: 12px; }
  .btn { display: inline-block; padding: 10px 16px; border: none; border-radius: 10px; font-size: 14px; font-weight: 600; cursor: pointer; background: #2563eb; color: #fff; text-decoration: none; }
  .btn:hover { opacity: 0.9; }
  .btn-secondary { background: #fff; color: #2563eb; border: 1.5px solid #2563eb; }
  .btn-red { background: #dc2626; }
  .btn-green { background: #16a34a; }
  .btn-small { padding: 6px 12px; font-size: 13px; }
  .btn-row { display: flex; gap: 10px; flex-wrap: wrap; }
  .alert { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
  .alert-success { background: #f0fdf4; color: #16a34a; border-left: 4px solid #16a34a; }
  .alert-error { background: #fef2f2; color: #dc2626; border-left: 4px solid #dc2626; }

  .user-card { border: 1.5px solid #e5e7eb; border-radius: 12px; padding: 16px; margin-bottom: 12px; background: #fff; }
  .user-header { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px; margin-bottom: 10px; }
  .user-login { font-weight: 700; font-size: 16px; }
  .user-name { font-size: 13px; color: #888; }
  .user-badge { padding: 3px 10px; border-radius: 8px; font-size: 12px; font-weight: 600; }
  .badge-admin { background: #fef2f2; color: #dc2626; }
  .badge-user { background: #eff6ff; color: #2563eb; }
  .user-stats { font-size: 13px; color: #666; margin-bottom: 10px; }
  .user-actions { display: flex; gap: 8px; flex-wrap: wrap; }
  .reset-form { display: none; margin-top: 10px; padding: 10px; background: #f9fafb; border-radius: 8px; }
  .reset-form input { padding: 8px 12px; border: 1.5px solid #e5e7eb; border-radius: 8px; font-size: 14px; }
</style>
</head>
<body>
<div class="container">

  <div class="card">
    <div class="top-bar">
      <h1>🛡️ Админ-панель</h1>
      <div>
        <span class="user-info">Вы вошли как <b><?= e($user['name'] ?: $user['email']) ?></b></span>
        <a href="logout.php" class="logout">Выйти</a>
      </div>
    </div>
    <div class="btn-row">
      <a href="profile.php" class="btn btn-secondary btn-small">← Личный кабинет</a>
      <a href="gallery.php" class="btn btn-secondary btn-small">← К галерее</a>
      <a href="index.html" class="btn btn-secondary btn-small">← На рабочее место</a>
    </div>
  </div>

  <?php if ($success): ?><div class="alert alert-success">✅ <?= e($success) ?></div><?php endif; ?>
  <?php if ($error): ?><div class="alert alert-error">❌ <?= e($error) ?></div><?php endif; ?>

  <div class="card">
    <h2>Пользователи (<?= count($users) ?>)</h2>

    <?php foreach ($users as $u): ?>
      <?php $isMe = ((int)$u['id'] === (int)$user['id']); ?>
      <div class="user-card">
        <div class="user-header">
          <div>
            <div class="user-login">
              <?= e($u['email']) ?>
              <?php if ($isMe): ?><span style="color:#16a34a;font-size:12px;">(это вы)</span><?php endif; ?>
            </div>
            <div class="user-name"><?= e($u['name'] ?: 'Имя не задано') ?></div>
          </div>
          <div class="user-badge <?= $u['is_admin'] ? 'badge-admin' : 'badge-user' ?>">
            <?= $u['is_admin'] ? '🛡️ Админ' : 'Инженер' ?>
          </div>
        </div>

        <div class="user-stats">
          📷 Фото: <b><?= (int)$u['photo_count'] ?></b> ·
          Объём: <b><?= formatSize((int)$u['total_size']) ?></b>
          <?php if ($u['last_upload']): ?>
            · Последнее: <?= e(date('d.m.Y H:i', strtotime($u['last_upload']))) ?>
          <?php endif; ?>
          · Зарегистрирован: <?= e(date('d.m.Y', strtotime($u['created_at']))) ?>
        </div>

        <?php if (!$isMe): ?>
          <div class="user-actions">
            <?php if ($u['is_admin']): ?>
              <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="remove_admin">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="btn btn-secondary btn-small">Снять админа</button>
              </form>
            <?php else: ?>
              <form method="post" style="display:inline;">
                <input type="hidden" name="action" value="make_admin">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="btn btn-green btn-small">Сделать админом</button>
              </form>
            <?php endif; ?>

            <button type="button" class="btn btn-secondary btn-small" onclick="toggleReset(<?= (int)$u['id'] ?>)">Сбросить пароль</button>

            <form method="post" style="display:inline;" onsubmit="return confirm('Удалить пользователя и все его фото?');">
              <input type="hidden" name="action" value="delete_user">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <button type="submit" class="btn btn-red btn-small">Удалить</button>
            </form>
          </div>

          <div class="reset-form" id="reset-<?= (int)$u['id'] ?>">
            <form method="post" style="display:flex;gap:8px;flex-wrap:wrap;align-items:center;">
              <input type="hidden" name="action" value="reset_password">
              <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
              <input type="text" name="new_password" placeholder="Новый пароль (мин. 6)" required style="flex:1;">
              <button type="submit" class="btn btn-small">Сохранить</button>
            </form>
          </div>
        <?php else: ?>
          <div style="font-size:12px;color:#888;">Это вы — свои действия тут не доступны.</div>
        <?php endif; ?>
      </div>
    <?php endforeach; ?>

  </div>

</div>
<script>
function toggleReset(id) {
  const el = document.getElementById('reset-' + id);
  if (el.style.display === 'block') {
    el.style.display = 'none';
  } else {
    el.style.display = 'block';
  }
}
</script>
</body>
</html>
