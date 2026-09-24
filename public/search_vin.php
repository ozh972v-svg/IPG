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

function classifyCampaign(array $s): string {
    $type = strtoupper(trim((string)($s['Type'] ?? '')));
    $cat  = mb_strtolower((string)($s['Category'] ?? ''));

    if (mb_strpos($cat, 'бюллетен') !== false) return 'IB';
    if (mb_strpos($cat, 'мероприятия «s»') !== false
        || mb_strpos($cat, 'мероприятия s') !== false
        || preg_match('/мероприятия\s*[«"]?\s*s\s*[»"]?/ui', $cat)) return 'S';
    if (mb_strpos($cat, 'мероприятия «r»') !== false
        || mb_strpos($cat, 'мероприятия r') !== false
        || preg_match('/мероприятия\s*[«"]?\s*r\s*[»"]?/ui', $cat)) return 'R';

    if ($type === 'IB' || $type === 'I') return 'IB';
    if ($type === 'S') return 'S';
    if ($type === 'R') return 'R';

    return 'OTHER';
}

function campaignSectionTitle(string $key): array {
    switch ($key) {
        case 'IB': return ['title' => '📢 Информационный бюллетень',                  'color' => '#2563eb'];
        case 'S':  return ['title' => '🟢 Организационно-технические мероприятия «S»', 'color' => '#059669'];
        case 'R':  return ['title' => '🔴 Организационно-технические мероприятия «R»', 'color' => '#dc2626'];
        default:   return ['title' => '📋 Прочие мероприятия',                        'color' => '#64748b'];
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Поиск по VIN — 1С:ГОА</title>
<link rel="stylesheet" href="app.css">
<?php include __DIR__ . '/pwa.php'; ?>    
<style>
  /* === Специфичные для страницы стили === */

  .page-hero {
    margin-bottom: 18px;
    padding: 4px 0;
  }
  .page-hero h1 {
    font-size: 24px;
    letter-spacing: -0.02em;
    margin-bottom: 6px;
  }
  .page-hero p {
    color: var(--ink-soft);
    font-size: 14.5px;
  }

  /* Форма поиска */
  .search-form {
    display: grid;
    grid-template-columns: 200px 1fr auto;
    gap: 10px;
    align-items: stretch;
  }
  @media (max-width: 640px) {
    .search-form { grid-template-columns: 1fr; }
  }

  /* Tabs */
  .tabs {
    display: flex;
    gap: 4px;
    border-bottom: 2px solid rgba(15,23,42,0.08);
    margin-bottom: 20px;
    flex-wrap: wrap;
  }
  .tab {
    padding: 12px 20px;
    border: none;
    background: transparent;
    font-size: 14px;
    font-weight: 600;
    color: var(--ink-soft);
    cursor: pointer;
    border-bottom: 3px solid transparent;
    margin-bottom: -2px;
    border-radius: 8px 8px 0 0;
    font-family: inherit;
    transition: all 0.15s;
  }
  .tab:hover { background: rgba(37,99,235,0.05); color: var(--ink); }
  .tab.active { color: var(--blue); border-bottom-color: var(--blue); }
  .tab-content { display: none; }
  .tab-content.active { display: block; animation: ipgFade 0.25s ease-out; }

  /* Поля сведений */
  .field-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(420px, 1fr));
    gap: 0 24px;
  }
  .field-row {
    display: flex;
    padding: 10px 0;
    border-bottom: 1px solid rgba(15,23,42,0.06);
    gap: 12px;
    font-size: 14px;
    align-items: flex-start;
  }
  .field-row:last-child { border-bottom: none; }
  .field-label { color: var(--ink-soft); flex-shrink: 0; width: 220px; font-size: 13px; }
  .field-value { color: var(--ink); font-weight: 600; flex: 1; word-break: normal; overflow-wrap: anywhere; }

  /* Заголовок секции */
  .section-title {
    font-size: 15px;
    font-weight: 700;
    color: #1e3a8a;
    margin: 22px 0 12px;
    padding-bottom: 8px;
    border-bottom: 2px solid rgba(37,99,235,0.1);
    letter-spacing: -0.005em;
  }
  .section-title:first-child { margin-top: 0; }

  /* Гарантийные баннеры */
  .warranty-banner {
    padding: 22px 26px;
    border-radius: 16px;
    text-align: center;
    font-size: 20px;
    font-weight: 800;
    margin: 14px 0;
    letter-spacing: -0.01em;
    position: relative;
    overflow: hidden;
  }
  .warranty-banner::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 16px;
    pointer-events: none;
    box-shadow: 0 1px 0 rgba(255,255,255,0.6) inset;
  }
  .warranty-banner.yes       { background: linear-gradient(135deg, #ecfdf5, #d1fae5); color: #065f46; border-left: 5px solid #10b981; }
  .warranty-banner.no        { background: linear-gradient(135deg, #fef2f2, #fee2e2); color: #991b1b; border-left: 5px solid #ef4444; }
  .warranty-banner.test      { background: linear-gradient(135deg, #eff6ff, #dbeafe); color: #1e40af; border-left: 5px solid #3b82f6; }
  .warranty-banner.test-done { background: linear-gradient(135deg, #f8fafc, #f1f5f9); color: #475569; border-left: 5px solid #94a3b8; }
  .warranty-banner.warn      { background: linear-gradient(135deg, #fffbeb, #fef3c7); color: #92400e; border-left: 5px solid #f59e0b; }

  .warranty-banner .banner-sub {
    font-size: 14px;
    font-weight: 500;
    color: #334155;
    margin-top: 16px;
    padding: 14px 18px;
    background: rgba(255,255,255,0.75);
    border-radius: 12px;
    text-align: left;
    display: flex;
    flex-direction: column;
  }
  .warranty-banner .banner-sub .row {
    display: flex;
    justify-content: space-between;
    align-items: center;
    padding: 8px 0;
    border-bottom: 1px dashed rgba(15,23,42,0.1);
    gap: 16px;
  }
  .warranty-banner .banner-sub .row:last-child { border-bottom: none; }
  .warranty-banner .banner-sub .row .label { color: #64748b; font-weight: 500; font-size: 13px; }
  .warranty-banner .banner-sub .row .value { color: #0f172a; font-weight: 700; font-size: 14px; text-align: right; }
  .warranty-banner .calc-note {
    font-size: 11.5px;
    color: #92400e;
    font-weight: 500;
    margin-top: 8px;
    font-style: italic;
  }

  /* ОТМ */
  .otm-section { margin-bottom: 22px; }
  .otm-section-title {
    display: flex;
    justify-content: space-between;
    align-items: center;
    font-size: 14px;
    font-weight: 700;
    padding: 12px 16px;
    border-radius: 12px;
    margin: 0 0 10px;
    color: #fff;
    gap: 12px;
    flex-wrap: wrap;
    box-shadow: 0 6px 18px -8px rgba(15,23,42,0.25);
  }
  .otm-section-title .cnt {
    background: rgba(255,255,255,0.28);
    padding: 3px 12px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
  }
  .otm-section-title .cnt-fail {
    background: rgba(255,255,255,0.95);
    color: #b91c1c;
    padding: 3px 12px;
    border-radius: 10px;
    font-size: 12px;
    font-weight: 700;
  }

  .otm-item {
    border: 1.5px solid rgba(15,23,42,0.08);
    border-radius: 12px;
    margin-bottom: 8px;
    background: #fff;
    overflow: hidden;
    transition: all 0.18s;
  }
  .otm-item:hover { border-color: rgba(37,99,235,0.3); }
  .otm-item[open] { border-color: var(--blue); box-shadow: 0 4px 16px -8px rgba(37,99,235,0.3); }
  .otm-item.done { background: #fafbfc; }

  .otm-item summary {
    padding: 13px 16px;
    cursor: pointer;
    display: flex;
    align-items: flex-start;
    gap: 12px;
    list-style: none;
    user-select: none;
  }
  .otm-item summary::-webkit-details-marker { display: none; }
  .otm-item summary::before {
    content: '▶';
    font-size: 10px;
    color: var(--blue);
    margin-top: 5px;
    flex-shrink: 0;
    transition: transform 0.18s;
  }
  .otm-item[open] summary::before { transform: rotate(90deg); }

  .otm-mark { flex-shrink: 0; font-size: 16px; line-height: 1.3; }
  .otm-main { flex: 1; min-width: 0; }
  .otm-name {
    font-weight: 600;
    color: var(--ink);
    font-size: 13.5px;
    line-height: 1.4;
    word-break: normal;
    overflow-wrap: anywhere;
  }
  .otm-item.done .otm-name { color: #64748b; font-weight: 500; }
  .otm-meta {
    font-size: 11.5px;
    color: #94a3b8;
    margin-top: 5px;
    display: flex;
    gap: 14px;
    flex-wrap: wrap;
  }
  .otm-meta span { white-space: nowrap; }

  .otm-body {
    padding: 14px 16px 16px 40px;
    border-top: 1px solid rgba(15,23,42,0.06);
    background: #f8fafc;
  }
  .otm-body .field-row { padding: 7px 0; font-size: 13px; }
  .otm-body .field-label { width: 180px; font-size: 12.5px; }

  .otm-status {
    padding: 9px 14px;
    border-radius: 10px;
    font-weight: 700;
    font-size: 12.5px;
    margin-bottom: 12px;
    text-align: center;
  }
  .otm-status.done     { background: linear-gradient(135deg, #ecfdf5, #d1fae5); color: #065f46; border-left: 4px solid #10b981; }
  .otm-status.not-done { background: linear-gradient(135deg, #fef2f2, #fee2e2); color: #991b1b; border-left: 4px solid #ef4444; }

  /* Таблица документов */
  table.doc-table { width: 100%; border-collapse: collapse; font-size: 13px; margin-top: 8px; }
  table.doc-table th {
    background: #f8fafc;
    color: #64748b;
    font-weight: 600;
    text-align: left;
    padding: 10px 12px;
    border-bottom: 1px solid rgba(15,23,42,0.08);
    font-size: 11.5px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
  }
  table.doc-table td {
    padding: 10px 12px;
    border-bottom: 1px solid rgba(15,23,42,0.05);
    vertical-align: top;
  }
  table.doc-table tr:last-child td { border-bottom: none; }
  table.doc-table tr:hover td { background: #f8fafc; }

  /* JSON */
  .json-toggle {
    font-size: 13px;
    color: var(--blue);
    cursor: pointer;
    display: inline-flex;
    align-items: center;
    gap: 6px;
    user-select: none;
    font-weight: 500;
  }
  .json-toggle:hover { text-decoration: underline; }
  .json-pre {
    display: none;
    background: #0f172a;
    color: #a5f3fc;
    border-radius: 12px;
    padding: 16px;
    font-size: 12px;
    font-family: 'SF Mono', Consolas, monospace;
    max-height: 400px;
    overflow: auto;
    margin-top: 12px;
    white-space: pre-wrap;
    line-height: 1.55;
  }

  .empty-section {
    padding: 20px;
    text-align: center;
    color: var(--ink-soft);
    font-size: 14px;
    background: #f8fafc;
    border-radius: 12px;
  }

  /* Кнопка-таблетка с иконкой VIN */
  .vin-summary {
    display: flex;
    align-items: center;
    gap: 14px;
    padding: 16px 20px;
    background: linear-gradient(135deg, #eff6ff, #dbeafe);
    border-radius: 14px;
    border-left: 4px solid var(--blue);
    margin-bottom: 18px;
    flex-wrap: wrap;
  }
  .vin-summary-icon {
    width: 44px;
    height: 44px;
    border-radius: 12px;
    background: linear-gradient(135deg, #2563eb, #06b6d4);
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 20px;
    color: #fff;
    flex-shrink: 0;
    box-shadow: 0 6px 14px -6px rgba(37,99,235,0.6);
  }
  .vin-summary-text { flex: 1; min-width: 0; }
  .vin-summary-title {
    font-size: 16px;
    font-weight: 700;
    color: #1e3a8a;
    letter-spacing: -0.01em;
  }
  .vin-summary-sub {
    font-size: 13px;
    color: #475569;
    margin-top: 3px;
  }

  @media (max-width: 700px) {
    .field-row { flex-direction: column; gap: 4px; }
    .field-label { width: auto; }
    .field-grid { grid-template-columns: 1fr; }
    .warranty-banner { font-size: 17px; padding: 18px; }
    .warranty-banner .banner-sub .row { flex-direction: column; align-items: flex-start; gap: 3px; }
    .warranty-banner .banner-sub .row .value { text-align: left; }
    .otm-body { padding-left: 14px; }
    .tab { padding: 10px 14px; font-size: 13px; }
  }
</style>
</head>
<body>
<div class="container">

  <?php
    $pageTitle    = 'Поиск по VIN';
    $pageSubtitle = '1С:ГОА · КАМАЗ';
    $backLink     = 'index.html';
    $backLabel    = 'На рабочее место';
    include __DIR__ . '/header.php';
  ?>

  <div class="page-hero">
    <h1>🔍 Поиск по VIN</h1>
    <p>Введите VIN шасси, VIN ТС или номер двигателя — данные подтянутся из 1С:ГОА</p>
  </div>

  <div class="card mb-3">
    <form method="get" class="search-form">
      <select name="method" class="form-select">
        <?php foreach ($METHODS as $m => $name): ?>
          <option value="<?= e($m) ?>" <?= $searchMethod === $m ? 'selected' : '' ?>><?= e($name) ?></option>
        <?php endforeach; ?>
      </select>
      <input type="text" name="number" class="form-input" value="<?= e($searchValue) ?>" placeholder="Например: XTC549015S2628195 или 2628195" required>
      <button type="submit" class="btn btn-primary">🔍 Найти</button>
    </form>
  </div>

  <?php if ($error): ?>
    <div class="flash flash-error mb-3">❌ <?= e($error) ?></div>
  <?php endif; ?>

  <?php if ($car): ?>
    <?php
      $nodeWarranties = $car['_other']['GuaranteesForNodes'] ?? [];
      $activeNodes = [];
      foreach ($nodeWarranties as $g) {
          if (isActive($g['ExtensionPeriod'] ?? null)) {
              $activeNodes[] = $g;
          }
      }

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

      $campaigns = $car['_other']['ServiceCampaigns'] ?? [];
      $campaignGroups = ['IB' => [], 'S' => [], 'R' => [], 'OTHER' => []];
      foreach ($campaigns as $c) {
          $key = classifyCampaign($c);
          $campaignGroups[$key][] = $c;
      }
      foreach ($campaignGroups as $k => $list) {
          usort($list, function($a, $b) {
              $aDone = (int)($a['Сompleted'] ?? $a['Completed'] ?? 0) > 0;
              $bDone = (int)($b['Сompleted'] ?? $b['Completed'] ?? 0) > 0;
              if ($aDone !== $bDone) return $aDone <=> $bDone;
              return strcmp((string)($a['StartDateCampaign'] ?? ''), (string)($b['StartDateCampaign'] ?? ''));
          });
          $campaignGroups[$k] = $list;
      }
      $sectionOrder = ['IB', 'S', 'R', 'OTHER'];

      $title1 = 'КАМАЗ ' . ($car['ShassisModel'] ?? '—');
      $title2 = 'VIN ш.: ' . ($car['VINShassis'] ?? '—') . ' · двигатель: ' . ($car['NumberEngine'] ?? '—');
    ?>

    <div class="card">
      <div class="vin-summary">
        <div class="vin-summary-icon">🚛</div>
        <div class="vin-summary-text">
          <div class="vin-summary-title"><?= e($title1) ?></div>
          <div class="vin-summary-sub"><?= e($title2) ?></div>
        </div>
      </div>

      <div class="tabs">
        <button type="button" class="tab active" data-tab="main">📋 Основные сведения</button>
        <button type="button" class="tab" data-tab="otm">🛠 ОТМ и Акции</button>
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
                <div class="row"><span class="label">Признак гарантии</span><span class="value"><?= e($prodSign) ?></span></div>
                <?php if ($wStartReal): ?>
                  <div class="row"><span class="label">Начало гарантии</span><span class="value"><?= e(fmtDate($wStartReal)) ?></span></div>
                <?php endif; ?>
                <div class="row">
                  <span class="label">Окончание гарантии</span>
                  <span class="value"><?= e(fmtDate($wEndEffective)) ?><?php if (!$wEndReal && $wEndCalc): ?> <span style="color:#92400e;">(расчётно)</span><?php endif; ?></span>
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
                <div class="row"><span class="label">Признак гарантии</span><span class="value"><?= e($prodSign) ?></span></div>
                <?php if ($wStartReal): ?>
                  <div class="row"><span class="label">Начало гарантии</span><span class="value"><?= e(fmtDate($wStartReal)) ?></span></div>
                <?php endif; ?>
                <div class="row">
                  <span class="label">Окончание гарантии</span>
                  <span class="value"><?= e(fmtDate($wEndEffective)) ?><?php if (!$wEndReal && $wEndCalc): ?> <span style="color:#92400e;">(расчётно)</span><?php endif; ?></span>
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
              <div class="row"><span class="label">Название</span><span class="value"><?= e($testAction['Name'] ?? '—') ?></span></div>
              <?php if (!empty($testAction['Number'])): ?>
                <div class="row"><span class="label">Номер акции</span><span class="value"><?= e($testAction['Number']) ?></span></div>
              <?php endif; ?>
              <?php if ($testStart): ?>
                <div class="row"><span class="label">Начало</span><span class="value"><?= e(fmtDate($testStart)) ?></span></div>
              <?php endif; ?>
              <?php if ($testEnd): ?>
                <div class="row"><span class="label">Окончание</span><span class="value"><?= e(fmtDate($testEnd)) ?></span></div>
              <?php endif; ?>
              <?php if (!empty($testAction['TypeAction'])): ?>
                <div class="row"><span class="label">Тип</span><span class="value"><?= e($testAction['TypeAction']) ?></span></div>
              <?php endif; ?>
              <?php if (!empty($testAction['KindAction'])): ?>
                <div class="row"><span class="label">Вид</span><span class="value"><?= e($testAction['KindAction']) ?></span></div>
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
              <div class="row"><span class="label">Признак гарантии</span><span class="value"><?= e($prodSign !== '' ? $prodSign : '—') ?></span></div>
              <div class="row"><span class="label">Начало гарантии</span><span class="value"><?= e(fmtDate($wStartReal)) ?></span></div>
              <div class="row"><span class="label">Окончание гарантии</span><span class="value"><?= e(fmtDate($wEndReal)) ?></span></div>
              <div class="row"><span class="label">Пробег окончания</span><span class="value"><?= number_format((int)($car['EndGuaranteeMileage'] ?? 0), 0, '.', ' ') ?> км</span></div>
              <div class="row"><span class="label">Наработка окончания</span><span class="value"><?= (int)($car['EndGuaranteeOperatingTime'] ?? 0) ?> м/ч</span></div>
            </div>
          </div>
        <?php endif; ?>

        <?php foreach ($activeNodes as $g): ?>
          <div class="warranty-banner yes">
            ✅ ГАРАНТИЯ НА УЗЛЫ
            <div class="banner-sub">
              <div class="row"><span class="label">Наименование</span><span class="value"><?= e($g['Name'] ?? 'Узел') ?></span></div>
              <div class="row"><span class="label">Обозначение узла</span><span class="value"><?= e($g['NameDefectiveNode'] ?? '—') ?></span></div>
              <div class="row"><span class="label">Действует до</span><span class="value"><?= e(fmtDate($g['ExtensionPeriod'] ?? null)) ?></span></div>
              <div class="row"><span class="label">Пробег окончания</span><span class="value"><?= number_format((int)($g['EndGuaranteeMileage'] ?? 0), 0, '.', ' ') ?> км</span></div>
              <div class="row"><span class="label">Наработка окончания</span><span class="value"><?= (int)($g['EndGuaranteeOperatingTime'] ?? 0) ?> м/ч</span></div>
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
                      <div class="field-row"><div class="field-label">Категория</div><div class="field-value"><?= e($s['Category'] ?? '—') ?></div></div>
                      <div class="field-row"><div class="field-label">Тип</div><div class="field-value"><?= e($s['Type'] ?? '—') ?></div></div>
                      <div class="field-row"><div class="field-label">Номер кампании</div><div class="field-value"><?= e($num !== '' ? $num : '—') ?></div></div>
                      <div class="field-row"><div class="field-label">Начало</div><div class="field-value"><?= e(fmtDate($sd)) ?></div></div>
                      <div class="field-row"><div class="field-label">Окончание</div><div class="field-value"><?= e(fmtDate($ed)) ?></div></div>
                      <div class="field-row"><div class="field-label">Выполнено</div><div class="field-value"><?= (int)($s['Сompleted'] ?? 0) ?></div></div>
                      <?php if (!empty($s['NameInformationMail'])): ?>
                        <div class="field-row"><div class="field-label">Информационное письмо</div><div class="field-value"><?= e($s['NameInformationMail']) ?></div></div>
                      <?php endif; ?>
                      <?php if (!empty($s['NumberInformationMail'])): ?>
                        <div class="field-row"><div class="field-label">№ информ. письма</div><div class="field-value"><?= e($s['NumberInformationMail']) ?></div></div>
                      <?php endif; ?>
                      <?php if (!empty($s['DefectiveDetail']['Name'])): ?>
                        <div class="field-row"><div class="field-label">Деталь-виновник</div><div class="field-value"><?= e($s['DefectiveDetail']['Name']) ?></div></div>
                      <?php endif; ?>
                      <?php if (!empty($s['NameDefect'])): ?>
                        <div class="field-row"><div class="field-label">Дефект</div><div class="field-value"><?= e($s['NameDefect']) ?></div></div>
                      <?php endif; ?>
                    </div>

                    <?php if (!empty($s['CauseFault'])): ?>
                      <div class="field-row" style="margin-top:8px;border-top:1px solid rgba(15,23,42,0.08);padding-top:10px;">
                        <div class="field-label">Причина</div>
                        <div class="field-value"><?= e($s['CauseFault']) ?></div>
                      </div>
                    <?php endif; ?>
                    <?php if (!empty($s['FaultDescription'])): ?>
                      <div class="field-row"><div class="field-label">Описание</div><div class="field-value"><?= e($s['FaultDescription']) ?></div></div>
                    <?php endif; ?>
                    <?php if (!empty($s['ResultDisassemblingDP'])): ?>
                      <div class="field-row"><div class="field-label">Результат / работы</div><div class="field-value"><?= e($s['ResultDisassemblingDP']) ?></div></div>
                    <?php endif; ?>

                    <?php if (!empty($s['VUDS']) && is_array($s['VUDS'])): ?>
                      <div style="margin-top:12px;">
                        <div style="font-weight:700;color:#1e3a8a;font-size:13px;margin-bottom:6px;">Виды устранения дефектов (VUDS)</div>
                        <table class="doc-table">
                          <thead><tr><th style="width:60px;">Код</th><th>Описание</th></tr></thead>
                          <tbody>
                            <?php foreach ($s['VUDS'] as $v): ?>
                              <tr><td><?= e($v['VarUD'] ?? '—') ?></td><td><?= e($v['UDDesc'] ?? '—') ?></td></tr>
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
                <th>Наименование</th><th>Тип</th><th>Вид</th><th>Начало</th><th>Окончание</th><th>Статус</th><th>Обработано</th>
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
            <thead><tr><th>Наименование</th><th>Тип</th><th>Статус</th></tr></thead>
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
      <div class="row-flex">
        <a href="gallery.php?key_type=vin&key_value=<?= urlencode($car['VINShassis'] ?? $car['NumberShassis'] ?? $searchValue) ?>" class="btn btn-green">📷 Фото по этому VIN</a>
        <a href="search_vin.php" class="btn btn-secondary">🔍 Новый поиск</a>
      </div>
    </div>

    <div class="card">
      <span class="json-toggle" onclick="toggleJson()">▸ Показать полный ответ 1С (JSON)</span>
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
  var t  = document.querySelector('.json-toggle');
  if (!el) return;
  if (el.style.display === 'block') {
    el.style.display = 'none';
    t.textContent = '▸ Показать полный ответ 1С (JSON)';
  } else {
    el.style.display = 'block';
    t.textContent = '▾ Скрыть JSON';
  }
}
</script>
</body>
</html>
