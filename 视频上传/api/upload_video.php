<?php

/**
 * 兼容：整文件直传（小文件或旧客户端）
 */
require_once __DIR__ . '/../common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    uploadBackendJson(['ok' => false, 'error' => '方法不允许'], 405);
    exit;
}

$contentLength = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
$postMaxBytes = uploadBackendPostMaxBytes();
if ($postMaxBytes > 0 && $contentLength > $postMaxBytes && empty($_POST) && empty($_FILES)) {
    uploadBackendJson([
        'ok' => false,
        'error' => '超过 post_max_size：' . uploadBackendFormatBytes($postMaxBytes),
    ], 413);
    exit;
}

$auth = uploadBackendAuthenticateUploadRequest();
if ($auth === null) {
    uploadBackendJson(['ok' => false, 'error' => '上传令牌或 API Token 无效'], 403);
    exit;
}

$file = $_FILES['video_file'] ?? null;
if (!is_array($file)) {
    uploadBackendJson(['ok' => false, 'error' => '未收到 video_file 字段'], 400);
    exit;
}

$targetRelative = trim(str_replace('\\', '/', (string)($_POST['stored_filename'] ?? $_POST['target_relative'] ?? '')), '/');
$result = uploadBackendSaveUploadedVideo($file, [
    'title' => $_POST['title'] ?? '',
    'description' => $_POST['description'] ?? '',
    'user_id' => (int)($auth['uid'] ?? 0),
    'target_relative' => $targetRelative,
]);
uploadBackendJson($result, empty($result['ok']) ? 400 : 200);
