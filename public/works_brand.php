<?php
require_once __DIR__ . '/db.php';
start_session();
$user = current_user();

$pageTitle    = 'Выбор марки';
$pageSubtitle = 'справочник работ · IPG';
$backLink     = 'index.html';
$backLabel    = 'На главную';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Выбор марки — Рабочее место инженера по гарантии</title>
<link rel="stylesheet" href="app.css">
<?php include __DIR__ . '/pwa.php'; ?>
<style>
  /* Локальные стили только для плиток марки */
  .brand-hero {
    text-align: center;
    margin-bottom: 28px;
    padding: 6px 0;
  }
  .brand-hero h1 {
    font-size: 26px;
    letter-spacing: -0.02em;
    margin-bottom: 6px;
  }
  .brand-hero p {
    color: var(--ink-soft);
    font-size: 15px;
  }

  .brand-tiles {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(320px, 1fr));
    gap: 22px;
  }

  .brand-tile {
    position: relative;
    background: #fff;
    border-radius: 22px;
    padding: 34px 30px 30px;
    text-decoration: none;
    color: inherit;
    overflow: hidden;
    isolation: isolate;
    border: 1px solid rgba(255,255,255,0.85);
    box-shadow:
      0 1px 0 rgba(255,255,255,0.9) inset,
      0 10px 30px rgba(15, 23, 42, 0.06);
    transition: all 0.28s cubic-bezier(0.16, 1, 0.3, 1);
    display: flex;
    flex-direction: column;
    gap: 18px;
    min-height: 240px;
    animation: brandFadeUp 0.55s ease-out both;
  }
  .brand-tile:nth-child(1) { animation-delay: 0.08s; }
  .brand-tile:nth-child(2) { animation-delay: 0.16s; }
  .brand-tile:nth-child(3) { animation-delay: 0.24s; }

  @keyframes brandFadeUp {
    from { opacity: 0; transform: translateY(18px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  /* Анимированная цветная обводка */
  .brand-tile::before {
    content: '';
    position: absolute;
    inset: 0;
    border-radius: 22px;
    padding: 1.5px;
    background: linear-gradient(135deg, var(--c1, #2563eb), var(--c2, #06b6d4));
    -webkit-mask:
      linear-gradient(#fff 0 0) content-box,
      linear-gradient(#fff 0 0);
    -webkit-mask-composite: xor;
    mask-composite: exclude;
    opacity: 0;
    transition: opacity 0.28s;
    pointer-events: none;
  }
  .brand-tile:hover::before { opacity: 1; }

  .brand-tile:hover {
    transform: translateY(-6px);
    box-shadow:
      0 1px 0 rgba(255,255,255,0.9) inset,
      0 28px 55px -14px rgba(15, 23, 42, 0.22);
  }
  .brand-tile:active { transform: translateY(-3px); transition-duration: 0.1s; }

  .brand-glow {
    position: absolute;
    width: 260px;
    height: 260px;
    border-radius: 50%;
    background: radial-gradient(circle, var(--glow, rgba(37,99,235,0.20)), transparent 70%);
    top: -130px;
    right: -130px;
    pointer-events: none;
    transition: transform 0.6s ease-out;
  }
  .brand-tile:hover .brand-glow { transform: scale(1.3); }

  .brand-head {
    display: flex;
    align-items: center;
    gap: 16px;
    position: relative;
    z-index: 2;
  }

  .brand-icon {
    width: 68px;
    height: 68px;
    border-radius: 18px;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 32px;
    flex-shrink: 0;
    color: #fff;
    box-shadow: 0 14px 26px -10px var(--shadow, rgba(37,99,235,0.6));
    transition: transform 0.3s cubic-bezier(0.16, 1, 0.3, 1);
  }
  .brand-tile:hover .brand-icon {
    transform: scale(1.06) rotate(-4deg);
  }

  .brand-name {
    font-size: 24px;
    font-weight: 800;
    letter-spacing: -0.02em;
    color: var(--ink);
    line-height: 1.15;
  }
  .brand-tag {
    font-size: 12px;
    font-weight: 700;
    letter-spacing: 0.06em;
    text-transform: uppercase;
    color: var(--ink-soft);
    margin-top: 4px;
  }

  .brand-desc {
    position: relative;
    z-index: 2;
    font-size: 14.5px;
    line-height: 1.55;
    color: var(--ink-soft);
    flex: 1;
  }

  .brand-foot {
    position: relative;
    z-index: 2;
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 12px;
  }

  .brand-cta {
    display: inline-flex;
    align-items: center;
    gap: 8px;
    font-weight: 700;
    font-size: 14px;
    padding: 10px 18px;
    border-radius: 12px;
    color: #fff;
    background: linear-gradient(135deg, var(--c1, #2563eb), var(--c2, #06b6d4));
    box-shadow: 0 10px 22px -10px var(--shadow, rgba(37,99,235,0.6));
    transition: all 0.28s;
  }
  .brand-tile:hover .brand-cta {
    transform: translateX(4px);
    box-shadow: 0 14px 28px -10px var(--shadow, rgba(37,99,235,0.7));
  }

  .brand-cta .arr {
    transition: transform 0.28s;
  }
  .brand-tile:hover .brand-cta .arr {
    transform: translateX(3px);
  }

  /* Цвета плиток */
  .brand-kamaz {
    --c1: #2563eb;
    --c2: #06b6d4;
    --glow: rgba(59, 130, 246, 0.22);
    --shadow: rgba(37, 99, 235, 0.55);
  }
  .brand-kamaz .brand-icon {
    background: linear-gradient(135deg, #2563eb 0%, #06b6d4 100%);
  }

  .brand-compass {
    --c1: #7c3aed;
    --c2: #db2777;
    --glow: rgba(168, 85, 247, 0.22);
    --shadow: rgba(124, 58, 237, 0.55);
  }
  .brand-compass .brand-icon {
    background: linear-gradient(135deg, #7c3aed 0%, #db2777 100%);
  }

  .brand-foton {
    --c1: #dc2626;
    --c2: #f97316;
    --glow: rgba(220, 38, 38, 0.22);
    --shadow: rgba(220, 38, 38, 0.55);
  }
  .brand-foton .brand-icon {
    background: linear-gradient(135deg, #dc2626 0%, #f97316 100%);
  }

  @media (max-width: 640px) {
    .brand-hero h1 { font-size: 20px; }
    .brand-hero p { font-size: 13.5px; }
    .brand-tile { padding: 26px 22px; border-radius: 18px; min-height: 200px; }
    .brand-icon { width: 56px; height: 56px; font-size: 26px; border-radius: 15px; }
    .brand-name { font-size: 20px; }
    .brand-desc { font-size: 13.5px; }
  }
</style>
</head>
<body>

<div class="container">

  <?php include __DIR__ . '/header.php'; ?>

  <div class="brand-hero">
    <h1>Выберите марку автомобиля</h1>
    <p>Справочник работ и операций зависит от марки</p>
  </div>

  <div class="brand-tiles">

    <a class="brand-tile brand-kamaz" href="works.php">
      <div class="brand-glow"></div>
      <div class="brand-head">
        <div class="brand-icon">🔧</div>
        <div>
          <div class="brand-name">КАМАЗ</div>
          <div class="brand-tag">1С:ГОА · поиск по VIN</div>
        </div>
      </div>
      <div class="brand-desc">
        Поиск работ по VIN через 1С:ГОА. Гарантия, техническое обслуживание,
        предпродажная подготовка, коммерческий ремонт и ОТМ.
      </div>
      <div class="brand-foot">
        <span class="brand-cta">
          Перейти к справочнику <span class="arr">→</span>
        </span>
      </div>
    </a>

    <a class="brand-tile brand-compass" href="works_compass.php">
      <div class="brand-glow"></div>
      <div class="brand-head">
        <div class="brand-icon">🚚</div>
        <div>
          <div class="brand-name">КОМПАС</div>
          <div class="brand-tag">Компас 5 · 6 · 9 · 12</div>
        </div>
      </div>
      <div class="brand-desc">
        Справочник работ по моделям Компас 5, 6, 9 и 12. Нормочасы
        по операциям, дерево групп, поиск по названию и коду.
      </div>
      <div class="brand-foot">
        <span class="brand-cta">
          Перейти к справочнику <span class="arr">→</span>
        </span>
      </div>
    </a>

    <a class="brand-tile brand-foton" href="works_foton.php">
      <div class="brand-glow"></div>
      <div class="brand-head">
        <div class="brand-icon">🚛</div>
        <div>
          <div class="brand-name">ФОТОН</div>
          <div class="brand-tag">AUMAN · AUMARK · TOANO · …</div>
        </div>
      </div>
      <div class="brand-desc">
        Справочник работ по семействам ФОТОН — AUMAN, AUMARK, TOANO, SAUVANA,
        GRATOUR, TUNLAND, VIEW, SUP, Miler, LOXA, TM.
      </div>
      <div class="brand-foot">
        <span class="brand-cta">
          Перейти к справочнику <span class="arr">→</span>
        </span>
      </div>
    </a>

  </div>

</div>

</body>
</html>
