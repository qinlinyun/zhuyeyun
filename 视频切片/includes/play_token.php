<?php

const PLAY_TOKEN_SETTING_SECRET = 'api_play_secret';
const PLAY_TOKEN_SETTING_TTL = 'api_play_token_ttl';
const PLAY_TOKEN_SETTING_SIGNED_PATH = 'play_signed_script_path';

function playTokenDefaultConfig(): array
{
    return [
        'api_secret' => '',
        'token_ttl' => 7200,
        'signed_script_path' => '/play_signed.php',
    ];
}

function getPlayTokenConfig(): array
{
    $cfg = playTokenDefaultConfig();
    $cfg['api_secret'] = getDomainSetting(PLAY_TOKEN_SETTING_SECRET);
    $ttl = (int)getDomainSetting(PLAY_TOKEN_SETTING_TTL);
    $cfg['token_ttl'] = max(300, min(86400, $ttl > 0 ? $ttl : 7200));
    $path = getDomainSetting(PLAY_TOKEN_SETTING_SIGNED_PATH);
    $cfg['signed_script_path'] = $path !== '' ? $path : '/play_signed.php';
    if ($cfg['signed_script_path'][0] !== '/') {
        $cfg['signed_script_path'] = '/' . $cfg['signed_script_path'];
    }

    return $cfg;
}

function savePlayTokenConfig(array $config): array
{
    $secret = trim((string)($config['api_secret'] ?? ''));
    if ($secret === '') {
        return ['success' => false, 'message' => 'API 密钥不能为空'];
    }
    if (strlen($secret) < 16) {
        return ['success' => false, 'message' => 'API 密钥至少 16 个字符'];
    }

    $ttl = (int)($config['token_ttl'] ?? 7200);
    $ttl = max(300, min(86400, $ttl > 0 ? $ttl : 7200));

    $signedPath = trim((string)($config['signed_script_path'] ?? '/play_signed.php'));
    if ($signedPath === '') {
        $signedPath = '/play_signed.php';
    }
    if ($signedPath[0] !== '/') {
        $signedPath = '/' . $signedPath;
    }

    if (!setDomainSetting(PLAY_TOKEN_SETTING_SECRET, $secret)) {
        return ['success' => false, 'message' => '保存 API 密钥失败'];
    }
    if (!setDomainSetting(PLAY_TOKEN_SETTING_TTL, (string)$ttl)) {
        return ['success' => false, 'message' => '保存有效期失败'];
    }
    if (!setDomainSetting(PLAY_TOKEN_SETTING_SIGNED_PATH, $signedPath)) {
        return ['success' => false, 'message' => '保存签名脚本路径失败'];
    }

    return ['success' => true, 'message' => 'API 配置已保存'];
}

function playTokenNormalizePath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, '..') !== false) {
        return '';
    }

    return $path;
}

/** 去掉 URL 中的 videos/ 前缀，对应 upload_dir 下的相对路径 */
function playTokenStorageRelativePath(string $path): string
{
    $path = playTokenNormalizePath($path);
    if (strpos($path, 'videos/') === 0) {
        $path = substr($path, 7);
    }

    return $path;
}

function playTokenNormalizeHost(string $host): string
{
    $host = preg_replace('#^https?://#i', '', trim($host));
    return trim($host, '/');
}

function playTokenRequestSign(string $secret, string $email, string $path, string $playHost, int $exp): string
{
    $payload = $email . '|' . $path . '|' . $playHost . '|' . $exp;
    return hash_hmac('sha256', $payload, $secret);
}

function playTokenPlaySign(string $secret, string $email, string $path, int $exp): string
{
    $payload = 'play|' . $email . '|' . $path . '|' . $exp;
    return hash_hmac('sha256', $payload, $secret);
}

function playTokenVerifyRequest(string $secret, string $email, string $path, string $playHost, int $exp, string $sign): bool
{
    if ($exp < time()) {
        return false;
    }
    $expected = playTokenRequestSign($secret, $email, $path, $playHost, $exp);
    return hash_equals($expected, $sign);
}

/** 兼容主站传入带 videos/ 前缀或完整 URL 的路径 */
function playTokenVerifyRequestFlexible(
    string $secret,
    string $email,
    string $pathInput,
    string $playHost,
    int $exp,
    string $sign
): bool {
    if ($sign === '' || $exp < time()) {
        return false;
    }

    $variants = [];
    $normalized = playTokenNormalizePath($pathInput);
    if ($normalized !== '') {
        $variants[] = $normalized;
    }
    $storage = playTokenStorageRelativePath($pathInput);
    if ($storage !== '') {
        $variants[] = $storage;
    }
    $variants = array_values(array_unique($variants));

    foreach ($variants as $path) {
        if (playTokenVerifyRequest($secret, $email, $path, $playHost, $exp, $sign)) {
            return true;
        }
    }

    return false;
}

function playTokenVerifyPlay(string $secret, string $email, string $path, int $exp, string $sign): bool
{
    if ($exp < time()) {
        return false;
    }
    $expected = playTokenPlaySign($secret, $email, $path, $exp);
    return hash_equals($expected, $sign);
}

function playTokenEncodeEmail(string $email): string
{
    return rtrim(strtr(base64_encode($email), '+/', '-_'), '=');
}

function playTokenDecodeEmail(string $encoded): string
{
    $encoded = strtr($encoded, '-_', '+/');
    $pad = strlen($encoded) % 4;
    if ($pad > 0) {
        $encoded .= str_repeat('=', 4 - $pad);
    }
    $raw = base64_decode($encoded, true);
    return $raw === false ? '' : $raw;
}

function playTokenEncodePath(string $path): string
{
    return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
}

function playTokenDecodePath(string $encoded): string
{
    $encoded = strtr($encoded, '-_', '+/');
    $pad = strlen($encoded) % 4;
    if ($pad > 0) {
        $encoded .= str_repeat('=', 4 - $pad);
    }
    $raw = base64_decode($encoded, true);
    return $raw === false ? '' : playTokenNormalizePath($raw);
}

function playTokenBuildSignedUrl(string $secret, string $email, string $path, string $playHost, int $exp, string $signedScriptPath): string
{
    $path = playTokenNormalizePath($path);
    $playHost = playTokenNormalizeHost($playHost);
    $sig = playTokenPlaySign($secret, $email, $path, $exp);
    $qs = http_build_query([
        'p' => playTokenEncodePath($path),
        'e' => playTokenEncodeEmail($email),
        'exp' => $exp,
        'sig' => $sig,
    ]);

    return 'https://' . $playHost . $signedScriptPath . '?' . $qs;
}

function playTokenResolveMediaFile(string $relativePath): ?string
{
    $relativePath = playTokenStorageRelativePath($relativePath);
    if ($relativePath === '') {
        return null;
    }

    $config = require __DIR__ . '/config.php';
    $base = realpath($config['upload_dir']);
    if ($base === false) {
        return null;
    }

    $full = realpath($base . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath));
    if ($full === false || strpos($full, $base) !== 0 || !is_file($full)) {
        return null;
    }

    return $full;
}

function playTokenMimeForPath(string $path): string
{
    if (preg_match('/\.m3u8$/i', $path)) {
        return 'application/vnd.apple.mpegurl';
    }
    if (preg_match('/\.ts$/i', $path)) {
        return 'video/mp2t';
    }
    if (preg_match('/\.mp4$/i', $path)) {
        return 'video/mp4';
    }

    return 'application/octet-stream';
}

/**
 * 解析 m3u8 总时长（秒）
 */
function playTokenParseM3u8Duration(string $relativePath): float
{
    $file = playTokenResolveMediaFile($relativePath);
    if ($file === null || !is_readable($file)) {
        return 0.0;
    }

    $content = file_get_contents($file);
    if ($content === false) {
        return 0.0;
    }

    $total = 0.0;
    if (preg_match_all('/#EXTINF:([\d.]+)/i', $content, $matches)) {
        foreach ($matches[1] as $sec) {
            $total += (float)$sec;
        }
    }

    return $total;
}

function playTokenResolvePlayExpiry(string $relativePath, int $requestExp, bool $autoTtl): int
{
    if (!$autoTtl) {
        return $requestExp;
    }

    $duration = playTokenParseM3u8Duration($relativePath);
    if ($duration <= 0) {
        $cfg = getPlayTokenConfig();
        $fallback = max(300, (int)$cfg['token_ttl']);

        return time() + $fallback;
    }

    $ttl = (int)ceil($duration * 2);
    $ttl = max(300, min(86400, $ttl));

    return time() + $ttl;
}

/**
 * 为 m3u8 内相对分片地址追加签名参数
 */
function playTokenRewriteM3u8(string $content, string $secret, string $email, string $m3u8Path, int $exp, string $signedScriptPath): string
{
    $m3u8Path = playTokenNormalizePath($m3u8Path);
    $dir = trim(str_replace('\\', '/', dirname($m3u8Path)), '/.');
    if ($dir === '.') {
        $dir = '';
    }

    $lines = preg_split('/\r\n|\r|\n/', $content);
    $out = [];

    foreach ($lines as $line) {
        $trim = trim($line);
        if ($trim === '' || $trim[0] === '#') {
            $out[] = $line;
            continue;
        }

        if (preg_match('#^https?://#i', $trim)) {
            $out[] = $line;
            continue;
        }

        $segmentPath = $trim;
        if ($dir !== '' && strpos($segmentPath, '/') === false) {
            $segmentPath = $dir . '/' . $segmentPath;
        } elseif ($dir !== '' && $segmentPath[0] !== '/') {
            $segmentPath = $dir . '/' . ltrim($segmentPath, '/');
        }
        $segmentPath = playTokenNormalizePath($segmentPath);
        if ($segmentPath === '') {
            $out[] = $line;
            continue;
        }

        $sig = playTokenPlaySign($secret, $email, $segmentPath, $exp);
        $qs = http_build_query([
            'p' => playTokenEncodePath($segmentPath),
            'e' => playTokenEncodeEmail($email),
            'exp' => $exp,
            'sig' => $sig,
        ]);
        $out[] = $signedScriptPath . '?' . $qs;
    }

    return implode("\n", $out);
}

function playTokenIssueFromRequest(array $input): array
{
    $cfg = getPlayTokenConfig();
    $pathRaw = (string)($input['path'] ?? '');
    $path = playTokenStorageRelativePath($pathRaw);
    $playHost = playTokenNormalizeHost((string)($input['play_host'] ?? ''));
    $exp = (int)($input['exp'] ?? 0);
    $sign = (string)($input['sign'] ?? '');

    $secrets = array_values(array_unique(array_filter([$cfg['api_secret']])));
    if (is_file(__DIR__ . '/video_sync.php')) {
        require_once __DIR__ . '/video_sync.php';
        $syncCfg = getVideoSyncConfig();
        if ($syncCfg['api_secret'] !== '') {
            $secrets[] = $syncCfg['api_secret'];
        }
    }
    $secrets = array_values(array_unique(array_filter($secrets)));

    if ($secrets === []) {
        return ['ok' => false, 'message' => '视频后端未配置 API 密钥'];
    }

    $email = trim((string)($input['email'] ?? ''));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '邮箱无效'];
    }
    if ($path === '') {
        return ['ok' => false, 'message' => '视频路径无效'];
    }
    if ($playHost === '') {
        return ['ok' => false, 'message' => '播放域名无效'];
    }
    if ($exp <= time()) {
        return ['ok' => false, 'message' => '请求已过期'];
    }

    $matchedSecret = '';
    foreach ($secrets as $secret) {
        if (playTokenVerifyRequestFlexible($secret, $email, $pathRaw, $playHost, $exp, $sign)) {
            $matchedSecret = $secret;
            break;
        }
    }
    if ($matchedSecret === '') {
        return ['ok' => false, 'message' => '签名校验失败'];
    }

    if (!playTokenResolveMediaFile($path)) {
        return ['ok' => false, 'message' => '视频文件不存在'];
    }

    $autoTtl = !empty($input['auto_ttl']);
    $playExp = playTokenResolvePlayExpiry($path, $exp, $autoTtl);

    $signSecret = $cfg['api_secret'] !== '' ? $cfg['api_secret'] : $matchedSecret;
    $playUrl = playTokenBuildSignedUrl(
        $signSecret,
        $email,
        $path,
        $playHost,
        $playExp,
        $cfg['signed_script_path']
    );

    return [
        'ok' => true,
        'play_url' => $playUrl,
        'mime' => playTokenMimeForPath($path),
        'expires_at' => $playExp,
        'duration_seconds' => $autoTtl ? playTokenParseM3u8Duration($path) : null,
    ];
}
