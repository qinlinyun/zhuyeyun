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

$fileName = trim((string)($data['file_name'] ?? ''));
$fileSize = (int)($data['file_size'] ?? 0);
$targetRelative = trim(str_replace('\\', '/', (string)($data['target_relative'] ?? $data['stored_filename'] ?? '')), '/');

if ($fileName === '' || $fileSize <= 0) {
    uploadBackendJson(['ok' => false, 'error' => '请提供有效的文件名与大小'], 400);
    exit;
}

if (!BackendChunkUpload::shouldUseChunkedUpload($fileSize)) {
    uploadBackendJson([
        'ok' => false,
        'error' => '文件不超过 ' . BackendSupport::formatBytes(BackendChunkUpload::CHUNK_THRESHOLD_BYTES) . '，请使用普通上传',
        'use_single_upload' => true,
    ], 400);
    exit;
}

if ($targetRelative !== '' && uploadBackendNormalizeRelativePath($targetRelative) === '') {
    uploadBackendJson(['ok' => false, 'error' => '目标保存路径无效'], 400);
    exit;
}

if ($fileSize > uploadBackendMaxUploadBytes()) {
    uploadBackendJson([
        'ok' => false,
        'error' => '视频不能超过 ' . uploadBackendFormatBytes(uploadBackendMaxUploadBytes()),
    ], 400);
    exit;
}

$chunkSize = BackendChunkUpload::resolveChunkSize((int)($data['chunk_size'] ?? 0));
$totalChunks = (int)ceil($fileSize / $chunkSize);
if ($totalChunks <= 0) {
    uploadBackendJson(['ok' => false, 'error' => '分片数量计算失败'], 400);
    exit;
}

try {
    $meta = BackendChunkUpload::createSession(
        (int)$auth['uid'],
        $fileName,
        $fileSize,
        $chunkSize,
        $totalChunks,
        $targetRelative
    );
} catch (Throwable $e) {
    uploadBackendJson(['ok' => false, 'error' => $e->getMessage()], 400);
    exit;
}

uploadBackendJson([
    'ok' => true,
    'mode' => 'chunk',
    'session_id' => (string)$meta['session_id'],
    'chunk_size' => (int)$meta['chunk_size'],
    'total_chunks' => (int)$meta['total_chunks'],
    'file_size' => $fileSize,
    'stored_filename' => $targetRelative,
    'init_url' => uploadBackendApiUrl('init.php'),
    'chunk_url' => uploadBackendApiUrl('chunk.php'),
    'finish_url' => uploadBackendApiUrl('finish.php'),
]);
