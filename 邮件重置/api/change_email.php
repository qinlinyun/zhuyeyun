<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/email_change.php';
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => '请使用 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'message' => '请先登录', 'login_url' => '../login.php'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = getCurrentUser();
if (!$user || !canUseSiteFeatures($user)) {
    echo json_encode(['ok' => false, 'message' => '账户不可用'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$result = processEmailChange(
    $pdo,
    (string)($_POST['username'] ?? ''),
    (string)($_POST['email'] ?? ''),
    (int)$user['id']
);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
