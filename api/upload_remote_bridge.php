<?php

/**
 * 跨域备用：浏览器 → 主站（同域）→ 远程上传后端
 * 视频经主站内存转发，不落盘；用于浏览器无法跨域访问远程后端时。
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/upload_config.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => '请使用 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$uploadToken = trim((string)($_POST['upload_token'] ?? ''));
$secret = trim((string)(getUploadApiConfig($pdo)['remote_api_token'] ?? ''));
$tokenPayload = $uploadToken !== '' && $secret !== '' ? parseUploadUserToken($uploadToken, $secret) : null;
if (!is_array($tokenPayload) || (int)$tokenPayload['uid'] !== $userId) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'message' => '上传令牌无效或已过期'], JSON_UNESCAPED_UNICODE);
    exit;
}

$file = $_FILES['video_file'] ?? null;
if (!is_array($file)) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'message' => '未收到视频文件'], JSON_UNESCAPED_UNICODE);
    exit;
}

@set_time_limit(0);
$result = UploadService::relayVideoToBackend($pdo, $file, $_POST);
http_response_code(!empty($result['ok']) ? 200 : 400);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
