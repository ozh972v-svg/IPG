<?php
set_time_limit(600);

require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) { header('Location: login.php'); exit; }
$pdo = get_db();

/* ============================================================
   1. ПОИСК ПО VIN
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
                    $rawCode = trim($code);
                    $normalized = rtrim($rawCode, "- \t\n\r\0\x0B");
                    $candidates = array_values(array_unique(array_filter([
                        $rawCode,
                        $normalized,
                    ])));

                    $complectation = $normalized;
                    if ($candidates) {
                        $ph = []; $lp = [];
                        foreach ($candidates as $i => $v) {
                            $k = ':cv' . $i; $ph[] = $k; $lp[$k] = $v;
                        }
                        $st = $pdo->prepare(
                            "SELECT complectation FROM work_operations
                              WHERE complectation IN (" . implode(',', $ph) . ")
                              LIMIT 1"
                        );
                        $st->execute($lp);
                        $dbVar = $st->fetchColumn();
                        if ($dbVar !== false && $dbVar !== null && $dbVar !== '') {
                            $complectation = $dbVar;
                        }
                    }
                }
            }
        }
    }
}

if ($complectation === null && $vin === '') {
    $manual = trim($_GET['complectation'] ?? '');
    if ($manual !== '') $complectation = $manual;
}

/* ============================================================
   1.5 АВТОЗАГРУЗКА
   ============================================================ */
$autoSyncInfo = null;

if ($complectation !== null
    && empty($_GET['find_prereq'])
    && empty($_GET['find_pair'])) {

    $cntStmt = $pdo->prepare("SELECT COUNT(*) FROM work_operations
                               WHERE complectation = :c AND deleted = FALSE");
    $cntStmt->execute([':c' => $complectation]);
    $existingCount = (int)$cntStmt->fetchColumn();

    if ($existingCount === 0) {
        require_once __DIR__ . '/sync_works_lib.php';
        $res = sync_works_for_complectation($complectation, '2021-01-01', date('Y-m-d'));
        if (!empty($res['ok'])) {
            $autoSyncInfo = sprintf(
                'Работы загружены из 1С: групп — %d, работ — %d',
                (int)$res['stats']['groups'],
                (int)$res['stats']['works']
            );
        } else {
            $vinError = 'Работы по этой комплектации ещё не загружены. '
                      . 'Автозагрузка не удалась: ' . ($res['error'] ?? 'неизвестная ошибка');
        }
    }
}

/* ============================================================
   2. КАТЕГОРИИ
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
function categoryGroupKeys(string $letter): array {
    static $map = null;
    if ($map === null) {
        global $CATEGORIES;
        $map = [];
        foreach ($CATEGORIES as $grp => $cat) {
            foreach ($cat['letters'] as $L) { $map[$L][] = $grp; }
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
   5. СПИСОК КОМПЛЕКТАЦИЙ
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
$q
