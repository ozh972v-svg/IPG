<?php
/**
 * Установка таблиц для справочника работ и номенклатуры.
 * Доступ: только администратор. Запускается один раз.
 */
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user || empty($user['is_admin'])) {
    header('Location: login.php');
    exit;
}

$done = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $pdo = get_db();

        // Справочник работ
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS work_operations (
                code            VARCHAR(20) PRIMARY KEY,
                parent_code     VARCHAR(20),
                it_is_group     BOOLEAN NOT NULL DEFAULT FALSE,
                name            VARCHAR(255),
                operation_code  VARCHAR(50),
                name_work       VARCHAR(255),
                eng_name        VARCHAR(255),
                description     TEXT,
                guard_work      BOOLEAN NOT NULL DEFAULT FALSE,
                fact_work       BOOLEAN NOT NULL DEFAULT FALSE,
                deleted         BOOLEAN NOT NULL DEFAULT FALSE,
                updated_at      TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_work_parent    ON work_operations(parent_code)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_work_name      ON work_operations(name)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_work_name_work ON work_operations(name_work)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_work_op_code   ON work_operations(operation_code)");

        // Справочник номенклатуры
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS nomenclatures (
                code          VARCHAR(50) PRIMARY KEY,
                name          VARCHAR(255),
                full_name     TEXT,
                eng_name      VARCHAR(255),
                base_measure  VARCHAR(20),
                code_1c       VARCHAR(20),
                parent        VARCHAR(255),
                deleted       BOOLEAN NOT NULL DEFAULT FALSE,
                updated_at    TIMESTAMPTZ NOT NULL DEFAULT NOW()
            )
        ");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nom_name   ON nomenclatures(name)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nom_parent ON nomenclatures(parent)");
        $pdo->exec("CREATE INDEX IF NOT EXISTS idx_nom_1c     ON nomenclatures(code_1c)");

        $done = true;
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Установка таблиц — 1С:ГОА</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; margin: 0; padding: 16px; color: #1a1a1a; line-height: 1.5; }
  .container { max-width: 720px; margin: 0 auto; }
  .card { background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); margin-bottom: 16px; }
  h1 { font-size: 20px; margin: 0 0 12px; }
  .btn { display: inline-block; padding: 12px 18px; border: none; border-radius: 10px; font-size: 15px; font-weight: 600; cursor: pointer; background: #16a34a; color: #fff; text-decoration: none; }
  .btn-secondary { background: #fff; color: #2563eb; border: 1.5px solid #2563eb; }
  .alert { padding: 12px 16px; border-radius: 10px; font-size: 14px; margin-bottom: 16px; }
  .alert-success { background: #f0fdf4; color: #16a34a; border-left: 4px solid #16a34a; }
  .alert-error { background: #fef2f2; color: #dc2626; border-left: 4px solid #dc2626; }
  code { background: #f3f4f6; padding: 2px 6px; border-radius: 4px; font-size: 13px; }
</style>
</head>
<body>
<div class="container">
  <div class="card">
    <h1>⚙️ Установка таблиц справочников</h1>
    <p style="color:#666;font-size:14px;">Создаст таблицы <code>work_operations</code> и <code>nomenclatures</code>. Безопасно запускать повторно — использует <code>CREATE IF NOT EXISTS</code>.</p>

    <?php if ($done): ?>
      <div class="alert alert-success">✅ Таблицы созданы. Можно переходить к поиску.</div>
      <a href="works.php" class="btn">К поиску работ →</a>
      <a href="nomenclature.php" class="btn btn-secondary">К поиску номенклатуры →</a>
    <?php elseif ($error): ?>
      <div class="alert alert-error">❌ Ошибка: <?= e($error) ?></div>
      <form method="post"><button class="btn" type="submit">Попробовать снова</button></form>
    <?php else: ?>
      <form method="post"><button class="btn" type="submit">Создать таблицы</button></form>
    <?php endif; ?>

    <div style="margin-top:16px;">
      <a href="index.html" class="btn btn-secondary" style="padding:8px 14px;font-size:14px;">← На рабочее место</a>
    </div>
  </div>
</div>
</body>
</html>
