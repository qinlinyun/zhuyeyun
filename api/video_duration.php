<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/m3u8_duration.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'message' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = getCurrentUser();
if (!$user || !canUseSiteFeatures($user)) {
    echo json_encode(['ok' => false, 'message' => '账户不可用'], JSON_UNESCAPED_UNICODE);
    exit;
}

$videoId = (int)($_GET['video_id'] ?? $_POST['video_id'] ?? 0);
if ($videoId <= 0) {
    echo json_encode(['ok' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$st = $pdo->prepare('SELECT * FROM videos WHERE id = ? LIMIT 1');
$st->execute([$videoId]);
$video = $st->fetch();
if (!$video) {
    echo json_encode(['ok' => false, 'message' => '视频不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

$duration = m3u8_duration_for_video($pdo, $user, $video, isAdmin());
if ($duration === null) {
    echo json_encode(['ok' => false, 'message' => '无法获取时长'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'duration' => $duration,
], JSON_UNESCAPED_UNICODE);
