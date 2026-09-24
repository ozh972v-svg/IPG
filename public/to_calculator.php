<?php
require __DIR__ . '/db.php';
start_session();
$user = current_user();

$pageTitle    = 'Калькулятор ТО КАМАЗ';
$pageSubtitle = 'регламент по комплектации';
$backLink     = 'index.html';
$backLabel    = 'На рабочее место';

$db = get_db();

$matrices = [];
try {
    $matrices = $db->query("SELECT * FROM to_matrices ORDER BY complectation")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    // таблицы ещё не созданы
}

$complectation = trim((string)($_GET['c'] ?? ''));
$toCode        = trim((string)($_GET['to'] ?? ''));

$matrix = null;
$norms  = [];
$items  = [];

if ($complectation !== '') {
    $st = $db->prepare("SELECT * FROM to_matrices WHERE complectation = ?");
    $st->execute([$complectation]);
    $matrix = $st->fetch(PDO::FETCH_ASSOC);

    if ($matrix) {
        $stN = $db->prepare("SELECT to_code, norm_hours FROM to_norms WHERE matrix_id = ?");
        $stN->execute([$matrix['id']]);
        foreach ($stN->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $norms[$r['to_code']] = (float)$r['norm_hours'];
        }

        if ($toCode !== '') {
            // jsonb_exists вместо оператора ? (он конфликтует с PDO placeholders)
            $stI = $db->prepare("
                SELECT row_num, name, article, unit, quantities
                  FROM to_items
                 WHERE matrix_id = ? AND jsonb_exists(quantities, ?)
                 ORDER BY row_num
            ");
            $stI->execute([$matrix['id'], $toCode]);
            $items = $stI->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

$TO_LABELS = [
    'PZ'   => 'Предварительно-заключительные работы',
    'PTO'  => 'ПТО (периодическое ТО)',
    'A2'   => '2ТО (А2)',
    'A3'   => '3ТО (А3)',
    'TOD'  => 'ТОд',
    'ZTOD' => '3ТОд',
    'TOK'  => 'ТОк',
    'TOM'  => 'ТОм',
    'V'    => '1 раз в год (В)',
    'V2'   => '1 раз в 2 года (В2)',
    'V3'   => '1 раз в 3 года (В3)',
    'V4'   => '1 раз в 4 года (В4)',
    'WASH' => 'Мойка а/м',
];

include __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="app.css">

<div class="container" style="max-width:1100px;margin:0 auto;padding:24px">

  <?php if (!$matrices): ?>
    <div style="background:rgba(255,255,255,0.85);padding:22px 26px;border-radius:18px">
      <h2 style="margin:0 0 8px;font-size:18px">Матриц пока нет</h2>
      <p style="color:#64748b;margin:0 0 14px">
        Сначала загрузите XLSX-файл с матрицами на странице импорта.
      </p>
      <a href="sync_to_matrix.php"
         style="display:inline-block;padding:10px 18px;border-radius:10px;background:#2563eb;color:#fff;text-decoration:none;font-weight:600">
        Перейти к импорту →
      </a>
    </div>

  <?php else: ?>

    <form method="get" style="background:rgba(255,255,255,0.85);padding:20px 24px;border-radius:18px;margin-bottom:22px;display:flex;flex-wrap:wrap;gap:14px;align-items:end">
      <div style="flex:1;min-width:240px">
        <label style="display:block;font-size:12.5px;color:#64748b;margin-bottom:5px">Комплектация</label>
        <select name="c" required
                style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;font-size:14px">
          <option value="">— выберите —</option>
          <?php foreach ($matrices as $m): ?>
            <option value="<?= e($m['complectation']) ?>"
              <?= $m['complectation'] === $complectation ? 'selected' : '' ?>>
              <?= e($m['complectation']) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <div style="flex:1;min-width:240px">
        <label style="display:block;font-size:12.5px;color:#64748b;margin-bottom:5px">Вид ТО</label>
        <select name="to" required
                style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;font-size:14px">
          <option value="">— выберите —</option>
          <?php foreach ($TO_LABELS as $code => $lbl): ?>
            <option value="<?= e($code) ?>" <?= $code === $toCode ? 'selected' : '' ?>>
              <?= e($lbl) ?>
            </option>
          <?php endforeach; ?>
        </select>
      </div>
      <button type="submit"
              style="padding:11px 22px;border:none;border-radius:10px;background:#2563eb;color:#fff;font-weight:600;cursor:pointer">
        Рассчитать
      </button>
    </form>

    <?php if ($complectation && !$matrix): ?>
      <div style="background:rgba(254,226,226,0.8);padding:14px 18px;border-radius:12px;margin-bottom:18px;color:#991b1b">
        Комплектация <b><?= e($complectation) ?></b> не найдена в базе.
      </div>
    <?php endif; ?>

    <?php if ($matrix): ?>
      <div style="background:rgba(255,255,255,0.85);padding:22px 26px;border-radius:18px;margin-bottom:18px">
        <div style="font-size:13px;color:#64748b;margin-bottom:4px">Комплектация</div>
        <div style="font-size:22px;font-weight:700;letter-spacing:-0.01em"><?= e($matrix['complectation']) ?></div>
        <?php if ($matrix['title']): ?>
          <div style="font-size:13.5px;color:#64748b;margin-top:4px"><?= e($matrix['title']) ?></div>
        <?php endif; ?>
      </div>

      <?php if ($toCode === ''): ?>
        <div style="background:rgba(255,255,255,0.85);padding:20px 24px;border-radius:18px">
          <h3 style="margin:0 0 12px;font-size:16px">Нормо-часы по видам ТО</h3>
          <?php if (!$norms): ?>
            <p style="color:#64748b">Нормы для этой матрицы не найдены.</p>
          <?php else: ?>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px">
              <?php foreach ($TO_LABELS as $code => $lbl): if (!isset($norms[$code])) continue; ?>
                <a href="?c=<?= urlencode($complectation) ?>&to=<?= urlencode($code) ?>"
                   style="display:block;padding:14px 16px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;text-decoration:none;color:#0f172a">
                  <div style="font-size:12.5px;color:#64748b"><?= e($lbl) ?></div>
                  <div style="font-size:20px;font-weight:700;margin-top:4px">
                    <?= number_format($norms[$code], 3, ',', ' ') ?> <span style="font-size:13px;font-weight:400;color:#64748b">чел/ч</span>
                  </div>
                </a>
              <?php endforeach; ?>
            </div>
          <?php endif; ?>
        </div>

      <?php else: ?>
        <?php $label = $TO_LABELS[$toCode] ?? $toCode; ?>
        <div style="background:rgba(255,255,255,0.85);padding:22px 26px;border-radius:18px;margin-bottom:18px">
          <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px;align-items:center">
            <div>
              <div style="font-size:13px;color:#64748b;margin-bottom:2px">Вид ТО</div>
              <div style="font-size:20px;font-weight:700"><?= e($label) ?></div>
            </div>
            <?php if (isset($norms[$toCode])): ?>
              <div style="text-align:right">
                <div style="font-size:13px;color:#64748b;margin-bottom:2px">Норматив трудоёмкости</div>
                <div style="font-size:24px;font-weight:700;color:#2563eb">
                  <?= number_format($norms[$toCode], 3, ',', ' ') ?> <span style="font-size:14px;font-weight:400;color:#64748b">чел/ч</span>
                </div>
              </div>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$items): ?>
          <div style="background:rgba(255,255,255,0.85);padding:20px 24px;border-radius:18px;color:#64748b">
            Для этого вида ТО в матрице нет запчастей/материалов.
          </div>
        <?php else: ?>
          <div style="background:rgba(255,255,255,0.85);padding:20px 24px;border-radius:18px">
            <h3 style="margin:0 0 14px;font-size:16px">Материалы и запчасти (<?= count($items) ?>)</h3>
            <table style="width:100%;border-collapse:collapse;font-size:14px">
              <thead>
                <tr style="text-align:left;color:#64748b;border-bottom:1px solid #e2e8f0">
                  <th style="padding:8px 6px;width:40px">#</th>
                  <th style="padding:8px 6px">Наименование</th>
                  <th style="padding:8px 6px;width:170px">Артикул</th>
                  <th style="padding:8px 6px;width:60px">Ед.</th>
                  <th style="padding:8px 6px;width:90px;text-align:right">Кол-во</th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($items as $it): ?>
                  <?php
                    $q = json_decode($it['quantities'], true);
                    $qty = $q[$toCode] ?? null;
                  ?>
                  <tr style="border-bottom:1px solid #f1f5f9">
                    <td style="padding:9px 6px;color:#94a3b8"><?= (int)$it['row_num'] ?></td>
                    <td style="padding:9px 6px"><?= e($it['name']) ?></td>
                    <td style="padding:9px 6px;font-family:ui-monospace,Menlo,monospace;font-size:13px"><?= e($it['article']) ?></td>
                    <td style="padding:9px 6px;color:#64748b"><?= e($it['unit']) ?></td>
                    <td style="padding:9px 6px;text-align:right;font-weight:600">
                      <?= $qty !== null ? number_format($qty, 3, ',', ' ') : '—' ?>
                    </td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        <?php endif; ?>

        <div style="margin-top:16px">
          <a href="?c=<?= urlencode($complectation) ?>"
             style="display:inline-block;padding:9px 16px;border-radius:10px;background:#f1f5f9;color:#0f172a;text-decoration:none;font-weight:600;font-size:13.5px">
            ← Все виды ТО для этой комплектации
          </a>
        </div>
      <?php endif; ?>
    <?php endif; ?>

  <?php endif; ?>

</div>

</body>
</html>
