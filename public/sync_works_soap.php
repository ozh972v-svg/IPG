<?php
// public/sync_works_soap.php
// Синхронизация работ КАМАЗ через SOAP-сервис 1С:ГОА (curl + SimpleXML)

declare(strict_types=1);

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
$stats    = ['complectations' => 0, 'total' => 0, 'works' => 0, 'with_norm' => 0];

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
        $log[] = 'Первые 2000 символов ответа: ' . substr((string)$response, 0, 2000);
    } else {
        $log[] = 'HTTP 200 OK, размер ответа: ' . strlen((string)$response) . ' байт';

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($response);
        if (!$xml) {
            $log[] = 'Не удалось разобрать XML:';
            foreach (libxml_get_errors() as $e) {
                $log[] = '  ' . trim($e->message);
            }
        } else {
            $xml->registerXPathNamespace('m', $targetNs);
            $workloads = $xml->xpath('//m:InstallationWorkload');
            if (!$workloads) {
                $log[] = 'Узлы InstallationWorkload не найдены (проверь namespace).';
            } else {
                $pdo = get_db();

                foreach ($workloads as $iw) {
                    $iw->registerXPathNamespace('m', $targetNs);

                    // Deleted у самой InstallationWorkload
                    $iwDeleted = (string)($iw->xpath('./m:Deleted')[0] ?? 'false');
                    if ($iwDeleted === 'true') {
                        continue;
                    }

                    // Комплектация
                    $timeRate = $iw->xpath('./m:TimeRate')[0] ?? null;
                    if (!$timeRate) {
                        continue;
                    }
                    $complectation = trim((string)$timeRate->Name);
                    if ($complectation === '') {
                        continue;
                    }
                    $modelShort = substr($complectation, 0, 5);

                    $stats['complectations']++;

                    // Удаляем старые записи по этой комплектации
                    $del = $pdo->prepare('DELETE FROM work_operations WHERE complectation = :c');
                    $del->execute([':c' => $complectation]);

                    // Обход работ
                    $wlNodes = $iw->xpath('.//m:Workload');
                    $insertedHere = 0;

                    foreach ($wlNodes as $wlNode) {
                        $op = $wlNode->xpath('./m:Operation')[0] ?? null;
                        if (!$op) {
                            continue;
                        }

                        $opDeleted = (string)($op->xpath('./m:Deleted')[0] ?? 'false');
                        if ($opDeleted === 'true') {
                            continue;
                        }

                        $opCode  = trim((string)$op->Code);
                        $opName  = trim((string)$op->Name);
                        $itGroup = (string)$op->ItIsGroup === 'true';

                        if ($opCode === '') {
                            continue;
                        }

                        $operationCode = trim((string)$op->OperationCode);
                        if ($operationCode === '') {
                            $operationCode = null;
                        }

                        // Трудоёмкость
                        $wlText  = trim((string)$wlNode->Workload);
                        $normTime = ($wlText === '') ? null : (float)$wlText;
                        if ($normTime !== null && $normTime <= 0) {
                            $normTime = null;
                        }

                        // Родитель — самый глубокий <m:Parent> (прямой родитель)
                        $parentCode = null;
                        $parents = $op->xpath('./m:Parent');
                        if ($parents && count($parents) > 0) {
                            // Берём прямой Parent (первый), а из него - вложенный deepest
                            $directParent = $parents[0];
                            // Если у прямого родителя есть вложенный Parent - это верхняя группа,
                            // а нам нужен именно прямой родитель (самый близкий).
                            // В присланном ответе структура: <Parent><Parent>верхняя</Parent>прямой</Parent>
                            // Прямой родитель - это сам directParent.
                            $pCode  = trim((string)$directParent->Code);
                            if ($pCode !== '') {
                                $parentCode = $pCode . '@' . $complectation;
                            }
                        }

                        $code = $opCode . '@' . $complectation;

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

                        $insertedHere++;
                        $stats['total']++;
                        if (!$itGroup) {
                            $stats['works']++;
                            if ($normTime !== null) {
                                $stats['with_norm']++;
                            }
                        }
                    }

                    $log[] = sprintf(
                        'Комплектация %s: удалено старых, вставлено %d записей',
                        $complectation,
                        $insertedHere
                    );
                }
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
      <span class="stat">Из них работ: <?= (int)$stats['works'] ?></span>
      <span class="stat">С нормой времени: <?= (int)$stats['with_norm'] ?></span>
    </div>
  <?php endif; ?>

  <h2>Лог</h2>
  <pre><?= htmlspecialchars(implode("\n", $log)) ?></pre>
<?php endif; ?>

</body>
</html>
