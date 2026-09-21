<?php
require_once __DIR__ . '/db.php';
start_session();
$user = current_user();

// Соответствие: модель → шасси
$models = [
    '5'  => ['chassis' => '43085', 'name' => 'Компас 5'],
    '6'  => ['chassis' => '43086', 'name' => 'Компас 6'],
    '9'  => ['chassis' => '43089', 'name' => 'Компас 9'],
    '12' => ['chassis' => '43082', 'name' => 'Компас 12'],
];

$modelKey = isset($_GET['model']) && isset($models[$_GET['model']]) ? $_GET['model'] : '5';
$chassis  = $models[$modelKey]['chassis'];

$db = get_db();

// Поиск
$search = trim($_GET['q'] ?? '');

// Список групп (узлов) для выбранного шасси
$sqlGroups = "SELECT code, name FROM work_operations
              WHERE brand = 'COMPASS' AND complectation = :ch AND it_is_group = TRUE
              ORDER BY name";
$st = $db->prepare($sqlGroups);
$st->execute([':ch' => $chassis]);
$groups = $st->fetchAll(PDO::FETCH_ASSOC);

// Выбранная группа
$group = $_GET['group'] ?? ($groups[0]['code'] ?? null);

// Работы выбранной группы (или результаты поиска)
if ($search !== '') {
    $sqlWorks = "SELECT code, operation_code, name, norm_time
                 FROM work_operations
                 WHERE brand = 'COMPASS' AND complectation = :ch AND it_is_group = FALSE
                   AND (name ILIKE :q OR operation_code ILIKE :q)
                 ORDER BY operation_code
                 LIMIT 500";
    $st = $db->prepare($sqlWorks);
    $st->execute([':ch' => $chassis, ':q' => '%' . $search . '%']);
    $works = $st->fetchAll(PDO::FETCH_ASSOC);
} else if ($group) {
    $sqlWorks = "SELECT code, operation_code, name, norm_time
                 FROM work_operations
                 WHERE brand = 'COMPASS' AND complectation = :ch AND it_is_group = FALSE
                   AND parent_code = :parent
                 ORDER BY operation_code";
    $st = $db->prepare($sqlWorks);
    $st->execute([':ch' => $chassis, ':parent' => $group]);
    $works = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
    $works = [];
}

// Имя выбранной группы для заголовка
$groupName = '';
foreach ($groups as $g) {
    if ($g['code'] === $group) { $groupName = $g['name']; break; }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Справочник работ — КОМПАС</title>
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
        padding: 14px 24px; background: #fff; border-bottom: 1px solid #e5e7eb;
        flex-wrap: wrap; gap: 12px;
    }
    .topbar h1 { margin: 0; font-size: 18px; font-weight: 600; }
    .topbar .user {
        display: flex; gap: 12px; align-items: center;
        font-size: 14px; color: #4b5563;
    }
    .topbar .user a { color: #2563eb; text-decoration: none; }

    .container {
        max-width: 1200px;
        margin: 0 auto;
        padding: 24px;
    }

    .back {
        display: inline-block; margin-bottom: 16px;
        color: #2563eb; text-decoration: none; font-size: 14px;
    }

    .model-bar {
        display: flex; gap: 8px; flex-wrap: wrap; margin-bottom: 16px;
    }
    .model-bar a {
        padding: 10px 18px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        text-decoration: none;
        color: #1f2937;
        font-weight: 500;
        font-size: 15px;
        transition: all .12s;
    }
    .model-bar a:hover { border-color: #cbd5e1; }
    .model-bar a.active {
        background: #2563eb; border-color: #2563eb; color: #fff;
    }

    .search-box {
        margin-bottom: 20px;
    }
    .search-box input {
        width: 100%;
        padding: 12px 16px;
        font-size: 15px;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #fff;
        outline: none;
    }
    .search-box input:focus { border-color: #2563eb; }

    .layout {
        display: grid;
        grid-template-columns: 280px 1fr;
        gap: 20px;
        align-items: start;
    }
    @media (max-width: 800px) {
        .layout { grid-template-columns: 1fr; }
    }

    .sidebar, .content {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 8px;
        box-shadow: 0 1px 2px rgba(0,0,0,.03);
    }

    .sidebar {
        max-height: 75vh;
        overflow-y: auto;
    }
    .sidebar a {
        display: block;
        padding: 10px 14px;
        border-radius: 8px;
        text-decoration: none;
        color: #1f2937;
        font-size: 14px;
        line-height: 1.35;
    }
    .sidebar a:hover { background: #f3f4f6; }
    .sidebar a.active {
        background: #dbeafe;
        color: #1d4ed8;
        font-weight: 600;
    }

    .content {
        padding: 20px 24px;
        min-height: 300px;
    }
    .content h2 {
        margin: 0 0 4px; font-size: 18px; font-weight: 600;
    }
    .content .count {
        color: #6b7280; font-size: 13px; margin: 0 0 16px;
    }

    table.works {
        width: 100%;
        border-collapse: collapse;
        font-size: 14px;
    }
    table.works th, table.works td {
        padding: 10px 8px;
        border-bottom: 1px solid #f1f5f9;
        text-align: left;
        vertical-align: top;
    }
    table.works th {
        font-weight: 600;
        color: #6b7280;
        font-size: 12px;
        text-transform: uppercase;
        letter-spacing: .03em;
    }
    table.works tr:hover td { background: #f9fafb; }
    table.works .code {
        font-family: ui-monospace, Menlo, monospace;
        color: #4b5563;
        white-space: nowrap;
        font-size: 13px;
    }
    table.works .norm {
        white-space: nowrap;
        text-align: right;
        font-variant-numeric: tabular-nums;
        font-weight: 600;
    }
    .empty {
        color: #9ca3af;
        padding: 40px 0;
        text-align: center;
    }
</style>
</head>
<body>

<div class="topbar">
    <h1>🚚 Справочник работ — КОМПАС</h1>
    <div class="user">
        <?php if ($user): ?>
            <span>👤 <?= e($user['name'] ?? $user['login'] ?? 'Пользователь') ?></span>
            <a href="logout.php">Выйти</a>
        <?php else: ?>
            <a href="login.php">Войти</a>
        <?php endif; ?>
    </div>
</div>

<div class="container">
    <a class="back" href="works_brand.php">← К выбору марки</a>

    <div class="model-bar">
        <?php foreach ($models as $key => $m): ?>
            <a href="?model=<?= e($key) ?>"
               class="<?= $key === $modelKey ? 'active' : '' ?>">
                <?= e($m['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <form class="search-box" method="get">
        <input type="hidden" name="model" value="<?= e($modelKey) ?>">
        <input type="text" name="q" value="<?= e($search) ?>"
               placeholder="Поиск по названию работы или коду (например, «масляный фильтр» или LT1004210)">
    </form>

    <div class="layout">

        <aside class="sidebar">
            <?php if (!$groups): ?>
                <div style="padding: 20px; color:#9ca3af;">Групп не найдено.</div>
            <?php else: ?>
                <?php foreach ($groups as $g): ?>
                    <a href="?model=<?= e($modelKey) ?>&group=<?= urlencode($g['code']) ?>"
                       class="<?= $g['code'] === $group && $search === '' ? 'active' : '' ?>">
                        <?= e($g['name']) ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </aside>

        <section class="content">
            <?php if ($search !== ''): ?>
                <h2>Результаты поиска: «<?= e($search) ?>»</h2>
                <p class="count">Найдено: <?= count($works) ?></p>
            <?php elseif ($group): ?>
                <h2><?= e($groupName) ?></h2>
                <p class="count">Работ в группе: <?= count($works) ?></p>
            <?php else: ?>
                <h2>Работы</h2>
                <p class="count">Выберите группу слева.</p>
            <?php endif; ?>

            <?php if (!$works): ?>
                <div class="empty">Ничего не найдено.</div>
            <?php else: ?>
                <table class="works">
                    <thead>
                        <tr>
                            <th style="width:110px;">Код</th>
                            <th>Наименование работы</th>
                            <th style="width:90px;text-align:right;">Н/ч</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($works as $w): ?>
                            <tr>
                                <td class="code"><?= e($w['operation_code']) ?></td>
                                <td><?= e($w['name']) ?></td>
                                <td class="norm">
                                    <?= $w['norm_time'] !== null ? e(number_format((float)$w['norm_time'], 2, ',', '')) : '—' ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </section>

    </div>
</div>

</body>
</html>
