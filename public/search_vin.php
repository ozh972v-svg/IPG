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

// Формат даты: 2026-12-30 -> 30.12.2026
function fmtDate($d) {
    if (!$d || $d === '—') return '—';
    $d = trim($d);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) {
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }
    return $d;
}

// Проверить, активна ли дата (>= сегодня)
function isActive($d) {
    if (!$d) return false;
    $ts = strtotime($d);
    if ($ts === false) return false;
    return $ts >= strtotime(date('Y-m-d'));
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Поиск по VIN — 1С:ГОА</title>
<style>
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; margin: 0; padding: 16px; color: #1a1a1a; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; }
  .card { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 16px; }
  h1 { font-size: 22px; margin: 0 0 8px; }
  h2 { font-size: 18px; margin: 0 0 16px; }
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
  .btn-row { display: flex; gap: 10px; flex-wrap: wrap; }
  .search-bar { display: flex; gap: 8px; flex-wrap: wrap; }
  .search-bar input, .search-bar select { padding: 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 15px; font-family: inherit; }
  .search-bar input { flex: 1; min-width: 200px; }
  .search-bar input:focus, .search-bar select:focus { outline: none; border-color: #2563eb; }

  .tabs { display: flex; gap: 4px; border-bottom: 2px solid #e5e7eb; margin-bottom: 16px; flex-wrap: wrap; }
  .tab {
    padding: 10px 16px; border: none; background: transparent;
    font-size: 14px; font-weight: 600; color: #666;
    cursor: pointer; border-bottom: 3px solid transparent;
    margin-bottom: -2px; border-radius: 6px 6px 0 0;
    font-family: inherit;
  }
  .tab:hover { background: #f9fafb; }
  .tab.active { color: #2563eb; border-bottom-color: #2563eb; }
  .tab-content { display: none; }
  .tab-content.active { display: block; }

  .field-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0f0f0; gap: 12px; font-size: 14px; }
  .field-row:last-child { border-bottom: none; }
  .field-label { color: #666; flex-shrink: 0; min-width: 200px; }
  .field-value { color: #1a1a1a; font-weight: 500; flex: 1; word-break: break-word; }

  .field-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(280px, 1fr)); gap: 0 20px; }

  .section-title {
    font-size: 16px; font-weight: 700; color: #1e3a8a;
    margin: 20px 0 10px; padding-bottom: 6px;
    border-bottom: 2px solid #eff6ff;
  }
  .section-title:first-child { margin-top: 0; }

  /* Баннер гарантии */
  .warranty-banner {
    padding: 14px 20px; border-radius: 12px; text-align: center;
    font-size: 20px; font-weight: 700; margin: 12px 0;
  }
  .warranty-banner.yes { background: #f0fdf4; color: #16a34a; border-left: 4px solid #16a34a; }
  .warranty-banner.no { background: #fef2f2; color: #dc2626; border-left: 4px solid #dc2626; }
  .warranty-banner small {
    display: block; font-size: 13px; font-weight: 500;
    color: #666; margin-top: 6px;
  }
  .warranty-banner .banner-sub {
    font-size: 13px; font-weight: 500; color: #555;
    margin-top: 8px; line-height: 1.6;
  }

  table.doc-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
  table.doc-table th {
    background: #f9fafb; color: #666; font-weight: 600;
    text-align: left; padding: 10px 12px;
    border-bottom: 1px solid #e5e7eb; font-size: 12px;
    text-transform: uppercase; letter-spacing: 0.3px;
  }
  table.doc-table td {
    padding: 10px 12px; border-bottom: 1px solid #f0f0f0;
    vertical-align: top;
  }
  table.doc-table tr:last-child td { border-bottom: none; }
  table.doc-table tr:hover td { background: #fafbff; }

  .otm-card {
    padding: 16px; border: 1.5px solid #e5e7eb; border-radius: 12px;
    margin-bottom: 12px; background: #fff; transition: all 0.15s;
  }
  .otm-card:hover { border-color: #2563eb; }
  .otm-card-header { display: flex; justify-content: space-between; align-items: center; gap: 10px; flex-wrap: wrap; margin-bottom: 10px; }
  .otm-card-title { font-weight: 700; font-size: 15px; color: #1e3a8a; }
  .badge { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; }
  .badge-blue { background: #eff6ff; color: #2563eb; }
  .badge-green { background: #f0fdf4; color: #16a34a; }
  .badge-red { background: #fef2f2; color: #dc2626; }
  .badge-yellow { background: #fffbeb; color: #b45309; }

  .alert { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
  .alert-error { background: #fef2f2; color: #dc2626; border-left: 4px solid #dc2626; }

  .json-toggle { font-size: 13px; color: #2563eb; cursor: pointer; margin-top: 16px; display: inline-block; }
  .json-toggle:hover { text-decoration: underline; }
  .json-pre {
    display: none; background: #f9fafb; border: 1px solid #e5e7eb;
    border-radius: 10px; padding: 14px; font-size: 12px;
    font-family: 'SF Mono', Consolas, monospace; max-height: 400px;
    overflow: auto; margin-top: 10px; white-space: pre-wrap;
  }

  @media (max-width: 600px) {
    .field-label { min-width: 100%; }
    .field-row { flex-direction: column; gap: 4px; }
  }
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
    <form method="get" class="search-bar">
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
      // Статус основной гарантии — считаем по дате окончания
      $mainActive = isActive($car['WarrantyExpirationDate'] ?? null);

      // Гарантии на узлы — только активные
      $nodeWarranties = $car['_other']['GuaranteesForNodes'] ?? [];
      $activeNodes = [];
      foreach ($nodeWarranties as $g) {
          if (isActive($g['ExtensionPeriod'] ?? null)) {
              $activeNodes[] = $g;
          }
      }

      $title = 'КАМАЗ ' . ($car['ShassisModel'] ?? '')
             . ' · VIN ш.: ' . ($car['VINShassis'] ?? '')
             . ' · дв: ' . ($car['NumberEngine'] ?? '');
    ?>

    <div class="card">
      <h1 style="font-size:18px; color:#1e3a8a; margin-bottom:16px;"><?= e($title) ?></h1>

      <div class="tabs">
        <button type="button" class="tab active" data-tab="main">Основные сведения</button>
        <button type="button" class="tab" data-tab="otm">ОТМ и Акции</button>
      </div>

      <!-- ===== ВКЛАДКА 1 ===== -->
      <div class="tab-content active" id="tab-main">

        <div class="section-title">Основные сведения</div>

        <div class="field-grid">
          <div class="field-row">
            <div class="field-label">VIN ТС</div>
            <div class="field-value"><?= e($car['VINTS'] ?? '—') ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Модель автотехники</div>
            <div class="field-value"><?= e($car['CarModel'] ?? '—') ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">VIN шасси</div>
            <div class="field-value"><?= e($car['VINShassis'] ?? '—') ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Модель шасси</div>
            <div class="field-value"><?= e($car['ShassisModel'] ?? '—') ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Номер двигателя</div>
            <div class="field-value"><?= e($car['NumberEngine'] ?? '—') ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Модель двигателя</div>
            <div class="field-value"><?= e($car['EngineModel'] ?? '—') ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Дата изготовления</div>
            <div class="field-value"><?= e(fmtDate($car['ProductionDate'] ?? null)) ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Номер шасси</div>
            <div class="field-value"><?= e($car['NumberShassis'] ?? '—') ?></div>
          </div>
          <div class="field-row">
            <div class="field-label">Конструкторский код комплектации</div>
            <div class="field-value"><?= e($car['TheDesignCodeOfTheConfiguration'] ?? '—') ?></div>
          </div>
        </div>

        <!-- ГАРАНТИЯ -->
        <div class="section-title">Гарантия</div>

        <!-- Баннер основной гарантии -->
        <div class="warranty-banner <?= $mainActive ? 'yes' : 'no' ?>">
          <?= $mainActive ? '✅ В ГАРАНТИИ' : '❌ НЕ В ГАРАНТИИ' ?>
          <div class="banner-sub">
            Основная гарантия:
            с <?= e(fmtDate($car['WarrantyStartDate'] ?? null)) ?>
            по <?= e(fmtDate($car['WarrantyExpirationDate'] ?? null)) ?>
            · пробег <?= number_format((int)($car['EndGuaranteeMileage'] ?? 0), 0, '.', ' ') ?> км
          </div>
        </div>

        <!-- Баннер гарантии на узлы -->
        <?php foreach ($activeNodes as $g): ?>
          <div class="warranty-banner yes">
            ✅ ГАРАНТИЯ НА УЗЛЫ
            <div class="banner-sub">
              <b><?= e($g['Name'] ?? 'Узел') ?></b> (<?= e($g['NameDefectiveNode'] ?? '—') ?>):
              действует до <?= e(fmtDate($g['ExtensionPeriod'] ?? null)) ?>
              · пробег <?= number_format((int)($g['EndGuaranteeMileage'] ?? 0), 0, '.', ' ') ?> км
              · наработка <?= (int)($g['EndGuaranteeOperatingTime'] ?? 0) ?> м/ч
            </div>
          </div>
        <?php endforeach; ?>

        <!-- Таблица всех гарантий на узлы -->
        <?php if (!empty($nodeWarranties)): ?>
          <div class="section-title" style="font-size:14px;">Все гарантии на узлы</div>
          <table class="doc-table">
            <thead>
              <tr>
                <th>Наименование</th>
                <th>Узел</th>
                <th>Дата окончания</th>
                <th>Пробег</th>
                <th>Наработка</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($nodeWarranties as $g): ?>
                <tr>
                  <td><?= e($g['Name'] ?? '—') ?></td>
                  <td><?= e($g['NameDefectiveNode'] ?? '—') ?></td>
                  <td><?= e(fmtDate($g['ExtensionPeriod'] ?? null)) ?></td>
                  <td><?= number_format((int)($g['EndGuaranteeMileage'] ?? 0), 0, '.', ' ') ?> км</td>
                  <td><?= (int)($g['EndGuaranteeOperatingTime'] ?? 0) ?> м/ч</td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

      </div>

      <!-- ===== ВКЛАДКА 2: ОТМ и Акции ===== -->
      <div class="tab-content" id="tab-otm">

        <?php if (!empty($car['_other']['ServiceCampaigns'])): ?>

          <div class="section-title">Сервисные кампании (ОТМ)</div>

          <?php foreach ($car['_other']['ServiceCampaigns'] as $s): ?>
            <div class="otm-card">
              <div class="otm-card-header">
                <div class="otm-card-title"><?= e($s['Name'] ?? '—') ?></div>
                <span class="badge <?= ($s['Type'] ?? '') === 'R' ? 'badge-red' : 'badge-yellow' ?>">
                  <?= e($s['Category'] ?? '') ?>
                </span>
              </div>

              <div class="field-grid" style="margin-top:10px;">
                <div class="field-row">
                  <div class="field-label">Номер кампании</div>
                  <div class="field-value"><?= e($s['NumberCampaign'] ?? '—') ?></div>
                </div>
                <div class="field-row">
                  <div class="field-label">Начало</div>
                  <div class="field-value"><?= e(fmtDate($s['StartDateCampaign'] ?? null)) ?></div>
                </div>
                <div class="field-row">
                  <div class="field-label">Окончание</div>
                  <div class="field-value"><?= e(fmtDate($s['EndDateCampaign'] ?? null)) ?></div>
                </div>
                <div class="field-row">
                  <div class="field-label">Выполнено</div>
                  <div class="field-value"><?= (int)($s['Сompleted'] ?? 0) ?></div>
                </div>
                <?php if (!empty($s['DefectiveDetail']['Name'])): ?>
                  <div class="field-row">
                    <div class="field-label">Деталь-виновник</div>
                    <div class="field-value"><?= e($s['DefectiveDetail']['Name']) ?></div>
                  </div>
                <?php endif; ?>
              </div>

              <?php if (!empty($s['CauseFault'])): ?>
                <div class="field-row" style="margin-top:8px;border-top:1px solid #f0f0f0;padding-top:10px;">
                  <div class="field-label">Причина</div>
                  <div class="field-value"><?= e($s['CauseFault']) ?></div>
                </div>
              <?php endif; ?>
              <?php if (!empty($s['FaultDescription'])): ?>
                <div class="field-row">
                  <div class="field-label">Описание</div>
                  <div class="field-value"><?= e($s['FaultDescription']) ?></div>
                </div>
              <?php endif; ?>
            </div>
          <?php endforeach; ?>

        <?php else: ?>
          <div style="padding:30px; text-align:center; color:#888;">Нет активных ОТМ по этой автотехнике</div>
        <?php endif; ?>

        <?php if (!empty($car['_other']['Actions'])): ?>
          <div class="section-title">Акции</div>
          <table class="doc-table">
            <thead>
              <tr>
                <th>Наименование</th>
                <th>Тип</th>
                <th>Вид</th>
                <th>Начало</th>
                <th>Окончание</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($car['_other']['Actions'] as $a): ?>
                <tr>
                  <td><?= e($a['Name'] ?? '—') ?></td>
                  <td><?= e($a['TypeAction'] ?? '—') ?></td>
                  <td><?= e($a['KindAction'] ?? '—') ?></td>
                  <td><?= e(fmtDate($a['StartDateAction'] ?? null)) ?></td>
                  <td><?= e(fmtDate($a['EndDateAction'] ?? null)) ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

        <?php if (!empty($car['_other']['SservicePackage'])): ?>
          <div class="section-title">Пакеты сервисных услуг</div>
          <table class="doc-table">
            <thead>
              <tr>
                <th>Наименование</th>
                <th>Тип</th>
                <th>Статус</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($car['_other']['SservicePackage'] as $p): ?>
                <tr>
                  <td><?= e($p['Name'] ?? '—') ?></td>
                  <td><?= e($p['TypeServicePackage'] ?? '—') ?></td>
                  <td><?= e($p['KindServicePackage'] ?? '—') ?></td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        <?php endif; ?>

      </div>

    </div>

    <div class="card">
      <div class="btn-row">
        <a href="gallery.php?key_type=vin&key_value=<?= urlencode($car['VINShassis'] ?? $car['NumberShassis'] ?? $searchValue) ?>" class="btn btn-green">📷 Фото по этому VIN</a>
        <a href="search_vin.php" class="btn btn-secondary">🔍 Новый поиск</a>
      </div>
    </div>

    <div class="card">
      <span class="json-toggle" onclick="toggleJson()">Показать полный ответ 1С (JSON)</span>
      <pre class="json-pre" id="jsonBox"><?= e(json_encode($car, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT)) ?></pre>
    </div>

  <?php endif; ?>

</div>
<script>
document.querySelectorAll('.tab').forEach(function(tab) {
  tab.addEventListener('click', function() {
    var name = tab.dataset.tab;
    document.querySelectorAll('.tab').forEach(function(t) { t.classList.remove('active'); });
    document.querySelectorAll('.tab-content').forEach(function(c) { c.classList.remove('active'); });
    tab.classList.add('active');
    document.getElementById('tab-' + name).classList.add('active');
  });
});

function toggleJson() {
  var el = document.getElementById('jsonBox');
  if (!el) return;
  el.style.display = (el.style.display === 'block') ? 'none' : 'block';
}
</script>
</body>
</html>
