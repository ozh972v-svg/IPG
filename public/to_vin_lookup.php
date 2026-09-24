<?php
/**
 * AJAX endpoint: VIN → комплектация через 1С:ГОА.
 * GET/POST: vin=...
 * Возвращает JSON:
 *   {ok, found_by, complectation, model, matrix_matches, exact_match, car}
 * или {ok: false, error: "..."}
 */
require __DIR__ . '/db.php';
start_session();
header('Content-Type: application/json; charset=utf-8');

$user = current_user();
if (!$user) {
    echo json_encode(['ok' => false, 'error' => 'Не авторизован'], JSON_UNESCAPED_UNICODE);
    exit;
}

$vin = strtoupper(trim((string)($_GET['vin'] ?? $_POST['vin'] ?? '')));
if ($vin === '' || strlen($vin) < 8) {
    echo json_encode(['ok' => false, 'error' => 'Введите VIN (минимум 8 символов)'], JSON_UNESCAPED_UNICODE);
    exit;
}

$login    = getenv('ONEC_LOGIN');
$password = getenv('ONEC_PASSWORD');
if (!$login || !$password) {
    echo json_encode(['ok' => false, 'error' => 'Не настроены ONEC_LOGIN и ONEC_PASSWORD'], JSON_UNESCAPED_UNICODE);
    exit;
}

/** Запрос к 1С */
function onec_request(string $method, string $number, string $login, string $password): array
{
    $url = 'https://web-1c.kamaz.ru/GOA/hs/CarData/V1/' . $method
         . '?Number=' . urlencode($number);

    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
        CURLOPT_USERPWD => $login . ':' . $password,
        CURLOPT_TIMEOUT => 30,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlError = curl_error($ch);
    curl_close($ch);

    if ($curlError) {
        return ['ok' => false, 'error' => 'Ошибка соединения с 1С: ' . $curlError, 'http' => 0];
    }
    if ($httpCode === 401) {
        return ['ok' => false, 'error' => 'Неверный логин/пароль 1С', 'http' => 401];
    }
    if ($httpCode === 403) {
        return ['ok' => false, 'error' => 'Нет прав у пользователя 1С', 'http' => 403];
    }
    if ($httpCode !== 200) {
        return ['ok' => false, 'error' => '1С вернул код ' . $httpCode, 'http' => $httpCode];
    }

    $data = json_decode($response, true);
    if (!is_array($data) || !isset($data['Car'])) {
        return ['ok' => false, 'error' => 'Автотехника не найдена', 'http' => 200];
    }
    return ['ok' => true, 'car' => $data['Car'], 'http' => 200];
}

/**
 * Жёсткая нормализация кода комплектации — используется для сравнения.
 * Убирает NBSP, длинные/короткие дефисы, множественные пробелы, приводит к верхнему регистру.
 */
function normalize_code(string $s): string
{
    // NBSP, узкий NBSP, тонкий пробел → обычный пробел
    $s = str_replace(["\xC2\xA0", "\xE2\x80\xAF", "\xE2\x80\x89"], ' ', $s);
    // Разные виды дефисов → обычный дефис
    $s = str_replace(["\xE2\x80\x90", "\xE2\x80\x91", "\xE2\x80\x92", "\xE2\x80\x93", "\xE2\x80\x94", "\xE2\x88\x92"], '-', $s);
    // Убираем ВСЕ пробелы (внутри и снаружи)
    $s = preg_replace('/\s+/u', '', $s);
    return mb_strtoupper($s, 'UTF-8');
}

/** Извлекает модель: 54901-0070004-CA → 54901 */
function extract_model(string $c): ?string
{
    if (preg_match('/([0-9]{5})/', $c, $m)) return $m[1];
    return null;
}

// Пробуем VIN шасси, потом VIN ТС
$attempts = ['VINShassis', 'VINTS'];
$lastError = 'Автотехника не найдена';
$found = null;

foreach ($attempts as $method) {
    $r = onec_request($method, $vin, $login, $password);
    if ($r['ok']) {
        $found = ['car' => $r['car'], 'found_by' => $method];
        break;
    }
    $lastError = $r['error'];
}

if (!$found) {
    echo json_encode(['ok' => false, 'error' => $lastError], JSON_UNESCAPED_UNICODE);
    exit;
}

$car = $found['car'];
$complectation = trim((string)($car['TheDesignCodeOfTheConfiguration'] ?? ''));
$model = $complectation !== '' ? extract_model($complectation) : null;

// --- Поиск в БД через нормализацию ---
$db = get_db();
$all = $db->query("SELECT complectation, model FROM to_matrices ORDER BY complectation")
          ->fetchAll(PDO::FETCH_ASSOC);

$exactMatch = false;
$matrixMatches = [];
$needleNorm = normalize_code($complectation);

if ($needleNorm !== '') {
    foreach ($all as $row) {
        if (normalize_code($row['complectation']) === $needleNorm) {
            $exactMatch = true;
            $matrixMatches[] = [
                'complectation' => $row['complectation'], // реальное значение из БД
                'model'         => $row['model'],
            ];
            break;
        }
    }
}

// Если точной нет — ищем по модели
if (!$exactMatch && $model) {
    foreach ($all as $row) {
        if ($row['model'] === $model) {
            $matrixMatches[] = [
                'complectation' => $row['complectation'],
                'model'         => $row['model'],
            ];
        }
    }
}

$carInfo = [
    'VINShassis'     => $car['VINShassis']    ?? null,
    'VINTS'          => $car['VINTS']         ?? null,
    'ShassisModel'   => $car['ShassisModel']  ?? null,
    'CarModel'       => $car['CarModel']      ?? null,
    'NumberShassis'  => $car['NumberShassis'] ?? null,
    'NumberEngine'   => $car['NumberEngine']  ?? null,
    'EngineModel'    => $car['EngineModel']   ?? null,
    'ProductionDate' => $car['ProductionDate'] ?? null,
];

echo json_encode([
    'ok'             => true,
    'found_by'       => $found['found_by'],
    'complectation'  => $complectation,
    'model'          => $model,
    'exact_match'    => $exactMatch,
    'matrix_matches' => $matrixMatches,
    'car'            => $carInfo,
], JSON_UNESCAPED_UNICODE);
