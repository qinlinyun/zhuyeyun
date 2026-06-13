<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/video_sync.php';
require_once __DIR__ . '/../includes/play_token.php';

requireDatabaseInstalled();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET' && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => '请使用 GET 或 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$exp = (int)($_GET['exp'] ?? $_POST['exp'] ?? 0);
$sign = (string)($_GET['sign'] ?? $_POST['sign'] ?? '');

$syncCfg = getVideoSyncConfig();
$playCfg = getPlayTokenConfig();
$secrets = array_values(array_unique(array_filter([
    $syncCfg['api_secret'],
    $playCfg['api_secret'],
])));

if ($secrets === []) {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => '视频后端未配置 API 密钥'], JSON_UNESCAPED_UNICODE);
    exit;
}

$verified = false;
foreach ($secrets as $secret) {
    if (videoSyncVerifyListRequest($secret, $exp, $sign)) {
        $verified = true;
        break;
    }
}

if (!$verified) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '签名校验失败或请求已过期'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'items' => videoSyncExportRecords(),
], JSON_UNESCAPED_UNICODE);
