<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/traffic.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'message' => '未登录']);
    exit;
}

$user = getCurrentUser();
if (!$user || !canUseSiteFeatures($user)) {
    echo json_encode(['ok' => false, 'message' => '账户不可用']);
    exit;
}

$pdo = getDB();
if (!trafficFeatureEnabled($pdo)) {
    echo json_encode(['ok' => false, 'message' => '系统未启用流量功能']);
    exit;
}

$videoId = (int)($_POST['video_id'] ?? $_GET['video_id'] ?? 0);
if ($videoId <= 0) {
    echo json_encode(['ok' => false, 'message' => '参数错误']);
    exit;
}

$st = $pdo->prepare("SELECT * FROM videos WHERE id = ?");
$st->execute([$videoId]);
$video = $st->fetch();
if (!$video) {
    echo json_encode(['ok' => false, 'message' => '视频不存在']);
    exit;
}

if (isAdmin()) {
    echo json_encode(['ok' => true, 'unlocked' => true, 'message' => '管理员无需解锁']);
    exit;
}

if (empty($video['is_traffic'])) {
    echo json_encode(['ok' => true, 'unlocked' => true, 'message' => '该视频无需流量']);
    exit;
}

if (trafficIsVideoUploader($video, (int)$user['id'])) {
    echo json_encode(['ok' => true, 'unlocked' => true, 'message' => '您上传的视频无需解锁'], JSON_UNESCAPED_UNICODE);
    exit;
}

// 触发一次到期自动重置，确保扣费基于最新流量状态
maybeAutoResetTraffic($pdo, (int)$user['id']);

$result = payAndUnlockVideo($pdo, (int)$user['id'], $video);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
