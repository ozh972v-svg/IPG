<?php
require __DIR__ . '/db.php';
start_session();

$user = current_user();

header('Content-Type: application/json; charset=utf-8');

if (!$user) {
    echo json_encode(['authorized' => false]);
    exit;
}

echo json_encode([
    'authorized' => true,
    'name' => $user['name'] ?: $user['email'],
    'login' => $user['email'],
    'is_admin' => (bool)$user['is_admin'],
]);
