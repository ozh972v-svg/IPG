<?php
require_once __DIR__ . '/db.php';
start_session();
$user = current_user();

$models = [
    '5'  => ['chassis' => '43085', 'name' => 'Компас 5'],
    '6'  => ['chassis' => '43086', 'name' => 'Компас 6'],
    '9'  => ['chassis' => '43089', 'name' => 'Компас 9'],
    '12' => ['chassis' => '43082', 'name' => 'Компас 12'],
];

$modelKey = isset($_GET['model']) && isset($models[$_GET['model']]) ? $_GET['model'] : '5';
$chassis  = $models[$modelKey]['chassis'];
$modelName = $models[$modelKey]['name'];

$db = get_db();
$search = trim($_GET['q'] ?? '');

$st = $db->prepare("SELECT code, name FROM work_operations
                    WHERE brand = 'COMPASS' AND complectation = :ch AND it_is_group = TRUE
                    ORDER BY name");
$st->execute([':ch' => $chassis]);
$groups = $st->fetchAll(PDO::FETCH_ASSOC);

$group = $_GET['group'] ?? ($groups[0]['code'] ?? null);

if ($search !== '') {
    $st = $db->prepare("SELECT code, operation_code, name, norm_time
                        FROM work_operations
                        WHERE brand = 'COMPASS' AND complectation = :ch AND it_is_group = FALSE
                          AND (name ILIKE :q OR operation_code ILIKE :q)
                        ORDER BY operation_code
                        LIMIT 500");
    $st->execute([':ch' => $chassis, ':q' => '%' . $search . '%']);
    $works = $st->fetchAll(PDO::FETCH_ASSOC);
} else if ($group) {
    $st = $db->prepare("SELECT code, operation_code, name, norm_time
                        FROM work_operations
                        WHERE brand = 'COMPASS' AND complectation = :ch AND it_is_group = FALSE
                          AND parent_code = :parent
                        ORDER BY operation_code");
    $st->execute([':ch' => $chassis, ':parent' => $group]);
    $works = $st->fetchAll(PDO::FETCH_ASSOC);
} else {
    $works = [];
}

$groupName = '';
foreach ($groups as $g) {
    if ($g['code'] === $group) { $groupName = $g['name']; break; }
}

function fmtNorm($n) {
    if ($n === null) return null;
    return rtrim(rtrim(number_format((float)$n, 3, ',', ' '), '0'), ',');
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Справочник работ — КОМПАС</title>
<link rel="stylesheet" href="app.css">
<?php include __DIR__ . '/pwa.php'; ?>    
<style>
  /* === Локальные стили для КОМПАСа === */

  body { padding: 24px 18px; }
  .container { max-width: 1500px; }

  /* Подсказка */
  .hint {
    background: linear-gradient(135deg, #f8fafc, #f1f5f9);
    border-left: 3px solid var(--violet);
    border-radius: 12px;
    padding: 14px 18px;
    font-size: 13.5px;
    color: #475569;
    line-height: 1.6;
    margin-bottom: 18px;
  }
  .hint b { color: #5b21b6; }

  /* Кнопки моделей */
  .model-bar {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(160px, 1fr));
    gap: 12px;
    margin-bottom: 20px;
  }
  .model-btn {
    position: relative;
    padding: 16px 20px;
    background: rgba(255,255,255,0.85);
    backdrop-filter: blur(10px);
    -webkit-backdrop-filter: blur(10px);
    border: 1.5px solid rgba(255,255,255,0.9);
    border-radius: 16px;
    text-decoration: none;
    color: var(--ink);
    font-weight: 600;
    font-size: 15px;
    text-align: center;
    transition: all 0.25s cubic-bezier(0.16, 1, 0.3, 1);
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 8px;
    overflow: hidden;
    box-shadow:
      0 1px 0 rgba(255,255,255,0.9) inset,
      0 6px 18px rgba(15, 23, 42, 0.05);
  }
  .model-btn:hover {
    transform: translateY(-3px);
    border-color: #c4b5fd;
    background: #fff;
    box-shadow: 0 12px 28px -8px rgba(124, 58, 237, 0.25);
  }
  .model-btn.active {
    background: linear-gradient(135deg, #7c3aed 0%, #c026d3 100%);
    color: #fff;
    border-color: transparent;
    box-shadow:
      0 1px 0 rgba(255,255,255,0.35) inset,
      0 14px 30px -10px rgba(124, 58, 237, 0.7);
  }
  .model-btn.active::after {
    content: '✓';
    font-size: 14px;
    font-weight: 800;
  }

  /* Поиск */
  .search-row {
    display: flex;
    gap: 10px;
    align-items: center;
    margin-bottom: 20px;
    flex-wrap: wrap;
  }
  .search-box { flex: 1; min-width: 240px; }
  .search-box input {
    width: 100%;
    padding: 12px 18px;
    font-size: 15px;
    border: 1.5px solid #e2e8f0;
    border-radius: 12px;
    background: #fff;
    outline: none;
    font-family: inherit;
    transition: all 0.15s;
  }
  .search-box input:focus {
    border-color: var(--violet);
    box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
  }

  /* Раскладка */
  .layout {
    display: grid;
    grid-template-columns: 280px 1fr 340px;
    gap: 14px;
    align-items: start;
  }
  @media (max-width: 1200px) {
    .layout { grid-template-columns: 260px 1fr; }
    .basket { grid-column: 1 / -1; }
  }
  @media (max-width: 800px) {
    .layout { grid-template-columns: 1fr; }
    .basket { grid-column: 1; }
  }

  /* Сайдбар */
  .sidebar {
    max-height: 75vh;
    overflow-y: auto;
    padding: 8px;
  }
  .sidebar a {
    display: block;
    padding: 10px 14px;
    border-radius: 9px;
    text-decoration: none;
    color: #334155;
    font-size: 14px;
    line-height: 1.35;
    transition: all 0.15s;
  }
  .sidebar a:hover { background: #f8fafc; color: var(--violet); }
  .sidebar a.active {
    background: linear-gradient(135deg, #ede9fe, #ddd6fe);
    color: #5b21b6;
    font-weight: 700;
  }

  /* Контент */
  .content {
    padding: 22px 26px;
    min-height: 300px;
  }
  .content h2 {
    margin: 0 0 6px;
    font-size: 18px;
    font-weight: 700;
    letter-spacing: -0.01em;
    color: var(--ink);
  }
  .content .model-tag {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    background: linear-gradient(135deg, #ede9fe, #ddd6fe);
    color: #5b21b6;
    font-size: 11.5px;
    font-weight: 700;
    padding: 4px 11px;
    border-radius: 20px;
    margin-left: 8px;
    vertical-align: middle;
    letter-spacing: 0.02em;
  }
  .content .count {
    color: var(--ink-soft);
    font-size: 13px;
    margin: 8px 0 18px;
  }

  /* Таблица */
  table.works {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
  }
  table.works th, table.works td {
    padding: 11px 10px;
    border-bottom: 1px solid rgba(15,23,42,0.05);
    text-align: left;
    vertical-align: top;
  }
  table.works th {
    font-weight: 600;
    color: #64748b;
    font-size: 11.5px;
    text-transform: uppercase;
    letter-spacing: 0.4px;
    background: #f8fafc;
    border-bottom: 2px solid rgba(15,23,42,0.08);
  }
  table.works tr:hover td { background: #f8fafc; }
  table.works tr:last-child td { border-bottom: none; }
  table.works .code {
    font-family: 'SF Mono', Consolas, monospace;
    color: #475569;
    white-space: nowrap;
    font-size: 13px;
    font-weight: 600;
  }
  table.works .norm {
    white-space: nowrap;
    text-align: right;
    font-variant-numeric: tabular-nums;
    font-weight: 700;
    color: #075985;
  }
  .empty {
    color: #94a3b8;
    padding: 60px 20px;
    text-align: center;
    font-size: 14px;
  }

  /* Кнопка добавить */
  .add-btn {
    background: linear-gradient(135deg, #059669, #10b981);
    color: #fff;
    border: none;
    padding: 6px 12px;
    border-radius: 8px;
    font-size: 13px;
    font-weight: 700;
    cursor: pointer;
    font-family: inherit;
    white-space: nowrap;
    transition: all 0.18s;
    box-shadow: 0 4px 10px -4px rgba(5, 150, 105, 0.5);
  }
  .add-btn:hover {
    transform: translateY(-1px);
    box-shadow: 0 8px 16px -6px rgba(5, 150, 105, 0.7);
  }
  .add-btn.in-basket {
    background: #cbd5e1;
    box-shadow: none;
    cursor: default;
    transform: none;
  }

  /* Корзина */
  .basket {
    position: sticky;
    top: 16px;
    max-height: calc(100vh - 32px);
    overflow-y: auto;
    padding: 18px;
  }
  .basket-header {
    display: flex;
    justify-content: space-between;
    align-items: center;
    margin-bottom: 14px;
  }
  .basket-header h2 {
    margin: 0;
    font-size: 16px;
    font-weight: 700;
    color: #5b21b6;
  }
  .basket-count {
    background: linear-gradient(135deg, #7c3aed, #c026d3);
    color: #fff;
    font-size: 12px;
    font-weight: 700;
    padding: 3px 12px;
    border-radius: 12px;
    box-shadow: 0 4px 10px -4px rgba(124, 58, 237, 0.5);
  }
  .basket-total {
    background: linear-gradient(135deg, #ede9fe, #ddd6fe);
    border-radius: 10px;
    padding: 12px 14px;
    margin-bottom: 12px;
    font-size: 13px;
    color: #5b21b6;
  }
  .basket-total b { font-size: 18px; }
  .basket-list { list-style: none; padding: 0; margin: 0; }
  .basket-item {
    border-bottom: 1px solid rgba(15,23,42,0.06);
    padding: 11px 0;
    display: flex;
    gap: 8px;
    font-size: 12.5px;
  }
  .basket-item:last-child { border-bottom: none; }
  .basket-item-content { flex: 1; min-width: 0; }
  .basket-item-op {
    font-family: 'SF Mono', Consolas, monospace;
    font-size: 11px;
    font-weight: 700;
    color: #92400e;
    background: #fef3c7;
    padding: 2px 7px;
    border-radius: 5px;
    display: inline-block;
    margin-bottom: 4px;
  }
  .basket-item-name { color: var(--ink); line-height: 1.35; word-wrap: break-word; }
  .basket-item-norm { color: #075985; font-weight: 700; margin-top: 4px; font-size: 11px; }
  .basket-item-del {
    color: #dc2626;
    font-size: 20px;
    cursor: pointer;
    padding: 0 6px;
    line-height: 1;
    user-select: none;
    border-radius: 6px;
    transition: background 0.15s;
  }
  .basket-item-del:hover { background: #fef2f2; }
  .basket-actions {
    display: flex;
    gap: 8px;
    flex-wrap: wrap;
    margin-top: 12px;
  }
  .basket-actions .btn {
    flex: 1;
    min-width: 100px;
    font-size: 12.5px;
    padding: 9px 10px;
  }

  /* AI-модалка */
  .ai-modal { max-width: 800px; }
  .ai-modal h3 {
    margin: 0 0 10px;
    font-size: 18px;
    color: #5b21b6;
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .ai-context {
    font-size: 12px;
    color: #64748b;
    background: #f8fafc;
    padding: 8px 12px;
    border-radius: 8px;
    margin-bottom: 14px;
    display: inline-block;
  }
  .ai-modal textarea {
    width: 100%;
    padding: 14px 16px;
    border: 1.5px solid #c4b5fd;
    border-radius: 12px;
    font-family: inherit;
    font-size: 15px;
    min-height: 80px;
    resize: vertical;
    background: #f5f3ff;
    transition: all 0.15s;
  }
  .ai-modal textarea:focus {
    outline: none;
    border-color: var(--violet);
    background: #fff;
    box-shadow: 0 0 0 4px rgba(124, 58, 237, 0.1);
  }
  .ai-row {
    display: flex;
    gap: 12px;
    flex-wrap: wrap;
    margin-top: 12px;
    align-items: center;
  }
  .ai-answer {
    background: linear-gradient(135deg, #f0fdf4, #dcfce7);
    border-left: 4px solid #10b981;
    padding: 16px 18px;
    border-radius: 12px;
    margin-top: 18px;
    white-space: pre-wrap;
    font-size: 14px;
    line-height: 1.65;
    display: none;
  }
  .ai-error {
    background: linear-gradient(135deg, #fef2f2, #fee2e2);
    border-left: 4px solid #ef4444;
    padding: 16px 18px;
    border-radius: 12px;
    margin-top: 18px;
    font-size: 14px;
    color: #991b1b;
    display: none;
  }
  .ai-loading { color: var(--violet); font-size: 14px; display: none; }

  /* Сообщение о копировании */
  .copy-msg {
    position: fixed;
    bottom: 24px;
    left: 50%;
    transform: translateX(-50%);
    background: linear-gradient(135deg, #7c3aed, #c026d3);
    color: #fff;
    padding: 14px 26px;
    border-radius: 12px;
    font-size: 14px;
    font-weight: 600;
    z-index: 2000;
    opacity: 0;
    transition: opacity 0.3s;
    pointer-events: none;
    box-shadow: 0 12px 30px -8px rgba(124, 58, 237, 0.6);
  }
  .copy-msg.show { opacity: 1; }

  @media (max-width: 640px) {
    body { padding: 14px 12px; }
    .layout { gap: 10px; }
    .model-bar { grid-template-columns: repeat(2, 1fr); gap: 8px; }
    .model-btn { padding: 12px 14px; font-size: 14px; }
    table.works { font-size: 13px; }
  }
</style>
</head>
<body>

<div class="container">

  <?php
    $pageTitle    = 'Справочник работ';
    $pageSubtitle = 'КОМПАС · 1С:ГОА';
    $backLink     = 'works_brand.php';
    $backLabel    = 'К выбору марки';
    include __DIR__ . '/header.php';
  ?>

  <div class="hint">
    <b>Шаг 1.</b> Выберите модель Компаса кнопками ниже (5 / 6 / 9 / 12).<br>
    <b>Шаг 2.</b> Слева выберите группу работ — работы появятся справа.<br>
    <b>Шаг 3.</b> Нажмите <b>🤖 Спросить ИИ</b> — вопрос будет искаться в справочнике выбранной модели.
    Если модель не выбрана, поиск пойдёт по Компасу 5 (по умолчанию).
  </div>

  <!-- Кнопки моделей -->
  <div class="model-bar">
    <?php foreach ($models as $key => $m): ?>
      <?php $isActive = ((string)$key === (string)$modelKey); ?>
      <a href="?model=<?= e($key) ?>" class="model-btn <?= $isActive ? 'active' : '' ?>">
        <?= e($m['name']) ?>
      </a>
    <?php endforeach; ?>
  </div>

  <div class="search-row">
    <form class="search-box" method="get">
      <input type="hidden" name="model" value="<?= e($modelKey) ?>">
      <input type="text" name="q" id="compassQ" value="<?= e($search) ?>"
             placeholder="Поиск по названию работы или коду (например, «масляный фильтр» или LT1004210)">
    </form>
    <button type="button" class="btn btn-ai" onclick="openAiModal(document.getElementById('compassQ').value)">🤖 Спросить ИИ</button>
  </div>

  <div class="layout">

    <aside class="card sidebar" style="padding:8px;">
      <?php if (!$groups): ?>
        <div style="padding: 20px; color:#94a3b8;">Групп не найдено.</div>
      <?php else: ?>
        <?php foreach ($groups as $g): ?>
          <a href="?model=<?= e($modelKey) ?>&group=<?= urlencode($g['code']) ?>"
             class="<?= $g['code'] === $group && $search === '' ? 'active' : '' ?>">
            <?= e($g['name']) ?>
          </a>
        <?php endforeach; ?>
      <?php endif; ?>
    </aside>

    <section class="card content">
      <?php if ($search !== ''): ?>
        <h2>
          Результаты поиска: «<?= e($search) ?>»
          <span class="model-tag"><?= e($modelName) ?></span>
        </h2>
        <p class="count">Найдено: <?= count($works) ?></p>
      <?php elseif ($group): ?>
        <h2>
          <?= e($groupName) ?>
          <span class="model-tag"><?= e($modelName) ?></span>
        </h2>
        <p class="count">Работ в группе: <?= count($works) ?></p>
      <?php else: ?>
        <h2>Работы <span class="model-tag"><?= e($modelName) ?></span></h2>
        <p class="count">Выберите группу слева.</p>
      <?php endif; ?>

      <?php if (!$works): ?>
        <div class="empty">Ничего не найдено.</div>
      <?php else: ?>
        <table class="works">
          <thead>
            <tr>
              <th style="width:120px;">Код</th>
              <th>Наименование работы</th>
              <th style="width:90px;text-align:right;">Н/ч</th>
              <th style="width:60px;"></th>
            </tr>
          </thead>
          <tbody>
            <?php foreach ($works as $w): ?>
              <tr>
                <td class="code"><?= e($w['operation_code']) ?></td>
                <td><?= e($w['name']) ?></td>
                <td class="norm">
                  <?= $w['norm_time'] !== null ? e(fmtNorm($w['norm_time'])) : '—' ?>
                </td>
                <td>
                  <button type="button" class="add-btn"
                    data-code="<?= e($w['code']) ?>"
                    data-op="<?= e($w['operation_code']) ?>"
                    data-name="<?= e($w['name']) ?>"
                    data-norm="<?= e((string)$w['norm_time']) ?>">➕</button>
                </td>
              </tr>
            <?php endforeach; ?>
          </tbody>
        </table>
      <?php endif; ?>
    </section>

    <div class="card basket">
      <div class="basket-header">
        <h2>📋 Выбранные работы</h2>
        <span class="basket-count" id="basketCount">0</span>
      </div>
      <div class="basket-total">Суммарная норма: <b id="basketTotal">0,00</b> ч</div>
      <ul class="basket-list" id="basketList">
        <li style="text-align:center;color:#94a3b8;padding:24px 0;font-size:13px;">Пока ничего не выбрано.<br>Нажми ➕ у работы.</li>
      </ul>
      <div class="basket-actions" id="basketActions" style="display:none;">
        <button class="btn btn-green btn-small" onclick="basketCopy()">📋 Копировать</button>
        <button class="btn btn-secondary btn-small" onclick="basketDownload()">💾 Скачать</button>
        <button class="btn btn-red btn-small" onclick="basketClear()">🗑️ Очистить</button>
      </div>
    </div>

  </div>
</div>

<div class="modal-overlay" id="aiModal">
  <div class="modal ai-modal">
    <h3>🤖 Помощник ИИ <button type="button" class="btn btn-secondary btn-small" onclick="closeAiModal()" style="margin-left:auto;">✕</button></h3>
    <div class="ai-context" id="aiContext">Без контекста</div>
    <textarea id="aiQuestion" placeholder="Например: найди работу по замене генератора"></textarea>
    <div class="ai-row">
      <button type="button" class="btn btn-ai" id="aiAskBtn" onclick="askAi()">🤖 Спросить ИИ</button>
      <span class="ai-loading" id="aiLoading">⏳ Думаю… 5–20 сек.</span>
    </div>
    <div class="ai-answer" id="aiAnswer"></div>
    <div class="ai-error" id="aiError"></div>
  </div>
</div>

<div class="copy-msg" id="copyMsg">✅ Скопировано в буфер</div>

<script>
const BASKET_KEY = 'works_basket_compass_v1';
let basket = [];

function basketLoad() {
  try { basket = JSON.parse(localStorage.getItem(BASKET_KEY) || '[]'); }
  catch(e) { basket = []; }
  if (!Array.isArray(basket)) basket = [];
}
function basketSave() { localStorage.setItem(BASKET_KEY, JSON.stringify(basket)); }
function escapeHtml(s) {
  const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML;
}

function basketRender() {
  const list    = document.getElementById('basketList');
  const count   = document.getElementById('basketCount');
  const total   = document.getElementById('basketTotal');
  const actions = document.getElementById('basketActions');

  count.textContent = basket.length;
  let sum = 0;
  basket.forEach(b => { if (b.norm) sum += parseFloat(b.norm); });
  total.textContent = sum.toFixed(2).replace('.', ',');

  if (basket.length === 0) {
    list.innerHTML = '<li style="text-align:center;color:#94a3b8;padding:24px 0;font-size:13px;">Пока ничего не выбрано.<br>Нажми ➕ у работы.</li>';
    actions.style.display = 'none';
    document.querySelectorAll('.add-btn').forEach(b => b.classList.remove('in-basket'));
    return;
  }
  actions.style.display = 'flex';
  list.innerHTML = basket.map((b, i) => `
    <li class="basket-item">
      <div class="basket-item-content">
        ${b.op ? `<div class="basket-item-op">${escapeHtml(b.op)}</div>` : ''}
        <div class="basket-item-name">${escapeHtml(b.name || '')}</div>
        ${b.norm ? `<div class="basket-item-norm">${escapeHtml(b.norm)} ч</div>` : ''}
      </div>
      <span class="basket-item-del" onclick="basketRemove(${i})">×</span>
    </li>
  `).join('');

  const codes = new Set(basket.map(b => b.code));
  document.querySelectorAll('.add-btn').forEach(btn => {
    if (codes.has(btn.dataset.code)) btn.classList.add('in-basket');
    else btn.classList.remove('in-basket');
  });
}

function basketAdd(item) {
  if (basket.some(b => b.code === item.code)) return false;
  basket.push(item); basketSave(); basketRender(); return true;
}
function basketRemove(i) { basket.splice(i, 1); basketSave(); basketRender(); }
function basketClear() {
  if (!confirm('Очистить все выбранные работы?')) return;
  basket = []; basketSave(); basketRender();
}
function basketCopy() {
  const text = basket.map(b => {
    const op = b.op ? `[${b.op}] ` : '';
    const n  = b.norm ? ` (${b.norm} ч)` : '';
    return op + b.name + n;
  }).join('\n');
  navigator.clipboard.writeText(text).then(() => showMsg('✅ Скопировано в буфер'));
}
function basketDownload() {
  const text = basket.map(b => [b.op || '', b.name || '', b.norm || ''].join('\t')).join('\n');
  const blob = new Blob([text], {type: 'text/plain;charset=utf-8'});
  const url  = URL.createObjectURL(blob);
  const a    = document.createElement('a');
  a.href = url;
  a.download = 'compass_works_' + new Date().toISOString().slice(0,10) + '.txt';
  a.click();
  URL.revokeObjectURL(url);
}
function showMsg(text) {
  const el = document.getElementById('copyMsg');
  el.textContent = text;
  el.classList.add('show');
  setTimeout(() => el.classList.remove('show'), 2000);
}

document.addEventListener('click', function(e) {
  if (!e.target.classList.contains('add-btn')) return;
  const btn = e.target;
  const item = {
    code: btn.dataset.code, op: btn.dataset.op,
    name: btn.dataset.name, norm: btn.dataset.norm || null
  };
  if (basket.some(b => b.code === item.code)) { showMsg('Уже в корзине'); return; }
  basketAdd(item);
});

/* ==== AI ==== */
const AI_CONTEXT = {
  chassis: <?= json_encode($chassis) ?>,
  model:   <?= json_encode($modelName) ?>,
  brand:   'COMPASS'
};

function openAiModal(initialQ) {
  const modal = document.getElementById('aiModal');
  const ctx   = document.getElementById('aiContext');
  const q     = document.getElementById('aiQuestion');
  const a     = document.getElementById('aiAnswer');
  const e     = document.getElementById('aiError');

  ctx.textContent = 'Контекст: ' + AI_CONTEXT.model + ' · Шасси ' + AI_CONTEXT.chassis;
  q.value = initialQ || '';
  a.style.display = 'none'; a.textContent = '';
  e.style.display = 'none'; e.textContent = '';
  modal.classList.add('active');
  setTimeout(() => q.focus(), 100);
}
function closeAiModal() { document.getElementById('aiModal').classList.remove('active'); }

async function askAi() {
  const q = document.getElementById('aiQuestion').value.trim();
  if (q.length < 3) return;

  const btn  = document.getElementById('aiAskBtn');
  const load = document.getElementById('aiLoading');
  const ans  = document.getElementById('aiAnswer');
  const err  = document.getElementById('aiError');

  btn.disabled = true; btn.textContent = '⏳ Думаю…';
  load.style.display = 'inline';
  ans.style.display = 'none'; err.style.display = 'none';

  try {
    const fd = new FormData();
    fd.append('q', q);
    fd.append('chassis', AI_CONTEXT.chassis);
    fd.append('model',   AI_CONTEXT.model);
    fd.append('brand',   AI_CONTEXT.brand);

    const resp = await fetch('ai_search_ajax.php', { method: 'POST', body: fd });
    const data = await resp.json();
    if (!data.ok) {
      err.textContent = '❌ ' + (data.error || 'Ошибка');
      err.style.display = 'block';
      return;
    }
    ans.textContent = data.answer;
    ans.style.display = 'block';
  } catch (ex) {
    err.textContent = '❌ Ошибка запроса: ' + ex.message;
    err.style.display = 'block';
  } finally {
    btn.disabled = false; btn.textContent = '🤖 Спросить ИИ';
    load.style.display = 'none';
  }
}

basketLoad();
basketRender();
</script>
</body>
</html>
