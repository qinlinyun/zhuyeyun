<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../includes/mail_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mailServerJsonResponse(['ok' => false, 'message' => '请使用 POST'], 405);
}

mailServerRequireApiKey();
set_time_limit(60);

$input = mailServerReadJsonInput();
$to = trim((string)($input['to'] ?? ''));
if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    mailServerJsonResponse(['ok' => false, 'message' => '请提供有效的 to 邮箱'], 400);
}

$subject = '竹叶云控邮局测试邮件';
$body = "这是一封来自独立邮局系统的测试邮件。\n\n发送时间：" . date('Y-m-d H:i:s');

$result = mailServerSend($to, $subject, $body, false);
if (empty($result['ok'])) {
    mailServerJsonResponse([
        'ok' => false,
        'message' => $result['message'] ?? '发送失败',
    ], 500);
}

mailServerJsonResponse([
    'ok' => true,
    'message' => '测试邮件已发送至 ' . $to,
]);
