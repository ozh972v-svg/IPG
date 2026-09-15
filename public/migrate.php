<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user || !$user['is_admin']) {
    http_response_code(403);
    exit('Доступ только для администратора');
}

$pdo = get_db();
$pdo->exec("CREATE TABLE IF NOT EXISTS schema_migrations (
    filename VARCHAR(255) PRIMARY KEY,
    applied_at TIMESTAMPTZ NOT NULL DEFAULT NOW()
)");

$files = glob(__DIR__ . '/migrations/*.sql');
sort($files);
$applied = $pdo->query("SELECT filename FROM schema_migrations")->fetchAll(PDO::FETCH_COLUMN);

$results = [];
foreach ($files as $file) {
    $name = basename($file);
    if (in_array($name, $applied, true)) {
        $results[] = ['name' => $name, 'status' => 'skipped'];
        continue;
    }
    try {
        $pdo->exec(file_get_contents($file));
        $pdo->prepare("INSERT INTO schema_migrations (filename) VALUES (:f)")
            ->execute([':f' => $name]);
        $results[] = ['name' => $name, 'status' => 'applied'];
    } catch (Throwable $e) {
        $results[] = ['name' => $name, 'status' => 'error', 'message' => $e->getMessage()];
        break;
    }
}
?>
<!DOCTYPE html>
<html lang="ru"><head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>Миграции</title>
<style>
  body { font-family: -apple-system, sans-serif; background:#f0f2f5; margin:0; padding:24px; color:#1a1a1a; }
  .container { max-width:800px; margin:0 auto; }
  .card { background:#fff; border-radius:14px; padding:20px; box-shadow:0 2px 12px rgba(0,0,0,0.06); margin-bottom:16px; }
  h1 { font-size:22px; margin:0 0 16px; }
  .row { padding:10px 12px; border-radius:8px; margin-bottom:6px; font-size:14px; }
  .ok { background:#f0fdf4; color:#16a34a; }
  .skip { background:#f9fafb; color:#666; }
  .err { background:#fef2f2; color:#dc2626; }
  .btn { display:inline-block; padding:10px 16px; border-radius:10px; background:#2563eb; color:#fff; text-decoration:none; font-weight:600; }
</style>
</head><body>
<div class="container">
  <div class="card">
    <h1>🗄️ Миграции БД</h1>
    <?php foreach ($results as $r): ?>
      <div class="row <?= $r['status']==='applied' ? 'ok' : ($r['status']==='skipped' ? 'skip' : 'err') ?>">
        <?php if ($r['status']==='applied'): ?>✅ Применена: <?php
        elseif ($r['status']==='skipped'): ?>⏭️ Пропущена: <?php
        else: ?>❌ Ошибка: <?php endif; ?>
        <b><?= e($r['name']) ?></b>
        <?php if (!empty($r['message'])): ?><br><small><?= e($r['message']) ?></small><?php endif; ?>
      </div>
    <?php endforeach; ?>
    <?php if (!$results): ?><div>Файлов миграций нет</div><?php endif; ?>
    <div style="margin-top:16px;">
      <a href="index.html" class="btn">← На главную</a>
    </div>
  </div>
</div>
</body></html>
