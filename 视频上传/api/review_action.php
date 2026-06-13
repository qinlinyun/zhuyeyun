<?php
require_once __DIR__ . '/../common.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    uploadBackendJson(['ok' => false, 'error' => '方法不允许'], 405);
    exit;
}

$raw = file_get_contents('php://input');
$data = json_decode($raw ?: '', true);
if (!is_array($data)) {
    $data = $_POST;
}

$token = (string)($data['api_token'] ?? '');
if (!uploadBackendValidateApiToken($token)) {
    uploadBackendJson(['ok' => false, 'error' => 'API Token 无效'], 403);
    exit;
}

$action = (string)($data['action'] ?? '');
$upload = is_array($data['upload'] ?? null) ? $data['upload'] : [];
$storedFilename = (string)($upload['stored_filename'] ?? '');
$backendFileId = (string)($upload['backend_file_id'] ?? '');
$relativeFile = $storedFilename !== '' ? $storedFilename : $backendFileId;

if ($action === 'approve') {
    @set_time_limit(0);
    ignore_user_abort(true);

    if ($relativeFile === '') {
        uploadBackendJson(['ok' => false, 'error' => '缺少待审核视频文件路径'], 400);
        exit;
    }
    $result = uploadBackendTranscodeApprovedVideo($upload, $relativeFile);
    uploadBackendJson($result, empty($result['ok']) ? 500 : 200);
    exit;
}

if ($action === 'reject') {
    $deleted = uploadBackendDeleteFileIfExists($relativeFile, $upload);
    uploadBackendJson([
        'ok' => true,
        'message' => $deleted ? '审核失败，后端已删除相关视频文件' : '审核失败，未找到可删除的视频文件',
        'deleted_original' => $deleted,
    ]);
    exit;
}

if ($action === 'save_original') {
    if ($relativeFile === '') {
        uploadBackendJson(['ok' => false, 'error' => '缺少要保存的原始文件路径'], 400);
        exit;
    }
    $result = uploadBackendSaveOriginalIfExists($relativeFile, $upload);
    uploadBackendJson([
        'ok' => !empty($result['ok']),
        'message' => !empty($result['ok']) ? '后端已保存原始 mp4 文件' : (string)($result['error'] ?? '未找到可保存的原始文件'),
        'record' => $result['record'] ?? null,
    ], empty($result['ok']) ? 404 : 200);
    exit;
}

if ($action === 'delete_video') {
    $deletedOriginal = uploadBackendDeleteFileIfExists($relativeFile, $upload);
    $mediaPaths = is_array($data['media_paths'] ?? null) ? $data['media_paths'] : [];
    $deletedMedia = uploadBackendDeletePublishedMedia($mediaPaths);
    uploadBackendJson([
        'ok' => true,
        'message' => '后端已同步删除相关视频文件',
        'deleted_original' => $deletedOriginal,
        'deleted_media_count' => $deletedMedia,
    ]);
    exit;
}

uploadBackendJson(['ok' => false, 'error' => '未知审核动作'], 400);
