<?php
/**
 * Показывает результат dry-разбора.
 * Если результат старый (>10 мин) или его нет — можно запустить заново.
 */
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user || !$user['is_admin']) {
    http_response_code(403);
    exit('Только для админа');
}

$file = __DIR__ . '/uploads/dry_result.json';
$result = null;
$fileAge = null;
if (file_exists($file)) {
    $fileAge = time() - filemtime($file);
    $result = json_decode(file_get_contents($file), true);
}

$autoReload = !empty($_GET['wait']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>DRY-разбор XML от 1С</title>
<?php if ($autoReload): ?><meta http-equiv="refresh" content="10"><?php endif; ?>
<style>
  *{box-sizing:border-box}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;margin:0;padding:16px;color:#1a1a1a;line-height:1.5}
  .container{max-width:1200px;margin:0 auto}
  .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,0.06);margin-bottom:16px}
  h1{font-size:22px;margin:0 0 12px} h2{font-size:16px;margin:0 0 12px;color:#1e3a8a}
  .btn{display:inline-block;padding:12px 18px;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;background:#2563eb;color:#fff;text-decoration:none;text-align:center}
  .btn-secondary{background:#fff;color:#2563eb;border:1.5px solid #2563eb}
  .btn-green{background:#16a34a}
  .alert{padding:12px 16px;border-radius:10px;font-size:14px;margin-bottom:16px}
  .alert-success{background:#f0fdf4;color:#16a34a;border-left:4px solid #16a34a}
  .alert-error{background:#fef2f2;color:#dc2626;border-left:4px solid #dc2626}
  .alert-info{background:#eff6ff;color:#2563eb;border-left:4px solid #2563eb}
  table{width:100%;border-collapse:collapse;font-size:12px}
  th{background:#f9fafb;text-align:left;padding:8px 10px;font-size:11px;color:#666;text-transform:uppercase}
  td{padding:8px 10px;border-bottom:1px solid #f0f0f0}
  code{background:#eff6ff;padding:2px 6px;border-radius:4px;color:#1e3a8a;font-size:12px}
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin-bottom:16px}
  .stat{padding:12px;background:#f9fafb;border-radius:8px;font-size:14px}
  .stat b{color:#2563eb;font-size:18px;display:block}
</style>
</head>
<body>
<div class="container">

  <div class="card">
    <h1>🔬 DRY-разбор XML от 1С</h1>
    <p style="color:#666;font-size:14px;">
      Запускает запрос к 1С в фоне (без записи в БД), сохраняет результат в файл, показывает здесь.
    </p>
    <?php if ($fileAge !== null): ?>
      <p style="font-size:12px;color:#888;">
        Последний результат: <b><?= floor($fileAge / 60) ?> мин <?= $fileAge % 60 ?> сек назад</b>
        <?php if ($fileAge > 600): ?><span style="color:#b45309;">(устарел, запусти заново)</span><?php endif; ?>
      </p>
    <?php endif; ?>
    <div style="margin-top:12px;">
      <a href="?wait=1" class="btn btn-green" onclick="
        var img = new Image();
        img.src = 'sync_worker_http.php?dry=works&t=' + Date.now();
        return true;
      ">🚀 Запустить DRY-разбор</a>
      <a href="?" class="btn btn-secondary">🔄 Обновить</a>
    </div>
    <?php if ($autoReload): ?>
      <div class="alert alert-info" style="margin-top:12px;">
        ⏳ Разбор идёт в фоне. Страница обновится через 10 секунд автоматически.
      </div>
    <?php endif; ?>
  </div>

  <?php if ($result === null): ?>
    <div class="card">
      <div class="alert alert-info">Результата пока нет. Нажми «Запустить DRY-разбор».</div>
    </div>
  <?php elseif (!empty($result['error'])): ?>
    <div class="card">
      <div class="alert alert-error">❌ Ошибка: <?= htmlspecialchars($result['error']) ?></div>
    </div>
  <?php else: ?>
    <div class="card">
      <h2>Статистика</h2>
      <div class="stats">
        <div class="stat"><b><?= (int)($result['xml_len'] ?? 0) ?></b>длина XML</div>
        <div class="stat"><b><?= $result['simplexml_ok'] ? '✅' : '❌' ?></b>SimpleXML</div>
        <div class="stat"><b><?= (int)($result['stats']['total'] ?? 0) ?></b>всего</div>
        <div class="stat"><b><?= (int)($result['stats']['groups'] ?? 0) ?></b>групп</div>
        <div class="stat"><b><?= (int)($result['stats']['works'] ?? 0) ?></b>работ</div>
      </div>
    </div>

    <?php if (!empty($result['items'])): ?>
      <div class="card">
        <h2>Первые 50 элементов</h2>
        <table>
          <thead><tr><th>Code</th><th>Parent</th><th>Тип</th><th>Имя</th><th>OperationCode</th></tr></thead>
          <tbody>
            <?php foreach ($result['items'] as $p): ?>
              <tr>
                <td><code><?= htmlspecialchars($p['code']) ?></code></td>
                <td><code><?= htmlspecialchars($p['parent_code'] ?? '—') ?></code></td>
                <td><?= $p['it_is_group'] ? '📁' : '🔧' ?></td>
                <td><?= htmlspecialchars(mb_substr($p['name'] ?? '', 0, 60)) ?></td>
                <td><?= htmlspecialchars($p['operation_code'] ?? '—') ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if (!empty($result['works_sample'])): ?>
      <div class="card">
        <h2>Примеры работ → вычисленный родитель</h2>
        <table>
          <thead><tr><th>Code</th><th>OperationCode</th><th>→ Родитель</th><th>Имя</th></tr></thead>
          <tbody>
            <?php foreach ($result['works_sample'] as $w): ?>
              <tr>
                <td><code><?= htmlspecialchars($w['code']) ?></code></td>
                <td><code><?= htmlspecialchars($w['operation_code'] ?? '—') ?></code></td>
                <td><code style="color:<?= empty($w['parent_code']) ? '#dc2626' : '#16a34a' ?>;"><?= htmlspecialchars($w['parent_code'] ?? '—') ?></code></td>
                <td><?= htmlspecialchars(mb_substr($w['name'] ?? '', 0, 60)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>
  <?php endif; ?>

</div>
</body>
</html>
