<?php
require __DIR__ . '/xlsx_reader.php';

$out = [];
$error = null;
$debug = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['xlsx']['tmp_name'])) {
    try {
        $file = $_FILES['xlsx']['tmp_name'];
        $out['file_name'] = $_FILES['xlsx']['name'] ?? '';
        $out['file_size'] = filesize($file);

        $reader = new XlsxReader($file);
        $out['sheets'] = $reader->sheetNames();
        $debug = $reader->debugInfo(50);

        foreach ($out['sheets'] as $i => $name) {
            if ($i >= 2) break;
            $rows = $reader->readSheet($name);
            $out['sheets_data'][$name]['row_count'] = count($rows);
            $out['sheets_data'][$name]['all_rows'] = $rows; // ВСЕ строки
        }
    } catch (Throwable $e) {
        $error = $e->getMessage() . "\n\n" . $e->getTraceAsString();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Диагностика XLSX v2 — IPG</title>
<style>
  body{font-family:-apple-system,system-ui,Arial;background:#0b1220;color:#e2e8f0;padding:24px;line-height:1.5}
  h1{font-size:20px;margin:0 0 14px}
  .box{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:18px;margin-bottom:16px}
  .box h2{font-size:15px;margin:0 0 10px;color:#93c5fd}
  pre{background:#0b1220;border:1px solid #1f2937;padding:12px;border-radius:8px;overflow-x:auto;font-size:12px;white-space:pre-wrap;word-break:break-word}
  label{display:block;margin-bottom:6px;color:#94a3b8;font-size:13px}
  input[type=file]{padding:8px;background:#1f2937;border-radius:8px;color:#e2e8f0}
  button{margin-top:10px;padding:10px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer}
  .err{background:#7f1d1d;border-color:#991b1b;padding:14px;border-radius:8px}
</style>
</head>
<body>
<h1>Диагностика XLSX (v2)</h1>

<div class="box">
  <form method="post" enctype="multipart/form-data">
    <label>Загрузи тот же XLSX:</label>
    <input type="file" name="xlsx" accept=".xlsx" required>
    <button type="submit">Диагностировать</button>
  </form>
</div>

<?php if ($error): ?>
  <div class="box err"><h2>❌ Ошибка</h2><pre><?= htmlspecialchars($error) ?></pre></div>
<?php endif; ?>

<?php if ($out): ?>
  <div class="box">
    <h2>Файл</h2>
    <pre><?= htmlspecialchars($out['file_name'] . ' · ' . $out['file_size'] . ' байт') ?></pre>
  </div>

  <div class="box">
    <h2>Листы (<?= count($out['sheets']) ?>)</h2>
    <pre><?= htmlspecialchars(implode("\n", $out['sheets'])) ?></pre>
  </div>

  <?php if ($debug): ?>
    <div class="box">
      <h2>🔍 Shared Strings: всего <?= (int)$debug['shared_count'] ?></h2>
      <p style="color:#94a3b8;font-size:13px;margin:0 0 8px">
        Если тут 0 — значит тексты хранятся не в sharedStrings, а как inline strings,
        либо sharedStrings.xml повреждён.
      </p>
      <pre><?= htmlspecialchars(print_r($debug['shared_sample'], true)) ?></pre>
    </div>
  <?php endif; ?>

  <?php if (!empty($out['sheets_data'])): ?>
    <?php foreach ($out['sheets_data'] as $name => $d): ?>
      <div class="box">
        <h2>Лист: <?= htmlspecialchars($name) ?> — строк: <?= (int)$d['row_count'] ?></h2>
        <div style="margin-bottom:8px;color:#94a3b8;font-size:13px">Все строки (только непустые ячейки):</div>
        <pre><?= htmlspecialchars(print_r($d['all_rows'], true)) ?></pre>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>
</body>
</html>
