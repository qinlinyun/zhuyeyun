<?php

/**
 * 远程上传后端门面（实现位于 lib/）
 */
require_once __DIR__ . '/lib/bootstrap.php';
require_once __DIR__ . '/includes/ui.php';

function uploadBackendDefaultConfig(): array
{
    return BackendConfig::get();
}

function uploadBackendConfig(): array
{
    return BackendConfig::get();
}

function uploadBackendCsrfToken(): string
{
    if (empty($_SESSION['upload_backend_csrf'])) {
        $_SESSION['upload_backend_csrf'] = bin2hex(random_bytes(32));
    }

    return (string)$_SESSION['upload_backend_csrf'];
}

function uploadBackendVerifyCsrf(?string $token): bool
{
    return is_string($token)
        && isset($_SESSION['upload_backend_csrf'])
        && hash_equals((string)$_SESSION['upload_backend_csrf'], $token);
}

function uploadBackendValidateApiToken(string $token): bool
{
    return BackendToken::validateApiToken($token);
}

function uploadBackendParseUserUploadToken(string $token): ?array
{
    return BackendToken::parseUserUploadToken($token);
}

function uploadBackendJson(array $payload, int $status = 200): void
{
    BackendSupport::json($payload, $status);
}

function uploadBackendResolveUploadDomain(): string
{
    $domain = trim((string)(BackendConfig::get()['UPLOAD_DOMAIN'] ?? ''));
    if ($domain !== '') {
        if (!preg_match('#^https?://#i', $domain)) {
            $domain = 'https://' . ltrim($domain, '/');
        }

        return rtrim($domain, '/');
    }

    return uploadBackendDetectServiceRootUrl();
}

function uploadBackendDetectServiceRootUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return '';
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    $rootPath = preg_replace('#/api/upload(?:/.*)?$#', '', $script) ?? $script;
    $rootPath = preg_replace('#/api/[^/]+\.php$#', '', $rootPath) ?? $rootPath;
    if (!preg_match('#\.php$#i', basename($rootPath))) {
        $rootPath = rtrim($rootPath, '/');
    } else {
        $rootPath = rtrim(dirname($rootPath), '/');
    }

    return $scheme . '://' . $host . ($rootPath !== '' && $rootPath !== '/' ? $rootPath : '');
}

function uploadBackendApiUrl(string $file): string
{
    $root = uploadBackendResolveUploadDomain();
    if ($root === '') {
        return '';
    }

    return rtrim($root, '/') . '/api/upload/' . ltrim($file, '/');
}

function uploadBackendResolveEmbedPageUrl(): string
{
    $base = uploadBackendResolveUploadDomain();
    if ($base === '') {
        return '';
    }

    return $base . '/embed_upload.php';
}

function uploadBackendSendEmbedFrameHeaders(): void
{
    if (headers_sent()) {
        return;
    }

    $ancestors = [];
    $mainSite = trim((string)(BackendConfig::get()['MAIN_SITE_URL'] ?? ''));
    if ($mainSite !== '') {
        foreach (preg_split('/[\s,;]+/', $mainSite) ?: [] as $part) {
            $part = trim($part);
            if ($part === '') {
                continue;
            }
            if (!preg_match('#^https?://#i', $part)) {
                $part = 'https://' . ltrim($part, '/');
            }
            $ancestors[] = rtrim($part, '/');
        }
    }

    $ancestors = array_values(array_unique($ancestors));
    if ($ancestors === []) {
        header('Content-Security-Policy: frame-ancestors *');
    } else {
        header('Content-Security-Policy: frame-ancestors ' . implode(' ', $ancestors));
    }
}

function uploadBackendIsLoggedIn(): bool
{
    return !empty($_SESSION['upload_backend_admin']);
}

function uploadBackendWantsJson(): bool
{
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function uploadBackendRequireLogin(): void
{
    if (!uploadBackendIsLoggedIn()) {
        if (uploadBackendWantsJson()) {
            uploadBackendJson(['ok' => false, 'message' => '登录状态已过期，请重新登录后再上传'], 401);
            exit;
        }
        header('Location: login.php');
        exit;
    }
}

function uploadBackendUrl(string $path): string
{
    return rtrim((string)(BackendConfig::get()['MAIN_SITE_URL'] ?? ''), '/') . '/' . ltrim($path, '/');
}

/**
 * @param array<string, string> $extraHeaders
 */
function uploadBackendPostJson(string $url, array $payload, array $extraHeaders = []): array
{
    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    if ($body === false) {
        return ['ok' => false, 'error' => '生成主站 API 请求失败'];
    }
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'error' => '服务器未启用 curl'];
    }
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    foreach ($extraHeaders as $key => $value) {
        $headers[] = $key . ': ' . $value;
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 15,
        CURLOPT_TIMEOUT => 300,
        CURLOPT_HTTPHEADER => $headers,
    ]);
    $response = curl_exec($ch);
    $curlError = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($response === false) {
        return ['ok' => false, 'error' => '无法连接主站 API：' . $curlError];
    }
    $data = json_decode((string)$response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'error' => '主站 API 返回格式错误（HTTP ' . $status . '）'];
    }
    if ($status >= 400 && empty($data['ok'])) {
        return ['ok' => false, 'error' => (string)($data['message'] ?? $data['error'] ?? '主站 API 请求失败')];
    }

    return $data;
}

/** @param array<string, mixed> $payload */
function uploadBackendRegisterReviewOnMainSite(array $payload): array
{
    $apiToken = trim((string)(BackendConfig::get()['API_TOKEN'] ?? ''));
    if ($apiToken === '') {
        return ['ok' => false, 'error' => '未配置 API_TOKEN，无法向主站登记审核'];
    }

    $url = uploadBackendUrl('api/upload_complete_remote.php');
    if ($url === '/api/upload_complete_remote.php') {
        return ['ok' => false, 'error' => '未配置 MAIN_SITE_URL'];
    }

    return uploadBackendPostJson($url, $payload, [
        'X-Remote-Api-Token' => $apiToken,
    ]);
}

function uploadBackendSaveUploadedVideo(array $file, array $meta = []): array
{
    return BackendStorage::saveUploadedFile($file, $meta);
}

function uploadBackendStoragePath(string $relativePath): string
{
    return BackendStorage::absolutePath($relativePath);
}

function uploadBackendStorageRoot(): string
{
    return BackendConfig::storageRoot();
}

function uploadBackendEnsureDir(string $dir): bool
{
    return BackendStorage::ensureDir($dir);
}

function uploadBackendNormalizeRelativePath(string $relativePath): string
{
    return BackendSupport::normalizeRelativePath($relativePath);
}

function uploadBackendFindStoredVideo(string $relativePath): ?array
{
    return BackendStorage::findVideo($relativePath);
}

function uploadBackendTranscodeApprovedVideo(array $upload, string $relativePath): array
{
    return BackendReview::approve($upload, $relativePath);
}

function uploadBackendDeleteFileIfExists(string $relativePath, array $upload = []): bool
{
    return BackendStorage::deleteFile($relativePath, $upload);
}

function uploadBackendDeletePathTree(string $path): bool
{
    return BackendStorage::deletePathTree($path);
}

function uploadBackendDeletePublishedMedia(array $mediaPaths): int
{
    return BackendReview::deletePublishedMedia($mediaPaths);
}

function uploadBackendSaveOriginalIfExists(string $relativePath, array $upload = []): array
{
    return BackendReview::saveOriginal($relativePath, $upload);
}

function uploadBackendReadOriginalRecords(): array
{
    return BackendReview::readOriginalIndex();
}

function uploadBackendDeleteOriginalRecord(string $id): bool
{
    return BackendReview::deleteOriginalRecord($id);
}

/** storage 内相对路径对应的公网直链（原始文件下载用，无签名/代理） */
function uploadBackendStorageDirectUrl(string $relativePath): string
{
    $relativePath = uploadBackendNormalizeRelativePath($relativePath);
    if ($relativePath === '') {
        return '';
    }

    return BackendTranscode::buildStoragePublicUrl(str_replace(DIRECTORY_SEPARATOR, '/', $relativePath));
}

function uploadBackendMaxUploadBytes(): int
{
    return BackendConfig::maxUploadBytes();
}

function uploadBackendPostMaxBytes(): int
{
    return BackendSupport::iniBytes((string)ini_get('post_max_size'));
}

function uploadBackendUploadMaxFilesizeBytes(): int
{
    return BackendSupport::iniBytes((string)ini_get('upload_max_filesize'));
}

function uploadBackendFormatBytes(int $bytes): string
{
    return BackendSupport::formatBytes($bytes);
}

function uploadBackendBinaryAvailable(string $path): bool
{
    if (method_exists(BackendSupport::class, 'binaryAvailable')) {
        return BackendSupport::binaryAvailable($path);
    }

    if ($path === '') {
        return false;
    }

    if (PHP_OS_FAMILY === 'Windows') {
        return $path !== '' && @is_file($path);
    }

    if (!function_exists('exec')) {
        return $path !== '' && @is_file($path);
    }

    $cmd = escapeshellarg($path) . ' -version 2>&1';
    exec($cmd, $output, $code);

    return $code === 0;
}

function uploadBackendAuthenticateUploadRequest(): ?array
{
    $uploadToken = trim((string)($_POST['upload_token'] ?? $_SERVER['HTTP_X_UPLOAD_TOKEN'] ?? ''));
    $payload = $uploadToken !== '' ? uploadBackendParseUserUploadToken($uploadToken) : null;
    if ($payload !== null) {
        return $payload;
    }
    $apiToken = (string)($_POST['api_token'] ?? $_SERVER['HTTP_X_API_TOKEN'] ?? '');
    if (uploadBackendValidateApiToken($apiToken)) {
        return ['uid' => 0, 'exp' => time() + 3600, 'nonce' => 'service', 'sid' => ''];
    }

    return null;
}

/** 分片上传鉴权：用户令牌 / API Token / 后台管理员会话 */
function uploadBackendAuthenticateChunkRequest(): ?array
{
    $auth = uploadBackendAuthenticateUploadRequest();
    if ($auth !== null) {
        return $auth;
    }
    if (uploadBackendIsLoggedIn()) {
        $admin = $_SESSION['upload_backend_admin'] ?? [];

        return [
            'uid' => max(1, (int)($admin['id'] ?? 1)),
            'exp' => time() + 7200,
            'nonce' => 'admin-session',
            'sid' => session_id() ?: 'admin',
        ];
    }

    return null;
}
