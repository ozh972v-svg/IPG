<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) { header('Location: login.php'); exit; }
$pdo = get_db();

// Текущая модель: из GET, или из сессии, или первая из БД
$models = $pdo->query("SELECT code, name FROM work_models ORDER BY code")->fetchAll();

$model = trim($_GET['model'] ?? '');
if ($model === '' && !empty($_SESSION['work_model'])) $model = $_SESSION['work_model'];
if ($model === '' && $models) $model = $models[0]['code'];
if ($model !== '') $_SESSION['work_model'] = $model;

$modelName = '';
foreach ($models as $m) {
    if ($m['code'] === $model) { $modelName = $m['name']; break; }
}

$q          = trim($_GET['q'] ?? '');
$groupCode  = trim($_GET['group'] ?? '');
$category   = trim($_GET['cat'] ?? '');
$guardOnly  = !empty($_GET['guard']);
$factOnly   = !empty($_GET['fact']);
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 100;

$CATEGORIES = [
    'A' => ['label' => 'Административные',          'prefix' => 'АХХ-ХХХ', 'desc' => 'Оформить заказ-наряд на ТО и ремонт'],
    'E' => ['label' => 'Диагностические',           'prefix' => 'ЕХХ-ХХХ', 'desc' => 'Работы по оценке состояния техники в целом'],
    'P' => ['label' => 'Постовые текущего ремонта','prefix' => 'РХХ-ХХХ (ТРП…)', 'desc' => 'Работы по снятию и установке изделий с автотехники, включая оценку состояния, слив/залив технических жидкостей и прокачку систем, регулировку после установки'],
    'C' => ['label' => 'Цеховые текущего ремонта',  'prefix' => 'СХХ-ХХХ (ТРЦ…)', 'desc' => 'Работы по разборке, очистке, оценке состояния, сборке, регулировке, обкатке и т.д., выполняемые в отношении изделий, снятых с автотехники'],
    'X' => ['label' => 'Ненормированная трудоёмкость', 'prefix' => '9999', 'desc' => 'Трудоёмкость работ определяется временем, фактически затраченным на их проведение'],
];

/* ===== ДЕРЕВО ===== */
$stmt = $pdo->prepare("
    SELECT code, parent_code, name
    FROM work_operations
    WHERE it_is_group = TRUE AND deleted = FALSE AND model = :m
    ORDER BY code
");
$stmt->execute([':m' => $model]);
$allGroups = $stmt->fetchAll();

$topGroups = [];
$subgroups = [];
foreach ($allGroups as $g) {
    $len = strlen($g['code']);
    if ($len === 2) $topGroups[] = $g;
    elseif ($len === 4) $subgroups[$g['parent_code'] ?? ''][] = $g;
}

/* ===== РАБОТЫ ===== */
$where  = ['w.it_is_group = FALSE', 'w.deleted = FALSE', 'w.model = :m'];
$params = [':m' => $model];

if ($q !== '') {
    $where[] = "(w.name ILIKE :q OR w.operation_code ILIKE :q OR w.code ILIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($groupCode !== '') {
    if (strlen($groupCode) === 2) {
        $where[] = "(w.parent_code = :g OR w.parent_code IN (SELECT code FROM work_operations WHERE parent_code = :g AND it_is_group = TRUE AND model = :m2))";
        $params[':m2'] = $model;
    } else {
        $where[] = "w.parent_code = :g";
    }
    $params[':g'] = $groupCode;
}
if ($category !== '' && isset($CATEGORIES[$category])) {
    $prefLetters = [
        'A' => ['А', 'A'], 'E' => ['Е', 'E'], 'P' => ['Р', 'P'],
        'C' => ['С', 'C'], 'X' => ['Х', 'X', '9'],
    ];
    $letters = $prefLetters[$category];
    $ors = [];
    foreach ($letters as $i => $L) {
        $key = ':p' . $category . $i;
        $ors[] = "w.operation_code LIKE $key";
        $params[$key] = $L . '%';
    }
    $where[] = '(' . implode(' OR ', $ors) . ')';
}
if ($guardOnly) $where[] = "w.guard_work = TRUE";
if ($factOnly)  $where[] = "w.fact_work = TRUE";
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM work_operations w $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare("
    SELECT w.code, w.name, w.operation_code, w.eng_name, w.description,
           w.guard_work, w.fact_work, w.norm_time, w.parent_code
    FROM work_operations w
    $whereSql
    ORDER BY w.operation_code NULLS LAST, w.name
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$pages = max(1, (int)ceil($total / $perPage));

/* ===== СТАТИСТИКА МОДЕЛИ ===== */
$stmt = $pdo->prepare("
    SELECT
        COUNT(*) FILTER (WHERE it_is_group = TRUE  AND deleted = FALSE AND model = :m) AS groups,
        COUNT(*) FILTER (WHERE it_is_group = FALSE AND deleted = FALSE AND model = :m) AS works,
        COUNT(*) FILTER (WHERE it_is_group = FALSE AND norm_time IS NOT NULL AND model = :m) AS with_norm
    FROM work_operations
");
$stmt->execute([':m' => $model]);
$stats = $stmt->fetch();

$currentGroup = null;
if ($groupCode !== '') {
    $stmt = $pdo->prepare("SELECT code, name FROM work_operations WHERE code = :c AND it_is_group = TRUE AND model = :m");
    $stmt->execute([':c' => $groupCode, ':m' => $model]);
    $currentGroup = $stmt->fetch() ?: null;
}
$stmt = $pdo->prepare("SELECT MAX(updated_at) FROM work_operations WHERE model = :m");
$stmt->execute([':m' => $model]);
$lastSync = $stmt->fetchColumn();

function fmtTs($ts) { return $ts ? date('d.m.Y H:i', strtotime($ts)) : '—'; }
function buildUrl($o = []) { return '?' . http_build_query(array_merge($_GET, $o)); }
function fmtNorm($n) {
    if ($n === null) return null;
    return rtrim(rtrim(number_format((float)$n, 3, ',', ' '), '0'), ',');
}
function opCategoryClass(?string $op): string {
    if ($op === null || $op === '') return '';
    $first = mb_substr($op, 0, 1);
    if (in_array($first, ['А','A'], true)) return 'cat-a';
    if (in_array($first, ['Е','E'], true)) return 'cat-e';
    if (in_array($first, ['Р','P'], true)) return 'cat-p';
    if (in_array($first, ['С','C'], true)) return 'cat-c';
    if (in_array($first, ['Х','X'], true)) return 'cat-x';
    if (preg_match('/^\d{4}$/', $op))        return 'cat-x';
    return '';
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Справочник работ</title>
<style>
  *{box-sizing:border-box}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;margin:0;padding:16px;color:#1a1a1a;line-height:1.5}
  .container{max-width:1400px;margin:0 auto}
  .card{background:#fff;border-radius:14px;padding:16px;box-shadow:0 2px 12px rgba(0,0,0,0.06);margin-bottom:12px}
  h1{font-size:22px;margin:0 0 8px} h2{font-size:16px;margin:0 0 12px;color:#1e3a8a}
  .top-bar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px}
  .user-info{font-size:13px;color:#666} .user-info b{color:#2563eb}
  .logout{color:#dc2626;text-decoration:none;font-size:13px;margin-left:12px}
  .btn{display:inline-block;padding:9px 14px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;background:#2563eb;color:#fff;text-decoration:none;text-align:center}
  .btn-secondary{background:#fff;color:#2563eb;border:1.5px solid #2563eb}
  .btn-small{padding:7px 12px;font-size:13px}
  .btn-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}

  .model-bar{background:#eff6ff;border-left:4px solid #2563eb;padding:10px 14px;border-radius:10px;font-size:13px;color:#1e3a8a;margin-bottom:12px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
  .model-bar label{font-weight:600}
  .model-bar select{padding:6px 10px;border:1.5px solid #93c5fd;border-radius:8px;font-family:inherit;font-size:13px;background:#fff;color:#1e3a8a;font-weight:600;cursor:pointer}
  .model-bar select:focus{outline:none;border-color:#2563eb}

  .legend-details summary{cursor:pointer;font-weight:600;color:#1e3a8a;font-size:14px;padding:8px 0}
  .legend-details summary::marker{color:#2563eb}
  .legend{display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:10px;margin-top:10px}
  .legend-item{padding:10px 14px;background:#f9fafb;border-radius:8px;border-left:3px solid #dc2626}
  .legend-item .code{font-family:'SF Mono',Consolas,monospace;font-weight:700;color:#dc2626;font-size:14px}
  .legend-item .label{font-weight:700;font-size:13px;color:#1a1a1a;margin:4px 0}
  .legend-item .desc{font-size:12px;color:#666;line-height:1.4}

  .layout{display:grid;grid-template-columns:340px 1fr;gap:12px}
  @media (max-width:900px){.layout{grid-template-columns:1fr}}

  .search-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
  .search-bar input[type=text]{flex:1;min-width:200px;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:14px;font-family:inherit}
  .search-bar input:focus{outline:none;border-color:#2563eb}
  .filters{display:flex;gap:16px;flex-wrap:wrap;font-size:13px;margin-top:8px;align-items:center}
  .filters label{display:flex;align-items:center;gap:5px;cursor:pointer}
  .cat-filters{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
  .cat-btn{padding:6px 10px;border-radius:8px;background:#f3f4f6;color:#333;font-size:12px;font-weight:600;text-decoration:none;border:1.5px solid transparent;display:flex;align-items:center;gap:6px}
  .cat-btn:hover{background:#e5e7eb}
  .cat-btn.active{background:#dc2626;color:#fff;border-color:#dc2626}
  .cat-dot{width:8px;height:8px;border-radius:50%;background:#dc2626}

  .tree{font-size:13px;max-height:75vh;overflow-y:auto}
  .tree > details > summary{padding:8px 10px;font-weight:700;color:#1e3a8a;cursor:pointer;border-radius:8px;display:flex;align-items:center;gap:8px;list-style:none}
  .tree > details > summary::-webkit-details-marker{display:none}
  .tree > details > summary::before{content:'▶';font-size:10px;color:#2563eb;transition:transform 0.15s}
  .tree > details[open] > summary::before{transform:rotate(90deg)}
  .tree > details > summary:hover{background:#f8faff}
  .tree > details > summary.selected{background:#eff6ff}
  .tree > details > summary a{text-decoration:none;color:inherit;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .tree-sub{display:block;padding:5px 10px 5px 30px;color:#333;border-radius:6px;margin-left:6px;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .tree-sub:hover{background:#f8faff;color:#2563eb}
  .tree-sub.selected{background:#eff6ff;color:#1e3a8a;font-weight:600}
  .tree-sub::before{content:'·';color:#cbd5e1;margin-right:6px}

  table.works{width:100%;border-collapse:collapse;font-size:13px}
  table.works th{background:#f9fafb;color:#666;font-weight:600;text-align:left;padding:10px 12px;border-bottom:2px solid #e5e7eb;font-size:11px;text-transform:uppercase;letter-spacing:0.4px}
  table.works td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:top}
  table.works tr:hover td{background:#fafbff}
  .op-code{padding:2px 8px;border-radius:5px;font-size:12px;font-weight:700;white-space:nowrap;font-family:'SF Mono',Consolas,monospace;background:#fef3c7;color:#92400e}
  .op-code.cat-a{background:#fee2e2;color:#991b1b}
  .op-code.cat-e{background:#fef3c7;color:#92400e}
  .op-code.cat-p{background:#dbeafe;color:#1e40af}
  .op-code.cat-c{background:#f3e8ff;color:#6b21a8}
  .op-code.cat-x{background:#f3f4f6;color:#4b5563}
  .work-name{color:#1a1a1a;font-weight:500}
  .work-eng{color:#888;font-size:11px;margin-top:3px}
  .work-desc{color:#666;font-size:11px;margin-top:4px;font-style:italic}
  .norm-time{background:#e0f2fe;color:#075985;padding:3px 10px;border-radius:6px;font-size:13px;font-weight:700;white-space:nowrap}
  .badge{display:inline-block;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:600;white-space:nowrap;margin-right:4px}
  .badge-guard{background:#f0fdf4;color:#16a34a}
  .badge-fact{background:#f3e8ff;color:#7c3aed}

  .stats{font-size:12px;color:#666;padding:8px 0;border-bottom:1px solid #f0f0f0;margin-bottom:8px}
  .stats b{color:#2563eb}
  .empty{text-align:center;padding:60px 20px;color:#999}
  .empty .big{font-size:48px;margin-bottom:8px}

  .pagination{display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin-top:16px}
  .pagination a,.pagination span{padding:7px 12px;border-radius:7px;text-decoration:none;font-size:13px;background:#fff;border:1.5px solid #e5e7eb;color:#2563eb}
  .pagination .active{background:#2563eb;color:#fff;border-color:#2563eb;font-weight:600}

  .warn{padding:12px 16px;border-radius:10px;background:#fffbeb;color:#b45309;border-left:4px solid #b45309;margin-bottom:12px;font-size:13px}
</style>
</head>
<body>
<div class="container">

  <div class="card">
    <div class="top-bar">
      <h1>🔧 Справочник работ — 1С:ГОА</h1>
      <div>
        <span class="user-info">👤 <b><?= e($user['name'] ?: $user['email']) ?></b></span>
        <a href="logout.php" class="logout">Выйти</a>
      </div>
    </div>
    <div class="btn-row">
      <a href="index.html" class="btn btn-secondary btn-small">← На рабочее место</a>
      <?php if ($user['is_admin']): ?>
        <a href="sync.php" class="btn btn-small">📥 Загрузка справочника</a>
      <?php endif; ?>
    </div>

    <div class="model-bar" style="margin-top:12px;">
      <label for="modelSelect">🚛 Модель автотехники:</label>
      <select id="modelSelect" onchange="if(this.value) window.location.href='?model='+encodeURIComponent(this.value);">
        <?php foreach ($models as $m): ?>
          <option value="<?= e($m['code']) ?>" <?= $m['code'] === $model ? 'selected' : '' ?>>
            <?= e($m['name']) ?>
          </option>
        <?php endforeach; ?>
      </select>
      <span style="color:#666;">Работ в модели: <b><?= number_format((int)$stats['works'], 0, '.', ' ') ?></b> · обновлено: <b><?= e(fmtTs($lastSync)) ?></b></span>
    </div>
  </div>

  <?php if ((int)$stats['works'] === 0): ?>
    <div class="warn">
      ⚠️ Для модели «<?= e($modelName ?: $model) ?>» нет работ.
      <?php if ($user['is_admin']): ?>Перейдите в <a href="sync.php">загрузку</a>.<?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <details class="legend-details">
      <summary>📖 Что означают категории работ (нажмите чтобы раскрыть)</summary>
      <div class="legend">
        <?php foreach ($CATEGORIES as $catKey => $cat): ?>
          <div class="legend-item">
            <div class="code"><?= e($cat['prefix']) ?></div>
            <div class="label"><?= e($cat['label']) ?></div>
            <div class="desc"><?= e($cat['desc']) ?></div>
          </div>
        <?php endforeach; ?>
      </div>
    </details>
  </div>

  <div class="layout">

    <div class="card">
      <h2>📁 Группы</h2>
      <div class="tree">
        <a href="?model=<?= urlencode($model) ?><?= $category ? '&cat=' . urlencode($category) : '' ?>" class="tree-sub <?= $groupCode === '' ? 'selected' : '' ?>" style="padding-left:10px;font-weight:700;color:#1e3a8a;background:<?= $groupCode === '' ? '#eff6ff' : 'transparent' ?>;">
          🏠 Все работы
        </a>
        <?php foreach ($topGroups as $g): ?>
          <?php $isOpen = ($groupCode === $g['code']) || strpos($groupCode, $g['code']) === 0; ?>
          <?php $sel = ($groupCode === $g['code']); ?>
          <details class="tree-details" <?= $isOpen ? 'open' : '' ?>>
            <summary class="<?= $sel ? 'selected' : '' ?>">
              <a href="?model=<?= urlencode($model) ?>&group=<?= urlencode($g['code']) ?><?= $category ? '&cat=' . urlencode($category) : '' ?>">
                <span style="color:#2563eb;font-size:12px;">📂</span>
                <?= e($g['name']) ?>
              </a>
            </summary>
            <?php foreach ($subgroups[$g['code']] ?? [] as $sub): ?>
              <?php $selSub = ($sub['code'] === $groupCode); ?>
              <a class="tree-sub <?= $selSub ? 'selected' : '' ?>"
                 href="?model=<?= urlencode($model) ?>&group=<?= urlencode($sub['code']) ?><?= $category ? '&cat=' . urlencode($category) : '' ?>">
                <?= e($sub['name']) ?>
              </a>
            <?php endforeach; ?>
          </details>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <h2>
        <?php if ($currentGroup): ?>
          📂 <?= e($currentGroup['name']) ?>
        <?php else: ?>
          📋 Все работы
        <?php endif; ?>
        <?php if ($category && isset($CATEGORIES[$category])): ?>
          <span style="font-weight:400;font-size:13px;color:#666;"> · категория: <b><?= e($CATEGORIES[$category]['label']) ?></b></span>
        <?php endif; ?>
      </h2>

      <form method="get">
        <input type="hidden" name="model" value="<?= e($model) ?>">
        <input type="hidden" name="group" value="<?= e($groupCode) ?>">
        <input type="hidden" name="cat" value="<?= e($category) ?>">
        <div class="search-bar">
          <input type="text" name="q" value="<?= e($q) ?>" placeholder="Поиск по названию, коду операции…">
          <button type="submit" class="btn">🔍 Найти</button>
          <?php if ($q !== '' || $guardOnly || $factOnly): ?>
            <a href="?model=<?= urlencode($model) ?><?= $groupCode ? '&group=' . urlencode($groupCode) : '' ?><?= $category ? '&cat=' . urlencode($category) : '' ?>" class="btn btn-secondary">Сбросить</a>
          <?php endif; ?>
        </div>
        <div class="filters">
          <label><input type="checkbox" name="guard" value="1" <?= $guardOnly ? 'checked' : '' ?>> Только постовые</label>
          <label><input type="checkbox" name="fact"  value="1" <?= $factOnly  ? 'checked' : '' ?>> Только фактические</label>
        </div>
      </form>

      <div class="cat-filters">
        <a href="?model=<?= urlencode($model) ?><?= $groupCode ? '&group=' . urlencode($groupCode) : '' ?>&q=<?= urlencode($q) ?>"
           class="cat-btn <?= $category === '' ? 'active' : '' ?>">Все категории</a>
        <?php foreach ($CATEGORIES as $catKey => $cat): ?>
          <a href="?model=<?= urlencode($model) ?><?= $groupCode ? '&group=' . urlencode($groupCode) : '' ?>&cat=<?= urlencode($catKey) ?>&q=<?= urlencode($q) ?>"
             class="cat-btn <?= $category === $catKey ? 'active' : '' ?>">
            <span class="cat-dot"></span><?= e($cat['prefix']) ?>
          </a>
        <?php endforeach; ?>
      </div>

      <div class="stats" style="margin-top:12px;">
        <?php if ($q !== ''): ?>Найдено: <b><?= number_format($total, 0, '.', ' ') ?></b> · <?php endif; ?>
        Всего работ в модели: <b><?= number_format((int)$stats['works'], 0, '.', ' ') ?></b>
        · Групп: <b><?= number_format((int)$stats['groups'], 0, '.', ' ') ?></b>
        · С нормой: <b><?= number_format((int)$stats['with_norm'], 0, '.', ' ') ?></b>
      </div>

      <?php if (!$rows): ?>
        <div class="empty"><div class="big">🔍</div>Ничего не найдено</div>
      <?php else: ?>
        <table class="works">
          <thead>
            <tr>
              <th style="width:130px;">Код операции</th>
              <th>Наименование работы</th>
              <th style="width:110px;">Норма</th>
              <th style="width:110px;">Флаги</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td>
                  <?php if ($r['operation_code']): ?>
                    <span class="op-code <?= opCategoryClass($r['operation_code']) ?>"><?= e($r['operation_code']) ?></span>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                  <div class="work-name"><?= e($r['name'] ?: '—') ?></div>
                  <?php if ($r['eng_name']): ?><div class="work-eng"><?= e($r['eng_name']) ?></div><?php endif; ?>
                  <?php if ($r['description'] && mb_strlen($r['description']) < 400): ?><div class="work-desc"><?= e($r['description']) ?></div><?php endif; ?>
                </td>
                <td>
                  <?php if ($r['norm_time'] !== null): ?>
                    <span class="norm-time"><?= e(fmtNorm($r['norm_time'])) ?> ч</span>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                  <?php if ($r['guard_work']): ?><span class="badge badge-guard">Постовая</span><?php endif; ?>
                  <?php if ($r['fact_work']):  ?><span class="badge badge-fact">Факт</span><?php endif; ?>
                  <?php if (!$r['guard_work'] && !$r['fact_work']): ?>—<?php endif; ?>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>

        <?php if ($pages > 1): ?>
          <div class="pagination">
            <?php if ($page > 1): ?><a href="<?= e(buildUrl(['page' => $page - 1])) ?>">← Назад</a><?php endif; ?>
            <span class="active"><?= $page ?></span>
            <span>из <?= $pages ?></span>
            <?php if ($page < $pages): ?><a href="<?= e(buildUrl(['page' => $page + 1])) ?>">Вперёд →</a><?php endif; ?>
          </div>
        <?php endif; ?>
      <?php endif; ?>
    </div>

  </div>
</div>
</body>
</html>
