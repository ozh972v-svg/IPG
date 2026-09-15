<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) { header('Location: login.php'); exit; }

$pdo = get_db();

$q           = trim($_GET['q'] ?? '');
$typeFilter  = $_GET['type'] ?? 'all';
$guardOnly   = !empty($_GET['guard']);
$factOnly    = !empty($_GET['fact']);
$showDeleted = !empty($_GET['show_deleted']);
$expandAll   = !empty($_GET['expand_all']);
$page        = max(1, (int)($_GET['page'] ?? 1));
$perPage     = 100;

$hasFilters = ($q !== '' || $typeFilter !== 'all' || $guardOnly || $factOnly || $showDeleted);

$where = [];
$params = [];

if ($q !== '') {
    $where[] = "(code ILIKE :q OR name ILIKE :q OR name_work ILIKE :q OR operation_code ILIKE :q OR eng_name ILIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($typeFilter === 'group') $where[] = "it_is_group = TRUE";
if ($typeFilter === 'item')  $where[] = "it_is_group = FALSE";
if ($guardOnly)   $where[] = "guard_work = TRUE";
if ($factOnly)    $where[] = "fact_work = TRUE";
if (!$showDeleted) $where[] = "deleted = FALSE";
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// ====== РЕЖИМ 1: ДЕРЕВО (без фильтров) ======
$tree = null;
$roots = [];
if (!$hasFilters) {
    $stmt = $pdo->query("SELECT * FROM work_operations WHERE deleted = FALSE ORDER BY code");
    $all = $stmt->fetchAll();

    $byCode = [];
    $byParent = [];
    foreach ($all as $r) {
        $byCode[$r['code']] = $r;
        $byParent[$r['parent_code'] ?? '__ROOT__'][] = $r;
    }
    foreach ($all as $r) {
        $pc = $r['parent_code'] ?? '';
        if ($pc === '' || !isset($byCode[$pc])) {
            $roots[] = $r;
        }
    }
    $tree = ['byParent' => $byParent, 'byCode' => $byCode];
} else {
    // ====== РЕЖИМ 2: ПЛОСКИЙ СПИСОК (с фильтрами) ======
    $stmt = $pdo->prepare("SELECT COUNT(*) FROM work_operations $whereSql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare("SELECT * FROM work_operations $whereSql ORDER BY code LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $pages = max(1, (int)ceil($total / $perPage));
}

// Статистика
$stats = $pdo->query("
    SELECT COUNT(*) AS total,
           COUNT(*) FILTER (WHERE it_is_group = TRUE) AS groups,
           COUNT(*) FILTER (WHERE it_is_group = FALSE) AS items
    FROM work_operations WHERE deleted = FALSE
")->fetch();

$lastSync = $pdo->query("SELECT MAX(updated_at) FROM work_operations")->fetchColumn();

function fmtTs($ts) { return $ts ? date('d.m.Y H:i', strtotime($ts)) : '—'; }
function buildUrl($o = []) { return '?' . http_build_query(array_merge($_GET, $o)); }

/**
 * Рекурсивный рендер узла дерева.
 */
function renderNode(array $node, array $byParent, int $depth = 0): void {
    $code = $node['code'];
    $children = $byParent[$code] ?? [];
    $isGroup = $node['it_is_group'];
    $name = $node['name'] ?: ($node['name_work'] ?: $code);
    $hasChildren = count($children) > 0;

    $indent = $depth * 16;
    $badges = '';
    if ($isGroup) $badges .= '<span class="badge badge-blue">Группа</span> ';
    if ($node['guard_work']) $badges .= '<span class="badge badge-green">Постовая</span> ';
    if ($node['fact_work'])  $badges .= '<span class="badge badge-gray">Факт</span> ';

    if ($hasChildren) {
        echo '<details class="tree-node">';
        echo '<summary style="padding-left:' . $indent . 'px;">';
        echo '<code class="code-cell">' . e($code) . '</code> ';
        echo '<span class="node-name">' . e($name) . '</span> ';
        echo $badges;
        echo '<span class="node-count">(' . count($children) . ')</span>';
        echo '</summary>';
        echo '<div style="padding-left:' . ($indent + 16) . 'px;border-left:2px solid #e5e7eb;margin-left:' . ($indent + 8) . 'px;">';
        foreach ($children as $child) {
            renderNode($child, $byParent, $depth + 1);
        }
        echo '</div>';
        echo '</details>';
    } else {
        echo '<div class="tree-leaf" style="padding-left:' . ($indent + 24) . 'px;">';
        echo '<code class="code-cell">' . e($code) . '</code> ';
        echo '<span class="node-name">' . e($name) . '</span> ';
        if ($node['operation_code']) echo '<code style="background:#fef3c7;color:#b45309;">' . e($node['operation_code']) . '</code> ';
        echo $badges;
        if ($node['eng_name']) echo '<span class="node-eng">' . e($node['eng_name']) . '</span>';
        echo '</div>';
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Поиск по работам</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background:#f0f2f5; margin:0; padding:16px; color:#1a1a1a; line-height:1.5; }
  .container { max-width:1200px; margin:0 auto; }
  .card { background:#fff; border-radius:14px; padding:20px; box-shadow:0 2px 12px rgba(0,0,0,0.06); margin-bottom:16px; }
  h1 { font-size:22px; margin:0 0 8px; }
  h2 { font-size:18px; margin:0 0 16px; }
  .top-bar { display:flex; justify-content:space-between; align-items:center; flex-wrap:wrap; gap:8px; margin-bottom:16px; }
  .user-info { font-size:13px; color:#666; }
  .user-info b { color:#2563eb; }
  .logout { color:#dc2626; text-decoration:none; font-size:13px; margin-left:12px; }
  .btn { display:inline-block; padding:10px 16px; border:none; border-radius:10px; font-size:14px; font-weight:600; cursor:pointer; background:#2563eb; color:#fff; text-decoration:none; text-align:center; }
  .btn:hover { opacity:0.9; }
  .btn-secondary { background:#fff; color:#2563eb; border:1.5px solid #2563eb; }
  .btn-small { padding:8px 14px; font-size:13px; }
  .btn-row { display:flex; gap:10px; flex-wrap:wrap; }
  .search-bar { display:flex; gap:8px; flex-wrap:wrap; align-items:center; }
  .search-bar input[type=text] { flex:1; min-width:200px; padding:12px; border:1.5px solid #e5e7eb; border-radius:10px; font-size:15px; font-family:inherit; }
  .search-bar input[type=text]:focus { outline:none; border-color:#2563eb; }
  .search-bar select { padding:12px; border:1.5px solid #e5e7eb; border-radius:10px; font-size:14px; font-family:inherit; }
  .filter-row { display:flex; gap:16px; flex-wrap:wrap; margin-top:12px; font-size:14px; }
  .filter-row label { display:flex; align-items:center; gap:6px; cursor:pointer; color:#444; }
  .stats { font-size:13px; color:#666; margin-bottom:12px; }
  .stats b { color:#2563eb; }

  /* Дерево */
  .tree { font-size:13px; }
  .tree-node summary { cursor:pointer; padding:6px 8px; border-radius:6px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .tree-node summary:hover { background:#f8faff; }
  .tree-node summary::marker { color:#2563eb; }
  .tree-leaf { padding:5px 8px; display:flex; align-items:center; gap:8px; flex-wrap:wrap; }
  .tree-leaf:hover { background:#f8faff; border-radius:6px; }
  .code-cell { background:#eff6ff; color:#1e3a8a; padding:2px 6px; border-radius:4px; font-size:12px; font-weight:600; }
  .node-name { color:#1a1a1a; font-weight:500; }
  .node-eng { color:#888; font-size:11px; }
  .node-count { color:#999; font-size:11px; }

  .badge { display:inline-block; padding:2px 8px; border-radius:6px; font-size:11px; font-weight:600; white-space:nowrap; }
  .badge-blue { background:#eff6ff; color:#2563eb; }
  .badge-green { background:#f0fdf4; color:#16a34a; }
  .badge-gray { background:#f3f4f6; color:#666; }
  .badge-red { background:#fef2f2; color:#dc2626; }

  /* Таблица */
  table.doc-table { width:100%; border-collapse:collapse; font-size:13px; }
  table.doc-table th { background:#f9fafb; color:#666; font-weight:600; text-align:left; padding:10px 12px; border-bottom:1px solid #e5e7eb; font-size:12px; text-transform:uppercase; }
  table.doc-table td { padding:10px 12px; border-bottom:1px solid #f0f0f0; vertical-align:top; }
  table.doc-table tr:hover td { background:#fafbff; }

  .pagination { display:flex; gap:8px; flex-wrap:wrap; margin-top:16px; align-items:center; }
  .pagination a, .pagination span { padding:8px 12px; border-radius:8px; text-decoration:none; font-size:14px; background:#fff; border:1.5px solid #e5e7eb; color:#2563eb; }
  .pagination .active { background:#2563eb; color:#fff; border-color:#2563eb; font-weight:600; }
  .empty { text-align:center; padding:40px 20px; color:#888; }
  .warn { padding:12px 16px; border-radius:10px; background:#fffbeb; color:#b45309; border-left:4px solid #b45309; margin-bottom:16px; font-size:14px; }
</style>
</head>
<body>
<div class="container">

  <div class="card">
    <div class="top-bar">
      <h1>🔧 Поиск по работам</h1>
      <div>
        <span class="user-info">👤 <b><?= e($user['name'] ?: $user['email']) ?></b></span>
        <a href="logout.php" class="logout">Выйти</a>
      </div>
    </div>
    <div class="btn-row">
      <a href="index.html" class="btn btn-secondary btn-small">← На рабочее место</a>
      <?php if ($user['is_admin']): ?>
        <a href="sync.php?type=works" class="btn btn-small">🔄 Синхронизация с 1С</a>
      <?php endif; ?>
    </div>
  </div>

  <?php if ((int)$stats['total'] === 0): ?>
    <div class="warn">
      ⚠️ Справочник работ пуст.
      <?php if ($user['is_admin']): ?>
        Перейдите в <a href="sync.php?type=works">синхронизацию</a> и запустите обновление.
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <div class="card">
    <form method="get">
      <div class="search-bar">
        <input type="text" name="q" value="<?= e($q) ?>" placeholder="Поиск по коду, названию, коду операции…">
        <button type="submit" class="btn">Найти</button>
        <?php if ($hasFilters): ?>
          <a href="works.php" class="btn btn-secondary">Сбросить</a>
        <?php endif; ?>
      </div>
      <div class="filter-row">
        <label>
          Тип:
          <select name="type">
            <option value="all"   <?= $typeFilter==='all'   ? 'selected' : '' ?>>все</option>
            <option value="group" <?= $typeFilter==='group' ? 'selected' : '' ?>>только группы</option>
            <option value="item"  <?= $typeFilter==='item'  ? 'selected' : '' ?>>только работы</option>
          </select>
        </label>
        <label><input type="checkbox" name="guard" value="1" <?= $guardOnly ? 'checked' : '' ?>> Постовые</label>
        <label><input type="checkbox" name="fact"  value="1" <?= $factOnly  ? 'checked' : '' ?>> Фактические</label>
        <label><input type="checkbox" name="show_deleted" value="1" <?= $showDeleted ? 'checked' : '' ?>> Показывать удалённые</label>
      </div>
    </form>
  </div>

  <div class="card">
    <div class="stats">
      Всего: <b><?= number_format((int)$stats['total'], 0, '.', ' ') ?></b>
      (групп: <b><?= (int)$stats['groups'] ?></b>, работ: <b><?= (int)$stats['items'] ?></b>)
      · Последняя синхронизация: <b><?= e(fmtTs($lastSync)) ?></b>
      <?php if ($hasFilters && isset($total)): ?> · Найдено: <b><?= number_format($total, 0, '.', ' ') ?></b><?php endif; ?>
    </div>

    <?php if (!$hasFilters): ?>
      <h2>📁 Дерево работ</h2>
      <p style="font-size:13px;color:#666;margin-bottom:12px;">
        Клик по группе — раскрыть/свернуть. <a href="?expand_all=1">Развернуть все</a>
      </p>
      <div class="tree">
        <?php if (empty($roots)): ?>
          <div class="empty"><div style="font-size:48px;">📭</div>Нет данных</div>
        <?php else: ?>
          <?php foreach ($roots as $node): ?>
            <?php renderNode($node, $tree['byParent'], 0); ?>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
    <?php else: ?>
      <h2>📋 Результаты поиска</h2>
      <?php if (empty($rows)): ?>
        <div class="empty"><div style="font-size:48px;">🔍</div>Ничего не найдено</div>
      <?php else: ?>
        <table class="doc-table">
          <thead>
            <tr>
              <th>Код</th>
              <th>Наименование</th>
              <th>Код операции</th>
              <th>Работа</th>
              <th>Флаги</th>
              <th>Родитель</th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($rows as $r): ?>
              <tr>
                <td><code><?= e($r['code']) ?></code></td>
                <td>
                  <?= e($r['name'] ?: '—') ?>
                  <?php if ($r['eng_name']): ?>
                    <div style="color:#888;font-size:11px;margin-top:2px;"><?= e($r['eng_name']) ?></div>
                  <?php endif; ?>
                </td>
                <td><?= $r['operation_code'] ? '<code>' . e($r['operation_code']) . '</code>' : '—' ?></td>
                <td><?= e($r['name_work'] ?: '—') ?></td>
                <td>
                  <?php if ($r['it_is_group']): ?><span class="badge badge-blue">Группа</span><?php endif; ?>
                  <?php if ($r['guard_work']): ?><span class="badge badge-green">Постовая</span><?php endif; ?>
                  <?php if ($r['fact_work']): ?><span class="badge badge-gray">Факт</span><?php endif; ?>
                  <?php if ($r['deleted']): ?><span class="badge badge-red">Удалена</span><?php endif; ?>
                </td>
                <td><?= $r['parent_code'] ? '<code>' . e($r['parent_code']) . '</code>' : '—' ?></td>
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
    <?php endif; ?>
  </div>

</div>
</body>
</html>
