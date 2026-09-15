<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user || !$user['is_admin']) { header('Location: index.html'); exit; }

$pdo = get_db();
$messages = [];
$error = null;

// === Запуск в фоне: ничего не запускаем, только показываем страницу с <img> ===
$startType = $_GET['run_type'] ?? '';
if ($startType && in_array($startType, ['works', 'nomenclature', 'all'], true)) {
    // Проверим, нет ли уже running
    $stmt = $pdo->query("SELECT COUNT(*) FROM sync_log WHERE status='running' AND started_at > NOW() - INTERVAL '15 minutes'");
    if ((int)$stmt->fetchColumn() > 0) {
        $error = 'Синхронизация уже запущена. Дождитесь завершения.';
        $startType = '';
    }
}

$history = $pdo->query("SELECT * FROM sync_log ORDER BY started_at DESC LIMIT 20")->fetchAll();

$lastRunning = null;
foreach ($history as $h) {
    if ($h['status'] === 'running') { $lastRunning = $h; break; }
}

function fmtTs($ts) { return $ts ? date('d.m.Y H:i:s', strtotime($ts)) : '—'; }
function fmtDiff($from, $to) {
    $s = strtotime($to) - strtotime($from);
    if ($s < 60) return $s . ' сек';
    return floor($s / 60) . ' мин ' . ($s % 60) . ' сек';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Синхронизация с 1С</title>
<?php if ($lastRunning || $startType): ?>
  <meta http-equiv="refresh" content="15">
<?php endif; ?>
<style>
  *{box-sizing:border-box} body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;margin:0;padding:16px;color:#1a1a1a;line-height:1.5}
  .container{max-width:900px;margin:0 auto} .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,0.06);margin-bottom:16px}
  h1{font-size:22px;margin:0 0 12px} h2{font-size:18px;margin:0 0 12px}
  .btn{display:inline-block;padding:12px 18px;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;background:#2563eb;color:#fff;text-decoration:none;text-align:center}
  .btn-secondary{background:#fff;color:#2563eb;border:1.5px solid #2563eb} .btn-green{background:#16a34a}
  .btn[disabled]{opacity:0.5;cursor:not-allowed;pointer-events:none}
  .btn-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
  .alert{padding:12px 16px;border-radius:10px;font-size:14px;margin-bottom:16px}
  .alert-success{background:#f0fdf4;color:#16a34a;border-left:4px solid #16a34a}
  .alert-error{background:#fef2f2;color:#dc2626;border-left:4px solid #dc2626}
  .alert-info{background:#eff6ff;color:#2563eb;border-left:4px solid #2563eb}
  table.doc-table{width:100%;border-collapse:collapse;font-size:13px}
  table.doc-table th{background:#f9fafb;color:#666;font-weight:600;text-align:left;padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:12px}
  table.doc-table td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:top}
  .badge{display:inline-block;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600}
  .badge-green{background:#f0fdf4;color:#16a34a} .badge-red{background:#fef2f2;color:#dc2626} .badge-blue{background:#eff6ff;color:#2563eb}
  code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:12px}
  .err-cell{color:#555;font-size:11px;word-break:break-all;max-width:480px}
  .spin { display:inline-block; animation: spin 1s linear infinite; }
  @keyframes spin { to { transform: rotate(360deg); } }
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <h1>🔄 Синхронизация с 1С:ГОА</h1>
    <div class="btn-row">
      <a href="index.html" class="btn btn-secondary">← На рабочее место</a>
      <a href="works.php" class="btn btn-secondary">К справочнику работ</a>
    </div>
  </div>

  <?php if ($error): ?><div class="alert alert-error">❌ <?= e($error) ?></div><?php endif; ?>
  <?php if ($startType): ?>
    <div class="alert alert-info">
      🚀 <b>Синхронизация «<?= e($startType) ?>» запускается в фоне...</b>
      <br><small>Воркер работает независимо от браузера. Страница обновится через 15 секунд.</small>
    </div>
    <!-- Невидимая «картинка», которая запускает воркер в фоне -->
    <img src="sync_worker_http.php?type=<?= e($startType) ?>" width="1" height="1" alt=""
         style="position:fixed;left:-100px;top:-100px;opacity:0;">
  <?php endif; ?>

  <?php if ($lastRunning): ?>
    <div class="alert alert-info">
      <span class="spin">🔄</span>
      <b>Синхронизация «<?= e($lastRunning['sync_type']) ?>» выполняется...</b>
      (запущена <?= e(fmtTs($lastRunning['started_at'])) ?>, прошло <?= e(fmtDiff($lastRunning['started_at'], date('Y-m-d H:i:s'))) ?>)
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>Запустить синхронизацию</h2>
    <div class="btn-row">
      <a href="sync.php?run_type=works" class="btn btn-green <?= ($lastRunning || $startType) ? 'disabled' : '' ?>">🔧 Обновить работы</a>
      <a href="sync.php?run_type=nomenclature" class="btn btn-green <?= ($lastRunning || $startType) ? 'disabled' : '' ?>">📦 Обновить номенклатуру</a>
      <a href="sync.php?run_type=all" class="btn <?= ($lastRunning || $startType) ? 'disabled' : '' ?>">🔄 Обновить всё</a>
      <?php if ($lastRunning || $startType): ?>
        <a href="sync.php" class="btn btn-secondary">🔄 Обновить статус</a>
      <?php endif; ?>
    </div>
    <p style="font-size:13px;color:#666;margin-top:12px;">
      Синхронизация работает в фоне (через невидимую картинку). Можно закрыть вкладку — процесс продолжится на сервере.
    </p>
  </div>

  <div class="card">
    <h2>История синхронизаций</h2>
    <?php if (!$history): ?><p style="color:#888;">Пока нет ни одной синхронизации.</p>
    <?php else: ?>
      <table class="doc-table">
        <thead><tr><th>Тип</th><th>Статус</th><th>Начало</th><th>Окончание</th><th>Записей</th><th>Детали</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td><code><?= e($h['sync_type']) ?></code></td>
            <td>
              <?php if ($h['status']==='ok'): ?><span class="badge badge-green">успех</span>
              <?php elseif ($h['status']==='error'): ?><span class="badge badge-red">ошибка</span>
              <?php else: ?><span class="badge badge-blue"><span class="spin">🔄</span> работает</span><?php endif; ?>
            </td>
            <td><?= e(fmtTs($h['started_at'])) ?></td>
            <td><?= e(fmtTs($h['finished_at'])) ?></td>
            <td><?= (int)$h['items_total'] ?></td>
            <td class="err-cell"><?= e($h['error_message'] ?: '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
