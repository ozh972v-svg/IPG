<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) { header('Location: login.php'); exit; }
$pdo = get_db();

/* ============================================================
   1. ПОИСК ПО VIN — заменяет старый выбор модели
   ============================================================ */
$vin = trim($_GET['vin'] ?? '');
$vinError = null;
$complectation = null;

if ($vin !== '') {
    $login    = getenv('ONEC_LOGIN');
    $password = getenv('ONEC_PASSWORD');
    if (!$login || !$password) {
        $vinError = 'Не настроены ONEC_LOGIN и ONEC_PASSWORD';
    } else {
        // Если ввели короткий номер (до 10 символов) — ищем по номеру шасси.
        // Если длинный (полный VIN) — ищем по VIN шасси.
        $method = (mb_strlen($vin) >= 10) ? 'VINShassis' : 'NumberChassis';
        $url = 'https://web-1c.kamaz.ru/GOA/hs/CarData/V1/' . $method
             . '?Number=' . urlencode($vin);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $login . ':' . $password,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr  = curl_error($ch);
        curl_close($ch);

        if ($curlErr) {
            $vinError = 'Ошибка соединения с 1С: ' . $curlErr;
        } elseif ($httpCode === 401) {
            $vinError = 'Неверный логин или пароль от 1С';
        } elseif ($httpCode !== 200) {
            $vinError = '1С вернул код ' . $httpCode;
        } else {
            $data = json_decode($response, true);
            if (!isset($data['Car'])) {
                $vinError = 'По этому VIN автотехника не найдена';
            } else {
                $code = $data['Car']['TheDesignCodeOfTheConfiguration'] ?? null;
                if (!$code) {
                    $vinError = 'В ответе 1С нет поля «Конструкторский код комплектации»';
                } else {
                    $complectation = trim($code);
                }
            }
        }
    }
}

/* Ручной выбор комплектации (если VIN не введён) — на всякий случай оставлено */
if ($complectation === null && $vin === '') {
    $manual = trim($_GET['complectation'] ?? '');
    if ($manual !== '') $complectation = $manual;
}

/* ============================================================
   2. КАТЕГОРИИ — 5 групп
   ============================================================ */
$CATEGORIES = [
    'pre_sale'    => ['label' => 'Предпродажная подготовка',              'desc' => 'Подготовка автотехники к продаже/передаче',                        'letters' => ['B'],            'icon' => 'B', 'color' => '#ea580c'],
    'warranty'    => ['label' => 'Работы по гарантии',                    'desc' => 'Гарантийные работы',                                             'letters' => ['A','P','C','E'],'icon' => 'A', 'color' => '#16a34a'],
    'maintenance' => ['label' => 'Техническое обслуживание',              'desc' => 'Регламентные и комплексные работы ТО',                            'letters' => ['T','X'],        'icon' => 'T', 'color' => '#ca8a04'],
    'commercial'  => ['label' => 'Коммерческий ремонт',                   'desc' => 'Постовые и цеховые работы текущего ремонта',                      'letters' => ['P','C','E'],    'icon' => 'P', 'color' => '#2563eb'],
    'otm'         => ['label' => 'Работы по организационно-техническим мероприятиям', 'desc' => 'Работы по доработке, выполняемые по решению ОТМ',   'letters' => ['M'],            'icon' => 'M', 'color' => '#9333ea'],
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
    global $DIAG_TRIGGERS;
    if ($name !== null) {
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

/* Возвращает СПИСОК групп, к которым относится буква.
   P, C, E попадают сразу в две группы: warranty и commercial. */
function categoryGroupKeys(string $letter): array {
    static $map = null;
    if ($map === null) {
        global $CATEGORIES;
        $map = [];
        foreach ($CATEGORIES as $grp => $cat) {
            foreach ($cat['letters'] as $L) {
                $map[$L][] = $grp;
            }
        }
    }
    return $map[$letter] ?? [];
}

/* ============================================================
   3. AJAX — предварительные работы
   ============================================================ */
if (!empty($_GET['find_prereq'])) {
    header('Content-Type: application/json; charset=utf-8');
    $obj     = trim($_GET['object'] ?? '');
    $exclude = trim($_GET['exclude'] ?? '');
    if ($obj === '' || mb_strlen($obj) < 3 || $complectation === null) { echo json_encode([]); exit; }

    $exclArr = $exclude !== '' ? array_filter(array_map('trim', explode(',', $exclude))) : [];
    $exclSql = ''; $exclParams = [];
    if ($exclArr) {
        $ph = [];
        foreach ($exclArr as $i => $c) { $k = ':ex'.$i; $ph[] = $k; $exclParams[$k] = $c; }
        $exclSql = ' AND code NOT IN (' . implode(',', $ph) . ') ';
    }
    $objClean = preg_replace('/\s*(снят|снята|снято|разобран|отсоединён)[а-я]*\s*/ui', '', $obj);
    $objClean = trim($objClean);
    if ($objClean === '') $objClean = $obj;

    $sql = "SELECT DISTINCT ON (operation_code) code, name, operation_code, norm_time
              FROM work_operations
             WHERE it_is_group = FALSE AND deleted = FALSE AND complectation = :c
               AND (name ILIKE :a OR name ILIKE :b)
               AND name !~* '\\([^)]*(снят|снята|снято|разобран|отсоедин)[^)]*\\)'
               $exclSql
             ORDER BY operation_code, LENGTH(name)";
    $params = array_merge([
        ':c' => $complectation,
        ':a' => 'Снять и установить %' . $objClean . '%',
        ':b' => 'Снять %' . $objClean . '%',
    ], $exclParams);
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    echo json_encode(array_slice($stmt->fetchAll(), 0, 15), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   4. AJAX — парная работа
   ============================================================ */
if (!empty($_GET['find_pair'])) {
    header('Content-Type: application/json; charset=utf-8');
    $name    = trim($_GET['name'] ?? '');
    $exclude = trim($_GET['exclude'] ?? '');
    if ($name === '' || $complectation === null) { echo json_encode([]); exit; }
    if (preg_match('/^Снять и установить\s/ui', $name)) { echo json_encode([]); exit; }

    $tail = null; $pairVerb = null;
    if (preg_match('/^Снять\s+(.+)$/ui', $name, $m))        { $tail = $m[1]; $pairVerb = 'Установить'; }
    elseif (preg_match('/^Установить\s+(.+)$/ui', $name, $m)) { $tail = $m[1]; $pairVerb = 'Снять'; }
    if ($tail === null) { echo json_encode([]); exit; }

    $tailKey = preg_replace('/\s*\([^)]*\)\s*$/u', '', $tail);
    $tailKey = trim($tailKey);
    if ($tailKey === '') $tailKey = $tail;
    if (mb_strlen($tailKey) > 60) $tailKey = mb_substr($tailKey, 0, 60);

    $exclArr = $exclude !== '' ? array_filter(array_map('trim', explode(',', $exclude))) : [];
    $exclSql = ''; $exclParams = [];
    if ($exclArr) {
        $ph = [];
        foreach ($exclArr as $i => $c) { $k = ':ex'.$i; $ph[] = $k; $exclParams[$k] = $c; }
        $exclSql = ' AND code NOT IN (' . implode(',', $ph) . ') ';
    }

    $variants = [
        $pairVerb . ' ' . $tailKey . '%',
        $pairVerb . ' и установить ' . $tailKey . '%',
        $pairVerb . ' и снять ' . $tailKey . '%',
    ];
    $ors = []; $params = [':c' => $complectation];
    foreach ($variants as $i => $pat) { $k = ':v'.$i; $ors[] = "name ILIKE $k"; $params[$k] = $pat; }

    $sql = "SELECT DISTINCT ON (operation_code) code, name, operation_code, norm_time
              FROM work_operations
             WHERE it_is_group = FALSE AND deleted = FALSE AND complectation = :c
               AND (" . implode(' OR ', $ors) . ")
               $exclSql
             ORDER BY operation_code, LENGTH(name)";
    $stmt = $pdo->prepare($sql); $stmt->execute(array_merge($params, $exclParams));
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   5. СПИСОК КОМПЛЕКТАЦИЙ (оставлен — может пригодиться)
   ============================================================ */
$complectations = $pdo->query("
    SELECT complectation,
           COUNT(DISTINCT operation_code) FILTER (WHERE it_is_group = FALSE AND deleted = FALSE) AS works_cnt
      FROM work_operations
     WHERE complectation IS NOT NULL AND complectation <> '' AND complectation <> '—'
     GROUP BY complectation
     ORDER BY complectation
")->fetchAll();

/* ============================================================
   6. ДЕРЕВО ГРУПП
   ============================================================ */
$q         = trim($_GET['q'] ?? '');
$groupCode = trim($_GET['group'] ?? '');
$category  = trim($_GET['cat'] ?? '');
$page      = max(1, (int)($_GET['page'] ?? 1));
$perPage   = 100;

$allGroups = [];
if ($complectation !== null) {
    $stmt = $pdo->prepare("SELECT code, parent_code, name FROM work_operations
                            WHERE it_is_group = TRUE AND deleted = FALSE AND complectation = :c
                            ORDER BY code");
    $stmt->execute([':c' => $complectation]);
    $allGroups = $stmt->fetchAll();
}

$topGroups = []; $subgroups = [];
foreach ($allGroups as $g) {
    $codeOnly   = explode('@', $g['code'])[0];
    $parentOnly = $g['parent_code'] ? explode('@', $g['parent_code'])[0] : null;
    if (strlen($codeOnly) === 2) {
        $topGroups[] = $g + ['code_only' => $codeOnly];
    } elseif (strlen($codeOnly) === 4) {
        $subgroups[$parentOnly][] = $g + ['code_only' => $codeOnly];
    }
}

/* ============================================================
   7. РАБОТЫ
   ============================================================ */
$where  = ['w.it_is_group = FALSE', 'w.deleted = FALSE'];
$params = [];
if ($complectation !== null) { $where[] = 'w.complectation = :c'; $params[':c'] = $complectation; }
if ($q !== '') {
    $where[] = "(w.name ILIKE :q OR w.operation_code ILIKE :q OR w.code ILIKE :q)";
    $params[':q'] = '%' . $q . '%';
}
if ($groupCode !== '' && $complectation !== null) {
    $gFull = $groupCode . '@' . $complectation;
    if (strlen($groupCode) === 2) {
        $where[] = "(w.parent_code = :g OR w.parent_code IN (
                        SELECT code FROM work_operations
                        WHERE parent_code = :g AND it_is_group = TRUE AND complectation = :c2
                    ))";
        $params[':c2'] = $complectation;
    } else {
        $where[] = "w.parent_code = :g";
    }
    $params[':g'] = $gFull;
}
if ($category !== '' && isset($CATEGORIES[$category])) {
    $letters = $CATEGORIES[$category]['letters'];
    $rus = ['A'=>'А','B'=>'В','C'=>'С','E'=>'Е','M'=>'М','P'=>'Р','T'=>'Т','X'=>'Х'];
    $ors = [];
    foreach ($letters as $i => $L) {
        $k1 = ':cL'.$i.'_en'; $k2 = ':cL'.$i.'_ru';
        $ors[] = "w.operation_code LIKE $k1";
        $ors[] = "w.operation_code LIKE $k2";
        $params[$k1] = $L . '%';
        $params[$k2] = ($rus[$L] ?? $L) . '%';
    }
    $where[] = '(' . implode(' OR ', $ors) . ')';
}
$whereSql = 'WHERE ' . implode(' AND ', $where);

$total = 0; $rows = []; $pages = 1;
if ($complectation !== null && ($category !== '' || $q !== '')) {
    $stmt = $pdo->prepare("SELECT COUNT(DISTINCT w.operation_code) FROM work_operations w $whereSql");
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $offset = ($page - 1) * $perPage;
    $stmt = $pdo->prepare("SELECT DISTINCT ON (w.operation_code)
                                  w.code, w.name, w.operation_code, w.eng_name, w.description,
                                  w.norm_time, w.parent_code
                             FROM work_operations w
                             $whereSql
                            ORDER BY w.operation_code NULLS LAST, LENGTH(w.name)
                            LIMIT $perPage OFFSET $offset");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $pages = max(1, (int)ceil($total / $perPage));
}

/* Счётчики категорий */
$catCountsInGroup = array_fill_keys(array_keys($CATEGORIES), 0);
$catCountsAll     = array_fill_keys(array_keys($CATEGORIES), 0);

if ($complectation !== null) {
    $stmt = $pdo->prepare("SELECT DISTINCT operation_code, name FROM work_operations
                            WHERE it_is_group = FALSE AND deleted = FALSE AND complectation = :c");
    $stmt->execute([':c' => $complectation]);
    foreach ($stmt->fetchAll() as $r) {
        $letter = opCategoryKey($r['operation_code'], $r['name']);
        foreach (categoryGroupKeys($letter) as $grp) {
            $catCountsAll[$grp]++;
        }
    }

    if ($groupCode !== '') {
        $gFull = $groupCode . '@' . $complectation;
        $stmt = $pdo->prepare("SELECT DISTINCT w.operation_code, w.name
                                 FROM work_operations w
                                WHERE w.it_is_group = FALSE AND w.deleted = FALSE AND w.complectation = :c
                                  AND (w.parent_code = :g OR w.parent_code IN (
                                        SELECT code FROM work_operations
                                        WHERE parent_code = :g AND it_is_group = TRUE AND complectation = :c2
                                      ))");
        $stmt->execute([':c' => $complectation, ':c2' => $complectation, ':g' => $gFull]);
        foreach ($stmt->fetchAll() as $r) {
            $letter = opCategoryKey($r['operation_code'], $r['name']);
            foreach (categoryGroupKeys($letter) as $grp) {
                $catCountsInGroup[$grp]++;
            }
        }
    }
}

/* Общая статистика */
$stats = ['groups' => 0, 'works' => 0];
$lastSync = null;
if ($complectation !== null) {
    $stmt = $pdo->prepare("SELECT COUNT(*) FILTER (WHERE it_is_group = TRUE)  AS groups,
                                  COUNT(DISTINCT operation_code) FILTER (WHERE it_is_group = FALSE) AS works,
                                  MAX(updated_at) AS last_sync
                             FROM work_operations
                            WHERE deleted = FALSE AND complectation = :c");
    $stmt->execute([':c' => $complectation]);
    $stats = $stmt->fetch() ?: $stats;
    $lastSync = $stats['last_sync'] ?? null;
}

$currentGroup = null;
if ($groupCode !== '' && $complectation !== null) {
    $stmt = $pdo->prepare("SELECT code, name FROM work_operations
                            WHERE code = :c AND it_is_group = TRUE AND complectation = :m");
    $stmt->execute([':c' => $groupCode . '@' . $complectation, ':m' => $complectation]);
    $currentGroup = $stmt->fetch() ?: null;
}

function groupIcon(string $groupCodeOnly): string {
    $code = substr(preg_replace('/\D/', '', $groupCodeOnly), 0, 2);
    if ($code === '') return '';
    $file = __DIR__ . '/icons/' . $code . '.svg';
    if (is_file($file)) return 'icons/' . $code . '.svg';
    return '';
}
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
<title>Справочник работ — 1С:ГОА</title>
<style>
  *{box-sizing:border-box}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;margin:0;padding:16px;color:#1a1a1a;line-height:1.5}
  .container{max-width:1700px;margin:0 auto}
  .card{background:#fff;border-radius:14px;padding:16px;box-shadow:0 2px 12px rgba(0,0,0,0.06);margin-bottom:12px}
  h1{font-size:22px;margin:0 0 8px} h2{font-size:16px;margin:0 0 12px;color:#1e3a8a}
  h3{font-size:14px;margin:0 0 10px;color:#1e3a8a}
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
  .vin-bar{background:#eff6ff;border-left:4px solid #2563eb;padding:12px 14px;border-radius:10px;margin-top:12px}
  .vin-bar form{display:flex;gap:10px;flex-wrap:wrap;align-items:center}
  .vin-bar label{font-weight:600;color:#1e3a8a;font-size:14px}
  .vin-bar input[type=text]{flex:1;min-width:260px;padding:10px 14px;border:1.5px solid #93c5fd;border-radius:8px;font-family:inherit;font-size:14px;background:#fff;color:#1e3a8a}
  .vin-bar input[type=text]:focus{outline:none;border-color:#2563eb}
  .vin-info{font-size:13px;color:#1e3a8a;margin-top:8px;padding:6px 10px;background:#dbeafe;border-radius:6px;display:inline-block}
  .cat-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:10px}
  .cat-card{border:1.5px solid #e5e7eb;border-radius:10px;padding:12px 14px;cursor:pointer;transition:all 0.15s;text-decoration:none;color:inherit;display:block;background:#fff;position:relative}
  .cat-card:hover{border-color:#2563eb;background:#f8faff;transform:translateY(-1px);box-shadow:0 4px 12px rgba(37,99,235,0.08)}
  .cat-card.active{background:#eff6ff;border-color:#2563eb;box-shadow:0 0 0 2px rgba(37,99,235,0.15)}
  .cat-card.empty{opacity:0.4;cursor:not-allowed;pointer-events:none}
  .cat-head{display:flex;align-items:center;gap:10px;margin-bottom:6px}
  .cat-letter{width:32px;height:32px;border-radius:8px;display:flex;align-items:center;justify-content:center;font-weight:800;font-size:14px;color:#fff;flex-shrink:0}
  .cat-title{font-weight:700;font-size:13px;color:#1a1a1a;line-height:1.3}
  .cat-count{margin-left:auto;background:#f3f4f6;color:#666;padding:2px 8px;border-radius:10px;font-size:11px;font-weight:600;flex-shrink:0}
  .cat-card.active .cat-count{background:#2563eb;color:#fff}
  .cat-desc{font-size:11px;color:#666;line-height:1.4;margin-left:42px}
  .breadcrumbs{font-size:13px;color:#666;margin-bottom:12px;display:flex;align-items:center;gap:8px;flex-wrap:wrap}
  .breadcrumbs a{color:#2563eb;text-decoration:none}
  .breadcrumbs a:hover{text-decoration:underline}
  .breadcrumbs .sep{color:#cbd5e1}
  .layout{display:grid;grid-template-columns:320px 1fr 360px;gap:12px;align-items:start}
  @media (max-width:1200px){.layout{grid-template-columns:280px 1fr;} .basket{grid-column:1/-1}}
  @media (max-width:800px){.layout{grid-template-columns:1fr} .basket{grid-column:1}}
  .search-bar{display:flex;gap:8px;flex-wrap:wrap;margin-bottom:12px}
  .search-bar input[type=text]{flex:1;min-width:200px;padding:11px 14px;border:1.5px solid #e5e7eb;border-radius:8px;font-size:14px;font-family:inherit}
  .search-bar input:focus{outline:none;border-color:#2563eb}
  .tree{font-size:13px;max-height:75vh;overflow-y:auto}
  .tree > details > summary{padding:8px 10px;font-weight:700;color:#1e3a8a;cursor:pointer;border-radius:8px;display:flex;align-items:center;gap:8px;list-style:none}
  .tree > details > summary::-webkit-details-marker{display:none}
  .tree > details > summary::before{content:'▶';font-size:10px;color:#2563eb;transition:transform 0.15s}
  .tree > details[open] > summary::before{transform:rotate(90deg)}
  .tree > details > summary:hover{background:#f8faff}
  .tree > details > summary.selected{background:#eff6ff}
  .tree > details > summary a{text-decoration:none;color:inherit;flex:1;overflow:hidden;text-overflow:ellipsis;white-space:nowrap;display:flex;align-items:center;gap:8px}
  .tree-icon{width:26px;height:26px;flex-shrink:0;object-fit:contain}
  .tree-icon-fallback{width:26px;height:26px;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:16px;color:#2563eb}
  .tree-sub{display:flex;align-items:center;gap:8px;padding:5px 10px 5px 24px;color:#333;border-radius:6px;margin-left:6px;text-decoration:none;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
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
  .norm-time{background:#e0f2fe;color:#075985;padding:3px 10px;border-radius:6px;font-size:13px;font-weight:700;white-space:nowrap}
  .add-btn{background:#16a34a;color:#fff;border:none;padding:5px 10px;border-radius:6px;font-size:12px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap}
  .add-btn:hover{background:#15803d}
  .add-btn.in-basket{background:#9ca3af;cursor:default}
  .empty{text-align:center;padding:60px 20px;color:#999}
  .empty .big{font-size:48px;margin-bottom:8px}
  .pagination{display:flex;gap:6px;justify-content:center;flex-wrap:wrap;margin-top:16px}
  .pagination a,.pagination span{padding:7px 12px;border-radius:7px;text-decoration:none;font-size:13px;background:#fff;border:1.5px solid #e5e7eb;color:#2563eb}
  .pagination .active{background:#2563eb;color:#fff;border-color:#2563eb;font-weight:600}
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
  .modal{background:#fff;border-radius:14px;max-width:680px;width:100%;max-height:80vh;overflow-y:auto;padding:24px}
  .modal h3{margin:0 0 12px;font-size:18px;color:#1e3a8a}
  .modal p{margin:0 0 14px;font-size:14px;color:#333}
  .modal .prereq-list{list-style:none;padding:0;margin:0 0 18px}
  .modal .prereq-item{padding:10px 12px;border:1.5px solid #e5e7eb;border-radius:8px;margin-bottom:8px;font-size:13px;display:flex;align-items:center;gap:10px}
  .modal .prereq-item input[type=checkbox]{width:18px;height:18px;flex-shrink:0}
  .modal .prereq-item label{flex:1;cursor:pointer}
  .modal-btns{display:flex;gap:10px;justify-content:flex-end;flex-wrap:wrap}
  .modal .badge-auto{background:#fef3c7;color:#92400e;font-size:10px;padding:2px 6px;border-radius:4px;font-weight:600;margin-left:8px}
  .modal .badge-pair{background:#dbeafe;color:#1e40af;font-size:10px;padding:2px 6px;border-radius:4px;font-weight:600;margin-left:8px}
  .prereq-sub{margin-left:24px;font-size:10px;color:#888;padding:4px 0 0;}
  .copy-msg{position:fixed;bottom:20px;left:50%;transform:translateX(-50%);background:#16a34a;color:#fff;padding:12px 24px;border-radius:10px;font-size:14px;font-weight:600;z-index:2000;opacity:0;transition:opacity 0.3s;pointer-events:none}
  .copy-msg.show{opacity:1}
  .step-hint{font-size:13px;color:#666;margin-bottom:12px;padding:8px 12px;background:#f9fafb;border-radius:8px;border-left:3px solid #2563eb}
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
      <a href="search_vin.php" class="btn btn-secondary btn-small">🔍 Поиск по VIN — 1С:ГОА</a>
      <?php if ($user['is_admin']): ?>
        <a href="sync.php" class="btn btn-small">📥 Загрузка справочника</a>
      <?php endif; ?>
    </div>

    <div class="vin-bar">
      <form method="get">
        <label for="vinInput">🔍 Поиск по VIN:</label>
        <input type="text" name="vin" id="vinInput" value="<?= e($vin) ?>"
               placeholder="Введите VIN (17 символов) или последние 7 цифр" autocomplete="off">
        <button type="submit" class="btn">Найти работы</button>
        <?php if ($vin !== '' || $complectation !== null): ?>
          <a href="works.php" class="btn btn-secondary btn-small">Сбросить</a>
        <?php endif; ?>
      </form>
      <?php if ($vinError): ?>
        <div class="vin-info" style="background:#fef2f2;color:#dc2626;">❌ <?= e($vinError) ?></div>
      <?php endif; ?>
      <?php if ($complectation !== null): ?>
        <div class="vin-info">
          ✅ Комплектация: <b><?= e($complectation) ?></b>
          <?php if ($lastSync): ?> · обновлено: <b><?= e(fmtTs($lastSync)) ?></b><?php endif; ?>
        </div>
      <?php endif; ?>
    </div>
  </div>

  <?php if ($complectation === null): ?>
    <div class="card">
      <h2>🔍 Введите VIN</h2>
      <div class="step-hint">
        Введите VIN шасси в поле выше, чтобы система нашла комплектацию через 1С:ГОА и показала работы.
        Можно вводить как полный VIN (17 символов), так и последние 7 цифр номера шасси.
      </div>
      <?php if (!$vin): ?>
        <p style="color:#888;font-size:14px;margin:0;">
          Пример: <code>XTC549010M1234567</code> или <code>1234567</code>
        </p>
      <?php endif; ?>
    </div>

  <?php else: ?>
    <div class="layout">

      <!-- ДЕРЕВО -->
      <div class="card">
        <h2>📁 Группы</h2>
        <div class="tree">
          <a href="?complectation=<?= urlencode($complectation) ?>"
             class="tree-sub <?= $groupCode === '' ? 'selected' : '' ?>"
             style="padding-left:10px;font-weight:700;color:#1e3a8a;background:<?= $groupCode === '' ? '#eff6ff' : 'transparent' ?>;">
            🏠 Все группы
          </a>
          <?php foreach ($topGroups as $g): ?>
            <?php $isOpen = ($groupCode === $g['code_only']) || strpos($groupCode, $g['code_only']) === 0; ?>
            <?php $sel = ($groupCode === $g['code_only']); ?>
            <?php $icon = groupIcon($g['code_only']); ?>
            <details <?= $isOpen ? 'open' : '' ?>>
              <summary class="<?= $sel ? 'selected' : '' ?>">
                <a href="?complectation=<?= urlencode($complectation) ?>&group=<?= urlencode($g['code_only']) ?>">
                  <?php if ($icon): ?>
                    <img src="<?= e($icon) ?>" alt="" class="tree-icon">
                  <?php else: ?>
                    <span class="tree-icon-fallback">📂</span>
                  <?php endif; ?>
                  <?= e($g['name']) ?>
                </a>
              </summary>
              <?php foreach ($subgroups[$g['code_only']] ?? [] as $sub): ?>
                <?php $selSub = ($sub['code_only'] === $groupCode); ?>
                <a class="tree-sub <?= $selSub ? 'selected' : '' ?>"
                   href="?complectation=<?= urlencode($complectation) ?>&group=<?= urlencode($sub['code_only']) ?>">
                  <?= e($sub['name']) ?>
                </a>
              <?php endforeach; ?>
            </details>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- ЦЕНТР -->
      <div class="card">
        <div class="breadcrumbs">
          <a href="?complectation=<?= urlencode($complectation) ?>">📁 Все группы</a>
          <?php if ($currentGroup): ?>
            <span class="sep">›</span>
            <a href="?complectation=<?= urlencode($complectation) ?>&group=<?= urlencode($groupCode) ?>"><?= e($currentGroup['name']) ?></a>
          <?php endif; ?>
          <?php if ($category && isset($CATEGORIES[$category])): ?>
            <span class="sep">›</span>
            <span><?= e($CATEGORIES[$category]['label']) ?></span>
          <?php endif; ?>
        </div>

        <?php if ($groupCode === ''): ?>
          <h2>📋 Шаг 1: выберите группу работ</h2>
          <div class="step-hint">
            Выберите группу в левом меню или кликните по категории ниже, чтобы увидеть работы по всем группам.
          </div>
          <h3>Или выберите сразу категорию:</h3>
          <div class="cat-grid">
            <?php foreach ($CATEGORIES as $key => $cat): ?>
              <?php $cnt = (int)($catCountsAll[$key] ?? 0); ?>
              <a class="cat-card <?= $cnt === 0 ? 'empty' : '' ?>"
                 href="?complectation=<?= urlencode($complectation) ?>&cat=<?= urlencode($key) ?>">
                <div class="cat-head">
                  <div class="cat-letter" style="background:<?= e($cat['color']) ?>;"><?= e($cat['icon']) ?></div>
                  <div class="cat-title"><?= e($cat['label']) ?></div>
                  <div class="cat-count"><?= number_format($cnt, 0, '.', ' ') ?></div>
                </div>
                <div class="cat-desc"><?= e($cat['desc']) ?></div>
              </a>
            <?php endforeach; ?>
          </div>

        <?php elseif ($category === ''): ?>
          <h2>📋 Шаг 2: выберите категорию в группе «<?= e($currentGroup['name'] ?? '') ?>»</h2>
          <div class="step-hint">
            Показаны только те категории, в которых есть работы внутри выбранной группы.
          </div>
          <div class="cat-grid">
            <?php foreach ($CATEGORIES as $key => $cat): ?>
              <?php $cnt = (int)($catCountsInGroup[$key] ?? 0); ?>
              <a class="cat-card <?= $cnt === 0 ? 'empty' : '' ?>"
                 href="?complectation=<?= urlencode($complectation) ?>&group=<?= urlencode($groupCode) ?>&cat=<?= urlencode($key) ?>">
                <div class="cat-head">
                  <div class="cat-letter" style="background:<?= e($cat['color']) ?>;"><?= e($cat['icon']) ?></div>
                  <div class="cat-title"><?= e($cat['label']) ?></div>
                  <div class="cat-count"><?= number_format($cnt, 0, '.', ' ') ?></div>
                </div>
                <div class="cat-desc"><?= e($cat['desc']) ?></div>
              </a>
            <?php endforeach; ?>
          </div>

        <?php else: ?>
          <h2>📋 Шаг 3: работы — <?= e($CATEGORIES[$category]['label']) ?></h2>

          <form method="get" style="margin-bottom:12px;">
            <input type="hidden" name="complectation" value="<?= e($complectation) ?>">
            <input type="hidden" name="group" value="<?= e($groupCode) ?>">
            <input type="hidden" name="cat" value="<?= e($category) ?>">
            <div class="search-bar">
              <input type="text" name="q" value="<?= e($q) ?>" placeholder="Поиск по названию или коду…">
              <button type="submit" class="btn">🔍 Найти</button>
              <?php if ($q !== ''): ?>
                <a href="?complectation=<?= urlencode($complectation) ?>&group=<?= urlencode($groupCode) ?>&cat=<?= urlencode($category) ?>" class="btn btn-secondary">Сбросить</a>
              <?php endif; ?>
            </div>
          </form>

          <?php if (!$rows): ?>
            <div class="empty"><div class="big">🔍</div>Ничего не найдено</div>
          <?php else: ?>
            <table class="works">
              <thead>
                <tr>
                  <th style="width:120px;">Код операции</th>
                  <th>Наименование работы</th>
                  <th style="width:80px;">Норма</th>
                  <th style="width:90px;"></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($rows as $r): ?>
                  <?php $catKey = opCategoryKey($r['operation_code'], $r['name']); ?>
                  <tr>
                    <td>
                      <?php if ($r['operation_code']): ?>
                        <span class="op-code <?= $catKey ? 'cat-' . strtolower($catKey) : '' ?>">
                          <?= e($r['operation_code']) ?>
                        </span>
                      <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                      <div class="work-name"><?= e($r['name'] ?: '—') ?></div>
                      <?php if ($r['eng_name']): ?><div class="work-eng"><?= e($r['eng_name']) ?></div><?php endif; ?>
                    </td>
                    <td>
                      <?php if ($r['norm_time'] !== null): ?>
                        <span class="norm-time"><?= e(fmtNorm($r['norm_time'])) ?> ч</span>
                      <?php else: ?>—<?php endif; ?>
                    </td>
                    <td>
                      <button type="button" class="add-btn"
                        data-code="<?= e($r['code']) ?>"
                        data-op="<?= e($r['operation_code']) ?>"
                        data-name="<?= e($r['name']) ?>"
                        data-norm="<?= e((string)$r['norm_time']) ?>">➕</button>
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
        <?php endif; ?>
      </div>

      <!-- КОРЗИНА -->
      <div class="card basket">
        <div class="basket-header">
          <h2>📋 Выбранные работы</h2>
          <span class="basket-count" id="basketCount">0</span>
        </div>
        <div class="basket-total">Суммарная норма: <b id="basketTotal">0,00</b> ч</div>
        <ul class="basket-list" id="basketList">
          <li style="text-align:center;color:#999;padding:24px 0;font-size:13px;">Пока ничего не выбрано.<br>Нажми ➕ у работы.</li>
        </ul>
        <div class="basket-actions" id="basketActions" style="display:none;">
          <button class="btn btn-green btn-small" onclick="basketCopy()">📋 Копировать</button>
          <button class="btn btn-secondary btn-small" onclick="basketDownload()">💾 Скачать</button>
          <button class="btn btn-red btn-small" onclick="basketClear()">🗑️ Очистить</button>
        </div>
      </div>

    </div>
  <?php endif; ?>
</div>

<div class="modal-overlay" id="prereqModal">
  <div class="modal">
    <h3>⚠️ Для этой работы нужен предварительный доступ</h3>
    <p id="prereqText">Отметьте, какие работы добавить в заказ-наряд:</p>
    <ul class="prereq-list" id="prereqList"></ul>
    <div class="modal-btns">
      <button class="btn btn-secondary" onclick="closePrereq()">Отмена</button>
      <button class="btn btn-green" onclick="addPrereqSelected()">Добавить выбранные</button>
    </div>
  </div>
</div>

<div class="copy-msg" id="copyMsg">✅ Скопировано в буфер</div>

<script>
const BASKET_KEY = 'works_basket_v1';
let basket = [];

function basketLoad() {
  try { basket = JSON.parse(localStorage.getItem(BASKET_KEY) || '[]'); }
  catch(e) { basket = []; }
  if (!Array.isArray(basket)) basket = [];
}
function basketSave() { localStorage.setItem(BASKET_KEY, JSON.stringify(basket)); }

function escapeHtml(s) {
  const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML;
}

function basketRender() {
  const list    = document.getElementById('basketList');
  const count   = document.getElementById('basketCount');
  const total   = document.getElementById('basketTotal');
  const actions = document.getElementById('basketActions');

  count.textContent = basket.length;

  let sum = 0;
  basket.forEach(b => { if (b.norm) sum += parseFloat(b.norm); });
  total.textContent = sum.toFixed(2).replace('.', ',');

  if (basket.length === 0) {
    list.innerHTML = '<li style="text-align:center;color:#999;padding:24px 0;font-size:13px;">Пока ничего не выбрано.<br>Нажми ➕ у работы.</li>';
    actions.style.display = 'none';
    document.querySelectorAll('.add-btn').forEach(b => b.classList.remove('in-basket'));
    return;
  }
  actions.style.display = 'flex';

  list.innerHTML = basket.map((b, i) => `
    <li class="basket-item">
      <div class="basket-item-content">
        ${b.op ? `<div class="basket-item-op">${escapeHtml(b.op)}</div>` : ''}
        <div class="basket-item-name">${escapeHtml(b.name || '')}</div>
        ${b.norm ? `<div class="basket-item-norm">${escapeHtml(b.norm)} ч</div>` : ''}
      </div>
      <span class="basket-item-del" onclick="basketRemove(${i})">×</span>
    </li>
  `).join('');

  const codes = new Set(basket.map(b => b.code));
  document.querySelectorAll('.add-btn').forEach(btn => {
    if (codes.has(btn.dataset.code)) btn.classList.add('in-basket');
    else btn.classList.remove('in-basket');
  });
}

function basketAdd(item) {
  if (basket.some(b => b.code === item.code)) return false;
  basket.push(item);
  basketSave();
  basketRender();
  return true;
}
function basketRemove(i) { basket.splice(i, 1); basketSave(); basketRender(); }
function basketClear() {
  if (!confirm('Очистить все выбранные работы?')) return;
  basket = []; basketSave(); basketRender();
}
function basketCopy() {
  const text = basket.map(b => {
    const op = b.op ? `[${b.op}] ` : '';
    const n  = b.norm ? ` (${b.norm} ч)` : '';
    return op + b.name + n;
  }).join('\n');
  navigator.clipboard.writeText(text).then(() => showMsg('✅ Скопировано в буфер'));
}
function basketDownload() {
  const text = basket.map(b => [b.op || '', b.name || '', b.norm || ''].join('\t')).join('\n');
  const blob = new Blob([text], {type: 'text/plain;charset=utf-8'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url;
  a.download = 'works_' + new Date().toISOString().slice(0,10) + '.txt';
  a.click();
  URL.revokeObjectURL(url);
}
function showMsg(text) {
  const el = document.getElementById('copyMsg');
  el.textContent = text;
  el.classList.add('show');
  setTimeout(() => el.classList.remove('show'), 2000);
}

function extractPrereqObjects(name) {
  if (!name) return [];
  const results = [];
  const re = /\(([^()]*?(?:снят|снята|снято|разобран|отсоедин)[^()]*?)\)/gi;
  let m;
  while ((m = re.exec(name)) !== null) {
    let inner = m[1];
    inner = inner
      .replace(/\b(и\s+разобран[а-я]*|разобран[а-я]*|с\s+автомобиля\s+снят[а-я]*|снят[а-я]*|отсоединён[а-я]*|установлен[а-я]*)\b/gi, ' ')
      .replace(/\s+/g, ' ')
      .trim();
    if (inner.length >= 3) results.push(inner);
  }
  return results;
}

async function findDependencies(item, existingCodes, depth) {
  if (depth > 2) return null;

  const objects = extractPrereqObjects(item.name);
  if (objects.length === 0) return null;

  let allPrereqs = [];
  for (const obj of objects) {
    try {
      const excludeParam = encodeURIComponent([...existingCodes].join(','));
      const url = '?find_prereq=1'
                + '&object=' + encodeURIComponent(obj)
                + '&exclude=' + excludeParam
                + '&complectation=' + encodeURIComponent('<?= e($complectation ?? '') ?>');
      const resp = await fetch(url, {headers: {'X-Requested-With': 'fetch'}});
      const items = await resp.json();
      const filtered = items.filter(p => !existingCodes.has(p.code));
      allPrereqs = allPrereqs.concat(filtered);
    } catch(err) { console.error(err); }
  }

  const seen = new Set();
  allPrereqs = allPrereqs.filter(p => {
    if (seen.has(p.code)) return false;
    seen.add(p.code);
    return true;
  });
  if (allPrereqs.length === 0) return null;

  const pairsToAdd = [];
  for (const p of allPrereqs) {
    if (/^Снять и установить\s/ui.test(p.name)) continue;
    if (!/^(Снять|Установить)\s/ui.test(p.name)) continue;
    try {
      const excludeParam = encodeURIComponent([...existingCodes, ...allPrereqs.map(x => x.code)].join(','));
      const url = '?find_pair=1'
                + '&name=' + encodeURIComponent(p.name)
                + '&exclude=' + excludeParam
                + '&complectation=' + encodeURIComponent('<?= e($complectation ?? '') ?>');
      const resp = await fetch(url, {headers: {'X-Requested-With': 'fetch'}});
      const pairs = await resp.json();
      pairs.forEach(pair => {
        if (allPrereqs.some(x => x.code === pair.code)) return;
        if (pairsToAdd.some(x => x.code === pair.code)) return;
        pair._pairedWith = p.code;
        pairsToAdd.push(pair);
      });
    } catch(err) { console.error(err); }
  }
  allPrereqs = allPrereqs.concat(pairsToAdd);

  const deeper = [];
  for (const p of allPrereqs) {
    const subCodes = new Set([...existingCodes, p.code, ...allPrereqs.map(x => x.code)]);
    const subResult = await findDependencies(
      { code: p.code, name: p.name, operation_code: p.operation_code, norm_time: p.norm_time },
      subCodes,
      depth + 1
    );
    if (subResult) { subResult.parentCode = p.code; deeper.push(subResult); }
  }

  return { parent: item, prereqs: allPrereqs, deeper: deeper };
}

function renderPrereqModal(tree) {
  const modal = document.getElementById('prereqModal');
  const list  = document.getElementById('prereqList');
  const text  = document.getElementById('prereqText');

  text.innerHTML = 'Для работы <b style="color:#92400e;">' + escapeHtml(tree.parent.name) + '</b> нужны предварительные работы:';

  const flat = [];
  function walk(node, level, parentLabel) {
    node.prereqs.forEach(p => {
      flat.push({ item: p, level: level, parentLabel: parentLabel, isPair: !!p._pairedWith });
      const sub = node.deeper.find(d => d.parentCode === p.code);
      if (sub) walk(sub, level + 1, p.name);
    });
  }
  walk(tree, 0, null);

  list.innerHTML = flat.map((entry, i) => {
    const indent = entry.level * 24;
    const subNote = entry.level > 0
      ? '<div class="prereq-sub" style="margin-left:' + (indent + 28) + 'px;">↳ требуется для: ' + escapeHtml(entry.parentLabel || '') + '</div>'
      : '';
    const pairBadge = entry.isPair ? '<span class="badge-pair">парная</span>' : '';
    return subNote + `
      <li class="prereq-item" style="margin-left:${indent}px;">
        <input type="checkbox" id="prereq-${i}" checked
               data-code="${escapeHtml(entry.item.code)}"
               data-op="${escapeHtml(entry.item.operation_code || '')}"
               data-name="${escapeHtml(entry.item.name)}"
               data-norm="${escapeHtml((entry.item.norm_time||'').toString())}">
        <label for="prereq-${i}">
          ${entry.item.operation_code ? `<span class="op-code">${escapeHtml(entry.item.operation_code)}</span> ` : ''}
          ${escapeHtml(entry.item.name)}
          ${entry.item.norm_time ? `<span class="badge-auto">${escapeHtml(String(entry.item.norm_time))} ч</span>` : ''}
          ${pairBadge}
        </label>
      </li>
    `;
  }).join('');

  modal.classList.add('active');
}
function closePrereq() { document.getElementById('prereqModal').classList.remove('active'); }
function addPrereqSelected() {
  const checked = document.querySelectorAll('#prereqList input[type=checkbox]:checked');
  let added = 0;
  checked.forEach(chk => {
    if (basketAdd({
      code: chk.dataset.code,
      op:   chk.dataset.op,
      name: chk.dataset.name,
      norm: chk.dataset.norm || null
    })) added++;
  });
  closePrereq();
  if (added > 0) showMsg('✅ Добавлено работ: ' + added);
}

document.addEventListener('click', async function(e) {
  if (!e.target.classList.contains('add-btn')) return;
  const btn = e.target;

  const item = {
    code: btn.dataset.code,
    op:   btn.dataset.op,
    name: btn.dataset.name,
    norm: btn.dataset.norm || null
  };

  if (basket.some(b => b.code === item.code)) { showMsg('Уже в корзине'); return; }

  basketAdd(item);

  const existingCodes = new Set(basket.map(b => b.code));
  const tree = await findDependencies(item, existingCodes, 0);
  if (tree && tree.prereqs.length > 0) renderPrereqModal(tree);
});

basketLoad();
basketRender();
</script>
</body>
</html>
