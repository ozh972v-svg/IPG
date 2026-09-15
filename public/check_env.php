<?php
header('Content-Type: text/html; charset=utf-8');
?>
<!DOCTYPE html>
<html lang="ru">
<head>
<meta charset="UTF-8">
<title>Проверка окружения PHP</title>
<style>
  body { font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; background:#f0f2f5; padding:24px; color:#1a1a1a; line-height:1.6; }
  .container { max-width:800px; margin:0 auto; background:#fff; border-radius:14px; padding:24px; box-shadow:0 2px 12px rgba(0,0,0,0.06); }
  h1 { font-size:20px; margin:0 0 16px; }
  table { width:100%; border-collapse:collapse; font-size:14px; }
  th, td { padding:10px 12px; text-align:left; border-bottom:1px solid #f0f0f0; }
  th { background:#f9fafb; font-weight:600; color:#666; font-size:12px; text-transform:uppercase; }
  .ok { color:#16a34a; font-weight:600; }
  .no { color:#dc2626; font-weight:600; }
  code { background:#f3f4f6; padding:2px 6px; border-radius:4px; font-size:12px; }
</style>
</head>
<body>
<div class="container">
  <h1>🔬 Проверка PHP-окружения</h1>
  <table>
    <thead><tr><th>Функция / модуль</th><th>Статус</th><th>Детали</th></tr></thead>
    <tbody>
      <tr>
        <td>PHP version</td>
        <td class="ok"><?= PHP_VERSION ?></td>
        <td>—</td>
      </tr>
      <tr>
        <td><code>simplexml_load_string</code></td>
        <td class="<?= function_exists('simplexml_load_string') ? 'ok' : 'no' ?>">
          <?= function_exists('simplexml_load_string') ? '✅ есть' : '❌ нет' ?>
        </td>
        <td><?= function_exists('simplexml_load_string') ? 'ext-simplexml установлен' : 'модуль отсутствует' ?></td>
      </tr>
      <tr>
        <td><code>DOMDocument</code></td>
        <td class="<?= class_exists('DOMDocument') ? 'ok' : 'no' ?>">
          <?= class_exists('DOMDocument') ? '✅ есть' : '❌ нет' ?>
        </td>
        <td>—</td>
      </tr>
      <tr>
        <td><code>xml_parser_create</code></td>
        <td class="<?= function_exists('xml_parser_create') ? 'ok' : 'no' ?>">
          <?= function_exists('xml_parser_create') ? '✅ есть' : '❌ нет' ?>
        </td>
        <td>SAX-парсер (ext-xml)</td>
      </tr>
      <tr>
        <td><code>libxml_use_internal_errors</code></td>
        <td class="<?= function_exists('libxml_use_internal_errors') ? 'ok' : 'no' ?>">
          <?= function_exists('libxml_use_internal_errors') ? '✅ есть' : '❌ нет' ?>
        </td>
        <td>—</td>
      </tr>
      <tr>
        <td><code>curl_init</code></td>
        <td class="<?= function_exists('curl_init') ? 'ok' : 'no' ?>">
          <?= function_exists('curl_init') ? '✅ есть' : '❌ нет' ?>
        </td>
        <td>—</td>
      </tr>
    </tbody>
  </table>

  <h2 style="font-size:16px;margin:24px 0 12px;">🧪 Тест парсинга вложенного XML</h2>
  <?php
  $testXml = '<?xml version="1.0" encoding="UTF-8"?>
<root><WorkOperations><Parent><ItIsGroup>true</ItIsGroup><Code>10</Code><Name>Двигатель</Name>
<Parent><ItIsGroup>false</ItIsGroup><Code>F001</Code><Name>Заменить прокладку</Name></Parent>
</Parent><Parent><ItIsGroup>true</ItIsGroup><Code>20</Code><Name>Трансмиссия</Name>
<Parent><ItIsGroup>false</ItIsGroup><Code>F002</Code><Name>Заменить масло</Name></Parent>
</Parent></WorkOperations></root>';

  $cleanXml = preg_replace('/<\?xml[^>]*\?>/i', '', $testXml, 1);

  // SimpleXML
  echo '<p><b>SimpleXML:</b> ';
  if (function_exists('simplexml_load_string')) {
      $prev = libxml_use_internal_errors(true);
      $sx = @simplexml_load_string($cleanXml, 'SimpleXMLElement', LIBXML_NOCDATA | LIBXML_NONET);
      $errs = libxml_get_errors();
      libxml_clear_errors();
      libxml_use_internal_errors($prev);
      if ($sx) {
          $nodes = $sx->xpath('//*[local-name()="WorkOperations"]');
          $cnt = $nodes ? count($nodes[0]->children()) : 0;
          echo '<span class="ok">✅ работает, найдено прямых детей: ' . $cnt . '</span>';
      } else {
          echo '<span class="no">❌ не работает</span>';
          if ($errs) {
              echo ' — ошибка: <code>' . htmlspecialchars($errs[0]->message) . '</code>';
          }
      }
  } else {
      echo '<span class="no">❌ функция отсутствует</span>';
  }
  echo '</p>';

  // DOMDocument
  echo '<p><b>DOMDocument:</b> ';
  if (class_exists('DOMDocument')) {
      $doc = new DOMDocument();
      $ok = @$doc->loadXML($cleanXml, LIBXML_NONET | LIBXML_NOCDATA);
      if ($ok) {
          echo '<span class="ok">✅ работает</span>';
      } else {
          echo '<span class="no">❌ не работает</span>';
      }
  } else {
      echo '<span class="no">❌ класса нет</span>';
  }
  echo '</p>';

  // xml_parser_create (SAX)
  echo '<p><b>xml_parser_create (SAX):</b> ';
  if (function_exists('xml_parser_create')) {
      $parser = xml_parser_create('UTF-8');
      if ($parser) {
          echo '<span class="ok">✅ работает, парсер создан</span>';
          xml_parser_free($parser);
      } else {
          echo '<span class="no">❌ не удалось создать парсер</span>';
      }
  } else {
      echo '<span class="no">❌ функция отсутствует</span>';
  }
  echo '</p>';

  // phpinfo-like: какие модули
  echo '<h2 style="font-size:16px;margin:24px 0 12px;">📦 Установленные модули PHP</h2>';
  $mods = get_loaded_extensions();
  sort($mods);
  echo '<p style="font-size:13px;">';
  foreach ($mods as $m) {
      $cls = in_array($m, ['SimpleXML', 'dom', 'xml', 'xmlreader', 'xmlwriter', 'libxml', 'curl', 'mbstring']) ? 'ok' : '';
      echo '<span class="' . $cls . '">' . e_html($m) . '</span> · ';
  }
  echo '</p>';

  function e_html($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
  ?>
</div>
</body>
</html>
