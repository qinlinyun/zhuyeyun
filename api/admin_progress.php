<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'msg' => 'forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$videoId   = (int)($_GET['video_id'] ?? 0);
$episodeId = (int)($_GET['episode_id'] ?? 0);

if ($videoId <= 0 || $episodeId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'bad params']);
    exit;
}

$pdo = getDB();

$stmt = $pdo->prepare("
    SELECT u.username, p.progress_seconds, p.duration_seconds, p.updated_at
    FROM video_watch_progress p
    JOIN users u ON u.id = p.user_id
    WHERE p.video_id = ? AND p.episode_id = ?
    ORDER BY p.updated_at DESC
    LIMIT 200
");
$stmt->execute([$videoId, $episodeId]);
$list = $stmt->fetchAll();

echo json_encode(['ok' => true, 'list' => $list]);
