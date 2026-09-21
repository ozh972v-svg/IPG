<?php
require_once __DIR__ . '/db.php';
start_session();
$user = current_user();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Выбор марки — Рабочее место инженера по гарантии</title>
<style>
    * { box-sizing: border-box; }
    body {
        margin: 0;
        font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, sans-serif;
        background: #f1f3f6;
        color: #1f2937;
        min-height: 100vh;
    }
    .topbar {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 16px 24px;
        background: #fff;
        border-bottom: 1px solid #e5e7eb;
    }
    .topbar h1 {
        margin: 0;
        font-size: 20px;
        font-weight: 600;
    }
    .topbar .user {
        display: flex;
        gap: 12px;
        align-items: center;
        font-size: 14px;
        color: #4b5563;
    }
    .topbar .user a {
        color: #2563eb;
        text-decoration: none;
    }
    main {
        max-width: 960px;
        margin: 0 auto;
        padding: 32px 24px;
    }
    main > h2 {
        font-size: 22px;
        margin: 0 0 8px;
    }
    main > p.lead {
        color: #6b7280;
        margin: 0 0 24px;
    }
    .tiles {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
        gap: 20px;
    }
    .tile {
        display: block;
        padding: 28px 24px;
        background: #fff;
        border: 1px solid #e5e7eb;
        border-radius: 14px;
        text-decoration: none;
        color: inherit;
        transition: transform .12s ease, box-shadow .12s ease, border-color .12s ease;
        box-shadow: 0 1px 2px rgba(0,0,0,.03);
    }
    .tile:hover {
        transform: translateY(-2px);
        box-shadow: 0 8px 20px rgba(0,0,0,.07);
        border-color: #cbd5e1;
    }
    .tile .icon {
        font-size: 40px;
        line-height: 1;
        margin-bottom: 14px;
    }
    .tile h3 {
        margin: 0 0 6px;
        font-size: 20px;
        font-weight: 600;
    }
    .tile p {
        margin: 0;
        color: #6b7280;
        font-size: 14px;
        line-height: 1.5;
    }
    .back {
        display: inline-block;
        margin-bottom: 20px;
        color: #2563eb;
        text-decoration: none;
        font-size: 14px;
    }
</style>
</head>
<body>

<div class="topbar">
    <h1>🔧 Рабочее место инженера по гарантии</h1>
    <div class="user">
        <?php if ($user): ?>
            <span>👤 <?= e($user['name'] ?? $user['login'] ?? 'Пользователь') ?></span>
            <a href="logout.php">Выйти</a>
        <?php else: ?>
            <a href="login.php">Войти</a>
        <?php endif; ?>
    </div>
</div>

<main>
    <a class="back" href="index.html">← На главную</a>
    <h2>Выберите марку автомобиля</h2>
    <p class="lead">Справочник работ и операций зависит от марки.</p>

    <div class="tiles">
        <a class="tile" href="works.php">
            <div class="icon">🔧</div>
            <h3>КАМАЗ</h3>
            <p>Поиск работ по VIN через 1С:ГОА. Категории: предпродажная, гарантия, ТО, коммерческая, ОТМ.</p>
        </a>

        <a class="tile" href="works_compass.php">
            <div class="icon">🚚</div>
            <h3>КОМПАС</h3>
            <p>Справочник работ по моделям Компас 5 / 6 / 9 / 12. Нормочасы по операциям.</p>
        </a>
    </div>
</main>

</body>
</html>
