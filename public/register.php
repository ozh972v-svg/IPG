<?php
require __DIR__ . '/db.php';
start_session();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = trim($_POST['email'] ?? '');
    $password = $_POST['password'] ?? '';
    $password2 = $_POST['password2'] ?? '';
    $name = trim($_POST['name'] ?? '');

    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Некорректный email';
    } elseif (strlen($password) < 6) {
        $error = 'Пароль должен быть не короче 6 символов';
    } elseif ($password !== $password2) {
        $error = 'Пароли не совпадают';
    } else {
        try {
            $pdo = get_db();
            $stmt = $pdo->prepare('SELECT id FROM users WHERE email = :email');
            $stmt->execute([':email' => $email]);
            if ($stmt->fetch()) {
                $error = 'Такой email уже зарегистрирован';
            } else {
                $hash = password_hash($password, PASSWORD_DEFAULT);
                $stmt = $pdo->prepare('INSERT INTO users (email, password_hash, name) VALUES (:email, :hash, :name) RETURNING id');
                $stmt->execute([':email' => $email, ':hash' => $hash, ':name' => $name]);
                $userId = $stmt->fetchColumn();
                $_SESSION['user_id'] = $userId;
                header('Location: gallery.php');
                exit;
            }
        } catch (Throwable $e) {
            $error = 'Ошибка: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Регистрация</title>
<style>
  body { font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif; background: #f0f2f5; margin: 0; padding: 20px; line-height: 1.5; }
  .card { max-width: 420px; margin: 40px auto; background: #fff; border-radius: 14px; padding: 24px; box-shadow: 0 2px 12px rgba(0,0,0,0.06); }
  h1 { font-size: 22px; margin: 0 0 20px; }
  label { display: block; font-size: 13px; font-weight: 600; color: #666; margin: 12px 0 6px; }
  input { width: 100%; padding: 12px; border: 1.5px solid #e5e7eb; border-radius: 10px; font-size: 15px; box-sizing: border-box; }
  input:focus { outline: none; border-color: #2563eb; }
  button { width: 100%; padding: 14px; border: none; border-radius: 10px; background: #2563eb; color: #fff; font-size: 16px; font-weight: 600; cursor: pointer; margin-top: 16px; }
  button:hover { opacity: 0.9; }
  .error { background: #fef2f2; color: #dc2626; padding: 12px; border-radius: 8px; font-size: 14px; margin-bottom: 12px; }
  .link { text-align: center; margin-top: 16px; font-size: 14px; }
  .link a { color: #2563eb; text-decoration: none; }
</style>
</head>
<body>
<div class="card">
  <h1>Регистрация</h1>
  <?php if ($error): ?><div class="error"><?= e($error) ?></div><?php endif; ?>
  <form method="post">
    <label>Имя (необязательно)</label>
    <input type="text" name="name" value="<?= e($_POST['name'] ?? '') ?>">
    <label>Email</label>
    <input type="email" name="email" required value="<?= e($_POST['email'] ?? '') ?>">
    <label>Пароль (минимум 6 символов)</label>
    <input type="password" name="password" required>
    <label>Повторите пароль</label>
    <input type="password" name="password2" required>
    <button type="submit">Зарегистрироваться</button>
  </form>
  <div class="link">Уже есть аккаунт? <a href="login.php">Войти</a></div>
</div>
</body>
</html>
