<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/watch_progress_service.php';

requireLogin();

header('Content-Type: text/event-stream; charset=utf-8');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');

@ini_set('zlib.output_compression', 0);
@ini_set('output_buffering', 'off');
@ini_set('implicit_flush', 1);
while (ob_get_level() > 0) {
    ob_end_flush();
}
ob_implicit_flush(true);

$mode = ($_GET['mode'] ?? 'user') === 'admin' ? 'admin' : 'user';
$lastId = (int)($_SERVER['HTTP_LAST_EVENT_ID'] ?? 0);
$uid = (int)($_SESSION['user_id'] ?? 0);

if ($mode === 'admin' && !isAdmin()) {
    echo "event:error\ndata:{}\n\n";
    exit;
}

if (isRedisWatchProgressAvailable()) {
    try {
        watchProgressStreamRedisSse($mode, $uid, 30);
        exit;
    } catch (Throwable $e) {
        echo "event:error\ndata:" . json_encode(['msg' => 'redis_sse_failed']) . "\n\n";
        flush();
    }
}

$pdo = getDB();
$start = time();
$maxSeconds = 25;
$sleepSec = 2;

echo "event:hello\ndata:{\"mode\":\"mysql\"}\n\n";
flush();

while (time() - $start < $maxSeconds) {
    if (connection_aborted()) {
        break;
    }

    if ($mode === 'admin') {
        $stmt = $pdo->prepare('
            SELECT id FROM watch_progress_events
            WHERE id > ?
            ORDER BY id ASC
            LIMIT 1
        ');
        $stmt->execute([$lastId]);
    } else {
        $stmt = $pdo->prepare('
            SELECT id FROM watch_progress_events
            WHERE id > ? AND target_user_id = ?
            ORDER BY id ASC
            LIMIT 1
        ');
        $stmt->execute([$lastId, $uid]);
    }

    $row = $stmt->fetch();

    if ($row) {
        $lastId = (int)$row['id'];
        echo "id: {$lastId}\n";
        echo "event:update\n";
        echo "data: {}\n\n";
        flush();
        break;
    }

    echo "event:ping\ndata:{}\n\n";
    flush();
    sleep($sleepSec);
}
