<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/wheel.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isAdmin()) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '权限不足'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
ensureWheelSchema($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $config = wheelLoadConfig($pdo);
    echo json_encode(['ok' => true, 'config' => $config], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => 'Method Not Allowed'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$payload = json_decode($raw !== false ? $raw : '', true);
if (!is_array($payload)) {
    $payload = $_POST;
}

wheelSaveConfig($pdo, $payload);
$config = wheelLoadConfig($pdo);

echo json_encode([
    'ok' => true,
    'message' => '配置已保存',
    'config' => $config,
], JSON_UNESCAPED_UNICODE);
