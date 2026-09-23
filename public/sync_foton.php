<?php
set_time_limit(900);
ini_set('memory_limit', '512M');

require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) { header('Location: login.php'); exit; }
if (!$user['is_admin']) { die('Только для администратора'); }
$pdo = get_db();

/**
 * Нормализует строку: если это Windows-1251 — конвертирует в UTF-8.
 * Иначе возвращает как есть.
 */
function fixEncoding($s) {
    if ($s === null || $s === '') return $s;
    if (mb_check_encoding($s, 'UTF-8')) return $s;
    /* Пробуем CP1251 → UTF-8 */
    $converted = @mb_convert_encoding($s, 'UTF-8', 'CP1251');
    if ($converted !== false && mb_check_encoding($converted, 'UTF-8')) {
        return $converted;
    }
    /* Резервный вариант — iconv */
    $converted = @iconv('CP1251', 'UTF-8//IGNORE', $s);
    if ($converted !== false) return $converted;
    return $s;
}
/**
 * Обрезает строку до N символов. Если длиннее — обрезает и логирует.
 */
function fixLength($s, $max = 490) {
    if ($s === null) return null;
    if (mb_strlen($s) <= $max) return $s;
    return mb_substr($s, 0, $max);
}
$result = null;
$error  = null;

/* ============================================================
   Импорт CSV
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['csv']['tmp_name'])) {
    $file = $_FILES['csv']['tmp_name'];
    $clearFirst = !empty($_POST['clear']);

    try {
        if ($clearFirst) {
            $pdo->exec("DELETE FROM work_operations WHERE brand = 'FOTON'");
        }

        $fh = fopen($file, 'r');
        if (!$fh) throw new Exception('Не удалось открыть файл');

        /* BOM убираем */
        $first = fgets($fh);
        if (strpos($first, "\xEF\xBB\xBF") === 0) {
            $first = substr($first, 3);
        }
        rewind($fh);

        /* Первая строка — заголовки, пропускаем */
        $headers = fgetcsv($fh, 0, ';');
        if (!$headers || count($headers) < 8) {
            throw new Exception('Не удалось прочитать заголовки. Проверьте, что разделитель — точка с запятой.');
        }

        $now = date('Y-m-d H:i:s');
        $total = 0;
        $groupsSeen = [];
        $subgroupsSeen = [];
        $worksSeen = [];

        $insertGroup = $pdo->prepare("
            INSERT INTO work_operations
                (code, parent_code, it_is_group, name, brand, deleted, updated_at)
            VALUES (:code, :parent, TRUE, :name, 'FOTON', FALSE, :ts)
            ON CONFLICT (code) DO NOTHING
        ");

        $insertWork = $pdo->prepare("
            INSERT INTO work_operations
                (code, parent_code, it_is_group, name, eng_name, norm_time,
                 complectation, model, brand, operation_code, deleted, updated_at)
            VALUES (:code, :parent, FALSE, :name, :eng, :norm,
                    :model_name, 'FOTON', 'FOTON', :op, FALSE, :ts)
            ON CONFLICT (code) DO UPDATE
                SET name = EXCLUDED.name,
                    eng_name = EXCLUDED.eng_name,
                    norm_time = EXCLUDED.norm_time,
                    parent_code = EXCLUDED.parent_code,
                    updated_at = EXCLUDED.updated_at
        ");

        $pdo->beginTransaction();

        while (($row = fgetcsv($fh, 0, ';')) !== false) {
            if (count($row) < 8) continue;

            /* Каждую ячейку прогоняем через fixEncoding */
            $group    = trim(fixEncoding((string)($row[0] ?? '')));
            $subgroup = trim(fixEncoding((string)($row[1] ?? '')));
            $opCode   = trim((string)($row[2] ?? ''));
            $name     = trim(fixEncoding((string)($row[3] ?? '')));
            $model    = trim(fixEncoding((string)($row[5] ?? '')));
            $normRaw  = trim((string)($row[7] ?? ''));
            $engName  = trim(fixEncoding((string)($row[8] ?? '')));

            if ($group === '' && $subgroup === '' && $opCode === '') continue;
            if ($name === '' && $opCode === '') continue;

            /* Норматив: "8,5" → 8.5 */
            $norm = null;
            if ($normRaw !== '') {
                $norm = (float)str_replace(',', '.', $normRaw);
            }

            /* Группа (верхний уровень) */
            if ($group !== '') {
                $groupCode = 'FOTON_' . $group;
                if (!isset($groupsSeen[$groupCode])) {
                    $groupsSeen[$groupCode] = true;
                                        $insertGroup->execute([
                        ':code'   => fixLength($groupCode),
                        ':parent' => null,
                        ':name'   => fixLength($group),
                        ':ts'     => $now,
                    ]);
                    $total++;
                }
            }

                        /* Подгруппа (второй уровень) */
            if ($subgroup !== '') {
                $subCode = 'FOTON_' . $subgroup;
                if (!isset($subgroupsSeen[$subCode])) {
                    $subgroupsSeen[$subCode] = true;
                    $insertGroup->execute([
                        ':code'   => fixLength($subCode),
                        ':parent' => $group !== '' ? fixLength('FOTON_' . $group) : null,
                        ':name'   => fixLength($subgroup),
                        ':ts'     => $now,
                    ]);
                    $total++;
                }
            }

            /* Работа — на каждую модель своя запись */
            if ($opCode !== '' && $name !== '' && $model !== '') {
                $workCode = $opCode . '@' . $model;
                if (!isset($worksSeen[$workCode])) {
                    $worksSeen[$workCode] = true;
                                        $insertWork->execute([
                        ':code'       => fixLength($workCode),
                        ':parent'     => $subgroup !== '' ? fixLength('FOTON_' . $subgroup) : null,
                        ':name'       => fixLength($name),
                        ':eng'        => $engName ? fixLength($engName) : null,
                        ':norm'       => $norm,
                        ':model_name' => fixLength($model),
                        ':op'         => $opCode,
                        ':ts'         => $now,
                    ]);
                    $total++;

                    /* Раз в 500 работ — коммит, чтобы не держать транзакцию долго */
                    if ($total % 500 === 0) {
                        $pdo->commit();
                        $pdo->beginTransaction();
                    }
                }
            }
        }

        fclose($fh);
        $pdo->commit();

        $result = [
            'total'     => $total,
            'groups'    => count($groupsSeen),
            'subgroups' => count($subgroupsSeen),
            'works'     => count($worksSeen),
            'cleared'   => $clearFirst,
        ];

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $error = $e->getMessage();
    }
}

/* Статистика по FOTON */
$stats = ['groups' => 0, 'works' => 0, 'models' => 0];
try {
    $st = $pdo->query("
        SELECT COUNT(*) FILTER (WHERE it_is_group = TRUE)  AS groups,
               COUNT(DISTINCT operation_code) FILTER (WHERE it_is_group = FALSE) AS works,
               COUNT(DISTINCT complectation) FILTER (WHERE it_is_group = FALSE) AS models
          FROM work_operations
         WHERE brand = 'FOTON' AND deleted = FALSE
    ");
    $stats = $st->fetch() ?: $stats;
} catch (Throwable $e) {}

$modelsList = [];
try {
    $st = $pdo->query("
        SELECT complectation, COUNT(DISTINCT operation_code) AS cnt
          FROM work_operations
         WHERE brand = 'FOTON' AND it_is_group = FALSE AND deleted = FALSE
           AND complectation IS NOT NULL
         GROUP BY complectation
         ORDER BY complectation
    ");
    $modelsList = $st->fetchAll();
} catch (Throwable $e) {}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Загрузка справочника ФОТОН</title>
<link rel="stylesheet" href="app.css">
<style>
  body { padding: 24px 18px; }
  .container { max-width: 900px; }

  .upload-box {
    padding: 32px 28px;
    background: linear-gradient(135deg, #fff, #f8fafc);
    border: 2px dashed #cbd5e1;
    border-radius: 16px;
    text-align: center;
    transition: all 0.2s;
    margin-bottom: 16px;
  }
  .upload-box:hover { border-color: #dc2626; background: linear-gradient(135deg, #fff, #fef2f2); }

  input[type=file] {
    display: block;
    width: 100%;
    margin: 18px 0;
    padding: 18px;
    background: #fff;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    font-family: inherit;
    cursor: pointer;
  }
  input[type=file]:hover { border-color: #dc2626; }

  .stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(140px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
  }
  .stat-card {
    background: #fff;
    border-radius: 12px;
    padding: 18px;
    text-align: center;
    border: 1.5px solid rgba(15,23,42,0.06);
  }
  .stat-num {
    font-size: 28px;
    font-weight: 800;
    color: #dc2626;
    letter-spacing: -0.02em;
    line-height: 1.1;
  }
  .stat-lbl {
    font-size: 12px;
    color: #64748b;
    text-transform: uppercase;
    letter-spacing: 0.04em;
    font-weight: 600;
    margin-top: 6px;
  }

  .models-list {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));
    gap: 8px;
    margin-top: 12px;
  }
  .model-chip {
    padding: 10px 14px;
    background: #fef2f2;
    border: 1.5px solid #fecaca;
    border-radius: 10px;
    font-size: 13px;
    font-weight: 600;
    color: #991b1b;
    display: flex;
    justify-content: space-between;
    gap: 8px;
    align-items: center;
  }
  .model-chip .cnt {
    background: #dc2626;
    color: #fff;
    padding: 2px 10px;
    border-radius: 10px;
    font-size: 11px;
    font-weight: 700;
  }

  .checkbox-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 14px 16px;
    background: #fffbeb;
    border-left: 4px solid #f59e0b;
    border-radius: 10px;
    font-size: 14px;
    margin-bottom: 16px;
    cursor: pointer;
  }
  .checkbox-row input { width: 18px; height: 18px; accent-color: #dc2626; cursor: pointer; }
</style>
</head>
<body>
<div class="container">

  <?php
    $pageTitle    = 'Загрузка справочника ФОТОН';
    $pageSubtitle = 'импорт CSV · 1С';
    $backLink     = 'works_brand.php';
    $backLabel    = 'К выбору марки';
    include __DIR__ . '/header.php';
  ?>

  <div class="card">
    <h2>📊 Текущее состояние базы</h2>
    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-num"><?= (int)$stats['groups'] ?></div>
        <div class="stat-lbl">Групп</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= (int)$stats['works'] ?></div>
        <div class="stat-lbl">Работ</div>
      </div>
      <div class="stat-card">
        <div class="stat-num"><?= (int)$stats['models'] ?></div>
        <div class="stat-lbl">Моделей</div>
      </div>
    </div>

    <?php if (!empty($modelsList)): ?>
      <h3 style="margin-top:20px;">Модели в базе (<?= count($modelsList) ?>)</h3>
      <div class="models-list">
        <?php foreach ($modelsList as $m): ?>
          <div class="model-chip">
            <span><?= e($m['complectation']) ?></span>
            <span class="cnt"><?= (int)$m['cnt'] ?></span>
          </div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <?php if ($error): ?>
    <div class="flash flash-error">❌ <?= e($error) ?></div>
  <?php endif; ?>

  <?php if ($result): ?>
    <div class="flash flash-success">
      ✅ Импорт завершён!<br>
      Обработано строк: <b><?= (int)$result['total'] ?></b><br>
      Групп: <b><?= (int)$result['groups'] ?></b> ·
      Подгрупп: <b><?= (int)$result['subgroups'] ?></b> ·
      Работ: <b><?= (int)$result['works'] ?></b>
      <?php if ($result['cleared']): ?><br>🗑️ Старые записи FOTON удалены перед импортом.<?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <h2>📥 Загрузить CSV</h2>
    <div class="step-hint" style="font-size:13.5px;color:#475569;padding:12px 16px;background:#f8fafc;border-radius:10px;border-left:3px solid #dc2626;margin-bottom:16px;line-height:1.55;">
      <b>Ожидаемый формат файла:</b><br>
      — Разделитель: <code>;</code> (точка с запятой)<br>
      — Кодировка: UTF-8 или Windows-1251 (определяется автоматически)<br>
      — Первая строка: заголовки (пропускаются)<br>
      — Столбцы: Группа | Система/узел | Код операции | Наименование | Модель двиг. | Модель | Линейка | Нормотив | Repair item
    </div>

    <form method="post" enctype="multipart/form-data">
      <label class="checkbox-row">
        <input type="checkbox" name="clear" value="1" checked>
        <span>Удалить старые записи ФОТОН перед импортом (рекомендуется)</span>
      </label>

      <div class="upload-box">
        <div style="font-size:52px;margin-bottom:12px;">📄</div>
        <div style="font-size:16px;font-weight:600;color:#0f172a;margin-bottom:6px;">Выберите CSV-файл</div>
        <div style="font-size:13px;color:#64748b;">Файл до 100 МБ</div>
        <input type="file" name="csv" accept=".csv,text/csv" required>
      </div>

      <button type="submit" class="btn btn-primary" style="width:100%;padding:16px;font-size:15px;">📥 Загрузить и обработать</button>
    </form>
  </div>

</div>
</body>
</html>
