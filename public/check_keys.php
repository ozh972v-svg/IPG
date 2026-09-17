<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();
if (!$user || !$user['is_admin']) {
    http_response_code(403);
    exit('Только для админа');
}

$pdo = get_db();

echo "<pre style='font-family:monospace;font-size:13px;padding:20px;'>";

echo "=== Все записи в keys ===\n\n";
$rows = $pdo->query("SELECT * FROM keys ORDER BY key_type, key_value")->fetchAll();
foreach ($rows as $r) {
    echo "key_type={$r['key_type']}  key_value={$r['key_value']}  "
       . "gos=" . ($r['gos_number'] ?? '—') . "  "
       . "order=" . ($r['order_number'] ?? '—') . "  "
       . "user_id=" . ($r['user_id'] ?? '—') . "  "
       . "created_at=" . ($r['created_at'] ?? '—') . "\n";
}

echo "\n=== Дубликаты (key_type, key_value) ===\n\n";
$dups = $pdo->query("
    SELECT key_type, key_value, COUNT(*) AS cnt
      FROM keys
     GROUP BY key_type, key_value
    HAVING COUNT(*) > 1
")->fetchAll();

if (!$dups) {
    echo "Дубликатов нет.\n";
} else {
    foreach ($dups as $d) {
        echo "{$d['key_type']} / {$d['key_value']}  →  {$d['cnt']} шт.\n";
    }
}

echo "\n=== Индексы таблицы keys ===\n\n";
$idx = $pdo->query("
    SELECT indexname, indexdef
      FROM pg_indexes
     WHERE tablename = 'keys'
")->fetchAll();
foreach ($idx as $i) {
    echo $i['indexname'] . "\n  " . $i['indexdef'] . "\n\n";
}

echo "</pre>";
