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
$subject = trim((string)($input['subject'] ?? ''));
$body = (string)($input['body'] ?? '');
$isHtml = !empty($input['is_html']) || !empty($input['isHtml']);

if ($to === '' || !filter_var($to, FILTER_VALIDATE_EMAIL)) {
    mailServerJsonResponse(['ok' => false, 'message' => '请提供有效的 to 邮箱'], 400);
}
if ($subject === '') {
    mailServerJsonResponse(['ok' => false, 'message' => '请提供 subject'], 400);
}
if ($body === '') {
    mailServerJsonResponse(['ok' => false, 'message' => '请提供 body'], 400);
}

$result = mailServerSend($to, $subject, $body, $isHtml);
if (empty($result['ok'])) {
    mailServerJsonResponse([
        'ok' => false,
        'message' => $result['message'] ?? '发送失败',
    ], 500);
}

mailServerJsonResponse(['ok' => true, 'message' => '邮件已发送']);
