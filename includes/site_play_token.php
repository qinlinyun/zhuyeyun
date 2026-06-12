<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

const SITE_PLAY_TOKEN_SETTING_ENABLED = 'site_play_token_enabled';
const SITE_PLAY_TOKEN_SETTING_SECRET = 'site_play_token_secret';
const SITE_PLAY_TOKEN_SETTING_TTL = 'site_play_token_ttl';

function isSitePlayTokenEnabled(PDO $pdo): bool
{
    return getSetting($pdo, SITE_PLAY_TOKEN_SETTING_ENABLED, '0') === '1';
}

function videoIsUserUploaded(array $video): bool
{
    return (int)($video['uploaded_by'] ?? 0) > 0;
}

function videoShouldUseSitePlayToken(PDO $pdo, array $video): bool
{
    return isSitePlayTokenEnabled($pdo) && videoIsUserUploaded($video);
}

function sitePlayTokenPathBelongsToUserUpload(PDO $pdo, string $path): bool
{
    $path = sitePlayTokenNormalizePath($path);
    if ($path === '') {
        return false;
    }

    $st = $pdo->query('
        SELECT ve.video_url
        FROM video_episodes ve
        INNER JOIN videos v ON v.id = ve.video_id
        WHERE v.uploaded_by IS NOT NULL AND v.uploaded_by > 0
    ');
    if ($st === false) {
        return false;
    }

    while ($row = $st->fetch(PDO::FETCH_ASSOC)) {
        $episodePath = sitePlayTokenNormalizePath((string)($row['video_url'] ?? ''));
        if ($episodePath === '') {
            continue;
        }
        if ($path === $episodePath) {
            return true;
        }
        if (preg_match('/\.m3u8$/i', $episodePath)) {
            $base = preg_replace('/\/[^\/]+\.m3u8$/i', '', $episodePath);
            if ($base !== null && $base !== '' && str_starts_with($path, $base . '/')) {
                return true;
            }
        }
    }

    return false;
}

function sitePlayTokenVideoAllowsGateway(PDO $pdo, int $videoId, string $path = ''): bool
{
    if (!isSitePlayTokenEnabled($pdo)) {
        return false;
    }

    if ($videoId > 0) {
        $st = $pdo->prepare('SELECT uploaded_by FROM videos WHERE id = ? LIMIT 1');
        $st->execute([$videoId]);
        $row = $st->fetch(PDO::FETCH_ASSOC);

        return $row !== false && (int)($row['uploaded_by'] ?? 0) > 0;
    }

    if ($path !== '') {
        return sitePlayTokenPathBelongsToUserUpload($pdo, $path);
    }

    return false;
}

function getSitePlayTokenConfig(PDO $pdo): array
{
    $ttl = (int)(getSetting($pdo, SITE_PLAY_TOKEN_SETTING_TTL, '7200') ?: 7200);

    return [
        'enabled' => isSitePlayTokenEnabled($pdo),
        'secret' => (string)(getSetting($pdo, SITE_PLAY_TOKEN_SETTING_SECRET, '') ?: ''),
        'token_ttl' => max(300, min(86400, $ttl > 0 ? $ttl : 7200)),
    ];
}

function ensureSitePlayTokenSecret(PDO $pdo): string
{
    $secret = trim((string)(getSetting($pdo, SITE_PLAY_TOKEN_SETTING_SECRET, '') ?: ''));
    if ($secret !== '' && strlen($secret) >= 16) {
        return $secret;
    }

    $secret = bin2hex(random_bytes(32));
    setSetting($pdo, SITE_PLAY_TOKEN_SETTING_SECRET, $secret);

    return $secret;
}

function saveSitePlayTokenEnabled(PDO $pdo, bool $enabled): void
{
    setSetting($pdo, SITE_PLAY_TOKEN_SETTING_ENABLED, $enabled ? '1' : '0');
    if ($enabled) {
        ensureSitePlayTokenSecret($pdo);
    }
}

function sitePlayTokenNormalizePath(string $path): string
{
    $path = str_replace('\\', '/', trim($path));
    $path = ltrim($path, '/');
    if ($path === '' || strpos($path, '..') !== false) {
        return '';
    }

    return $path;
}

function sitePlayTokenEncodePath(string $path): string
{
    return rtrim(strtr(base64_encode($path), '+/', '-_'), '=');
}

function sitePlayTokenDecodePath(string $encoded): string
{
    $encoded = strtr($encoded, '-_', '+/');
    $pad = strlen($encoded) % 4;
    if ($pad > 0) {
        $encoded .= str_repeat('=', 4 - $pad);
    }
    $raw = base64_decode($encoded, true);

    return $raw === false ? '' : sitePlayTokenNormalizePath($raw);
}

function sitePlayTokenSign(string $secret, int $userId, string $path, int $domainId, int $exp): string
{
    $payload = 'play|' . $userId . '|' . $path . '|' . $domainId . '|' . $exp;

    return hash_hmac('sha256', $payload, $secret);
}

function sitePlayTokenVerify(string $secret, int $userId, string $path, int $domainId, int $exp, string $sign): bool
{
    if ($sign === '' || $exp < time()) {
        return false;
    }
    $path = sitePlayTokenNormalizePath($path);
    if ($path === '' || $userId <= 0 || $domainId <= 0) {
        return false;
    }

    $expected = sitePlayTokenSign($secret, $userId, $path, $domainId, $exp);

    return hash_equals($expected, $sign);
}

function sitePlayTokenRequestBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = trim((string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
    if ($host === '') {
        $host = 'localhost';
    }

    return $scheme . '://' . $host;
}

function sitePlayTokenGatewayPath(): string
{
    return '/api/site_play.php';
}

function buildSitePlayMediaUrl(
    PDO $pdo,
    int $userId,
    int $videoId,
    int $episodeId,
    int $domainId,
    string $relativePath
): string {
    $cfg = getSitePlayTokenConfig($pdo);
    $secret = $cfg['secret'] !== '' ? $cfg['secret'] : ensureSitePlayTokenSecret($pdo);
    $path = sitePlayTokenNormalizePath($relativePath);
    $exp = time() + (int)$cfg['token_ttl'];
    $sig = sitePlayTokenSign($secret, $userId, $path, $domainId, $exp);
    $qs = http_build_query([
        'vid' => $videoId,
        'eid' => $episodeId,
        'did' => $domainId,
        'uid' => $userId,
        'p' => sitePlayTokenEncodePath($path),
        'exp' => $exp,
        'sig' => $sig,
    ]);

    return sitePlayTokenRequestBaseUrl() . sitePlayTokenGatewayPath() . '?' . $qs;
}

function sitePlayTokenMimeForPath(string $path): string
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
 * 将 m3u8 内相对分片地址改写为本站带签名的网关地址
 */
function sitePlayTokenRewriteM3u8(
    string $content,
    string $secret,
    int $userId,
    string $m3u8Path,
    int $domainId,
    int $exp,
    int $videoId = 0,
    int $episodeId = 0
): string {
    $m3u8Path = sitePlayTokenNormalizePath($m3u8Path);
    $dir = trim(str_replace('\\', '/', dirname($m3u8Path)), '/.');
    if ($dir === '.') {
        $dir = '';
    }

    $gateway = sitePlayTokenGatewayPath();
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
        $segmentPath = sitePlayTokenNormalizePath($segmentPath);
        if ($segmentPath === '') {
            $out[] = $line;
            continue;
        }

        $sig = sitePlayTokenSign($secret, $userId, $segmentPath, $domainId, $exp);
        $qsData = [
            'did' => $domainId,
            'uid' => $userId,
            'p' => sitePlayTokenEncodePath($segmentPath),
            'exp' => $exp,
            'sig' => $sig,
        ];
        if ($videoId > 0) {
            $qsData['vid'] = $videoId;
        }
        if ($episodeId > 0) {
            $qsData['eid'] = $episodeId;
        }
        $qs = http_build_query($qsData);
        $out[] = $gateway . '?' . $qs;
    }

    return implode("\n", $out);
}

function sitePlayTokenBuildCdnUrl(string $domainHost, string $relativePath): string
{
    $host = preg_replace('#^https?://#i', '', trim($domainHost));
    $host = trim($host, '/');
    $path = '/' . sitePlayTokenNormalizePath($relativePath);

    return 'https://' . $host . $path;
}

function sitePlayTokenFetchRemote(string $url): ?string
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 45,
            'follow_location' => 1,
            'header' => "User-Agent: SitePlayGateway/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $data = @file_get_contents($url, false, $ctx);

    return $data === false ? null : $data;
}

function sitePlayTokenStreamRemote(string $url): bool
{
    $ctx = stream_context_create([
        'http' => [
            'timeout' => 0,
            'follow_location' => 1,
            'header' => "User-Agent: SitePlayGateway/1.0\r\n",
        ],
        'ssl' => [
            'verify_peer' => true,
            'verify_peer_name' => true,
        ],
    ]);
    $fp = @fopen($url, 'rb', false, $ctx);
    if ($fp === false) {
        return false;
    }

    while (!feof($fp)) {
        $chunk = fread($fp, 1024 * 256);
        if ($chunk === false) {
            break;
        }
        echo $chunk;
        if (function_exists('flush')) {
            flush();
        }
    }
    fclose($fp);

    return true;
}
