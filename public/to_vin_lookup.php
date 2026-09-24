<?php
/**
 * AJAX endpoint: VIN → комплектация через 1С:ГОА.
 * GET/POST: vin=...
 * Возвращает JSON:
 *   {ok, found_by, complectation, model, matrix_matches, exact_match, ...}
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

/** Запрос к 1С. Возвращает ['ok'=>bool, 'car'=>?, 'error'=>?] */
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

/** Извлекает модель из комплектации: 54901-0070004-CA → 54901 */
function extract_model(string $c): ?string
{
    if (preg_match('/^([0-9]{4,6})/', $c, $m)) return $m[1];
    return null;
}

// Пробуем сначала VIN шасси, потом VIN ТС
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

// Проверяем, есть ли такая комплектация в БД; если нет — ищем по модели
$db = get_db();
$exactMatch = false;
$matrixMatches = [];

if ($complectation !== '') {
    $st = $db->prepare("SELECT complectation, model FROM to_matrices WHERE complectation = ?");
    $st->execute([$complectation]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        $exactMatch = true;
        $matrixMatches[] = $row;
    }
}

if (!$exactMatch && $model) {
    $st = $db->prepare("SELECT complectation, model FROM to_matrices WHERE model = ? ORDER BY complectation");
    $st->execute([$model]);
    $matrixMatches = $st->fetchAll(PDO::FETCH_ASSOC);
}

// Извлекаем полезные поля из карточки авто
$carInfo = [
    'VINShassis'       => $car['VINShassis']    ?? null,
    'VINTS'            => $car['VINTS']         ?? null,
    'ShassisModel'     => $car['ShassisModel']  ?? null,
    'CarModel'         => $car['CarModel']      ?? null,
    'NumberShassis'    => $car['NumberShassis'] ?? null,
    'NumberEngine'     => $car['NumberEngine']  ?? null,
    'EngineModel'      => $car['EngineModel']   ?? null,
    'ProductionDate'   => $car['ProductionDate'] ?? null,
];

echo json_encode([
    'ok'              => true,
    'found_by'        => $found['found_by'],
    'complectation'   => $complectation,
    'model'           => $model,
    'exact_match'     => $exactMatch,
    'matrix_matches'  => $matrixMatches,
    'car'             => $carInfo,
], JSON_UNESCAPED_UNICODE);
