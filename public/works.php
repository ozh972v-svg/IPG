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
  .legend-item{padding:10px 14px;
