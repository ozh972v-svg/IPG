<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) { header('Location: login.php'); exit; }
$pdo = get_db();

/* ============================================================
   AJAX: обработка вопроса
   ============================================================ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['q'])) {
    header('Content-Type: application/json; charset=utf-8');

    $question = trim((string)$_POST['q']);
    if (mb_strlen($question) < 3) {
        echo json_encode(['ok' => false, 'error' => 'Слишком короткий вопрос']);
        exit;
    }

    /* ---- 1. Ключевые слова ---- */
    $stopWords = [
        'какие','какая','какой','каких','работы','работа','работ','по','на','для','и','в','с','о','к','от','до',
        'а','но','же','ли','бы','не','или','либо','то','это','тот','эта','эти','все','всё','весь',
        'мне','нам','вам','дай','покажи','найди','есть','нет','этот','эту','того','чем','что','как','где',
        'когда','нужно','надо','если','может','можно','авто','автомобиля','автомобиль','тс','список',
    ];

    $textLower = mb_strtolower($question);
    $textClean = preg_replace('/[^\p{L}\p{N}\s]/u', ' ', $textLower);
    $words = preg_split('/\s+/u', $textClean);

    $keywords = [];
    foreach ($words as $w) {
        $w = trim($w);
        if (mb_strlen($w) < 4) continue;
        if (in_array($w, $stopWords, true)) continue;
        $keywords[] = $w;
    }
    $keywords = array_values(array_unique($keywords));

    if (empty($keywords)) {
        echo json_encode(['ok' => false, 'error' => 'Не удалось выделить ключевые слова. Переформулируйте вопрос.']);
        exit;
    }

    /* ---- 2. Бренд ---- */
    $brand = null;
    if (mb_stripos($question, 'компас') !== false) $brand = 'COMPASS';
    if (mb_stripos($question, 'камаз')  !== false) $brand = 'KAMAZ';

    /* ---- 3. Поиск работ ---- */
    $where  = ['it_is_group = FALSE', 'deleted = FALSE', 'operation_code IS NOT NULL'];
    $params = [];

    if ($brand !== null) {
        $where[] = 'brand = :brand';
        $params[':brand'] = $brand;
    }

    $orParts = [];
    foreach ($keywords as $i => $kw) {
        $stem = mb_substr($kw, 0, max(4, mb_strlen($kw) - 2));
        $k1 = ":k{$i}_n"; $k2 = ":k{$i}_e"; $k3 = ":k{$i}_o";
        $orParts[] = "(name ILIKE $k1 OR eng_name ILIKE $k2 OR operation_code ILIKE $k3)";
        $params[$k1] = '%' . $stem . '%';
        $params[$k2] = '%' . $stem . '%';
        $params[$k3] = '%' . $kw . '%';
    }
    $where[] = '(' . implode(' OR ', $orParts) . ')';

    $sql = "SELECT DISTINCT ON (operation_code)
                   operation_code, name, eng_name, norm_time, complectation, brand
              FROM work_operations
             WHERE " . implode(' AND ', $where) . "
             ORDER BY operation_code, LENGTH(name)
             LIMIT 40";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $works = $stmt->fetchAll();

    if (empty($works)) {
        echo json_encode([
            'ok' => true,
            'answer' => 'По вашему запросу в справочнике ничего не найдено. Попробуйте переформулировать или уточнить марку (КАМАЗ / КОМПАС).',
            'works' => [],
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    /* ---- 4. Контекст для LLM ---- */
    $lines = [];
    foreach ($works as $w) {
        $op   = $w['operation_code'] ? '[' . $w['operation_code'] . '] ' : '';
        $n    = $w['norm_time'] !== null ? ' (' . rtrim(rtrim(number_format((float)$w['norm_time'], 3, '.', ' '), '0'), '.') . ' ч)' : '';
        $comp = $w['complectation'] ? ' | компл.: ' . $w['complectation'] : '';
        $brandStr = $w['brand'] ? ' | ' . $w['brand'] : '';
        $lines[] = $op . $w['name'] . $n . $comp . $brandStr;
    }
    $context = implode("\n", $lines);

    /* ---- 5. Запрос к LLM ---- */
    $apiKey  = getenv('AI_API_KEY');
    $baseUrl = rtrim((string)getenv('AI_BASE_URL'), '/');
    $model   = getenv('AI_MODEL') ?: 'deepseek/deepseek-chat';

    if (!$apiKey || !$baseUrl) {
        echo json_encode(['ok' => false, 'error' => 'Не настроены AI_API_KEY / AI_BASE_URL']);
        exit;
    }

    $system = "Ты — помощник мастера-приёмщика сервиса КАМАЗ/КОМПАС.\n"
            . "Отвечай ТОЛЬКО на основе списка работ, который тебе дали ниже.\n"
            . "Если в списке нет ответа на вопрос — честно скажи: «В справочнике таких работ нет».\n"
            . "Не выдумывай коды и названия. Отвечай кратко и по делу, на русском языке.\n"
            . "Формат: сначала короткий ответ, потом (если уместно) список подходящих работ с кодами.";

    $userMsg = "Вопрос пользователя:\n{$question}\n\nРаботы из справочника (только они — источник правды):\n{$context}";

    $payload = [
        'model'       => $model,
        'messages'    => [
            ['role' => 'system', 'content' => $system],
            ['role' => 'user',   'content' => $userMsg],
        ],
        'temperature' => 0.3,
        'max_tokens'  => 1200,
    ];

    $ch = curl_init($baseUrl . '/chat/completions');
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($payload, JSON_UNESCAPED_UNICODE),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $apiKey,
        ],
        CURLOPT_TIMEOUT        => 120,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
    ]);
    $resp = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($err) {
        echo json_encode(['ok' => false, 'error' => 'Ошибка соединения с ИИ: ' . $err]);
        exit;
    }
    if ($code !== 200) {
        echo json_encode(['ok' => false, 'error' => "ИИ вернул код $code: " . mb_substr((string)$resp, 0, 400)]);
        exit;
    }

    $data   = json_decode((string)$resp, true);
    $answer = $data['choices'][0]['message']['content'] ?? 'Пустой ответ от ИИ';

    echo json_encode([
        'ok'     => true,
        'answer' => $answer,
        'works'  => $works,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Помощник ИИ — справочник работ</title>
<style>
  *{box-sizing:border-box}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;margin:0;padding:16px;color:#1a1a1a;line-height:1.5}
  .container{max-width:1100px;margin:0 auto}
  .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,0.06);margin-bottom:16px}
  h1{font-size:22px;margin:0 0 8px}
  h2{font-size:16px;margin:0 0 12px;color:#1e3a8a}
  .top-bar{display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:8px;margin-bottom:12px}
  .user-info{font-size:13px;color:#666}
  .user-info b{color:#2563eb}
  .logout{color:#dc2626;text-decoration:none;font-size:13px;margin-left:12px}
  .btn{display:inline-block;padding:9px 14px;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;background:#2563eb;color:#fff;text-decoration:none;text-align:center;font-family:inherit}
  .btn:hover{opacity:0.9}
  .btn-secondary{background:#fff;color:#2563eb;border:1.5px solid #2563eb}
  .btn-small{padding:7px 12px;font-size:13px}
  .btn-row{display:flex;gap:10px;flex-wrap:wrap;align-items:center;margin-bottom:12px}
  .ask-form{display:flex;gap:10px;flex-wrap:wrap}
  .ask-form textarea{flex:1;min-width:260px;padding:12px;border:1.5px solid #93c5fd;border-radius:10px;font-family:inherit;font-size:15px;min-height:60px;resize:vertical;background:#eff6ff}
  .ask-form textarea:focus{outline:none;border-color:#2563eb;background:#fff}
  .answer-box{background:#f0fdf4;border-left:4px solid #16a34a;padding:14px 16px;border-radius:10px;margin-top:14px;white-space:pre-wrap;font-size:14px;line-height:1.6}
  .error-box{background:#fef2f2;border-left:4px solid #dc2626;padding:14px 16px;border-radius:10px;margin-top:14px;font-size:14px;color:#991b1b}
  .loading{color:#2563eb;font-size:14px;margin-top:12px;display:none}
  .hint{font-size:13px;color:#666;background:#f9fafb;border-left:3px solid #2563eb;padding:8px 12px;border-radius:8px;margin-bottom:12px}
  .example{display:inline-block;background:#eff6ff;color:#1e3a8a;padding:4px 10px;border-radius:6px;font-size:12px;cursor:pointer;margin:3px 4px 3px 0;border:1px solid #bfdbfe}
  .example:hover{background:#dbeafe}
  table.works{width:100%;border-collapse:collapse;font-size:13px;margin-top:14px}
  table.works th{background:#f9fafb;color:#666;font-weight:600;text-align:left;padding:9px 12px;border-bottom:2px solid #e5e7eb;font-size:11px;text-transform:uppercase}
  table.works td{padding:9px 12px;border-bottom:1px solid #f0f0f0;vertical-align:top}
  table.works tr:hover td{background:#fafbff}
  .op-code{padding:2px 8px;border-radius:5px;font-size:12px;font-weight:700;white-space:nowrap;font-family:'SF Mono',Consolas,monospace;background:#fef3c7;color:#92400e}
</style>
</head>
<body>
<div class="container">

  <div class="card">
    <div class="top-bar">
      <h1>🤖 Помощник ИИ — справочник работ</h1>
      <div>
        <span class="user-info">👤 <b><?= e($user['name'] ?: $user['email']) ?></b></span>
        <a href="logout.php" class="logout">Выйти</a>
      </div>
    </div>
    <div class="btn-row">
      <a href="index.html" class="btn btn-secondary btn-small">← На рабочее место</a>
      <a href="works_brand.php" class="btn btn-secondary btn-small">🔧 Справочник работ</a>
    </div>
    <div class="hint">
      Задайте вопрос обычными словами. Помощник найдёт подходящие работы в справочнике КАМАЗ/КОМПАС и ответит на их основе.
    </div>
    <div>
      Примеры вопросов:
      <span class="example" onclick="fillQ(this)">Какие работы по замене масла в двигателе КАМАЗ?</span>
      <span class="example" onclick="fillQ(this)">Что входит в предпродажную подготовку?</span>
      <span class="example" onclick="fillQ(this)">Работы по тормозной системе КОМПАС?</span>
      <span class="example" onclick="fillQ(this)">Замена ремня ГРМ — какие коды?</span>
    </div>
  </div>

  <div class="card">
    <h2>Ваш вопрос</h2>
    <form class="ask-form" onsubmit="askAI(event)">
      <textarea id="q" placeholder="Например: Какие работы по замене масла в двигателе КАМАЗ 54901?" autofocus></textarea>
      <button type="submit" class="btn" id="askBtn" style="min-width:120px;">🤖 Спросить ИИ</button>
    </form>
    <div class="loading" id="loading">⏳ Ищу работы и спрашиваю ИИ… Это занимает 5–20 секунд.</div>
    <div id="result"></div>
  </div>

</div>

<script>
function fillQ(el) {
  document.getElementById('q').value = el.textContent.trim();
  document.getElementById('q').focus();
}

function escapeHtml(s) {
  const d = document.createElement('div'); d.textContent = s == null ? '' : s; return d.innerHTML;
}

async function askAI(e) {
  e.preventDefault();
  const q = document.getElementById('q').value.trim();
  if (q.length < 3) return;

  const btn = document.getElementById('askBtn');
  const loading = document.getElementById('loading');
  const result = document.getElementById('result');

  btn.disabled = true;
  btn.textContent = '⏳ Думаю…';
  loading.style.display = 'block';
  result.innerHTML = '';

  try {
    const fd = new FormData();
    fd.append('q', q);

    const resp = await fetch('ai_assistant.php', { method: 'POST', body: fd });
    const data = await resp.json();

    if (!data.ok) {
      result.innerHTML = '<div class="error-box">❌ ' + escapeHtml(data.error || 'Ошибка') + '</div>';
      return;
    }

    let html = '<div class="answer-box">' + escapeHtml(data.answer) + '</div>';

    if (data.works && data.works.length > 0) {
      html += '<h2 style="margin-top:20px;">📋 Работы, которые нашёл поиск (' + data.works.length + ')</h2>';
      html += '<table class="works"><thead><tr><th style="width:120px;">Код</th><th>Наименование</th><th style="width:80px;">Норма</th><th style="width:120px;">Комплектация</th></tr></thead><tbody>';
      for (const w of data.works) {
        const norm = w.norm_time !== null ? (parseFloat(w.norm_time).toFixed(2).replace(/\.?0+$/, '') + ' ч') : '—';
        html += '<tr>'
             +  '<td>' + (w.operation_code ? '<span class="op-code">' + escapeHtml(w.operation_code) + '</span>' : '—') + '</td>'
             +  '<td>' + escapeHtml(w.name || '') + (w.eng_name ? '<div style="color:#888;font-size:11px;margin-top:2px;">' + escapeHtml(w.eng_name) + '</div>' : '') + '</td>'
             +  '<td>' + escapeHtml(norm) + '</td>'
             +  '<td style="font-size:11px;color:#666;">' + escapeHtml(w.complectation || '—') + '</td>'
             +  '</tr>';
      }
      html += '</tbody></table>';
    }

    result.innerHTML = html;

  } catch (err) {
    result.innerHTML = '<div class="error-box">❌ Ошибка запроса: ' + escapeHtml(err.message) + '</div>';
  } finally {
    btn.disabled = false;
    btn.textContent = '🤖 Спросить ИИ';
    loading.style.display = 'none';
  }
}
</script>
</body>
</html>
