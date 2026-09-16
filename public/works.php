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

/* ===== ДЕРЕВО ===== */
// Группы: код 2 цифры (00, 09, 10, ...)
// Подгруппы: код 4 цифры (0000, 0900, 1000, ...)
// Работа: code — код операции (00-000, П10-008), parent — подгруппа

$stmt = $pdo->query("
    SELECT code, parent_code, name, it_is_group
    FROM work_operations
    WHERE it_is_group = TRUE AND deleted = FALSE
    ORDER BY LENGTH(code), code
");
$allGroups = $stmt->fetchAll();

$topGroups = [];    // 2-значные: 00, 09, 10
$subgroups = [];    // 4-значные: 0000, 1002
foreach ($allGroups as $g) {
    $len = strlen($g['code']);
    if ($len === 2) $topGroups[] = $g;
    elseif ($len === 4) $subgroups[$g['parent_code'] ?? ''][] = $g;
}

/* ===== РАБОТЫ ===== */
$where  = ['w.it_is_group = FALSE', 'w.deleted = FALSE'];
$params = [];

if ($q !== '') {
    $where[] = "(w.name ILIKE :q OR w.operation_code ILIKE :q OR w.code ILIKE :q)";
    $params[':q'] = '%' . $q . '%';
}

// При выборе группы — показываем работы всех её подгрупп
$groupFilter = '';
if ($groupCode !== '') {
    if (strlen($groupCode) === 2) {
        // Верхняя группа → работы всех подгрупп группы
        $groupFilter = "
            WITH RECURSIVE sub AS (
                SELECT code FROM work_operations WHERE code = :g
                UNION ALL
                SELECT wo.code FROM work_operations wo
                JOIN sub ON wo.parent_code = sub.code
                WHERE wo.it_is_group = TRUE
            )
        ";
        $where[] = "w.parent_code IN (SELECT code FROM sub)";
    } else {
        // Подгруппа → работы только её
        $where[] = "w.parent_code = :g";
    }
    $params[':g'] = $groupCode;
}

if ($guardOnly) $where[] = "w.guard_work = TRUE";
if ($factOnly)  $where[] = "w.fact_work = TRUE";
$whereSql = 'WHERE ' . implode(' AND ', $where);

$stmt = $pdo->prepare($groupFilter . " SELECT COUNT(*) FROM work_operations w $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

$offset = ($page - 1) * $perPage;
$stmt = $pdo->prepare($groupFilter . "
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

/* ===== СТАТИСТИКА ===== */
$stats = $pdo->query("
    SELECT
        COUNT(*) FILTER (WHERE it_is_group = TRUE  AND deleted = FALSE) AS groups,
        COUNT(*) FILTER (WHERE it_is_group = FALSE AND deleted = FALSE) AS works,
        COUNT(*) FILTER (WHERE it_is_group = FALSE AND norm_time IS NOT NULL) AS with_norm
    FROM work_operations
")->fetch();

$currentGroup = null;
if ($groupCode !== '') {
    $stmt = $pdo->prepare("SELECT code, name FROM work_operations WHERE code = :c AND it_is_group = TRUE");
    $stmt->execute([':c' => $groupCode]);
    $currentGroup = $stmt->fetch() ?: null;
}

$lastSync = $pdo->query("SELECT MAX(updated_at) FROM work_operations")->fetchColumn();

function fmtTs($ts) { return $ts ? date('d.m.Y H:i', strtotime($ts)) : '—'; }
function buildUrl($o = []) { return '?' . http_build_query(array_merge($_GET, $o)); }
function fmtNorm($n) {
    if ($n === null) return null;
    return rtrim(rtrim(number_format((float)$n, 3, ',', ' '), '0'), ',');
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

  .tree{font-size:13px;max-height:75vh;overflow-y:auto}
  .tree-top{padding:8px 12px;font-weight:700;border-radius:8px;margin-bottom:2px;display:flex;align-items:center;gap:8px;color:#1e3a8a;cursor:pointer}
  .tree-top:hover{background:#f8faff}
  .tree-top.selected{background:#eff6ff}
  .tree-sub{padding:5px 12px 5px 32px;border-radius:6px;margin-left:8px;color:#333;display:flex;align-items:center;gap:6px}
  .tree-sub:hover{background:#f8faff}
  .tree-sub.selected{background:#eff6ff;color:#1e3a8a;font-weight:600}
  .tree a{text-decoration:none;color:inherit;display:flex;align-items:center;gap:6px;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
  .tree a:hover{color:#2563eb}
  .tree-icon{color:#2563eb;font-size:12px}

  table.works{width:100%;border-collapse:collapse;font-size:13px}
  table.works th{background:#f9fafb;color:#666;font-weight:600;text-align:left;padding:10px 12px;border-bottom:2px solid #e5e7eb;font-size:11px;text-transform:uppercase;letter-spacing:0.4px}
  table.works td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:top}
  table.works tr:hover td{background:#fafbff}
  .op-code{background:#fef3c7;color:#92400e;padding:2px 8px;border-radius:5px;font-size:12px;font-weight:700;white-space:nowrap;font-family:'SF Mono',Consolas,monospace}
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
  </div>

  <?php if ((int)$stats['works'] === 0): ?>
    <div class="warn">⚠️ Справочник пуст. <?php if ($user['is_admin']): ?>Перейдите в <a href="sync.php">загрузку</a>.<?php endif; ?></div>
  <?php endif; ?>

  <div class="layout">

    <!-- ЛЕВАЯ ПАНЕЛЬ: дерево -->
    <div class="card">
      <h2>📁 Группы</h2>
      <div class="tree">
        <div class="tree-top <?= $groupCode === '' && $q === '' ? 'selected' : '' ?>">
          <a href="?">🏠 <b>Все работы</b></a>
        </div>
        <?php foreach ($topGroups as $g): ?>
          <?php $sel = ($g['code'] === $groupCode); ?>
          <div class="tree-top <?= $sel ? 'selected' : '' ?>">
            <a href="?group=<?= urlencode($g['code']) ?>">
              <span class="tree-icon">📂</span>
              <b><?= e($g['name']) ?></b>
            </a>
          </div>
          <?php foreach ($subgroups[$g['code']] ?? [] as $sub): ?>
            <?php $selSub = ($sub['code'] === $groupCode); ?>
            <div class="tree-sub <?= $selSub ? 'selected' : '' ?>">
              <a href="?group=<?= urlencode($sub['code']) ?>">
                <span class="tree-icon">📄</span>
                <?= e($sub['name']) ?>
              </a>
            </div>
          <?php endforeach; ?>
        <?php endforeach; ?>
      </div>
    </div>

    <!-- ПРАВАЯ ПАНЕЛЬ -->
    <div class="card">
      <h2>
        <?php if ($currentGroup): ?>
          📂 <?= e($currentGroup['name']) ?>
        <?php else: ?>
          📋 Все работы
        <?php endif; ?>
      </h2>

      <form method="get">
        <input type="hidden" name="group" value="<?= e($groupCode) ?>">
        <div class="search-bar">
          <input type="text" name="q" value="<?= e($q) ?>" placeholder="Поиск по названию, коду операции…">
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
        Всего работ: <b><?= number_format((int)$stats['works'], 0, '.', ' ') ?></b>
        · Групп: <b><?= number_format((int)$stats['groups'], 0, '.', ' ') ?></b>
        · С нормой: <b><?= number_format((int)$stats['with_norm'], 0, '.', ' ') ?></b>
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
              <th style="width:110px;">Норма</th>
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
                  <div class="work-name"><?= e($r['name'] ?: '—') ?></div>
                  <?php if ($r['eng_name']): ?>
                    <div class="work-eng"><?= e($r['eng_name']) ?></div>
                  <?php endif; ?>
                  <?php if ($r['description'] && mb_strlen($r['description']) < 400): ?>
                    <div class="work-desc"><?= e($r['description']) ?></div>
                  <?php endif; ?>
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
