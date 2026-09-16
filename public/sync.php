<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user || !$user['is_admin']) { header('Location: index.html'); exit; }

$pdo = get_db();
$messages = [];
$error = null;

/**
 * Определяет роли колонок по формату значения.
 */
function detect_roles(array $cols): array {
    $roles = ['group'=>null, 'subgroup'=>null, 'opCode'=>null, 'name'=>null, 'norm'=>null, 'complectation'=>null];

    foreach ($cols as $i => $raw) {
        $v = trim($raw);
        if ($v === '') continue;

        // Норма времени: "0,500" или "0.500"
        if (preg_match('/^\d+[,.]\d+$/', $v)) {
            if ($roles['norm'] === null) $roles['norm'] = ['idx'=>$i, 'val'=>$v];
            continue;
        }

        // Код комплектации: "54901-0070004-CA" или "КАМАЗ 54901-004-92 (94)"
        if (preg_match('/^(КАМАЗ\s+)?\d{4,5}-\d+/u', $v)) {
            if ($roles['complectation'] === null) $roles['complectation'] = ['idx'=>$i, 'val'=>$v];
            continue;
        }

        // Код операции: "00-000", "П10-008", "C10-0211", "X99-9910", "P-35-02-02-01"
        if (preg_match('/^([A-Za-zА-Яа-я]+\-?\d[\d\-]*|\d{2}\-\d[\d\-]*)$/u', $v)) {
            if ($roles['opCode'] === null) $roles['opCode'] = ['idx'=>$i, 'val'=>$v];
            continue;
        }

        // Подгруппа: 4 цифры + разделитель ("0000. Автомобиль", "8228 - Холодильник")
        if (preg_match('/^\d{4}\s*[.\-–]\s*/', $v)) {
            if ($roles['subgroup'] === null) $roles['subgroup'] = ['idx'=>$i, 'val'=>$v];
            continue;
        }

        // Группа: 2 цифры + разделитель ("00. Автомобиль", "82 - Принадлежности")
        if (preg_match('/^\d{2}\s*[.\-–]\s*/', $v)) {
            if ($roles['group'] === null) $roles['group'] = ['idx'=>$i, 'val'=>$v];
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

function extract_code(string $s): ?string {
    if (preg_match('/^(\d+)\s*[.\-–]/', trim($s), $m)) return $m[1];
    return null;
}

/**
 * Извлекает код модели из кода комплектации.
 * "54901-0070004-CA"  → "54901"
 * "КАМАЗ 54901-004-92" → "54901"
 */
function extract_model_code(string $complectation): ?string {
    if (preg_match('/(\d{4,5})\s*-/', $complectation, $m)) return $m[1];
    return null;
}

/**
 * Извлекает название комплектации (без префикса "КАМАЗ").
 */
function clean_complectation(string $c): string {
    return trim(preg_replace('/^КАМАЗ\s+/u', '', $c));
}

/**
 * Импорт файла.
 */
function import_file(string $path): array {
    global $pdo;

    $handle = fopen($path, 'r');
    if (!$handle) throw new RuntimeException('Не удалось открыть файл');

    $headersFound = false;
    $groups = [];
    $subgroups = [];
    $works = [];
    $complectations = [];
    $lineNo = 0;

    while (($line = fgets($handle)) !== false) {
        $lineNo++;
        $line = rtrim($line, "\r\n");
        if ($lineNo === 1) $line = preg_replace('/^\xEF\xBB\xBF/', '', $line);

        $cols = explode("\t", $line);

        if (!$headersFound) {
            if (isset($cols[0]) && trim($cols[0]) === 'Норма времени') $headersFound = true;
            continue;
        }

        if (isset($cols[0]) && trim($cols[0]) === 'Итого') break;

        $r = detect_roles($cols);
        if ($r['opCode'] === null || $r['name'] === null) continue;

        $opCode = $r['opCode']['val'];
        $name   = $r['name'];

        // Код комплектации
        if ($r['complectation'] !== null) {
            $complectations[clean_complectation($r['complectation']['val'])] = true;
        }

        if ($r['group'] !== null) {
            $gCode = extract_code($r['group']['val']);
            if ($gCode !== null) $groups[$gCode] = trim($r['group']['val']);
        }

        $subCode = null;
        if ($r['subgroup'] !== null) {
            $subCode = extract_code($r['subgroup']['val']);
            if ($subCode !== null) $subgroups[$subCode] = trim($r['subgroup']['val']);
        }

        $normVal = null;
        if ($r['norm'] !== null) {
            $normVal = (float)str_replace([' ', ','], ['', '.'], $r['norm']['val']);
        }

        $parentCode = $subCode;
        if ($parentCode === null && $r['group'] !== null) {
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
        throw new RuntimeException('В файле не найдено ни одной работы.');
    }
    if (empty($complectations)) {
        throw new RuntimeException('В файле не найдена колонка «Комплектация» (или она пустая).');
    }

    // Берём первую комплектацию (обычно одна в файле)
    $complectation = array_key_first($complectations);
    $modelCode = extract_model_code($complectation);
    if ($modelCode === null) {
        throw new RuntimeException('Не удалось определить код модели из комплектации "' . $complectation . '"');
    }
    $modelName = 'КАМАЗ ' . $modelCode;

    // Родители подгрупп: '1002' → '10'
    $subgroupParents = [];
    foreach ($subgroups as $code => $_) {
        if (preg_match('/^(\d{2})\d{2}$/', $code, $m)) {
            if (isset($groups[$m[1]])) $subgroupParents[$code] = $m[1];
        }
    }

    // Уникальные коды работ (в рамках комплектации)
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

    // Удаляем все старые записи для этой комплектации
    $pdo->prepare("DELETE FROM work_operations WHERE complectation = :c")
        ->execute([':c' => $complectation]);

    // Регистрируем модель (если её ещё нет)
    try {
        $pdo->prepare("
            INSERT INTO work_models (code, name, updated_at)
            VALUES (:c, :n, NOW())
            ON CONFLICT (code) DO UPDATE SET name = EXCLUDED.name, updated_at = NOW()
        ")->execute([':c' => $modelCode, ':n' => $modelName]);
    } catch (Throwable $e) {
        // Если таблицы work_models нет — пропускаем
    }

    $stmt = $pdo->prepare("
        INSERT INTO work_operations
            (code, parent_code, it_is_group, name, operation_code, norm_time, deleted, model, complectation, updated_at)
        VALUES
            (:code, :parent, :is_group, :name, :op_code, :norm, FALSE, :model, :complectation, NOW())
    ");

    foreach ($groups as $code => $gName) {
        $stmt->execute([
            ':code' => mb_substr($code, 0, 50),
            ':parent' => null,
            ':is_group' => 1,
            ':name' => mb_substr($gName, 0, 250),
            ':op_code' => null,
            ':norm' => null,
            ':model' => $modelCode,
            ':complectation' => $complectation,
        ]);
    }
    foreach ($subgroups as $code => $gName) {
        $stmt->execute([
            ':code' => mb_substr($code, 0, 50),
            ':parent' => $subgroupParents[$code] ?? null,
            ':is_group' => 1,
            ':name' => mb_substr($gName, 0, 250),
            ':op_code' => null,
            ':norm' => null,
            ':model' => $modelCode,
            ':complectation' => $complectation,
        ]);
    }
    foreach ($works as $w) {
        $stmt->execute([
            ':code' => $w['code'],
            ':parent' => $w['parent'],
            ':is_group' => 0,
            ':name' => mb_substr($w['name'], 0, 250),
            ':op_code' => mb_substr($w['op_code'], 0, 50),
            ':norm' => $w['norm'],
            ':model' => $modelCode,
            ':complectation' => $complectation,
        ]);
    }

    return [
        'model'          => $modelName,
        'complectation'  => $complectation,
        'groups'         => count($groups),
        'subgroups'      => count($subgroups),
        'works'          => count($works),
        'with_norm'      => count(array_filter($works, fn($w) => $w['norm'] !== null)),
    ];
}

// ============ ОБРАБОТКА ЗАГРУЗКИ ============
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['datafile']['tmp_name'])) {
    try {
        $result = import_file($_FILES['datafile']['tmp_name']);

        $dir = __DIR__ . '/uploads';
        if (!is_dir($dir)) @mkdir($dir, 0775, true);
        @move_uploaded_file($_FILES['datafile']['tmp_name'], $dir . '/import_' . preg_replace('/[^\w\-]/', '_', $result['complectation']) . '.txt');

        $messages[] = sprintf(
            'Импорт выполнен. Модель: <b>%s</b>, комплектация: <b>%s</b>. Групп: %d, подгрупп: %d, работ: %d (с нормой: %d)',
            $result['model'], $result['complectation'],
            $result['groups'], $result['subgroups'], $result['works'], $result['with_norm']
        );
    } catch (Throwable $e) {
        $error = 'Ошибка импорта: ' . $e->getMessage();
    }
}

// Статистика БД
$totalStats = $pdo->query("
    SELECT
        COUNT(*) FILTER (WHERE it_is_group = FALSE AND deleted = FALSE) AS works,
        COUNT(*) FILTER (WHERE it_is_group = FALSE AND norm_time IS NOT NULL) AS with_norm,
        COUNT(DISTINCT complectation) AS complectations
    FROM work_operations
")->fetch();

// Список загруженных комплектаций
$complectations = $pdo->query("
    SELECT complectation, model, COUNT(*) AS works_cnt, MAX(updated_at) AS updated_at
    FROM work_operations
    WHERE it_is_group = FALSE AND deleted = FALSE AND complectation IS NOT NULL
    GROUP BY complectation, model
    ORDER BY updated_at DESC
")->fetchAll();

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
  table th{background:#f9fafb;text-align:left;padding:10px 12px;font-size:11px;color:#666;text-transform:uppercase;letter-spacing:0.3px}
  table td{padding:10px 12px;border-bottom:1px solid #f0f0f0}
  code{background:#eff6ff;padding:2px 6px;border-radius:4px;color:#1e3a8a;font-size:12px}
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
    <div class="alert alert-success">✅ <?= $m ?></div>
  <?php endforeach; ?>
  <?php if ($error): ?>
    <div class="alert alert-error">❌ <?= e($error) ?></div>
  <?php endif; ?>

  <div class="card">
    <h2>Загрузить файл .txt / .tsv для комплектации</h2>
    <div class="alert alert-info" style="font-size:13px;">
      <b>Как выгрузить файл из 1С:</b><br>
      1. Отчёт «Трудоёмкость операций по нормам времени» → «ДляВыгрузки»<br>
      2. Сохранить как <code>.txt</code> в кодировке <b>UTF-8</b>, разделитель — <b>табуляция</b><br>
      3. Загрузить сюда — <b>код модели и код комплектации определятся автоматически из файла</b>
    </div>

    <form method="post" enctype="multipart/form-data" id="uploadForm">
      <label class="dropzone" id="dropzone">
        <input type="file" name="datafile" id="fileInput"
