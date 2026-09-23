<?php
require __DIR__ . '/db.php';
start_session();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) { echo json_encode(['ok'=>false,'error'=>'Не авторизован']); exit; }
$pdo = get_db();

/* ============================================================
   Словарь аббревиатур
   ============================================================ */
function expandAbbreviations(string $text): string {
    $map = [
        'ГБЦ' => 'головка блок цилиндров',
        'БЦ'  => 'блок цилиндров',
        'ДВС' => 'двигатель',
        'КПП' => 'коробка передач',
        'АКПП'=> 'автоматическая коробка передач',
        'МКПП'=> 'механическая коробка передач',
        'ТНВД'=> 'топливный насос высокого давления',
        'ТННД'=> 'топливный насос низкого давления',
        'ОЖ'  => 'охлаждающая жидкость',
        'ГУР' => 'гидроусилитель руля',
        'ЭБУ' => 'электронный блок управления',
        'ЦБУ' => 'центральный блок управления',
        'ТНВ' => 'теплообменник наддувочного воздуха',
        'ТРК' => 'турбокомпрессор',
        'ЭГР' => 'система рециркуляции выхлопных газов',
        'EGR' => 'система рециркуляции выхлопных газов',
        'ABS' => 'антиблокировочная система',
        'АБС' => 'антиблокировочная система',
        'ГП'  => 'главная передача',
        'КП'  => 'коробка передач',
        'ПГУ' => 'пневмогидроусилитель',
        'ОГ'  => 'система выпуска газов',
    ];
    $result = $text;
    foreach ($map as $abbr => $full) {
        $pattern = '/(?<![А-ЯA-Z])' . preg_quote($abbr, '/') . '(?![А-ЯA-Z])/ui';
        $result = preg_replace($pattern, $full, $result);
    }
    return $result;
}

/* ============================================================
   Расшифровка типов
   ============================================================ */
function opTypeInfo(?string $code): array {
    $c = strtoupper(trim((string)$code));
    if ($c === '') return ['short' => '—', 'full' => '—'];
    if (preg_match('/^9{4,}/', $c)) {
        return ['short' => 'Ненормированная', 'full' => 'Ненормированная трудоёмкость'];
    }
    $letter = mb_substr($c, 0, 1);
    $rus = ['А'=>'A','В'=>'B','Т'=>'T','Х'=>'X','Е'=>'E','Р'=>'P','С'=>'C','М'=>'M'];
    if (isset($rus[$letter])) $letter = $rus[$letter];
    switch ($letter) {
        case 'A': return ['short' => 'Административные', 'full' => 'Административные'];
        case 'B': return ['short' => 'Предпродажная', 'full' => 'Предпродажная подготовка'];
        case 'T': return ['short' => 'ТО', 'full' => 'Техническое обслуживание'];
        case 'X': return ['short' => 'Комплекс ТО', 'full' => 'Комплекс работ ТО'];
        case 'E': return ['short' => 'Диагностика', 'full' => 'Диагностические работы'];
        case 'P': return ['short' => 'Постовые ТРП', 'full' => 'Постовые работы текущего ремонта'];
        case 'C': return ['short' => 'Цеховые ТРЦ', 'full' => 'Цеховые работы текущего ремонта'];
        case 'M': return ['short' => 'ОТМ', 'full' => 'Доработка (ОТМ)'];
        default:  return ['short' => '—', 'full' => '—'];
    }
}

/* ============================================================
   GigaChat: access_token
   ============================================================ */
function getGigaChatToken(): ?string {
    $authKey = getenv('GIGACHAT_AUTH_KEY');
    $scope   = getenv('GIGACHAT_SCOPE') ?: 'GIGACHAT_API_PERS';
    if (!$authKey) return null;

    $cacheFile = sys_get_temp_dir() . '/gigachat_token_' . md5($authKey) . '.json';
    if (is_file($cacheFile)) {
        $cached = json_decode((string)file_get_contents($cacheFile), true);
        if (!empty($cached['access_token']) && !empty($cached['expires_at'])) {
            if ($cached['expires_at'] - 60 > time()) return $cached['access_token'];
        }
    }

    $rquid = sprintf('%04x%04x-%04x-%04x-%04x-%04x%04x%04x',
        mt_rand(0, 0xffff), mt_rand(0, 0xffff),
        mt_rand(0, 0xffff),
        mt_rand(0, 0x0fff) | 0x4000,
        mt_rand(0, 0x3fff) | 0x8000,
        mt_rand(0, 0xffff), mt_rand(0, 0xffff), mt_rand(0, 0xffff)
    );

    $ch = curl_init('https://ngw.devices.sberbank.ru:9443/api/v2/oauth');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => 'scope=' . urlencode($scope),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/x-www-form-urlencoded',
            'Accept: application/json',
            'RqUID: ' . $rquid,
            'Authorization: Basic ' . $authKey,
        ],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err || $code !== 200) return null;
    $data = json_decode((string)$resp, true);
    if (empty($data['access_token'])) return null;

    $expiresAt = $data['expires_at'] ?? (time() + 1800);
    file_put_contents($cacheFile, json_encode([
        'access_token' => $data['access_token'],
        'expires_at'   => $expiresAt,
    ]));
    return $data['access_token'];
}

/* ============================================================
   Резолвер VIN → комплектация
   ============================================================ */
function resolveComplectationByVin(string $vin, PDO $pdo): ?string {
    static $cache = [];
    if (isset($cache[$vin])) return $cache[$vin];

    $login    = getenv('ONEC_LOGIN');
    $password = getenv('ONEC_PASSWORD');
    if (!$login || !$password) { $cache[$vin] = null; return null; }

    $attempts = [];
    if (mb_strlen($vin) >= 10) {
        $attempts[] = ['method' => 'VINShassis', 'value' => $vin];
        $attempts[] = ['method' => 'VINTS',      'value' => $vin];
    }
    if (preg_match('/(\d{7})$/', $vin, $m)) {
        $attempts[] = ['method' => 'NumberChassis', 'value' => $m[1]];
    }
    if (mb_strlen($vin) < 10) {
        $attempts[] = ['method' => 'NumberChassis', 'value' => $vin];
    }

    foreach ($attempts as $a) {
        $url = 'https://web-1c.kamaz.ru/GOA/hs/CarData/V1/' . $a['method']
             . '?Number=' . urlencode($a['value']);
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPAUTH       => CURLAUTH_BASIC,
            CURLOPT_USERPWD        => $login . ':' . $password,
            CURLOPT_TIMEOUT        => 15,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER     => ['Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($httpCode !== 200 || !$response) continue;

        $data = json_decode($response, true);
        $raw  = trim((string)($data['Car']['TheDesignCodeOfTheConfiguration'] ?? ''));
        if ($raw === '') continue;

        $normalized = rtrim($raw, "- \t\n\r\0\x0B");
        $candidates = array_values(array_unique(array_filter([$raw, $normalized])));

        $ph = []; $lp = [];
        foreach ($candidates as $i => $v) { $k = ':cv'.$i; $ph[] = $k; $lp[$k] = $v; }
        $st = $pdo->prepare("SELECT complectation FROM work_operations
                              WHERE complectation IN (" . implode(',', $ph) . ")
                              LIMIT 1");
        $st->execute($lp);
        $found = $st->fetchColumn();
        if ($found !== false && $found !== null && $found !== '') {
            $cache[$vin] = (string)$found;
            return $cache[$vin];
        }
        $cache[$vin] = $normalized;
        return $cache[$vin];
    }
    $cache[$vin] = null;
    return null;
}

/* ============================================================
   Приём вопроса и контекста
   ============================================================ */
$question = trim((string)($_POST['q'] ?? ''));
if (mb_strlen($question) < 3) { echo json_encode(['ok'=>false,'error'=>'Слишком короткий вопрос']); exit; }
if (mb_strlen($question) > 600) $question = mb_substr($question, 0, 600);

$contextComplectation = trim((string)($_POST['complectation'] ?? '')) ?: null;
$contextBrand         = trim((string)($_POST['brand'] ?? ''))         ?: null;
$contextChassis       = trim((string)($_POST['chassis'] ?? ''))       ?: null;
$contextModel         = trim((string)($_POST['model'] ?? ''))         ?: null;

$brand = $contextBrand;
if ($brand === null) {
    if (mb_stripos($question, 'компас') !== false) $brand = 'COMPASS';
    if (mb_stripos($question, 'камаз')  !== false) $brand = 'KAMAZ';
    if (mb_stripos($question, 'фотон')  !== false) $brand = 'FOTON';
}

$vinCandidate = null;
if (preg_match('/\b([A-HJ-NPR-Z0-9]{17})\b/i', $question, $m)) {
    $vinCandidate = strtoupper($m[1]);
}

$resolvedComplectation = $contextComplectation;
if ($resolvedComplectation === null && $vinCandidate) {
    $resolvedComplectation = resolveComplectationByVin($vinCandidate, $pdo);
}

$questionExpanded = expandAbbreviations($question);

$stopWords = [
    'какие','какая','какой','каких','работы','работа','работ','работу','работой','работе','работам','работах',
    'найди','найти','поищи','поиск','покажи','показать','дай','дайте','подбери','подскажи','скажи','объясни',
    'по','на','для','и','в','с','о','к','от','до','а','но','же','ли','бы','не','или','либо','то','это',
    'тот','эта','эти','того','все','всё','весь','мне','нам','вам','есть','нет','этот','эту',
    'чем','что','как','где','когда','нужно','надо','нужен','нужна','если','может','можно',
    'авто','автомобиля','автомобиль','тс','список','сколько','который','которая','которые','почему',
    'зачем','требуется','пожалуйста','меня','этом','этой',
    'камаз','камаза','компас','компаса','компасе','фотон','фотона','фотоне',
];

$actionVerbs = ['замен','снять','установить','проверить','отремонтировать','демонтаж','монтаж','разобрать','собрать','отрегулировать'];

$textLower = mb_strtolower($questionExpanded);
$textClean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $textLower);
$words = preg_split('/\s+/u', $textClean);
$keywords = [];
foreach ($words as $w) {
    $w = trim($w);
    if (mb_strlen($w) < 4) continue;
    if (in_array($w, $stopWords, true)) continue;
    $keywords[] = $w;
}
$keywords = array_values(array_unique($keywords));

if (empty($keywords)) {
    echo json_encode(['ok'=>false,'error'=>'Не удалось выделить ключевые слова. Переформулируйте вопрос.']);
    exit;
}

$synonyms = [
    'диагност'    => ['проверк', 'оценк', 'дефектовк'],
    'проверк'     => ['диагност', 'оценк'],
    'подвес'      => ['амортизатор', 'рессор', 'пружин'],
    'амортизатор' => ['подвес', 'рессор'],
    'рессор'      => ['подвес', 'амортизатор'],
    'прокладк'    => ['прокладка', 'уплотнен'],
    'головк'      => ['гбц'],
    'уплотнен'    => ['прокладк', 'сальник'],
];

$stems = [];
foreach ($keywords as $kw) {
    $stem = mb_substr($kw, 0, max(4, mb_strlen($kw) - 2));
    if (mb_strlen($stem) < 4) $stem = $kw;
    $stems[] = $stem;
    if (isset($synonyms[$stem])) {
        foreach ($synonyms[$stem] as $syn) {
            if (mb_strlen($syn) >= 4) $stems[] = $syn;
        }
    }
}
$stems = array_values(array_unique($stems));

$specificStems = [];
foreach ($stems as $s) {
    $isVerb = false;
    foreach ($actionVerbs as $v) {
        if (mb_strpos($s, $v) === 0 || mb_strpos($v, $s) === 0) { $isVerb = true; break; }
    }
    if (!$isVerb) $specificStems[] = $s;
}
if (empty($specificStems)) $specificStems = $stems;

/* ============================================================
   Фильтр по бренду / комплектации
   ============================================================ */
$baseWhere  = ['it_is_group = FALSE', 'deleted = FALSE', 'operation_code IS NOT NULL'];
$baseParams = [];
$familyFilterActive = false;
$familyFilterValue  = null;

if ($resolvedComplectation !== null) {
    $fotonFamilies = ['AUMAN','AUMARK','TOANO','SAUVANA','GRATOUR','TUNLAND','VIEW','SUP','Miler','LOXA','TM'];
    if ($brand === 'FOTON' && in_array($resolvedComplectation, $fotonFamilies, true)) {
        $baseWhere[] = 'complectation LIKE :comp';
        $baseParams[':comp'] = $resolvedComplectation . '%';
        $familyFilterActive = true;
        $familyFilterValue  = $resolvedComplectation;
    } else {
        $baseWhere[] = 'complectation = :comp';
        $baseParams[':comp'] = $resolvedComplectation;
    }
} elseif ($contextChassis !== null) {
    $baseWhere[] = 'complectation = :chassis';
    $baseParams[':chassis'] = $contextChassis;
} elseif ($brand !== null) {
    $baseWhere[] = 'brand = :brand';
    $baseParams[':brand'] = $brand;
}

/* ============================================================
   Функция поиска
   ============================================================ */
function searchWorks(PDO $pdo, array $baseWhere, array $baseParams, array $stems, array $specificStems, int $limit = 60): array {
    $where = $baseWhere;
    $params = $baseParams;
    $ors = [];
    foreach ($specificStems as $i => $s) {
        $k = ":a{$i}";
        $params[$k] = '%' . $s . '%';
        $ors[] = "(name ILIKE $k OR eng_name ILIKE $k)";
    }
    if (!empty($ors)) {
        $where[] = '(' . implode(' AND ', $ors) . ')';
        $sql = "SELECT DISTINCT ON (operation_code)
                       operation_code, name, eng_name, norm_time, complectation, brand
                  FROM work_operations
                 WHERE " . implode(' AND ', $where) . "
                 ORDER BY operation_code, LENGTH(name)
                 LIMIT " . (int)$limit;
        $st = $pdo->prepare($sql);
        $st->execute($params);
        $rows = $st->fetchAll(PDO::FETCH_ASSOC);
        if (!empty($rows)) return $rows;
    }

    $where = $baseWhere;
    $params = $baseParams;
    $ors = [];
    foreach ($stems as $i => $s) {
        $k = ":o{$i}";
        $params[$k] = '%' . $s . '%';
        $ors[] = "(name ILIKE $k OR eng_name ILIKE $k OR operation_code ILIKE $k)";
    }
    $where[] = '(' . implode(' OR ', $ors) . ')';
    $sql = "SELECT DISTINCT ON (operation_code)
                   operation_code, name, eng_name, norm_time, complectation, brand
              FROM work_operations
             WHERE " . implode(' AND ', $where) . "
             ORDER BY operation_code, LENGTH(name)
             LIMIT " . (int)$limit;
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/* --- 1. Основные --- */
$mainWorks = searchWorks($pdo, $baseWhere, $baseParams, $stems, $specificStems, 40);

/* --- 2. Расширение если пусто --- */
$expandedSearch = false;
if (empty($mainWorks) && $familyFilterActive && $brand === 'FOTON') {
    $bwNoFam = [];
    foreach ($baseWhere as $w) {
        if (strpos($w, 'complectation') === false) $bwNoFam[] = $w;
    }
    $bwNoFam[] = 'brand = :brand';
    $bpNoFam = $baseParams;
    unset($bpNoFam[':comp']);
    $bpNoFam[':brand'] = 'FOTON';

    $mainWorks = searchWorks($pdo, $bwNoFam, $bpNoFam, $stems, $specificStems, 40);
    if (!empty($mainWorks)) $expandedSearch = true;
}

/* ============================================================
   Определяем «базовый узел» основной работы
   Например: «Прокладка головки блока цилиндров, замена»
   → узел = «прокладк», «головк»
   ============================================================ */
$isComplex = false;
$mainWorkMaxNorm = 0;
if (!empty($mainWorks)) {
    foreach ($mainWorks as $mw) {
        $n = (float)($mw['norm_time'] ?? 0);
        if ($n > $mainWorkMaxNorm) $mainWorkMaxNorm = $n;
    }
    /* Если есть работа с нормой ≥ 5 ч — считаем её комплексной */
    if ($mainWorkMaxNorm >= 5.0) $isComplex = true;
}

/* ============================================================
   Связанные работы — исключаем «Снять X» / «Установить X», 
   если в основных есть «Заменить X» для того же узла
   ============================================================ */
$relatedWorks = [];
if (!empty($specificStems)) {
    $relWhere = $baseWhere;
    $relParams = $baseParams;
    $rOrs = [];
    foreach ($specificStems as $i => $s) {
        $k = ":r{$i}";
        $relParams[$k] = '%' . $s . '%';
        $rOrs[] = "(name ILIKE $k OR eng_name ILIKE $k)";
    }
    $relWhere[] = '(' . implode(' OR ', $rOrs) . ')';

    $relSql = "SELECT DISTINCT ON (operation_code)
                      operation_code, name, eng_name, norm_time, complectation, brand
                 FROM work_operations
                WHERE " . implode(' AND ', $relWhere) . "
                ORDER BY operation_code, LENGTH(name)
                LIMIT 200";
    $st = $pdo->prepare($relSql);
    $st->execute($relParams);
    $relRaw = $st->fetchAll(PDO::FETCH_ASSOC);

    $mainCodes = array_column($mainWorks, 'operation_code');
    $hasReplaceInMain = false;
    foreach ($mainWorks as $mw) {
        if (preg_match('/замен/iu', $mw['name'])) { $hasReplaceInMain = true; break; }
    }

    foreach ($relRaw as $r) {
        if (in_array($r['operation_code'], $mainCodes, true)) continue;

        /* Если в основных есть «Заменить X» — исключаем «Снять X» и «Установить X» */
        if ($hasReplaceInMain && preg_match('/^\s*(снять|установить)\s/iu', $r['name'])) continue;

        $relatedWorks[] = $r;
        if (count($relatedWorks) >= 30) break;
    }
}

/* ============================================================
   Рекомендуемые — ТОЛЬКО диагностика / дефектовка / проверки
   (без снять-установить)
   ============================================================ */
$recommendedWorks = [];
if (!empty($specificStems)) {
    $recWhere = $baseWhere;
    $recParams = $baseParams;
    $recOrs = [];
    foreach ($specificStems as $i => $s) {
        $k = ":rc{$i}";
        $recParams[$k] = '%' . $s . '%';
        $recOrs[] = "(name ILIKE $k OR eng_name ILIKE $k)";
    }
    $recWhere[] = '(' . implode(' OR ', $recOrs) . ')';
    /* Строго: диагностика, проверка, дефектовка, оценка */
    $recWhere[] = "(operation_code ILIKE 'E%' OR operation_code ILIKE 'Е%'
                   OR name ILIKE '%проверк%' OR name ILIKE '%диагност%'
                   OR name ILIKE '%дефектов%' OR name ILIKE '%оценк%')";

    $recSql = "SELECT DISTINCT ON (operation_code)
                      operation_code, name, eng_name, norm_time, complectation, brand
                 FROM work_operations
                WHERE " . implode(' AND ', $recWhere) . "
                ORDER BY operation_code, LENGTH(name)
                LIMIT 25";
    try {
        $st = $pdo->prepare($recSql);
        $st->execute($recParams);
        $recRaw = $st->fetchAll(PDO::FETCH_ASSOC);

        $allFound = array_merge(
            array_column($mainWorks, 'operation_code'),
            array_column($relatedWorks, 'operation_code')
        );
        foreach ($recRaw as $r) {
            if (in_array($r['operation_code'], $allFound, true)) continue;
            $recommendedWorks[] = $r;
            if (count($recommendedWorks) >= 12) break;
        }
    } catch (Throwable $e) {}
}

/* ============================================================
   Контекст
   ============================================================ */
$filterInfo = '';
if ($brand !== null)         $filterInfo .= 'Бренд: ' . $brand . '. ';
if ($contextModel)           $filterInfo .= 'Модель: ' . $contextModel . '. ';
if ($contextChassis)         $filterInfo .= 'Шасси: ' . $contextChassis . '. ';
if ($vinCandidate)           $filterInfo .= 'VIN: ' . $vinCandidate . '. ';
if ($resolvedComplectation)  $filterInfo .= 'Комплектация: ' . $resolvedComplectation . '. ';

function formatWorkLine(array $w): string {
    $op   = $w['operation_code'] ? '[' . $w['operation_code'] . '] ' : '';
    $type = opTypeInfo($w['operation_code']);
    $tag  = $type['short'] !== '—' ? ' <' . $type['short'] . '> ' : ' ';
    $n    = $w['norm_time'] !== null ? ' (' . rtrim(rtrim(number_format((float)$w['norm_time'], 3, '.', ' '), '0'), '.') . ' ч)' : '';
    $comp = $w['complectation'] ? ' | компл.: ' . $w['complectation'] : '';
    return $op . $tag . $w['name'] . $n . $comp;
}

$ctx = [];
if ($filterInfo) $ctx[] = "=== ФИЛЬТРЫ ===\n" . trim($filterInfo);

if (!empty($expandedSearch)) {
    $ctx[] = "=== ПРИМЕЧАНИЕ ===\nВ исходном семействе ({$familyFilterValue}) ничего не найдено. Показаны работы со всего бренда FOTON.";
}

if (!empty($mainWorks)) {
    $lines = array_map('formatWorkLine', $mainWorks);
    $ctx[] = "=== ОСНОВНЫЕ РАБОТЫ (прямое совпадение с запросом) ===\n" . implode("\n", $lines);
} else {
    $ctx[] = "=== ОСНОВНЫЕ РАБОТЫ ===\nПрямых совпадений не найдено.";
}

if (!empty($relatedWorks)) {
    $lines = array_map('formatWorkLine', $relatedWorks);
    $ctx[] = "=== СВЯЗАННЫЕ РАБОТЫ (та же деталь, другая операция — могут быть дополнительными) ===\n" . implode("\n", $lines);
}

if (!empty($recommendedWorks)) {
    $lines = array_map('formatWorkLine', $recommendedWorks);
    $ctx[] = "=== РЕКОМЕНДУЕМЫЕ РАБОТЫ (диагностика / дефектовка / проверки) ===\n" . implode("\n", $lines);
}

$context = implode("\n\n", $ctx);

/* ============================================================
   Запрос к GigaChat
   ============================================================ */
$token = getGigaChatToken();
if (!$token) {
    echo json_encode(['ok'=>false,'error'=>'Не удалось получить токен GigaChat.']);
    exit;
}

$model = getenv('GIGACHAT_MODEL') ?: 'GigaChat';

$isComplexFlag = $isComplex ? "ОСНОВНАЯ РАБОТА ЯВЛЯЕТСЯ КОМПЛЕКСНОЙ (норма ≥ 5 ч) — она уже включает в себя снятие, установку и замену детали. Не разбивай её на подшаги." : "";

$system = "Ты — опытный эксперт-помощник мастера-приёмщика сервиса КАМАЗ / КОМПАС / ФОТОН.\n"
        . "\n"
        . "{$isComplexFlag}\n"
        . "\n"
        . "ВАЖНОЕ ПРАВИЛО О КОМПЛЕКСНЫХ РАБОТАХ:\n"
        . "  • Если основная работа имеет норму ≥ 5 часов — она УЖЕ ВКЛЮЧАЕТ в себя все операции "
        . "по снятию, установке и замене детали. НЕ расписывай её как последовательность отдельных работ.\n"
        . "  • Если пользователь спрашивает про замену детали — не предлагай отдельно «снять X» и "
        . "«установить X», если такие работы уже подразумеваются в основной работе.\n"
        . "  • В разделе «Дополнительно» предлагай ТОЛЬКО диагностику, дефектовку, проверки, "
        . "замену сопутствующих расходников (болты, прокладки, уплотнения).\n"
        . "\n"
        . "Контекст содержит три секции:\n"
        . "  • ОСНОВНЫЕ — прямое совпадение с запросом (самое главное)\n"
        . "  • СВЯЗАННЫЕ — та же деталь, другая операция\n"
        . "  • РЕКОМЕНДУЕМЫЕ — диагностика, дефектовка, проверки\n"
        . "\n"
        . "РАСШИФРОВКА ТИПОВ (первая буква кода):\n"
        . "  A — Административные | B — Предпродажная | T — ТО | X — Комплекс ТО\n"
        . "  E — Диагностика | P — Постовые (снятие/установка) | C — Цеховые | M — ОТМ\n"
        . "\n"
        . "ФОРМАТ ОТВЕТА:\n"
        . "\n"
        . "🔧 **Основная работа:**\n"
        . "   [код] Название — X.X ч\n"
        . "   _Тип: [постовые/цеховые]. Включает: снятие, замену, установку._  ← если норма ≥ 5 ч\n"
        . "   _Тип: [постовые]._  ← если норма < 5 ч\n"
        . "\n"
        . "📋 **Что дополнительно потребуется:**\n"
        . "- [код] Название — X.X ч   (сопутствующие детали, расходники — из СВЯЗАННЫХ)\n"
        . "(если нет — пропусти этот блок)\n"
        . "\n"
        . "🔍 **Рекомендуется проверить:**\n"
        . "- [код] Название — X.X ч   (диагностика, дефектовка — из РЕКОМЕНДУЕМЫХ)\n"
        . "(если нет — пропусти этот блок)\n"
        . "\n"
        . "💡 **Совет мастеру:** 1–2 фразы — нюанс по работе.\n"
        . "\n"
        . "ЖЁСТКИЕ ПРАВИЛА:\n"
        . "1. НЕ расписывай порядок ремонта как «шаг 1, шаг 2, шаг 3», если основная работа комплексная.\n"
        . "2. Используй ТОЛЬКО коды и нормы из контекста. Не выдумывай.\n"
        . "3. Если точного совпадения нет — предложи близкое из СВЯЗАННЫХ, сказав «точного нет, но есть похожее».\n"
        . "4. Если ничего нет — ответь своими знаниями и начни со строки "
        . "«⚠️ Общий ответ (в базе по этому запросу ничего не найдено):».\n"
        . "5. Отвечай по-русски, кратко, структурированно.";

$payload = [
    'model'       => $model,
    'messages'    => [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => "Вопрос мастера:\n{$question}\n\nКонтекст из базы:\n{$context}"],
    ],
    'temperature' => 0.2,
    'max_tokens'  => 2500,
];

$ch = curl_init('https://gigachat.devices.sberbank.ru/api/v1/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => [
        'Content-Type: application/json',
        'Accept: application/json',
        'Authorization: Bearer ' . $token,
    ],
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['ok'=>false,'error'=>'Ошибка соединения с GigaChat: ' . $err]);
    exit;
}
if ($code !== 200) {
    echo json_encode(['ok'=>false,'error'=>"GigaChat вернул код $code: " . mb_substr((string)$resp, 0, 400)]);
    exit;
}

$data   = json_decode((string)$resp, true);
$answer = $data['choices'][0]['message']['content'] ?? 'Пустой ответ';

echo json_encode([
    'ok'      => true,
    'answer'  => $answer,
    'filters' => [
        'brand'         => $brand,
        'model'         => $contextModel,
        'chassis'       => $contextChassis,
        'vin'           => $vinCandidate,
        'complectation' => $resolvedComplectation,
        'expanded'      => $expandedSearch,
        'is_complex'    => $isComplex,
        'main_norm_max' => $mainWorkMaxNorm,
        'counts'        => [
            'main'        => count($mainWorks),
            'related'     => count($relatedWorks),
            'recommended' => count($recommendedWorks),
        ],
    ],
], JSON_UNESCAPED_UNICODE);
