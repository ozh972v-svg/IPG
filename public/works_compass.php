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
<style>
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        background: #f1f3f6;
        color: #1f2937;
        min-height: 100vh;
    }
    .topbar {
        display: flex; align-items: center; justify-content: space-between;
        padding: 14px 24px; background: #fff; border-bottom: 1px solid #e5e7eb;
        flex-wrap: wrap; gap: 12px;
    }
    .topbar h1 { margin: 0; font-size: 18px; font-weight: 600; }
    .topbar .user {
        display: flex; gap: 12px; align-items: center;
        font-size: 14px; color: #4b5563;
    }
    .topbar .user a { color: #2563eb; text-decoration: none; }

    .container { max-width: 1500px; margin: 0 auto; padding: 24px; }

    .back {
        display: inline-block; margin-bottom: 16px;
        color: #2563eb; text-decoration: none; font-size: 14px;
    }

    .model-bar {
        display: flex; gap: 10px; flex-wrap: wrap; margin-bottom: 20px;
    }
    .model-bar a {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 12px 22px;
        background: #fff;
        border: 2px solid #e5e7eb;
        border-radius: 12px;
        text-decoration: none;
        color: #6b7280;
        font-weight: 500;
        font-size: 15px;
        transition: all .12s;
    }
    .model-bar a:hover {
        border-color: #93c5fd;
        background: #f0f7ff;
        color: #1d4ed8;
    }

    .search-row {
        display: flex; gap: 8px; align-items: center;
        margin-bottom: 20px; flex-wrap: wrap;
    }
    .search-box { flex: 1; min-width: 240px; margin: 0; }
    .search-box input {
        width: 100%;
        padding: 12px 16px;
        font-size: 15px;
        border: 1px solid #e5e7eb;
        border-radius: 10px;
        background: #fff;
        outline: none;
    }
    .search-box input:focus { border-color: #2563eb; }

    .layout {
        display: grid;
        grid-template-columns: 280px 1fr 340px;
        gap: 20px;
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

    .sidebar, .content, .basket {
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        padding: 8px;
        box-shadow: 0 1px 2px rgba(0,0,0,.03);
    }
    .sidebar { max-height: 75vh; overflow-y: auto; }
    .sidebar a {
        display: block;
        padding: 10px 14px;
        border-radius: 8px;
        text-decoration: none;
        color: #1f2937;
        font-size: 14px;
        line-height: 1.35;
    }
    .sidebar a:hover { background: #f3f4f6; }
    .sidebar a.active {
        background: #dbeafe; color: #1d4ed8; font-weight: 600;
    }

    .content { padding: 20px 24px; min-height: 300px; }
    .content h2 {
        margin: 0 0 4px; font-size: 18px; font-weight: 600;
    }
    .content .model-tag {
        display: inline-block;
        background: #dbeafe;
        color: #1d4ed8;
        font-size: 12px;
        font-weight: 700;
        padding: 3px 10px;
        border-radius: 20px;
        margin-left: 8px;
        vertical-align: middle;
        letter-spacing: .02em;
    }
    .content .count { color: #6b7280; font-size: 13px; margin: 8px 0 16px; }

    table.works { width: 100%; border-collapse: collapse; font-size: 14px; }
    table.works th, table.works td {
        padding: 10px 8px;
        border-bottom: 1px solid #f1f5f9;
        text-align: left;
        vertical-align: top;
    }
    table.works th {
        font-weight: 600; color: #6b7280; font-size: 12px;
        text-transform: uppercase; letter-spacing: .03em;
    }
    table.works tr:hover td { background: #f9fafb; }
    table.works .code {
        font-family: ui-monospace, Menlo, monospace;
        color: #4b5563; white-space: nowrap; font-size: 13px;
    }
    table.works .norm {
        white-space: nowrap; text-align: right;
        font-variant-numeric: tabular-nums; font-weight: 600;
    }
    .empty { color: #9ca3af; padding: 40px 0; text-align: center; }

    .add-btn {
        background: #16a34a; color: #fff; border: none;
        padding: 5px 12px; border-radius: 6px;
        font-size: 14px; font-weight: 700; cursor: pointer;
        font-family: inherit; white-space: nowrap;
    }
    .add-btn:hover { background: #15803d; }
    .add-btn.in-basket { background: #9ca3af; cursor: default; }

    .basket { position: sticky; top: 16px; max-height: calc(100vh - 32px); overflow-y: auto; padding: 16px; }
    .basket-header {
        display: flex; justify-content: space-between; align-items: center;
        margin-bottom: 12px;
    }
    .basket-header h2 { margin: 0; font-size: 16px; font-weight: 600; }
    .basket-count {
        background: #2563eb; color: #fff; font-size: 12px; font-weight: 700;
        padding: 2px 10px; border-radius: 12px;
    }
    .basket-total {
        background: #eff6ff; border-radius: 8px; padding: 10px 12px;
        margin-bottom: 12px; font-size: 13px; color: #1e3a8a;
    }
    .basket-total b { font-size: 18px; }
    .basket-list { list-style: none; padding: 0; margin: 0; }
    .basket-item {
        border-bottom: 1px solid #f0f0f0; padding: 10px 0;
        display: flex; gap: 8px; font-size: 12px;
    }
    .basket-item:last-child { border-bottom: none; }
    .basket-item-content { flex: 1; min-width: 0; }
    .basket-item-op {
        font-family: ui-monospace, Menlo, monospace; font-size: 11px; font-weight: 700;
        color: #92400e; background: #fef3c7; padding: 1px 6px;
        border-radius: 4px; display: inline-block; margin-bottom: 3px;
    }
    .basket-item-name { color: #1a1a1a; line-height: 1.3; word-wrap: break-word; }
    .basket-item-norm { color: #075985; font-weight: 700; margin-top: 3px; font-size: 11px; }
    .basket-item-del {
        color: #dc2626; font-size: 20px; cursor: pointer;
        padding: 0 4px; line-height: 1; user-select: none;
    }
    .basket-item-del:hover { background: #fef2f2; border-radius: 4px; }
    .basket-actions { display: flex; gap: 6px; flex-wrap: wrap; margin-top: 12px; }
    .basket-actions .btn {
        flex: 1; min-width: 100px; font-size: 12px; padding: 8px 10px;
    }
    .btn {
        display: inline-block; padding: 9px 14px; border: none; border-radius: 8px;
        font-size: 14px; font-weight: 600; cursor: pointer;
        background: #2563eb; color: #fff; text-decoration: none;
        text-align: center; font-family: inherit;
    }
    .btn:hover { opacity: .9; }
    .btn-green { background: #16a34a; }
    .btn-red { background: #dc2626; }
    .btn-secondary {
        background: #fff; color: #2563eb; border: 1.5px solid #2563eb;
    }
    .copy-msg {
        position: fixed; bottom: 20px; left: 50%; transform: translateX(-50%);
        background: #16a34a; color: #fff; padding: 12px 24px;
        border-radius: 10px; font-size: 14px; font-weight: 600;
        z-index: 2000; opacity: 0; transition: opacity .3s; pointer-events: none;
    }
    .copy-msg.show { opacity: 1; }

    /* === AI-модалка === */
    .ai-btn{background:#7c3aed;color:#fff;border:none;padding:11px 16px;border-radius:10px;font-size:14px;font-weight:600;cursor:pointer;font-family:inherit;white-space:nowrap}
    .ai-btn:hover{opacity:.9}
    .ai-modal-overlay{position:fixed;inset:0;background:rgba(0,0,0,.5);display:none;align-items:center;justify-content:center;z-index:3000;padding:20px}
    .ai-modal-overlay.active{display:flex}
    .ai-modal{background:#fff;border-radius:14px;max-width:800px;width:100%;max-height:85vh;overflow-y:auto;padding:24px}
    .ai-modal h3{margin:0 0 8px;font-size:18px;color:#1e3a8a;display:flex;align-items:center;gap:8px}
    .ai-modal .ai-context{font-size:12px;color:#666;background:#f9fafb;padding:6px 10px;border-radius:6px;margin-bottom:12px;display:inline-block}
    .ai-modal textarea{width:100%;padding:12px;border:1.5px solid #93c5fd;border-radius:10px;font-family:inherit;font-size:15px;min-height:70px;resize:vertical;background:#eff6ff}
    .ai-modal textarea:focus{outline:none;border-color:#2563eb;background:#fff}
    .ai-modal .ai-row{display:flex;gap:10px;flex-wrap:wrap;margin-top:10px;align-items:center}
    .ai-modal .ai-answer{background:#f0fdf4;border-left:4px solid #16a34a;padding:14px 16px;border-radius:10px;margin-top:16px;white-space:pre-wrap;font-size:14px;line-height:1.6;display:none}
    .ai-modal .ai-error{background:#fef2f2;border-left:4px solid #dc2626;padding:14px 16px;border-radius:10px;margin-top:16px;font-size:14px;color:#991b1b;display:none}
    .ai-modal .ai-loading{color:#2563eb;font-size:14px;display:none}
</style>
</head>
<body>

<div class="topbar">
    <h1>🚚 Справочник работ — КОМПАС</h1>
    <div class="user">
        <?php if ($user): ?>
            <span>👤 <?= e($user['name'] ?? $user['login'] ?? 'Пользователь') ?></span>
            <a href="logout.php">Выйти</a>
        <?php else: ?>
            <a href="login.php">Войти</a>
        <?php endif; ?>
    </div>
</div>

<div class="container">
    <a class="back" href="works_brand.php">← К выбору марки</a>

    <!-- Кнопки моделей -->
    <div class="model-bar">
        <?php foreach ($models as $key => $m): ?>
            <?php $isActive = ((string)$key === (string)$modelKey); ?>
            <a href="?model=<?= e($key) ?>"
               style="<?= $isActive
                   ? 'background:#2563eb;color:#ffffff;border-color:#1d4ed8;font-weight:700;box-shadow:0 6px 16px rgba(37,99,235,.4);'
                   : '' ?>">
                <?php if ($isActive): ?>✓ <?php endif; ?><?= e($m['name']) ?>
            </a>
        <?php endforeach; ?>
    </div>

    <div class="search-row">
        <form class="search-box" method="get">
            <input type="hidden" name="model" value="<?= e($modelKey) ?>">
            <input type="text" name="q" id="compassQ" value="<?= e($search) ?>"
                   placeholder="Поиск по названию работы или коду (например, «масляный фильтр» или LT1004210)">
        </form>
        <button type="button" class="ai-btn" onclick="openAiModal(document.getElementById('compassQ').value)">🤖 Спросить ИИ</button>
    </div>

    <div class="layout">

        <aside class="sidebar">
            <?php if (!$groups): ?>
                <div style="padding: 20px; color:#9ca3af;">Групп не найдено.</div>
            <?php else: ?>
                <?php foreach ($groups as $g): ?>
                    <a href="?model=<?= e($modelKey) ?>&group=<?= urlencode($g['code']) ?>"
                       class="<?= $g['code'] === $group && $search === '' ? 'active' : '' ?>">
                        <?= e($g['name']) ?>
                    </a>
                <?php endforeach; ?>
            <?php endif; ?>
        </aside>

        <section class="content">
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
                            <th style="width:110px;">Код</th>
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

        <div class="basket">
            <div class="basket-header">
                <h2>📋 Выбранные работы</h2>
                <span class="basket-count" id="basketCount">0</span>
            </div>
            <div class="basket-total">Суммарная норма: <b id="basketTotal">0,00</b> ч</div>
            <ul class="basket-list" id="basketList">
                <li style="text-align:center;color:#999;padding:24px 0;font-size:13px;">Пока ничего не выбрано.<br>Нажми ➕ у работы.</li>
            </ul>
            <div class="basket-actions" id="basketActions" style="display:none;">
                <button class="btn btn-green" onclick="basketCopy()">📋 Копировать</button>
                <button class="btn btn-secondary" onclick="basketDownload()">💾 Скачать</button>
                <button class="btn btn-red" onclick="basketClear()">🗑️ Очистить</button>
            </div>
        </div>

    </div>
</div>

<!-- AI-модалка -->
<div class="ai-modal-overlay" id="aiModal">
  <div class="ai-modal">
    <h3>🤖 Помощник ИИ <button type="button" class="btn btn-secondary" onclick="closeAiModal()" style="margin-left:auto;padding:6px 12px;">✕</button></h3>
    <div class="ai-context" id="aiContext">Без контекста</div>
    <textarea id="aiQuestion" placeholder="Например: найди работу по замене генератора"></textarea>
    <div class="ai-row">
      <button type="button" class="ai-btn" id="aiAskBtn" onclick="askAi()">🤖 Спросить ИИ</button>
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
        list.innerHTML = '<li style="text-align:center;color:#999;padding:24px 0;font-size:13px;">Пока ничего не выбрано.<br>Нажми ➕ у работы.</li>';
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
    basket.push(item);
    basketSave();
    basketRender();
    return true;
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
        code: btn.dataset.code,
        op:   btn.dataset.op,
        name: btn.dataset.name,
        norm: btn.dataset.norm || null
    };
    if (basket.some(b => b.code === item.code)) { showMsg('Уже в корзине'); return; }
    basketAdd(item);
});

/* ==== AI-модалка ==== */
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

    const parts = [];
    if (AI_CONTEXT.model)   parts.push('Модель: ' + AI_CONTEXT.model);
    if (AI_CONTEXT.chassis) parts.push('Шасси: ' + AI_CONTEXT.chassis);
    ctx.textContent = parts.length ? 'Контекст: ' + parts.join(' · ') : 'Без контекста';

    q.value = initialQ || '';
    a.style.display = 'none'; a.textContent = '';
    e.style.display = 'none'; e.textContent = '';

    modal.classList.add('active');
    setTimeout(() => q.focus(), 100);
}
function closeAiModal() {
    document.getElementById('aiModal').classList.remove('active');
}
async function askAi() {
    const q = document.getElementById('aiQuestion').value.trim();
    if (q.length < 3) return;

    const btn  = document.getElementById('aiAskBtn');
    const load = document.getElementById('aiLoading');
    const ans  = document.getElementById('aiAnswer');
    const err  = document.getElementById('aiError');

    btn.disabled = true; btn.textContent = '⏳ Думаю…';
    load.style.display = 'inline';
    ans.style.display = 'none';
    err.style.display = 'none';

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
