<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}

$error = '';
$car = null;
$searchValue = '';
$searchMethod = 'NumberChassis';

$METHODS = [
    'NumberChassis' => 'Номер шасси',
    'VINShassis'    => 'VIN шасси',
    'VINTS'         => 'VIN ТС',
    'NumberEngine'  => 'Номер двигателя',
];

if (!empty($_GET['number'])) {
    $searchValue = trim($_GET['number']);
    $searchMethod = $_GET['method'] ?? 'NumberChassis';

    if (!isset($METHODS[$searchMethod])) {
        $error = 'Неверный метод поиска';
    } elseif ($searchValue === '') {
        $error = 'Введите значение';
    } else {
        $login = getenv('ONEC_LOGIN');
        $password = getenv('ONEC_PASSWORD');

        if (!$login || !$password) {
            $error = 'Не настроены ONEC_LOGIN и ONEC_PASSWORD';
        } else {
            $url = 'https://web-1c.kamaz.ru/GOA/hs/CarData/V1/' . $searchMethod
                 . '?Number=' . urlencode($searchValue);

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
                $error = 'Ошибка соединения с 1С: ' . $curlError;
            } elseif ($httpCode === 401) {
                $error = 'Неверный логин или пароль от 1С';
            } elseif ($httpCode === 403) {
                $error = 'Нет прав у пользователя 1С на этот сервис';
            } elseif ($httpCode !== 200) {
                $error = '1С вернул код ' . $httpCode . ': ' . substr($response, 0, 200);
            } else {
                $data = json_decode($response, true);
                if (!isset($data['Car'])) {
                    $error = 'Автотехника не найдена';
                } else {
                    $car = $data['Car'];
                    $car['_other'] = $data;
                    unset($car['_other']['Car']);
                }
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Поиск по VIN — 1С:ГОА</title>
<style>
  * { box-sizing: border-box; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; margin: 0; padding: 16px; color: #1a1a1a; line-height: 1.5; }
  .container { max-width: 900px; margin: 0 auto; }
  .card { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 16px; }
  h1 { font-size: 22px; margin: 0 0 8px; }
  h2 { font-size: 17px; margin: 0 0 14px; color: #1e3a8a; }
  .top-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
  .user-info { font-size: 13px; color: #666; }
  .user-info b { color: #2563eb; }
  .nav-link { text-decoration: none; font-size: 13px; margin-left: 12px; }
  .logout { color: #dc2626; text-decoration: none; font-size: 13px; margin-left: 12px; }
  .btn { display: inline-block; padding: 12px 18px; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; background: #2563eb; color: #fff; text-decoration: none; text-align: center; transition: opacity 0.2s; }
  .btn:hover { opacity: 0.9; }
  .btn-secondary { background: #fff; color: #2563eb; border: 1.5px solid #2563eb; }
  .btn-green { background: #16a34a; }
  .btn-small { padding: 8px 14px; font-size: 14px; }
  .btn-row { display: flex; gap: 10px; flex-wrap: wrap; margin-top: 12px; }
  .search-form { display: flex; gap: 10px; flex-wrap: wrap; align-items: flex-end; }
  .search-form select, .search-form input { padding: 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 15px; }
  .search-form select { min-width: 180px; }
  .search-form input { flex: 1; min-width: 200px; }
  .search-form select:focus, .search-form input:focus { outline: none; border-color: #2563eb; }

  .row { display: flex; justify-content: space-between; padding: 8px 0; border-bottom: 1px solid #f0f0f0; font-size: 14px; gap: 12px; }
  .row:last-child { border-bottom: none; }
  .row .label { color: #666; flex-shrink: 0; min-width: 200px; }
  .row .value { color: #1a1a1a; font-weight: 500; text-align: right; word-break: break-word; }
  .row .value.bad { color: #dc2626; }
  .row .value.good { color: #16a34a; }

  .section { margin-top: 16px; padding: 14px; background: #f9fafb; border-radius: 10px; border-left: 4px solid #2563eb; }
  .section h3 { margin: 0 0 10px; font-size: 15px; color: #1e3a8a; }

  .badge { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }
  .badge-green { background: #f0fdf4; color: #16a34a; }
  .badge-red { background: #fef2f2; color: #dc2626; }
  .badge-blue { background: #eff6ff; color: #2563eb; }
  .badge-yellow { background: #fffbeb; color: #b45309; }

  .alert { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
  .alert-error { background: #fef2f2; color: #dc2626; border-left: 4px solid #dc2626; }
  .alert-info { background: #eff6ff; color: #2563eb; border-left: 4px solid #2563eb; }
</style>
</head>
<body>
<div class="container">

  <div class="card">
    <div class="top-bar">
      <h1>🔍 Поиск по VIN — 1С:ГОА</h1>
      <div>
        <span class="user-info">👤 <b><?= e($user['name'] ?: $user['email']) ?></b></span>
        <a href="gallery.php" class="nav-link" style="color:#2563eb;">📸 Галерея</a>
        <a href="profile.php" class="nav-link" style="color:#2563eb;">Профиль</a>
        <?php if ($user['is_admin']): ?>
          <a href="admin.php" class="nav-link" style="color:#dc2626;">🛡️ Админ</a>
        <?php endif; ?>
        <a href="logout.php" class="logout">Выйти</a>
      </div>
    </div>
    <div class="btn-row">
      <a href="index.html" class="btn btn-secondary btn-small">← На рабочее место</a>
    </div>
  </div>

  <div class="card">
    <h2>Найти автотехнику</h2>
    <form method="get" class="search-form">
      <select name="method">
        <?php foreach ($METHODS as $m => $name): ?>
          <option value="<?= e($m) ?>" <?= $searchMethod === $m ? 'selected' : '' ?>><?= e($name) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="number" value="<?= e($searchValue) ?>" placeholder="Например: 1433081" required>
      <button type="submit" class="btn">Найти</button>
    </form>
  </div>

  <?php if ($error): ?>
    <div class="alert alert-error">❌ <?= e($error) ?></div>
  <?php endif; ?>

  <?php if ($car): ?>
    <?php
      $inWarranty = !empty($car['InWarranty']);
      $warrantyClass = $inWarranty ? 'good' : 'bad';
    ?>

    <div class="card">
      <h2>📋 Карточка автотехники</h2>

      <div class="section">
        <h3>Основные данные</h3>
        <div class="row"><span class="label">Наименование</span><span class="value"><?= e($car['Name'] ?? '—') ?></span></div>
        <div class="row"><span class="label">Модель автотехники</span><span class="value"><?= e($car['CarModel'] ?? '—') ?></span></div>
        <div class="row"><span class="label">Модель шасси</span><span class="value"><?= e($car['ShassisModel'] ?? '—') ?></span></div>
        <div class="row"><span class="label">Номер шасси</span><span class="value"><?= e($car['NumberShassis'] ?? '—') ?></span></div>
        <div class="row"><span class="label">VIN шасси</span><span class="value"><?= e($car['VINShassis'] ?? '—') ?></span></div>
        <div class="row"><span class="label">VIN ТС</span><span class="value"><?= e($car['VINTS'] ?? '—') ?></span></div>
        <div class="row"><span class="label">Двигатель</span><span class="value"><?= e($car['EngineModel'] ?? '—') ?> / <?= e($car['NumberEngine'] ?? '—') ?></span></div>
        <div class="row"><span class="label">Номер кабины</span><span class="value"><?= e($car['CabNumber'] ?? '—') ?></span></div>
        <div class="row"><span class="label">Дата изготовления</span><span class="value"><?= e($car['ProductionDate'] ?? '—') ?></span></div>
        <div class="row"><span class="label">Признак шасси</span><span class="value"><?= e($car['ChassisAttribute'] ?? '—') ?></span></div>
        <div class="row"><span class="label">Комплектация</span><span class="value"><?= e($car['CompleteSetOfVehicles'] ?? '—') ?></span></div>
        <div class="row"><span class="label">Конструкторский код</span><span class="value"><?= e($car['TheDesignCodeOfTheConfiguration'] ?? '—') ?></span></div>
        <div class="row"><span class="label">Глобальный код</span><span class="value"><?= e($car['GlobalCode'] ?? '—') ?></span></div>
        <div class="row"><span class="label">В перегоне</span><span class="value"><?= !empty($car['Peregon']) ? 'Да' : 'Нет' ?></span></div>
      </div>

      <div class="section">
        <h3>Гарантия</h3>
        <div class="row"><span class="label">Статус</span><span class="value <?= $warrantyClass ?>"><?= e($car['WarrantyIndication'] ?? '—') ?></span></div>
        <div class="row"><span class="label">Дата начала</span><span class="value"><?= e($car['WarrantyStartDate'] ?? '—') ?></span></div>
        <div class="row"><span class="label">Дата окончания</span><span class="value"><?= e($car['WarrantyExpirationDate'] ?? '—') ?></span></div>
        <div class="row"><span class="label">Пробег окончания</span><span class="value"><?= number_format((int)($car['EndGuaranteeMileage'] ?? 0), 0, '.', ' ') ?> км</span></div>
        <div class="row"><span class="label">Наработка окончания</span><span class="value"><?= (int)($car['EndGuaranteeOperatingTime'] ?? 0) ?> м/час</span></div>
      </div>

      <?php if (!empty($car['_other']['GuaranteesForNodes'])): ?>
        <div class="section">
          <h3>Гарантия на узлы</h3>
          <?php foreach ($car['_other']['GuaranteesForNodes'] as $g): ?>
            <div class="row"><span class="label"><?= e($g['NameDefectiveNode'] ?? '—') ?></span><span class="value">до <?= e($g['ExtensionPeriod'] ?? '—') ?>, пробег <?= number_format((int)($g['EndGuaranteeMileage'] ?? 0), 0, '.', ' ') ?> км</span></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($car['_other']['SservicePackage'])): ?>
        <div class="section">
          <h3>Пакеты сервисных услуг</h3>
          <?php foreach ($car['_other']['SservicePackage'] as $p): ?>
            <div class="row"><span class="label"><?= e($p['Name'] ?? '—') ?></span><span class="value"><span class="badge badge-blue"><?= e($p['TypeServicePackage'] ?? '—') ?></span> <?= e($p['KindServicePackage'] ?? '') ?></span></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($car['_other']['ServiceContract'])): ?>
        <div class="section">
          <h3>Сервисные контракты</h3>
          <?php foreach ($car['_other']['ServiceContract'] as $c): ?>
            <div class="row"><span class="label"><?= e($c['Name'] ?? '—') ?></span><span class="value"><?= e($c['StartDateServiceContract'] ?? '') ?> → <?= e($c['EndDateServiceContract'] ?? '') ?></span></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($car['_other']['Actions'])): ?>
        <div class="section">
          <h3>Акции</h3>
          <?php foreach ($car['_other']['Actions'] as $a): ?>
            <div class="row"><span class="label"><?= e($a['Name'] ?? '—') ?></span><span class="value"><?= e($a['TypeAction'] ?? '') ?> / <?= e($a['KindAction'] ?? '') ?></span></div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <?php if (!empty($car['_other']['ServiceCampaigns'])): ?>
        <div class="section" style="border-left-color: #f59e0b;">
          <h3>🛠️ Сервисные кампании (ОТМ)</h3>
          <?php foreach ($car['_other']['ServiceCampaigns'] as $s): ?>
            <div style="padding: 10px 0; border-bottom: 1px solid #e5e7eb;">
              <div style="font-weight: 600; color: #b45309;"><?= e($s['Name'] ?? '—') ?></div>
              <div style="font-size: 13px; color: #666; margin-top: 4px;">
                <span class="badge <?= ($s['Type'] ?? '') === 'R' ? 'badge-red' : 'badge-yellow' ?>"><?= e($s['Category'] ?? '—') ?></span>
                · до <?= e($s['EndDateCampaign'] ?? '—') ?>
                · выполнено: <?= (int)($s['Сompleted'] ?? 0) ?>
              </div>
              <?php if (!empty($s['CauseFault'])): ?>
                <div style="font-size: 13px; margin-top: 6px; color: #444;"><b>Причина:</b> <?= e(mb_substr($s['CauseFault'], 0, 300)) ?></div>
              <?php endif; ?>
              <?php if (!empty($s['DefectiveDetail']['Name'])): ?>
                <div style="font-size: 13px; margin-top: 4px; color: #444;"><b>Деталь:</b> <?= e($s['DefectiveDetail']['Name']) ?></div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>
        </div>
      <?php endif; ?>

      <div class="btn-row">
        <a href="gallery.php?key_type=vin&key_value=<?= urlencode($car['VINShassis'] ?? $car['NumberShassis'] ?? $searchValue) ?>" class="btn btn-green btn-small">📷 Фото по этому VIN</a>
      </div>
    </div>

    <div class="card">
      <h2>Полный ответ 1С (для отладки)</h2>
      <details>
        <summary style="cursor:pointer;color:#2563eb;font-size:14px;">Показать JSON</summary>
        <pre style="font-size:11px; background:#f9fafb; padding:12px; border-radius:8px; overflow:auto; max-height:400px; margin-top:12px;"><?= e(json_encode($car, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
      </details>
    </div>
  <?php endif; ?>

</div>
</body>
</html>
