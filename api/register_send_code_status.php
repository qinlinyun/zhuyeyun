<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/settings.php';
require_once __DIR__ . '/../includes/register_verify.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => '请使用 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$email = trim((string)($_POST['email'] ?? ''));
$result = getRegisterVerifySendStatus($pdo, $email);

$response = [
    'ok' => !empty($result['ok']),
    'retry_after' => (int)($result['retry_after'] ?? 0),
    'resend_remaining' => (int)($result['resend_remaining'] ?? 0),
    'resend_interval' => (int)($result['resend_interval'] ?? 60),
];
if (!empty($result['message'])) {
    $response['message'] = (string)$result['message'];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
