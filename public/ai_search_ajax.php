<?php
require __DIR__ . '/db.php';
start_session();

header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) { echo json_encode(['ok'=>false,'error'=>'Не авторизован']); exit; }
$pdo = get_db();

/* ============================================================
   Резолвер VIN → комплектация через 1С:ГОА
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

/* ---- Бренд (учитываем контекст) ---- */
$brand = $contextBrand;
if ($brand === null) {
    if (mb_stripos($question, 'компас') !== false) $brand = 'COMPASS';
    if (mb_stripos($question, 'камаз')  !== false) $brand = 'KAMAZ';
}

/* ---- VIN из вопроса ---- */
$vinCandidate = null;
if (preg_match('/\b([A-HJ-NPR-Z0-9]{17})\b/i', $question, $m)) {
    $vinCandidate = strtoupper($m[1]);
}

/* ---- Резолв VIN → комплектация (если VIN указан, но контекста нет) ---- */
$resolvedComplectation = $contextComplectation;
if ($resolvedComplectation === null && $vinCandidate) {
    $resolvedComplectation = resolveComplectationByVin($vinCandidate, $pdo);
}

/* ---- Ключевые слова ---- */
$stopWords = [
    'какие','какая','какой','каких','работы','работа','работ','работу','работой','работе','работам','работах',
    'найди','найти','поищи','поиск','покажи','показать','дай','дайте','подбери','подскажи','скажи','объясни',
    'по','на','для','и','в','с','о','к','от','до','а','но','же','ли','бы','не','или','либо','то','это',
    'тот','эта','эти','того','все','всё','весь','мне','нам','вам','есть','нет','этот','эту',
    'чем','что','как','где','когда','нужно','надо','нужен','нужна','если','может','можно',
    'авто','автомобиля','автомобиль','тс','список','сколько','который','которая','которые','почему',
    'зачем','требуется','пожалуйста','меня','этом','этой',
    'камаз','камаза','компас','компаса','компасе',
];
$textLower = mb_strtolower($question);
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

/* ============================================================
   Поиск работ
   Приоритет фильтра: complectation (контекст или 1С) → chassis → brand
   ============================================================ */
$where  = ['it_is_group = FALSE', 'deleted = FALSE', 'operation_code IS NOT NULL'];
$params = [];

if ($resolvedComplectation !== null) {
    $where[] = 'complectation = :comp';
    $params[':comp'] = $resolvedComplectation;
} elseif ($contextChassis !== null) {
    $where[] = 'complectation = :chassis';
    $params[':chassis'] = $contextChassis;
} elseif ($brand !== null) {
    $where[] = 'brand = :brand';
    $params[':brand'] = $brand;
}

$orParts   = [];
$scoreExpr = [];
foreach ($keywords as $i => $kw) {
    $stem = mb_substr($kw, 0, max(4, mb_strlen($kw) - 2));
    $k = ":k{$i}";
    $params[$k] = '%' . $stem . '%';
    $orParts[]   = "(name ILIKE $k OR eng_name ILIKE $k OR operation_code ILIKE $k)";
    $scoreExpr[] = "CASE WHEN name ILIKE $k THEN 5 ELSE 0 END";
    $scoreExpr[] = "CASE WHEN eng_name ILIKE $k THEN 2 ELSE 0 END";
}
$where[] = '(' . implode(' OR ', $orParts) . ')';

$scoreSQL = '(' . implode(' + ', $scoreExpr) . ')';

$sql = "SELECT operation_code, name, eng_name, norm_time, complectation, brand,
               $scoreSQL AS _score
          FROM work_operations
         WHERE " . implode(' AND ', $where) . "
         ORDER BY _score DESC, LENGTH(name), operation_code
         LIMIT 300";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$works = [];
$seen = [];
foreach ($rows as $r) {
    if (isset($seen[$r['operation_code']])) continue;
    $seen[$r['operation_code']] = true;
    unset($r['_score']);
    $works[] = $r;
    if (count($works) >= 50) break;
}

/* ============================================================
   Контекст для LLM
   ============================================================ */
$filterInfo = '';
if ($brand !== null)         $filterInfo .= 'Бренд: ' . $brand . '. ';
if ($contextModel)           $filterInfo .= 'Модель: ' . $contextModel . '. ';
if ($contextChassis)         $filterInfo .= 'Шасси: ' . $contextChassis . '. ';
if ($vinCandidate)           $filterInfo .= 'VIN: ' . $vinCandidate . '. ';
if ($resolvedComplectation)  $filterInfo .= 'Комплектация: ' . $resolvedComplectation . '. ';

$ctx = [];
if ($filterInfo) $ctx[] = "=== ФИЛЬТРЫ ===\n" . trim($filterInfo);

if (!empty($works)) {
    $lines = [];
    foreach ($works as $w) {
        $op   = $w['operation_code'] ? '[' . $w['operation_code'] . '] ' : '';
        $n    = $w['norm_time'] !== null ? ' (' . rtrim(rtrim(number_format((float)$w['norm_time'], 3, '.', ' '), '0'), '.') . ' ч)' : '';
        $comp = $w['complectation'] ? ' | компл.: ' . $w['complectation'] : '';
        $lines[] = $op . $w['name'] . $n . $comp;
    }
    $ctx[] = "=== СПРАВОЧНИК РАБОТ (сверху — самые релевантные) ===\n" . implode("\n", $lines);
} else {
    $ctx[] = "=== СПРАВОЧНИК РАБОТ ===\nНичего не найдено по запросу.";
}

$context = implode("\n\n", $ctx);

/* ============================================================
   Запрос к LLM
   ============================================================ */
$apiKey  = getenv('AI_API_KEY');
$baseUrl = rtrim((string)getenv('AI_BASE_URL'), '/');
$model   = getenv('AI_MODEL') ?: 'deepseek/deepseek-chat';

if (!$apiKey || !$baseUrl) {
    echo json_encode(['ok'=>false,'error'=>'Не настроены AI_API_KEY / AI_BASE_URL']);
    exit;
}

$system = "Ты — помощник мастера-приёмщика сервиса КАМАЗ/КОМПАС.\n"
        . "Тебе дают контекст: фильтры и справочник работ (отсортирован по релевантности).\n"
        . "ПРАВИЛА:\n"
        . "1. Перечисли работы из контекста, которые подходят под вопрос. Не добавляй ничего от себя.\n"
        . "2. Если работ по фильтру нет — честно скажи, для какой комплектации/модели искал.\n"
        . "3. Не выдумывай коды и номера, которых нет в контексте.\n"
        . "4. Отвечай кратко, по-русски.";

$payload = [
    'model'       => $model,
    'messages'    => [
        ['role' => 'system', 'content' => $system],
        ['role' => 'user',   'content' => "Вопрос:\n{$question}\n\nКонтекст:\n{$context}"],
    ],
    'temperature' => 0.2,
    'max_tokens'  => 1500,
];

$ch = curl_init($baseUrl . '/chat/completions');
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST           => true,
    CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
    CURLOPT_HTTPHEADER     => ['Content-Type: application/json', 'Authorization: Bearer ' . $apiKey],
    CURLOPT_TIMEOUT        => 120,
    CURLOPT_SSL_VERIFYPEER => false,
    CURLOPT_SSL_VERIFYHOST => false,
]);
$resp = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$err  = curl_error($ch);
curl_close($ch);

if ($err) {
    echo json_encode(['ok'=>false,'error'=>'Ошибка соединения с ИИ: ' . $err]);
    exit;
}
if ($code !== 200) {
    echo json_encode(['ok'=>false,'error'=>"ИИ вернул код $code: " . mb_substr((string)$resp, 0, 400)]);
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
    ],
], JSON_UNESCAPED_UNICODE);
