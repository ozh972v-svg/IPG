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

function fmtDate($d) {
    if (!$d || $d === '—') return '—';
    $d = trim($d);
    if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $d, $m)) {
        return $m[3] . '.' . $m[2] . '.' . $m[1];
    }
    return $d;
}

function isActive($d) {
    if (!$d) return false;
    $ts = strtotime($d);
    if ($ts === false) return false;
    return $ts >= strtotime(date('Y-m-d'));
}

function isPast($d) {
    if (!$d) return false;
    $ts = strtotime($d);
    if ($ts === false) return false;
    return $ts < strtotime(date('Y-m-d'));
}

/**
 * Классификация ОТМ по разделам, как в 1С:
 *   'IB' — Информационный бюллетень
 *   'S'  — Организационно-технические мероприятия «S»
 *   'R'  — Организационно-технические мероприятия «R»
 *   'OTHER' — прочее (не попавшее в три основных раздела)
 */
function classifyCampaign(array $s): string {
    $type = strtoupper(trim((string)($s['Type'] ?? '')));
    $cat  = mb_strtolower((string)($s['Category'] ?? ''));

    // Сначала смотрим Category — она у 1С обычно информативнее
    if (mb_strpos($cat, 'бюллетен') !== false) {
        return 'IB';
    }
    if (mb_strpos($cat, 'мероприятия «s»') !== false
        || mb_strpos($cat, 'мероприятия s') !== false
        || preg_match('/мероприятия\s*[«"]?\s*s\s*[»"]?/ui', $cat)) {
        return 'S';
    }
    if (mb_strpos($cat, 'мероприятия «r»') !== false
        || mb_strpos($cat, 'мероприятия r') !== false
        || preg_match('/мероприятия\s*[«"]?\s*r\s*[»"]?/ui', $cat)) {
        return 'R';
    }

    // Fallback по Type
    if ($type === 'IB' || $type === 'I')  return 'IB';
    if ($type === 'S')                     return 'S';
    if ($type === 'R')                     return 'R';

    return 'OTHER';
}

/** Название раздела для отображения. */
function campaignSectionTitle(string $key): array {
    switch ($key) {
        case 'IB': return ['title' => '📢 Информационный бюллетень',                        'color' => '#2563eb'];
        case 'S':  return ['title' => '🟢 Организационно-технические мероприятия «S»',       'color' => '#16a34a'];
        case 'R':  return ['title' => '🔴 Организационно-технические мероприятия «R»',       'color' => '#dc2626'];
        default:   return ['title' => '📋 Прочие мероприятия',                              'color' => '#6b7280'];
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
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; margin: 0; padding: 16px; color: #1a1a1a; line-height: 1.5; }
  .container { max-width: 1100px; margin: 0 auto; }
  .card { background: #fff; border-radius: 14px; padding: 20px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 16px; }
  h1 { font-size: 22px; margin: 0 0 8px; }
  h2 { font-size: 18px; margin: 0 0 16px; }
  .top-bar { display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 8px; margin-bottom: 16px; }
  .user-info { font-size: 13px; color: #666; }
  .user-info b { color: #2563eb; }
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

  .field-row { display: flex; padding: 8px 0; border-bottom: 1px solid #f0f0f0; gap: 12px; font-size: 14px; align-items: flex-start; }
  .field-row:last-child { border-bottom: none; }
  .field-label { color: #666; flex-shrink: 0; width: 220px; }
  .field-value { color: #1a1a1a; font-weight: 500; flex: 1; word-break: normal; overflow-wrap: anywhere; }

  .field-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(420px, 1fr)); gap: 0 24px; }

  .section-title {
    font-size: 16px; font-weight: 700; color: #1e3a8a;
    margin: 20px 0 10px; padding-bottom: 6px;
    border-bottom: 2px solid #eff6ff;
  }
  .section-title:first-child { margin-top: 0; }

  .warranty-banner {
    padding: 20px 24px; border-radius: 12px; text-align: center;
    font-size: 22px; font-weight: 700; margin: 14px 0;
  }
  .warranty-banner.yes { background: #f0fdf4; color: #16a34a; border-left: 5px solid #16a34a; }
  .warranty-banner.no { background: #fef2f2; color: #dc2626; border-left: 5px solid #dc2626; }
  .warranty-banner.test { background: #eff6ff; color: #1d4ed8; border-left: 5px solid #1d4ed8; }
  .warranty-banner.test-done { background: #f3f4f6; color: #4b5563; border-left: 5px solid #9ca3af; }
  .warranty-banner.warn { background: #fffbeb; color: #b45309; border-left: 5px solid #f59e0b; }

  .warranty-banner .banner-sub {
    font-size: 15px; font-weight: 500; color: #333;
    margin-top: 16px; line-height: 1.6;
    display: flex; flex-direction: column; gap: 0;
    padding: 14px 18px; background: rgba(255,255,255,0.7);
    border-radius: 10px; text-align: left;
  }
  .warranty-banner .banner-sub .row {
    display: flex; justify-content: space-between; align-items: center;
    padding: 8px 0; border-bottom: 1px dashed rgba(0,0,0,0.1);
    gap: 16px;
  }
  .warranty-banner .banner-sub .row:last-child { border-bottom: none; }
  .warranty-banner .banner-sub .row .label {
    color: #666; font-weight: 500; font-size: 14px;
  }
  .warranty-banner .banner-sub .row .value {
    color: #1a1a1a; font-weight: 700; font-size: 15px;
    text-align: right;
  }
  .warranty-banner .calc-note {
    font-size: 11px; color: #92400e; font-weight: 500;
    margin-top: 6px; font-style: italic;
  }

  /* --- ОТМ: сворачиваемые карточки --- */
  .otm-section { margin-bottom: 20px; }
  .otm-section-title {
    display: flex; justify-content: space-between; align-items: center;
    font-size: 14px; font-weight: 700;
    padding: 10px 14px; border-radius: 10px;
    margin: 0 0 8px; color: #fff;
    gap: 12px; flex-wrap: wrap;
  }
  .otm-section-title .cnt {
    background: rgba(255,255,255,0.25);
    padding: 2px 10px; border-radius: 10px; font-size: 12px; font-weight: 700;
  }
  .otm-section-title .cnt-fail {
    background: rgba(255,255,255,0.9); color: #b91c1c;
    padding: 2px 10px; border-radius: 10px; font-size: 12px; font-weight: 700;
  }

  .otm-item {
    border: 1.5px solid #e5e7eb; border-radius: 10px;
    margin-bottom: 8px; background: #fff; overflow: hidden;
    transition: border-color 0.15s;
  }
  .otm-item[open] { border-color: #2563eb; }
  .otm-item.done { background: #fafafa; }

  .otm-item summary {
    padding: 11px 14px; cursor: pointer;
    display: flex; align-items: flex-start; gap: 10px;
    list-style: none; user-select: none;
  }
  .otm-item summary::-webkit-details-marker { display: none; }
  .otm-item summary::before {
    content: '▶'; font-size: 10px; color: #2563eb;
    margin-top: 5px; flex-shrink: 0;
    transition: transform 0.15s;
  }
  .otm-item[open] summary::before { transform: rotate(90deg); }

  .otm-mark { flex-shrink: 0; font-size: 16px; line-height: 1.3; }
  .otm-main { flex: 1; min-width: 0; }
  .otm-name {
    font-weight: 600; color: #1a1a1a; font-size: 13px; line-height: 1.35;
    word-break: normal; overflow-wrap: anywhere;
  }
  .otm-item.done .otm-name { color: #6b7280; font-weight: 500; }
  .otm-meta {
    font-size: 11px; color: #888; margin-top: 4px;
    display: flex; gap: 14px; flex-wrap: wrap;
  }
  .otm-meta span { white-space: nowrap; }

  .otm-body {
    padding: 12px 14px 14px 38px;
    border-top: 1px solid #f0f0f0;
    background: #fafbff;
  }
  .otm-body .field-row { padding: 6px 0; font-size: 13px; }
  .otm-body .field-label { width: 180px; font-size: 12px; }

  .otm-status {
    padding: 8px 12px; border-radius: 8px;
    font-weight: 700; font-size: 12px;
    margin-bottom: 10px; text-align: center;
  }
  .otm-status.done { background: #f0fdf4; color: #16a34a; border-left: 4px solid #16a34a; }
  .otm-status.not-done { background: #fef2f2; color: #dc2626; border-left: 4px solid #dc2626; }

  .badge { display: inline-block; padding: 3px 10px; border-radius: 6px; font-size: 12px; font-weight: 600; white-space: nowrap; }
  .badge-blue { background: #eff6ff; color: #2563eb; }
  .badge-green { background: #f0fdf4; color: #16a34a; }
  .badge-red { background: #fef2f2; color: #dc2626; }
  .badge-yellow { background: #fffbeb; color: #b45309; }
  .badge-gray { background: #f3f4f6; color: #6b7280; }

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

  .empty-section {
    padding: 14px; text-align: center; color: #888; font-size: 13px;
    background: #f9fafb; border-radius: 8px;
  }

  @media (max-width: 700px) {
    .field-row { flex-direction: column; gap: 4px; }
    .field-label { width: auto; }
    .field-grid { grid-template-columns: 1fr; }
    .warranty-banner { font-size: 18px; padding: 16px; }
    .warranty-banner .banner-sub .row { flex-direction: column; align-items: flex-start; gap: 2px; }
    .warranty-banner .banner-sub .row .value { text-align: left; }
    .otm-body { padding-left: 14px; }
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
      /* ---------- Гарантия на узлы ---------- */
      $nodeWarranties = $car['_other']['GuaranteesForNodes'] ?? [];
      $activeNodes = [];
      foreach ($nodeWarranties as $g) {
          if (isActive($g['ExtensionPeriod'] ?? null)) {
              $activeNodes[] = $g;
          }
      }

      /* ---------- Производственная гарантия ---------- */
      $prodSign       = trim((string)($car['WarrantyIndication'] ?? ''));
      $wStartRaw      = trim((string)($car['WarrantyStartDate'] ?? ''));
      $wEndRaw        = trim((string)($car['WarrantyExpirationDate'] ?? ''));
      $productionDate = trim((string)($car['ProductionDate'] ?? ''));

      $hasProdSign   = $prodSign !== '' && mb_stripos($prodSign, 'производств') !== false;
      $wStartReal    = $wStartRaw !== '' ? $wStartRaw : null;
      $wEndReal      = $wEndRaw   !== '' ? $wEndRaw   : null;

      $wEndCalc = null;
      if ($hasProdSign && !$wEndReal && $productionDate !== '') {
          $ts = strtotime($productionDate);
          if ($ts !== false) {
              $wEndCalc = date('Y-m-d', strtotime('+36 months', $ts));
          }
      }
      $wEndEffective = $wEndReal ?: $wEndCalc;
      $prodActive    = $wEndEffective ? isActive($wEndEffective) : false;

      /* ---------- Тестовая эксплуатация ---------- */
      $testAction = null;
      foreach (($car['_other']['Actions'] ?? []) as $a) {
          $nm = (string)($a['Name'] ?? '');
          if ($nm !== '' && mb_stripos($nm, 'тестов') !== false) {
              $testAction = $a;
              break;
          }
      }

      $testStatus = 'none';
      $testEnd    = null;
      $testStart  = null;
      if ($testAction) {
          $testStart = $testAction['StartDateAction'] ?? null;
          $testEnd   = $testAction['EndDateAction']   ?? null;
          if ($testEnd && isPast($testEnd))         $testStatus = 'done';
          elseif ($testEnd && isActive($testEnd))   $testStatus = 'active';
          elseif (!$testEnd)                        $testStatus = 'unknown';
          else                                      $testStatus = 'done';
      }

      $testPositive = ($testStatus === 'active' || $testStatus === 'unknown');
      $anyStatus    = $hasProdSign || $testPositive || !empty($activeNodes);

      /* ---------- ОТМ: группировка по разделам ---------- */
      $campaigns = $car['_other']['ServiceCampaigns'] ?? [];
      $campaignGroups = ['IB' => [], 'S' => [], 'R' => [], 'OTHER' => []];
      foreach ($campaigns as $c) {
          $key = classifyCampaign($c);
          $campaignGroups[$key][] = $c;
      }
      // Внутри каждого раздела: сначала невыполненные (done=0), потом выполненные (done=1)
      foreach ($campaignGroups as $k => $list) {
          usort($list, function($a, $b) {
              $aDone = (int)($a['Сompleted'] ?? $a['Completed'] ?? 0) > 0;
              $bDone = (int)($b['Сompleted'] ?? $b['Completed'] ?? 0) > 0;
              if ($aDone !== $bDone) return $aDone <=> $bDone; // 0 (невыполн) — раньше
              return strcmp((string)($a['StartDateCampaign'] ?? ''), (string)($b['StartDateCampaign'] ?? ''));
          });
          $campaignGroups[$k] = $list;
      }

      // Порядок разделов для отображения: IB → S → R → OTHER
      $sectionOrder = ['IB', 'S', 'R', 'OTHER'];

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

        <div class="section-title">Гарантия и эксплуатация</div>

        <?php if ($hasProdSign): ?>
          <?php if ($prodActive): ?>
            <div class="warranty-banner yes">
              ✅ ПРОИЗВОДСТВЕННАЯ ГАРАНТИЯ
              <div class="banner-sub">
                <div class="row">
                  <span class="label">Признак гарантии</span>
                  <span class="value"><?= e($prodSign) ?></span>
                </div>
                <?php if ($wStartReal): ?>
                  <div class="row">
                    <span class="label">Начало гарантии</span>
                    <span class="value"><?= e(fmtDate($wStartReal)) ?></span>
                  </div>
                <?php endif; ?>
                <div class="row">
                  <span class="label">Окончание гарантии</span>
                  <span class="value">
                    <?= e(fmtDate($wEndEffective)) ?>
                    <?php if (!$wEndReal && $wEndCalc): ?><span style="color:#92400e;"> (расчётно)</span><?php endif; ?>
                  </span>
                </div>
                <?php if (!$wEndReal && $wEndCalc): ?>
                  <div class="calc-note">Расчёт: дата изготовления + 36 месяцев. Точная дата в 1С может отличаться — уточняйте в первоисточнике.</div>
                <?php endif; ?>
              </div>
            </div>
          <?php else: ?>
            <div class="warranty-banner warn">
              ⚠️ ПРОИЗВОДСТВЕННАЯ ГАРАНТИЯ (срок истёк)
              <div class="banner-sub">
                <div class="row">
                  <span class="label">Признак гарантии</span>
                  <span class="value"><?= e($prodSign) ?></span>
                </div>
                <?php if ($wStartReal): ?>
                  <div class="row">
                    <span class="label">Начало гарантии</span>
                    <span class="value"><?= e(fmtDate($wStartReal)) ?></span>
                  </div>
                <?php endif; ?>
                <div class="row">
                  <span class="label">Окончание гарантии</span>
                  <span class="value">
                    <?= e(fmtDate($wEndEffective)) ?>
                    <?php if (!$wEndReal && $wEndCalc): ?><span style="color:#92400e;"> (расчётно)</span><?php endif; ?>
                  </span>
                </div>
              </div>
            </div>
          <?php endif; ?>
        <?php endif; ?>

        <?php if ($testAction): ?>
          <?php
            if ($testStatus === 'done') {
                $testClass = 'warranty-banner test-done';
                $testTitle = '🧪 ТЕСТОВАЯ ЭКСПЛУАТАЦИЯ — ЗАВЕРШЕНА';
            } elseif ($testStatus === 'active') {
                $testClass = 'warranty-banner test';
                $testTitle = '🧪 ТЕСТОВАЯ ЭКСПЛУАТАЦИЯ — ДЕЙСТВУЕТ';
            } else {
                $testClass = 'warranty-banner test';
                $testTitle = '🧪 ТЕСТОВАЯ ЭКСПЛУАТАЦИЯ';
            }
          ?>
          <div class="<?= $testClass ?>">
            <?= e($testTitle) ?>
            <div class="banner-sub">
              <div class="row">
                <span class="label">Название</span>
                <span class="value"><?= e($testAction['Name'] ?? '—') ?></span>
              </div>
              <?php if (!empty($testAction['Number'])): ?>
                <div class="row">
                  <span class="label">Номер акции</span>
                  <span class="value"><?= e($testAction['Number']) ?></span>
                </div>
              <?php endif; ?>
              <?php if ($testStart): ?>
                <div class="row">
                  <span class="label">Начало</span>
                  <span class="value"><?= e(fmtDate($testStart)) ?></span>
                </div>
              <?php endif; ?>
              <?php if ($testEnd): ?>
                <div class="row">
                  <span class="label">Окончание</span>
                  <span class="value"><?= e(fmtDate($testEnd)) ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($testAction['TypeAction'])): ?>
                <div class="row">
                  <span class="label">Тип</span>
                  <span class="value"><?= e($testAction['TypeAction']) ?></span>
                </div>
              <?php endif; ?>
              <?php if (!empty($testAction['KindAction'])): ?>
                <div class="row">
                  <span class="label">Вид</span>
                  <span class="value"><?= e($testAction['KindAction']) ?></span>
                </div>
              <?php endif; ?>
              <div class="row">
                <span class="label">Статус</span>
                <span class="value">
                  <?php if ($testStatus === 'active'): ?>
                    ✅ действует<?= $testEnd ? ' до ' . e(fmtDate($testEnd)) : '' ?>
                  <?php elseif ($testStatus === 'done'): ?>
                    ⏹ завершена <?= $testEnd ? e(fmtDate($testEnd)) : '' ?>
                  <?php else: ?>
                    ℹ️ без срока
                  <?php endif; ?>
                </span>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php if (!$anyStatus): ?>
          <div class="warranty-banner no">
            ❌ НЕ В ГАРАНТИИ
            <div class="banner-sub">
              <div class="row">
                <span class="label">Признак гарантии</span>
                <span class="value"><?= e($prodSign !== '' ? $prodSign : '—') ?></span>
              </div>
              <div class="row">
                <span class="label">Начало гарантии</span>
                <span class="value"><?= e(fmtDate($wStartReal)) ?></span>
              </div>
              <div class="row">
                <span class="label">Окончание гарантии</span>
                <span class="value"><?= e(fmtDate($wEndReal)) ?></span>
              </div>
              <div class="row">
                <span class="label">Пробег окончания</span>
                <span class="value"><?= number_format((int)($car['EndGuaranteeMileage'] ?? 0), 0, '.', ' ') ?> км</span>
              </div>
              <div class="row">
                <span class="label">Наработка окончания</span>
                <span class="value"><?= (int)($car['EndGuaranteeOperatingTime'] ?? 0) ?> м/ч</span>
              </div>
            </div>
          </div>
        <?php endif; ?>

        <?php foreach ($activeNodes as $g): ?>
          <div class="warranty-banner yes">
            ✅ ГАРАНТИЯ НА УЗЛЫ
            <div class="banner-sub">
              <div class="row">
                <span class="label">Наименование</span>
                <span class="value"><?= e($g['Name'] ?? 'Узел') ?></span>
              </div>
              <div class="row">
                <span class="label">Обозначение узла</span>
                <span class="value"><?= e($g['NameDefectiveNode'] ?? '—') ?></span>
              </div>
              <div class="row">
                <span class="label">Действует до</span>
                <span class="value"><?= e(fmtDate($g['ExtensionPeriod'] ?? null)) ?></span>
              </div>
              <div class="row">
                <span class="label">Пробег окончания</span>
                <span class="value"><?= number_format((int)($g['EndGuaranteeMileage'] ?? 0), 0, '.', ' ') ?> км</span>
              </div>
              <div class="row">
                <span class="label">Наработка окончания</span>
                <span class="value"><?= (int)($g['EndGuaranteeOperatingTime'] ?? 0) ?> м/ч</span>
              </div>
            </div>
          </div>
        <?php endforeach; ?>

      </div>

      <div class="tab-content" id="tab-otm">

        <?php if (empty($campaigns)): ?>
          <div class="empty-section">Нет ОТМ по этой автотехнике</div>
        <?php else: ?>

          <?php foreach ($sectionOrder as $secKey): ?>
            <?php $items = $campaignGroups[$secKey] ?? []; ?>
            <?php if (empty($items)) continue; ?>

            <?php
              $secInfo   = campaignSectionTitle($secKey);
              $total     = count($items);
              $notDone   = 0;
              foreach ($items as $it) {
                  if ((int)($it['Сompleted'] ?? $it['Completed'] ?? 0) === 0) $notDone++;
              }
            ?>

            <div class="otm-section">
              <div class="otm-section-title" style="background:<?= e($secInfo['color']) ?>;">
                <span><?= e($secInfo['title']) ?></span>
                <span>
                  <span class="cnt"><?= $total ?></span>
                  <?php if ($notDone > 0): ?>
                    <span class="cnt-fail">не вып.: <?= $notDone ?></span>
                  <?php endif; ?>
                </span>
              </div>

              <?php foreach ($items as $s): ?>
                <?php
                  $isDone = (int)($s['Сompleted'] ?? $s['Completed'] ?? 0) > 0;
                  $mark   = $isDone ? '✅' : '❌';
                  $num    = (string)($s['NumberCampaign'] ?? '');
                  $sd     = $s['StartDateCampaign'] ?? null;
                  $ed     = $s['EndDateCampaign']   ?? null;
                ?>
                <details class="otm-item <?= $isDone ? 'done' : '' ?>">
                  <summary>
                    <span class="otm-mark"><?= $mark ?></span>
                    <span class="otm-main">
                      <div class="otm-name"><?= e($s['Name'] ?? '—') ?></div>
                      <div class="otm-meta">
                        <?php if ($num !== ''): ?><span>📄 <?= e($num) ?></span><?php endif; ?>
                        <?php if ($sd): ?><span>🗓 с <?= e(fmtDate($sd)) ?></span><?php endif; ?>
                        <?php if ($ed): ?><span>до <?= e(fmtDate($ed)) ?></span><?php endif; ?>
                        <?php if (!$isDone): ?><span style="color:#dc2626;font-weight:700;">● требует выполнения</span><?php endif; ?>
                      </div>
                    </span>
                  </summary>

                  <div class="otm-body">
                    <div class="otm-status <?= $isDone ? 'done' : 'not-done' ?>">
                      <?= $isDone ? '✅ Мероприятие выполнено' : '❌ Мероприятие не выполнено' ?>
                    </div>

                    <div class="field-grid">
                      <div class="field-row">
                        <div class="field-label">Категория</div>
                        <div class="field-value"><?= e($s['Category'] ?? '—') ?></div>
                      </div>
                      <div class="field-row">
                        <div class="field-label">Тип</div>
                        <div class="field-value"><?= e($s['Type'] ?? '—') ?></div>
                      </div>
                      <div class="field-row">
                        <div class="field-label">Номер кампании</div>
                        <div class="field-value"><?= e($num !== '' ? $num : '—') ?></div>
                      </div>
                      <div class="field-row">
                        <div class="field-label">Начало</div>
                        <div class="field-value"><?= e(fmtDate($sd)) ?></div>
                      </div>
                      <div class="field-row">
                        <div class="field-label">Окончание</div>
                        <div class="field-value"><?= e(fmtDate($ed)) ?></div>
                      </div>
                      <div class="field-row">
                        <div class="field-label">Выполнено</div>
                        <div class="field-value"><?= (int)($s['Сompleted'] ?? 0) ?></div>
                      </div>
                      <?php if (!empty($s['NameInformationMail'])): ?>
                        <div class="field-row">
                          <div class="field-label">Информационное письмо</div>
                          <div class="field-value"><?= e($s['NameInformationMail']) ?></div>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($s['NumberInformationMail'])): ?>
                        <div class="field-row">
                          <div class="field-label">№ информ. письма</div>
                          <div class="field-value"><?= e($s['NumberInformationMail']) ?></div>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($s['DefectiveDetail']['Name'])): ?>
                        <div class="field-row">
                          <div class="field-label">Деталь-виновник</div>
                          <div class="field-value"><?= e($s['DefectiveDetail']['Name']) ?></div>
                        </div>
                      <?php endif; ?>
                      <?php if (!empty($s['NameDefect'])): ?>
                        <div class="field-row">
                          <div class="field-label">Дефект</div>
                          <div class="field-value"><?= e($s['NameDefect']) ?></div>
                        </div>
                      <?php endif; ?>
                    </div>

                    <?php if (!empty($s['CauseFault'])): ?>
                      <div class="field-row" style="margin-top:8px;border-top:1px solid #e5e7eb;padding-top:10px;">
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
                    <?php if (!empty($s['ResultDisassemblingDP'])): ?>
                      <div class="field-row">
                        <div class="field-label">Результат / работы</div>
                        <div class="field-value"><?= e($s['ResultDisassemblingDP']) ?></div>
                      </div>
                    <?php endif; ?>

                    <?php if (!empty($s['VUDS']) && is_array($s['VUDS'])): ?>
                      <div style="margin-top:12px;">
                        <div style="font-weight:700;color:#1e3a8a;font-size:13px;margin-bottom:6px;">Виды устранения дефектов (VUDS)</div>
                        <table class="doc-table">
                          <thead>
                            <tr>
                              <th style="width:60px;">Код</th>
                              <th>Описание</th>
                            </tr>
                          </thead>
                          <tbody>
                            <?php foreach ($s['VUDS'] as $v): ?>
                              <tr>
                                <td><?= e($v['VarUD'] ?? '—') ?></td>
                                <td><?= e($v['UDDesc'] ?? '—') ?></td>
                              </tr>
                            <?php endforeach; ?>
                          </tbody>
                        </table>
                      </div>
                    <?php endif; ?>
                  </div>
                </details>
              <?php endforeach; ?>
            </div>
          <?php endforeach; ?>

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
                <th>Статус</th>
                <th>Обработано</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($car['_other']['Actions'] as $a): ?>
                <?php
                  $aStart = $a['StartDateAction'] ?? null;
                  $aEnd   = $a['EndDateAction']   ?? null;
                  $aProcessed = (int)($a['Сompleted'] ?? $a['Completed'] ?? 0) > 0;
                  if ($aEnd && isPast($aEnd))       $aStatus = '⏹ завершена';
                  elseif ($aEnd && isActive($aEnd)) $aStatus = '✅ действует';
                  else                              $aStatus = 'ℹ️ без срока';
                ?>
                <tr>
                  <td><?= e($a['Name'] ?? '—') ?></td>
                  <td><?= e($a['TypeAction'] ?? '—') ?></td>
                  <td><?= e($a['KindAction'] ?? '—') ?></td>
                  <td><?= e(fmtDate($aStart)) ?></td>
                  <td><?= e(fmtDate($aEnd)) ?></td>
                  <td><?= e($aStatus) ?></td>
                  <td><?= $aProcessed ? '✅' : '—' ?></td>
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
