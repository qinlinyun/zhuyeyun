<?php

/**
 * 用户视频上传模块门面（实现位于 includes/upload/）
 */
require_once __DIR__ . '/upload/bootstrap.php';

function getUploadModeConfig(PDO $pdo): array
{
    return UploadConfig::getMode($pdo);
}

function saveUploadModeConfig(PDO $pdo, array $data): void
{
    UploadConfig::saveMode($pdo, $data);
}

function ensureUploadLocalDirectories(array $config, string $baseDir): array
{
    return UploadConfig::ensureLocalDirectories($config, $baseDir);
}

function getUploadApiConfig(PDO $pdo): array
{
    return UploadConfig::getApi($pdo);
}

function saveUploadApiConfig(PDO $pdo, array $data): void
{
    UploadConfig::saveApi($pdo, $data);
}

function normalizeUploadRemoteBackendUrl(string $url): string
{
    return UploadSupport::normalizeRemoteBackendUrl($url);
}

function normalizeUploadBaseUrl(string $url): string
{
    return UploadSupport::normalizeBaseUrl($url);
}

function resolveUploadBackendRoot(array $apiConfig): string
{
    return UploadConfig::resolveBackendRoot($apiConfig);
}

function resolveUploadVideoApiEndpoint(array $apiConfig): string
{
    $endpoints = resolveUploadVideoApiEndpoints($apiConfig);

    return $endpoints[0] ?? '';
}

function resolveUploadVideoApiEndpoints(array $apiConfig): array
{
    return UploadConfig::resolveLegacyVideoEndpoints($apiConfig);
}

function resolveUploadChunkApiEndpoints(array $apiConfig): array
{
    return UploadConfig::resolveChunkEndpoints($apiConfig);
}

function resolveUploadFinishApiEndpoints(array $apiConfig): array
{
    return UploadConfig::resolveFinishEndpoints($apiConfig);
}

function resolveUploadEmbedPageUrl(PDO $pdo): string
{
    return UploadConfig::resolveEmbedUploadPageUrl(getUploadApiConfig($pdo));
}

function resolveUploadMobilePageUrl(PDO $pdo): string
{
    return UploadConfig::resolveMobileUploadPageUrl(getUploadApiConfig($pdo));
}

function isMobileUploadUserAgent(?string $userAgent = null): bool
{
    $ua = $userAgent ?? (string)($_SERVER['HTTP_USER_AGENT'] ?? '');

    return (bool)preg_match('/Android|webOS|iPhone|iPad|iPod|Mobile/i', $ua);
}

function uploadBase64UrlEncode(string $value): string
{
    return UploadSupport::base64UrlEncode($value);
}

function uploadBase64UrlDecode(string $value): string
{
    return UploadSupport::base64UrlDecode($value);
}

function uploadBuildSignedToken(array $payload, string $secret): array
{
    return UploadTokenService::buildSigned($payload, $secret);
}

function uploadParseSignedToken(string $token, string $secret): ?array
{
    return UploadTokenService::parseSigned($token, $secret);
}

function buildUploadBackendInstallerToken(int $adminId, string $secret, int $ttlSeconds = 900): array
{
    return UploadTokenService::buildInstallerToken($adminId, $secret, $ttlSeconds);
}

function buildUploadUserToken(int $userId, string $secret, int $ttlSeconds = 3600): array
{
    return UploadTokenService::buildUserToken($userId, $secret, $ttlSeconds);
}

function parseUploadUserToken(string $token, string $secret): ?array
{
    return UploadTokenService::parseUserToken($token, $secret);
}

function rememberUploadTokenNonce(int $userId, string $nonce, int $exp): void
{
    UploadTokenService::rememberNonce(getDB(), $userId, $nonce, $exp);
}

function consumeUploadTokenNonce(int $userId, string $nonce): bool
{
    return UploadTokenService::consumeNonce(getDB(), $userId, $nonce);
}

function generateUploadApiToken(): string
{
    return UploadConfig::generateApiToken();
}

function uploadFileErrorMessage(int $code): string
{
    return UploadSupport::fileErrorMessage($code);
}

function uploadResponseSnippet(string $response): string
{
    return UploadSupport::responseSnippet($response);
}

function uploadValidateVideoFile(array $file): ?string
{
    return UploadService::validateVideoFile($file);
}

function uploadNormalizeRelativePath(string $relative): string
{
    return UploadSupport::normalizeRelativePath($relative);
}

function getUploadPhpConfig(PDO $pdo): array
{
    return UploadConfig::getPhp($pdo);
}

function saveUploadPhpConfig(PDO $pdo, array $data): void
{
    UploadConfig::savePhp($pdo, $data);
}

function isUploadPhpReady(PDO $pdo): bool
{
    return UploadConfig::isPhpUploadReady($pdo);
}

function prepareUserVideoUpload(PDO $pdo, int $userId, string $originalFilename): array
{
    return UploadService::prepareUserUpload($pdo, $userId, $originalFilename);
}

/** @deprecated 主站不再中转视频文件 */
function submitUserVideoUpload(PDO $pdo, int $userId, array $file, array $meta): array
{
    return UploadService::submitUserVideo($pdo, $userId, $file, $meta);
}

function testUploadBackendConnection(PDO $pdo): array
{
    return UploadService::testBackendConnection($pdo);
}

/** @deprecated */
function getUploadFtpConfig(PDO $pdo): array
{
    return UploadConfig::getFtp($pdo);
}

/** @deprecated */
function saveUploadFtpConfig(PDO $pdo, array $data): void
{
    UploadConfig::savePhp($pdo, $data);
}

/** @deprecated */
function isUploadFtpConfigured(PDO $pdo): bool
{
    return UploadConfig::isPhpUploadReady($pdo);
}

function saveUploadedVideoToLocal(array $file, array $modeConfig, string $baseDir): array
{
    unset($modeConfig, $baseDir);

    return ['ok' => false, 'error' => '已改为 FTP 上传，请使用 uploadVideoViaFtp'];
}

function uploadInitSession(PDO $pdo, int $userId, array $input): array
{
    return UploadService::initUpload($pdo, $userId, $input);
}

function getUploadVideoConfig(PDO $pdo): array
{
    return UploadConfig::getVideo($pdo);
}

function saveUploadVideoConfig(PDO $pdo, array $data): void
{
    UploadConfig::saveVideo($pdo, $data);
}

function ensureUserVideoUploadsTable(PDO $pdo): void
{
    UploadService::ensureTable($pdo);
}

function uploadReviewStatusLabels(): array
{
    return UploadService::statusLabels();
}

function uploadReviewStatusClass(string $status): string
{
    return UploadService::statusClass($status);
}

function createUserVideoUpload(PDO $pdo, int $userId, array $data): int
{
    return UploadService::createRecord($pdo, $userId, $data);
}

function fetchUserVideoUploads(PDO $pdo, int $userId): array
{
    return UploadService::fetchByUser($pdo, $userId);
}

function fetchUploadReviewList(PDO $pdo): array
{
    return UploadService::fetchReviewList($pdo);
}

function notifyUploadBackendAction(PDO $pdo, array $upload, string $action, array $extra = []): array
{
    return UploadService::notifyBackend($pdo, $upload, $action, $extra);
}

function notifyUploadBackendReviewAction(PDO $pdo, array $upload, string $action): array
{
    return UploadService::notifyBackend($pdo, $upload, $action);
}

function applyUploadVideoPublishSettings(PDO $pdo, array $upload, array $backendResult): void
{
    UploadService::applyPublishSettings($pdo, $upload, $backendResult);
}

function uploadNotifyUser(PDO $pdo, int $userId, string $title, string $content, int $adminId): void
{
    UploadService::notifyUser($pdo, $userId, $title, $content, $adminId);
}

function uploadNormalizeCoverUrl(PDO $pdo, string $path): string
{
    return UploadSupport::resolveCoverUrl($pdo, $path);
}

function uploadNormalizeMediaPath(string $path): string
{
    return UploadSupport::normalizeMediaPath($path);
}

function uploadVideoRecordMap(PDO $pdo): array
{
    return UploadService::videoRecordMap($pdo);
}

function saveUploadVideoRecordMap(PDO $pdo, array $map): void
{
    UploadService::saveVideoRecordMap($pdo, $map);
}

function fetchManagedUploadedVideos(PDO $pdo): array
{
    return UploadService::fetchManaged($pdo);
}

function getManagedUploadedVideo(PDO $pdo, int $uploadId): ?array
{
    return UploadService::getManaged($pdo, $uploadId);
}

function updateManagedUploadedVideoStatus(PDO $pdo, int $uploadId, string $status, int $adminId): array
{
    return UploadService::updateManagedStatus($pdo, $uploadId, $status, $adminId);
}

function editManagedUploadedVideo(PDO $pdo, int $uploadId, array $data): array
{
    return UploadService::editManaged($pdo, $uploadId, $data);
}

function deleteManagedUploadedVideo(PDO $pdo, int $uploadId, int $adminId): array
{
    return UploadService::deleteManaged($pdo, $uploadId, $adminId);
}

function reviewUserVideoUpload(PDO $pdo, int $uploadId, string $action, int $adminId): array
{
    return UploadService::review($pdo, $uploadId, $action, $adminId);
}

function postUploadedVideoToRemote(array $apiConfig, array $file, array $fields): array
{
    if ($error = UploadService::validateVideoFile($file)) {
        return ['ok' => false, 'error' => $error];
    }

    $endpoint = resolveUploadVideoApiEndpoint($apiConfig);
    $token = (string)($apiConfig['remote_api_token'] ?? '');
    if ($endpoint === '' || $token === '') {
        return ['ok' => false, 'error' => '远程后端地址或 API Token 未配置'];
    }

    $payload = array_merge($fields, ['api_token' => $token]);
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => '服务器未启用 curl，无法代传远程后端'];
    }

    $mime = function_exists('mime_content_type') ? (mime_content_type((string)$file['tmp_name']) ?: 'application/octet-stream') : 'application/octet-stream';
    $payload['video_file'] = new CURLFile((string)$file['tmp_name'], $mime, (string)$file['name']);

    $ch = curl_init($endpoint);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);

    if ($response === false) {
        return ['ok' => false, 'error' => '无法连接远程上传后端：' . $curlError];
    }

    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => '远程上传后端返回格式错误'];
    }
    if ($status >= 400 && empty($data['ok'])) {
        return ['ok' => false, 'error' => (string)($data['error'] ?? $data['message'] ?? '远程上传失败')];
    }

    return $data;
}
