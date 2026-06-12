<?php

require_once __DIR__ . '/analytics_schema.php';
require_once __DIR__ . '/analytics_config.php';

function recordUserGrowthEvent(int $userId): void
{
    // 用户注册时间已写入 users.created_at，无需额外存储
    if ($userId <= 0) {
        return;
    }
    analyticsDb();
}

function getUserGrowthRangeStartTs(string $range): ?int
{
    $now = time();
    if ($range === '24h') {
        return $now - 86400;
    }
    if ($range === 'all') {
        return null;
    }

    $days = (int)rtrim($range, 'd');
    return strtotime(date('Y-m-d 00:00:00', $now - ($days - 1) * 86400));
}

function getUserGrowthEarliestTs(PDO $pdo): ?int
{
    $stmt = $pdo->prepare('SELECT UNIX_TIMESTAMP(MIN(created_at)) FROM users WHERE username <> ?');
    $stmt->execute(['admin']);
    $ts = $stmt->fetchColumn();

    return $ts !== false && $ts !== null ? (int)$ts : null;
}

function normalizeUserGrowthRange(string $range): string
{
    $allowed = ['24h', '7d', '14d', '30d', 'all'];
    return in_array($range, $allowed, true) ? $range : '24h';
}

function buildUserGrowthBuckets(string $range, PDO $pdo): array
{
    $now = time();

    if ($range === '24h') {
        $start = strtotime(date('Y-m-d H:00:00', $now - 23 * 3600));
        $step = 3600;
        $count = 24;
        $labelFn = static function (int $ts): string {
            return date('m-d H:i', $ts);
        };
    } elseif ($range === 'all') {
        $maxDays = getAnalyticsGrowthMaxDays($pdo);
        $earliest = getUserGrowthEarliestTs($pdo);
        if ($earliest === null) {
            $start = strtotime('today', $now);
            $count = 1;
        } else {
            $start = strtotime(date('Y-m-d 00:00:00', $earliest));
            $endDay = strtotime(date('Y-m-d 00:00:00', $now));
            $count = max(1, (int)(($endDay - $start) / 86400) + 1);
            if ($count > $maxDays) {
                $start = strtotime(date('Y-m-d 00:00:00', $now - ($maxDays - 1) * 86400));
                $count = $maxDays;
            }
        }

        $step = 86400;
        $labelFn = static function (int $ts): string {
            return date('Y-m-d', $ts);
        };
    } else {
        $days = (int)rtrim($range, 'd');
        $start = strtotime(date('Y-m-d 00:00:00', $now - ($days - 1) * 86400));
        $step = 86400;
        $count = $days;
        $labelFn = static function (int $ts): string {
            return date('m-d', $ts);
        };
    }

    $buckets = [];
    for ($i = 0; $i < $count; $i++) {
        $bucketStart = $start + $i * $step;
        $buckets[] = [
            'start' => $bucketStart,
            'end' => $bucketStart + $step,
            'label' => $labelFn($bucketStart),
            'count' => 0,
        ];
    }

    return $buckets;
}

function getUserGrowthTrend(string $range): array
{
    $pdo = analyticsDb();
    $range = normalizeUserGrowthRange($range);
    $sinceTs = getUserGrowthRangeStartTs($range);
    $buckets = buildUserGrowthBuckets($range, $pdo);

    $sql = 'SELECT UNIX_TIMESTAMP(created_at) AS ts FROM users WHERE username <> ?';
    $params = ['admin'];
    if ($sinceTs !== null) {
        $sql .= ' AND created_at >= FROM_UNIXTIME(?)';
        $params[] = $sinceTs;
    }

    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);

    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $ts = (int)($row['ts'] ?? 0);
        if ($ts <= 0) {
            continue;
        }

        foreach ($buckets as &$bucket) {
            if ($ts >= $bucket['start'] && $ts < $bucket['end']) {
                $bucket['count']++;
                break;
            }
        }
        unset($bucket);
    }

    $labels = array_column($buckets, 'label');
    $counts = array_map(static function ($bucket) {
        return (int)$bucket['count'];
    }, $buckets);
    $total = array_sum($counts);
    $peakIndex = $total > 0 ? (int)array_search(max($counts), $counts, true) : null;

    $rangeLabels = [
        '24h' => '24小时',
        '7d' => '7天',
        '14d' => '14天',
        '30d' => '30天',
        'all' => '全部',
    ];

    return [
        'ok' => true,
        'range' => $range,
        'range_label' => $rangeLabels[$range],
        'labels' => $labels,
        'counts' => $counts,
        'total' => $total,
        'peak' => $peakIndex !== null ? [
            'label' => $labels[$peakIndex],
            'count' => $counts[$peakIndex],
        ] : null,
    ];
}
