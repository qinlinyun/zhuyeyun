<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/watch_progress_service.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_SESSION['user_id'] ?? 0);
$videoId = (int)($_GET['video_id'] ?? 0);
$episodeId = (int)($_GET['episode_id'] ?? 0);

if ($userId <= 0 || $videoId <= 0 || $episodeId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'bad params']);
    exit;
}

$row = watchProgressGet(getDB(), $userId, $videoId, $episodeId);

echo json_encode([
    'ok' => true,
    'progress' => $row['progress_seconds'],
    'duration' => $row['duration_seconds'],
    'redis' => isRedisWatchProgressAvailable(),
]);
