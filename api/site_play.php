<?php

declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/traffic.php';
require_once __DIR__ . '/../includes/site_play_token.php';

$pdo = getDB();
$cfg = getSitePlayTokenConfig($pdo);

if (!$cfg['enabled'] || $cfg['secret'] === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo '播放加密未开启';
    exit;
}

$secret = $cfg['secret'];
$path = sitePlayTokenDecodePath((string)($_GET['p'] ?? ''));
$userId = (int)($_GET['uid'] ?? 0);
$domainId = (int)($_GET['did'] ?? 0);
$exp = (int)($_GET['exp'] ?? 0);
$sig = (string)($_GET['sig'] ?? '');
$videoId = (int)($_GET['vid'] ?? 0);
$episodeId = (int)($_GET['eid'] ?? 0);

if ($path === '' || $userId <= 0 || $domainId <= 0 || $exp <= 0 || $sig === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo '参数错误';
    exit;
}

if (!sitePlayTokenVerify($secret, $userId, $path, $domainId, $exp, $sig)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '链接无效或已过期';
    exit;
}

$st = $pdo->prepare('SELECT domain FROM domains WHERE id = ? LIMIT 1');
$st->execute([$domainId]);
$domainRow = $st->fetch();
if (!$domainRow || trim((string)$domainRow['domain']) === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '线路无效';
    exit;
}

$isManifest = (bool)preg_match('/\.m3u8$/i', $path);
if ($isManifest && ($videoId <= 0 || $episodeId <= 0)) {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo '参数错误';
    exit;
}

if ($isManifest) {
    $st = $pdo->prepare('SELECT * FROM videos WHERE id = ? LIMIT 1');
    $st->execute([$videoId]);
    $video = $st->fetch();
    if (!$video) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '视频不存在';
        exit;
    }

    if (!videoIsUserUploaded($video)) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo '该视频不适用播放加密';
        exit;
    }

    $st = $pdo->prepare('SELECT * FROM video_episodes WHERE id = ? AND video_id = ? LIMIT 1');
    $st->execute([$episodeId, $videoId]);
    $episode = $st->fetch();
    if (!$episode) {
        http_response_code(404);
        header('Content-Type: text/plain; charset=utf-8');
        echo '集数不存在';
        exit;
    }

    $episodePath = sitePlayTokenNormalizePath((string)$episode['video_url']);
    if ($episodePath === '' || $episodePath !== $path) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo '无权播放';
        exit;
    }

    if (!isAdmin()) {
        $trafficEnabled = trafficFeatureEnabled($pdo);
        if ($trafficEnabled && !empty($video['is_traffic'])) {
            $unlockInfo = getVideoUnlockStatus($pdo, $userId, $video);
            if (empty($unlockInfo['unlocked']) && !trafficAllowsTrialWatch($unlockInfo)) {
                http_response_code(403);
                header('Content-Type: text/plain; charset=utf-8');
                echo '请先解锁该视频';
                exit;
            }
        }
    }
}

if (!sitePlayTokenVideoAllowsGateway($pdo, $videoId, $path)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '该视频不适用播放加密';
    exit;
}

$cdnUrl = sitePlayTokenBuildCdnUrl((string)$domainRow['domain'], $path);
$mime = sitePlayTokenMimeForPath($path);

header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if ($isManifest) {
    $content = sitePlayTokenFetchRemote($cdnUrl);
    if ($content === null) {
        http_response_code(502);
        header('Content-Type: text/plain; charset=utf-8');
        echo '无法获取播放列表';
        exit;
    }

    header('Content-Type: ' . $mime);
    echo sitePlayTokenRewriteM3u8($content, $secret, $userId, $path, $domainId, $exp, $videoId, $episodeId);
    exit;
}

header('Content-Type: ' . $mime);
if (!sitePlayTokenStreamRemote($cdnUrl)) {
    http_response_code(502);
    header('Content-Type: text/plain; charset=utf-8');
    echo '无法读取媒体';
}
