<?php
require_once __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    http_response_code(401);
    die('Нужно войти в систему.');
}

header('Content-Type: text/html; charset=utf-8');

$messages = [];
$done = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_FILES['csv']['tmp_name'])) {
    $tmp = $_FILES['csv']['tmp_name'];
    $fh = fopen($tmp, 'r');
    if (!$fh) {
        $messages[] = '❌ Не удалось открыть загруженный файл.';
    } else {
        $db = get_db();

        // Читаем первую строку — заголовки
        $header = fgetcsv($fh, 0, ',');
        // Нормализуем BOM и пробелы
        if ($header) {
            $header[0] = preg_replace('/^\xEF\xBB\xBF/', '', $header[0]);
            $header = array_map('trim', $header);
        }

        // Ожидаемые колонки
        $colIdx = [
            'узел'               => array_search('Узел', $header),
            'код'                => array_search('Код', $header),
            'наименование'       => array_search('Наименование', $header),
            'нормочас'           => array_search('Нормочас', $header),
            'макс_нормочас'      => array_search('Макс. нормочас', $header),
            'применимость'       => array_search('Применимость шасси', $header),
        ];

        if (in_array(false, $colIdx, true)) {
            $messages[] = '❌ Не найдены нужные колонки. Ожидаются: Узел, Код, Наименование, Нормочас, Макс. нормочас, Применимость шасси.';
            $messages[] = 'Найдены: ' . implode(' | ', $header);
        } else {
            // Считаем все строки
            $rows = [];
            while (($r = fgetcsv($fh, 0, ',')) !== false) {
                if (count($r) < 6) continue;
                $rows[] = $r;
            }

            $messages[] = 'Прочитано строк: ' . count($rows);

            // Накапливаем данные по шасси
            $byChassis = []; // chassis => [ [code, parent, group?, name, norm], ... ]

            foreach ($rows as $r) {
                $uzel    = trim($r[$colIdx['узел']] ?? '');
                $code    = trim($r[$colIdx['код']] ?? '');
                $name    = trim($r[$colIdx['наименование']] ?? '');
                $normRaw = trim($r[$colIdx['нормочас']] ?? '');
                $maxRaw  = trim($r[$colIdx['макс_нормочас']] ?? '');
                $chassis = trim($r[$colIdx['применимость']] ?? '');

                if ($code === '' || $chassis === '') continue;

                // Нормочас
                $normRaw = str_replace(',', '.', $normRaw);
                $maxRaw  = str_replace(',', '.', $maxRaw);
                $normVal = is_numeric($normRaw) ? (float)$normRaw : null;
                $maxVal  = is_numeric($maxRaw)  ? (float)$maxRaw  : null;

                // Если основной нормочас 0, но есть Макс — используем его
                if (($normVal === null || $normVal == 0.0) && $maxVal !== null && $maxVal > 0) {
                    $normVal = $maxVal;
                }

                // Разбиваем применимость по запятой/точке с запятой
                $chassisList = preg_split('/\s*[,;]\s*/', $chassis);
                $chassisList = array_filter(array_map('trim', $chassisList));

                foreach ($chassisList as $ch) {
                    if (!isset($byChassis[$ch])) $byChassis[$ch] = [];

                    // Группа верхнего уровня — по «Узел»
                    $groupCode = 'UZEL_' . preg_replace('/\s+/u', '_', $uzel) . '@' . $ch;

                    $byChassis[$ch][] = [
                        'group_code' => $groupCode,
                        'group_name' => $uzel,
                        'op_code'    => $code,
                        'op_name'    => $name,
                        'norm'       => $normVal,
                    ];
                }
            }

            $messages[] = 'Шасси найдено: ' . implode(', ', array_keys($byChassis));

            // Записываем в базу
            $db->beginTransaction();
            try {
                $delStmt = $db->prepare("DELETE FROM work_operations WHERE brand = 'COMPASS' AND complectation = :c");
                $insGroup = $db->prepare("
                    INSERT INTO work_operations
                        (code, parent_code, it_is_group, name, operation_code, norm_time, complectation, model, brand, deleted, updated_at)
                    VALUES
                        (:code, NULL, TRUE, :name, NULL, NULL, :comp, :model, 'COMPASS', FALSE, NOW())
                ");
                $insWork = $db->prepare("
                    INSERT INTO work_operations
                        (code, parent_code, it_is_group, name, operation_code, norm_time, complectation, model, brand, deleted, updated_at)
                    VALUES
                        (:code, :parent, FALSE, :name, :opcode, :norm, :comp, :model, 'COMPASS', FALSE, NOW())
                ");

                foreach ($byChassis as $ch => $items) {
                    $model = 'KOMPAS_' . $ch;

                    // Удаляем старые записи по этому шасси
                    $delStmt->execute([':c' => $ch]);

                    // Вставляем группы (уникальные)
                    $seenGroups = [];
                    foreach ($items as $it) {
                        if (!isset($seenGroups[$it['group_code']])) {
                            $insGroup->execute([
                                ':code'  => $it['group_code'],
                                ':name'  => $it['group_name'],
                                ':comp'  => $ch,
                                ':model' => $model,
                            ]);
                            $seenGroups[$it['group_code']] = true;
                        }
                    }

                    // Вставляем работы
                    foreach ($items as $it) {
                        $insWork->execute([
                            ':code'   => $it['op_code'] . '@' . $ch,
                            ':parent' => $it['group_code'],
                            ':name'   => $it['op_name'],
                            ':opcode' => $it['op_code'],
                            ':norm'   => $it['norm'],
                            ':comp'   => $ch,
                            ':model'  => $model,
                        ]);
                    }

                    $messages[] = sprintf(
                        '✅ Шасси %s: удалено старых, вставлено %d работ и %d групп',
                        $ch,
                        count($items),
                        count($seenGroups)
                    );
                }

                $db->commit();
                $done = true;
                $messages[] = '🎉 Импорт завершён успешно.';
            } catch (Throwable $e) {
                $db->rollBack();
                $messages[] = '❌ Ошибка записи в БД: ' . $e->getMessage();
            }
        }

        fclose($fh);
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Загрузка справочника КОМПАС</title>
<style>
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        background: #f1f3f6;
        color: #1f2937;
        min-height: 100vh;
    }
    .topbar {
        display: flex; align-items: center; justify-content: space-between;
        padding: 16px 24px; background: #fff; border-bottom: 1px solid #e5e7eb;
    }
    .topbar h1 { margin: 0; font-size: 20px; font-weight: 600; }
    main { max-width: 720px; margin: 0 auto; padding: 32px 24px; }
    .back { display: inline-block; margin-bottom: 20px; color: #2563eb; text-decoration: none; font-size: 14px; }
    h2 { margin: 0 0 8px; font-size: 22px; }
    p.lead { color: #6b7280; margin: 0 0 24px; }
    .card {
        background: #fff; border: 1px solid #e5e7eb; border-radius: 14px;
        padding: 24px; box-shadow: 0 1px 2px rgba(0,0,0,.03);
    }
    input[type=file] { display: block; margin: 12px 0 20px; font-size: 15px; }
    button {
        background: #2563eb; color: #fff; border: 0; padding: 12px 22px;
        border-radius: 8px; font-size: 15px; font-weight: 600; cursor: pointer;
    }
    button:hover { background: #1d4ed8; }
    .messages { margin-top: 20px; }
    .messages div {
        padding: 10px 14px; margin-bottom: 8px; border-radius: 8px;
        background: #f3f4f6; font-size: 14px; font-family: ui-monospace, Menlo, monospace;
    }
    .messages div:last-child { margin-bottom: 0; }
</style>
</head>
<body>

<div class="topbar">
    <h1>🔧 Рабочее место инженера по гарантии</h1>
    <div style="font-size:14px;color:#4b5563;">
        👤 <?= e($user['name'] ?? $user['login'] ?? 'Пользователь') ?>
        &nbsp;·&nbsp;
        <a href="logout.php" style="color:#2563eb;text-decoration:none;">Выйти</a>
    </div>
</div>

<main>
    <a class="back" href="works_brand.php">← К выбору марки</a>
    <h2>Загрузка справочника работ КОМПАС</h2>
    <p class="lead">Загрузите CSV-файл, сохранённый из Excel в кодировке UTF-8. Данные по каждому шасси перезаписываются целиком.</p>

    <div class="card">
        <form method="post" enctype="multipart/form-data">
            <label style="font-weight:600;">CSV-файл:</label>
            <input type="file" name="csv" accept=".csv,text/csv" required>
            <button type="submit">Загрузить и обновить базу</button>
        </form>

        <?php if ($messages): ?>
            <div class="messages">
                <?php foreach ($messages as $m): ?>
                    <div><?= e($m) ?></div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</main>

</body>
</html>
