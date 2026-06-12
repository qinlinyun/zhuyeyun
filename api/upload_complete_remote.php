<?php

/**
 * 远程上传后端代用户登记审核（服务端调用，凭 API Token + 用户 upload_token）
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/upload_config.php';

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => '请使用 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$apiConfig = getUploadApiConfig($pdo);
$secret = trim((string)($apiConfig['remote_api_token'] ?? ''));
if ($secret === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => '远程上传 Token 未配置'], JSON_UNESCAPED_UNICODE);
    exit;
}

$remoteToken = trim((string)($_SERVER['HTTP_X_REMOTE_API_TOKEN'] ?? $_SERVER['HTTP_X_UPLOAD_API_TOKEN'] ?? ''));
if ($remoteToken === '' || !hash_equals($secret, $remoteToken)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '远程 API 鉴权失败'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$json = json_decode($raw ?: '', true);
$data = is_array($json) ? $json : $_POST;

$uploadToken = trim((string)($data['upload_token'] ?? ''));
$tokenPayload = parseUploadUserToken($uploadToken, $secret);
if (!is_array($tokenPayload)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '上传令牌无效或已过期'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)($tokenPayload['uid'] ?? 0);
if ($userId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '上传令牌用户无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!consumeUploadTokenNonce($userId, (string)$tokenPayload['nonce'])) {
    http_response_code(409);
    echo json_encode([
        'ok' => false,
        'message' => '上传令牌无效、已过期或已使用，请返回主站重新打开上传页',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$storedFilename = trim((string)($data['stored_filename'] ?? ''));
$backendFileId = trim((string)($data['backend_file_id'] ?? ''));
if ($storedFilename === '' && $backendFileId === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => '缺少上传文件标识'], JSON_UNESCAPED_UNICODE);
    exit;
}
if (
    ($storedFilename !== '' && uploadNormalizeRelativePath($storedFilename) === '')
    || ($backendFileId !== '' && uploadNormalizeRelativePath($backendFileId) === '')
) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => '上传文件标识格式无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

try {
    $uploadId = createUserVideoUpload($pdo, $userId, [
        'title' => (string)($data['title'] ?? ''),
        'description' => (string)($data['description'] ?? ''),
        'original_filename' => trim((string)($data['original_filename'] ?? '')),
        'stored_filename' => $storedFilename !== '' ? $storedFilename : $backendFileId,
        'backend_file_id' => $backendFileId !== '' ? $backendFileId : $storedFilename,
        'is_traffic' => !empty($data['is_traffic']),
        'traffic_cost' => (string)($data['traffic_cost'] ?? '0'),
    ]);
} catch (Throwable $e) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
    exit;
}

echo json_encode([
    'ok' => true,
    'message' => '视频已提交，等待管理员审核',
    'upload_id' => $uploadId,
    'stored_filename' => $storedFilename !== '' ? $storedFilename : $backendFileId,
    'size_bytes' => max(0, (int)($data['size_bytes'] ?? 0)),
], JSON_UNESCAPED_UNICODE);
