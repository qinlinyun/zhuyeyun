<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/redis_config.php';

requireAdmin();
header('Content-Type: application/json; charset=utf-8');

$override = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $override = [
        'enabled' => true,
        'host' => trim((string)($_POST['redis_host'] ?? '127.0.0.1')),
        'port' => (int)($_POST['redis_port'] ?? 6379),
        'password' => (string)($_POST['redis_password'] ?? ''),
        'database' => (int)($_POST['redis_database'] ?? 0),
    ];
}

$result = testRedisConnection($override);
echo json_encode([
    'ok' => $result['ok'],
    'message' => $result['message'],
    'detail' => $result['detail'] ?? '',
    'extension_loaded' => isRedisExtensionLoaded(),
], JSON_UNESCAPED_UNICODE);
