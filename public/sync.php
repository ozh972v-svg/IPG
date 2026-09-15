<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user || !$user['is_admin']) { header('Location: index.html'); exit; }

$pdo  = get_db();
$type = $_GET['type'] ?? '';
if (!in_array($type, ['works', 'nomenclature', 'all'], true)) $type = '';
$running = !empty($_GET['run']);
$messages = []; $error = null;

function soap_action(string $op): string { return 'http://1c.kamaz.ru/zakaz#Zakaz:' . $op; }

function onec_call(string $operation, array $params = []): string {
    $login    = getenv('ONEC_SOAP_LOGIN')    ?: getenv('ONEC_LOGIN');
    $password = getenv('ONEC_SOAP_PASSWORD') ?: getenv('ONEC_PASSWORD');
    if (!$login || !$password) throw new RuntimeException('Не заданы ONEC_LOGIN / ONEC_PASSWORD');
    $endpoint = getenv('ONEC_SOAP_URL') ?: 'https://web-1c.kamaz.ru/GOA/ws/Zakaz';
    $ns = 'http://1c.kamaz.ru/zakaz';
    $xsi = 'http://www.w3.org/2001/XMLSchema-instance';
    $paramsXml = '';
    foreach ($params as $k => $v) {
        $paramsXml .= ($v === null)
            ? '<zak:' . $k . ' xsi:nil="true"/>'
            : '<zak:' . $k . '>' . htmlspecialchars((string)$v, ENT_XML1) . '</zak:' . $k . '>';
    }
    $envelope = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soapenv:Envelope xmlns:soapenv="http://schemas.xmlsoap.org/soap/envelope/" xmlns:zak="' . $ns . '" xmlns:xsi="' . $xsi . '">'
        . '<soapenv:Header/><soapenv:Body><zak:' . $operation . '>' . $paramsXml . '</zak:' . $operation . '></soapenv:Body></soapenv:Envelope>';
    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true, CURLOPT_POST => true, CURLOPT_POSTFIELDS => $envelope,
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC, CURLOPT_USERPWD => $login . ':' . $password,
        CURLOPT_TIMEOUT => 110, CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_SSL_VERIFYPEER => false, CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER => ['Content-Type: text/xml; charset=utf-8', 'SOAPAction: "' . soap_action($operation) . '"'],
    ]);
    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);
    if ($curlErr) throw new RuntimeException('Ошибка соединения: ' . $curlErr);
    if ($httpCode === 401 || $httpCode === 402) throw new RuntimeException('Неверный логин/пароль (HTTP ' . $httpCode . ')');
    if ($httpCode === 403) throw new RuntimeException('Нет прав на операцию ' . $operation);
    if ($httpCode !== 200) throw new RuntimeException('1С вернул код ' . $httpCode . '. Ответ: ' . substr(preg_replace('/\s+/', ' ', (string)$response), 0, 500));
    return (string)$response;
}

function xml_tag(string $xml, string $tag): ?string {
    $t = preg_quote($tag, '~');
    $re = '~<(?:[^:>\s]+:)?' . $t . '(?:\s[^>]*)?>([^<]*)</(?:[^:>\s]+:)?' . $t . '>~i';
    if (preg_match($re, $xml, $m)) {
        $v = trim(html_entity_decode($m[1], ENT_XML1, 'UTF-8'));
        return $v === '' ? null : $v;
    }
    return null;
}

function cut(?string $s, int $max): ?string {
    if ($s === null) return null;
    return mb_substr($s, 0, $max, 'UTF-8');
}

/**
 * Парсит ответ через SimpleXML (правильно читает вложенные элементы).
 */
function parse_works_simplexml(string $xml): array {
    if (!function_exists('simplexml_load_string')) return [];
    $xml = preg_replace('/^\xEF\xBB\xBF/', '', $xml);
    $prev = libxml_use_internal_errors(true);
    $sx = simplexml_load_string($xml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
    libxml_clear_errors();
    libxml_use_internal_errors($prev);
    if (!$sx) return [];

    // Все элементы <WorkOperations>/* (прямые дети с именем Parent)
    $nodes = $sx->xpath('//*[local-name()="WorkOperations"]/*[local-name()="Parent"]');
    if (!$nodes) {
        $nodes = $sx->xpath('//*[local-name()="WorkOperations"]/*');
    }
    if (!$nodes) return [];

    $items = [];
    foreach ($nodes as $n) {
        // Code самого элемента (первый прямой Code)
        $code = '';
        foreach ($n->children() as $child) {
            if ($child->getName() === 'Code') { $code = trim((string)$child); break; }
        }
        if ($code === '') continue;

        // Вложенный <Parent><Code>...</Code></Parent> — ссылка на родителя
        $parentCode = null;
        foreach ($n->children() as $child) {
            if ($child->getName() === 'Parent') {
                foreach ($child->children() as $c2) {
                    if ($c2->getName() === 'Code') {
                        $pc = trim((string)$c2);
                        if ($pc !== '' && $pc !== $code) $parentCode = $pc;
                        break;
                    }
                }
                break;
            }
        }

        $get = function(string $tag) use ($n) {
            foreach ($n->children() as $c) {
                if ($c->getName() === $tag) {
                    $v = trim((string)$c);
                    return $v === '' ? null : $v;
                }
            }
            return null;
        };

        $items[$code] = [
            'code'           => cut($code, 50),
            'parent_code'    => cut($parentCode, 50),
            'it_is_group'    => strtolower($get('ItIsGroup') ?? 'false') === 'true',
            'name'           => cut($get('Name'), 250),
            'operation_code' => cut($get('OperationCode'), 50),
            'name_work'      => cut($get('NameWork'), 250),
            'eng_name'       => cut($get('EngName'), 250),
            'description'    => cut($get('Description'), 5000),
            'guard_work'     => strtolower($get('GuardWork') ?? 'false') === 'true',
            'fact_work'      => strtolower($get('FactWork') ?? 'false') === 'true',
            'deleted'        => strtolower($get('Deleted') ?? 'false') === 'true',
        ];
    }
    return array_values($items);
}

/**
 * Fallback — старая версия парсера на регулярках.
 */
function parse_works_regex(string $xml): array {
    $items = [];
    $re = '~<(?:[^:>\s]+:)?Code(?:\s[^>]*)?>([^<]*)</(?:[^:>\s]+:)?Code>~i';
    if (!preg_match_all($re, $xml, $m, PREG_OFFSET_CAPTURE)) return $items;
    $codes = $m[1]; $positions = $m[0]; $n = count($codes);

    for ($i = 0; $i < $n; $i++) {
        $code = trim(html_entity_decode($codes[$i][0], ENT_XML1, 'UTF-8'));
        if ($code === '' || mb_strlen($code, 'UTF-8') > 50) continue;
        if (!preg_match('/^[A-Za-zА-Яа-я0-9]/u', $code)) continue;
        $startPos = $positions[$i][1];
        $endPos = ($i + 1 < $n) ? $positions[$i + 1][1] : strlen($xml);
        $wStart = max(0, $startPos - 300);
        $window = substr($xml, $wStart, $endPos - $wStart + 400);

        $get = function(string $tag) use ($window) {
            $t = preg_quote($tag, '~');
            if (preg_match('~<(?:[^:>\s]+:)?' . $t . '(?:\s[^>]*)?>([^<]*)</(?:[^:>\s]+:)?' . $t . '>~i', $window, $mm)) {
                $v = trim(html_entity_decode($mm[1], ENT_XML1, 'UTF-8'));
                return $v === '' ? null : $v;
            }
            return null;
        };

        $items[$code] = [
            'code' => cut($code, 50), 'parent_code' => null,
            'it_is_group' => strtolower($get('ItIsGroup') ?? 'false') === 'true',
            'name' => cut($get('Name'), 250), 'operation_code' => cut($get('OperationCode'), 50),
            'name_work' => cut($get('NameWork'), 250), 'eng_name' => cut($get('EngName'), 250),
            'description' => cut($get('Description'), 5000),
            'guard_work' => strtolower($get('GuardWork') ?? 'false') === 'true',
            'fact_work'  => strtolower($get('FactWork')  ?? 'false') === 'true',
            'deleted'    => strtolower($get('Deleted')   ?? 'false') === 'true',
        ];
    }
    return array_values($items);
}

function parse_works(string $xml): array {
    $viaSx = parse_works_simplexml($xml);
    if (!empty($viaSx)) return $viaSx;
    return parse_works_regex($xml);
}

function date_tz(): string { return getenv('ONEC_DATE_TZ') ?: '+05:00'; }

function do_sync_works(PDO $pdo): array {
    $tz = date_tz();
    $xml = onec_call('UnloadWorkOperations', [
        'OperationCode' => null, 'StartDate' => '2000-01-01' . $tz, 'EndDate' => '2099-12-31' . $tz,
    ]);
    $parsed = parse_works($xml);
    if (count($parsed) === 0) {
        return ['total' => 0, 'message' => 'Записей 0. Длина ответа: ' . strlen($xml) . ' байт'];
    }

    $stmt = $pdo->prepare("
        INSERT INTO work_operations (code, parent_code, it_is_group, name, operation_code, name_work, eng_name, description, guard_work, fact_work, deleted, updated_at)
        VALUES (:code, :parent_code, :it_is_group, :name, :operation_code, :name_work, :eng_name, :description, :guard_work, :fact_work, :deleted, NOW())
        ON CONFLICT (code) DO UPDATE SET
            parent_code=EXCLUDED.parent_code, it_is_group=EXCLUDED.it_is_group, name=EXCLUDED.name,
            operation_code=EXCLUDED.operation_code, name_work=EXCLUDED.name_work, eng_name=EXCLUDED.eng_name,
            description=EXCLUDED.description, guard_work=EXCLUDED.guard_work, fact_work=EXCLUDED.fact_work,
            deleted=EXCLUDED.deleted, updated_at=NOW()
    ");
    $total = 0;
    foreach ($parsed as $p) {
        $stmt->execute([
            ':code' => $p['code'], ':parent_code' => $p['parent_code'],
            ':it_is_group' => $p['it_is_group'] ? 1 : 0, ':name' => $p['name'],
            ':operation_code' => $p['operation_code'], ':name_work' => $p['name_work'],
            ':eng_name' => $p['eng_name'], ':description' => $p['description'],
            ':guard_work' => $p['guard_work'] ? 1 : 0, ':fact_work' => $p['fact_work'] ? 1 : 0,
            ':deleted' => $p['deleted'] ? 1 : 0,
        ]);
        $total++;
    }
    return ['total' => $total, 'message' => null];
}

function do_sync_nomenclature(PDO $pdo): array {
    $inn = getenv('ONEC_INN'); $kpp = getenv('ONEC_KPP');
    if (!$inn || !$kpp) throw new RuntimeException('Нужны ONEC_INN и ONEC_KPP');
    $xml = onec_call('UnloadNomenclatureUpdates', ['INN' => $inn, 'KPP' => $kpp]);
    $parsed = parse_works($xml);
    if (count($parsed) === 0) return ['total' => 0, 'message' => 'Записей 0'];
    $stmt = $pdo->prepare("
        INSERT INTO nomenclatures (code, name, full_name, eng_name, base_measure, code_1c, parent, deleted, updated_at)
        VALUES (:code, :name, :full_name, :eng_name, :base_measure, :code_1c, :parent, :deleted, NOW())
        ON CONFLICT (code) DO UPDATE SET name=EXCLUDED.name, full_name=EXCLUDED.full_name, eng_name=EXCLUDED.eng_name,
            base_measure=EXCLUDED.base_measure, code_1c=EXCLUDED.code_1c, parent=EXCLUDED.parent, deleted=EXCLUDED.deleted, updated_at=NOW()
    ");
    $total = 0;
    foreach ($parsed as $p) {
        $stmt->execute([':code' => $p['code'], ':name' => $p['name'], ':full_name' => null,
            ':eng_name' => $p['eng_name'], ':base_measure' => null, ':code_1c' => null,
            ':parent' => $p['parent_code'], ':deleted' => $p['deleted'] ? 1 : 0]);
        $total++;
    }
    return ['total' => $total, 'message' => null];
}

function run_sync(PDO $pdo, string $t, callable $fn): array {
    $stmt = $pdo->prepare("INSERT INTO sync_log (sync_type, status) VALUES (:t, 'running') RETURNING id");
    $stmt->execute([':t' => $t]); $logId = (int)$stmt->fetchColumn();
    try {
        $r = $fn($pdo);
        $pdo->prepare("UPDATE sync_log SET status='ok', finished_at=NOW(), items_total=:n, error_message=:m WHERE id=:id")
            ->execute([':n' => $r['total'], ':m' => $r['message'] ?: null, ':id' => $logId]);
        return ['ok' => true, 'total' => $r['total'], 'message' => $r['message']];
    } catch (Throwable $e) {
        $pdo->prepare("UPDATE sync_log SET status='error', finished_at=NOW(), error_message=:m WHERE id=:id")
            ->execute([':m' => $e->getMessage(), ':id' => $logId]);
        return ['ok' => false, 'error' => $e->getMessage()];
    }
}

if ($running && $type) {
    if ($type === 'works') $r = run_sync($pdo, 'works', 'do_sync_works');
    elseif ($type === 'nomenclature') $r = run_sync($pdo, 'nomenclature', 'do_sync_nomenclature');
    else {
        $r1 = run_sync($pdo, 'works', 'do_sync_works');
        $r2 = run_sync($pdo, 'nomenclature', 'do_sync_nomenclature');
        $r = ['ok' => ($r1['ok'] && $r2['ok']), 'total' => ($r1['total'] ?? 0) + ($r2['total'] ?? 0)];
    }
    if (!empty($r['ok'])) {
        $msg = 'Готово. Записей: ' . ($r['total'] ?? '—');
        if (!empty($r['message'])) $msg .= '. ' . $r['message'];
        $messages[] = $msg;
    } else $error = $r['error'] ?? 'Ошибка';
}

$history = $pdo->query("SELECT * FROM sync_log ORDER BY started_at DESC LIMIT 20")->fetchAll();
function fmtTs($ts) { return $ts ? date('d.m.Y H:i:s', strtotime($ts)) : '—'; }
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Синхронизация с 1С</title>
<style>
  *{box-sizing:border-box} body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;background:#f0f2f5;margin:0;padding:16px;color:#1a1a1a;line-height:1.5}
  .container{max-width:900px;margin:0 auto} .card{background:#fff;border-radius:14px;padding:20px;box-shadow:0 2px 12px rgba(0,0,0,0.06);margin-bottom:16px}
  h1{font-size:22px;margin:0 0 12px} h2{font-size:18px;margin:0 0 12px}
  .btn{display:inline-block;padding:12px 18px;border:none;border-radius:10px;font-size:15px;font-weight:600;cursor:pointer;background:#2563eb;color:#fff;text-decoration:none;text-align:center}
  .btn-secondary{background:#fff;color:#2563eb;border:1.5px solid #2563eb} .btn-green{background:#16a34a}
  .btn-row{display:flex;gap:10px;flex-wrap:wrap;margin-bottom:12px}
  .alert{padding:12px 16px;border-radius:10px;font-size:14px;margin-bottom:16px}
  .alert-success{background:#f0fdf4;color:#16a34a;border-left:4px solid #16a34a}
  .alert-error{background:#fef2f2;color:#dc2626;border-left:4px solid #dc2626}
  table.doc-table{width:100%;border-collapse:collapse;font-size:13px}
  table.doc-table th{background:#f9fafb;color:#666;font-weight:600;text-align:left;padding:10px 12px;border-bottom:1px solid #e5e7eb;font-size:12px}
  table.doc-table td{padding:10px 12px;border-bottom:1px solid #f0f0f0;vertical-align:top}
  .badge{display:inline-block;padding:3px 10px;border-radius:6px;font-size:12px;font-weight:600}
  .badge-green{background:#f0fdf4;color:#16a34a} .badge-red{background:#fef2f2;color:#dc2626} .badge-blue{background:#eff6ff;color:#2563eb}
  code{background:#f3f4f6;padding:2px 6px;border-radius:4px;font-size:12px}
  .err-cell{color:#555;font-size:11px;word-break:break-all;max-width:480px}
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <h1>🔄 Синхронизация с 1С:ГОА</h1>
    <div class="btn-row">
      <a href="index.html" class="btn btn-secondary">← На рабочее место</a>
      <a href="works.php" class="btn btn-secondary">К справочнику работ</a>
      <a href="nomenclature.php" class="btn btn-secondary">К справочнику номенклатуры</a>
    </div>
  </div>
  <?php if ($error): ?><div class="alert alert-error">❌ <?= e($error) ?></div><?php endif; ?>
  <?php foreach ($messages as $m): ?><div class="alert alert-success">✅ <?= e($m) ?></div><?php endforeach; ?>

  <div class="card">
    <h2>Что синхронизировать</h2>
    <div class="btn-row">
      <a href="sync.php?type=works&run=1" class="btn btn-green" onclick="return confirm('Обновить работы? Может занять 1-2 мин.')">🔧 Обновить работы</a>
      <a href="sync.php?type=nomenclature&run=1" class="btn btn-green" onclick="return confirm('Обновить номенклатуру?')">📦 Обновить номенклатуру</a>
      <a href="sync.php?type=all&run=1" class="btn" onclick="return confirm('Обновить всё?')">🔄 Обновить всё</a>
    </div>
    <p style="font-size:13px;color:#666;margin-top:12px;">Парсер ответа: SimpleXML (правильно читает вложенные элементы) с fallback на регулярки.</p>
  </div>

  <div class="card">
    <h2>История синхронизаций</h2>
    <?php if (!$history): ?><p style="color:#888;">Пока нет ни одной синхронизации.</p>
    <?php else: ?>
      <table class="doc-table">
        <thead><tr><th>Тип</th><th>Статус</th><th>Начало</th><th>Окончание</th><th>Записей</th><th>Детали</th></tr></thead>
        <tbody>
        <?php foreach ($history as $h): ?>
          <tr>
            <td><code><?= e($h['sync_type']) ?></code></td>
            <td><?php if ($h['status']==='ok'): ?><span class="badge badge-green">успех</span>
                <?php elseif ($h['status']==='error'): ?><span class="badge badge-red">ошибка</span>
                <?php else: ?><span class="badge badge-blue">работает</span><?php endif; ?></td>
            <td><?= e(fmtTs($h['started_at'])) ?></td>
            <td><?= e(fmtTs($h['finished_at'])) ?></td>
            <td><?= (int)$h['items_total'] ?></td>
            <td class="err-cell"><?= e($h['error_message'] ?: '') ?></td>
          </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    <?php endif; ?>
  </div>
</div>
</body>
</html>
