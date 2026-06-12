<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/mail_broadcast.php';

header('Content-Type: application/json; charset=utf-8');

if (!isAdmin()) {
    echo json_encode(['ok' => false, 'message' => '无权限'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$action = trim((string)($_GET['action'] ?? $_POST['action'] ?? 'step'));

if ($action === 'status') {
    $job = getMailBroadcastJob($pdo);
    echo json_encode([
        'ok' => true,
        'job' => $job,
        'label' => mailBroadcastJobProgressLabel($job),
        'active' => mailBroadcastJobIsActive($job),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($action === 'step') {
    @set_time_limit(300);
    $result = processMailBroadcastStep($pdo);
    $response = [
        'ok' => !empty($result['ok']),
        'message' => (string)($result['message'] ?? ''),
        'job' => $result['job'] ?? getMailBroadcastJob($pdo),
        'label' => mailBroadcastJobProgressLabel($result['job'] ?? getMailBroadcastJob($pdo)),
        'done' => !empty($result['done']),
        'waiting' => !empty($result['waiting']),
    ];
    if (isset($result['wait_seconds'])) {
        $response['wait_seconds'] = (int)$result['wait_seconds'];
    }
    echo json_encode($response, JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => false, 'message' => '未知操作'], JSON_UNESCAPED_UNICODE);
