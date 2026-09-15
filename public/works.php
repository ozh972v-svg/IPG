<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) { header('Location: login.php'); exit; }
$pdo = get_db();

$q          = trim($_GET['q'] ?? '');
$groupCode  = trim($_GET['group'] ?? '');
$guardOnly  = !empty($_GET['guard']);
$factOnly   = !empty($_GET['fact']);
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 100;

/* ----- Левая панель: дерево групп ----- */
$stmt = $pdo->query("SELECT * FROM work_operations WHERE it_is_group = TRUE AND deleted = FALSE ORDER BY code");
$allGroups = $stmt->fetchAll();

$groupByParent = [];
$groupByCode   = [];
foreach ($allGroups as $g) {
    $groupByCode[$g['code']] = $g;
    $pc = $g['parent_code'] ?? '__ROOT__';
    $groupByParent[$pc][] = $g;
}
$rootGroups = [];
foreach ($allGroups as $g) {
    $pc = $g['parent_code'] ?? '';
    if ($pc === '' || !isset($groupByCode[$pc])) $rootGroups[] = $g;
}

/* ----- Правая панель: работы выбранной группы ----- */
// ВАЖНО: во всех условиях используем префикс w. — потому что в запросе есть JOIN
$where  = ['w.it_is_group = FALSE', 'w.deleted = FALSE'];
$params = [];

if ($q !== '') {
    $where[] = "(w.code ILIKE :q OR w.name ILIKE :q OR w.name_work ILIKE :q OR w.operation_code ILIKE :q OR w.eng_name ILIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($groupCode !== '') {
    $where[] = "w.parent_code = :g";
    $params[':g'] = $groupCode;
}
if ($guardOnly) $where[] = "w.guard_work = TRUE";
if ($factOnly)  $where[] = "w.fact_work = TRUE";
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare("SELECT COUNT(*) FROM work_operations w $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare("
    SELECT w.*, p.name AS group_name
    FROM work_operations w
    LEFT JOIN work_operations p ON p.code = w.parent_code
    $whereSql
    ORDER BY w.operation_code NULLS LAST, w.name
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();
$pages = max(1, (int)ceil($total / $perPage));

// Общая статистика (без JOIN — префиксы не нужны)
$stats = $pdo->query("
    SELECT COUNT(*) FILTER (WHERE it_is_group = FALSE AND deleted = FALSE) AS items,
           COUNT(*) FILTER (WHERE it_is_group = TRUE  AND deleted = FALSE) AS groups
    FROM work_operations
")->fetch();

$currentGroup = $groupCode !== '' && isset($groupByCode[$groupCode]) ? $groupByCode[$groupCode] : null;
$lastSync = $pdo->query("SELECT MAX(updated_at) FROM work_operations")->fetchColumn();

function fmtTs($ts) { return $ts ? date('d.m.Y H:i', strtotime($ts)) : '—'; }
function buildUrl($o = []) { return '?' . http_build_query(array_merge($_GET, $o)); }

function renderGroup(array $g, array $byParent, string $selectedCode, int $depth = 0): void {
    $code = $g['code'];
    $children = $byParent[$code] ?? [];
    $isSelected = ($code === $selectedCode);
    $indent = $depth * 14;
    $name = $g['name'] ?: $code;
    $display = preg_replace('/^\d+[\.\s]+/u', '', $name);
    $prefix  = '';
    if (preg_match('/^(\d+[\.]?)/u', $name, $mm)) $prefix = $mm[1];

    echo '<div class="tree-row' . ($isSelected ? ' selected' : '') . '" style="padding-left:' . (8 + $indent) . 'px;">';
    if ($prefix) echo '<span class="tree-prefix">' . e($prefix) . '</span>';
    echo '<a href="?group=' . urlencode($code) . '" class="tree-link">' . e($display) . '</a>';
    echo '</div>';

    foreach ($children as $c) {
        renderGroup($c, $byParent, $selectedCode, $depth + 1);
    }
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
  .btn-row{display:flex;gap:10px;flex-wrap:wrap}

  .layout{display:grid;grid-template-columns:340px 1fr;gap:12px}
  @media (max-width:900px){.layout{grid-template-columns:1fr}}

  .search-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
  .search-bar input[type=text]{flex:1;min-width:200px;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:14px;font-family:inherit}
  .search-bar input:focus{outline:none;border-color:#2563eb}
  .filters{display:flex;gap:16px;flex-wrap:wrap;font-size:13px;margin-top:8px}
  .filters label{display:flex;align-items:center;gap:5px;cursor:pointer}

  .tree{font-size:13px;max-height:80vh;overflow-y:auto}
  .tree-row{padding:5px 8px;border-radius:6px;display:flex;align-items:center;gap:6px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
  .tree-row:hover{background:#f8faff}
  .tree-row.selected{background:#eff6ff;font-weight:600}
  .tree-row.selected .tree-link{color:#1e3a8a}
  .tree-prefix{color:#2563eb;font-weight:700;font-size:12px;min-width:38px;display:inline-block}
  .tree-link{color:#333;text-decoration:none;overflow:hidden;text-overflow:ellipsis}
  .tree-link:hover{color:#2563eb}
  .tree-group-header{padding:8px;color:#999;font-size:11px;text-transform:uppercase;letter-spacing:0.5px}

  table.works{width:100%;border-collapse:collapse;font-size:13px}
  table.works th{background:#f9fafb;color:#666;font-weight:600;text-align:left;padding:10px 12px;border-bottom:2px solid #e5e7eb;font-size:11px;text-transform:uppercase;letter-spacing:0.4px}
  table.works td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:top}
  table.works tr:hover td{background:#fafbff}
  .op-code{background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:5px;font-size:12px;font-weight:700;white-space:nowrap;font-family:'SF Mono',Consolas,monospace}
  .work-name{color:#1a1a1a;font-weight:500}
  .work-eng{color:#888;font-size:11px;margin-top:3px}
  .work-desc{color:#666;font-size:11px;margin-top:4px;font-style:italic}
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
        <a href="sync.php?type=works" class="btn btn-small">🔄 Синхронизация</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ((int)$stats['items'] === 0): ?>
    <div class="warn">⚠️ Справочник пуст. <?php if ($user['is_admin']): ?>Перейдите в <a href="sync.php?type=works">синхронизацию</a>.<?php endif; ?></div>
  <?php endif; ?>

  <div class="layout">

    <div class="card">
      <h2>📁 Группы</h2>
      <div class="tree">
        <div class="tree-row <?= $groupCode === '' && $q === '' ? 'selected' : '' ?>">
          <a href="?" class="tree-link" style="font-weight:600;">🏠 Все работы</a>
        </div>
        <div class="tree-group-header">Дерево</div>
        <?php foreach ($rootGroups as $g): ?>
          <?php renderGroup($g, $groupByParent, $groupCode); ?>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="card">
      <h2>
        <?php if ($currentGroup): ?>
          <?php $cn = $currentGroup['name'] ?: $currentGroup['code']; echo '📂 ' . e($cn); ?>
        <?php else: ?>
          📋 Все работы
        <?php endif; ?>
      </h2>

      <form method="get">
        <input type="hidden" name="group" value="<?= e($groupCode) ?>">
        <div class="search-bar">
          <input type="text" name="q" value="<?= e($q) ?>" placeholder="Поиск по названию, коду операции, английскому…">
          <button type="submit" class="btn">🔍 Найти</button>
          <?php if ($q !== '' || $guardOnly || $factOnly): ?>
            <a href="?<?= $groupCode ? 'group=' . urlencode($groupCode) : '' ?>" class="btn btn-secondary">Сбросить</a>
          <?php endif; ?>
        </div>
        <div class="filters">
          <label><input type="checkbox" name="guard" value="1" <?= $guardOnly ? 'checked' : '' ?>> Только постовые</label>
          <label><input type="checkbox" name="fact"  value="1" <?= $factOnly  ? 'checked' : '' ?>> Только фактические</label>
        </div>
      </form>

      <div class="stats" style="margin-top:12px;">
        <?php if ($q !== ''): ?>Найдено: <b><?= number_format($total, 0, '.', ' ') ?></b> · <?php endif; ?>
        Всего работ: <b><?= number_format((int)$stats['items'], 0, '.', ' ') ?></b>
        · Групп: <b><?= number_format((int)$stats['groups'], 0, '.', ' ') ?></b>
        · Обновлено: <b><?= e(fmtTs($lastSync)) ?></b>
      </div>

      <?php if (!$rows): ?>
        <div class="empty"><div class="big">🔍</div>Ничего не найдено</div>
      <?php else: ?>
        <table class="works">
          <thead>
            <tr>
              <th style="width:130px;">Код операции</th>
              <th>Наименование работы</th>
              <th style="width:110px;">Флаги</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td>
                  <?php if ($r['operation_code']): ?>
                    <span class="op-code"><?= e($r['operation_code']) ?></span>
                  <?php else: ?>—<?php endif; ?>
                </td>
                <td>
                  <div class="work-name"><?= e($r['name'] ?: ($r['name_work'] ?: '—')) ?></div>
                  <?php if ($r['eng_name']): ?>
                    <div class="work-eng"><?= e($r['eng_name']) ?></div>
                  <?php endif; ?>
                  <?php if ($r['description'] && mb_strlen($r['description']) < 400): ?>
                    <div class="work-desc"><?= e($r['description']) ?></div>
                  <?php endif; ?>
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
