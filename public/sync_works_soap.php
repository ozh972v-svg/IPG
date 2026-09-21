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

        // Убираем namespace — тогда поля читаются напрямую
        $clean = preg_replace('/\s+xmlns(:[A-Za-z0-9_]+)?="[^"]*"/', '', (string)$response);
        $clean = preg_replace('/<(\/)?[A-Za-z0-9_]+:/', '<$1', (string)$clean);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($clean);

        if (!$xml) {
            $log[] = 'Не удалось разобрать XML:';
            foreach (libxml_get_errors() as $e) {
                $log[] = '  ' . trim($e->message);
            }
        } else {
            $iwNodes = $xml->xpath('//InstallationWorkloads') ?: [];
            $log[] = 'Найдено InstallationWorkloads: ' . count($iwNodes);

            // Собираем уникальные complectations
            $comps = [];
            foreach ($iwNodes as $iw) {
                if (trim((string)($iw->Deleted ?? 'false')) === 'true') continue;
                $comp = trim((string)($iw->TimeRate->Name ?? ''));
                if ($comp !== '') $comps[$comp] = true;
            }
            $comps = array_keys($comps);
            $log[] = 'Уникальных complectations: ' . count($comps) . ' [' . implode(', ', $comps) . ']';

            $pdo = get_db();

            // Удаляем старые записи
            $del = $pdo->prepare('DELETE FROM work_operations WHERE complectation = :c');
            foreach ($comps as $c) {
                $del->execute([':c' => $c]);
                $log[] = "Удалены старые записи по complectation = $c";
            }

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

            // Функция: собрать цепочку родителей ОТ ВЕРХНЕГО К НИЖНЕМУ
            // Возвращает массив ['Code@comp' => ['name' => ..., 'parent_code' => ...], ...]
            $collectChain = function (SimpleXMLElement $node, string $comp) use (&$collectChain): array {
                $chain = [];
                if (isset($node->Parent)) {
                    // Сначала рекурсивно поднимаемся выше
                    $chain = $collectChain($node->Parent, $comp);
                    $p = $node->Parent;
                    $pCode = trim((string)($p->Code ?? ''));
                    if ($pCode !== '') {
                        $parentAboveCode = count($chain) > 0 ? $chain[count($chain) - 1]['code'] : null;
                        $chain[] = [
                            'code'        => $pCode . '@' . $comp,
                            'parent_code' => $parentAboveCode,
                            'name'        => trim((string)($p->Name ?? '')),
                        ];
                    }
                }
                return $chain;
            };

            // Множество уже вставленных групп (по всем complectations)
            $seenGroups = [];

            $insertedComps = [];
            $insertErrors  = 0;

            foreach ($iwNodes as $iwIdx => $iw) {
                if (trim((string)($iw->Deleted ?? 'false')) === 'true') {
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

                    // Собрать цепочку родителей (от верхнего к прямому)
                    $chain = $collectChain($op, $complectation);

                    // Вставить группы (те, которых ещё нет)
                    foreach ($chain as $g) {
                        $gCode = $g['code'];
                        if (isset($seenGroups[$gCode])) continue;
                        $seenGroups[$gCode] = true;
                        try {
                            $stmt->execute([
                                ':code'           => $gCode,
                                ':parent_code'    => $g['parent_code'],
                                ':it_is_group'    => 'true',
                                ':name'           => $g['name'],
                                ':operation_code' => null,
                                ':norm_time'      => null,
                                ':complectation'  => $complectation,
                                ':model'          => $modelShort,
                            ]);
                            $stats['groups']++;
                            $stats['total']++;
                            $insertedComps[$complectation]++;
                        } catch (Throwable $e) {
                            $insertErrors++;
                            if ($insertErrors <= 5) {
                                $log[] = '  ! INSERT группы ' . $gCode . ': ' . $e->getMessage();
                            }
                        }
                    }

                    // Прямой родитель — последний в цепочке
                    $directParentCode = count($chain) > 0 ? $chain[count($chain) - 1]['code'] : null;
                    $code = $opCode . '@' . $complectation;

                    try {
                        $stmt->execute([
                            ':code'           => $code,
                            ':parent_code'    => $directParentCode,
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
                        $insertErrors++;
                        if ($insertErrors <= 5) {
                            $log[] = '  ! INSERT работы ' . $code . ': ' . $e->getMessage();
                        }
                    }
                }
            }

            $log[] = '';
            foreach ($insertedComps as $c => $n) {
                $log[] = "Итог по $c: вставлено $n записей";
            }
            if ($insertErrors > 5) {
                $log[] = "Всего ошибок INSERT: $insertErrors (показаны первые 5)";
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
