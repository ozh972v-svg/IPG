<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user || !$user['is_admin']) { header('Location: index.html'); exit; }

$pdo = get_db();
$messages = [];
$error = null;
$importResult = null;

/**
 * Определяет роль каждой колонки по формату значения.
 * Устойчиво к сдвигам колонок в отчёте 1С.
 */
function detect_roles(array $cols): array {
    $roles = ['group'=>null, 'subgroup'=>null, 'opCode'=>null, 'name'=>null, 'norm'=>null];

    foreach ($cols as $i => $raw) {
        $v = trim($raw);
        if ($v === '') continue;

        // Норма времени: "0,500" или "0.500" (только цифры, запятая/точка, цифры)
        if (preg_match('/^\d+[,.]\d+$/', $v)) {
            $roles['norm'] = ['idx'=>$i, 'val'=>$v];
            continue;
        }

        // Код операции: "00-000", "П10-008", "C10-0211", "X99-9910", "P-35-02-02-01", "Р10-0511", "Д40-0010"
        // Начинается с буквы ИЛИ двух цифр, потом дефис(ы) и цифры
        if (preg_match('/^([A-Za-zА-Яа-я]+\-?\d[\d\-]*|\d{2}\-\d[\d\-]*)$/u', $v)) {
            $roles['opCode'] = ['idx'=>$i, 'val'=>$v];
            continue;
        }

        // Подгруппа: ровно 4 цифры в начале, потом " . " или " - " ("0000. Автомобиль", "8228 - Холодильник")
        if (preg_match('/^\d{4}\s*[.\-–]\s*/', $v)) {
            if ($roles['subgroup'] === null) {
                $roles['subgroup'] = ['idx'=>$i, 'val'=>$v];
            }
            continue;
        }

        // Группа: ровно 2 цифры в начале, потом " . " или " - " ("00. Автомобиль", "82 - Принадлежности")
        if (preg_match('/^\d{2}\s*[.\-–]\s*/', $v)) {
            if ($roles['group'] === null) {
                $roles['group'] = ['idx'=>$i, 'val'=>$v];
            }
            continue;
        }
    }

    // Название работы — колонка сразу после кода операции
    if ($roles['opCode'] !== null) {
        $opIdx = $roles['opCode']['idx'];
        if (isset($cols[$opIdx + 1])) {
            $nameVal = trim($cols[$opIdx + 1]);
            if ($nameVal !== '') $roles['name'] = $nameVal;
        }
    }

    return $roles;
}

/**
 * Извлекает числовой код из строки типа "00. Автомобиль" или "8228 - Холодильник".
 */
function extract_code(string $s): ?string {
    if (preg_match('/^(\d+)\s*[.\-–]/', trim($s), $m)) return $m[1];
    return null;
}

/**
 * Парсит TSV-файл и импортирует в БД.
 */
function import_file(string $path): array {
    global $pdo;

    $handle = fopen($path, 'r');
    if (!$handle) throw new RuntimeException('Не удалось открыть файл');

    $headersFound = false;
    $groups = [];      // '00' => '00. Автомобиль'
    $subgroups = [];   // '0000' => '0000. Автомобиль'
    $works = [];
    $lineNo = 0;

    while (($line = fgets($handle)) !== false) {
        $lineNo++;
        $line = rtrim($line, "\r\n");
        if ($lineNo === 1) $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);

        $cols = explode("\t", $line);

        // Ищем строку-заголовок
        if (!$headersFound) {
            if (isset($cols[0]) && trim($cols[0]) === 'Норма времени') {
                $headersFound = true;
            }
            continue;
        }

        // Ищем конец таблицы
        if (isset($cols[0]) && trim($cols[0]) === 'Итого') break;

        // Определяем роли колонок
        $r = detect_roles($cols);

        // Пропускаем строки без кода операции и названия — это заголовки/пустышки
        if ($r['opCode'] === null || $r['name'] === null) continue;

        $opCode = $r['opCode']['val'];
        $name   = $r['name'];

        // Группа
        if ($r['group'] !== null) {
            $gCode = extract_code($r['group']['val']);
            if ($gCode !== null) $groups[$gCode] = trim($r['group']['val']);
        }

        // Подгруппа
        $subCode = null;
        if ($r['subgroup'] !== null) {
            $subCode = extract_code($r['subgroup']['val']);
            if ($subCode !== null) $subgroups[$subCode] = trim($r['subgroup']['val']);
        }

        // Норма времени
        $normVal = null;
        if ($r['norm'] !== null) {
            $normVal = (float)str_replace([' ', ','], ['', '.'], $r['norm']['val']);
        }

        // Родитель работы = подгруппа, если есть; иначе группа
        $parentCode = null;
        if ($subCode !== null) {
            $parentCode = $subCode;
        } elseif ($r['group'] !== null) {
            $parentCode = extract_code($r['group']['val']);
        }

        $works[] = [
            'op_code' => $opCode,
            'name'    => $name,
            'norm'    => $normVal,
            'parent'  => $parentCode,
        ];
    }
    fclose($handle);

    if (empty($works)) {
        throw new RuntimeException('В файле не найдено ни одной работы. Проверь, что файл TSV/UTF-8 и содержит колонки с кодом операции.');
    }

    // Родитель подгруппы: '1002' → '10', '0900' → '09'
    $subgroupParents = [];
    foreach ($subgroups as $code => $_) {
        if (preg_match('/^(\d{2})\d{2}$/', $code, $m)) {
            if (isset($groups[$m[1]])) $subgroupParents[$code] = $m[1];
        }
    }

    // Уникальные коды для работ (у двух работ может быть один operation_code)
    // Если opCode повторяется — добавляем #2, #3 и т.д.
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

    // === ЗАПИСЬ В БД ===
    $pdo->exec("TRUNCATE work_operations");

    $stmt = $pdo->prepare("
        INSERT INTO work_operations
            (code, parent_code, it_is_group, name, operation_code, norm_time, deleted, updated_at)
        VALUES
            (:code, :parent, :is_group, :name, :op_code, :norm, FALSE, NOW())
    ");

    // 1) Группы (00, 10, 13, ...)
    foreach ($groups as $code => $name) {
        $stmt->execute([
            ':code'     => mb_substr($code, 0, 50),
            ':parent'   => null,
            ':is_group' => 1,
            ':name'     => mb_substr($name, 0, 250),
            ':op_code'  => null,
            ':norm'     => null,
        ]);
    }

    // 2) Подгруппы (0000, 1002, ...)
    foreach ($subgroups as $code => $name) {
        $stmt->execute([
            ':code'     => mb_substr($code, 0, 50),
            ':parent'   => $subgroupParents[$code] ?? null,
            ':is_group' => 1,
            ':name'     => mb_substr($name, 0, 250),
            ':op_code'  => null,
            ':norm'     => null,
        ]);
    }

    // 3) Работы
    foreach ($works as $w) {
        $stmt->execute([
            ':code'     => $w['code'],
            ':parent'   => $w['parent'],
            ':is_group' => 0,
            ':name'     => mb_substr($w['name'], 0, 250),
            ':op_code'  => mb_substr($w['op_code'], 0, 50),
            ':norm'     => $w['norm'],
        ]);
    }

    return [
        'groups'          => count($groups),
        'subgroups'       => count($subgroups),
        'works'           => count($works),
        'works_with_norm' => count(array_filter($works, fn($w) => $w['norm'] !== null)),
    ];
}

// ============ ОБРАБОТКА ЗАГРУЗКИ ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['datafile']['tmp_name'])) {
    try {
        $importResult = import_file($_FILES['datafile']['tmp_name']);

        // Сохраним исходный файл для истории
        $dir = __DIR__ . '/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @move_uploaded_file($_FILES['datafile']['tmp_name'], $dir . '/last_import.txt');

        $messages[] = sprintf(
            'Импорт выполнен: групп %d, подгрупп %d, работ %d (с нормой времени: %d)',
            $importResult['groups'],
            $importResult['subgroups'],
            $importResult['works'],
            $importResult['works_with_norm']
        );
    } catch (Throwable $e) {
        $error = 'Ошибка импорта: ' . $e->getMessage();
    }
}

// Статистика БД
$stats = $pdo->query("
    SELECT
        COUNT(*) FILTER (WHERE it_is_group = TRUE  AND deleted = FALSE) AS groups,
        COUNT(*) FILTER (WHERE it_is_group = FALSE AND deleted = FALSE) AS works,
        COUNT(*) FILTER (WHERE it_is_group = FALSE AND norm_time IS NOT NULL) AS with_norm
    FROM work_operations
")->fetch();

$lastUpdate = $pdo->query("SELECT MAX(updated_at) FROM work_operations")->fetchColumn();

// Примеры работ
$sampleWorks = $pdo->query("
    SELECT code, name, operation_code, norm_time, parent_code
    FROM work_operations
    WHERE it_is_group = FALSE AND deleted = FALSE
    ORDER BY operation_code
    LIMIT 15
")->fetchAll();

function fmtTs($ts) { return $ts ? date('d.m.Y H:i:s', strtotime($ts)) : '—'; }
function fmtNorm($n) {
    if ($n === null) return null;
    return rtrim(rtrim(number_format((float)$n, 3, ',', ' '), '0'), ',');
}
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
  .stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(180px,1fr));gap:10px;margin:12px 0}
  .stat{padding:14px;background:#f9fafb;border-radius:10px;text-align:center}
  .stat b{display:block;color:#2563eb;font-size:22px;font-weight:700}
  .stat small{color:#666;font-size:12px;text-transform:uppercase;letter-spacing:0.3px}
  .dropzone{border:2px dashed #cbd5e1;border-radius:14px;padding:40px 20px;text-align:center;background:#f8fafc;transition:all 0.2s;cursor:pointer;margin-bottom:12px}
  .dropzone:hover{border-color:#2563eb;background:#eff6ff}
  .dropzone input[type=file]{display:none}
  .dropzone .icon{font-size:42px;margin-bottom:8px}
  .dropzone .title{font-size:16px;font-weight:600;color:#1e3a8a}
  .dropzone .sub{font-size:13px;color:#666;margin-top:4px}
  .file-selected{background:#f0fdf4;border-color:#16a34a;color:#16a34a}
  table{width:100%;border-collapse:collapse;font-size:13px}
  table th{background:#f9fafb;text-align:left;padding:8px 10px;font-size:11px;color:#666;text-transform:uppercase;letter-spacing:0.3px}
  table td{padding:8px 10px;border-bottom:1px solid #f0f0f0}
  code{background:#eff6ff;padding:2px 6px;border-radius:4px;color:#1e3a8a;font-size:12px}
  .norm{background:#e0f2fe;color:#075985;padding:2px 8px;border-radius:5px;font-weight:600;font-size:12px}
</style>
</head>
<body>
<div class="container">

  <div class="card">
    <div class="top-bar">
      <h1>📥 Загрузка справочника работ</h1>
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
    <h2>Загрузить файл .txt / .tsv (табулированный)</h2>
    <div class="alert alert-info" style="font-size:13px;">
      <b>Как выгрузить файл из 1С:</b><br>
      1. Отчёт «Трудоёмкость операций по нормам времени» → «ДляВыгрузки»<br>
      2. Сохранить как <code>.txt</code> в кодировке <b>UTF-8</b>, разделитель — <b>табуляция</b><br>
      3. Загрузить сюда
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
    <h2>Текущее состояние справочника</h2>
    <div class="stats">
      <div class="stat"><b><?= number_format((int)$stats['groups'], 0, '.', ' ') ?></b><small>групп</small></div>
      <div class="stat"><b><?= number_format((int)$stats['works'], 0, '.', ' ') ?></b><small>работ</small></div>
      <div class="stat"><b><?= number_format((int)$stats['with_norm'], 0, '.', ' ') ?></b><small>с нормой времени</small></div>
    </div>
    <p style="font-size:13px;color:#666;text-align:center;">
      Последнее обновление: <b><?= e(fmtTs($lastUpdate)) ?></b>
    </p>
  </div>

  <?php if ($sampleWorks): ?>
    <div class="card">
      <h2>Первые 15 работ в базе</h2>
      <table>
        <thead><tr><th>Код операции</th><th>Наименование</th><th>Норма</th><th>Родитель</th></tr></thead>
        <tbody>
          <?php foreach ($sampleWorks as $w): ?>
            <tr>
              <td><code><?= e($w['operation_code'] ?: $w['code']) ?></code></td>
              <td><?= e(mb_substr($w['name'], 0, 80)) ?></td>
              <td><?php if ($w['norm_time'] !== null): ?><span class="norm"><?= e(fmtNorm($w['norm_time'])) ?> ч</span><?php else: ?>—<?php endif; ?></td>
              <td><code><?= e($w['parent_code'] ?: '—') ?></code></td>
            </tr>
          <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  <?php endif; ?>

</div>

<script>
(function(){
  var input = document.getElementById('fileInput');
  var drop  = document.getElementById('dropzone');
  var name  = document.getElementById('fileName');
  var hint  = document.getElementById('fileHint');
  var btn   = document.getElementById('submitBtn');

  function showFile(file) {
    if (!file) return;
    name.textContent = file.name;
    hint.textContent = (file.size / 1024).toFixed(1) + ' КБ — готов к загрузке';
    drop.classList.add('file-selected');
    btn.disabled = false;
  }

  input.addEventListener('change', function() {
    if (input.files && input.files[0]) showFile(input.files[0]);
  });

  ['dragenter','dragover'].forEach(function(ev){
    drop.addEventListener(ev, function(e){ e.preventDefault(); drop.style.borderColor = '#2563eb'; });
  });
  ['dragleave','drop'].forEach(function(ev){
    drop.addEventListener(ev, function(e){ e.preventDefault(); drop.style.borderColor = ''; });
  });
  drop.addEventListener('drop', function(e){
    if (e.dataTransfer.files && e.dataTransfer.files[0]) {
      input.files = e.dataTransfer.files;
      showFile(e.dataTransfer.files[0]);
    }
  });
})();
</script>
</body>
</html>
