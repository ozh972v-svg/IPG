<?php
/**
 * PWA-вставки для всех страниц IPG.
 * Подключать ВНУТРИ <head>, после <title> и <meta viewport>:
 *   <?php include __DIR__ . '/pwa.php'; ?>
 *
 * Если страница — чистый HTML (index.html), вставь её содержимое
 * без <?php ?> напрямую в <head>.
 */
?>
<link rel="manifest" href="/manifest.webmanifest">
<meta name="theme-color" content="#0b1220">
<meta name="apple-mobile-web-app-capable" content="yes">
<meta name="mobile-web-app-capable" content="yes">
<meta name="apple-mobile-web-app-status-bar-style" content="black-translucent">
<meta name="apple-mobile-web-app-title" content="IPG">
<link rel="apple-touch-icon" href="/icons/apple-touch-icon.png">
<script>
  if ('serviceWorker' in navigator) {
    window.addEventListener('load', function () {
      navigator.serviceWorker.register('/sw.js').catch(function (err) {
        console.warn('SW registration failed:', err);
      });
    });
  }
</script>
