<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) { header('Location: login.php'); exit; }
$pdo = get_db();

/* ============================================================
   1. ПОИСК ПО VIN (заменяет старый выбор модели)
   ============================================================ */
$vin = trim($_GET['vin'] ?? '');
$vinError = null;
$complectation = null; // код комплектации, полученный из 1С по VIN

if ($vin !== '') {
    $login = getenv('ONEC_LOGIN');
    $password = getenv('ONEC_PASSWORD');
    if (!$login || !$password) {
        $vinError = 'Не настроены ONEC_LOGIN и ONEC_PASSWORD';
    } else {
        // Ищем по VIN шасси (метод VINShassis — самый точный)
        $url = 'https://web-1c.kamaz.ru/GOA/hs/CarData/V1/VINShassis'
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
                $car = $data['Car'];
                $code = $car['TheDesignCodeOfTheConfiguration'] ?? null;
                if (!$code) {
                    $vinError = 'В ответе 1С нет поля «Конструкторский код комплектации»';
                } else {
                    $complectation = trim($code);
                }
            }
        }
    }
}

/* Ручной выбор комплектации (если VIN не введён) */
if ($complectation === null && $vin === '') {
    $manual = trim($_GET['complectation'] ?? '');
    if ($manual !== '') $complectation = $manual;
}

/* ============================================================
   2. КАТЕГОРИИ — 5 групп по твоему ТЗ
   ============================================================ */
$CATEGORIES = [
    'pre_sale' => [
        'label'   => 'Предпродажная подготовка',
        'desc'    => 'Подготовка автотехники к продаже/передаче',
        'letters' => ['B'],
        'icon'    => 'B',
        'color'   => '#ea580c',
    ],
    'warranty' => [
        'label'   => 'Работы по гарантии',
        'desc'    => 'Гарантийные работы',
        'letters' => ['A', 'P', 'C', 'E'],
        'icon'    => 'A',
        'color'   => '#16a34a',
    ],
    'maintenance' => [
        'label'   => 'Техническое обслуживание',
        'desc'    => 'Регламентные и комплексные работы ТО',
        'letters' => ['T', 'X'],
        'icon'    => 'T',
        'color'   => '#ca8a04',
    ],
    'commercial' => [
        'label'   => 'Коммерческий ремонт',
        'desc'    => 'Постовые и цеховые работы текущего ремонта',
        'letters' => ['P', 'C', 'E'],
        'icon'    => 'P',
        'color'   => '#2563eb',
    ],
    'otm' => [
        'label'   => 'Работы по организационно-техническим мероприятиям',
        'desc'    => 'Работы по доработке, выполняемые по решению ОТМ',
        'letters' => ['M'],
        'icon'    => 'M',
        'color'   => '#9333ea',
    ],
];

/* Триггеры диагностики (для буквы E — по названию работы) */
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

/* Определяет, к какой из 5 групп относится категория-буква */
function categoryGroupKey(string $letter): ?string {
    static $map = null;
    if ($map === null) {
        global $CATEGORIES;
        $map = [];
        foreach ($CATEGORIES as $groupKey => $cat) {
            foreach ($cat['letters'] as $L) {
                $map[$L] = $groupKey;
            }
        }
    }
    return $map[$letter] ?? null;
}

/* ============================================================
   3. AJAX: поиск предварительных работ
   ============================================================ */
if (!empty($_GET['find_prereq'])) {
    header('Content-Type: application/json; charset=utf-8');
    $obj     = trim($_GET['object'] ?? '');
    $exclude = trim($_GET['exclude'] ?? '');
    if ($obj === '' || mb_strlen($obj) < 3 || $complectation === null) {
        echo json_encode([]); exit;
    }

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

    $sql = "
        SELECT DISTINCT ON (operation_code)
               code, name, operation_code, norm_time
        FROM work_operations
        WHERE it_is_group = FALSE AND deleted = FALSE AND complectation = :c
          AND (name ILIKE :a OR name ILIKE :b)
          AND name !~* '\\([^)]*(снят|снята|снято|разобран|отсоедин)[^)]*\\)'
          $exclSql
        ORDER BY operation_code, LENGTH(name)
    ";
    $params = array_merge([
        ':c' => $complectation,
        ':a' => 'Снять и установить %' . $objClean . '%',
        ':b' => 'Снять %' . $objClean . '%',
    ], $exclParams);
    $stmt = $pdo->prepare($sql); $stmt->execute($params);
    $res = array_slice($stmt->fetchAll(), 0, 15);
    echo json_encode($res, JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   4. AJAX: поиск парной работы
   ============================================================ */
if (!empty($_GET['find_pair'])) {
    header('Content-Type: application/json; charset=utf-8');
    $name    = trim($_GET['name'] ?? '');
    $exclude = trim($_GET['exclude'] ?? '');
    if ($name === '' || $complectation === null) { echo json_encode([]); exit; }
    if (preg_match('/^Снять и установить\s/ui', $name)) { echo json_encode([]); exit; }

    $tail = null; $pairVerb = null;
    if (preg_match('/^Снять\s+(.+)$/ui', $name, $m)) { $tail = $m[1]; $pairVerb = 'Установить'; }
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
    $orsSql = '(' . implode(' OR ', $ors) . ')';

    $sql = "
        SELECT DISTINCT ON (operation_code)
               code, name, operation_code, norm_time
        FROM work_operations
        WHERE it_is_group = FALSE AND deleted = FALSE AND complectation = :c
          AND $orsSql
          $exclSql
        ORDER BY operation_code, LENGTH(name)
    ";
    $stmt = $pdo->prepare($sql); $stmt->execute(array_merge($params, $exclParams));
    echo json_encode($stmt->fetchAll(), JSON_UNESCAPED_UNICODE);
    exit;
}

/* ============================================================
   5. СПИСОК ДОСТУПНЫХ КОМПЛЕКТАЦИЙ (для ручного выбора)
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
    $stmt = $pdo->prepare("
        SELECT code, parent_code, name FROM work_operations
        WHERE it_is_group = TRUE AND deleted = FALSE AND complectation = :c
        ORDER BY code
    ");
    $stmt->execute([':c' => $complectation]);
    $allGroups = $stmt->fetchAll();
}

$topGroups = []; $subgroups = [];
foreach ($allGroups as $g) {
    $codeOnly = explode('@', $g['code'])[0];
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

if ($complectation !== null) {
    $where[] = 'w.complectation = :c';
    $params[':c'] = $complectation;
}

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
    $ors = [];
    $rus = ['A'=>'А','B'=>'В','C'=>'С','E'=>'Е','M'=>'М','P'=>'Р','T'=>'Т','X'=>'Х'];
    foreach ($letters as $i => $L) {
        $k1 = ':cL'.$i.'_en';
        $k2 = ':cL'.$i.'_ru';
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
    $stmt = $pdo->prepare("
        SELECT DISTINCT ON (w.operation_code)
               w.code, w.name, w.operation_code, w.eng_name, w.description,
               w.norm_time, w.parent_code
        FROM work_operations w
        $whereSql
        ORDER BY w.operation_code NULLS LAST, LENGTH(w.name)
        LIMIT $perPage OFFSET $offset
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll();
    $pages = max(1, (int)ceil($total / $perPage));
}

/* Счётчики категорий в выбранной группе и во всей комплектации */
$catCountsInGroup = array_fill_keys(array_keys($CATEGORIES), 0);
$catCountsAll     = array_fill_keys(array_keys($CATEGORIES), 0);

if ($complectation !== null) {
    // Все работы комплектации
    $stmt = $pdo->prepare("
        SELECT DISTINCT operation_code, name FROM work_operations
        WHERE it_is_group = FALSE AND deleted = FALSE AND complectation = :c
    ");
    $stmt->execute([':c' => $complectation]);
    foreach ($stmt->fetchAll() as $r) {
        $letter = opCategoryKey($r['operation_code'], $r['name']);
        $grp = categoryGroupKey($letter);
        if ($grp !== null) $catCountsAll[$grp]++;
    }

    // Работы в выбранной группе
    if ($groupCode !== '') {
        $gFull = $groupCode . '@' . $complectation;
        $stmt = $pdo->prepare("
            SELECT DISTINCT w.operation_code, w.name
            FROM work_operations w
            WHERE w.it_is_group = FALSE AND w.deleted = FALSE AND w.complectation = :c
              AND (w.parent_code = :g OR w.parent_code IN (
                    SELECT code FROM work_operations
                    WHERE parent_code = :g AND it_is_group = TRUE AND complectation = :c2
                  ))
        ");
        $stmt->execute([':c' => $complectation, ':c2' => $complectation, ':g' => $gFull]);
        foreach ($stmt->fetchAll() as $r) {
            $letter = opCategoryKey($r['operation_code'], $r['name']);
            $grp = categoryGroupKey($letter);
            if ($grp !== null) $catCountsInGroup[$grp]++;
        }
    }
}

/* Общая статистика по комплектации */
$stats = ['groups' => 0, 'works' => 0];
$lastSync = null;
if ($complectation !== null) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FILTER (WHERE it_is_group = TRUE)  AS groups,
               COUNT(DISTINCT operation_code) FILTER (WHERE it_is_group = FALSE) AS works,
               MAX(updated_at) AS last_sync
        FROM work_operations
        WHERE deleted = FALSE AND complectation = :c
    ");
    $stmt->execute([':c' => $complectation]);
    $stats = $stmt->fetch() ?: $stats;
    $lastSync = $stats['last_sync'] ?? null;
}

/* Иконка группы */
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

$currentGroup = null;
if ($groupCode !== '' && $complectation !== null) {
    $stmt = $pdo->prepare("SELECT code, name FROM work_operations
                           WHERE code = :c AND it_is_group = TRUE AND complectation = :m");
    $stmt->execute([':c' => $groupCode . '@' . $complectation, ':m' => $complectation]);
    $currentGroup = $stmt->fetch() ?: null;
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
  .tree-sub::before{content:'·';color:#cbd5e1;margin-right
