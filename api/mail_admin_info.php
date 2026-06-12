<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/mail_server_api.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => '请使用 GET'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
mailServerRequireApiKey($pdo);

$result = getSiteAdminPublicInfo($pdo);

if (empty($result['ok'])) {
    http_response_code(404);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
