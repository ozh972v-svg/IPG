<?php
require __DIR__ . '/db.php';
header('Content-Type: text/plain; charset=utf-8');

$pdo = get_db();
$stmt = $pdo->query('SELECT id, key_value, file_path, storage_path FROM photos ORDER BY id DESC LIMIT 10');

foreach ($stmt as $row) {
    echo "ID: {$row['id']}\n";
    echo "  key_value: {$row['key_value']}\n";
    echo "  file_path: {$row['file_path']}\n";
    echo "  storage_path: " . ($row['storage_path'] ?? 'NULL') . "\n\n";
}
