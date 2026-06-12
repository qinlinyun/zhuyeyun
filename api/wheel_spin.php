<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/wheel.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '请先登录', 'login_url' => 'login.php'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = getCurrentUser();
if (!$user || !canUseSiteFeatures($user)) {
    echo json_encode(['ok' => false, 'message' => '账户不可用'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$result = wheelPerformSpin($pdo, (int)$user['id']);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
