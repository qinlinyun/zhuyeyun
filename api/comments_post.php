<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/comments.php';
require_once __DIR__ . '/../includes/user_profile.php';

header('Content-Type: application/json; charset=utf-8');

requireLogin();
$user = getCurrentUser();
if (!$user || !canUseSiteFeatures($user)) {
    echo json_encode(['ok' => false, 'message' => '账户不可用'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
ensureCommentsSchema($pdo);

$videoId = (int)($_POST['video_id'] ?? 0);
$content = (string)($_POST['content'] ?? '');
$parentRaw = $_POST['parent_id'] ?? null;
$parentId = ($parentRaw !== null && $parentRaw !== '') ? (int)$parentRaw : null;

$result = commentCreate($pdo, $videoId, (int)$user['id'], $content, $parentId);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
