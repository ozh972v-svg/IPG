<?php
/**
 * Сбрасывает зависшие синхронизации (status='running' старше 5 минут).
 * Доступ только админу.
 */
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user || !$user['is_admin']) {
    http_response_code(403);
    exit('Только для админа');
}

$pdo = get_db();
$msg = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $stmt = $pdo->prepare("
        UPDATE sync_log
        SET status = 'error',
            finished_at = NOW(),
            error_message = COALESCE(error_message, '') || ' [сброшено вручную: зависла]'
        WHERE status = 'running'
    ");
    $stmt->execute();
    $msg = 'Сброшено записей: ' . $stmt->rowCount();
}

// Показать текущие running
$running = $pdo->query("SELECT * FROM sync_log WHERE status='running' ORDER BY started_at DESC")->fetchAll();

function fmtTs($ts) { return $ts ? date('d.m.Y H:i:s', strtotime($ts)) : '—'; }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Сброс зависших синхронизаций</title>
<style>
  body { font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; background:#f0f2f5; padding:24px; color:#1a1a1a; line-height:1.5; }
  .container { max-width:700px; margin:0 auto; }
  .card { background:#fff; border-radius:14px; padding:24px; box-shadow:0 2px 12px rgba(0,0,0,0.06); margin-bottom:16px; }
  h1 { font-size:20px; margin:0 0 12px; }
  .btn { display:inline-block; padding:12px 20px; border:none; border-radius:10px; font-size:15px; font-weight:600; cursor:pointer; background:#dc2626; color:#fff; text-decoration:none; }
  .btn-secondary { background:#fff; color:#2563eb; border:1.5px solid #2563eb; }
  .alert { padding:12px 16px; border-radius:10px; font-size:14px; margin-bottom:16px; }
  .alert-success { background:#f0fdf4; color:#16a34a; border-left:4px solid #16a34a; }
  .alert-info { background:#eff6ff; color:#2563eb; border-left:4px solid #2563eb; }
  table { width:100%; border-collapse:collapse; font-size:13px; margin-top:8px; }
  th, td { padding:8px 10px; text-align:left; border-bottom:1px solid #f0f0f0; }
  th { background:#f9fafb; font-size:11px; color:#666; text-transform:uppercase; }
  code { background:#f3f4f6; padding:2px 6px; border-radius:4px; font-size:12px; }
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <h1>🧹 Сброс зависших синхронизаций</h1>

    <?php if ($msg): ?>
      <div class="alert alert-success">✅ <?= htmlspecialchars($msg) ?></div>
    <?php endif; ?>

    <p>Найдено зависших (status='running'): <b><?= count($running) ?></b></p>

    <?php if ($running): ?>
      <table>
        <thead><tr><th>Тип</th><th>Начало</th><th>Прошло</th></tr></thead>
        <tbody>
          <?php foreach ($running as $r): ?>
            <tr>
              <td><code><?= htmlspecialchars($r['sync_type']) ?></code></td>
              <td><?= htmlspecialchars(fmtTs($r['started_at'])) ?></td>
              <td><?= floor((time() - strtotime($r['started_at'])) / 60) ?> мин</td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>

      <form method="post" style="margin-top:16px;">
        <button class="btn" type="submit" onclick="return confirm('Сбросить все зависшие?')">
          🧹 Сбросить все зависшие
        </button>
      </form>
    <?php else: ?>
      <div class="alert alert-info">Нет зависших синхронизаций</div>
      <a href="sync.php" class="btn btn-secondary">← К синхронизации</a>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
