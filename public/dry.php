<?php
/**
 * DRY-разбор XML от 1С — показать, что распарсилось, без записи в БД.
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
  .container{max-width:1400px;margin:0 auto}
  .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,0.06);margin-bottom:16px}
  h1{font-size:22px;margin:0 0 12px} h2{font-size:16px;margin:0 0 12px;color:#1e3a8a}
  .btn{display:inline-block;padding:12px 18px;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;background:#2563eb;color:#fff;text-decoration:none;text-align:center}
  .btn-secondary{background:#fff;color:#2563eb;border:1.5px solid #2563eb}
  .btn-green{background:#16a34a}
  .alert{padding:12px 16px;border-radius:10px;font-size:14px;margin-bottom:16px}
  .alert-success{background:#f0fdf4;color:#16a34a;border-left:4px solid #16a34a}
  .alert-error{background:#fef2f2;color:#dc2626;border-left:4px solid #dc2626}
  .alert-info{background:#eff6ff;color:#2563eb;border-left:4px solid #2563eb}
  .alert-warn{background:#fffbeb;color:#b45309;border-left:4px solid #b45309}
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
      <a href="?wait=1" class="btn btn-green">🚀 Запустить DRY-разбор</a>
      <a href="?" class="btn btn-secondary">🔄 Обновить</a>
    </div>
    <?php if ($autoReload): ?>
      <div class="alert alert-info" style="margin-top:12px;">
        ⏳ Разбор идёт в фоне. Страница обновится через 10 секунд автоматически.
      </div>
      <!-- Невидимая картинка запускает фоновый воркер -->
      <img src="sync_worker_http.php?dry=works&t=<?= time() ?>" width="1" height="1" alt="" style="position:fixed;left:-100px;top:-100px;opacity:0;">
    <?php endif; ?>
  </div>

  <?php if ($result === null): ?>
    <div class="card">
      <div class="alert alert-info">Результата пока нет. Нажми «Запустить DRY-разбор».</div>
    </div>
  <?php elseif (!empty($result['error'])): ?>
    <div class="card">
      <div class="alert alert-error">❌ Ошибка 1С: <?= htmlspecialchars($result['error']) ?></div>
    </div>
  <?php elseif (!empty($result['parse_error'])): ?>
    <div class="card">
      <h2>❌ Ошибка парсинга</h2>
      <div class="alert alert-error">
        <b>Длина XML:</b> <?= number_format((int)($result['xml_len'] ?? 0), 0, '.', ' ') ?> байт<br>
        <b>Ошибка:</b> <?= htmlspecialchars($result['parse_error']) ?>
      </div>
    </div>
  <?php else: ?>
    <div class="card">
      <h2>Статистика</h2>
      <div class="stats">
        <div class="stat"><b><?= number_format((int)($result['xml_len'] ?? 0), 0, '.', ' ') ?></b>длина XML (байт)</div>
        <div class="stat"><b><?= !empty($result['simplexml_ok']) ? '✅' : '❌' ?></b>SAX-парсер</div>
        <div class="stat"><b><?= (int)($result['stats']['total'] ?? 0) ?></b>всего</div>
        <div class="stat"><b><?= (int)($result['stats']['groups'] ?? 0) ?></b>групп</div>
        <div class="stat"><b><?= (int)($result['stats']['works'] ?? 0) ?></b>работ</div>
      </div>
    </div>

    <?php if (!empty($result['items'])): ?>
      <div class="card">
        <h2>Первые 50 элементов</h2>
        <table>
          <thead><tr><th>Code</th><th>Parent_Code (вложенный)</th><th>Тип</th><th>Имя</th><th>OperationCode</th></tr></thead>
          <tbody>
            <?php foreach ($result['items'] as $p): ?>
              <tr>
                <td><code><?= htmlspecialchars($p['code']) ?></code></td>
                <td>
                  <?php if (!empty($p['parent_code'])): ?>
                    <code style="background:#f0fdf4;color:#16a34a;"><?= htmlspecialchars($p['parent_code']) ?></code>
                  <?php else: ?>
                    <code style="background:#fef2f2;color:#dc2626;">—</code>
                  <?php endif; ?>
                </td>
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
        <h2>Примеры работ → вложенный Parent из XML</h2>
        <p style="font-size:12px;color:#666;margin-bottom:8px;">
          Если у работ тут стоит <code style="color:#16a34a;">зелёный</code> код — значит в XML есть вложенный &lt;Parent&gt;&lt;Code&gt;.
          Если <code style="color:#dc2626;">красное тире</code> — в 1С нет вложенного Parent у работ.
        </p>
        <table>
          <thead><tr><th>Code</th><th>OperationCode</th><th>Parent_Code</th><th>Имя</th></tr></thead>
          <tbody>
            <?php foreach ($result['works_sample'] as $w): ?>
              <tr>
                <td><code><?= htmlspecialchars($w['code']) ?></code></td>
                <td><code><?= htmlspecialchars($w['operation_code'] ?? '—') ?></code></td>
                <td>
                  <?php if (!empty($w['parent_code'])): ?>
                    <code style="background:#f0fdf4;color:#16a34a;font-weight:700;"><?= htmlspecialchars($w['parent_code']) ?></code>
                  <?php else: ?>
                    <code style="background:#fef2f2;color:#dc2626;">—</code>
                  <?php endif; ?>
                </td>
                <td><?= htmlspecialchars(mb_substr($w['name'] ?? '', 0, 60)) ?></td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      </div>
    <?php endif; ?>

    <?php if (!empty($result['raw_xml'])): ?>
      <div class="card">
        <h2>🔍 Сырой XML (первые 100 000 символов) — для отладки</h2>
        <p style="color:#666;font-size:13px;">
          Скинь скриншот этой области — мне нужно увидеть, есть ли у работ вложенный &lt;Parent&gt;.
        </p>
        <pre style="background:#1a1a1a;color:#d1d5db;padding:16px;border-radius:10px;font-size:10px;overflow:auto;max-height:600px;white-space:pre-wrap;word-break:break-all;"><?= htmlspecialchars($result['raw_xml']) ?></pre>
      </div>
    <?php endif; ?>
  <?php endif; ?>

</div>
</body>
</html>
