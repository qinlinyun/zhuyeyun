<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/comments.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();
$user = getCurrentUser();
if (!$user) {
    echo json_encode(['ok' => false, 'message' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
ensureCommentsSchema($pdo);

$commentId = (int)($_POST['comment_id'] ?? 0);
if ($commentId <= 0) {
    echo json_encode(['ok' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = commentDelete($pdo, $commentId, (int)$user['id'], isAdmin());
echo json_encode($result, JSON_UNESCAPED_UNICODE);
