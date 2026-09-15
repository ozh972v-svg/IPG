<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$pdo = get_db();

$q         = trim($_GET['q'] ?? '');
$type      = $_GET['type'] ?? '';       // '' | group | element
$guard     = $_GET['guard'] ?? '';      // '' | 1
$fact      = $_GET['fact'] ?? '';       // '' | 1
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 50;
$offset    = ($page - 1) * $perPage;

$where  = ['deleted = FALSE'];
$params = [];

if ($q !== '') {
    $where[] = "(code ILIKE :q OR name ILIKE :q OR name_work ILIKE :q OR operation_code ILIKE :q OR eng_name ILIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($type === 'group')   { $where[] = "it_is_group = TRUE";  }
if ($type === 'element') { $where[] = "it_is_group = FALSE"; }
if ($guard === '1')      { $where[] = "guard_work = TRUE";   }
if ($fact === '1')       { $where[] = "fact_work = TRUE";    }

$whereSql = implode(' AND ', $where);

// Всего
$stmt = $pdo->prepare("SELECT COUNT(*) AS cnt FROM work_operations WHERE $whereSql");
$stmt->execute($params);
$total = (int)$stmt->fetch()['cnt'];

// Строки
$stmt = $pdo->prepare("
    SELECT * FROM work_operations
    WHERE $whereSql
    ORDER BY it_is_group DESC, COALESCE(name, name_work, code)
    LIMIT $perPage OFFSET $offset
");
$stmt->execute($params);
$rows = $stmt->fetchAll();

$totalPages = max(1, (int)ceil($total / $perPage));

// Статистика
$stats = $pdo->query("
    SELECT
      COUNT(*) AS total,
      COUNT(*) FILTER (WHERE it_is_group = TRUE) AS groups,
      COUNT(*) FILTER (WHERE it_is_group = FALSE) AS elements
    FROM work_operations WHERE deleted = FALSE
")->fetch();

function buildUrl(array $override = []): string {
    $q = array_merge($_GET, $override);
    return 'works.php?' . http_build_query($q);
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Поиск по работам — 1С:ГОА</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; margin: 0; padding: 16px; color: #1a1a1a; line-height: 1.5; }
  .container { max-width: 1200px; margin: 0 auto; }
  .card { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 16px; }
  h1 { font-size: 22px; margin: 0 0 8px; }
  h2 { font-size: 18px; margin: 0 0 16px; }
  .top-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
  .user-info { font-size: 13px; color: #666; }
  .user-info b { color: #2563eb; }
  .logout { color: #dc2626; text-decoration: none; font-size: 13px; margin-left: 12px; }
  .btn { display: inline-block; padding: 12px 18px; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; background: #2563eb; color: #fff; text-decoration: none; text-align: center; transition: opacity 0.2s; }
  .btn:hover { opacity: 0.9; }
  .btn-secondary { background: #fff; color: #2563eb; border: 1.5px solid #2563eb; }
  .btn-small { padding: 8px 14px; font-size: 14px; }
  .btn-row { display: flex; gap: 10px; flex-wrap: wrap; }

  .search-bar { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .search-bar input[type=text] { flex: 1; min-width: 240px; padding: 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 15px; font-family: inherit; }
  .search-bar input[type=text]:focus { outline: none; border-color: #2563eb; }
  .search-bar select { padding: 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 15px; font-family: inherit; background: #fff; }
  .search-bar select:focus { outline: none; border-color: #2563eb; }
  .checkbox-label { display: inline-flex; align-items: center; gap: 6px; font-size: 14px; cursor: pointer; user-select: none; padding: 12px 8px; }

  .stats { display: flex; gap: 12px; flex-wrap: wrap; font-size: 13px; color: #666; margin-top: 12px; }
  .stats b { color: #1a1a1a; }

  table.doc-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  table.doc-table th {
    background: #f9fafb; color: #666; font-weight: 600;
    text-align: left; padding: 10px 12px;
    border-bottom: 1px solid #e5e7eb; font-size: 12px;
    text-transform: uppercase; letter-spacing: 0.3px;
    position: sticky; top: 0; z-index: 1;
  }
  table.doc-table td {
    padding: 10px 12px; border-bottom: 1px solid #f0f0f0;
    vertical-align: top;
  }
  table.doc-table tr:hover td { background: #fafbff; }
  table.doc-table .code-cell { font-family: 'SF Mono', Consolas, monospace; font-weight: 600; color: #1e3a8a; white-space: nowrap; }
  table.doc-table .desc-cell { color: #666; font-size: 12px; }

  .badge { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: 11px; font-weight: 600; white-space: nowrap; margin-right: 4px; }
  .badge-group   { background: #eff6ff; color: #2563eb; }
  .badge-element { background: #f0fdf4; color: #16a34a; }
  .badge-guard   { background: #fef3c7; color: #b45309; }
  .badge-fact    { background: #f3e8ff; color: #7c3aed; }

  .empty-state { text-align: center; padding: 60px 20px; color: #888; font-size: 14px; }
  .empty-state .big { font-size: 48px; margin-bottom: 12px; }

  .pagination { display: flex; gap: 6px; justify-content: center; flex-wrap: wrap; margin-top: 20px; }
  .pagination a, .pagination span {
    padding: 8px 14px; border-radius: 8px; font-size: 14px; text-decoration: none; font-weight: 600;
    background: #fff; color: #2563eb; border: 1.5px solid #e5e7eb;
  }
  .pagination a:hover { border-color: #2563eb; }
  .pagination .current { background: #2563eb; color: #fff; border-color: #2563eb; }
  .pagination .disabled { color: #ccc; pointer-events: none; }

  @media (max-width: 800px) {
    table.doc-table { font-size: 12px; }
    table.doc-table th, table.doc-table td { padding: 8px 6px; }
    table.doc-table .col-desc { display: none; }
  }
</style>
</head>
<body>
<div class="container">

  <div class="card">
    <div class="top-bar">
      <h1>🔧 Поиск по работам — 1С:ГОА</h1>
      <div>
        <span
