<?php
require __DIR__ . '/db.php';
start_session();
$user = current_user();

$pageTitle    = 'Справочник матриц ТО';
$pageSubtitle = 'КАМАЗ · все комплектации';
$backLink     = 'to_calculator.php';
$backLabel    = 'К калькулятору';

$db = get_db();

$matrices = $db->query("
    SELECT m.*,
           (SELECT COUNT(*) FROM to_items WHERE matrix_id = m.id) AS items_count,
           (SELECT COUNT(*) FROM to_norms WHERE matrix_id = m.id) AS norms_count
      FROM to_matrices m
     ORDER BY m.model NULLS LAST, m.complectation
")->fetchAll(PDO::FETCH_ASSOC);

// Группируем по модели
$byModel = [];
foreach ($matrices as $m) {
    $key = $m['model'] ?: '— без модели —';
    $byModel[$key][] = $m;
}

include __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="app.css">

<div class="container" style="max-width:1100px;margin:0 auto;padding:24px">

  <div style="display:flex;justify-content:space-between;align-items:flex-start;gap:14px;flex-wrap:wrap;margin-bottom:20px">
    <div>
      <h1 style="margin:0 0 6px;font-size:22px">📊 Справочник матриц ТО</h1>
      <p style="margin:0;color:#64748b;font-size:14px">
        Всего матриц: <b><?= count($matrices) ?></b>
        <?php if ($byModel): ?>
          · моделей: <b><?= count($byModel) ?></b>
        <?php endif; ?>
      </p>
    </div>
    <a href="sync_to_matrix.php"
       style="padding:10px 18px;border-radius:10px;background:#2563eb;color:#fff;text-decoration:none;font-weight:600;font-size:14px;white-space:nowrap">
      📥 Загрузить новые
    </a>
  </div>

  <?php if (!$matrices): ?>
    <div style="background:rgba(255,255,255,0.85);padding:22px 26px;border-radius:18px;text-align:center;color:#64748b">
      Матриц пока нет. Загрузите XLSX-файлы.
    </div>
  <?php else: ?>
    <?php foreach ($byModel as $model => $list): ?>
      <div style="background:rgba(255,255,255,0.85);padding:18px 22px;border-radius:16px;margin-bottom:14px">
        <h2 style="margin:0 0 12px;font-size:16px;color:#1e3a8a">
          Модель <b><?= e($model) ?></b>
          <span style="color:#64748b;font-weight:400;font-size:13.5px">— <?= count($list) ?> комплектац<?= count($list) === 1 ? 'ия' : (count($list) < 5 ? 'ии' : 'ий') ?></span>
        </h2>

        <table style="width:100%;border-collapse:collapse;font-size:14px">
          <tr style="text-align:left;color:#64748b;border-bottom:1px solid #e2e8f0">
            <th style="padding:6px 8px">Комплектация</th>
            <th style="padding:6px 8px;width:80px;text-align:right">Строк</th>
            <th style="padding:6px 8px;width:80px;text-align:right">Норм</th>
            <th style="padding:6px 8px;width:140px;color:#94a3b8;font-weight:400">Импорт</th>
            <th style="padding:6px 8px;width:200px"></th>
          </tr>
          <?php foreach ($list as $m): ?>
            <tr style="border-bottom:1px solid #f1f5f9">
              <td style="padding:9px 8px">
                <b style="font-family:ui-monospace,Menlo,monospace"><?= e($m['complectation']) ?></b>
                <?php if ($m['title'] && $m['title'] !== $m['complectation']): ?>
                  <div style="font-size:11.5px;color:#94a3b8;margin-top:2px"><?= e(mb_substr($m['title'], 0, 100)) ?></div>
                <?php endif; ?>
              </td>
              <td style="padding:9px 8px;text-align:right;color:#64748b"><?= (int)$m['items_count'] ?></td>
              <td style="padding:9px 8px;text-align:right;color:#64748b"><?= (int)$m['norms_count'] ?></td>
              <td style="padding:9px 8px;font-size:12px;color:#94a3b8"><?= e(date('d.m.Y H:i', strtotime($m['imported_at']))) ?></td>
              <td style="padding:9px 8px;text-align:right">
                <a href="to_calculator.php?c=<?= urlencode($m['complectation']) ?>"
                   style="display:inline-block;padding:6px 12px;border-radius:8px;background:#eff6ff;color:#1d4ed8;text-decoration:none;font-weight:600;font-size:12.5px">
                  Открыть →
                </a>
              </td>
            </tr>
          <?php endforeach; ?>
        </table>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>

  <div style="margin-top:20px">
    <a href="to_calculator.php"
       style="display:inline-block;padding:9px 16px;border-radius:10px;background:#f1f5f9;color:#0f172a;text-decoration:none;font-weight:600;font-size:13.5px">
      ← Калькулятор ТО
    </a>
  </div>

</div>

</body>
</html>
