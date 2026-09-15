<?php
/**
 * Пересчитывает parent_code у существующих записей в БД.
 * Не обращается к 1С. Работает за секунды.
 * Доступ только админу.
 */
set_time_limit(0);

require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user || !$user['is_admin']) {
    http_response_code(403);
    exit('Только для админа');
}

$pdo = get_db();

// Собираем все коды групп
$stmt = $pdo->query("SELECT code FROM work_operations WHERE it_is_group = TRUE");
$groupCodes = [];
foreach ($stmt->fetchAll() as $row) {
    $groupCodes[$row['code']] = true;
}
$totalGroups = count($groupCodes);

// Загружаем все записи (ключ — code)
$stmt = $pdo->query("SELECT code, parent_code, it_is_group, operation_code FROM work_operations");
$rows = $stmt->fetchAll();

$updated = 0;
$skipped_no_parent = 0;
$errors = 0;

$updStmt = $pdo->prepare("UPDATE work_operations SET parent_code = :p WHERE code = :c");

foreach ($rows as $r) {
    $newParent = null;

    if ($r['it_is_group']) {
        // Группа: родитель = код без последних 2 цифр
        $code = $r['code'];
        if (preg_match('/^\d+$/', $code) && strlen($code) > 2) {
            $candidate = substr($code, 0, -2);
            if (isset($groupCodes[$candidate])) {
                $newParent = $candidate;
            }
        }
    } else {
        // Работа: префикс кода операции = группа (П10-017 → 10, 00-000 → 00)
        $opc = $r['operation_code'] ?? '';
        if ($opc && preg_match('/^[A-Za-zА-Яа-я]*?(\d{2})/u', $opc, $m)) {
            $candidate = $m[1];
            if (isset($groupCodes[$candidate])) {
                $newParent = $candidate;
            }
        }
    }

    // Обновляем, если изменилось
    if ($newParent !== $r['parent_code']) {
        try {
            $updStmt->execute([':p' => $newParent, ':c' => $r['code']]);
            $updated++;
        } catch (Throwable $e) {
            $errors++;
        }
    }
    if ($newParent === null) $skipped_no_parent++;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Пересчёт родителей</title>
<style>
  body { font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; background:#f0f2f5; padding:24px; line-height:1.5; }
  .container { max-width:700px; margin:0 auto; }
  .card { background:#fff; border-radius:14px; padding:24px; box-shadow:0 2px 12px rgba(0,0,0,0.06); margin-bottom:16px; }
  h1 { font-size:20px; margin:0 0 16px; }
  .btn { display:inline-block; padding:12px 20px; border-radius:10px; background:#2563eb; color:#fff; text-decoration:none; font-weight:600; margin-right:8px; }
  .btn-secondary { background:#fff; color:#2563eb; border:1.5px solid #2563eb; }
  .stat { display:grid; grid-template-columns:repeat(auto-fit, minmax(150px,1fr)); gap:10px; margin:12px 0; }
  .stat-item { padding:12px; background:#f9fafb; border-radius:8px; }
  .stat-item b { display:block; color:#2563eb; font-size:20px; }
  .stat-item small { color:#666; }
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <h1>🔧 Пересчёт родителей (parent_code)</h1>

    <div class="stat">
      <div class="stat-item"><b><?= number_format($totalGroups, 0, '.', ' ') ?></b><small>групп найдено</small></div>
      <div class="stat-item"><b><?= number_format(count($rows), 0, '.', ' ') ?></b><small>всего записей</small></div>
      <div class="stat-item"><b><?= number_format($updated, 0, '.', ' ') ?></b><small>обновлено parent</small></div>
      <div class="stat-item"><b><?= number_format($skipped_no_parent, 0, '.', ' ') ?></b><small>без родителя</small></div>
      <?php if ($errors): ?>
        <div class="stat-item" style="background:#fef2f2;color:#dc2626;"><b><?= $errors ?></b><small>ошибок</small></div>
      <?php endif; ?>
    </div>

    <p style="font-size:13px;color:#666;">
      <?php if ($skipped_no_parent > 0): ?>
        ⚠️ У <?= number_format($skipped_no_parent, 0, '.', ' ') ?> записей не удалось определить родителя —
        возможно, код операции не начинается с 2 цифр. Это нормально для некоторых работ.
      <?php else: ?>
        ✅ У всех записей проставлен родитель.
      <?php endif; ?>
    </p>

    <div style="margin-top:20px;">
      <a href="works.php" class="btn">Перейти к справочнику →</a>
      <a href="fix_parents.php" class="btn btn-secondary">Пересчитать ещё раз</a>
    </div>
  </div>
</div>
</body>
</html>
