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

function v($val) {
    return htmlspecialchars((string)($val ?? ''), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
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
  body {
    font-family: Arial, Tahoma, sans-serif;
    background: #eaeaea;
    margin: 0;
    padding: 10px;
    color: #000;
    font-size: 13px;
    line-height: 1.35;
  }
  .container { max-width: 1200px; margin: 0 auto; }

  /* Верхняя панель */
  .top-panel {
    background: linear-gradient(to bottom, #f8d878, #f0c048);
    border: 1px solid #b89020;
    border-radius: 4px;
    padding: 8px 12px;
    margin-bottom: 10px;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
  }
  .top-panel .title {
    font-size: 16px;
    font-weight: bold;
    color: #4a3a00;
  }
  .top-panel .actions a {
    text-decoration: none;
    color: #000080;
    font-size: 12px;
    margin-left: 12px;
  }
  .top-panel .actions a:hover { text-decoration: underline; }

  /* Панель поиска */
  .search-card {
    background: #fff;
    border: 1px solid #b0b0b0;
    border-radius: 3px;
    padding: 10px 12px;
    margin-bottom: 10px;
  }
  .search-form { display: flex; gap: 8px; flex-wrap: wrap; align-items: center; }
  .search-form select, .search-form input {
    padding: 5px 8px;
    border: 1px solid #a0a0a0;
    border-radius: 2px;
    font-size: 13px;
    font-family: inherit;
  }
  .search-form input { flex: 1; min-width: 200px; }
  .search-form select { min-width: 160px; }
  .search-form button {
    padding: 5px 16px;
    border: 1px solid #b89020;
    background: linear-gradient(to bottom, #f8d878, #f0c048);
    border-radius: 2px;
    font-weight: bold;
    cursor: pointer;
    font-size: 13px;
  }
  .search-form button:hover { background: linear-gradient(to bottom, #f0c048, #e8b030); }

  /* Окно карточки */
  .doc-window {
    background: #f5f5f5;
    border: 1px solid #808080;
    border-radius: 3px;
    overflow: hidden;
  }
  .doc-header {
    background: #fff;
    padding: 6px 10px;
    border-bottom: 1px solid #d0d0d0;
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 8px;
  }
  .doc-header .doc-title {
    font-size: 14px;
    font-weight: bold;
    color: #000080;
  }
  .doc-header .doc-nav a {
    text-decoration: none;
    color: #000080;
    font-size: 12px;
    margin-left: 10px;
  }
  .doc-header .doc-nav a:hover { text-decoration: underline; }

  /* Заголовок ОТМ */
  .otm-banner {
    background: #fff;
    padding: 8px;
    text-align: center;
    border-bottom: 1px solid #d0d0d0;
  }
  .otm-banner .otm-title {
    color: #000080;
    font-size: 13px;
    margin-bottom: 6px;
  }
  .otm-buttons { display: flex; justify-content: center; gap: 10px; flex-wrap: wrap; }
  .otm-buttons .otm-btn {
    padding: 4px 20px;
    border: 1px solid #a0a0a0;
    background: #fff;
    border-radius: 2px;
    font-size: 12px;
    font-weight: bold;
  }
  .otm-buttons .otm-btn.green { color: #008000; }
  .otm-buttons .otm-btn.red { color: #cc0000; }

  /* Вкладки */
  .tabs {
    display: flex;
    background: #e8e8e8;
    border-bottom: 1px solid #a0a0a0;
    overflow-x: auto;
    white-space: nowrap;
  }
  .tab {
    padding: 6px 14px;
    border: 1px solid #a0a0a0;
    border-bottom: none;
    background: #d8d8d8;
    margin-right: -1px;
    margin-top: 4px;
    cursor: pointer;
    font-size: 13px;
    color: #000;
    border-radius: 3px 3px 0 0;
    flex-shrink: 0;
  }
  .tab:hover { background: #e8e8e8; }
  .tab.active {
    background: #fff;
    margin-top: 0;
    padding-top: 10px;
    font-weight: bold;
    position: relative;
    z-index: 1;
  }

  /* Содержимое вкладок */
  .tab-content { display: none; background: #fff; padding: 12px; }
  .tab-content.active { display: block; }

  /* Поля-строки (как в 1С) */
  .field-row {
    display: flex;
    align-items: center;
    padding: 3px 0;
    border-bottom: 1px solid #eee;
    gap: 10px;
    flex-wrap: wrap;
  }
  .field-label {
    color: #333;
    font-size: 12px;
    min-width: 180px;
    flex-shrink: 0;
  }
  .field-value {
    flex: 1;
    background: #fff;
    border: 1px solid #d0d0d0;
    padding: 3px 8px;
    font-size: 13px;
    min-height: 22px;
    border-radius: 2px;
    min-width: 150px;
  }
  .field-value.empty { background: #fff; color: #999; }

  .field-group { display: flex; gap: 10px; flex-wrap: wrap; }
  .field-group .field-row { flex: 1; min-width: 280px; border-bottom: none; }

  /* Гарантия */
  .warranty-status {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 12px;
    padding: 8px 0;
    border-bottom: 1px solid #d0d0d0;
  }
  .warranty-status .status-left {
    color: #008000;
    font-size: 20px;
    font-weight: bold;
    font-style: italic;
  }
  .warranty-status .status-right {
    color: #008000;
    font-size: 20px;
    font-weight: bold;
    font-style: italic;
  }
  .warranty-status.not-warranty .status-left { color: #cc0000; }

  .warranty-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 10px;
    margin-bottom: 16px;
  }
  .warranty-grid .field-row { flex-direction: column; align-items: flex-start; border-bottom: none; }
  .warranty-grid .field-label { font-weight: bold; color: #333; }
  .warranty-grid .field-value { color: #008000; font-weight: bold; width: 100%; border: none; background: transparent; padding: 0; }

  .contract-row {
    display: flex;
    gap: 10px;
    margin-bottom: 12px;
    flex-wrap: wrap;
  }
  .contract-row .field-row { flex: 1; min-width: 250px; border-bottom: none; }

  /* Таблицы */
  .doc-table {
    width: 100%;
    border-collapse: collapse;
    margin-top: 10px;
    font-size: 12px;
  }
  .doc-table th {
    background: #f0f0f0;
    border: 1px solid #a0a0a0;
    padding: 4px 6px;
    text-align: left;
    font-weight: normal;
    color: #000;
  }
  .doc-table td {
    border: 1px solid #d0d0d0;
    padding: 3px 6px;
    vertical-align: top;
  }
  .doc-table tr.selected td { background: #d8e8f8; }

  .section-title {
    font-size: 13px;
    color: #000080;
    margin: 14px 0 6px;
    padding-bottom: 3px;
    border-bottom: 1px solid #d0d0d0;
  }

  /* ОТМ таблица */
  .otm-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 12px;
    margin-top: 10px;
  }
  .otm-table th {
    background: #f0f0f0;
    border: 1px solid #a0a0a0;
    padding: 4px 6px;
    text-align: left;
    font-weight: normal;
    white-space: nowrap;
  }
  .otm-table td {
    border: 1px solid #d0d0d0;
    padding: 3px 6px;
    vertical-align: top;
  }
  .otm-table .otm-cat-S { color: #000080; }
  .otm-table .otm-cat-R { color: #cc0000; font-weight: bold; }
  .otm-table .icon-link { color: #000080; text-decoration: underline; cursor: pointer; font-size: 11px; }

  /* Алерты */
  .alert-error {
    background: #fff0f0;
    color: #cc0000;
    border: 1px solid #ffb0b0;
    padding: 8px 12px;
    border-radius: 3px;
    font-size: 13px;
    margin-bottom: 10px;
  }

  /* Скрыть JSON */
  .json-toggle {
    font-size: 12px;
    color: #000080;
    cursor: pointer;
    text-decoration: underline;
    margin-top: 14px;
    display: inline-block;
  }
  .json-pre {
    display: none;
    background: #f8f8f8;
    border: 1px solid #d0d0d0;
    padding: 8px;
    border-radius: 3px;
    font-size: 11px;
    font-family: Consolas, monospace;
    max-height: 400px;
    overflow: auto;
    margin-top: 8px;
    white-space: pre-wrap;
  }

  @media (max-width: 700px) {
    .field-label { min-width: 100%; }
    .warranty-status { flex-direction: column; align-items: flex-start; gap: 6px; }
  }
</style>
</head>
<body>
<div class="container">

  <!-- Верхняя панель -->
  <div class="top-panel">
    <div class="title">🔍 Поиск автотехники — 1С:ГОА</div>
    <div class="actions">
      <a href="gallery.php">📸 Галерея</a>
      <a href="profile.php">👤 Профиль</a>
      <?php if ($user['is_admin']): ?>
        <a href="admin.php">🛡️ Админ</a>
      <?php endif; ?>
      <a href="index.html">🏠 Рабочее место</a>
      <a href="logout.php" style="color:#cc0000;">Выйти</a>
    </div>
  </div>

  <!-- Панель поиска -->
  <div class="search-card">
    <form method="get" class="search-form">
      <select name="method">
        <?php foreach ($METHODS as $m => $name): ?>
          <option value="<?= v($m) ?>" <?= $searchMethod === $m ? 'selected' : '' ?>><?= v($name) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="number" value="<?= v($searchValue) ?>" placeholder="Например: 1433081" required>
      <button type="submit">Найти</button>
    </form>
  </div>

  <?php if ($error): ?>
    <div class="alert-error">❌ <?= v($error) ?></div>
  <?php endif; ?>

  <?php if ($car): ?>
    <?php
      $inWarranty = !empty($car['InWarranty']);
      $docTitle = 'КАМАЗ ' . ($car['ShassisModel'] ?? '')
                . ' VIN ш.: ' . ($car['VINShassis'] ?? '')
                . ' дв: ' . ($car['NumberEngine'] ?? '')
                . ' (Автотехника)';
    ?>

    <div class="doc-window">

      <!-- Заголовок документа -->
      <div class="doc-header">
        <div class="doc-title"><?= v($docTitle) ?></div>
        <div class="doc-nav">
          <a href="#" onclick="return false;">Записать и закрыть</a>
          <a href="#" onclick="return false;">Обновить</a>
          <a href="#" onclick="return false;">Печать</a>
          <a href="#" onclick="return false;">Все действия</a>
        </div>
      </div>

      <!-- Баннер ОТМ -->
      <div class="otm-banner">
        <div class="otm-title">Организационно-технические мероприятия</div>
        <div class="otm-buttons">
          <div class="otm-btn">Вып. <?= count($car['_other']['ServiceCampaigns'] ?? []) ?></div>
          <div class="otm-btn red">Не вып. 0</div>
          <div class="otm-btn green">Дефект отсут. 0</div>
        </div>
      </div>

      <!-- Вкладки -->
      <div class="tabs">
        <div class="tab active" data-tab="main">Основные сведения</div>
        <div class="tab" data-tab="warranty">Гарантия</div>
        <div class="tab" data-tab="otm">Информация по ОТМ и Акциям</div>
      </div>

      <!-- ===== ВКЛАДКА 1: Основные сведения ===== -->
      <div class="tab-content active" id="tab-main">

        <div class="field-row">
          <div class="field-label">Код</div>
          <div class="field-value"><?= v($car['Code'] ?? '—') ?></div>
        </div>
        <div class="field-row">
          <div class="field-label">Наименование</div>
          <div class="field-value"><?= v($car['Name'] ?? '—') ?></div>
        </div>

        <div class="section-title">Основные сведения</div>

        <div class="field-row">
          <div class="field-label">VIN ТС</div>
          <div class="field-value"><?= v($car['VINTS'] ?? '') ?></div>
        </div>
        <div class="field-row">
          <div class="field-label">Модель автотехники</div>
          <div class="field-value"><?= v($car['CarModel'] ?? '') ?></div>
        </div>
        <div class="field-row">
          <div class="field-label">VIN Шасси</div>
          <div class="field-value"><?= v($car['VINShassis'] ?? '') ?></div>
        </div>
        <div class="field-row">
          <div class="field-label">Модель шасси</div>
          <div class="field-value"><?= v($car['ShassisModel'] ?? '') ?></div>
        </div>

        <div class="field-group">
          <div class="field-row">
            <div class="field-label">Номер двигателя</div>
            <div class="field-value"><?= v($car['NumberEngine'] ?? '') ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Модель двигателя</div>
            <div class="field-value"><?= v($car['EngineModel'] ?? '') ?></div>
          </div>
        </div>

        <div class="field-group">
          <div class="field-row">
            <div class="field-label">Номер кабины</div>
            <div class="field-value"><?= v($car['CabNumber'] ?? '') ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Номер шасси</div>
            <div class="field-value"><?= v($car['NumberShassis'] ?? '') ?></div>
          </div>
        </div>

        <div class="field-group">
          <div class="field-row">
            <div class="field-label">Дата изготовления</div>
            <div class="field-value"><?= v($car['ProductionDate'] ?? '') ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Признак шасси</div>
            <div class="field-value"><?= v($car['ChassisAttribute'] ?? '') ?></div>
          </div>
        </div>

        <div class="field-row">
          <div class="field-label">Комплектация автотехники</div>
          <div class="field-value"><?= v($car['CompleteSetOfVehicles'] ?? '') ?></div>
        </div>
        <div class="field-row">
          <div class="field-label">Глобальный код</div>
          <div class="field-value"><?= v($car['GlobalCode'] ?? '') ?></div>
        </div>
        <div class="field-row">
          <div class="field-label">Конструкторский код комплектации</div>
          <div class="field-value"><?= v($car['TheDesignCodeOfTheConfiguration'] ?? '') ?></div>
        </div>

        <div class="field-row">
          <div class="field-label">Краткое описание</div>
          <div class="field-value"><?= v($car['ShortDescription'] ?? '') ?></div>
        </div>

        <div class="field-group">
          <div class="field-row">
            <div class="field-label">В перегоне</div>
            <div class="field-value"><?= !empty($car['Peregon']) ? 'Да' : 'Нет' ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Признак гарантии</div>
            <div class="field-value"><?= v($car['WarrantyIndication'] ?? '') ?></div>
          </div>
        </div>

      </div>

      <!-- ===== ВКЛАДКА 2: Гарантия ===== -->
      <div class="tab-content" id="tab-warranty">

        <div class="warranty-status <?= !$inWarranty ? 'not-warranty' : '' ?>">
          <div class="status-left"><?= $inWarranty ? 'В ГАРАНТИИ' : 'НЕ В ГАРАНТИИ' ?></div>
          <div class="status-right"><?= !empty($car['_other']['GuaranteesForNodes']) ? 'ГАРАНТИЯ НА УЗЛЫ' : '' ?></div>
        </div>

        <div class="warranty-grid">
          <div class="field-row">
            <div class="field-label">Дата окончания Производственной / Товарной гарантии</div>
            <div class="field-value"><?= v($car['WarrantyExpirationDate'] ?? '') ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Дата начала гарантии</div>
            <div class="field-value"><?= v($car['WarrantyStartDate'] ?? '') ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Дата окончания гарантии</div>
            <div class="field-value"><?= v($car['WarrantyExpirationDate'] ?? '') ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Пробег окончания гарантии</div>
            <div class="field-value"><?= number_format((int)($car['EndGuaranteeMileage'] ?? 0), 0, '.', ' ') ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Наработка окончания гарантии (м/час)</div>
            <div class="field-value"><?= (int)($car['EndGuaranteeOperatingTime'] ?? 0) ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Признак гарантии</div>
            <div class="field-value"><?= v($car['WarrantyIndication'] ?? '') ?></div>
          </div>
        </div>

        <div class="contract-row">
          <div class="field-row">
            <div class="field-label">Владелец контракта / договора</div>
            <div class="field-value"><?= v($car['Executor']['Name'] ?? '') ?></div>
          </div>
        </div>

        <!-- Гарантия на автомобиль -->
        <div class="section-title">Гарантия на автомобиль</div>
        <table class="doc-table">
          <thead>
            <tr>
              <th>Период</th>
              <th>Документ</th>
              <th>Дата начала гарантии</th>
              <th>Дата окончания гарантии</th>
              <th>Пробег окончания гарантии</th>
              <th>Наработка окончания гарантии (м/час)</th>
            </tr>
          </thead>
          <tbody>
            <tr class="selected">
              <td><?= v($car['WarrantyStartDate'] ?? '—') ?></td>
              <td>Реализация потребителю</td>
              <td><?= v($car['WarrantyStartDate'] ?? '') ?></td>
              <td><?= v($car['WarrantyExpirationDate'] ?? '') ?></td>
              <td><?= number_format((int)($car['EndGuaranteeMileage'] ?? 0), 0, '.', ' ') ?></td>
              <td><?= (int)($car['EndGuaranteeOperatingTime'] ?? 0) ?></td>
            </tr>
          </tbody>
        </table>

        <!-- Гарантия на узлы -->
        <?php if (!empty($car['_other']['GuaranteesForNodes'])): ?>
          <div class="section-title">Гарантия на узлы</div>
          <table class="doc-table">
            <thead>
              <tr>
                <th>Период</th>
                <th>Документ</th>
                <th>Наименование</th>
                <th>Обозначение дефектного</th>
                <th>Дата окончания гарантии</th>
                <th>Пробег окончания гарантии</th>
                <th>Наработка окончания гарантии (м/час)</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($car['_other']['GuaranteesForNodes'] as $g): ?>
                <tr class="selected">
                  <td><?= v($g['Date'] ?? '—') ?></td>
                  <td><?= v($g['Number'] ?? '—') ?></td>
                  <td><?= v($g['Name'] ?? '—') ?></td>
                  <td><?= v($g['NameDefectiveNode'] ?? '') ?></td>
                  <td><?= v($g['ExtensionPeriod'] ?? '') ?></td>
                  <td><?= number_format((int)($g['EndGuaranteeMileage'] ?? 0), 0, '.', ' ') ?></td>
                  <td><?= (int)($g['EndGuaranteeOperatingTime'] ?? 0) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

      </div>

      <!-- ===== ВКЛАДКА 3: ОТМ и Акции ===== -->
      <div class="tab-content" id="tab-otm">

        <?php if (!empty($car['_other']['ServiceCampaigns'])): ?>

          <table class="otm-table">
            <thead>
              <tr>
                <th>Вид</th>
                <th>Дата начала акции</th>
                <th>Сро.</th>
                <th>Статус</th>
                <th>Срок выполнения</th>
                <th>Дата окончания О.</th>
                <th>Участвует</th>
                <th>Выполнено</th>
                <th>Отметка о выполнении</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($car['_other']['ServiceCampaigns'] as $s): ?>
                <tr>
                  <td class="<?= ($s['Type'] ?? '') === 'R' ? 'otm-cat-R' : 'otm-cat-S' ?>">
                    <?= v($s['Name'] ?? '—') ?>
                  </td>
                  <td><?= v($s['StartDateCampaign'] ?? '') ?></td>
                  <td></td>
                  <td><?= v($s['Type'] ?? '') ?></td>
                  <td><?= v($s['EndDateCampaign'] ?? '') ?></td>
                  <td><?= v($s['EndDateCampaign'] ?? '') ?></td>
                  <td>1</td>
                  <td><?= (int)($s['Сompleted'] ?? 0) ?></td>
                  <td><span class="icon-link">Открыть осн.</span></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>

          <div class="section-title" style="margin-top:20px;">Детали</div>
          <?php foreach ($car['_other']['ServiceCampaigns'] as $s): ?>
            <div style="padding:10px 0; border-bottom:1px solid #e5e7eb;">
              <div style="font-weight:bold; color:#000080; margin-bottom:6px;"><?= v($s['Name'] ?? '—') ?></div>
              <div class="field-row">
                <div class="field-label">Категория</div>
                <div class="field-value"><?= v($s['Category'] ?? '') ?></div>
              </div>
              <div class="field-row">
                <div class="field-label">Информационное письмо</div>
                <div class="field-value"><?= v($s['NameInformationMail'] ?? '') ?></div>
              </div>
              <div class="field-row">
                <div class="field-label">Номер кампании</div>
                <div class="field-value"><?= v($s['NumberCampaign'] ?? '') ?></div>
              </div>
              <?php if (!empty($s['NameDefect'])): ?>
                <div class="field-row">
                  <div class="field-label">Наименование дефекта</div>
                  <div class="field-value"><?= v($s['NameDefect']) ?></div>
                </div>
              <?php endif; ?>
              <?php if (!empty($s['DefectiveDetail']['Name'])): ?>
                <div class="field-row">
                  <div class="field-label">Деталь виновника</div>
                  <div class="field-value"><?= v($s['DefectiveDetail']['Name']) ?></div>
                </div>
              <?php endif; ?>
              <?php if (!empty($s['CauseFault'])): ?>
                <div class="field-row">
                  <div class="field-label">Причина возникновения</div>
                  <div class="field-value"><?= v($s['CauseFault']) ?></div>
                </div>
              <?php endif; ?>
              <?php if (!empty($s['FaultDescription'])): ?>
                <div class="field-row">
                  <div class="field-label">Описание неисправности</div>
                  <div class="field-value"><?= v($s['FaultDescription']) ?></div>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

        <?php else: ?>
          <div style="padding:20px; text-align:center; color:#888;">Нет активных ОТМ по этой автотехнике</div>
        <?php endif; ?>

      </div>

    </div>

    <!-- Ссылка на галерею по VIN -->
    <div style="margin-top:14px;">
      <a href="gallery.php?key_type=vin&key_value=<?= urlencode($car['VINShassis'] ?? $car['NumberShassis'] ?? $searchValue) ?>"
         style="display:inline-block; padding:8px 16px; background:#16a34a; color:#fff; text-decoration:none; border-radius:3px; font-weight:bold;">
        📷 Фото по этому VIN
      </a>
    </div>

    <!-- JSON для отладки -->
    <span class="json-toggle" onclick="toggleJson()">Показать полный ответ 1С (JSON)</span>
    <pre class="json-pre" id="jsonBox"><?= v(json_encode($car, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>

  <?php endif; ?>

</div>

<script>
// Вкладки
document.querySelectorAll('.tab').forEach(function(tab) {
  tab.addEventListener('click', function() {
    const name = tab.dataset.tab;
    document.querySelectorAll('.tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.tab-content').forEach(function(c) { c.classList.remove('active'); });
    tab.classList.add('active');
    document.getElementById('tab-' + name).classList.add('active');
  });
});

// JSON
function toggleJson() {
  const el = document.getElementById('jsonBox');
  if (!el) return;
  el.style.display = (el.style.display === 'block') ? 'none' : 'block';
}
</script>
</body>
</html>
