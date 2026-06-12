<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/watch_progress_service.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');

if (isAdmin()) {
    echo json_encode(['ok' => true, 'skipped' => true]);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'unauthorized']);
    exit;
}

$videoId = (int)($_POST['video_id'] ?? 0);
$episodeId = (int)($_POST['episode_id'] ?? 0);
$progress = (int)($_POST['progress'] ?? 0);
$duration = (int)($_POST['duration'] ?? 0);
$flushToDb = isset($_POST['flush']) && (string)$_POST['flush'] === '1';

$result = watchProgressSave(getDB(), $userId, $videoId, $episodeId, $progress, $duration, $flushToDb);

if (!$result['ok']) {
    echo json_encode(['ok' => false, 'msg' => 'bad params']);
    exit;
}

echo json_encode([
    'ok' => true,
    'storage' => $result['storage'] ?? 'mysql',
    'flushed' => !empty($result['flushed']),
]);
