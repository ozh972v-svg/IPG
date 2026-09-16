<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) { header('Location: login.php'); exit; }
$pdo = get_db();

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

/**
 * Классификатор КАМАЗ + авто-категория E (Диагностика) по названию.
 */
$CATEGORIES = [
    'A' => ['label' => 'Административные',                   'desc' => 'Работы по оформлению заказ-наряда, приёмке-выдаче, согласованиям', 'color' => '#dc2626'],
    'B' => ['label' => 'Предпродажная подготовка',           'desc' => 'Работы по подготовке автотехники к продаже/передаче', 'color' => '#ea580c'],
    'T' => ['label' => 'Техническое обслуживание',           'desc' => 'Регламентные работы ТО (ТО-1, ТО-2, сезонное обслуживание)', 'color' => '#ca8a04'],
    'X' => ['label' => 'Комплекс работ ТО',                  'desc' => 'Комплексные регламентные работы (ПТО, ПЗР, А2, А3, ТОд и др.)', 'color' => '#65a30d'],
    'E' => ['label' => 'Диагностика автотехники',            'desc' => 'Работы по оценке состояния техники в целом', 'color' => '#0891b2'],
    'P' => ['label' => 'Постовые работы текущего ремонта',   'desc' => 'Работы по снятию и установке изделий, слив/залив жидкостей, прокачка систем, регулировка', 'color' => '#2563eb'],
    'C' => ['label' => 'Цеховые работы текущего ремонта',    'desc' => 'Разборка, очистка, оценка, сборка, регулировка, обкатка изделий, снятых с автотехники', 'color' => '#7c3aed'],
    'M' => ['label' => 'Доработка (работы только для ОТМ)',  'desc' => 'Работы по доработке, выполняемые по решению ОТМ', 'color' => '#be185d'],
];

$DIAG_TRIGGERS = [
    'диагностика', 'диагностировать', 'поиск неисправност', 'поиск дефект',
    'определить неисправност', 'выявление неисправност',
    'проверить состояние', 'проверка состояния',
    'проверить работоспособност', 'проверка работоспособност',
    'проверить и при необходимости', 'дефектовка',
    'оценка состояния', 'оценить состояние', 'оценка качества',
];

function opCategoryKey(?string $op, ?string $name = null): string {
    if ($name !== null) {
        global $DIAG_TRIGGERS;
        $lower = mb_strtolower($name);
        foreach ($DIAG_TRIGGERS as $t) {
            if (mb_strpos($lower, $t) !== false) return 'E';
        }
    }
    if ($op === null || $op === '') return '';
    $first = mb_substr($op, 0, 1);
    $map = ['А'=>'A','A'=>'A','В'=>'B','B'=>'B','Т'=>'T','T'=>'T','Х'=>'X','X'=>'X',
            'Е'=>'E','E'=>'E','Р'=>'P','P'=>'P','С'=>'C','C'=>'C','М'=>'M','M'=>'M'];
    return $map[$first] ?? '';
}

/* ===== AJAX: поиск предварительных работ ===== */
if (!empty($_GET['find_prereq'])) {
    header('Content-Type: application/json; charset=utf-8');
    $obj = trim($_GET['object'] ?? '');
    $exclude = trim($_GET['exclude'] ?? '');
    if ($obj === '' || mb_strlen($obj) < 3) { echo json_encode([]); exit; }

    $exclArr = $exclude !== '' ? array_filter(array_map('trim', explode(',', $exclude))) : [];
    $exclSql = '';
    $exclParams = [];
    if ($exclArr) {
        $ph = [];
        foreach ($exclArr as $i => $c) {
            $key = ':ex' . $i;
            $ph[] = $key;
            $exclParams[$key] = $c;
        }
        $exclSql = ' AND code NOT IN (' . implode(',', $ph) . ') ';
    }

    $objClean = preg_replace('/\s*(снят|снята|снято|разобран|отсоединён)[а-я]*\s*/ui', '', $obj);
    $objClean = trim($objClean);
    if ($objClean === '') $objClean = $obj;

    $sql = "
        SELECT code, name, operation_code, norm_time
        FROM work_operations
        WHERE it_is_group = FALSE AND deleted = FALSE AND model = :m
          AND (name ILIKE :a OR name ILIKE :b)
          AND name !~* '\\([^)]*(снят|снята|снято|разобран|отсоедин)[^)]*\\)'
          $exclSql
        ORDER BY
            CASE WHEN name ILIKE :c THEN 0
                 WHEN name ILIKE :d THEN 1
                 ELSE 2 END,
            LENGTH(name)
        LIMIT 15
    ";
    $params = array_merge([
        ':m' => $model,
        ':a' => 'Снять и установить %' . $objClean . '%',
        ':b' => 'Снять %' . $objClean . '%',
        ':c' => 'Снять и установить ' . $objClean . '%',
        ':d' => 'Снять ' . $objClean . '%',
    ], $exclParams);

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}

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
    if ($category === 'E') {
        global $DIAG_TRIGGERS;
        $ors = ["(w.operation_code LIKE 'Е%' OR w.operation_code LIKE 'E%')"];
        $i = 0;
        foreach ($DIAG_TRIGGERS as $t) {
            $key = ':t' . $i;
            $ors[] = "w.name ILIKE $key";
            $params[$key] = '%' . $t . '%';
            $i++;
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    } else {
        $letters = [
            'A' => ['А', 'A'], 'B' => ['В', 'B'], 'T' => ['Т', 'T'], 'X' => ['Х', 'X'],
            'P' => ['Р', 'P'], 'C' => ['С', 'C'], 'M' => ['М', 'M'],
        ];
        $set = $letters[$category] ?? [$category];
        $ors = [];
        foreach ($set as $i => $L) {
            $key = ':p' . $category . $i;
            $ors[] = "w.operation_code LIKE $key";
            $params[$key] = $L . '%';
        }
        $where[] = '(' . implode(' OR ', $ors) . ')';
    }
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

/* ===== СТАТИСТИКА ===== */
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
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Справочник работ</title>
<style>
  *{box-sizing:border-box}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;margin:0;padding:16px;color:#1a1a1a;line-height:1.5}
  .container{max-width:1700px;margin:0 auto}
  .card{background:#fff;border-radius:14px;padding:16px;box-shadow:0 2px 12px rgba(0,0,0,0.06);margin-bottom:12px}
  h1{font-size:22px;margin:0 0 8px} h2{font-size:16px;margin:0 0 12px;color:#1e3a8a}
  .top-bar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px}
  .user-info{font-size:13px;color:#666} .user-info b{color:#2563eb}
  .logout{color:#dc2626;text-decoration:none;font-size:13px;margin-left:12px}
  .btn{display:inline-block;padding:9px 14px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;background:#2563eb;color:#fff;text-decoration:none;text-align:center;font-family:inherit}
  .btn:hover{opacity:0.9}
  .btn-secondary{background:#fff;color:#2563eb;border:1.5px solid #2563eb}
  .btn-green{background:#16a34a}
  .btn-red{background:#dc2626}
  .btn-small{padding:7px 12px;font-size:13px}
  .btn-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center}

  .model-bar{background:#eff6ff;border-left:4px solid #2563eb;padding:10px 14px;border-radius:10px;font-size:13px;color:#1e3a8a;margin-bottom:12px;display:flex;align-items:center;gap:14px;flex-wrap:wrap}
  .model-bar label{font-weight:600}
  .model-bar select{padding:6px 10px;border:1.5px solid #93c5fd;border-radius:8px;font-family:inherit;font-size:13px;background:#fff;color:#1e3a8a;font-weight:600;cursor:pointer}
  .model-bar select:focus{outline:none;border-color:#2563eb}

  .cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(280px,1fr));gap:10px;margin-top:12px}
  .cat-card{border:1.5px solid #e5e7eb;border-radius:10px;padding:12px 14px;cursor:pointer;transition:all 0.15s;text-decoration:none;color:inherit;display:block;background:#fff}
  .cat-card:hover{border-color:#2563eb;background:#f8faff;transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,0.08)}
  .cat-card.active{background:#eff6ff;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,0.15)}
  .cat-head{display:flex;align-items:center;gap:10px;margin-bottom:6px}
  .cat-letter{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:16px;color:#fff;flex-shrink:0}
  .cat-title{font-weight:700;font-size:14px;color:#1a1a1a}
  .cat-desc{font-size:12px;color:#666;line-height:1.4;margin-left:42px}
  .clear-cat{display:inline-block;margin-top:12px;padding:8px 14px;background:#fff;border:1.5px solid #2563eb;color:#2563eb;border-radius:8px;text-decoration:none;font-size:13px;font-weight:600}
  .clear-cat:hover{background:#eff6ff}

  .layout{display:grid;grid-template-columns:300px 1fr 360px;gap:12px;align-items:start}
  @media (max-width:1200px){.layout{grid-template-columns:280px 1fr;} .basket{grid-column:1/-1}}
  @media (max-width:800px){.layout{grid-template-columns:1fr} .basket{grid-column:1}}

  .search-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
  .search-bar input[type=text]{flex:1;min-width:200px;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:14px;font-family:inherit}
  .search-bar input:focus{outline:none;border-color:#2563eb}
  .filters{display:flex;gap:16px;flex-wrap:wrap;font-size:13px;margin-top:8px;align-items:center}
  .filters label{display:flex;align-items:center;gap:5px;cursor:pointer}

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
  .cat-a{background:#fee2e2;color:#991b1b}
  .cat-b{background:#ffedd5;color:#9a3412}
  .cat-t{background:#fef9c3;color:#854d0e}
  .cat-x{background:#ecfccb;color:#3f6212}
  .cat-e{background:#cffafe;color:#155e75}
  .cat-p{background:#dbeafe;color:#1e40af}
  .cat-c{background:#ede9fe;color:#5b21b6}
  .cat-m{background:#fce7f3;color:#9d174d}
  .work-name{color:#1a1a1a;font-weight:500}
  .work-eng{color:#888;font-size:11px;margin-top:3px}
  .work-desc{color:#666;font-size:11px;margin-top:4px;font-style:italic}
  .norm-time{background:#e0f2fe;color:#075985;padding:3px 10px;border-radius:6px;font-size:13px;font-weight:700;white-space:nowrap}
  .badge{display:inline-block;padding:2px 8px;border-radius:5px;font-size:10px;font-weight:600;white-space:nowrap;margin-right:4px}
  .badge-guard{background:#f0fdf4;color:#16a34a}
  .badge-fact{background:#f3e8ff;color:#7c3aed}
  .add-btn{background:#16a34a;color:#fff;border:none;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap}
  .add-btn:hover{background:#15803d}
  .add-btn.in-basket{background:#9ca3af;cursor:default}

  .stats{font-size:12px;color:#666;padding:8px 0;border-bottom:1px solid #f0f0f0;margin-bottom:8px}
  .stats b{color:#2563eb}
  .empty{text-align:center;padding:60px 20px;color:#999}
  .empty .big{font-size:48px;margin-bottom:8px}

  .pagination{display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin-top:16px}
  .pagination a,.pagination span{padding:7px 12px;border-radius:7px;text-decoration:none;font-size:13px;background:#fff;border:1.5px solid #e5e7eb;color:#2563eb}
  .pagination .active{background:#2563eb;color:#fff;border-color:#2563eb;font-weight:600}

  .warn{padding:12px 16px;border-radius:10px;background:#fffbeb;color:#b45309;border-left:4px solid #b45309;margin-bottom:12px;font-size:13px}

  .basket{position:sticky;top:16px;max-height:calc(100vh - 32px);overflow-y:auto}
  .basket-header{display:flex;justify-content:space-between;align-items:center;margin-bottom:12px}
  .basket-header h2{margin:0;font-size:16px;color:#1e3a8a}
  .basket-count{background:#2563eb;color:#fff;font-size:12px;font-weight:700;padding:2px 10px;border-radius:12px}
  .basket-total{background:#eff6ff;border-radius:8px;padding:10px 12px;margin-bottom:12px;font-size:13px;color:#1e3a8a}
  .basket-total b{font-size:18px}
  .basket-list{list-style:none;padding:0;margin:0}
  .basket-item{border-bottom:1px solid #f0f0f0;padding:10px 0;display:flex;gap:8px;font-size:12px}
  .basket-item:last-child{border-bottom:none}
  .basket-item-content{flex:1;min-width:0}
  .basket-item-op{font-family:'SF Mono',Consolas,monospace;font-size:11px;font-weight:700;color:#92400e;background:#fef3c7;padding:1px 6px;border-radius:4px;display:inline-block;margin-bottom:3px}
  .basket-item-name{color:#1a1a1a;line-height:1.3;word-wrap:break-word}
  .basket-item-norm{color:#075985;font-weight:700;margin-top:3px;font-size:11px}
  .basket-item-del{color:#dc2626;font-size:18px;cursor:pointer;padding:0 4px;line-height:1;user-select:none}
  .basket-item-del:hover{background:#fef2f2;border-radius:4px}
  .basket-actions{display:flex;gap:6px;flex-wrap:wrap;margin-top:12px}
  .basket-actions .btn{flex:1;min-width:120px;font-size:12px;padding:8px 10px}

  .modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,0.5);display:none;align-items:center;justify-content:center;z-index:1000;padding:20px}
  .modal-overlay.active{display:flex}
  .modal{background:#fff;border-radius:14px;max-width:640px;width:100%;max-height:80vh;overflow-y:auto;padding:24px}
  .modal h3{margin:0 0 12px;font-size:18px;color:#1e3a8a}
  .modal p{margin:0 0 14px;font-size:14px;color:#333}
  .modal .prereq-list{list-style:none;padding:0;margin:0 0 18px}
  .modal .prereq-item{padding:10px 12px;border:1.5px solid #e5e7eb;border-radius:8px;margin-bottom:8px;font-size:13px;display:flex;align-items:center;gap:10px}
  .modal .prereq-item input[type=checkbox]{width:18px;height:18px;flex-shrink:0}
  .modal .prereq-item label{flex:1;cursor:pointer}
  .modal-btns{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
  .modal .badge-auto{background:#fef3c7;color:#92400e;font-size:10px;padding:2px 6px;border-radius:4px;font-weight:600;margin-left:8px}
  .prereq-sub{margin-left:24px;font-size:10px;color:#888;padding:4px 0 0;}

  .copy-msg{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#16a34a;color:#fff;padding:12px 24px;border-radius:10px;font-size:14px;font-weight:600;z-index:2000;opacity:0;transition:opacity 0.3s;pointer-events:none}
  .copy-msg.show{opacity:1}
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

  <div class="card">
    <h2>📖 Категории работ — по первому символу кода операции</h2>
    <p style="font-size:13px;color:#666;margin:0 0 4px;">
      Диагностические работы определяются автоматически по названию (слова «диагностика», «поиск неисправности», «проверить состояние» и т.п.).
    </p>
    <div class="cat-grid">
      <?php foreach ($CATEGORIES as $key => $cat): ?>
        <?php $isActive = ($category === $key); ?>
        <a class="cat-card <?= $isActive ? 'active' : '' ?>"
           href="?model=<?= urlencode($model) ?><?= $groupCode ? '&group=' . urlencode($groupCode) : '' ?><?= $q ? '&q=' . urlencode($q) : '' ?>&cat=<?= urlencode($key) ?>">
          <div class="cat-head">
            <div class="cat-letter" style="background:<?= e($cat['color']) ?>;"><?= e($key) ?></div>
            <div class="cat-title"><?= e($cat['label']) ?></div>
          </div>
          <div class="cat-desc"><?= e($cat['desc']) ?></div>
        </a>
      <?php endforeach; ?>
    </div>
    <?php if ($category !== ''): ?>
      <a class="clear-cat" href="?model=<?= urlencode($model) ?><?= $groupCode ? '&group=' . urlencode($groupCode) : '' ?><?= $q ? '&q=' . urlencode($q) : '' ?>">
        ✖ Сбросить фильтр категории
      </a>
    <?php endif; ?>
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
          <span style="font-weight:400;font-size:13px;color:#666;"> · <b><?= e($CATEGORIES[$category]['label']) ?></b></span>
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
            <a href="
