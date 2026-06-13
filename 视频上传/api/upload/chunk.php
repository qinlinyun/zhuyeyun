<?php

require_once __DIR__ . '/../../common.php';

set_time_limit(0);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    uploadBackendJson(['ok' => false, 'error' => '方法不允许'], 405);
    exit;
}

$auth = uploadBackendAuthenticateChunkRequest();
if ($auth === null) {
    uploadBackendJson(['ok' => false, 'error' => '上传令牌无效或未登录'], 403);
    exit;
}

$userId = (int)$auth['uid'];
$sessionId = trim((string)($_POST['session_id'] ?? $_SERVER['HTTP_X_SESSION_ID'] ?? ($auth['sid'] ?? '')));
$chunkIndex = (int)($_POST['chunk_index'] ?? $_SERVER['HTTP_X_CHUNK_INDEX'] ?? -1);
$file = $_FILES['chunk'] ?? null;

if (!is_array($file)) {
    uploadBackendJson(['ok' => false, 'error' => '缺少分片文件 chunk'], 400);
    exit;
}

$result = BackendChunkUpload::saveChunk($sessionId, $userId, $chunkIndex, (string)$file['tmp_name']);
uploadBackendJson($result, empty($result['ok']) ? 400 : 200);
