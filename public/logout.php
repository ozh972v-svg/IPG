<?php
require __DIR__ . '/db.php';
start_session();
session_destroy();
header('Location: login.php');
exit;
