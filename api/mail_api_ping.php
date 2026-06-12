<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/mail_config.php';
require_once __DIR__ . '/../includes/mail_api_client.php';

requireAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => '请使用 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$cfg = getMailSmtpConfig($pdo);

$postUrl = trim((string)($_POST['api_url'] ?? ''));
$postKey = trim((string)($_POST['api_key'] ?? ''));
if ($postUrl !== '') {
    $cfg['api_url'] = $postUrl;
}
if ($postKey !== '') {
    $cfg['api_key'] = $postKey;
}

if (!isMailApiConfigured($cfg)) {
    echo json_encode(['ok' => false, 'message' => '请填写 API 地址与密钥（可先保存或直接填写后测试）'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = pingSiteMailApi($cfg);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
