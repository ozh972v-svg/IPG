<?php
/**
 * Общая шапка для всех страниц IPG.
 * Использование:
 *   $pageTitle    = 'Справочник работ';
 *   $pageSubtitle = 'КАМАЗ · 1С:ГОА';          // опционально
 *   $backLink     = 'index.html';               // опционально
 *   $backLabel    = 'На рабочее место';         // опционально
 *   include __DIR__ . '/header.php';
 */
if (!isset($user)) { $user = current_user(); }
$pageTitle    = $pageTitle    ?? 'Рабочее место';
$pageSubtitle = $pageSubtitle ?? 'инженер по гарантии · IPG';
?>
<div class="ipg-header">
  <div class="brand">
    <div class="brand-logo">🔧</div>
    <div>
      <div class="brand-title"><?= e($pageTitle) ?></div>
      <div class="brand-sub"><?= e($pageSubtitle) ?></div>
    </div>
  </div>
  <div class="user-nav">
    <?php if (!empty($backLink)): ?>
      <a href="<?= e($backLink) ?>" class="back">← <?= e($backLabel ?? 'Назад') ?></a>
    <?php endif; ?>
    <span class="uname">👤 <b><?= e($user['name'] ?: $user['email']) ?></b></span>
    <a href="profile.php" class="profile">Профиль</a>
    <?php if (!empty($user['is_admin'])): ?>
      <a href="admin.php" class="admin">🛡️ Админ</a>
    <?php endif; ?>
    <a href="logout.php" class="logout">Выйти</a>
  </div>
</div>
