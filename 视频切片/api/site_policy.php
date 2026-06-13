<?php

require_once __DIR__ . '/../includes/bootstrap.php';
require_once __DIR__ . '/../includes/play_token.php';
require_once __DIR__ . '/../includes/site_policy.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => '请使用 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode((string)$raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$cfg = getPlayTokenConfig();
if ($cfg['api_secret'] === '') {
    http_response_code(503);
    echo json_encode(['ok' => false, 'message' => 'API 未配置'], JSON_UNESCAPED_UNICODE);
    exit;
}

$proxyEnabled = (string)($input['proxy_enabled'] ?? '0');
$antiDownload = (string)($input['anti_download'] ?? '0');
$tokenAuto = (string)($input['token_auto_duration'] ?? '0');
$exp = (int)($input['exp'] ?? 0);
$sign = (string)($input['sign'] ?? '');

if (!sitePolicyVerifySign($cfg['api_secret'], $proxyEnabled, $antiDownload, $tokenAuto, $exp, $sign)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '签名校验失败或请求已过期'], JSON_UNESCAPED_UNICODE);
    exit;
}

$result = saveSitePolicy([
    'proxy_enabled' => $proxyEnabled === '1',
    'anti_download' => $antiDownload === '1',
    'token_auto_duration' => $tokenAuto === '1',
]);

if (!$result['success']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $result['message']], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode(['ok' => true, 'message' => $result['message']], JSON_UNESCAPED_UNICODE);
