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
ensureUserProfileSchema($pdo);

$videoId = (int)($_GET['video_id'] ?? 0);
$page = max(1, (int)($_GET['page'] ?? 1));
if ($videoId <= 0) {
    echo json_encode(['ok' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = commentFetchForVideo(
    $pdo,
    $videoId,
    $page,
    (int)$user['id'],
    isAdmin()
);

echo json_encode([
    'ok' => true,
    'items' => $result['items'],
    'total' => $result['total'],
    'page' => $result['page'],
    'pages' => $result['pages'],
], JSON_UNESCAPED_UNICODE);
