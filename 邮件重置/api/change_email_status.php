<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../includes/email_change.php';
require_once __DIR__ . '/../includes/bootstrap.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'message' => '请先登录', 'login_url' => '../login.php'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = getCurrentUser();
if (!$user || !canUseSiteFeatures($user)) {
    echo json_encode(['ok' => false, 'message' => '账户不可用'], JSON_UNESCAPED_UNICODE);
    exit;
}

$username = trim((string)($_GET['username'] ?? $_POST['username'] ?? ''));
if ($username === '') {
    echo json_encode(['ok' => false, 'message' => '请填写账号'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (strcasecmp($username, (string)$user['username']) !== 0) {
    echo json_encode(['ok' => false, 'message' => '只能查询当前登录账号'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$result = getEmailChangeStatus($pdo, $username);

echo json_encode($result, JSON_UNESCAPED_UNICODE);
