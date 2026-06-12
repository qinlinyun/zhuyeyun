<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/wheel.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = getCurrentUser();
if (!$user || !canUseSiteFeatures($user)) {
    echo json_encode(['ok' => false, 'message' => '账户不可用'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(50, (int)($_GET['per_page'] ?? 10)));
$result = wheelListRecords($pdo, $page, $perPage, (int)$user['id']);

echo json_encode([
    'ok' => true,
    'records' => $result,
], JSON_UNESCAPED_UNICODE);
