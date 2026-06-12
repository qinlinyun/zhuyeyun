<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/play_domains.php';

const M3U8_DURATION_CACHE_TTL = 21600;

function m3u8_format_duration(float $seconds): string
{
    if ($seconds <= 0) {
        return '';
    }

    $total = (int)round($seconds);
    $hours = intdiv($total, 3600);
    $minutes = intdiv($total % 3600, 60);
    $secs = $total % 60;

    if ($hours > 0) {
        return sprintf('%d:%02d:%02d', $hours, $minutes, $secs);
    }

    return sprintf('%d:%02d', $minutes, $secs);
}

function m3u8_parse_duration_from_content(string $content): float
{
    $total = 0.0;
    if (preg_match_all('/#EXTINF:([\d.]+)/i', $content, $matches)) {
        foreach ($matches[1] as $sec) {
            $total += (float)$sec;
        }
    }

    return $total;
}

function m3u8_resolve_local_path(string $path): ?string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') {
        return null;
    }

    if (preg_match('#^https?://#i', $path)) {
        return null;
    }

    $relative = ltrim($path, '/');
    $root = dirname(__DIR__);
    $candidates = [
        $root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative),
        $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, preg_replace('#^storage/#i', '', $relative)),
    ];

    foreach ($candidates as $candidate) {
        if (is_file($candidate) && is_readable($candidate)) {
            return $candidate;
        }
    }

    return null;
}

function m3u8_fetch_text_via_shell(string $url): ?string
{
    if (!function_exists('shell_exec')) {
        return null;
    }

    $curlBin = PHP_OS_FAMILY === 'Windows' ? 'curl.exe' : 'curl';
    $cmd = $curlBin
        . ' -s -L --connect-timeout 5 --max-time 10 --ssl-no-revoke '
        . '-H ' . escapeshellarg('Accept: application/vnd.apple.mpegurl, application/x-mpegURL, text/plain')
        . ' ' . escapeshellarg($url);

    $content = @shell_exec($cmd);
    if (!is_string($content) || trim($content) === '') {
        return null;
    }

    return $content;
}

function m3u8_fetch_text(string $urlOrPath, int $depth = 0): ?string
{
    if ($depth > 2) {
        return null;
    }

    $local = m3u8_resolve_local_path($urlOrPath);
    if ($local !== null) {
        $content = @file_get_contents($local);

        return $content !== false ? $content : null;
    }

    if (!preg_match('#^https?://#i', $urlOrPath)) {
        return null;
    }

    $content = null;

    if (function_exists('curl_init')) {
        $ch = curl_init($urlOrPath);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_TIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => false,
            CURLOPT_HTTPHEADER => ['Accept: application/vnd.apple.mpegurl, application/x-mpegURL, text/plain'],
        ]);
        $content = curl_exec($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($content !== false && $status < 400) {
            return $content;
        }

        $content = null;
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 8,
            'header' => "Accept: application/vnd.apple.mpegurl, application/x-mpegURL, text/plain\r\n",
        ],
        'ssl' => [
            'verify_peer' => false,
            'verify_peer_name' => false,
        ],
    ]);
    $content = @file_get_contents($urlOrPath, false, $ctx);
    if ($content !== false) {
        return $content;
    }

    return m3u8_fetch_text_via_shell($urlOrPath);
}

function m3u8_resolve_media_playlist_url(string $baseUrl, string $content): ?string
{
    if (stripos($content, '#EXTINF:') !== false) {
        return $baseUrl;
    }

    if (stripos($content, '#EXT-X-STREAM-INF') === false) {
        return null;
    }

    $baseDir = preg_replace('#/[^/]*$#', '/', $baseUrl) ?: ($baseUrl . '/');
    $lines = preg_split('/\r\n|\r|\n/', $content) ?: [];
    foreach ($lines as $index => $line) {
        if (stripos($line, '#EXT-X-STREAM-INF') === false) {
            continue;
        }
        $next = trim((string)($lines[$index + 1] ?? ''));
        if ($next === '' || str_starts_with($next, '#')) {
            continue;
        }
        if (preg_match('#^https?://#i', $next)) {
            return $next;
        }

        return $baseDir . ltrim($next, '/');
    }

    return null;
}

function m3u8_fetch_duration(string $urlOrPath): ?float
{
    static $memory = [];
    $cacheKey = md5($urlOrPath);
    if (array_key_exists($cacheKey, $memory)) {
        return $memory[$cacheKey];
    }

    $content = m3u8_fetch_text($urlOrPath);
    if ($content === null || trim($content) === '') {
        $memory[$cacheKey] = null;

        return null;
    }

    $mediaUrl = m3u8_resolve_media_playlist_url($urlOrPath, $content);
    if ($mediaUrl !== null && $mediaUrl !== $urlOrPath) {
        $nested = m3u8_fetch_duration($mediaUrl);
        $memory[$cacheKey] = $nested;

        return $nested;
    }

    $duration = m3u8_parse_duration_from_content($content);
    $memory[$cacheKey] = $duration > 0 ? $duration : null;

    return $memory[$cacheKey];
}

function m3u8_get_cached_video_duration(PDO $pdo, int $videoId): ?float
{
    if ($videoId <= 0) {
        return null;
    }

    $raw = getSetting($pdo, 'm3u8_dur_' . $videoId, '') ?? '';
    if ($raw === '') {
        return null;
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }

    $cachedAt = (int)($data['t'] ?? 0);
    $seconds = (float)($data['s'] ?? 0);
    if ($seconds <= 0 || $cachedAt <= 0 || (time() - $cachedAt) > M3U8_DURATION_CACHE_TTL) {
        return null;
    }

    return $seconds;
}

function m3u8_set_cached_video_duration(PDO $pdo, int $videoId, float $seconds): void
{
    if ($videoId <= 0 || $seconds <= 0) {
        return;
    }

    setSetting($pdo, 'm3u8_dur_' . $videoId, json_encode([
        's' => round($seconds, 3),
        't' => time(),
    ], JSON_UNESCAPED_UNICODE));
}

/** @return array<int, string> */
function m3u8_first_episode_paths(PDO $pdo, array $videoIds): array
{
    $videoIds = array_values(array_unique(array_filter(array_map('intval', $videoIds))));
    if ($videoIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($videoIds), '?'));
    $sql = "
        SELECT ve.video_id, ve.video_url
        FROM video_episodes ve
        INNER JOIN (
            SELECT video_id, MIN(episode_order) AS min_order, MIN(id) AS min_id
            FROM video_episodes
            WHERE video_id IN ($placeholders)
            GROUP BY video_id
        ) first_ep ON first_ep.video_id = ve.video_id
            AND ve.episode_order = first_ep.min_order
            AND ve.id = first_ep.min_id
    ";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($videoIds);

    $map = [];
    while ($row = $stmt->fetch()) {
        $map[(int)$row['video_id']] = trim((string)($row['video_url'] ?? ''));
    }

    return $map;
}

/** @return list<string> */
function m3u8_build_candidate_urls(PDO $pdo, array $user, array $video, string $episodePath, bool $asAdmin): array
{
    $episodePath = trim(str_replace('\\', '/', $episodePath));
    if ($episodePath === '') {
        return [];
    }

    if (preg_match('#^https?://#i', $episodePath)) {
        return [$episodePath];
    }

    $urls = [];
    if (m3u8_resolve_local_path($episodePath) !== null) {
        $urls[] = $episodePath;
    }

    $relativePath = '/' . ltrim($episodePath, '/');
    $domains = play_domains_for_playback($pdo, $user, $video, $asAdmin);
    foreach ($domains as $domainRow) {
        $domain = trim((string)($domainRow['domain'] ?? ''));
        if ($domain === '') {
            continue;
        }
        $domain = preg_replace('#^https?://#i', '', $domain);
        $urls[] = 'https://' . rtrim($domain, '/') . $relativePath;
    }

    if ($urls === []) {
        $urls[] = $relativePath;
    }

    return array_values(array_unique($urls));
}

function m3u8_duration_for_video(PDO $pdo, array $user, array $video, bool $asAdmin): ?string
{
    $videoId = (int)($video['id'] ?? 0);
    if ($videoId <= 0) {
        return null;
    }

    $cached = m3u8_get_cached_video_duration($pdo, $videoId);
    if ($cached !== null) {
        $formatted = m3u8_format_duration($cached);

        return $formatted !== '' ? $formatted : null;
    }

    $episodePaths = m3u8_first_episode_paths($pdo, [$videoId]);
    $episodePath = $episodePaths[$videoId] ?? '';
    if ($episodePath === '' || !preg_match('/\.m3u8(\?.*)?$/i', $episodePath)) {
        return null;
    }

    $seconds = null;
    foreach (m3u8_build_candidate_urls($pdo, $user, $video, $episodePath, $asAdmin) as $candidateUrl) {
        $seconds = m3u8_fetch_duration($candidateUrl);
        if ($seconds !== null && $seconds > 0) {
            break;
        }
    }

    if ($seconds === null || $seconds <= 0) {
        return null;
    }

    m3u8_set_cached_video_duration($pdo, $videoId, $seconds);
    $formatted = m3u8_format_duration($seconds);

    return $formatted !== '' ? $formatted : null;
}

/**
 * @param list<array<string, mixed>> $videos
 * @return array<int, string> video_id => formatted duration
 */
function m3u8_durations_for_videos(PDO $pdo, array $user, array $videos, bool $asAdmin): array
{
    if ($videos === []) {
        return [];
    }

    $result = [];

    foreach ($videos as $video) {
        $videoId = (int)($video['id'] ?? 0);
        if ($videoId <= 0) {
            continue;
        }

        $formatted = m3u8_duration_for_video($pdo, $user, $video, $asAdmin);
        if ($formatted !== null) {
            $result[$videoId] = $formatted;
        }
    }

    return $result;
}
