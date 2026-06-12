<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/mail_sender.php';

requireAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => '请使用 POST 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$testEmail = trim((string)($_POST['test_email'] ?? ''));
if ($testEmail === '' || !filter_var($testEmail, FILTER_VALIDATE_EMAIL)) {
    echo json_encode(['ok' => false, 'message' => '请填写有效的测试收件邮箱'], JSON_UNESCAPED_UNICODE);
    exit;
}

set_time_limit(60);

$pdo = getDB();
$result = sendSiteMailTest($pdo, $testEmail);

if (!empty($result['ok'])) {
    echo json_encode([
        'ok' => true,
        'message' => '测试邮件已发送至 ' . $testEmail,
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => false,
    'message' => $result['message'] ?? '测试邮件发送失败',
], JSON_UNESCAPED_UNICODE);
