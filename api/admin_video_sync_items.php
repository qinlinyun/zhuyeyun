<?php
require_once __DIR__ . '/../includes/admin_json.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/video_data_sync.php';

requireAdminJson();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
$result = fetchVideoSyncItemsFromBackend($pdo);

if (!$result['ok']) {
    http_response_code(400);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
