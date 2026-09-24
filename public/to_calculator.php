<?php
require __DIR__ . '/db.php';
start_session();
$user = current_user();

$pageTitle    = 'Калькулятор ТО КАМАЗ';
$pageSubtitle = 'регламент ТО по пробегу и сроку';
$backLink     = 'index.html';
$backLabel    = 'На рабочее место';

$db = get_db();

$matrices = [];
try {
    $matrices = $db->query("SELECT * FROM to_matrices ORDER BY model, complectation")->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {}

$vin           = strtoupper(trim((string)($_GET['vin'] ?? '')));
$complectation = trim((string)($_GET['c'] ?? ''));
$toCode        = trim((string)($_GET['to'] ?? ''));

$mileage      = (int)($_GET['mileage'] ?? 0);
$lastMileage  = (int)($_GET['last_mileage'] ?? 0);
$lastDate     = trim((string)($_GET['last_date'] ?? ''));
$lastToLabel  = trim((string)($_GET['last_to'] ?? ''));

function extract_model_from_vin(string $vin): ?string
{
    $vin = strtoupper(trim($vin));
    if (strlen($vin) < 8) return null;
    $model = substr($vin, 3, 5);
    if (!preg_match('/^[A-Z0-9]{5}$/', $model)) return null;
    return $model;
}

$vinModel = $vin !== '' ? extract_model_from_vin($vin) : null;

$filteredMatrices = $matrices;
if ($vinModel) {
    $filteredMatrices = array_values(array_filter($matrices, function ($m) use ($vinModel) {
        $mm = $m['model'] ?? '';
        if ($mm === '') return false;
        return $mm === $vinModel || substr($mm, 0, 4) === substr($vinModel, 0, 4);
    }));
    if (!$filteredMatrices) $filteredMatrices = $matrices;
}

$matrix = null;
$norms  = [];
$items  = [];

if ($complectation !== '') {
    $st = $db->prepare("SELECT * FROM to_matrices WHERE complectation = ?");
    $st->execute([$complectation]);
    $matrix = $st->fetch(PDO::FETCH_ASSOC);

    if ($matrix) {
        $stN = $db->prepare("SELECT to_code, norm_hours FROM to_norms WHERE matrix_id = ?");
        $stN->execute([$matrix['id']]);
        foreach ($stN->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $norms[$r['to_code']] = (float)$r['norm_hours'];
        }

        if ($toCode !== '') {
            $stI = $db->prepare("
                SELECT row_num, name, article, unit, quantities
                  FROM to_items
                 WHERE matrix_id = ? AND jsonb_exists(quantities, ?)
                 ORDER BY row_num
            ");
            $stI->execute([$matrix['id'], $toCode]);
            $items = $stI->fetchAll(PDO::FETCH_ASSOC);
        }
    }
}

$TO_LABELS = [
    'PZ'   => 'Предварительно-заключительные работы',
    'PTO'  => 'ПТО (периодическое ТО)',
    'A2'   => '2ТО (А2)',
    'A3'   => '3ТО (А3)',
    'TOD'  => 'ТОд',
    'ZTOD' => '3ТОд',
    'TOK'  => 'ТОк',
    'TOM'  => 'ТОм',
    'V'    => '1 раз в год (В)',
    'V2'   => '1 раз в 2 года (В2)',
    'V3'   => '1 раз в 3 года (В3)',
    'V4'   => '1 раз в 4 года (В4)',
    'WASH' => 'Мойка а/м',
];

function compute_recommendations(int $mileage, int $lastMileage, ?string $lastDate): array
{
    $kmSince = max(0, $mileage - $lastMileage);
    $monthsSince = null;
    if ($lastDate !== null && $lastDate !== '') {
        try {
            $d = new DateTime($lastDate);
            $now = new DateTime();
            if ($d <= $now) {
                $diff = $d->diff($now);
                $monthsSince = $diff->y * 12 + $diff->m;
            }
        } catch (Throwable $e) {}
    }
    $intervals = [
        'PTO' => ['km' => 120000, 'months' => 12],
        'TOD' => ['km' => 120000, 'months' => 12],
        'TOK' => ['km' => 240000, 'months' => 24],
        'V'   => ['km' => 0,      'months' => 12],
        'V2'  => ['km' => 0,      'months' => 24],
        'V3'  => ['km' => 0,      'months' => 36],
        'V4'  => ['km' => 0,      'months' => 48],
    ];
    $out = [];
    foreach ($intervals as $code => $rule) {
        $byKm   = $rule['km'] > 0 && $kmSince >= $rule['km'];
        $byTime = $monthsSince !== null && $monthsSince >= $rule['months'];
        if ($byKm || $byTime) {
            $out[$code] = [
                'by_km' => $byKm, 'by_time' => $byTime,
                'km_since' => $kmSince, 'months_since' => $monthsSince,
                'km_need' => $rule['km'], 'months_need' => $rule['months'],
            ];
        }
    }
    return $out;
}

$recommendations = [];
$kmSince = 0;
$monthsSince = null;
$hasInput = false;

if ($matrix && $mileage > 0) {
    $hasInput = true;
    $recommendations = compute_recommendations($mileage, $lastMileage, $lastDate);
    $kmSince = max(0, $mileage - $lastMileage);
    if ($lastDate !== '') {
        try {
            $d = new DateTime($lastDate);
            $now = new DateTime();
            if ($d <= $now) {
                $diff = $d->diff($now);
                $monthsSince = $diff->y * 12 + $diff->m;
            }
        } catch (Throwable $e) {}
    }
}

include __DIR__ . '/header.php';
?>
<link rel="stylesheet" href="app.css">

<div class="container" style="max-width:1100px;margin:0 auto;padding:24px">

  <?php if (!$matrices): ?>
    <div style="background:rgba(255,255,255,0.85);padding:22px 26px;border-radius:18px">
      <h2 style="margin:0 0 8px;font-size:18px">Матриц пока нет</h2>
      <p style="color:#64748b;margin:0 0 14px">Сначала загрузите XLSX-файл с матрицами.</p>
      <a href="sync_to_matrix.php"
         style="display:inline-block;padding:10px 18px;border-radius:10px;background:#2563eb;color:#fff;text-decoration:none;font-weight:600">
        Перейти к импорту →
      </a>
    </div>
  <?php else: ?>

    <form method="get" style="background:rgba(255,255,255,0.85);padding:22px 26px;border-radius:18px;margin-bottom:20px">
      <h2 style="margin:0 0 6px;font-size:17px">Определить ТО по VIN и пробегу</h2>
      <p style="margin:0 0 16px;color:#64748b;font-size:13.5px">
        Введите VIN и нажмите «Запросить 1С» — комплектация подставится автоматически. Или выберите вручную.
      </p>

      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px">
        <div>
          <label style="display:block;font-size:12.5px;color:#64748b;margin-bottom:5px">VIN (опц.)</label>
          <div style="display:flex;gap:8px">
            <input type="text" name="vin" id="vinInput" value="<?= e($vin) ?>" maxlength="20"
                   placeholder="XTC549015S2617735" autocomplete="off" spellcheck="false"
                   style="flex:1;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;font-size:14px;font-family:ui-monospace,Menlo,monospace;text-transform:uppercase">
            <button type="button" id="vinLookupBtn"
                    style="padding:10px 14px;border:none;border-radius:10px;background:#059669;color:#fff;font-weight:600;cursor:pointer;font-size:13px;white-space:nowrap">
              🔍 Запросить 1С
            </button>
          </div>
          <div id="vinStatus" style="margin-top:8px;font-size:12.5px;line-height:1.5"></div>
        </div>

        <div>
          <label style="display:block;font-size:12.5px;color:#64748b;margin-bottom:5px">Комплектация *</label>
          <select name="c" id="complectationSelect" required
                  style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;font-size:14px">
            <option value="">— выберите —</option>
            <?php foreach ($filteredMatrices as $m): ?>
              <option value="<?= e($m['complectation']) ?>"
                <?= $m['complectation'] === $complectation ? 'selected' : '' ?>>
                <?= e($m['complectation']) ?><?= $m['model'] ? ' (' . e($m['model']) . ')' : '' ?>
              </option>
            <?php endforeach; ?>
            <?php if ($filteredMatrices !== $matrices): ?>
              <optgroup label="— другие модели —">
                <?php foreach ($matrices as $m): if (in_array($m, $filteredMatrices)) continue; ?>
                  <option value="<?= e($m['complectation']) ?>"
                    <?= $m['complectation'] === $complectation ? 'selected' : '' ?>>
                    <?= e($m['complectation']) ?>
                  </option>
                <?php endforeach; ?>
              </optgroup>
            <?php endif; ?>
          </select>
        </div>

        <div>
          <label style="display:block;font-size:12.5px;color:#64748b;margin-bottom:5px">Текущий пробег, км *</label>
          <input type="number" name="mileage" min="0" step="1" required
                 value="<?= $mileage > 0 ? $mileage : '' ?>"
                 placeholder="например, 245000"
                 style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;font-size:14px">
        </div>

        <div>
          <label style="display:block;font-size:12.5px;color:#64748b;margin-bottom:5px">Пробег при последнем ТО, км</label>
          <input type="number" name="last_mileage" min="0" step="1"
                 value="<?= $lastMileage > 0 ? $lastMileage : '' ?>"
                 placeholder="опц. — если известно"
                 style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;font-size:14px">
        </div>

        <div>
          <label style="display:block;font-size:12.5px;color:#64748b;margin-bottom:5px">Дата последнего ТО</label>
          <input type="date" name="last_date" value="<?= e($lastDate) ?>"
                 style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;font-size:14px">
        </div>

        <div>
          <label style="display:block;font-size:12.5px;color:#64748b;margin-bottom:5px">Последнее было</label>
          <select name="last_to"
                  style="width:100%;padding:10px 12px;border-radius:10px;border:1px solid #cbd5e1;background:#fff;font-size:14px">
            <option value="">не знаю / не важно</option>
            <option value="PTO" <?= $lastToLabel === 'PTO' ? 'selected' : '' ?>>ПТО</option>
            <option value="TOD" <?= $lastToLabel === 'TOD' ? 'selected' : '' ?>>ТОД</option>
            <option value="TOK" <?= $lastToLabel === 'TOK' ? 'selected' : '' ?>>ТОк</option>
            <option value="V"   <?= $lastToLabel === 'V'   ? 'selected' : '' ?>>В (раз в год)</option>
          </select>
        </div>
      </div>

      <div style="margin-top:16px">
        <button type="submit"
                style="padding:12px 26px;border:none;border-radius:10px;background:#2563eb;color:#fff;font-weight:700;cursor:pointer;font-size:14.5px">
          Определить ТО →
        </button>
      </div>
    </form>

    <?php if ($complectation && !$matrix): ?>
      <div style="background:rgba(254,226,226,0.8);padding:14px 18px;border-radius:12px;margin-bottom:18px;color:#991b1b">
        Комплектация <b><?= e($complectation) ?></b> не найдена в базе.
      </div>
    <?php endif; ?>

    <?php if ($matrix && $hasInput): ?>
      <div style="background:rgba(255,255,255,0.85);padding:22px 26px;border-radius:18px;margin-bottom:20px">
        <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:12px;align-items:baseline;margin-bottom:14px">
          <h2 style="margin:0;font-size:17px">Что пора делать</h2>
          <div style="font-size:13px;color:#64748b">
            с последнего ТО: <b><?= number_format($kmSince, 0, ',', ' ') ?> км</b>
            <?php if ($monthsSince !== null): ?>
              · <b><?= (int)$monthsSince ?> мес.</b>
            <?php endif; ?>
          </div>
        </div>

        <?php if (!$recommendations): ?>
          <div style="padding:16px 18px;border-radius:12px;background:rgba(220,252,231,0.7);color:#166534;font-size:14.5px">
            ✅ <b>Все интервалы в норме.</b>
            До ближайшего ПТО осталось <?= number_format(120000 - $kmSince, 0, ',', ' ') ?> км
            <?php if ($monthsSince !== null && $monthsSince < 12): ?>
              или <?= 12 - (int)$monthsSince ?> мес.
            <?php endif; ?>
          </div>
        <?php else: ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:14px">
            <?php foreach ($recommendations as $code => $r):
              $label = $TO_LABELS[$code] ?? $code;
              $hasNorm = isset($norms[$code]);
            ?>
              <div style="padding:16px 18px;border-radius:14px;background:#fff7ed;border:1.5px solid #fdba74">
                <div style="font-size:15.5px;font-weight:700;margin-bottom:8px;color:#9a3412">
                  ⚠️ <?= e($label) ?>
                </div>
                <div style="font-size:13px;color:#7c2d12;line-height:1.55">
                  <?php if ($r['by_km']): ?>
                    • По пробегу: <b><?= number_format($r['km_since'], 0, ',', ' ') ?> км</b>
                      (норма <?= number_format($r['km_need'], 0, ',', ' ') ?> км)<br>
                  <?php endif; ?>
                  <?php if ($r['by_time']): ?>
                    • По сроку: <b><?= (int)$r['months_since'] ?> мес.</b>
                      (норма <?= (int)$r['months_need'] ?> мес.)<br>
                  <?php endif; ?>
                  <?php if ($hasNorm): ?>
                    • Трудоёмкость: <b><?= number_format($norms[$code], 3, ',', ' ') ?> чел/ч</b>
                  <?php else: ?>
                    • <span style="color:#94a3b8">норматив не задан</span>
                  <?php endif; ?>
                </div>
                <a href="?vin=<?= urlencode($vin) ?>&c=<?= urlencode($complectation) ?>&to=<?= urlencode($code) ?>&mileage=<?= $mileage ?>&last_mileage=<?= $lastMileage ?>&last_date=<?= urlencode($lastDate) ?>"
                   style="display:inline-block;margin-top:12px;padding:8px 14px;border-radius:9px;background:#ea580c;color:#fff;text-decoration:none;font-weight:600;font-size:13px">
                  Открыть карточку →
                </a>
              </div>
            <?php endforeach; ?>
          </div>

          <?php if ($lastToLabel === ''): ?>
            <div style="margin-top:14px;padding:12px 16px;border-radius:10px;background:rgba(254,249,195,0.7);font-size:13px;color:#713f12">
              💡 Если это <b>не первое</b> ТО — откройте дополнительно <b>А2</b>, <b>А3</b> или <b>В2/В3/В4</b> из списка ниже.
            </div>
          <?php elseif ($lastToLabel === 'PTO'): ?>
            <div style="margin-top:14px;padding:12px 16px;border-radius:10px;background:rgba(254,249,195,0.7);font-size:13px;color:#713f12">
              💡 Возможно, пора добавить <b>А2</b> или <b>А3</b>.
            </div>
          <?php endif; ?>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($matrix && !$toCode): ?>
      <div style="background:rgba(255,255,255,0.85);padding:22px 26px;border-radius:18px;margin-bottom:20px">
        <h2 style="margin:0 0 6px;font-size:17px">Или выберите ТО вручную</h2>
        <p style="margin:0 0 16px;color:#64748b;font-size:13.5px">
          Комплектация <b><?= e($matrix['complectation']) ?></b>
          <?php if ($matrix['model']): ?>· модель <b><?= e($matrix['model']) ?></b><?php endif; ?>
        </p>
        <?php if (!$norms): ?>
          <p style="color:#64748b">Нормы для этой матрицы не найдены.</p>
        <?php else: ?>
          <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(200px,1fr));gap:10px">
            <?php foreach ($TO_LABELS as $code => $lbl): if (!isset($norms[$code])) continue; ?>
              <a href="?vin=<?= urlencode($vin) ?>&c=<?= urlencode($complectation) ?>&to=<?= urlencode($code) ?>"
                 style="display:block;padding:14px 16px;border-radius:12px;background:#f8fafc;border:1px solid #e2e8f0;text-decoration:none;color:#0f172a">
                <div style="font-size:12.5px;color:#64748b"><?= e($lbl) ?></div>
                <div style="font-size:20px;font-weight:700;margin-top:4px">
                  <?= number_format($norms[$code], 3, ',', ' ') ?> <span style="font-size:13px;font-weight:400;color:#64748b">чел/ч</span>
                </div>
              </a>
            <?php endforeach; ?>
          </div>
        <?php endif; ?>
      </div>
    <?php endif; ?>

    <?php if ($matrix && $toCode !== ''): ?>
      <?php $label = $TO_LABELS[$toCode] ?? $toCode; ?>

      <div style="background:rgba(255,255,255,0.85);padding:22px 26px;border-radius:18px;margin-bottom:18px">
        <div style="font-size:13px;color:#64748b;margin-bottom:2px">
          <?= e($matrix['complectation']) ?>
          <?php if ($vin): ?> · VIN <b><?= e($vin) ?></b><?php endif; ?>
        </div>
        <div style="display:flex;justify-content:space-between;flex-wrap:wrap;gap:14px;align-items:center">
          <div style="font-size:22px;font-weight:700"><?= e($label) ?></div>
          <?php if (isset($norms[$toCode])): ?>
            <div style="text-align:right">
              <div style="font-size:12.5px;color:#64748b">Норматив трудоёмкости</div>
              <div style="font-size:24px;font-weight:700;color:#2563eb">
                <?= number_format($norms[$toCode], 3, ',', ' ') ?> <span style="font-size:14px;font-weight:400;color:#64748b">чел/ч</span>
              </div>
            </div>
          <?php endif; ?>
        </div>
      </div>

      <?php if (!$items): ?>
        <div style="background:rgba(255,255,255,0.85);padding:20px 24px;border-radius:18px;color:#64748b">
          Для этого вида ТО в матрице нет запчастей/материалов.
        </div>
      <?php else: ?>
        <div style="background:rgba(255,255,255,0.85);padding:20px 24px;border-radius:18px">
          <h3 style="margin:0 0 14px;font-size:16px">Материалы и запчасти (<?= count($items) ?>)</h3>
          <table style="width:100%;border-collapse:collapse;font-size:14px">
            <thead>
              <tr style="text-align:left;color:#64748b;border-bottom:1px solid #e2e8f0">
                <th style="padding:8px 6px;width:40px">#</th>
                <th style="padding:8px 6px">Наименование</th>
                <th style="padding:8px 6px;width:170px">Артикул</th>
                <th style="padding:8px 6px;width:60px">Ед.</th>
                <th style="padding:8px 6px;width:90px;text-align:right">Кол-во</th>
              </tr>
            </thead>
            <tbody>
              <?php foreach ($items as $it): ?>
                <?php
                  $q = json_decode($it['quantities'], true);
                  $qty = $q[$toCode] ?? null;
                ?>
                <tr style="border-bottom:1px solid #f1f5f9">
                  <td style="padding:9px 6px;color:#94a3b8"><?= (int)$it['row_num'] ?></td>
                  <td style="padding:9px 6px"><?= e($it['name']) ?></td>
                  <td style="padding:9px 6px;font-family:ui-monospace,Menlo,monospace;font-size:13px"><?= e($it['article']) ?></td>
                  <td style="padding:9px 6px;color:#64748b"><?= e($it['unit']) ?></td>
                  <td style="padding:9px 6px;text-align:right;font-weight:600">
                    <?= $qty !== null ? number_format($qty, 3, ',', ' ') : '—' ?>
                  </td>
                </tr>
              <?php endforeach; ?>
            </tbody>
          </table>
        </div>
      <?php endif; ?>

      <div style="margin-top:16px">
        <a href="?vin=<?= urlencode($vin) ?>&c=<?= urlencode($complectation) ?>"
           style="display:inline-block;padding:9px 16px;border-radius:10px;background:#f1f5f9;color:#0f172a;text-decoration:none;font-weight:600;font-size:13.5px">
          ← К списку ТО
        </a>
      </div>
    <?php endif; ?>

  <?php endif; ?>

</div>

<script>
(function() {
  var btn    = document.getElementById('vinLookupBtn');
  var input  = document.getElementById('vinInput');
  var status = document.getElementById('vinStatus');
  var select = document.getElementById('complectationSelect');
  if (!btn || !input || !status || !select) return;

  btn.addEventListener('click', function() {
    var vin = (input.value || '').trim().toUpperCase();
    if (vin.length < 8) {
      status.innerHTML = '<span style="color:#b91c1c">Введите VIN (минимум 8 символов)</span>';
      return;
    }

    btn.disabled = true;
    btn.textContent = '⏳ Ищу…';
    status.innerHTML = '<span style="color:#64748b">Запрос в 1С:ГОА…</span>';

    fetch('to_vin_lookup.php?vin=' + encodeURIComponent(vin), { credentials: 'same-origin' })
      .then(function(r) { return r.json(); })
      .then(function(data) {
        btn.disabled = false;
        btn.textContent = '🔍 Запросить 1С';

        if (!data.ok) {
          status.innerHTML = '<span style="color:#b91c1c">❌ ' + escapeHtml(data.error || 'Ошибка') + '</span>';
          return;
        }

        var complectation = (data.complectation || '').trim();
        var model = (data.model || '').trim();
        var car = data.car || {};
        var carLine = '';
        if (car.ShassisModel) carLine = ' · модель: ' + escapeHtml(car.ShassisModel);

        if (data.exact_match) {
          // Точное совпадение — подставляем в select
          var found = false;
          for (var i = 0; i < select.options.length; i++) {
            if (select.options[i].value === complectation) {
              select.selectedIndex = i;
              found = true;
              break;
            }
          }
          status.innerHTML =
            '<span style="color:#166534">✅ Комплектация из 1С: <b>' + escapeHtml(complectation) + '</b>' + carLine + '</span>' +
            (found ? '<br><span style="color:#64748b">подставлено в список</span>' : '');
        } else if (data.matrix_matches && data.matrix_matches.length > 0) {
          // Точной нет, но по модели есть матрицы
          var list = data.matrix_matches.map(function(m) { return m.complectation; }).join(', ');
          status.innerHTML =
            '<span style="color:#92400e">⚠️ 1С отдал: <b>' + escapeHtml(complectation) + '</b>' + carLine + '</span>' +
            '<br><span style="color:#64748b">Такой матрицы в базе нет. По модели <b>' + escapeHtml(model) + '</b> доступны: ' + escapeHtml(list) + '</span>';
        } else {
          status.innerHTML =
            '<span style="color:#92400e">⚠️ 1С отдал: <b>' + escapeHtml(complectation || '—') + '</b>' + carLine + '</span>' +
            '<br><span style="color:#64748b">Матрицы для этой комплектации в базе нет. Выберите вручную из списка ниже.</span>';
        }
      })
      .catch(function(err) {
        btn.disabled = false;
        btn.textContent = '🔍 Запросить 1С';
        status.innerHTML = '<span style="color:#b91c1c">❌ Ошибка сети: ' + escapeHtml(String(err)) + '</span>';
      });
  });

  // Если пользователь меняет VIN — очищаем статус
  input.addEventListener('input', function() {
    status.innerHTML = '';
  });

  function escapeHtml(s) {
    var d = document.createElement('div');
    d.textContent = s == null ? '' : String(s);
    return d.innerHTML;
  }
})();
</script>

</body>
</html>
