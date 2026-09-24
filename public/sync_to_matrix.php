<?php
require __DIR__ . '/db.php';
require __DIR__ . '/xlsx_reader.php';
start_session();
$user = current_user();

$pageTitle    = 'Импорт матриц ТО';
$pageSubtitle = 'КАМАЗ · XLSX';
$backLink     = 'index.html';
$backLabel    = 'На рабочее место';

// Автосоздание таблиц
$db = get_db();
$db->exec("
CREATE TABLE IF NOT EXISTS to_matrices (
    id             SERIAL PRIMARY KEY,
    complectation  VARCHAR(100) UNIQUE NOT NULL,
    title          VARCHAR(500),
    brand          VARCHAR(50) DEFAULT 'KAMAZ',
    source_file    VARCHAR(300),
    imported_at    TIMESTAMPTZ DEFAULT NOW()
)");
$db->exec("
CREATE TABLE IF NOT EXISTS to_norms (
    id          SERIAL PRIMARY KEY,
    matrix_id   INT NOT NULL REFERENCES to_matrices(id) ON DELETE CASCADE,
    to_code     VARCHAR(20) NOT NULL,
    norm_hours  NUMERIC(10,3),
    UNIQUE(matrix_id, to_code)
)");
$db->exec("
CREATE TABLE IF NOT EXISTS to_items (
    id          SERIAL PRIMARY KEY,
    matrix_id   INT NOT NULL REFERENCES to_matrices(id) ON DELETE CASCADE,
    row_num     INT,
    name        VARCHAR(500) NOT NULL,
    article     VARCHAR(200),
    unit        VARCHAR(50),
    quantities  JSONB NOT NULL DEFAULT '{}'::jsonb
)");

/**
 * "1 раз в 4 года (В4)" → V4, "Периодическое техническое обслуживание (ПТО)" → PTO, и т.д.
 */
function to_label_to_code(string $label): ?string
{
    $l = mb_strtolower(trim($label), 'UTF-8');
    if ($l === '') return null;

    if (mb_strpos($l, 'предварительно-заключительные') !== false) return 'PZ';
    if (mb_strpos($l, 'периодическое техническое')      !== false) return 'PTO';
    if (preg_match('/\b2\s*то\b/u', $l))                          return 'A2';
    if (preg_match('/\b3\s*то\b/u', $l))                          return 'A3';
    if (mb_strpos($l, '3тод') !== false)                          return 'ZTOD';
    if (mb_strpos($l, 'тод')  !== false)                          return 'TOD';
    if (mb_strpos($l, 'ток')  !== false)                          return 'TOK';
    if (mb_strpos($l, 'том')  !== false)                          return 'TOM';
    if (mb_strpos($l, '4 года') !== false)                        return 'V4';
    if (mb_strpos($l, '3 года') !== false)                        return 'V3';
    if (mb_strpos($l, '2 года') !== false)                        return 'V2';
    if (mb_strpos($l, 'раз в год') !== false)                     return 'V';
    if (mb_strpos($l, 'мойка') !== false)                         return 'WASH';
    return null;
}

$flash = null;
$report = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['xlsx']['tmp_name'])) {
    try {
        $reader = new XlsxReader($_FILES['xlsx']['tmp_name']);
        $fileName = $_FILES['xlsx']['name'] ?? 'upload.xlsx';

        foreach ($reader->sheetNames() as $sheetName) {
            $rows = $reader->readSheet($sheetName);
            if (!$rows) continue;

            // Заголовок в строке 1 — извлекаем комплектацию
            $title = trim($rows[1]['A'] ?? '');
            $complectation = null;
            if (preg_match('/КАМАЗ\s+([A-Z0-9\-]+)/u', $title, $m)) {
                $complectation = $m[1];
            } else {
                // fallback — из имени листа
                $complectation = preg_replace('/^ТО\s+/u', '', $sheetName);
            }
            if (!$complectation) continue;

            // Найти строку-заголовок ТО и строку норм
            $headerRow = null; $normRow = null; $dataHeaderRow = null;
            foreach ($rows as $num => $cells) {
                $a = trim($cells['A'] ?? '');
                if ($a === 'Наименование показателя' && $headerRow === null) $headerRow = $num;
                if ($a === 'Нормативная трудоёмкость') $normRow = $num;
                if ($a === '№ п/п') $dataHeaderRow = $num;
            }
            if (!$headerRow || !$dataHeaderRow) continue;

            // Карта колонок → код ТО
            $colToCode = [];
            foreach (($rows[$headerRow] ?? []) as $col => $label) {
                if (in_array($col, ['A','B','C','D'])) continue;
                $code = to_label_to_code((string)$label);
                if ($code) $colToCode[$col] = $code;
            }
            if (!$colToCode) continue;

            // Нормо-часы
            $norms = [];
            if ($normRow) {
                foreach ($colToCode as $col => $code) {
                    $val = $rows[$normRow][$col] ?? '';
                    $val = str_replace(',', '.', trim((string)$val));
                    if ($val !== '' && is_numeric($val)) {
                        $norms[$code] = (float)$val;
                    }
                }
            }

            // Данные (строки с № п/п после dataHeaderRow + 1 «к-во»)
            $items = [];
            $skippedQtyRow = false;
            foreach ($rows as $num => $cells) {
                if ($num <= $dataHeaderRow) continue;
                $a = trim($cells['A'] ?? '');

                // Первая строка после шапки — "к-во" (в колонке E). Пропускаем её.
                if (!$skippedQtyRow && $a === '') {
                    $skippedQtyRow = true;
                    continue;
                }
                if (!is_numeric($a)) continue;

                $name    = trim((string)($cells['B'] ?? ''));
                $article = trim((string)($cells['C'] ?? ''));
                $unit    = trim((string)($cells['D'] ?? ''));
                if ($name === '') continue;

                $qty = [];
                foreach ($colToCode as $col => $code) {
                    $val = str_replace(',', '.', trim((string)($cells[$col] ?? '')));
                    if ($val !== '' && is_numeric($val)) {
                        $q = (float)$val;
                        if ($q > 0) $qty[$code] = $q;
                    }
                }
                $items[] = [
                    'row_num'    => (int)$a,
                    'name'       => $name,
                    'article'    => $article,
                    'unit'       => $unit,
                    'quantities' => $qty,
                ];
            }

            // Записать в БД (транзакция на матрицу)
            $db->beginTransaction();
            try {
                // Upsert матрицы
                $st = $db->prepare("
                    INSERT INTO to_matrices (complectation, title, source_file)
                    VALUES (:c, :t, :f)
                    ON CONFLICT (complectation) DO UPDATE
                      SET title = EXCLUDED.title,
                          source_file = EXCLUDED.source_file,
                          imported_at = NOW()
                    RETURNING id
                ");
                $st->execute([':c' => $complectation, ':t' => $title, ':f' => $fileName]);
                $matrixId = (int)$st->fetchColumn();

                // Чистим старое
                $db->prepare("DELETE FROM to_norms WHERE matrix_id = ?")->execute([$matrixId]);
                $db->prepare("DELETE FROM to_items WHERE matrix_id = ?")->execute([$matrixId]);

                // Нормы
                $stN = $db->prepare("INSERT INTO to_norms (matrix_id, to_code, norm_hours) VALUES (?,?,?)");
                foreach ($norms as $code => $h) {
                    $stN->execute([$matrixId, $code, $h]);
                }

                // Строки
                $stI = $db->prepare("
                    INSERT INTO to_items (matrix_id, row_num, name, article, unit, quantities)
                    VALUES (?,?,?,?,?,?::jsonb)
                ");
                foreach ($items as $it) {
                    $stI->execute([
                        $matrixId,
                        $it['row_num'],
                        $it['name'],
                        $it['article'],
                        $it['unit'],
                        json_encode($it['quantities'], JSON_UNESCAPED_UNICODE),
                    ]);
                }
                $db->commit();

                $report[] = [
                    'sheet'         => $sheetName,
                    'complectation' => $complectation,
                    'items'         => count($items),
                    'norms'         => count($norms),
                ];
            } catch (Throwable $e) {
                $db->rollBack();
                $report[] = ['sheet' => $sheetName, 'error' => $e->getMessage()];
            }
        }
        $flash = 'Импорт завершён. Обработано листов: ' . count($report);
    } catch (Throwable $e) {
        $flash = 'Ошибка: ' . $e->getMessage();
    }
}

// Текущий список матриц
$matrices = $db->query("
    SELECT m.*,
           (SELECT COUNT(*) FROM to_items WHERE matrix_id = m.id) AS items_count
      FROM to_matrices m
     ORDER BY m.complectation
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="app.css">

<div class="container" style="max-width:1100px;margin:0 auto;padding:24px">

  <?php if ($flash): ?>
    <div class="flash" style="margin-bottom:18px;padding:14px 18px;border-radius:12px;background:rgba(219,234,254,0.85)">
      <?= e($flash) ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data"
        style="background:rgba(255,255,255,0.8);padding:22px 24px;border-radius:18px;margin-bottom:22px">
    <h2 style="margin:0 0 10px;font-size:18px">Загрузить матрицы ТО (XLSX)</h2>
    <p style="margin:0 0 14px;color:#64748b;font-size:14px">
      Один файл — все листы сразу. Каждый лист = одна комплектация.
      Если комплектация уже есть в базе — данные перезапишутся.
    </p>
    <input type="file" name="xlsx" accept=".xlsx" required>
    <button type="submit"
            style="margin-left:10px;padding:10px 18px;border:none;border-radius:10px;background:#2563eb;color:#fff;font-weight:600;cursor:pointer">
      Импортировать
    </button>
  </form>

  <?php if ($report): ?>
    <div style="background:rgba(255,255,255,0.8);padding:18px 22px;border-radius:16px;margin-bottom:22px">
      <h3 style="margin:0 0 10px;font-size:16px">Отчёт по листам</h3>
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        <tr style="text-align:left;color:#64748b"><th>Лист</th><th>Комплектация</th><th>Строк</th><th>Норм</th></tr>
        <?php foreach ($report as $r): ?>
          <tr>
            <td><?= e($r['sheet'] ?? '') ?></td>
            <td><?= e($r['complectation'] ?? '') ?></td>
            <td><?= isset($r['items']) ? (int)$r['items'] : '—' ?></td>
            <td><?= isset($r['norms']) ? (int)$r['norms'] : '—' ?></td>
          </tr>
          <?php if (!empty($r['error'])): ?>
            <tr><td colspan="4" style="color:#b91c1c">Ошибка: <?= e($r['error']) ?></td></tr>
          <?php endif; ?>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>

  <div style="background:rgba(255,255,255,0.8);padding:18px 22px;border-radius:16px">
    <h3 style="margin:0 0 10px;font-size:16px">Матрицы в базе (<?= count($matrices) ?>)</h3>
    <?php if (!$matrices): ?>
      <p style="color:#64748b">Пока пусто. Загрузи XLSX выше.</p>
    <?php else: ?>
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        <tr style="text-align:left;color:#64748b">
          <th>Комплектация</th><th>Строк</th><th>Импорт</th><th></th>
        </tr>
        <?php foreach ($matrices as $m): ?>
          <tr>
            <td><b><?= e($m['complectation']) ?></b></td>
            <td><?= (int)$m['items_count'] ?></td>
            <td><?= e($m['imported_at']) ?></td>
            <td><a href="to_calculator.php?c=<?= urlencode($m['complectation']) ?>">Открыть →</a></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

</div>

</body>
</html>
