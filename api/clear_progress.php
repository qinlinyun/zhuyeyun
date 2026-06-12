<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/watch_progress_service.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['ok' => false, 'msg' => 'unauthorized']);
    exit;
}

watchProgressClearUser(getDB(), $userId);
echo json_encode(['ok' => true]);
