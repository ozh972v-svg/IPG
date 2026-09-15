<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user) {
    header('Location: login.php');
    exit;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Рабочее место инженера по гарантии</title>
<style>
  * { box-sizing: border-box; -webkit-tap-highlight-color: transparent; }
  body {
    font-family: -apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, sans-serif;
    background: #f0f2f5;
    margin: 0;
    padding: 24px 16px;
    color: #1a1a1a;
    line-height: 1.5;
  }
  .container { max-width: 900px; margin: 0 auto; }

  .top-bar {
    display: flex;
    justify-content: space-between;
    align-items: center;
    flex-wrap: wrap;
    gap: 12px;
    margin-bottom: 24px;
  }
  h1 {
    font-size: 24px;
    margin: 0;
    color: #1e3a8a;
  }
  h1 span { color: #2563eb; }

  .user-nav {
    display: flex;
    align-items: center;
    gap: 12px;
    flex-wrap: wrap;
    font-size: 13px;
  }
  .user-nav .uname { color: #666; }
  .user-nav .uname b { color: #2563eb; }
  .user-nav a {
    text-decoration: none;
    padding: 6px 12px;
    border-radius: 8px;
    font-weight: 600;
  }
  .user-nav a.profile { color: #2563eb; background: #eff6ff; }
  .user-nav a.admin { color: #dc2626; background: #fef2f2; }
  .user-nav a.logout { color: #666; background: #f3f4f6; }

  .tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
    gap: 16px;
    margin-bottom: 20px;
  }
  .tile {
    background: #fff;
    border-radius: 14px;
    padding: 28px 24px;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
    cursor: pointer;
    transition: all 0.2s;
    border: 2px solid transparent;
    display: flex;
    flex-direction: column;
    gap: 10px;
    text-decoration: none;
    color: inherit;
  }
  .tile:hover {
    transform: translateY(-3px);
    box-shadow: 0 6px 20px rgba(0,0,0,0.1);
  }
  .tile-primary:hover { border-color: #2563eb; }
  .tile-success:hover { border-color: #16a34a; }
  .tile-icon { font-size: 42px; line-height: 1; }
  .tile-title {
    font-size: 20px;
    font-weight: 700;
    color: #1a1a1a;
  }
  .tile-desc {
    font-size: 14px;
    color: #666;
  }
  .tile-primary { border-left: 5px solid #2563eb; }
  .tile-success { border-left: 5px solid #16a34a; }

  .footer {
    background: #fff;
    border-radius: 14px;
    padding: 16px;
    text-align: center;
    font-size: 13px;
    color: #888;
    box-shadow: 0 2px 12px rgba(0,0,0,0.06);
  }
</style>
</head>
<body>
<div class="container">

  <div class="top-bar">
    <h1>🔧 Рабочее место <span>инженера по гарантии</span></h1>
    <div class="user-nav">
      <span class="uname">👤 <b><?= e($user['name'] ?: $user['email']) ?></b></span>
      <a href="profile.php" class="profile">Профиль</a>
      <?php if ($user['is_admin']): ?>
        <a href="admin.php" class="admin">🛡️ Админ</a>
      <?php endif; ?>
      <a href="logout.php" class="logout">Выйти</a>
    </div>
  </div>

  <div class="tiles">
    <a href="search_vin.php" class="tile tile-primary">
      <div class="tile-icon">🔍</div>
      <div class="tile-title">Поиск по VIN</div>
      <div class="tile-desc">Данные по автотехнике из 1С:ГОА — гарантия, узлы, ОТМ, акции</div>
    </a>

    <a href="gallery.php" class="tile tile-success">
      <div class="tile-icon">📸</div>
      <div class="tile-title">Фото по РА</div>
      <div class="tile-desc">Прикреплять фото к рекламационным актам</div>
    </a>
  </div>

  <div class="footer">
    Данные сохраняются в облаке · Работает на RelaxDev · Версия 2.0
  </div>

</div>
</body>
</html>
