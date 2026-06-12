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

$result = sendRegisterVerificationCode($pdo, $email);

$response = [
    'ok' => !empty($result['ok']),
    'message' => (string)($result['message'] ?? ($result['ok'] ? 'ok' : '发送失败')),
];
if (isset($result['retry_after'])) {
    $response['retry_after'] = (int)$result['retry_after'];
}
if (isset($result['resend_remaining'])) {
    $response['resend_remaining'] = (int)$result['resend_remaining'];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
