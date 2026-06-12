<?php

require_once __DIR__ . '/player_proxy.php';

/**
 * 确保 videos 表存在 skip_backend_proxy 字段
 */
function ensureVideoSkipBackendProxyColumn(PDO $pdo): bool
{
    static $done = false;
    if ($done) {
        return true;
    }

    $st = $pdo->query("SHOW COLUMNS FROM videos LIKE 'skip_backend_proxy'");
    if (!$st || !$st->fetch()) {
        $pdo->exec(
            'ALTER TABLE videos ADD COLUMN skip_backend_proxy tinyint(1) NOT NULL DEFAULT 0 AFTER cover'
        );
    }

    $done = true;

    return true;
}

function videoSkipsBackendProxy(PDO $pdo, $video): bool
{
    ensureVideoSkipBackendProxyColumn($pdo);

    if (is_array($video)) {
        if ((int)($video['uploaded_by'] ?? 0) > 0) {
            return true;
        }
        if (array_key_exists('skip_backend_proxy', $video)) {
            return !empty($video['skip_backend_proxy']);
        }
        $video = (int)($video['id'] ?? 0);
    }

    $videoId = (int)$video;
    if ($videoId <= 0) {
        return false;
    }

    $st = $pdo->prepare('SELECT skip_backend_proxy, uploaded_by FROM videos WHERE id = ? LIMIT 1');
    $st->execute([$videoId]);
    $row = $st->fetch();

    if (!$row) {
        return false;
    }

    return !empty($row['skip_backend_proxy']) || (int)($row['uploaded_by'] ?? 0) > 0;
}

/** 该视频是否应走后端代理申请 token（用户上传视频文件在上传 CDN，不走切片后端） */
function shouldUsePlayerProxyForVideo(PDO $pdo, array $video): bool
{
    if ((int)($video['uploaded_by'] ?? 0) > 0) {
        return false;
    }

    return isPlayerProxyEnabled($pdo) && !videoSkipsBackendProxy($pdo, $video);
}

/**
 * @return array<int,array<string,mixed>>
 */
function listVideosForTokenSettings(PDO $pdo): array
{
    ensureVideoSkipBackendProxyColumn($pdo);

    $hasSg = (bool)$pdo->query("SHOW TABLES LIKE 'server_groups'")->fetch()
        && (bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'server_group_id'")->fetch();

    if ($hasSg) {
        $sql = 'SELECT v.id, v.title, v.cover, v.skip_backend_proxy, v.created_at,
                       sg.name AS server_group_name,
                       (SELECT COUNT(*) FROM video_episodes ve WHERE ve.video_id = v.id) AS episode_count
                FROM videos v
                LEFT JOIN server_groups sg ON sg.id = v.server_group_id
                ORDER BY v.id DESC';
    } else {
        $sql = 'SELECT v.id, v.title, v.cover, v.skip_backend_proxy, v.created_at,
                       NULL AS server_group_name,
                       (SELECT COUNT(*) FROM video_episodes ve WHERE ve.video_id = v.id) AS episode_count
                FROM videos v
                ORDER BY v.id DESC';
    }

    return $pdo->query($sql)->fetchAll();
}

/**
 * @param array<int,int|string> $videoIds
 * @param array<int,int|string> $skipIds 勾选为直链（跳过代理）的视频 ID
 */
function saveVideoSkipBackendProxySettings(PDO $pdo, array $videoIds, array $skipIds): void
{
    ensureVideoSkipBackendProxyColumn($pdo);

    $skipMap = [];
    foreach ($skipIds as $id) {
        $skipMap[(int)$id] = true;
    }

    $st = $pdo->prepare('UPDATE videos SET skip_backend_proxy = ? WHERE id = ?');
    foreach ($videoIds as $id) {
        $videoId = (int)$id;
        if ($videoId <= 0) {
            continue;
        }
        $st->execute([isset($skipMap[$videoId]) ? 1 : 0, $videoId]);
    }
}
