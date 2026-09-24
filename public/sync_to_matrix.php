<?php
require __DIR__ . '/db.php';
require __DIR__ . '/xlsx_reader.php';
start_session();
$user = current_user();

$pageTitle    = 'Импорт матриц ТО';
$pageSubtitle = 'КАМАЗ · XLSX';
$backLink     = 'index.html';
$backLabel    = 'На рабочее место';

$db = get_db();
$db->exec("
CREATE TABLE IF NOT EXISTS to_matrices (
    id             SERIAL PRIMARY KEY,
    complectation  VARCHAR(150) UNIQUE NOT NULL,
    model          VARCHAR(50),
    title          VARCHAR(500),
    brand          VARCHAR(50) DEFAULT 'KAMAZ',
    source_file    VARCHAR(300),
    imported_at    TIMESTAMPTZ DEFAULT NOW()
)");
$db->exec("ALTER TABLE to_matrices ALTER COLUMN complectation TYPE VARCHAR(150)");
$db->exec("ALTER TABLE to_matrices ADD COLUMN IF NOT EXISTS model VARCHAR(50)");
$db->exec("
CREATE TABLE IF NOT EXISTS to_norms (
    id          SERIAL PRIMARY KEY,
    matrix_id   INT NOT NULL REFERENCES to_matrices(id) ON DELETE CASCADE,
    to_code     VARCHAR(30) NOT NULL,
    norm_hours  NUMERIC(10,3),
    UNIQUE(matrix_id, to_code)
)");
$db->exec("ALTER TABLE to_norms ALTER COLUMN to_code TYPE VARCHAR(30)");
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

function norm(string $s): string
{
    $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return mb_strtolower(trim($s), 'UTF-8');
}

/**
 * Распознаёт вид ТО по заголовку колонки. Поддерживает старые и новые обозначения.
 */
function to_label_to_code(string $label): ?string
{
    $l = norm($label);
    if ($l === '') return null;

    // --- Точные совпадения ---
    $exact = [
        'мойка' => 'WASH',
        'мойка а/м' => 'WASH',
        'в' => 'V', 'в2' => 'V2', 'в3' => 'V3', 'в4' => 'V4',
        'а2' => 'A2', 'а3' => 'A3',
        'то' => 'TO',
        'сто' => 'STO',
        'тобм' => 'TOBM',
        '2тобм' => '2TOBM',
        '2тобмг' => '2TOBMG',
        'тод' => 'TOD', '3тод' => 'ZTOD',
        'ток' => 'TOK', 'том' => 'TOM',
    ];
    if (isset($exact[$l])) return $exact[$l];

    // --- Специфичные ---
    if (mb_strpos($l, 'предварительно-заключительные') !== false) return 'PZ';
    if (mb_strpos($l, 'периодическое техническое')      !== false) return 'PTO';

    // ТО-2500 (старые КАМАЗы)
    if (preg_match('/\bто[-\s]?2500\b/u', $l)) return 'TO2500';

    // 2ТОбмг / 2ТОбм / ТОбм  (порядок важен: специфичные раньше)
    if (mb_strpos($l, '2тобмг') !== false) return '2TOBMG';
    if (mb_strpos($l, '2тобм')  !== false) return '2TOBM';
    if (preg_match('/(^|[^0-9a-zа-я])тобм/u', $l)) return 'TOBM';

    // 3ТОд / ТОд
    if (mb_strpos($l, '3тод') !== false) return 'ZTOD';
    if (preg_match('/(^|[^0-9a-zа-я])тод/u', $l)) return 'TOD';

    // ТОк / ТОм
    if (preg_match('/(^|[^0-9a-zа-я])ток/u', $l)) return 'TOK';
    if (preg_match('/(^|[^0-9a-zа-я])том/u', $l)) return 'TOM';

    // А2 / А3 в разных формулировках
    if (preg_match('/\(а2\)/u', $l)) return 'A2';
    if (preg_match('/\(а3\)/u', $l)) return 'A3';
    if (preg_match('/\b2\s*то\b/u', $l)) return 'A2';
    if (preg_match('/\b3\s*то\b/u', $l)) return 'A3';

    // В / В2 / В3 / В4 в формулировках
    if (mb_strpos($l, '4 года') !== false) return 'V4';
    if (mb_strpos($l, '3 года') !== false) return 'V3';
    if (mb_strpos($l, '2 года') !== false) return 'V2';
    if (mb_strpos($l, 'раз в год') !== false) return 'V';

    return null;
}

/** Извлекает модель из complectation: 54901-0070004-CA → 54901 */
function extract_model(string $c): ?string
{
    if (preg_match('/([0-9]{5})/', $c, $m)) return $m[1];
    return null;
}

/** Определяет complectation из заголовка листа/A1 */
function detect_complectation(string $sheetName, string $a1): array
{
    // 1) Пробуем найти код вида 54901-0070004-CA в A1
    if (preg_match('/КАМАЗ\s+([0-9A-ZА-Я][0-9A-ZА-Я\-]{4,40})/u', $a1, $m)) {
        $code = trim($m[1], '-');
        if (preg_match('/[0-9]/', $code)) {
            return ['complectation' => $code, 'model' => extract_model($code)];
        }
    }
    // 2) Код прямо в A1 без "КАМАЗ"
    if (preg_match('/([0-9]{5}-[0-9A-ZА-Я\-]+)/u', $a1, $m)) {
        return ['complectation' => $m[1], 'model' => extract_model($m[1])];
    }
    // 3) Fallback: имя листа
    $c = trim($sheetName);
    return ['complectation' => $c, 'model' => extract_model($c)];
}

$flash = null;
$report = [];
$debug = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['xlsx']['tmp_name'])) {
    try {
        $reader = new XlsxReader($_FILES['xlsx']['tmp_name']);
        $fileName = $_FILES['xlsx']['name'] ?? 'upload.xlsx';

        foreach ($reader->sheetNames() as $sheetName) {
            $rows = $reader->readSheet($sheetName);
            $dbg = ['sheet' => $sheetName, 'row_count' => count($rows)];
            if (!$rows) { $dbg['error'] = 'пустой лист'; $debug[] = $dbg; continue; }

            $a1 = trim((string)($rows[1]['A'] ?? ''));
            $dbg['a1'] = $a1;

            $detected = detect_complectation($sheetName, $a1);
            $complectation = $detected['complectation'];
            $model = $detected['model'];
            $dbg['complectation'] = $complectation;
            $dbg['model'] = $model;

            // --- Ищем ключевые строки в ЛЮБОЙ колонке, в первых 15 строках ---
            $headerRow = null; $normRow = null; $dataHeaderRow = null;
            foreach ($rows as $num => $cells) {
                if ($num > 15) break;
                foreach ($cells as $v) {
                    $vn = norm((string)$v);
                    if ($vn === '') continue;
                    if ($headerRow === null && mb_strpos($vn, 'наименование показателя') !== false) $headerRow = $num;
                    if ($normRow === null && mb_strpos($vn, 'нормативная трудоёмкость') !== false) $normRow = $num;
                    if ($dataHeaderRow === null && mb_strpos($vn, 'п/п') !== false) $dataHeaderRow = $num;
                }
            }
            $dbg['header_row'] = $headerRow;
            $dbg['norm_row'] = $normRow;
            $dbg['data_header_row'] = $dataHeaderRow;
            if (!$headerRow || !$dataHeaderRow) {
                $dbg['error'] = 'не найдены заголовки (нужны строки "Наименование показателя" и "№ п/п")';
                $debug[] = $dbg;
                continue;
            }

            // --- Карта колонок ТО (в строке "Наименование показателя") ---
            $colToCode = [];
            foreach (($rows[$headerRow] ?? []) as $col => $label) {
                if (in_array($col, ['A','B','C','D'])) continue;
                $code = to_label_to_code((string)$label);
                if ($code) $colToCode[$col] = $code;
            }

            // --- Fallback: если не распознали ни одну колонку, но в A1/имени листа есть "ТО-NNNN" ---
            if (empty($colToCode)) {
                $lookup = $a1 . ' ' . $sheetName;
                if (preg_match('/ТО[-\s]?(\d+)/ui', $lookup, $mm)) {
                    $code = 'TO' . $mm[1];
                    // Первая колонка после D, у которой есть значение в dataHeaderRow
                    foreach (($rows[$dataHeaderRow] ?? []) as $col => $v) {
                        if (in_array($col, ['A','B','C','D'])) continue;
                        if (trim((string)$v) !== '') {
                            $colToCode[$col] = $code;
                            break;
                        }
                    }
                    $dbg['fallback_code'] = $code;
                }
            }

            $dbg['col_map'] = $colToCode;
            if (!$colToCode) {
                $dbg['error'] = 'не распознаны колонки ТО';
                $debug[] = $dbg;
                continue;
            }

            // --- Нормо-часы ---
            $norms = [];
            if ($normRow) {
                foreach ($colToCode as $col => $code) {
                    $val = str_replace(',', '.', trim((string)($rows[$normRow][$col] ?? '')));
                    if ($val !== '' && is_numeric($val)) {
                        $norms[$code] = (float)$val;
                    }
                }
            }
            $dbg['norms'] = $norms;

            // --- Данные: начинаем с первой строки после dataHeaderRow, где A — число ---
            $items = [];
            foreach ($rows as $num => $cells) {
                if ($num <= $dataHeaderRow) continue;
                $a = trim((string)($cells['A'] ?? ''));
                if ($a === '' || !is_numeric($a)) continue;

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
                    'row_num' => (int)$a,
                    'name' => $name,
                    'article' => $article,
                    'unit' => $unit,
                    'quantities' => $qty,
                ];
            }
            $dbg['items_count'] = count($items);

            // --- Сохранение ---
            $db->beginTransaction();
            try {
                $st = $db->prepare("
                    INSERT INTO to_matrices (complectation, model, title, source_file)
                    VALUES (:c, :m, :t, :f)
                    ON CONFLICT (complectation) DO UPDATE
                      SET model = EXCLUDED.model,
                          title = EXCLUDED.title,
                          source_file = EXCLUDED.source_file,
                          imported_at = NOW()
                    RETURNING id
                ");
                $st->execute([':c' => $complectation, ':m' => $model, ':t' => $a1, ':f' => $fileName]);
                $matrixId = (int)$st->fetchColumn();

                $db->prepare("DELETE FROM to_norms WHERE matrix_id = ?")->execute([$matrixId]);
                $db->prepare("DELETE FROM to_items WHERE matrix_id = ?")->execute([$matrixId]);

                $stN = $db->prepare("INSERT INTO to_norms (matrix_id, to_code, norm_hours) VALUES (?,?,?)");
                foreach ($norms as $code => $h) $stN->execute([$matrixId, $code, $h]);

                $stI = $db->prepare("
                    INSERT INTO to_items (matrix_id, row_num, name, article, unit, quantities)
                    VALUES (?,?,?,?,?,?::jsonb)
                ");
                foreach ($items as $it) {
                    $stI->execute([
                        $matrixId, $it['row_num'], $it['name'], $it['article'], $it['unit'],
                        json_encode($it['quantities'], JSON_UNESCAPED_UNICODE),
                    ]);
                }
                $db->commit();
                $dbg['saved'] = true;
                $report[] = [
                    'sheet' => $sheetName,
                    'complectation' => $complectation,
                    'model' => $model,
                    'items' => count($items),
                    'norms' => count($norms),
                ];
            } catch (Throwable $e) {
                $db->rollBack();
                $dbg['error'] = 'БД: ' . $e->getMessage();
            }
            $debug[] = $dbg;
        }
        $flash = 'Импорт завершён. Листов: ' . count($reader->sheetNames()) . ', сохранено матриц: ' . count($report);
    } catch (Throwable $e) {
        $flash = 'Ошибка: ' . $e->getMessage();
    }
}

$matrices = $db->query("
    SELECT m.*, (SELECT COUNT(*) FROM to_items WHERE matrix_id = m.id) AS items_count
      FROM to_matrices m
     ORDER BY m.model NULLS LAST, m.complectation
")->fetchAll(PDO::FETCH_ASSOC);

include __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="app.css">

<div class="container" style="max-width:1100px;margin:0 auto;padding:24px">

  <?php if ($flash): ?>
    <div style="margin-bottom:18px;padding:14px 18px;border-radius:12px;background:rgba(219,234,254,0.85)">
      <?= e($flash) ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data"
        style="background:rgba(255,255,255,0.8);padding:22px 24px;border-radius:18px;margin-bottom:22px">
    <h2 style="margin:0 0 10px;font-size:18px">Загрузить матрицы ТО (XLSX)</h2>
    <p style="margin:0 0 14px;color:#64748b;font-size:14px">
      Один файл — все листы сразу. Каждый лист = одна комплектация. Если комплектация уже есть — перезапишется.
      Поддерживаются разные структуры: <i>54901-0070004-CA</i>, <i>65115-48 самосвал</i>, <i>ТО-2500 63501</i>.
    </p>
    <input type="file" name="xlsx" accept=".xlsx" required>
    <button type="submit"
            style="margin-left:10px;padding:10px 18px;border:none;border-radius:10px;background:#2563eb;color:#fff;font-weight:600;cursor:pointer">
      Импортировать
    </button>
  </form>

  <?php if ($debug): ?>
    <details style="background:rgba(255,255,255,0.8);padding:14px 20px;border-radius:16px;margin-bottom:22px">
      <summary style="cursor:pointer;font-weight:600;font-size:14px">🔍 Диагностика (разверни)</summary>
      <pre style="background:#0b1220;color:#e2e8f0;padding:14px;border-radius:10px;overflow:auto;font-size:12px;margin-top:12px"><?= e(print_r($debug, true)) ?></pre>
    </details>
  <?php endif; ?>

  <?php if ($report): ?>
    <div style="background:rgba(220,252,231,0.7);padding:18px 22px;border-radius:16px;margin-bottom:22px">
      <h3 style="margin:0 0 10px;font-size:16px">✅ Сохранено</h3>
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        <tr style="text-align:left;color:#64748b"><th>Лист</th><th>Комплектация</th><th>Модель</th><th>Строк</th><th>Норм</th></tr>
        <?php foreach ($report as $r): ?>
          <tr>
            <td><?= e($r['sheet']) ?></td>
            <td><b><?= e($r['complectation']) ?></b></td>
            <td><?= e($r['model'] ?? '—') ?></td>
            <td><?= (int)$r['items'] ?></td>
            <td><?= (int)$r['norms'] ?></td>
          </tr>
        <?php endforeach; ?>
      </table>
    </div>
  <?php endif; ?>

  <div style="background:rgba(255,255,255,0.8);padding:18px 22px;border-radius:16px">
    <h3 style="margin:0 0 10px;font-size:16px">Матрицы в базе (<?= count($matrices) ?>)</h3>
    <?php if (!$matrices): ?>
      <p style="color:#64748b">Пока пусто.</p>
    <?php else: ?>
      <table style="width:100%;border-collapse:collapse;font-size:14px">
        <tr style="text-align:left;color:#64748b">
          <th>Модель</th><th>Комплектация</th><th>Строк</th><th>Импорт</th><th></th>
        </tr>
        <?php foreach ($matrices as $m): ?>
          <tr>
            <td><b><?= e($m['model'] ?? '—') ?></b></td>
            <td><?= e($m['complectation']) ?></td>
            <td><?= (int)$m['items_count'] ?></td>
            <td style="font-size:12.5px;color:#64748b"><?= e($m['imported_at']) ?></td>
            <td><a href="to_calculator.php?c=<?= urlencode($m['complectation']) ?>">Открыть →</a></td>
          </tr>
        <?php endforeach; ?>
      </table>
    <?php endif; ?>
  </div>

</div>

</body>
</html>
