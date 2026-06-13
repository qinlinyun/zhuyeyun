<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/api_auth.php';
require_once __DIR__ . '/../includes/mail_service.php';

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    mailServerJsonResponse(['ok' => false, 'message' => '请使用 GET'], 405);
}

mailServerRequireApiKey();

$config = mailServerLoadConfig();
mailServerJsonResponse([
    'ok' => true,
    'message' => 'API 连接正常',
    'service' => 'zhuyeyun-mail-server',
    'version' => 1,
    'smtp_ready' => mailServerSmtpReady($config),
]);
