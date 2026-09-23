<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) { header('Location: login.php'); exit; }
$pdo = get_db();

/* Маппинг моделей КОМПАС → номер шасси */
$COMPASS_MODELS = [
    '5'  => '43085',
    '6'  => '43086',
    '9'  => '43089',
    '12' => '43082',
];

/**
 * Резолвит VIN в номер комплектации через 1С:ГОА.
 * Пробует: VINShassis → VINTS → NumberChassis (последние 7 цифр).
 */
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
   ДИАГНОСТИКА
   ============================================================ */

/* ?debug=LT3700110 — сырые строки из work_operations */
if (!empty($_GET['debug']) && $user['is_admin']) {
    header('Content-Type: application/json; charset=utf-8');
    $code = trim((string)$_GET['debug']);
    $stmt = $pdo->prepare("
        SELECT operation_code, name, brand, complectation, model, it_is_group, deleted,
               parent_code, code
          FROM work_operations
         WHERE operation_code ILIKE :c
         ORDER BY operation_code
         LIMIT 50
    ");
    $stmt->execute([':c' => '%' . $code . '%']);
    echo json_encode([
        'query'   => $code,
        'matches' => $stmt->fetchAll(),
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* ?debug_vin=XTC... — что 1С отдаёт по этому VIN */
if (!empty($_GET['debug_vin']) && $user['is_admin']) {
    header('Content-Type: application/json; charset=utf-8');
    $vin = strtoupper(trim((string)$_GET['debug_vin']));

    $login    = getenv('ONEC_LOGIN');
    $password = getenv('ONEC_PASSWORD');
    $results  = [];

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
        $resp = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        $design = null;
        $carName = null;
        if ($code === 200 && $resp) {
            $j = json_decode($resp, true);
            $design  = $j['Car']['TheDesignCodeOfTheConfiguration'] ?? null;
            $carName = $j['Car']['Name'] ?? null;
        }
        $results[] = [
            'method'        => $a['method'],
            'value'         => $a['value'],
            'http_code'     => $code,
            'complectation' => $design,
            'car_name'      => $carName,
            'raw_first_500' => mb_substr((string)$resp, 0, 500),
        ];
    }

    echo json_encode(['vin' => $vin, 'attempts' => $results], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    exit;
}

/* ============================================================
   AJAX: обработка вопроса
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['q'])) {
    header('Content-Type: application/json; charset=utf-8');

    $question = trim((string)$_POST['q']);
    if (mb_strlen($question) < 3) {
        echo json_encode(['ok' => false, 'error' => 'Слишком короткий вопрос']);
        exit;
    }
    if (mb_strlen($question) > 600) $question = mb_substr($question, 0, 600);

    /* ---- Модель (Компас N) ---- */
    $chassis  = null;
    $modelTxt = null;
    if (preg_match('/компас\s*(\d+)/ui', $question, $m)) {
        if (isset($COMPASS_MODELS[$m[1]])) {
            $chassis  = $COMPASS_MODELS[$m[1]];
            $modelTxt = 'Компас ' . $m[1];
        }
    }

    /* ---- Бренд ---- */
    $brand = null;
    if (mb_stripos($question, 'компас') !== false) $brand = 'COMPASS';
    if (mb_stripos($question, 'камаз')  !== false) $brand = 'KAMAZ';

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

    /* ---- Явные идентификаторы ---- */
    $vinCandidate = null;
    if (preg_match('/\b([A-HJ-NPR-Z0-9]{17})\b/i', $question, $m)) {
        $vinCandidate = strtoupper($m[1]);
    }
    $numberCandidate = null;
    if (!$vinCandidate && preg_match('/\b(\d{4,8})\b/', $question, $m)) {
        $numberCandidate = $m[1];
    }

       /* ---- Комплектация из POST (со страницы works.php / works_compass.php) ---- */
    $postComplectation = trim((string)($_POST['complectation'] ?? ''));
    $resolvedComplectation = null;
    $resolveError = null;

    if ($postComplectation !== '') {
        $resolvedComplectation = $postComplectation;
    } elseif ($vinCandidate) {
        $resolvedComplectation = resolveComplectationByVin($vinCandidate, $pdo);
        if ($resolvedComplectation === null) {
            $resolveError = 'Не удалось получить комплектацию по VIN из 1С.';
        }
    }

    /* ============================================================
       Поиск по справочнику работ
       ============================================================ */
    $works = [];
    $canSearchWorks = !empty($keywords) || $vinCandidate;

    if ($canSearchWorks && !empty($keywords)) {
        $where  = ['it_is_group = FALSE', 'deleted = FALSE', 'operation_code IS NOT NULL'];
        $params = [];

        if ($resolvedComplectation !== null) {
            $where[] = 'complectation = :comp';
            $params[':comp'] = $resolvedComplectation;
        } elseif ($chassis !== null) {
            $where[] = 'complectation = :chassis';
            $params[':chassis'] = $chassis;
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

        $seen = [];
        foreach ($rows as $r) {
            if (isset($seen[$r['operation_code']])) continue;
            $seen[$r['operation_code']] = true;
            unset($r['_score']);
            $works[] = $r;
            if (count($works) >= 50) break;
        }
    }

    /* ============================================================
       Поиск по рекламационным актам (keys)
       ============================================================ */
    $raList = [];
    try {
        $raWhere  = [];
        $raParams = [];

        if ($vinCandidate) {
            $raWhere[] = "key_value ILIKE :vin";
            $raParams[':vin'] = '%' . $vinCandidate . '%';
        }
        if ($numberCandidate) {
            $raWhere[] = "(key_value ILIKE :num OR COALESCE(order_number,'') ILIKE :num)";
            $raParams[':num'] = '%' . $numberCandidate . '%';
        }
        if (!empty($keywords)) {
            $raOr = [];
            foreach ($keywords as $i => $kw) {
                $k1 = ":r{$i}_kv"; $k2 = ":r{$i}_gn"; $k3 = ":r{$i}_on"; $k4 = ":r{$i}_ds";
                $raOr[] = "(key_value ILIKE $k1 OR COALESCE(gos_number,'') ILIKE $k2"
                        . " OR COALESCE(order_number,'') ILIKE $k3 OR COALESCE(description,'') ILIKE $k4)";
                $raParams[$k1] = '%' . $kw . '%';
                $raParams[$k2] = '%' . $kw . '%';
                $raParams[$k3] = '%' . $kw . '%';
                $raParams[$k4] = '%' . $kw . '%';
            }
            $raWhere[] = '(' . implode(' OR ', $raOr) . ')';
        }

        if (!empty($raWhere)) {
            $raSql = "SELECT key_type, key_value, gos_number, order_number, description,
                             user_id, created_at, updated_at
                        FROM keys
                       WHERE (" . implode(' OR ', $raWhere) . ")
                       ORDER BY updated_at DESC NULLS LAST, created_at DESC
                       LIMIT 20";
            $stmt = $pdo->prepare($raSql);
            $stmt->execute($raParams);
            $raList = $stmt->fetchAll();
        }

        foreach ($raList as &$ra) {
            $stmt = $pdo->prepare("SELECT COUNT(*) FROM photos WHERE key_type = :kt AND key_value = :kv");
            $stmt->execute([':kt' => $ra['key_type'], ':kv' => $ra['key_value']]);
            $ra['photo_count'] = (int)$stmt->fetchColumn();
        }
        unset($ra);
    } catch (Throwable $e) {}

    /* ============================================================
       Контекст для LLM
       ============================================================ */
    $ctxParts = [];

    $filterInfo = '';
    if ($brand !== null)         $filterInfo .= 'Бренд: ' . $brand . '. ';
    if ($modelTxt)               $filterInfo .= 'Модель: ' . $modelTxt . ' (шасси ' . $chassis . '). ';
    if ($vinCandidate)           $filterInfo .= 'VIN: ' . $vinCandidate . '. ';
    if ($resolvedComplectation)  $filterInfo .= 'Комплектация: ' . $resolvedComplectation . '. ';
    if ($resolveError)           $filterInfo .= 'ОШИБКА РЕЗОЛВА: ' . $resolveError . ' ';
    if ($filterInfo) $ctxParts[] = "=== ФИЛЬТРЫ, ПРИМЕНЁННЫЕ К ПОИСКУ ===\n" . trim($filterInfo);

    if (!empty($works)) {
        $lines = [];
        foreach ($works as $w) {
            $op   = $w['operation_code'] ? '[' . $w['operation_code'] . '] ' : '';
            $n    = $w['norm_time'] !== null ? ' (' . rtrim(rtrim(number_format((float)$w['norm_time'], 3, '.', ' '), '0'), '.') . ' ч)' : '';
            $comp = $w['complectation'] ? ' | компл.: ' . $w['complectation'] : '';
            $br   = $w['brand'] ? ' | ' . $w['brand'] : '';
            $lines[] = $op . $w['name'] . $n . $comp . $br;
        }
        $ctxParts[] = "=== СПРАВОЧНИК РАБОТ (отсортирован по релевантности) ===\n" . implode("\n", $lines);
    } else {
        if ($resolvedComplectation) {
            $ctxParts[] = "=== СПРАВОЧНИК РАБОТ ===\n"
                . "Для комплектации $resolvedComplectation работ по запросу не найдено. "
                . "Возможно, они ещё не загружены — откройте works.php и введите этот VIN.";
        } else {
            $ctxParts[] = "=== СПРАВОЧНИК РАБОТ ===\nНичего не найдено по запросу.";
        }
    }

    if (!empty($raList)) {
        $lines = [];
        foreach ($raList as $r) {
            $line = ($r['key_type'] === 'ra' ? 'РА' : 'VIN') . ': ' . $r['key_value'];
            if (!empty($r['description']))   $line .= ' — ' . $r['description'];
            if (!empty($r['gos_number']))    $line .= ' | гос.: ' . $r['gos_number'];
            if (!empty($r['order_number']))  $line .= ' | ЗН: ' . $r['order_number'];
            $line .= ' | фото: ' . $r['photo_count'];
            if (!empty($r['created_at']))    $line .= ' | создан: ' . date('d.m.Y', strtotime($r['created_at']));
            $lines[] = $line;
        }
        $ctxParts[] = "=== РЕКЛАМАЦИОННЫЕ АКТЫ / ЗАПИСИ ===\n" . implode("\n", $lines);
    }

    $context = implode("\n\n", $ctxParts);

    /* ============================================================
       Запрос к LLM
       ============================================================ */
    $apiKey  = getenv('AI_API_KEY');
    $baseUrl = rtrim((string)getenv('AI_BASE_URL'), '/');
    $model   = getenv('AI_MODEL') ?: 'deepseek/deepseek-chat';

    if (!$apiKey || !$baseUrl) {
        echo json_encode(['ok' => false, 'error' => 'Не настроены AI_API_KEY / AI_BASE_URL']);
        exit;
    }

    $system = "Ты — помощник мастера-приёмщика сервиса КАМАЗ/КОМПАС.\n"
            . "Тебе дают контекст: фильтры поиска, справочник работ и записи рекламационных актов (РА/VIN).\n"
            . "Работы уже отфильтрованы по комплектации (если был VIN) или по шасси/бренду.\n"
            . "Работы отсортированы по релевантности — самые похожие сверху.\n"
            . "\n"
            . "ПРАВИЛА:\n"
            . "1. Перечисли работы из контекста, которые подходят под вопрос. Не добавляй ничего от себя.\n"
            . "2. Если работ по комплектации нет — честно скажи и предложи загрузить их через works.php.\n"
            . "3. Если в контексте совсем пусто и нет специфического запроса — можешь ответить своими знаниями, "
            . "начав со строки «⚠️ Общий ответ (в базе по этому запросу ничего не найдено):».\n"
            . "4. Не выдумывай коды и номера, которых нет в контексте.\n"
            . "5. Отвечай кратко, по-русски.";

    $userMsg = "Вопрос пользователя:\n{$question}\n\n"
             . "Контекст из базы (только это — источник правды):\n{$context}";

    $payload = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userMsg],
        ],
        'temperature' => 0.2,
        'max_tokens'  => 1500,
    ];

    $ch = curl_init($baseUrl . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
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
        echo json_encode(['ok' => false, 'error' => 'Ошибка соединения с ИИ: ' . $err]);
        exit;
    }
    if ($code !== 200) {
        echo json_encode(['ok' => false, 'error' => "ИИ вернул код $code: " . mb_substr((string)$resp, 0, 400)]);
        exit;
    }

    $data   = json_decode((string)$resp, true);
    $answer = $data['choices'][0]['message']['content'] ?? 'Пустой ответ от ИИ';

    echo json_encode([
        'ok'      => true,
        'answer'  => $answer,
        'works'   => $works,
        'ra'      => $raList,
        'filters' => [
            'brand'         => $brand,
            'model'         => $modelTxt,
            'chassis'       => $chassis,
            'vin'           => $vinCandidate,
            'complectation' => $resolvedComplectation,
            'resolveError'  => $resolveError,
        ],
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Помощник ИИ — работы и РА</title>
<style>
  *{box-sizing:border-box}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;margin:0;padding:16px;color:#1a1a1a;line-height:1.5}
  .container{max-width:1100px;margin:0 auto}
  .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,0.06);margin-bottom:16px}
  h1{font-size:22px;margin:0 0 8px}
  h2{font-size:16px;margin:0 0 12px;color:#1e3a8a}
  .top-bar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px}
  .user-info{font-size:13px;color:#666}
  .user-info b{color:#2563eb}
  .logout{color:#dc2626;text-decoration:none;font-size:13px;margin-left:12px}
  .btn{display:inline-block;padding:9px 14px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;background:#2563eb;color:#fff;text-decoration:none;text-align:center;font-family:inherit}
  .btn:hover{opacity:0.9}
  .btn-secondary{background:#fff;color:#2563eb;border:1.5px solid #2563eb}
  .btn-small{padding:7px 12px;font-size:13px}
  .btn-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
  .ask-form{display:flex;gap:10px;flex-wrap:wrap}
  .ask-form textarea{flex:1;min-width:260px;padding:12px;border:1.5px solid #93c5fd;border-radius:10px;font-family:inherit;font-size:15px;min-height:60px;resize:vertical;background:#eff6ff}
  .ask-form textarea:focus{outline:none;border-color:#2563eb;background:#fff}
  .answer-box{background:#f0fdf4;border-left:4px solid #16a34a;padding:14px 16px;border-radius:10px;margin-top:14px;white-space:pre-wrap;font-size:14px;line-height:1.6}
  .error-box{background:#fef2f2;border-left:4px solid #dc2626;padding:14px 16px;border-radius:10px;margin-top:14px;font-size:14px;color:#991b1b}
  .loading{color:#2563eb;font-size:14px;margin-top:12px;display:none}
  .hint{font-size:13px;color:#666;background:#f9fafb;border-left:3px solid #2563eb;padding:8px 12px;border-radius:8px;margin-bottom:12px}
  .example{display:inline-block;background:#eff6ff;color:#1e3a8a;padding:4px 10px;border-radius:6px;font-size:12px;cursor:pointer;margin:3px 4px 3px 0;border:1px solid #bfdbfe}
  .example:hover{background:#dbeafe}
  .filters{display:flex;gap:6px;flex-wrap:wrap;margin-top:10px}
  .filter-badge{background:#e0e7ff;color:#3730a3;padding:3px 10px;border-radius:12px;font-size:12px;font-weight:600}
  .filter-badge.error{background:#fef2f2;color:#991b1b;}
</style>
</head>
<body>
<div class="container">

  <div class="card">
    <div class="top-bar">
      <h1>🤖 Помощник ИИ — работы и РА</h1>
      <div>
        <span class="user-info">👤 <b><?= e($user['name'] ?: $user['email']) ?></b></span>
        <a href="logout.php" class="logout">Выйти</a>
      </div>
    </div>
    <div class="btn-row">
      <a href="index.html" class="btn btn-secondary btn-small">← На рабочее место</a>
      <a href="works_brand.php" class="btn btn-secondary btn-small">🔧 Справочник работ</a>
      <a href="gallery.php" class="btn btn-secondary btn-small">📸 Фото по РА</a>
    </div>
    <div class="hint">
      Спросите что угодно: по справочнику работ, по рекламационным актам, по VIN и фото.
      Можно указывать модель: «найди замену генератора на Компас 9».
    </div>
    <div>
      Примеры вопросов:
      <span class="example" onclick="fillQ(this)">найди работу по замене генератора на Компас 9</span>
      <span class="example" onclick="fillQ(this)">Что значит код C10-0236?</span>
      <span class="example" onclick="fillQ(this)">Какие РА есть по VIN XTC549015S2628195?</span>
      <span class="example" onclick="fillQ(this)">Как работает система охлаждения КАМАЗ?</span>
    </div>
  </div>

  <div class="card">
    <h2>Ваш вопрос</h2>
    <form class="ask-form" onsubmit="askAI(event)">
      <textarea id="q" placeholder="Например: найди работу по замене генератора на Компас 9" autofocus></textarea>
      <button type="submit" class="btn" id="askBtn" style="min-width:120px;">🤖 Спросить ИИ</button>
    </form>
    <div class="loading" id="loading">⏳ Ищу в базе и спрашиваю ИИ… Это занимает 5–20 секунд.</div>
    <div id="result"></div>
  </div>

</div>

<script>
function fillQ(el) {
  document.getElementById('q').value = el.textContent.trim();
  document.getElementById('q').focus();
}
function escapeHtml(s) {
  const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML;
}

async function askAI(e) {
  e.preventDefault();
  const q = document.getElementById('q').value.trim();
  if (q.length < 3) return;

  const btn = document.getElementById('askBtn');
  const loading = document.getElementById('loading');
  const result = document.getElementById('result');

  btn.disabled = true;
  btn.textContent = '⏳ Думаю…';
  loading.style.display = 'block';
  result.innerHTML = '';

  try {
    const fd = new FormData();
    fd.append('q', q);

    const resp = await fetch('ai_assistant.php', { method: 'POST', body: fd });
    const data = await resp.json();

    if (!data.ok) {
      result.innerHTML = '<div class="error-box">❌ ' + escapeHtml(data.error || 'Ошибка') + '</div>';
      return;
    }

    let html = '';
    const f = data.filters || {};
    const badges = [];
    if (f.brand)         badges.push('Бренд: ' + f.brand);
    if (f.model)         badges.push('Модель: ' + f.model);
    if (f.chassis)       badges.push('Шасси: ' + f.chassis);
    if (f.vin)           badges.push('VIN: ' + f.vin);
    if (f.complectation) badges.push('Комплектация: ' + f.complectation);
    if (badges.length) {
      html += '<div class="filters">' + badges.map(b => '<span class="filter-badge">' + escapeHtml(b) + '</span>').join('') + '</div>';
    }
    if (f.resolveError) {
      html += '<div class="filters"><span class="filter-badge error">⚠️ ' + escapeHtml(f.resolveError) + '</span></div>';
    }

    html += '<div class="answer-box">' + escapeHtml(data.answer) + '</div>';

    result.innerHTML = html;
  } catch (err) {
    result.innerHTML = '<div class="error-box">❌ Ошибка запроса: ' + escapeHtml(err.message) + '</div>';
  } finally {
    btn.disabled = false;
    btn.textContent = '🤖 Спросить ИИ';
    loading.style.display = 'none';
  }
}
</script>
</body>
</html>
