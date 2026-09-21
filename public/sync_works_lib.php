<?php
// public/sync_works_lib.php
// Переиспользуемая функция синхронизации работ КАМАЗ через SOAP 1С:ГОА.
// Используется и в sync_works_soap.php, и в works.php (автозагрузка).

declare(strict_types=1);

require_once __DIR__ . '/db.php';

if (!function_exists('sync_works_for_complectation')) {

    /**
     * Синхронизировать работы по одной комплектации.
     *
     * @param string $model     ModelShassis (обычно = complectation, напр. 54901-0070014-CA)
     * @param string $startDate YYYY-MM-DD
     * @param string $endDate   YYYY-MM-DD
     * @return array{ok:bool, error:?string, http:?int, log:string[], stats:array}
     */
    function sync_works_for_complectation(string $model, string $startDate, string $endDate): array
    {
        $wsdlUrl    = 'https://web-1c.kamaz.ru/GOA/ws/ws2.1cws';
        $soapAction = 'http://1c.kamaz.ru/zakaz#zakaz:UnloadInstallationWorkloads';
        $targetNs   = 'http://1c.kamaz.ru/zakaz';

        $login    = getenv('ONEC_LOGIN')    ?: '';
        $password = getenv('ONEC_PASSWORD') ?: '';

        $log   = [];
        $stats = ['complectations' => 0, 'total' => 0, 'groups' => 0, 'works' => 0, 'with_norm' => 0];
        $httpCode = null;

        // --- SOAP-запрос ---
        $xmlBody = '<?xml version="1.0" encoding="UTF-8"?>'
            . '<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/"'
            . ' xmlns:zak="' . $targetNs . '">'
            . '<soap:Body>'
            . '<zak:UnloadInstallationWorkloads>'
            . '<zak:StartDate>'   . htmlspecialchars($startDate, ENT_XML1) . '</zak:StartDate>'
            . '<zak:EndDate>'     . htmlspecialchars($endDate,   ENT_XML1) . '</zak:EndDate>'
            . '<zak:ModelShassis>' . htmlspecialchars($model,    ENT_XML1) . '</zak:ModelShassis>'
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
            return ['ok' => false, 'error' => 'cURL: ' . $curlErr, 'http' => $httpCode, 'log' => $log, 'stats' => $stats];
        }
        if ($httpCode !== 200) {
            $log[] = 'HTTP ' . $httpCode;
            $log[] = 'Первые 2000 символов: ' . substr((string)$response, 0, 2000);
            return ['ok' => false, 'error' => 'HTTP ' . $httpCode, 'http' => $httpCode, 'log' => $log, 'stats' => $stats];
        }

        $log[] = 'HTTP 200 OK, размер: ' . strlen((string)$response) . ' байт';

        // --- Убираем namespace ---
        $clean = preg_replace('/\s+xmlns(:[A-Za-z0-9_]+)?="[^"]*"/', '', (string)$response);
        $clean = preg_replace('/<(\/)?[A-Za-z0-9_]+:/', '<$1', (string)$clean);

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($clean);
        if (!$xml) {
            foreach (libxml_get_errors() as $e) {
                $log[] = '  libxml: ' . trim($e->message);
            }
            return ['ok' => false, 'error' => 'XML не разобран', 'http' => $httpCode, 'log' => $log, 'stats' => $stats];
        }

        $iwNodes = $xml->xpath('//InstallationWorkloads') ?: [];
        $log[] = 'Найдено InstallationWorkloads: ' . count($iwNodes);

        // --- Собираем уникальные complectations ---
        $comps = [];
        foreach ($iwNodes as $iw) {
            if (trim((string)($iw->Deleted ?? 'false')) === 'true') continue;
            $comp = trim((string)($iw->TimeRate->Name ?? ''));
            if ($comp !== '') $comps[$comp] = true;
        }
        $comps = array_keys($comps);
        $log[] = 'Уникальных complectations: ' . count($comps) . ' [' . implode(', ', $comps) . ']';

        if (count($comps) === 0) {
            return ['ok' => false, 'error' => 'Нет complectations в ответе', 'http' => $httpCode, 'log' => $log, 'stats' => $stats];
        }

        $pdo = get_db();

        // --- Удаляем старые записи ---
        $del = $pdo->prepare('DELETE FROM work_operations WHERE complectation = :c');
        foreach ($comps as $c) {
            $del->execute([':c' => $c]);
            $log[] = "Удалены старые записи по complectation = $c";
        }

        // --- INSERT ---
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

        // Рекурсивно собираем цепочку родителей (от верхнего к прямому)
        $collectChain = function (SimpleXMLElement $node, string $comp) use (&$collectChain): array {
            $chain = [];
            if (isset($node->Parent)) {
                $chain = $collectChain($node->Parent, $comp);
                $p = $node->Parent;
                $pCode = trim((string)($p->Code ?? ''));
                if ($pCode !== '') {
                    $parentAbove = count($chain) > 0 ? $chain[count($chain) - 1]['code'] : null;
                    $chain[] = [
                        'code'        => $pCode . '@' . $comp,
                        'parent_code' => $parentAbove,
                        'name'        => trim((string)($p->Name ?? '')),
                    ];
                }
            }
            return $chain;
        };

        $seenGroups = [];
        $insertedComps = [];
        $insertErrors = 0;

        foreach ($iwNodes as $iwIdx => $iw) {
            if (trim((string)($iw->Deleted ?? 'false')) === 'true') continue;
            $complectation = trim((string)($iw->TimeRate->Name ?? ''));
            if ($complectation === '') continue;

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
            $log[] = "Узел #$iwIdx ($complectation): работ в узле " . count($wlList);

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

                $wlText   = trim((string)($wl->Workload ?? ''));
                $normTime = ($wlText === '') ? null : (float)$wlText;
                if ($normTime !== null && $normTime <= 0) $normTime = null;

                $chain = $collectChain($op, $complectation);

                // Группы
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
                        if ($insertErrors <= 5) $log[] = '  ! INSERT группы ' . $gCode . ': ' . $e->getMessage();
                    }
                }

                // Работа
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
                    if ($insertErrors <= 5) $log[] = '  ! INSERT работы ' . $code . ': ' . $e->getMessage();
                }
            }
        }

        foreach ($insertedComps as $c => $n) {
            $log[] = "Итог по $c: вставлено $n записей";
        }
        if ($insertErrors > 5) {
            $log[] = "Всего ошибок INSERT: $insertErrors (показаны первые 5)";
        }

        return ['ok' => true, 'error' => null, 'http' => $httpCode, 'log' => $log, 'stats' => $stats];
    }
}
