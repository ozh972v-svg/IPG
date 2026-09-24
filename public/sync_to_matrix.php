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

// ============ ОЧИСТКА БАЗЫ ============
$cleared = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_all') {
    $db->exec("TRUNCATE to_matrices RESTART IDENTITY CASCADE");
    $cleared = 'База матриц очищена';
}

function norm(string $s): string
{
    $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF"], ' ', $s);
    $s = preg_replace('/\s+/u', ' ', $s);
    return mb_strtolower(trim($s), 'UTF-8');
}

function to_label_to_code(string $label): ?string
{
    $l = norm($label);
    if ($l === '') return null;

    $exact = [
        'мойка' => 'WASH', 'мойка а/м' => 'WASH',
        'в' => 'V', 'в2' => 'V2', 'в3' => 'V3', 'в4' => 'V4',
        'а2' => 'A2', 'а3' => 'A3',
        'то' => 'TO', 'сто' => 'STO',
        'тобм' => 'TOBM', '2тобм' => '2TOBM', '2тобмг' => '2TOBMG',
        'тод' => 'TOD', '3тод' => 'ZTOD',
        'ток' => 'TOK', 'том' => 'TOM',
    ];
    if (isset($exact[$l])) return $exact[$l];

    if (mb_strpos($l, 'предварительно-заключительные') !== false) return 'PZ';
    if (mb_strpos($l, 'периодическое техническое')      !== false) return 'PTO';
    if (preg_match('/\bто[-\s]?2500\b/u', $l))                    return 'TO2500';
    if (mb_strpos($l, '2тобмг') !== false)                        return '2TOBMG';
    if (mb_strpos($l, '2тобм')  !== false)                        return '2TOBM';
    if (preg_match('/(^|[^0-9a-zа-я])тобм/u', $l))                return 'TOBM';
    if (mb_strpos($l, '3тод') !== false)                          return 'ZTOD';
    if (preg_match('/(^|[^0-9a-zа-я])тод/u', $l))                 return 'TOD';
    if (preg_match('/(^|[^0-9a-zа-я])ток/u', $l))                 return 'TOK';
    if (preg_match('/(^|[^0-9a-zа-я])том/u', $l))                 return 'TOM';
    if (preg_match('/\(а2\)/u', $l))                              return 'A2';
    if (preg_match('/\(а3\)/u', $l))                              return 'A3';
    if (preg_match('/\b2\s*то\b/u', $l))                          return 'A2';
    if (preg_match('/\b3\s*то\b/u', $l))                          return 'A3';
    if (mb_strpos($l, '4 года') !== false)                        return 'V4';
    if (mb_strpos($l, '3 года') !== false)                        return 'V3';
    if (mb_strpos($l, '2 года') !== false)                        return 'V2';
    if (mb_strpos($l, 'раз в год') !== false)                     return 'V';
    return null;
}

function extract_model(string $c): ?string
{
    if (preg_match('/([0-9]{5})/', $c, $m)) return $m[1];
    return null;
}

/**
 * Разбирает строку со списком кодов: "54902-760-В5, 762-В5, 768-В5"
 * → ["54902-760-В5", "54902-762-В5", "54902-768-В5"]
 */
function parse_code_list(string $list): array
{
    $parts = preg_split('/\s*,\s*/u', $list);
    $codes = [];
    $model = null;

    foreach ($parts as $part) {
        $part = trim($part);
        if ($part === '') continue;

        // Полный код: 5 цифр + дефис + что-то
        if (preg_match('/^([0-9]{5})([-0-9A-ZА-Я]+)/u', $part, $m)) {
            $model = $m[1];
            $codes[] = $m[1] . $m[2];
        }
        // Короткий суффикс: "762-B5" или "0070014-CA"
        elseif (preg_match('/^([0-9]{3,6}[-0-9A-ZА-Я]+)/u', $part, $m)) {
            $suffix = $m[0];
            if ($model && strpos($suffix, $model) !== 0) {
                $codes[] = $model . '-' . $suffix;
            } else {
                $codes[] = $suffix;
            }
        }
    }
    return $codes;
}

/**
 * Определяет список комплектаций для листа.
 * @return string[]
 */
function detect_complectations(string $sheetName, string $a1): array
{
    $codes = [];

    // 1) A1 содержит "КАМАЗ <list>"
    if ($a1 !== '' && preg_match('/КАМАЗ\s+(.+)$/u', $a1, $m)) {
        $list = trim($m[1]);
        // Обрезаем описательные хвосты
        $list = preg_replace('/(\s+семейств\w+|\s+с\s+дв\.).*$/u', '', $list);
        $codes = parse_code_list($list);
    }

    // 2) Имя листа содержит "КАМАЗ <list>"
    if (empty($codes) && preg_match('/КАМАЗ\s+(.+)$/u', $sheetName, $m)) {
        $codes = parse_code_list(trim($m[1]));
    }

    // 3) Разбираем имя листа как список
    if (empty($codes)) {
        $sheetClean = preg_replace('/^\s*ТО[-\s]*/u', '', trim($sheetName));
        $codes = parse_code_list($sheetClean);
    }

    // 4) Fallback — имя листа как есть
    if (empty($codes)) {
        $s = trim($sheetName);
        if ($s !== '') $codes = [$s];
    }

    return array_values(array_unique(array_filter($codes)));
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

            $complectations = detect_complectations($sheetName, $a1);
            $dbg['complectations'] = $complectations;
            if (empty($complectations)) {
                $dbg['error'] = 'не определены комплектации';
                $debug[] = $dbg;
                continue;
            }

            // --- Ищем ключевые строки в первых 15 строках ---
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
                $dbg['error'] = 'не найдены заголовки';
                $debug[] = $dbg;
                continue;
            }

            // --- Карта колонок ТО ---
            $colToCode = [];
            foreach (($rows[$headerRow] ?? []) as $col => $label) {
                if (in_array($col, ['A','B','C','D'])) continue;
                $code = to_label_to_code((string)$label);
                if ($code) $colToCode[$col] = $code;
            }

            // Fallback для одного ТО типа (ТО-2500)
            if (empty($colToCode)) {
                $lookup = $a1 . ' ' . $sheetName;
                if (preg_match('/ТО[-\s]?(\d+)/ui', $lookup, $mm)) {
                    $code = 'TO' . $mm[1];
                    foreach (($rows[$dataHeaderRow] ?? []) as $col => $v) {
                        if (in_array($col, ['A','B','C','D'])) continue;
                        if (trim((string)$v) !== '') {
                            $colToCode[$col] = $code;
                            break;
                        }
                    }
                }
            }

            $dbg['col_map'] = $colToCode;
            if (!$colToCode) { $dbg['error'] = 'не распознаны колонки ТО'; $debug[] = $dbg; continue; }

            // --- Нормы ---
            $norms = [];
            if ($normRow) {
                foreach ($colToCode as $col => $code) {
                    $val = str_replace(',', '.', trim((string)($rows[$normRow][$col] ?? '')));
                    if ($val !== '' && is_numeric($val)) $norms[$code] = (float)$val;
                }
            }

            // --- Данные ---
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
                    'row_num' => (int)$a, 'name' => $name,
                    'article' => $article, 'unit' => $unit, 'quantities' => $qty,
                ];
            }

            // --- Сохраняем ОДНУ матрицу на КАЖДУЮ комплектацию ---
            $savedCount = 0;
            foreach ($complectations as $c) {
                $model = extract_model($c);
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
                    $st->execute([':c' => $c, ':m' => $model, ':t' => $a1, ':f' => $fileName]);
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
                            $matrixId, $it['row_num'], $it['name'],
                            $it['article'], $it['unit'],
                            json_encode($it['quantities'], JSON_UNESCAPED_UNICODE),
                        ]);
                    }
                    $db->commit();
                    $savedCount++;
                    $report[] = [
                        'sheet' => $sheetName, 'complectation' => $c,
                        'model' => $model, 'items' => count($items), 'norms' => count($norms),
                    ];
                } catch (Throwable $e) {
                    $db->rollBack();
                    $dbg['error_' . $c] = 'БД: ' . $e->getMessage();
                }
            }
            $dbg['saved_matrices'] = $savedCount;
            $debug[] = $dbg;
        }
        $flash = 'Импорт завершён. Сохранено матриц: ' . count($report);
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

  <?php if ($cleared): ?>
    <div style="margin-bottom:18px;padding:14px 18px;border-radius:12px;background:rgba(254,226,226,0.85);color:#991b1b">
      🗑 <?= e($cleared) ?>
    </div>
  <?php endif; ?>

  <?php if ($flash): ?>
    <div style="margin-bottom:18px;padding:14px 18px;border-radius:12px;background:rgba(219,234,254,0.85)">
      <?= e($flash) ?>
    </div>
  <?php endif; ?>

  <form method="post" enctype="multipart/form-data"
        style="background:rgba(255,255,255,0.8);padding:22px 24px;border-radius:18px;margin-bottom:22px">
    <h2 style="margin:0 0 10px;font-size:18px">Загрузить матрицы ТО (XLSX)</h2>
    <p style="margin:0 0 14px;color:#64748b;font-size:14px">
      Один лист может содержать несколько комплектаций через запятую (например, <i>54902-760-В5, 762-В5, 768-В5</i>) — будут созданы отдельные матрицы. Повторный импорт той же комплектации перезаписывает её.
    </p>
    <input type="file" name="xlsx" accept=".xlsx" required>
    <button type="submit"
            style="margin-left:10px;padding:10px 18px;border:none;border-radius:10px;background:#2563eb;color:#fff;font-weight:600;cursor:pointer">
      Импортировать
    </button>
  </form>

  <form method="post" onsubmit="return confirm('Удалить ВСЕ матрицы из базы? Это действие необратимо. Потребуется заново импортировать все XLSX-файлы.');"
        style="background:rgba(254,226,226,0.5);padding:16px 22px;border-radius:16px;margin-bottom:22px;border:1px solid rgba(239,68,68,0.3)">
    <input type="hidden" name="action" value="clear_all">
    <div style="display:flex;justify-content:space-between;align-items:center;gap:16px;flex-wrap:wrap">
      <div>
        <div style="font-weight:600;font-size:14.5px;color:#991b1b">⚠️ Полная очистка базы матриц</div>
        <div style="font-size:13px;color:#7f1d1d;margin-top:2px">
          Удалит все матрицы, нормы и строки. Используйте, если надо переимпортировать всё заново.
        </div>
      </div>
      <button type="submit"
              style="padding:10px 18px;border:none;border-radius:10px;background:#dc2626;color:#fff;font-weight:600;cursor:pointer;white-space:nowrap">
        🗑 Очистить базу
      </button>
    </div>
  </form>

  <?php if ($debug): ?>
    <details style="background:rgba(255,255,255,0.8);padding:14px 20px;border-radius:16px;margin-bottom:22px">
      <summary style="cursor:pointer;font-weight:600;font-size:14px">🔍 Диагностика (разверни)</summary>
      <pre style="background:#0b1220;color:#e2e8f0;padding:14px;border-radius:10px;overflow:auto;font-size:12px;margin-top:12px"><?= e(print_r($debug, true)) ?></pre>
    </details>
  <?php endif; ?>

  <?php if ($report): ?>
    <div style="background:rgba(220,252,231,0.7);padding:18px 22px;border-radius:16px;margin-bottom:22px">
      <h3 style="margin:0 0 10px;font-size:16px">✅ Сохранено (<?= count($report) ?>)</h3>
      <table style="width:100%;border-collapse:collapse;font-size:13.5px">
        <tr style="text-align:left;color:#64748b"><th>Лист</th><th>Комплектация</th><th>Модель</th><th>Строк</th><th>Норм</th></tr>
        <?php foreach ($report as $r): ?>
          <tr>
            <td style="font-size:12.5px;color:#64748b"><?= e($r['sheet']) ?></td>
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
