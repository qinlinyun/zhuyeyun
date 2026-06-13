<?php

require_once __DIR__ . '/../../common.php';

set_time_limit(0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    uploadBackendJson(['ok' => false, 'error' => '方法不允许'], 405);
    exit;
}

$raw = file_get_contents('php://input');
$json = json_decode($raw ?: '', true);
$data = is_array($json) ? $json : $_POST;

$auth = uploadBackendAuthenticateChunkRequest();
if ($auth === null) {
    uploadBackendJson(['ok' => false, 'error' => '上传令牌无效或未登录'], 403);
    exit;
}

$sessionId = trim((string)($data['session_id'] ?? $_SERVER['HTTP_X_SESSION_ID'] ?? ($auth['sid'] ?? '')));
$result = BackendChunkUpload::mergeToMp4($sessionId, (int)$auth['uid']);
uploadBackendJson($result, empty($result['ok']) ? 400 : 200);
