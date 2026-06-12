<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/traffic.php';

const WHEEL_SETTING_KEY = 'wheel_config';
const WHEEL_ENABLED_KEY = 'wheel_enabled';

function wheelParseBool(mixed $value, bool $default = false): bool
{
    if ($value === null) {
        return $default;
    }
    if (is_bool($value)) {
        return $value;
    }
    if (is_int($value) || is_float($value)) {
        return (int) $value !== 0;
    }
    $text = strtolower(trim((string) $value));
    if ($text === '') {
        return $default;
    }
    if (in_array($text, ['1', 'true', 'yes', 'on'], true)) {
        return true;
    }
    if (in_array($text, ['0', 'false', 'no', 'off'], true)) {
        return false;
    }

    return $default;
}

/**
 * @return array<string, mixed>
 */
function wheelDefaultConfig(): array
{
    return [
        'enabled' => true,
        'daily_spins' => 0,
        'spin_cost_traffic' => 10,
        'prizes' => [
            [
                'id' => 'thanks',
                'label' => '谢谢参与',
                'type' => 'none',
                'weight' => 40,
                'color' => '#94a3b8',
            ],
            [
                'id' => 'traffic_5',
                'label' => '5 流量',
                'type' => 'traffic',
                'amount' => 5,
                'validity_days' => 30,
                'weight' => 25,
                'color' => '#4ade80',
            ],
            [
                'id' => 'traffic_20',
                'label' => '20 流量',
                'type' => 'traffic',
                'amount' => 20,
                'validity_days' => 30,
                'weight' => 10,
                'color' => '#22c55e',
            ],
            [
                'id' => 'unlock_random',
                'label' => '随机解锁视频',
                'type' => 'unlock_random',
                'weight' => 15,
                'color' => '#60a5fa',
            ],
            [
                'id' => 'unlock_all_today',
                'label' => '今日全解锁',
                'type' => 'unlock_all_today',
                'weight' => 10,
                'color' => '#f59e0b',
            ],
        ],
    ];
}

function ensureWheelSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    ensureSiteSettingsTable($pdo);

    $pdo->exec("CREATE TABLE IF NOT EXISTS `wheel_spins` (
        `id` bigint(20) unsigned NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `prize_id` varchar(64) NOT NULL,
        `prize_type` varchar(32) NOT NULL,
        `prize_label` varchar(128) NOT NULL,
        `result_json` text DEFAULT NULL,
        `traffic_cost` int(11) NOT NULL DEFAULT 0,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_wheel_spins_user_day` (`user_id`, `created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    if (!$pdo->query("SHOW COLUMNS FROM wheel_spins LIKE 'traffic_cost'")->fetchColumn()) {
        $pdo->exec('ALTER TABLE wheel_spins ADD COLUMN traffic_cost int(11) NOT NULL DEFAULT 0 AFTER result_json');
    }

    $raw = getSetting($pdo, WHEEL_SETTING_KEY, '');
    if ($raw === '' || $raw === null) {
        setSetting($pdo, WHEEL_SETTING_KEY, json_encode(wheelDefaultConfig(), JSON_UNESCAPED_UNICODE));
    }
    if (getSetting($pdo, WHEEL_ENABLED_KEY, '') === '') {
        setSetting($pdo, WHEEL_ENABLED_KEY, '1');
    }

    $ready = true;
}

function wheelReadEnabledFlag(PDO $pdo): ?bool
{
    $flag = getSetting($pdo, WHEEL_ENABLED_KEY, '');
    if ($flag === '1') {
        return true;
    }
    if ($flag === '0') {
        return false;
    }

    return null;
}

function wheelWriteEnabledFlag(PDO $pdo, bool $enabled): void
{
    setSetting($pdo, WHEEL_ENABLED_KEY, $enabled ? '1' : '0');
}

function wheelIsActivityEnabled(array $config, ?PDO $pdo = null): bool
{
    if ($pdo instanceof PDO) {
        $flag = wheelReadEnabledFlag($pdo);
        if ($flag !== null) {
            return $flag;
        }
    }

    return wheelParseBool($config['enabled'] ?? null, true);
}

/**
 * @return array<string, mixed>
 */
function wheelLoadConfig(PDO $pdo): array
{
    ensureWheelSchema($pdo);
    $raw = getSetting($pdo, WHEEL_SETTING_KEY, '');
    $config = json_decode((string)$raw, true);
    if (!is_array($config)) {
        $config = wheelDefaultConfig();
        setSetting($pdo, WHEEL_SETTING_KEY, json_encode($config, JSON_UNESCAPED_UNICODE));
    }

    $config['enabled'] = wheelIsActivityEnabled($config, $pdo);
    if (wheelReadEnabledFlag($pdo) === null) {
        wheelWriteEnabledFlag($pdo, $config['enabled']);
    }
    $config['daily_spins'] = max(0, (int)($config['daily_spins'] ?? 0));
    $config['spin_cost_traffic'] = max(0, (int)($config['spin_cost_traffic'] ?? 0));
    $prizes = [];
    foreach (($config['prizes'] ?? []) as $prize) {
        if (!is_array($prize)) {
            continue;
        }
        $id = trim((string)($prize['id'] ?? ''));
        $label = trim((string)($prize['label'] ?? ''));
        $type = trim((string)($prize['type'] ?? 'none'));
        if ($id === '' || $label === '') {
            continue;
        }
        $normalized = [
            'id' => $id,
            'label' => $label,
            'type' => $type,
            'weight' => max(0, (int)($prize['weight'] ?? 0)),
            'color' => trim((string)($prize['color'] ?? '#1296db')) ?: '#1296db',
        ];
        if ($type === 'traffic') {
            $normalized['amount'] = max(1, (int)($prize['amount'] ?? 1));
            $normalized['validity_days'] = max(1, (int)($prize['validity_days'] ?? 30));
        }
        $prizes[] = $normalized;
    }
    if ($prizes === []) {
        $prizes = wheelDefaultConfig()['prizes'];
    }
    $config['prizes'] = $prizes;

    return $config;
}

/**
 * @param array<string, mixed> $config
 */
function wheelSaveConfig(PDO $pdo, array $config): void
{
    ensureWheelSchema($pdo);
    $normalized = wheelLoadConfig($pdo);
    $normalized['enabled'] = wheelParseBool($config['enabled'] ?? null, (bool) $normalized['enabled']);
    $normalized['daily_spins'] = max(0, (int)($config['daily_spins'] ?? $normalized['daily_spins']));
    $normalized['spin_cost_traffic'] = max(0, (int)($config['spin_cost_traffic'] ?? $normalized['spin_cost_traffic']));
    wheelWriteEnabledFlag($pdo, (bool) $normalized['enabled']);

    $incoming = [];
    foreach (($config['prizes'] ?? []) as $prize) {
        if (!is_array($prize)) {
            continue;
        }
        $id = trim((string)($prize['id'] ?? ''));
        if ($id === '') {
            continue;
        }
        $item = [
            'id' => $id,
            'label' => trim((string)($prize['label'] ?? '')),
            'type' => trim((string)($prize['type'] ?? 'none')),
            'weight' => max(0, (int)($prize['weight'] ?? 0)),
            'color' => trim((string)($prize['color'] ?? '#1296db')) ?: '#1296db',
        ];
        if ($item['label'] === '') {
            continue;
        }
        if ($item['type'] === 'traffic') {
            $item['amount'] = max(1, (int)($prize['amount'] ?? 1));
            $item['validity_days'] = max(1, (int)($prize['validity_days'] ?? 30));
        }
        $incoming[] = $item;
    }
    if ($incoming !== []) {
        $normalized['prizes'] = $incoming;
    }

    setSetting($pdo, WHEEL_SETTING_KEY, json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRESERVE_ZERO_FRACTION));
    $normalized['enabled'] = wheelIsActivityEnabled($normalized, $pdo);
}

/**
 * @param array<string, mixed> $config
 * @return list<array<string, mixed>>
 */
function wheelPublicPrizes(array $config): array
{
    $items = [];
    foreach ($config['prizes'] as $index => $prize) {
        $items[] = [
            'index' => $index,
            'id' => $prize['id'],
            'label' => $prize['label'],
            'color' => $prize['color'],
            'type' => $prize['type'],
        ];
    }

    return $items;
}

function wheelTodaySpinCount(PDO $pdo, int $userId): int
{
    ensureWheelSchema($pdo);
    $start = chinaNow('Y-m-d') . ' 00:00:00';
    $end = chinaNow('Y-m-d') . ' 23:59:59';
    $stmt = $pdo->prepare('SELECT COUNT(*) FROM wheel_spins WHERE user_id = ? AND created_at BETWEEN ? AND ?');
    $stmt->execute([$userId, $start, $end]);

    return (int)$stmt->fetchColumn();
}

/**
 * @return array{can_spin:bool,spins_left:int,daily_left:int,traffic_left:int,spin_cost_traffic:int,message:string}
 */
function wheelSpinAvailability(PDO $pdo, int $userId, array $config): array
{
    $dailyLimit = max(0, (int)($config['daily_spins'] ?? 0));
    $spinCost = max(0, (int)($config['spin_cost_traffic'] ?? 0));
    $usedToday = wheelTodaySpinCount($pdo, $userId);
    $dailyLeft = $dailyLimit > 0 ? max(0, $dailyLimit - $usedToday) : PHP_INT_MAX;

    $trafficLeft = 0;
    $trafficSpins = PHP_INT_MAX;
    $message = '';

    if ($spinCost > 0) {
        if (!trafficFeatureEnabled($pdo)) {
            return [
                'can_spin' => false,
                'spins_left' => 0,
                'daily_left' => $dailyLeft === PHP_INT_MAX ? -1 : $dailyLeft,
                'traffic_left' => 0,
                'spin_cost_traffic' => $spinCost,
                'message' => '系统未启用流量功能',
            ];
        }
        $traffic = getUserTraffic($pdo, $userId);
        $trafficLeft = (int)($traffic['left'] ?? 0);
        $trafficSpins = intdiv($trafficLeft, $spinCost);
        if ($trafficSpins <= 0) {
            $message = '剩余流量不足，每次抽奖需要 ' . $spinCost . ' 流量';
        }
    }

    if ($dailyLimit > 0 && $dailyLeft <= 0) {
        $message = '今日抽奖次数已用完';
    }

    $spinsLeft = min($dailyLeft, $trafficSpins);
    if ($spinsLeft === PHP_INT_MAX) {
        $spinsLeft = -1;
    }

    if ($dailyLimit <= 0 && $spinCost <= 0) {
        return [
            'can_spin' => false,
            'spins_left' => 0,
            'daily_left' => -1,
            'traffic_left' => $trafficLeft,
            'spin_cost_traffic' => 0,
            'message' => '请配置每日次数或流量消耗',
        ];
    }

    $canSpin = ($dailyLimit <= 0 || $dailyLeft > 0)
        && ($spinCost <= 0 || $trafficSpins > 0);

    return [
        'can_spin' => $canSpin,
        'spins_left' => $spinsLeft === PHP_INT_MAX ? -1 : (int)$spinsLeft,
        'daily_left' => $dailyLeft === PHP_INT_MAX ? -1 : (int)$dailyLeft,
        'traffic_left' => $trafficLeft,
        'spin_cost_traffic' => $spinCost,
        'message' => $canSpin ? '' : ($message ?: '暂时无法抽奖'),
    ];
}

function wheelSpinsLeft(PDO $pdo, int $userId, array $config): int
{
    $availability = wheelSpinAvailability($pdo, $userId, $config);
    $left = (int)$availability['spins_left'];

    return $left < 0 ? PHP_INT_MAX : $left;
}

/**
 * @param list<array<string, mixed>> $prizes
 * @return array<string, mixed>|null
 */
function wheelPickPrize(array $prizes): ?array
{
    $pool = [];
    $total = 0;
    foreach ($prizes as $prize) {
        $weight = max(0, (int)($prize['weight'] ?? 0));
        if ($weight <= 0) {
            continue;
        }
        $total += $weight;
        $pool[] = [$prize, $total];
    }
    if ($total <= 0 || $pool === []) {
        return null;
    }

    $roll = random_int(1, $total);
    foreach ($pool as [$prize, $cap]) {
        if ($roll <= $cap) {
            return $prize;
        }
    }

    return $pool[count($pool) - 1][0];
}

function wheelEndOfToday(): string
{
    return chinaNow('Y-m-d') . ' 23:59:59';
}

function wheelSafeRollback(PDO $pdo): void
{
    try {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
    } catch (Throwable $e) {
    }
}

function wheelSafeCommit(PDO $pdo): void
{
    try {
        if ($pdo->inTransaction()) {
            $pdo->commit();
        }
    } catch (Throwable $e) {
    }
}

function wheelPrepareForSpin(PDO $pdo): void
{
    ensureWheelSchema($pdo);
    if (trafficFeatureEnabled($pdo)) {
        ensureTrafficEarningsSchema($pdo);
    }
}

function wheelDeductTrafficForSpin(PDO $pdo, int $userId, int $cost): array
{
    if ($cost <= 0) {
        return ['ok' => true, 'cost' => 0];
    }
    if (!trafficFeatureEnabled($pdo)) {
        return ['ok' => false, 'message' => '系统未启用流量功能'];
    }

    ensureTrafficEarningsSchema($pdo);
    $hasEarningCols = trafficColumnExists($pdo, 'users', 'traffic_earnings_total');
    $lockSql = 'SELECT traffic_total, traffic_used, traffic_expires_at';
    if ($hasEarningCols) {
        $lockSql .= ', traffic_earnings_total';
    }
    $lockSql .= ' FROM users WHERE id = ? FOR UPDATE';

    $st = $pdo->prepare($lockSql);
    $st->execute([$userId]);
    $u = $st->fetch();
    if (!$u) {
        return ['ok' => false, 'message' => '用户不存在'];
    }

    $total = (int)$u['traffic_total'];
    $used = (int)$u['traffic_used'];
    $expires = $u['traffic_expires_at'];
    $expired = $expires && strtotime((string)$expires) <= time();
    $earningAvailable = $hasEarningCols ? max(0, (int)($u['traffic_earnings_total'] ?? 0)) : 0;
    $baseLeft = $expired ? 0 : max(0, $total - $used);
    $combinedLeft = $baseLeft + $earningAvailable;

    if ($combinedLeft < $cost) {
        return [
            'ok' => false,
            'message' => '剩余流量不足，抽奖需要 ' . $cost . ' 流量，当前剩余 ' . $combinedLeft,
        ];
    }

    $fromBase = min($cost, $baseLeft);
    $fromEarning = $cost - $fromBase;
    $newUsed = $used + $fromBase;
    $newEarning = $earningAvailable - $fromEarning;

    if ($hasEarningCols) {
        $pdo->prepare('UPDATE users SET traffic_used = ?, traffic_earnings_total = ? WHERE id = ?')
            ->execute([$newUsed, $newEarning, $userId]);
    } else {
        $pdo->prepare('UPDATE users SET traffic_used = ? WHERE id = ?')->execute([$newUsed, $userId]);
    }

    $remark = '大转盘抽奖消耗';
    if ($fromEarning > 0) {
        $remark .= '（基础-' . $fromBase . '，收益-' . $fromEarning . '）';
    }
    wheelLogTraffic($pdo, $userId, 'wheel_spin_cost', -$cost, $total, $used, $total, $newUsed, $remark, $userId);

    return ['ok' => true, 'cost' => $cost, 'traffic_left' => max(0, $baseLeft - $fromBase) + $newEarning];
}

function wheelLogTraffic(PDO $pdo, int $userId, string $action, int $change, int $beforeTotal, int $beforeUsed, int $afterTotal, int $afterUsed, string $remark, int $opId): void
{
    $pdo->prepare('INSERT INTO traffic_logs (user_id, action, change_amount, before_total, before_used, after_total, after_used, remark, operator_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)')
        ->execute([$userId, $action, $change, $beforeTotal, $beforeUsed, $afterTotal, $afterUsed, $remark, $opId]);
}

function wheelGrantTraffic(PDO $pdo, int $userId, int $amount, int $validityDays): array
{
    if (!trafficFeatureEnabled($pdo)) {
        return ['ok' => false, 'message' => '系统未启用流量功能'];
    }

    $amount = max(1, $amount);
    $validityDays = max(1, $validityDays);
    $newExpires = date('Y-m-d H:i:s', time() + $validityDays * 86400);

    $stmt = $pdo->prepare('SELECT traffic_total, traffic_used, traffic_expires_at FROM users WHERE id = ? FOR UPDATE');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => '用户不存在'];
    }

    $beforeTotal = (int)$row['traffic_total'];
    $beforeUsed = (int)$row['traffic_used'];
    $afterTotal = $beforeTotal + $amount;
    $currentExpires = $row['traffic_expires_at'] ?? null;
    $finalExpires = $newExpires;
    if ($currentExpires && strtotime((string)$currentExpires) > time()) {
        $finalExpires = strtotime((string)$currentExpires) > strtotime($newExpires) ? $currentExpires : $newExpires;
    }

    $pdo->prepare('UPDATE users SET traffic_total = ?, traffic_expires_at = ? WHERE id = ?')
        ->execute([$afterTotal, $finalExpires, $userId]);
    wheelLogTraffic($pdo, $userId, 'wheel_grant', $amount, $beforeTotal, $beforeUsed, $afterTotal, $beforeUsed, '大转盘奖励流量（' . $validityDays . '天有效）', 0);

    return [
        'ok' => true,
        'amount' => $amount,
        'expires_at' => $finalExpires,
        'message' => '已获得 ' . $amount . ' 流量，有效期至 ' . $finalExpires,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function wheelLockedTrafficVideos(PDO $pdo, int $userId): array
{
    if (!trafficFeatureEnabled($pdo)) {
        return [];
    }

    $now = chinaNow('Y-m-d H:i:s');
    $sql = "SELECT v.*
        FROM videos v
        WHERE v.is_traffic = 1
          AND (v.uploaded_by IS NULL OR v.uploaded_by = 0 OR v.uploaded_by <> ?)
          AND NOT EXISTS (
              SELECT 1 FROM video_unlocks u
              WHERE u.user_id = ? AND u.video_id = v.id
                AND (u.expires_at IS NULL OR u.expires_at > ?)
          )
        ORDER BY v.id ASC";
    $stmt = $pdo->prepare($sql);
    $stmt->execute([$userId, $userId, $now]);

    return $stmt->fetchAll() ?: [];
}

function wheelGrantFreeUnlock(PDO $pdo, int $userId, int $videoId, ?string $expiresAt): array
{
    if (!trafficFeatureEnabled($pdo)) {
        return ['ok' => false, 'message' => '系统未启用流量功能'];
    }

    $stmt = $pdo->prepare('SELECT * FROM videos WHERE id = ?');
    $stmt->execute([$videoId]);
    $video = $stmt->fetch();
    if (!$video || empty($video['is_traffic'])) {
        return ['ok' => false, 'message' => '视频不存在或无需解锁'];
    }
    if (trafficIsVideoUploader($video, $userId)) {
        return ['ok' => true, 'video_id' => $videoId, 'title' => (string)$video['title'], 'message' => '您上传的视频无需解锁'];
    }

    $check = $pdo->prepare('SELECT id FROM video_unlocks WHERE user_id = ? AND video_id = ?');
    $check->execute([$userId, $videoId]);
    if ($row = $check->fetch()) {
        $pdo->prepare('UPDATE video_unlocks SET cost = 0, expires_at = ?, paid_at = NOW() WHERE id = ?')
            ->execute([$expiresAt, (int)$row['id']]);
    } else {
        $pdo->prepare('INSERT INTO video_unlocks (user_id, video_id, cost, expires_at, refresh_count, refresh_limit, validity_minutes, paid_at)
            VALUES (?, ?, 0, ?, 0, 0, 0, NOW())')
            ->execute([$userId, $videoId, $expiresAt]);
    }

    return [
        'ok' => true,
        'video_id' => $videoId,
        'title' => (string)$video['title'],
        'expires_at' => $expiresAt,
        'message' => '已解锁视频《' . (string)$video['title'] . '》',
    ];
}

function wheelApplyUnlockRandom(PDO $pdo, int $userId): array
{
    $locked = wheelLockedTrafficVideos($pdo, $userId);
    if ($locked === []) {
        return ['ok' => true, 'message' => '当前没有可解锁的流量视频', 'video' => null];
    }
    $pick = $locked[array_rand($locked)];

    return wheelGrantFreeUnlock($pdo, $userId, (int)$pick['id'], null);
}

function wheelApplyUnlockAllToday(PDO $pdo, int $userId): array
{
    $locked = wheelLockedTrafficVideos($pdo, $userId);
    if ($locked === []) {
        return ['ok' => true, 'message' => '当前没有可解锁的流量视频', 'count' => 0, 'videos' => []];
    }

    $expiresAt = wheelEndOfToday();
    $titles = [];
    foreach ($locked as $video) {
        $result = wheelGrantFreeUnlock($pdo, $userId, (int)$video['id'], $expiresAt);
        if (!empty($result['ok'])) {
            $titles[] = (string)$video['title'];
        }
    }

    return [
        'ok' => true,
        'count' => count($titles),
        'expires_at' => $expiresAt,
        'videos' => $titles,
        'message' => '已解锁 ' . count($titles) . ' 个视频，有效期至今日 ' . substr($expiresAt, 11, 5),
    ];
}

/**
 * @param array<string, mixed> $prize
 * @return array<string, mixed>
 */
function wheelApplyPrize(PDO $pdo, int $userId, array $prize): array
{
    $type = (string)($prize['type'] ?? 'none');
    switch ($type) {
        case 'traffic':
            return wheelGrantTraffic(
                $pdo,
                $userId,
                (int)($prize['amount'] ?? 1),
                (int)($prize['validity_days'] ?? 30)
            );
        case 'unlock_random':
            return wheelApplyUnlockRandom($pdo, $userId);
        case 'unlock_all_today':
            return wheelApplyUnlockAllToday($pdo, $userId);
        default:
            return ['ok' => true, 'message' => '谢谢参与，下次好运'];
    }
}

/**
 * @return array<string, mixed>
 */
function wheelPerformSpin(PDO $pdo, int $userId): array
{
    wheelPrepareForSpin($pdo);
    $config = wheelLoadConfig($pdo);

    if (!$config['enabled']) {
        return ['ok' => false, 'message' => '大转盘活动暂未开启'];
    }

    $availability = wheelSpinAvailability($pdo, $userId, $config);
    if (empty($availability['can_spin'])) {
        return [
            'ok' => false,
            'message' => (string)($availability['message'] ?: '暂时无法抽奖'),
            'spins_left' => max(0, (int)$availability['spins_left']),
            'traffic_left' => (int)$availability['traffic_left'],
        ];
    }

    $prize = wheelPickPrize($config['prizes']);
    if (!$prize) {
        return ['ok' => false, 'message' => '奖品配置无效，请联系管理员'];
    }

    $spinCost = max(0, (int)($config['spin_cost_traffic'] ?? 0));
    $dailyLimit = max(0, (int)($config['daily_spins'] ?? 0));
    $prizeIndex = 0;
    foreach ($config['prizes'] as $idx => $item) {
        if ($item['id'] === $prize['id']) {
            $prizeIndex = $idx;
            break;
        }
    }

    $apply = [];
    $trafficCostPaid = 0;
    $committed = false;

    $pdo->beginTransaction();
    try {
        if ($dailyLimit > 0) {
            $usedToday = wheelTodaySpinCount($pdo, $userId);
            if ($usedToday >= $dailyLimit) {
                wheelSafeRollback($pdo);
                return ['ok' => false, 'message' => '今日抽奖次数已用完', 'spins_left' => 0];
            }
        }

        if ($spinCost > 0) {
            $deduct = wheelDeductTrafficForSpin($pdo, $userId, $spinCost);
            if (empty($deduct['ok'])) {
                wheelSafeRollback($pdo);
                return ['ok' => false, 'message' => (string)($deduct['message'] ?? '流量不足')];
            }
            $trafficCostPaid = $spinCost;
        }

        $apply = wheelApplyPrize($pdo, $userId, $prize);
        if (empty($apply['ok'])) {
            wheelSafeRollback($pdo);
            return ['ok' => false, 'message' => (string)($apply['message'] ?? '发奖失败')];
        }

        $resultJson = json_encode($apply, JSON_UNESCAPED_UNICODE);
        if ($resultJson === false) {
            $resultJson = json_encode(['ok' => true, 'message' => (string)($apply['message'] ?? '恭喜中奖')], JSON_UNESCAPED_UNICODE);
        }

        $pdo->prepare('INSERT INTO wheel_spins (user_id, prize_id, prize_type, prize_label, result_json, traffic_cost)
            VALUES (?, ?, ?, ?, ?, ?)')
            ->execute([
                $userId,
                (string)$prize['id'],
                (string)$prize['type'],
                (string)$prize['label'],
                $resultJson,
                $trafficCostPaid,
            ]);

        wheelSafeCommit($pdo);
        $committed = true;
    } catch (Throwable $e) {
        wheelSafeRollback($pdo);

        if (!$committed) {
            $latest = wheelFindLatestSpin($pdo, $userId, (string)$prize['id']);
            if ($latest) {
                $committed = true;
                $apply = is_array($latest['result'] ?? null) ? $latest['result'] : $apply;
            } else {
                return ['ok' => false, 'message' => '抽奖失败，请稍后再试'];
            }
        }
    }

    if (!$committed) {
        return ['ok' => false, 'message' => '抽奖失败，请稍后再试'];
    }

    $config = wheelLoadConfig($pdo);
    $after = wheelSpinAvailability($pdo, $userId, $config);
    $spinsLeft = (int)$after['spins_left'];

    return [
        'ok' => true,
        'prize' => [
            'index' => $prizeIndex,
            'id' => $prize['id'],
            'label' => $prize['label'],
            'type' => $prize['type'],
            'color' => $prize['color'],
        ],
        'detail' => $apply,
        'message' => (string)($apply['message'] ?? '恭喜中奖'),
        'traffic_cost' => $trafficCostPaid,
        'spins_left' => $spinsLeft < 0 ? -1 : $spinsLeft,
        'traffic_left' => (int)$after['traffic_left'],
        'spin_cost_traffic' => (int)$after['spin_cost_traffic'],
    ];
}

/**
 * @return array<string, mixed>|null
 */
function wheelFindLatestSpin(PDO $pdo, int $userId, string $prizeId): ?array
{
    $stmt = $pdo->prepare('SELECT prize_id, prize_label, prize_type, result_json, created_at
        FROM wheel_spins
        WHERE user_id = ? AND prize_id = ?
        ORDER BY id DESC
        LIMIT 1');
    $stmt->execute([$userId, $prizeId]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $createdAt = strtotime((string)$row['created_at']);
    if ($createdAt === false || $createdAt < time() - 30) {
        return null;
    }

    return wheelFormatRecordRow($row);
}

function wheelFormatRecordRow(array $row): array
{
    $result = json_decode((string)($row['result_json'] ?? ''), true);
    if (!is_array($result)) {
        $result = [];
    }
    $detail = (string)($result['message'] ?? '');

    return [
        'id' => (int)($row['id'] ?? 0),
        'user_id' => (int)($row['user_id'] ?? 0),
        'username' => (string)($row['username'] ?? ''),
        'prize_id' => (string)($row['prize_id'] ?? ''),
        'prize_type' => (string)($row['prize_type'] ?? ''),
        'prize_label' => (string)($row['prize_label'] ?? ''),
        'traffic_cost' => (int)($row['traffic_cost'] ?? 0),
        'detail' => $detail,
        'result' => $result,
        'created_at' => (string)($row['created_at'] ?? ''),
    ];
}

/**
 * @return array{items:list<array<string,mixed>>,total:int,page:int,per_page:int,pages:int}
 */
function wheelListRecords(PDO $pdo, int $page, int $perPage, ?int $userId = null, string $keyword = ''): array
{
    ensureWheelSchema($pdo);
    $page = max(1, $page);
    $perPage = max(1, min(50, $perPage));
    $offset = ($page - 1) * $perPage;

    $where = [];
    $params = [];
    if ($userId !== null && $userId > 0) {
        $where[] = 's.user_id = ?';
        $params[] = $userId;
    }
    if ($keyword !== '') {
        $where[] = '(u.username LIKE ? OR u.email LIKE ? OR s.prize_label LIKE ?)';
        $like = '%' . $keyword . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }
    $whereSql = $where === [] ? '' : 'WHERE ' . implode(' AND ', $where);

    $countSql = "SELECT COUNT(*) FROM wheel_spins s LEFT JOIN users u ON u.id = s.user_id {$whereSql}";
    $stmt = $pdo->prepare($countSql);
    $stmt->execute($params);
    $total = (int)$stmt->fetchColumn();

    $sql = "SELECT s.*, u.username
        FROM wheel_spins s
        LEFT JOIN users u ON u.id = s.user_id
        {$whereSql}
        ORDER BY s.id DESC
        LIMIT {$perPage} OFFSET {$offset}";
    $stmt = $pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll() ?: [];

    $items = [];
    foreach ($rows as $row) {
        $items[] = wheelFormatRecordRow($row);
    }

    $pages = $total > 0 ? (int)ceil($total / $perPage) : 1;

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'per_page' => $perPage,
        'pages' => $pages,
    ];
}

/**
 * @return list<array<string, mixed>>
 */
function wheelRecentSpins(PDO $pdo, int $userId, int $limit = 10): array
{
    $result = wheelListRecords($pdo, 1, max(1, min(50, $limit)), $userId);

    return $result['items'];
}
