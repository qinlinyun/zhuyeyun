<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/user_login_analytics.php';

requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
echo json_encode([
    'ok' => true,
    'items' => getUserLoginRanking($pdo),
], JSON_UNESCAPED_UNICODE);
