<?php

require_once __DIR__ . '/analytics_schema.php';
require_once __DIR__ . '/analytics_config.php';

function recordVideoClick(int $videoId): void
{
    if (!isAnalyticsClicksEnabled()) {
        return;
    }

    if ($videoId <= 0) {
        return;
    }

    $pdo = analyticsDb();
    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare('SELECT video_id FROM analytics_video_clicks WHERE video_id = ? LIMIT 1');
    $stmt->execute([$videoId]);

    if ($stmt->fetch()) {
        $update = $pdo->prepare("
            UPDATE analytics_video_clicks
            SET clicks = clicks + 1, last_clicked_at = ?
            WHERE video_id = ?
        ");
        $update->execute([$now, $videoId]);
        return;
    }

    $insert = $pdo->prepare("
        INSERT INTO analytics_video_clicks (video_id, clicks, first_clicked_at, last_clicked_at)
        VALUES (?, 1, ?, ?)
    ");
    $insert->execute([$videoId, $now, $now]);
}

function getVideoClickCounts(): array
{
    $pdo = analyticsDb();
    $stmt = $pdo->query('SELECT video_id, clicks FROM analytics_video_clicks');
    $counts = [];
    foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
        $counts[(int)$row['video_id']] = (int)$row['clicks'];
    }

    return $counts;
}

function getVideoClickRanking(PDO $pdo): array
{
    analyticsDb($pdo);

    $limit = getAnalyticsRankingLimit($pdo);
    $stmt = $pdo->prepare("
        SELECT video_id, clicks
        FROM analytics_video_clicks
        ORDER BY clicks DESC, last_clicked_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($items === []) {
        return [];
    }

    $videoIds = array_values(array_filter(array_map(static function ($row) {
        return (int)($row['video_id'] ?? 0);
    }, $items), static function ($id) {
        return $id > 0;
    }));
    if ($videoIds === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($videoIds), '?'));
    $videoStmt = $pdo->prepare("SELECT id, title, cover FROM videos WHERE id IN ($placeholders)");
    $videoStmt->execute($videoIds);
    $videosById = [];
    foreach ($videoStmt->fetchAll() as $row) {
        $videosById[(int)$row['id']] = $row;
    }

    $epStmt = $pdo->prepare("SELECT video_id, COUNT(*) AS cnt FROM video_episodes WHERE video_id IN ($placeholders) GROUP BY video_id");
    $epStmt->execute($videoIds);
    $episodeCounts = [];
    foreach ($epStmt->fetchAll() as $row) {
        $episodeCounts[(int)$row['video_id']] = (int)$row['cnt'];
    }

    $ranking = [];
    $rank = 1;
    foreach ($items as $meta) {
        $id = (int)($meta['video_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $video = $videosById[$id] ?? null;
        $ranking[] = [
            'rank' => $rank++,
            'video_id' => $id,
            'title' => $video ? (string)$video['title'] : '（视频已删除）',
            'cover' => $video ? (string)($video['cover'] ?? '') : '',
            'episode_count' => $episodeCounts[$id] ?? 0,
            'clicks' => (int)($meta['clicks'] ?? 0),
            'play_url' => '../play.php?id=' . $id,
            'exists' => $video !== null,
        ];
    }

    return $ranking;
}
