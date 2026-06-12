<?php

require_once __DIR__ . '/redis_config.php';

function watchProgressField(int $videoId, int $episodeId): string
{
    return $videoId . ':' . $episodeId;
}

function watchProgressHashKey(int $userId): string
{
    return getRedisPrefix() . 'progress:' . $userId;
}

function watchProgressSeqKey(): string
{
    return getRedisPrefix() . 'watch:seq';
}

function watchProgressPayloadKey(int $seq): string
{
    return getRedisPrefix() . 'watch:payload:' . $seq;
}

function watchProgressPublishThrottleKey(int $userId, int $videoId, int $episodeId): string
{
    return getRedisPrefix() . 'watch:pub:' . $userId . ':' . $videoId . ':' . $episodeId;
}

/**
 * @return array{progress_seconds:int,duration_seconds:int,updated_at?:string}|null
 */
function decodeWatchProgressPayload(string $raw): ?array
{
    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return null;
    }
    return [
        'progress_seconds' => max(0, (int)($data['p'] ?? $data['progress_seconds'] ?? 0)),
        'duration_seconds' => max(0, (int)($data['d'] ?? $data['duration_seconds'] ?? 0)),
        'updated_at' => isset($data['t']) ? (string)$data['t'] : ($data['updated_at'] ?? null),
    ];
}

function encodeWatchProgressPayload(int $progress, int $duration): string
{
    return json_encode([
        'p' => $progress,
        'd' => $duration,
        't' => date('Y-m-d H:i:s'),
    ], JSON_UNESCAPED_UNICODE);
}

/**
 * @return array{progress_seconds:int,duration_seconds:int}|null
 */
function watchProgressGetFromMysql(PDO $pdo, int $userId, int $videoId, int $episodeId): ?array
{
    $stmt = $pdo->prepare('
        SELECT progress_seconds, duration_seconds
        FROM video_watch_progress
        WHERE user_id = ? AND video_id = ? AND episode_id = ?
        LIMIT 1
    ');
    $stmt->execute([$userId, $videoId, $episodeId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return null;
    }
    return [
        'progress_seconds' => (int)$row['progress_seconds'],
        'duration_seconds' => (int)$row['duration_seconds'],
    ];
}

function watchProgressWarmRedis(int $userId, int $videoId, int $episodeId, int $progress, int $duration): void
{
    if (!isRedisWatchProgressAvailable()) {
        return;
    }
    try {
        $redis = getRedisConnection();
        $redis->hSet(
            watchProgressHashKey($userId),
            watchProgressField($videoId, $episodeId),
            encodeWatchProgressPayload($progress, $duration)
        );
    } catch (Throwable $e) {
        // 忽略缓存写入失败
    }
}

/**
 * @return array{progress_seconds:int,duration_seconds:int}
 */
function watchProgressGet(PDO $pdo, int $userId, int $videoId, int $episodeId): array
{
    $empty = ['progress_seconds' => 0, 'duration_seconds' => 0];

    if (isRedisWatchProgressAvailable()) {
        try {
            $redis = getRedisConnection();
            $raw = $redis->hGet(watchProgressHashKey($userId), watchProgressField($videoId, $episodeId));
            if (is_string($raw) && $raw !== '') {
                $decoded = decodeWatchProgressPayload($raw);
                if ($decoded) {
                    return [
                        'progress_seconds' => $decoded['progress_seconds'],
                        'duration_seconds' => $decoded['duration_seconds'],
                    ];
                }
            }
        } catch (Throwable $e) {
            // 回退 MySQL
        }
    }

    $fromDb = watchProgressGetFromMysql($pdo, $userId, $videoId, $episodeId);
    if ($fromDb) {
        watchProgressWarmRedis($userId, $videoId, $episodeId, $fromDb['progress_seconds'], $fromDb['duration_seconds']);
        return $fromDb;
    }

    return $empty;
}

function watchProgressFlushToMysql(
    PDO $pdo,
    int $userId,
    int $videoId,
    int $episodeId,
    int $progress,
    int $duration
): void {
    $stmt = $pdo->prepare('
        INSERT INTO video_watch_progress (user_id, video_id, episode_id, progress_seconds, duration_seconds)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE
          progress_seconds = VALUES(progress_seconds),
          duration_seconds = VALUES(duration_seconds),
          updated_at = CURRENT_TIMESTAMP
    ');
    $stmt->execute([$userId, $videoId, $episodeId, $progress, $duration]);
}

function watchProgressPublishNotify(
    int $userId,
    int $videoId,
    int $episodeId,
    int $progress,
    int $duration
): void {
    if (!isRedisWatchProgressAvailable()) {
        return;
    }

    try {
        $redis = getRedisConnection();
        $throttleKey = watchProgressPublishThrottleKey($userId, $videoId, $episodeId);
        $throttleSec = getRedisPublishThrottleSec();
        if (!$redis->set($throttleKey, '1', ['nx', 'ex' => $throttleSec])) {
            return;
        }

        require_once __DIR__ . '/../config/database.php';
        watchProgressFlushToMysql(getDB(), $userId, $videoId, $episodeId, $progress, $duration);

        $payload = json_encode([
            'user_id' => $userId,
            'video_id' => $videoId,
            'episode_id' => $episodeId,
            'progress_seconds' => $progress,
            'duration_seconds' => $duration,
            'updated_at' => date('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);

        $channel = getRedisPrefix() . 'watch:updates';
        $redis->publish($channel, $payload);
    } catch (Throwable $e) {
        // 推送失败不影响主流程
    }
}

/**
 * 保存观看进度（方案 B：Redis 热写 + 可选刷 MySQL）
 *
 * @return array{ok:bool,storage:string,flushed?:bool}
 */
function watchProgressSave(
    PDO $pdo,
    int $userId,
    int $videoId,
    int $episodeId,
    int $progress,
    int $duration,
    bool $flushToDb = false
): array {
    if ($videoId <= 0 || $episodeId <= 0) {
        return ['ok' => false, 'storage' => 'none'];
    }

    if ($progress < 0) {
        $progress = 0;
    }
    if ($duration < 0) {
        $duration = 0;
    }
    if ($duration > 0 && $progress > $duration) {
        $progress = $duration;
    }

    if (isRedisWatchProgressAvailable()) {
        try {
            $redis = getRedisConnection();
            $redis->hSet(
                watchProgressHashKey($userId),
                watchProgressField($videoId, $episodeId),
                encodeWatchProgressPayload($progress, $duration)
            );

            if ($flushToDb) {
                watchProgressFlushToMysql($pdo, $userId, $videoId, $episodeId, $progress, $duration);
            }

            watchProgressPublishNotify($userId, $videoId, $episodeId, $progress, $duration);

            return [
                'ok' => true,
                'storage' => 'redis',
                'flushed' => $flushToDb,
            ];
        } catch (Throwable $e) {
            // 回退 MySQL
        }
    }

    watchProgressFlushToMysql($pdo, $userId, $videoId, $episodeId, $progress, $duration);
    watchProgressInsertLegacyEvent($pdo, $userId, $videoId, $episodeId);

    return ['ok' => true, 'storage' => 'mysql', 'flushed' => true];
}

/** MySQL 回退模式：写入旧事件表供 SSE 轮询 */
function watchProgressInsertLegacyEvent(PDO $pdo, int $userId, int $videoId, int $episodeId): void
{
    try {
        $stmt = $pdo->prepare('
            INSERT INTO watch_progress_events (actor_user_id, target_user_id, action, video_id, episode_id)
            VALUES (?, ?, ?, ?, ?)
        ');
        $stmt->execute([$userId, $userId, 'save', $videoId, $episodeId]);
    } catch (Throwable $e) {
        // 表不存在时忽略
    }
}

function watchProgressDelete(PDO $pdo, int $userId, int $videoId, int $episodeId): void
{
    if (isRedisWatchProgressAvailable()) {
        try {
            $redis = getRedisConnection();
            $redis->hDel(watchProgressHashKey($userId), watchProgressField($videoId, $episodeId));
        } catch (Throwable $e) {
        }
    }

    $pdo->prepare('DELETE FROM video_watch_progress WHERE user_id = ? AND video_id = ? AND episode_id = ?')
        ->execute([$userId, $videoId, $episodeId]);

    try {
        $pdo->prepare('
            INSERT INTO watch_progress_events (actor_user_id, target_user_id, action, video_id, episode_id)
            VALUES (?, ?, ?, ?, ?)
        ')->execute([$userId, $userId, 'delete', $videoId, $episodeId]);
    } catch (Throwable $e) {
    }
}

function watchProgressClearUser(PDO $pdo, int $userId): void
{
    if (isRedisWatchProgressAvailable()) {
        try {
            $redis = getRedisConnection();
            $redis->del(watchProgressHashKey($userId));
        } catch (Throwable $e) {
        }
    }

    $pdo->prepare('DELETE FROM video_watch_progress WHERE user_id = ?')->execute([$userId]);

    try {
        $pdo->prepare('
            INSERT INTO watch_progress_events (actor_user_id, target_user_id, action)
            VALUES (?, ?, ?)
        ')->execute([$userId, $userId, 'clear']);
    } catch (Throwable $e) {
    }
}

/**
 * 将 Redis 中该用户全部进度刷入 MySQL（打开记录列表前可选调用）
 */
function watchProgressFlushUserToMysql(PDO $pdo, int $userId): int
{
    if (!isRedisWatchProgressAvailable()) {
        return 0;
    }

    try {
        $redis = getRedisConnection();
        $all = $redis->hGetAll(watchProgressHashKey($userId));
    } catch (Throwable $e) {
        return 0;
    }

    if (!$all) {
        return 0;
    }

    $count = 0;
    foreach ($all as $field => $raw) {
        if (!is_string($raw) || !str_contains($field, ':')) {
            continue;
        }
        [$videoId, $episodeId] = array_map('intval', explode(':', $field, 2));
        $decoded = decodeWatchProgressPayload($raw);
        if (!$decoded || $videoId <= 0 || $episodeId <= 0) {
            continue;
        }
        watchProgressFlushToMysql(
            $pdo,
            $userId,
            $videoId,
            $episodeId,
            $decoded['progress_seconds'],
            $decoded['duration_seconds']
        );
        $count++;
    }

    return $count;
}

/**
 * Redis SSE：阻塞订阅，收到匹配消息后输出并结束
 */
function watchProgressStreamRedisSse(string $mode, int $userId, int $maxSeconds = 30): void
{
    $redis = getRedisConnection();
    $channel = getRedisPrefix() . 'watch:updates';
    $deadline = time() + $maxSeconds;
    $done = false;

    echo "event:hello\ndata:{\"mode\":\"redis\"}\n\n";
    flush();

    $redis->setOption(Redis::OPT_READ_TIMEOUT, 2);

    $redis->subscribe([$channel], function (Redis $r, string $ch, string $message) use ($mode, $userId, &$done, $deadline) {
        if ($done || time() >= $deadline) {
            return;
        }
        if (connection_aborted()) {
            $done = true;
            try {
                $r->unsubscribe([$ch]);
            } catch (Throwable $e) {
            }
            return;
        }

        $data = json_decode($message, true);
        if (!is_array($data)) {
            return;
        }

        $targetUserId = (int)($data['user_id'] ?? 0);
        if ($mode !== 'admin' && $targetUserId !== $userId) {
            return;
        }

        echo 'event: update' . "\n";
        echo 'data: ' . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";
        flush();
        $done = true;
        try {
            $r->unsubscribe([$ch]);
        } catch (Throwable $e) {
        }
    });
}
