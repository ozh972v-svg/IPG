<?php
/**
 * Диагностика XLSX-парсера. Показывает сырые данные, которые видит XlsxReader.
 * Открывать: https://ipg.relaxdev.ru/to_debug.php
 * После отладки — можно удалить.
 */
require __DIR__ . '/xlsx_reader.php';
start_session();
$user = current_user();

$out = [];
$error = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['xlsx']['tmp_name'])) {
    try {
        $file = $_FILES['xlsx']['tmp_name'];
        $out['file_name'] = $_FILES['xlsx']['name'] ?? '';
        $out['file_size'] = filesize($file);

        $reader = new XlsxReader($file);
        $out['sheets'] = $reader->sheetNames();

        foreach ($out['sheets'] as $i => $name) {
            if ($i >= 2) break; // только первые 2 листа для наглядности
            $rows = $reader->readSheet($name);
            $out['sheets_data'][$name]['row_count'] = count($rows);
            $out['sheets_data'][$name]['first_15_rows'] = array_slice($rows, 0, 15, true);

            // Проверка regex на заголовке
            $title = trim($rows[1]['A'] ?? '');
            $out['sheets_data'][$name]['title_A1'] = $title;
            $m = [];
            if (preg_match('/КАМАЗ\s+([A-Z0-9\-]+)/u', $title, $m)) {
                $out['sheets_data'][$name]['complectation'] = $m[1];
            } else {
                $out['sheets_data'][$name]['complectation'] = '❌ regex не поймал';
            }
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
<title>Диагностика XLSX — IPG</title>
<style>
  body{font-family:-apple-system,system-ui,Arial;background:#0b1220;color:#e2e8f0;padding:24px;line-height:1.5}
  h1{font-size:20px;margin:0 0 14px}
  .box{background:#111827;border:1px solid #1f2937;border-radius:12px;padding:18px;margin-bottom:16px}
  .box h2{font-size:15px;margin:0 0 10px;color:#93c5fd}
  pre{background:#0b1220;border:1px solid #1f2937;padding:12px;border-radius:8px;overflow-x:auto;font-size:12.5px}
  label{display:block;margin-bottom:6px;color:#94a3b8;font-size:13px}
  input[type=file]{padding:8px;background:#1f2937;border-radius:8px;color:#e2e8f0}
  button{margin-top:10px;padding:10px 18px;background:#2563eb;color:#fff;border:none;border-radius:8px;font-weight:600;cursor:pointer}
  .err{background:#7f1d1d;border-color:#991b1b;padding:14px;border-radius:8px}
</style>
</head>
<body>
<h1>Диагностика XLSX-парсера</h1>

<div class="box">
  <form method="post" enctype="multipart/form-data">
    <label>Загрузи тот же XLSX-файл:</label>
    <input type="file" name="xlsx" accept=".xlsx" required>
    <button type="submit">Диагностировать</button>
  </form>
</div>

<?php if ($error): ?>
  <div class="box err">
    <h2>❌ Ошибка парсера</h2>
    <pre><?= htmlspecialchars($error) ?></pre>
  </div>
<?php endif; ?>

<?php if ($out): ?>
  <div class="box">
    <h2>Файл</h2>
    <pre><?= htmlspecialchars(print_r([
      'name' => $out['file_name'],
      'size' => $out['file_size'] . ' байт',
    ], true)) ?></pre>
  </div>

  <div class="box">
    <h2>Найденные листы (<?= count($out['sheets']) ?>)</h2>
    <pre><?= htmlspecialchars(print_r($out['sheets'], true)) ?></pre>
  </div>

  <?php if (!empty($out['sheets_data'])): ?>
    <?php foreach ($out['sheets_data'] as $name => $d): ?>
      <div class="box">
        <h2>Лист: <?= htmlspecialchars($name) ?></h2>
        <div>Строк: <b><?= (int)$d['row_count'] ?></b></div>
        <div>Заголовок в A1: <b><?= htmlspecialchars($d['title_A1']) ?></b></div>
        <div>Regex поймал: <b><?= htmlspecialchars($d['complectation']) ?></b></div>
        <div style="margin-top:10px;color:#94a3b8;font-size:13px">Первые 15 строк:</div>
        <pre><?= htmlspecialchars(print_r($d['first_15_rows'], true)) ?></pre>
      </div>
    <?php endforeach; ?>
  <?php endif; ?>
<?php endif; ?>
</body>
</html>
