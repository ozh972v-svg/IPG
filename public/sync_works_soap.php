<?php
// public/sync_works_soap.php
// Синхронизация работ КАМАЗ через SOAP-сервис 1С:ГОА (curl + SimpleXML)

declare(strict_types=1);

ini_set('display_errors', '1');
error_reporting(E_ALL);
ini_set('memory_limit', '512M');
set_time_limit(600);

require_once __DIR__ . '/db.php';

start_session();
if (!current_user()) {
    header('Location: index.html');
    exit;
}

$wsdlUrl    = 'https://web-1c.kamaz.ru/GOA/ws/ws2.1cws';
$soapAction = 'http://1c.kamaz.ru/zakaz#zakaz:UnloadInstallationWorkloads';
$targetNs   = 'http://1c.kamaz.ru/zakaz';

$login    = getenv('ONEC_LOGIN')    ?: '';
$password = getenv('ONEC_PASSWORD') ?: '';

$start  = $_REQUEST['start']  ?? '2021-01-01';
$end    = $_REQUEST['end']    ?? date('Y-m-d');
$model  = $_REQUEST['model']  ?? '54901-0070014-CA';
$run    = isset($_REQUEST['run']) && $_REQUEST['run'] == '1';

$log      = [];
$httpCode = 0;
$curlErr  = '';
$response = '';
$stats    = ['complectations' => 0, 'total' => 0, 'works' => 0, 'with_norm' => 0, 'groups' => 0];

if ($run) {
    $xmlBody = '<?xml version="1.0" encoding="UTF-8"?>'
        . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"'
        . ' xmlns:zak="' . $targetNs . '">'
        . '<soap:Body>'
        . '<zak:UnloadInstallationWorkloads>'
        . '<zak:StartDate>'   . htmlspecialchars($start, ENT_XML1) . '</zak:StartDate>'
        . '<zak:EndDate>'     . htmlspecialchars($end,   ENT_XML1) . '</zak:EndDate>'
        . '<zak:ModelShassis>' . htmlspecialchars($model, ENT_XML1) . '</zak:ModelShassis>'
        . '</zak:UnloadInstallationWorkloads>'
        . '</soap:Body>'
        . '</soap:Envelope>';

    $ch = curl_init($wsdlUrl);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $xmlBody,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER         => false,
        CURLOPT_TIMEOUT        => 180,
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_HTTPHEADER     => [
            'Content-Type: text/xml; charset=utf-8',
            'SOAPAction: ' . $soapAction,
            'Authorization: Basic ' . base64_encode($login . ':' . $password),
        ],
    ]);

    $response = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $curlErr  = curl_error($ch);
    curl_close($ch);

    if ($curlErr) {
        $log[] = "cURL ошибка: $curlErr";
    } elseif ($httpCode !== 200) {
        $log[] = "HTTP $httpCode";
        $log[] = 'Первые 2000 символов: ' . substr((string)$response, 0, 2000);
    } else {
        $log[] = 'HTTP 200 OK, размер: ' . strlen((string)$response) . ' байт';

        // Убираем namespace: сначала объявления, потом префиксы у тегов.
        // Это позволяет читать поля напрямую: $node->TimeRate->Name и т.д.
        $clean = preg_replace('/\s+xmlns(:[A-Za-z0-9_]+)?="[^"]*"/', '', (string)$response);
        $clean = preg_replace('/<(\/)?[A-Za-z0-9_]+:/', '<$1', (string)$clean);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($clean);

        if (!$xml) {
            $log[] = 'Не удалось разобрать XML после чистки:';
            foreach (libxml_get_errors() as $e) {
                $log[] = '  ' . trim($e->message);
            }
        } else {
            // Находим все InstallationWorkloads
            $iwNodes = $xml->xpath('//InstallationWorkloads');
            if (!$iwNodes) {
                $iwNodes = [];
            }
            $log[] = 'Найдено InstallationWorkloads: ' . count($iwNodes);

            // --- Шаг 1: собрать уникальные complectations ---
            $comps = [];
            foreach ($iwNodes as $iw) {
                $deleted = trim((string)($iw->Deleted ?? 'false'));
                if ($deleted === 'true') continue;

                $comp = trim((string)($iw->TimeRate->Name ?? ''));
                if ($comp === '') continue;
                $comps[$comp] = true;
            }
            $comps = array_keys($comps);
            $log[] = 'Уникальных complectations: ' . count($comps) . ' [' . implode(', ', $comps) . ']';

            // --- Шаг 2: удалить старые записи по каждой complectation ---
            $pdo = get_db();
            $del = $pdo->prepare('DELETE FROM work_operations WHERE complectation = :c');
            foreach ($comps as $c) {
                $del->execute([':c' => $c]);
                $log[] = "Удалены старые записи по complectation = $c";
            }

            // --- Шаг 3: обойти все узлы и вставить записи ---
            $stmt = $pdo->prepare(
                'INSERT INTO work_operations
                 (code, parent_code, it_is_group, name, operation_code, norm_time, complectation, model, deleted, updated_at)
                 VALUES (:code, :parent_code, :it_is_group, :name, :operation_code, :norm_time, :complectation, :model, false, NOW())
                 ON CONFLICT (code) DO UPDATE SET
                   parent_code    = EXCLUDED.parent_code,
                   it_is_group    = EXCLUDED.it_is_group,
                   name           = EXCLUDED.name,
                   operation_code = EXCLUDED.operation_code,
                   norm_time      = EXCLUDED.norm_time,
                   complectation  = EXCLUDED.complectation,
                   model          = EXCLUDED.model,
                   deleted        = EXCLUDED.deleted,
                   updated_at     = NOW()'
            );

            $insertedComps = [];

            foreach ($iwNodes as $iwIdx => $iw) {
                $deleted = trim((string)($iw->Deleted ?? 'false'));
                if ($deleted === 'true') {
                    $log[] = "Узел #$iwIdx: Deleted=true, пропускаем";
                    continue;
                }

                $complectation = trim((string)($iw->TimeRate->Name ?? ''));
                if ($complectation === '') {
                    $log[] = "Узел #$iwIdx: TimeRate/Name пуст, пропускаем";
                    continue;
                }

                $iwNumber   = trim((string)($iw->Number ?? ''));
                $modelShort = substr($complectation, 0, 5);

                if (!isset($insertedComps[$complectation])) {
                    $insertedComps[$complectation] = 0;
                    $stats['complectations']++;
                }

                // Обход всех работ в этом узле
                $wlList = [];
                if (isset($iw->Workloads) && isset($iw->Workloads->Workload)) {
                    foreach ($iw->Workloads->Workload as $wl) {
                        $wlList[] = $wl;
                    }
                }

                $log[] = "Узел #$iwIdx (Number=$iwNumber, complectation=$complectation): работ в узле " . count($wlList);

                foreach ($wlList as $wl) {
                    if (!isset($wl->Operation)) continue;
                    $op = $wl->Operation;

                    // Пропуск удалённых
                    if (trim((string)($op->Deleted ?? 'false')) === 'true') continue;

                    $opCode  = trim((string)($op->Code ?? ''));
                    $opName  = trim((string)($op->Name ?? ''));
                    $itGroup = trim((string)($op->ItIsGroup ?? 'false')) === 'true';
                    if ($opCode === '') continue;

                    $operationCode = trim((string)($op->OperationCode ?? ''));
                    if ($operationCode === '') $operationCode = null;

                    // Трудоёмкость
                    $wlText   = trim((string)($wl->Workload ?? ''));
                    $normTime = ($wlText === '') ? null : (float)$wlText;
                    if ($normTime !== null && $normTime <= 0) $normTime = null;

                    // Прямой родитель = внешний <Parent>
                    $parentCode = null;
                    if (isset($op->Parent)) {
                        $pCode = trim((string)($op->Parent->Code ?? ''));
                        if ($pCode !== '') {
                            $parentCode = $pCode . '@' . $complectation;
                        }
                    }

                    $code = $opCode . '@' . $complectation;

                    try {
                        $stmt->execute([
                            ':code'           => $code,
                            ':parent_code'    => $parentCode,
                            ':it_is_group'    => $itGroup ? 'true' : 'false',
                            ':name'           => $opName,
                            ':operation_code' => $operationCode,
                            ':norm_time'      => $normTime,
                            ':complectation'  => $complectation,
                            ':model'          => $modelShort,
                        ]);
                        $insertedComps[$complectation]++;
                        $stats['total']++;
                        if ($itGroup) $stats['groups']++;
                        else {
                            $stats['works']++;
                            if ($normTime !== null) $stats['with_norm']++;
                        }
                    } catch (Throwable $e) {
                        $log[] = '  ! INSERT ошибка для code=' . $opCode . ': ' . $e->getMessage();
                    }
                }
            }

            $log[] = '';
            foreach ($insertedComps as $c => $n) {
                $log[] = "Итог по $c: вставлено $n записей";
            }
        }
    }
}
?><!doctype html>
<html lang="ru">
<head>
<meta charset="utf-8">
<title>Синхронизация работ через SOAP 1С:ГОА</title>
<style>
body { font-family: system-ui, sans-serif; margin: 20px; background: #f5f5f5; }
form { background:#fff; padding:16px; border-radius:8px; max-width:680px; }
label { display:block; margin:8px 0 4px; font-weight:600; }
input[type=text], input[type=date] { width:100%; padding:8px; box-sizing:border-box; }
button { margin-top:12px; padding:10px 16px; font-size:15px; cursor:pointer; }
.status { margin:16px 0; padding:12px; border-radius:6px; }
.ok { background:#e6f7e6; border:1px solid #7ac77a; }
.err { background:#fde8e8; border:1px solid #e07a7a; }
pre { background:#111; color:#eee; padding:12px; border-radius:6px; overflow:auto; max-height:600px; font-size:12px; white-space:pre-wrap; }
.stat { display:inline-block; margin-right:20px; font-weight:700; }
</style>
</head>
<body>

<h1>Синхронизация работ через SOAP 1С:ГОА</h1>

<form method="post">
  <label>StartDate</label>
  <input type="date" name="start" value="<?= htmlspecialchars($start) ?>">

  <label>EndDate</label>
  <input type="date" name="end" value="<?= htmlspecialchars($end) ?>">

  <label>ModelShassis (комплектация)</label>
  <input type="text" name="model" value="<?= htmlspecialchars($model) ?>">

  <input type="hidden" name="run" value="1">
  <button type="submit">🔄 Синхронизировать</button>
</form>

<?php if ($run): ?>
  <?php if ($curlErr || $httpCode !== 200): ?>
    <div class="status err"><b>Ошибка.</b> HTTP <?= (int)$httpCode ?>. Смотрите лог ниже.</div>
  <?php else: ?>
    <div class="status ok">
      <span class="stat">Комплектаций: <?= (int)$stats['complectations'] ?></span>
      <span class="stat">Всего записей: <?= (int)$stats['total'] ?></span>
      <span class="stat">Групп: <?= (int)$stats['groups'] ?></span>
      <span class="stat">Работ: <?= (int)$stats['works'] ?></span>
      <span class="stat">С нормой: <?= (int)$stats['with_norm'] ?></span>
    </div>
  <?php endif; ?>

  <h2>Лог</h2>
  <pre><?= htmlspecialchars(implode("\n", $log)) ?></pre>
<?php endif; ?>

</body>
</html>
