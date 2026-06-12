<?php

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/player_backends.php';

const PLAYER_PROXY_SETTING_ENABLED = 'player_proxy_enabled';
const PLAYER_PROXY_SETTING_BACKEND = 'player_proxy_backend_url';
const PLAYER_PROXY_SETTING_BACKENDS = 'player_proxy_backend_urls';
const PLAYER_PROXY_SETTING_SECRET = 'player_proxy_api_secret';
const PLAYER_PROXY_SETTING_TTL = 'player_proxy_token_ttl';

function defaultPlayerProxyConfig(): array
{
    return [
        'enabled' => false,
        'backend_url' => '',
        'backends' => [],
        'backend_urls' => [],
        'api_secret' => '',
        'token_ttl' => 7200,
    ];
}

function getPlayerProxyConfig(PDO $pdo): array
{
    $cfg = defaultPlayerProxyConfig();
    $cfg['enabled'] = getSetting($pdo, PLAYER_PROXY_SETTING_ENABLED, '0') === '1';
    $cfg['backends'] = loadPlayerBackendEntries($pdo, PLAYER_PROXY_SETTING_BACKENDS, PLAYER_PROXY_SETTING_BACKEND);
    $cfg['backend_urls'] = playerBackendUrlsOnly($cfg['backends']);
    $cfg['backend_url'] = firstPlayerBackendUrl($cfg['backends']);
    $cfg['api_secret'] = trim((string)getSetting($pdo, PLAYER_PROXY_SETTING_SECRET, ''));
    $ttl = (int)getSetting($pdo, PLAYER_PROXY_SETTING_TTL, (string)$cfg['token_ttl']);
    $cfg['token_ttl'] = max(300, min(86400, $ttl > 0 ? $ttl : 7200));

    return $cfg;
}

function isPlayerProxyEnabled(PDO $pdo): bool
{
    $cfg = getPlayerProxyConfig($pdo);
    return $cfg['enabled']
        && $cfg['backend_urls'] !== []
        && $cfg['api_secret'] !== '';
}

function savePlayerProxyConfig(PDO $pdo, array $config): void
{
    setSetting($pdo, PLAYER_PROXY_SETTING_ENABLED, !empty($config['enabled']) ? '1' : '0');
    $entries = normalizePlayerBackendEntries($config['backends'] ?? []);
    if ($entries === [] && trim((string)($config['backend_url'] ?? '')) !== '') {
        $entries = normalizePlayerBackendEntries([['name' => '', 'url' => $config['backend_url']]]);
    }
    savePlayerBackendEntries($pdo, PLAYER_PROXY_SETTING_BACKENDS, PLAYER_PROXY_SETTING_BACKEND, $entries);
    setSetting($pdo, PLAYER_PROXY_SETTING_SECRET, trim((string)($config['api_secret'] ?? '')));
    $ttl = (int)($config['token_ttl'] ?? 7200);
    $ttl = max(300, min(86400, $ttl > 0 ? $ttl : 7200));
    setSetting($pdo, PLAYER_PROXY_SETTING_TTL, (string)$ttl);
}

/** 将配置中的地址规范为视频切片站点根 URL（去掉误填的 /api/xxx.php 后缀） */
function normalizePlayerBackendBaseUrl(string $url): string
{
    $url = trim(rtrim($url, '/'));
    if ($url === '') {
        return '';
    }

    $suffixes = [
        '/api/video_data_sync.php',
        '/api/video_sync_list.php',
        '/api/play_token.php',
        '/api/site_policy.php',
    ];
    foreach ($suffixes as $suffix) {
        $len = strlen($suffix);
        if ($len > 0 && strcasecmp(substr($url, -$len), $suffix) === 0) {
            $url = rtrim(substr($url, 0, -$len), '/');
        }
    }

    if (preg_match('#/api$#i', $url)) {
        $url = rtrim((string)preg_replace('#/api$#i', '', $url), '/');
    }

    return $url;
}

function playerProxyValidationError(array $config): ?string
{
    if (empty($config['enabled'])) {
        return null;
    }

    $entries = normalizePlayerBackendEntries($config['backends'] ?? []);
    if ($entries === [] && trim((string)($config['backend_url'] ?? '')) !== '') {
        $entries = normalizePlayerBackendEntries([['name' => '', 'url' => $config['backend_url']]]);
    }
    if ($err = playerBackendValidationError($entries, '开启后端代理时，请至少填写一个视频后端地址')) {
        return $err;
    }
    if (trim((string)($config['api_secret'] ?? '')) === '') {
        return '开启后端代理时，请填写与视频后端一致的 API 密钥';
    }
    if (strlen(trim((string)$config['api_secret'])) < 16) {
        return 'API 密钥至少 16 个字符';
    }

    return null;
}

/** 主站请求视频后端时使用的签名 */
function playerProxyRequestSign(string $secret, string $email, string $path, string $playHost, int $exp): string
{
    $payload = $email . '|' . $path . '|' . $playHost . '|' . $exp;
    return hash_hmac('sha256', $payload, $secret);
}

/** 与视频切片 playTokenStorageRelativePath 保持一致，用于签名与请求 */
function playerProxyNormalizeStoragePath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        $parts = parse_url($path);
        $path = (string)($parts['path'] ?? '');
    }
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, '..') !== false) {
        return '';
    }
    if (strpos($path, 'videos/') === 0) {
        $path = substr($path, 7);
    }

    return $path;
}

function playerProxyNormalizePlayHost(string $host): string
{
    $host = preg_replace('#^https?://#i', '', trim($host));

    return trim($host, '/');
}

/**
 * 向视频切片后端索取时效播放链接
 *
 * @return array{ok:bool,play_url?:string,mime?:string,expires_at?:int,message?:string}
 */
function fetchSignedPlayUrlFromBackend(
    string $backendBase,
    string $secret,
    string $email,
    string $relativePath,
    string $playHost,
    int $ttl,
    bool $autoTtl = false
): array {
    $backendBase = normalizePlayerBackendBaseUrl($backendBase);
    $relativePath = playerProxyNormalizeStoragePath($relativePath);
    $playHost = playerProxyNormalizePlayHost($playHost);
    if ($relativePath === '' || $playHost === '') {
        return ['ok' => false, 'message' => '播放路径或线路域名无效'];
    }

    $exp = time() + $ttl;
    $sign = playerProxyRequestSign($secret, $email, $relativePath, $playHost, $exp);

    $endpoint = $backendBase . '/api/play_token.php';
    $body = json_encode([
        'email' => $email,
        'path' => $relativePath,
        'play_host' => $playHost,
        'exp' => $exp,
        'sign' => $sign,
        'auto_ttl' => $autoTtl ? 1 : 0,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($endpoint);
    if ($ch === false) {
        return ['ok' => false, 'message' => '无法初始化请求'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 8,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $raw === false) {
        return ['ok' => false, 'message' => '连接视频后端失败'];
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => '视频后端返回格式错误'];
    }

    if ($httpCode >= 400 || empty($data['ok'])) {
        return ['ok' => false, 'message' => (string)($data['message'] ?? '获取播放链接失败')];
    }

    return [
        'ok' => true,
        'play_url' => (string)($data['play_url'] ?? ''),
        'mime' => (string)($data['mime'] ?? 'application/x-mpegURL'),
        'expires_at' => (int)($data['expires_at'] ?? $exp),
    ];
}

/**
 * 依次尝试多个视频后端，直到获取播放链接成功
 *
 * @param string[] $backendUrls
 * @return array{ok:bool,play_url?:string,mime?:string,expires_at?:int,message?:string,backend?:string}
 */
function fetchSignedPlayUrlFromAnyBackend(
    array $backendUrls,
    string $secret,
    string $email,
    string $relativePath,
    string $playHost,
    int $ttl,
    bool $autoTtl = false
): array {
    $backendUrls = array_values(array_filter(array_map('normalizePlayerBackendBaseUrl', $backendUrls)));
    if ($backendUrls === []) {
        return ['ok' => false, 'message' => '未配置视频后端地址'];
    }

    $errors = [];
    foreach ($backendUrls as $backendBase) {
        $result = fetchSignedPlayUrlFromBackend(
            $backendBase,
            $secret,
            $email,
            $relativePath,
            $playHost,
            $ttl,
            $autoTtl
        );
        if (!empty($result['ok'])) {
            $result['backend'] = $backendBase;

            return $result;
        }
        $errors[] = $backendBase . '：' . (string)($result['message'] ?? '失败');
    }

    return [
        'ok' => false,
        'message' => '所有视频后端均不可用（' . implode('；', $errors) . '）',
    ];
}

/**
 * 向多个视频后端推送 JSON POST
 *
 * @return array{ok:bool,message:string,success:int,failed:int}
 */
function postJsonToPlayerBackends(array $backendUrls, string $body, int $timeout = 15): array
{
    $backendUrls = array_values(array_filter(array_map('normalizePlayerBackendBaseUrl', $backendUrls)));
    if ($backendUrls === []) {
        return ['ok' => false, 'message' => '未配置视频后端地址', 'success' => 0, 'failed' => 0];
    }

    $success = 0;
    $failed = 0;
    $errors = [];

    foreach ($backendUrls as $backendBase) {
        $endpoint = rtrim($backendBase, '/') . '/api/site_policy.php';
        $ch = curl_init($endpoint);
        if ($ch === false) {
            $failed++;
            $errors[] = $backendBase . '：无法初始化请求';
            continue;
        }

        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'Accept: application/json',
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $raw === false) {
            $failed++;
            $errors[] = $backendBase . '：连接失败';
            continue;
        }

        $data = json_decode((string)$raw, true);
        if (!is_array($data) || $httpCode >= 400 || empty($data['ok'])) {
            $failed++;
            $errors[] = $backendBase . '：' . (string)($data['message'] ?? '请求失败');
            continue;
        }

        $success++;
    }

    if ($success > 0 && $failed === 0) {
        return [
            'ok' => true,
            'message' => '已同步到 ' . $success . ' 个视频后端',
            'success' => $success,
            'failed' => 0,
        ];
    }
    if ($success > 0) {
        return [
            'ok' => true,
            'message' => '已同步 ' . $success . ' 个，失败 ' . $failed . ' 个（' . implode('；', $errors) . '）',
            'success' => $success,
            'failed' => $failed,
        ];
    }

    return [
        'ok' => false,
        'message' => implode('；', $errors),
        'success' => 0,
        'failed' => $failed,
    ];
}
