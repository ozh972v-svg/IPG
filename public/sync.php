<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user || !$user['is_admin']) { header('Location: index.html'); exit; }

$pdo = get_db();
$messages = [];
$error = null;

/**
 * Определяет индексы колонок по заголовкам.
 * Заголовок ищется в первых 5 строках файла (там есть «Норма времени», «Модель шасси», ...).
 */
function find_header_row(array $lines): ?int {
    foreach ($lines as $i => $line) {
        $cols = explode("\t", $line);
        $first = trim($cols[0] ?? '');
        // Шапка таблицы начинается со слова «Норма времени»
        if ($first === 'Норма времени') return $i;
    }
    return null;
}

/**
 * Определяет индексы нужных колонок по строке заголовка.
 */
function map_columns(string $headerLine): array {
    $cols = array_map('trim', explode("\t", $headerLine));
    $map = [];
    $parentIdx = 0;

    foreach ($cols as $i => $title) {
        if ($title === 'Комплектация')       $map['complectation'] = $i;
        elseif ($title === 'Код операции')   $map['op_code'] = $i;
        elseif ($title === 'Операция')       $map['name'] = $i;
        elseif ($title === 'Трудоемкость')   $map['norm'] = $i;
        elseif ($title === 'Родитель') {
            // Две колонки «Родитель»: первая — группа, вторая — подгруппа
            if ($parentIdx === 0) { $map['group'] = $i; $parentIdx++; }
            else                  { $map['subgroup'] = $i; }
        }
    }
    return $map;
}

function extract_code(string $s): ?string {
    if (preg_match('/^(\d+)\s*[.\-–]/', trim($s), $m)) return $m[1];
    return null;
}

/**
 * Парсит файл. Возвращает список комплектаций, каждая со своими группами/подгруппами/работами.
 * [
 *   '54901-0070004-CA' => ['groups'=>[...], 'subgroups'=>[...], 'works'=>[...]],
 *   ...
 * ]
 */
function parse_file(string $path): array {
    $content = file_get_contents($path);
    if ($content === false) throw new RuntimeException('Не удалось прочитать файл');

    // Убираем BOM, разбиваем на строки
    $content = preg_replace('/^\xEF\xBB\xBF/', '', $content);
    $lines = preg_split('/\r\n|\r|\n/', $content);

    $headerRow = find_header_row($lines);
    if ($headerRow === null) {
        throw new RuntimeException('Не найдена строка заголовка «Норма времени … Комплектация … Код операции …». Убедитесь, что выгружен отчёт «Трудоёмкость операций по нормам времени» в формате TSV (табуляция).');
    }

    $map = map_columns($lines[$headerRow]);
    if (!isset($map['complectation']) || !isset($map['op_code']) || !isset($map['name'])) {
        throw new RuntimeException('В заголовке не найдены обязательные колонки: «Комплектация», «Код операции», «Операция». Проверьте формат выгрузки.');
    }

    $byComplectation = [];

    for ($i = $headerRow + 1; $i < count($lines); $i++) {
        $line = rtrim($lines[$i], "\r\n");
        if ($line === '') continue;
        $cols = explode("\t", $line);
        if (trim($cols[0] ?? '') === 'Итого') break;

        $comp = trim($cols[$map['complectation']] ?? '');
        $opCode = trim($cols[$map['op_code']] ?? '');
        $name = trim($cols[$map['name']] ?? '');
        if ($comp === '' || $opCode === '' || $name === '') continue;

        // Убираем хвостовой дефис у комплектации
        $comp = rtrim($comp, '-');

        if (!isset($byComplectation[$comp])) {
            $byComplectation[$comp] = ['groups'=>[], 'subgroups'=>[], 'works'=>[]];
        }

        // Группа и подгруппа
        $gCode = null;
        if (isset($map['group'])) {
            $gVal = trim($cols[$map['group']] ?? '');
            if ($gVal !== '') {
                $gCode = extract_code($gVal);
                if ($gCode !== null) $byComplectation[$comp]['groups'][$gCode] = $gVal;
            }
        }

        $subCode = null;
        if (isset($map['subgroup'])) {
            $subVal = trim($cols[$map['subgroup']] ?? '');
            if ($subVal !== '') {
                $subCode = extract_code($subVal);
                if ($subCode !== null) $byComplectation[$comp]['subgroups'][$subCode] = $subVal;
            }
        }

        // Норма — из колонки «Трудоемкость»
        $norm = null;
        if (isset($map['norm'])) {
            $nVal = trim($cols[$map['norm']] ?? '');
            if ($nVal !== '') $norm = (float)str_replace([' ', ','], ['', '.'], $nVal);
        }

        $parentCode = $subCode ?? $gCode;

        $byComplectation[$comp]['works'][] = [
            'op_code' => $opCode,
            'name'    => $name,
            'norm'    => $norm,
            'parent'  => $parentCode,
        ];
    }

    if (empty($byComplectation)) {
        throw new RuntimeException('В файле не найдено ни одной строки с работой.');
    }

    return $byComplectation;
}

/**
 * Импорт одной комплектации: удаляет старые записи по ней, вставляет новые.
 */
function import_one(string $complectation, array $data): array {
    global $pdo;

    $groups    = $data['groups'];
    $subgroups = $data['subgroups'];
    $works     = $data['works'];

    // Родители подгрупп: 3757 → 37
    $subgroupParents = [];
    foreach ($subgroups as $code => $_) {
        if (preg_match('/^(\d{2})\d{2}$/', $code, $m)) {
            if (isset($groups[$m[1]])) $subgroupParents[$code] = $m[1];
        }
    }

    // Уникальные коды работ
    $usedCodes = [];
    foreach ($works as &$w) {
        $base = $w['op_code'];
        $candidate = $base;
        $i = 1;
        while (isset($usedCodes[$candidate])) {
            $i++;
            $candidate = $base . '#' . $i;
        }
        $usedCodes[$candidate] = true;
        $w['code'] = mb_substr($candidate, 0, 50);
    }
    unset($w);

    // Удаляем старое только для этой комплектации
    $pdo->prepare("DELETE FROM work_operations WHERE complectation = :c")
        ->execute([':c' => $complectation]);

    $stmt = $pdo->prepare("
        INSERT INTO work_operations
            (code, parent_code, it_is_group, name, operation_code, norm_time, complectation, deleted, updated_at)
        VALUES
            (:code, :parent, :is_group, :name, :op_code, :norm, :complectation, FALSE, NOW())
    ");

    foreach ($groups as $code => $name) {
        $stmt->execute([
            ':code'           => $code . '@' . $complectation,
            ':parent'         => null,
            ':is_group'       => 1,
            ':name'           => mb_substr($name, 0, 250),
            ':op_code'        => null,
            ':norm'           => null,
            ':complectation'  => $complectation,
        ]);
    }
    foreach ($subgroups as $code => $name) {
        $stmt->execute([
            ':code'           => $code . '@' . $complectation,
            ':parent'         => $subgroupParents[$code] ? ($subgroupParents[$code] . '@' . $complectation) : null,
            ':is_group'       => 1,
            ':name'           => mb_substr($name, 0, 250),
            ':op_code'        => null,
            ':norm'           => null,
            ':complectation'  => $complectation,
        ]);
    }
    foreach ($works as $w) {
        $parent = $w['parent'] ? ($w['parent'] . '@' . $complectation) : null;
        $stmt->execute([
            ':code'           => $w['code'] . '@' . $complectation,
            ':parent'         => $parent,
            ':is_group'       => 0,
            ':name'           => mb_substr($w['name'], 0, 250),
            ':op_code'        => mb_substr($w['op_code'], 0, 50),
            ':norm'           => $w['norm'],
            ':complectation'  => $complectation,
        ]);
    }

    return [
        'complectation' => $complectation,
        'groups'        => count($groups),
        'subgroups'     => count($subgroups),
        'works'         => count($works),
        'with_norm'     => count(array_filter($works, fn($w) => $w['norm'] !== null)),
    ];
}

// ============ ОБРАБОТКА ЗАГРУЗКИ ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['datafile']['tmp_name'])) {
    try {
        $parsed = parse_file($_FILES['datafile']['tmp_name']);
        $results = [];
        foreach ($parsed as $comp => $data) {
            $results[] = import_one($comp, $data);
        }

        $dir = __DIR__ . '/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        $safe = 'multi_' . date('Ymd_His');
        @move_uploaded_file($_FILES['datafile']['tmp_name'], $dir . '/import_' . $safe . '.txt');

        $totalWorks = array_sum(array_column($results, 'works'));
        $messages[] = sprintf(
            'Импорт выполнен. Комплектаций: %d. Всего работ: %d.',
            count($results), $totalWorks
        );
        foreach ($results as $r) {
            $messages[] = sprintf(
                '  • %s — групп %d, подгрупп %d, работ %d (с нормой: %d)',
                $r['complectation'], $r['groups'], $r['subgroups'], $r['works'], $r['with_norm']
            );
        }
    } catch (Throwable $e) {
        $error = 'Ошибка импорта: ' . $e->getMessage();
    }
}

// Список загруженных комплектаций
$complectations = $pdo->query("
    SELECT complectation,
           COUNT(*) FILTER (WHERE it_is_group = TRUE  AND deleted = FALSE) AS groups_cnt,
           COUNT(*) FILTER (WHERE it_is_group = FALSE AND deleted = FALSE) AS works_cnt,
           MAX(updated_at) AS last_update
      FROM work_operations
     WHERE complectation IS NOT NULL AND complectation <> '—'
     GROUP BY complectation
     ORDER BY last_update DESC
")->fetchAll();

$totalStats = $pdo->query("
    SELECT
        COUNT(*) FILTER (WHERE it_is_group = FALSE AND deleted = FALSE) AS works,
        COUNT(*) FILTER (WHERE it_is_group = FALSE AND norm_time IS NOT NULL) AS with_norm
    FROM work_operations
")->fetch();

function fmtTs($ts) { return $ts ? date('d.m.Y H:i:s', strtotime($ts)) : '—'; }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Загрузка справочника работ</title>
<style>
  *{box-sizing:border-box}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;margin:0;padding:16px;color:#1a1a1a;line-height:1.5}
  .container{max-width:1000px;margin:0 auto}
  .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,0.06);margin-bottom:16px}
  h1{font-size:22px;margin:0 0 12px} h2{font-size:16px;margin:0 0 12px;color:#1e3a8a}
  .top-bar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px}
  .user-info{font-size:13px;color:#666} .user-info b{color:#2563eb}
  .logout{color:#dc2626;text-decoration:none;font-size:13px;margin-left:12px}
  .btn{display:inline-block;padding:12px 18px;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;background:#2563eb;color:#fff;text-decoration:none;text-align:center;font-family:inherit}
  .btn:hover{opacity:0.9}
  .btn-secondary{background:#fff;color:#2563eb;border:1.5px solid #2563eb}
  .btn-green{background:#16a34a}
  .btn-small{padding:8px 14px;font-size:13px}
  .btn-row{display:flex;gap:10px;flex-wrap:wrap}
  .alert{padding:12px 16px;border-radius:10px;font-size:14px;margin-bottom:16px}
  .alert-success{background:#f0fdf4;color:#16a34a;border-left:4px solid #16a34a}
  .alert-error{background:#fef2f2;color:#dc2626;border-left:4px solid #dc2626}
  .alert-info{background:#eff6ff;color:#2563eb;border-left:4px solid #2563eb}
  .dropzone{border:2px dashed #cbd5e1;border-radius:14px;padding:40px 20px;text-align:center;background:#f8fafc;transition:all 0.2s;cursor:pointer;margin-bottom:12px}
  .dropzone:hover{border-color:#2563eb;background:#eff6ff}
  .dropzone input[type=file]{display:none}
  .dropzone .icon{font-size:42px;margin-bottom:8px}
  .dropzone .title{font-size:16px;font-weight:600;color:#1e3a8a}
  .dropzone .sub{font-size:13px;color:#666;margin-top:4px}
  .file-selected{background:#f0fdf4;border-color:#16a34a;color:#16a34a}
  table{width:100%;border-collapse:collapse;font-size:13px}
  table th{background:#f9fafb;text-align:left;padding:10px 12px;font-size:11px;color:#666;text-transform:uppercase}
  table td{padding:10px 12px;border-bottom:1px solid #f0f0f0}
  code{background:#eff6ff;padding:2px 6px;border-radius:4px;color:#1e3a8a;font-size:12px}
  .stats-grid{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:12px 0}
  .stat{padding:14px;background:#f9fafb;border-radius:10px;text-align:center}
  .stat b{display:block;color:#2563eb;font-size:22px;font-weight:700}
  .stat small{color:#666;font-size:12px;text-transform:uppercase}
</style>
</head>
<body>
<div class="container">

  <div class="card">
    <div class="top-bar">
      <h1>📥 Загрузка справочника работ по комплектациям</h1>
      <div>
        <span class="user-info">👤 <b><?= e($user['name'] ?: $user['email']) ?></b></span>
        <a href="logout.php" class="logout">Выйти</a>
      </div>
    </div>
    <div class="btn-row">
      <a href="index.html" class="btn btn-secondary btn-small">← На рабочее место</a>
      <a href="works.php" class="btn btn-secondary btn-small">К справочнику →</a>
    </div>
  </div>

  <?php foreach ($messages as $m): ?>
    <div class="alert alert-success">✅ <?= e($m) ?></div>
  <?php endforeach; ?>
  <?php if ($error): ?>
    <div class="alert alert-error">❌ <?= e($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>Загрузить файл .txt / .tsv</h2>
    <div class="alert alert-info" style="font-size:13px;">
      <b>Как выгрузить из 1С:</b><br>
      1. Отчёт «Трудоёмкость операций по нормам времени» → «ДляВыгрузки»<br>
      2. Сохрани как <code>.txt</code> в кодировке <b>UTF-8</b>, разделитель — табуляция<br>
      3. Загрузи сюда — <b>комплектации и нормы времени программа возьмёт из файла сама</b><br>
      <b>Важно:</b> если в файле несколько комплектаций — все они импортируются за один раз.
    </div>

    <form method="post" enctype="multipart/form-data" id="uploadForm">
      <label class="dropzone" id="dropzone">
        <input type="file" name="datafile" id="fileInput" accept=".txt,.tsv,.csv">
        <div class="icon">📄</div>
        <div class="title" id="fileName">Нажмите, чтобы выбрать файл</div>
        <div class="sub" id="fileHint">или перетащите сюда · .txt / .tsv / .csv</div>
      </label>

      <div class="btn-row" style="justify-content:center;">
        <button type="submit" class="btn btn-green" id="submitBtn" disabled>🚀 Импортировать</button>
      </div>
    </form>
  </div>

  <div class="card">
    <h2>Загруженные комплектации</h2>
    <?php if (!$complectations): ?>
      <p style="color:#888;">Ещё ничего не загружено. Загрузите первый файл.</p>
    <?php else: ?>
      <table>
        <thead>
          <tr>
            <th>Код комплектации</th>
            <th style="width:100px;">Групп</th>
            <th style="width:100px;">Работ</th>
            <th style="width:180px;">Обновлено</th>
          </tr>
        </thead>
        <tbody>
          <?php foreach ($complectations as $c): ?>
            <tr>
              <td><code><?= e($c['complectation']) ?></code></td>
              <td><?= number_format((int)$c['groups_cnt'], 0, '.', ' ') ?></td>
              <td><b><?= number_format((int)$c['works_cnt'], 0, '.', ' ') ?></b></td>
              <td><?= fmtTs($c['last_update']) ?></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>

  <div class="card">
    <h2>Общая статистика</h2>
    <div class="stats-grid">
      <div class="stat">
        <b><?= number_format((int)$totalStats['works'], 0, '.', ' ') ?></b>
        <small>Всего работ</small>
      </div>
      <div class="stat">
        <b><?= number_format((int)$totalStats['with_norm'], 0, '.', ' ') ?></b>
        <small>С нормой времени</small>
      </div>
    </div>
  </div>

</div>

<script>
(function () {
  var input  = document.getElementById('fileInput');
  var zone   = document.getElementById('dropzone');
  var nameEl = document.getElementById('fileName');
  var hintEl = document.getElementById('fileHint');
  var btn    = document.getElementById('submitBtn');

  function showFile() {
    if (input.files && input.files.length) {
      var f = input.files[0];
      nameEl.textContent = f.name;
      hintEl.textContent = (f.size / 1024).toFixed(1) + ' КБ · готово к загрузке';
      zone.classList.add('file-selected');
      btn.disabled = false;
    } else {
      nameEl.textContent = 'Нажмите, чтобы выбрать файл';
      hintEl.textContent = 'или перетащите сюда · .txt / .tsv / .csv';
      zone.classList.remove('file-selected');
      btn.disabled = true;
    }
  }

  input.addEventListener('change', showFile);

  ['dragenter', 'dragover'].forEach(function (ev) {
    zone.addEventListener(ev, function (e) {
      e.preventDefault(); e.stopPropagation();
      zone.style.borderColor = '#2563eb';
      zone.style.background  = '#eff6ff';
    });
  });

  ['dragleave', 'drop'].forEach(function (ev) {
    zone.addEventListener(ev, function (e) {
      e.preventDefault(); e.stopPropagation();
      zone.style.borderColor = '#cbd5e1';
      zone.style.background  = '#f8fafc';
    });
  });

  zone.addEventListener('drop', function (e) {
    if (e.dataTransfer && e.dataTransfer.files.length) {
      input.files = e.dataTransfer.files;
      showFile();
    }
  });
})();
</script>
</body>
</html>
