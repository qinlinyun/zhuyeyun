<?php
// 流量功能相关辅助函数
require_once __DIR__ . '/settings.php';

// 检测数据库是否启用了流量功能
function trafficFeatureEnabled(PDO $pdo): bool {
    static $enabled = null;
    if ($enabled !== null) return $enabled;
    $enabled = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'traffic_total'")->fetch()
        && (bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'is_traffic'")->fetch()
        && (bool)$pdo->query("SHOW TABLES LIKE 'video_unlocks'")->fetch();
    return $enabled;
}

// 取用户最新流量信息（自动触发到期重置）；展示用 total/left 含可用收益流量
function getUserTraffic(PDO $pdo, int $userId): array {
    maybeAutoResetTraffic($pdo, $userId);

    $hasEarningCols = trafficColumnExists($pdo, 'users', 'traffic_earnings_total');
    $sql = 'SELECT traffic_total, traffic_used, traffic_expires_at, auto_reset_days, traffic_last_reset_at';
    if ($hasEarningCols) {
        $sql .= ', traffic_earnings_total, traffic_earnings_frozen';
    }
    $sql .= ' FROM users WHERE id = ?';

    $st = $pdo->prepare($sql);
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) {
        return [
            'total' => 0, 'used' => 0, 'left' => 0,
            'base_total' => 0, 'base_used' => 0, 'base_left' => 0,
            'earning_available' => 0, 'earning_frozen' => 0,
            'expires_at' => null, 'expired' => false,
            'auto_reset_days' => 0, 'last_reset_at' => null, 'next_reset_at' => null,
        ];
    }
    $baseTotal = (int)$row['traffic_total'];
    $baseUsed = (int)$row['traffic_used'];
    $earningAvailable = $hasEarningCols ? max(0, (int)($row['traffic_earnings_total'] ?? 0)) : 0;
    $earningFrozen = $hasEarningCols ? max(0, (int)($row['traffic_earnings_frozen'] ?? 0)) : 0;
    $expires = $row['traffic_expires_at'];
    $expired = $expires && strtotime($expires) <= time();
    $baseLeft = $expired ? 0 : max(0, $baseTotal - $baseUsed);

    // 计算下次自动重置时间
    $resetDays = (int)$row['auto_reset_days'];
    $nextReset = null;
    if ($resetDays === 0) {
        $g = $pdo->prepare('SELECT auto_reset_days FROM user_groups WHERE id = (SELECT group_id FROM users WHERE id = ?)');
        $g->execute([$userId]);
        $resetDays = (int)($g->fetchColumn() ?: 0);
    }
    if ($resetDays > 0) {
        $base = $row['traffic_last_reset_at'] ?: date('Y-m-d H:i:s');
        $nextReset = computeNextAutoResetAt($row['traffic_last_reset_at'], $resetDays, $base);
    }

    return [
        'base_total' => $baseTotal,
        'base_used' => $baseUsed,
        'base_left' => $baseLeft,
        'earning_available' => $earningAvailable,
        'earning_frozen' => $earningFrozen,
        'total' => $baseTotal + $earningAvailable,
        'used' => $baseUsed,
        'left' => $baseLeft + $earningAvailable,
        'expires_at' => $expires,
        'expired' => (bool)$expired,
        'auto_reset_days' => $resetDays,
        'last_reset_at' => $row['traffic_last_reset_at'],
        'next_reset_at' => $nextReset,
    ];
}

// 计算用户实际生效的自动重置周期（用户级 > 0 优先；否则取分组级）
function getEffectiveAutoResetDays(PDO $pdo, int $userId): int {
    $st = $pdo->prepare("SELECT u.auto_reset_days AS u_days, g.auto_reset_days AS g_days
        FROM users u LEFT JOIN user_groups g ON g.id = u.group_id WHERE u.id = ?");
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) return 0;
    $u = (int)$row['u_days'];
    $g = (int)($row['g_days'] ?? 0);
    return $u > 0 ? $u : $g;
}

function computeNextAutoResetAt(?string $lastReset, int $resetDays, ?string $fallbackAnchor = null): ?string
{
    if ($resetDays <= 0) {
        return null;
    }
    $base = $lastReset ?: ($fallbackAnchor ?: date('Y-m-d H:i:s'));
    return date('Y-m-d H:i:s', strtotime($base) + $resetDays * 86400);
}

function isAutoResetDue(?string $lastReset, int $resetDays, ?int $now = null): bool
{
    if ($resetDays <= 0 || !$lastReset) {
        return false;
    }
    $now = $now ?? time();
    return (strtotime($lastReset) + $resetDays * 86400) <= $now;
}

function ensureAutoResetAnchor(PDO $pdo, int $userId): ?string
{
    $st = $pdo->prepare('SELECT traffic_last_reset_at FROM users WHERE id = ? LIMIT 1');
    $st->execute([$userId]);
    $lastReset = $st->fetchColumn();
    if ($lastReset) {
        return (string)$lastReset;
    }

    $anchor = date('Y-m-d H:i:s');
    $upd = $pdo->prepare('UPDATE users SET traffic_last_reset_at = ? WHERE id = ? AND traffic_last_reset_at IS NULL');
    $upd->execute([$anchor, $userId]);
    if ($upd->rowCount() > 0) {
        return $anchor;
    }

    $st->execute([$userId]);
    $lastReset = $st->fetchColumn();
    return $lastReset ? (string)$lastReset : $anchor;
}

// 用户/分组实际生效的自动重置周期 SQL 表达式
function trafficEffectiveAutoResetDaysSql(string $userAlias = 'u', string $groupAlias = 'g'): string
{
    return "CASE
        WHEN COALESCE({$userAlias}.auto_reset_days, 0) > 0 THEN {$userAlias}.auto_reset_days
        ELSE COALESCE({$groupAlias}.auto_reset_days, 0)
    END";
}

// 登录用户访问任意页面时触发到期检测
function touchAutoResetForUser(PDO $pdo, int $userId): void
{
    if ($userId <= 0 || !trafficFeatureEnabled($pdo)) {
        return;
    }
    maybeAutoResetTraffic($pdo, $userId);
}

// 后台定时批量检测（5 分钟内最多执行一次，避免每次翻页都全量扫描）
function maybeRunScheduledAutoResets(PDO $pdo): void
{
    static $ran = false;
    if ($ran) {
        return;
    }
    $ran = true;

    if (!trafficFeatureEnabled($pdo) || !trafficColumnExists($pdo, 'users', 'auto_reset_days')) {
        return;
    }

    require_once __DIR__ . '/settings.php';
    $lastRun = (int)(getSetting($pdo, 'traffic_auto_reset_last_run', '0') ?: '0');
    if (time() - $lastRun < 300) {
        return;
    }

    runDueAutoResets($pdo);
    setSetting($pdo, 'traffic_auto_reset_last_run', (string)time());
}

// 自动重置检测：到期则按分组默认值重发流量、清空已用、清除该用户解锁记录
function maybeAutoResetTraffic(PDO $pdo, int $userId): bool {
    static $done = [];
    if (isset($done[$userId])) {
        return false;
    }

    if (!trafficFeatureEnabled($pdo)) {
        return false;
    }

    $st = $pdo->prepare("SELECT u.id, u.group_id, u.traffic_total, u.traffic_used, u.traffic_last_reset_at, u.auto_reset_days,
            g.default_traffic, g.traffic_validity_days, g.auto_reset_days AS group_auto_reset_days
        FROM users u LEFT JOIN user_groups g ON g.id = u.group_id WHERE u.id = ?");
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) {
        return false;
    }

    $userDays = (int)$row['auto_reset_days'];
    $groupDays = (int)($row['group_auto_reset_days'] ?? 0);
    $resetDays = $userDays > 0 ? $userDays : $groupDays;
    if ($resetDays <= 0) {
        $done[$userId] = true;
        return false;
    }

    $lastReset = $row['traffic_last_reset_at'] ? (string)$row['traffic_last_reset_at'] : null;
    $now = time();

    if (!$lastReset) {
        ensureAutoResetAnchor($pdo, $userId);
        $done[$userId] = true;
        return false;
    }

    if (!isAutoResetDue($lastReset, $resetDays, $now)) {
        $done[$userId] = true;
        return false;
    }

    // 触发重置
    $beforeTotal = (int)$row['traffic_total'];
    $beforeUsed  = (int)$row['traffic_used'];
    $defaultTraffic = (int)($row['default_traffic'] ?? 0);
    $validityDays   = (int)($row['traffic_validity_days'] ?? 0);
    $newExpires = $validityDays > 0 ? date('Y-m-d H:i:s', $now + $validityDays * 86400) : null;
    $newResetAt = chinaNow();

    $ownTransaction = !trafficInTransaction($pdo);
    try {
        if ($ownTransaction) {
            $pdo->beginTransaction();
        }
        $pdo->prepare("UPDATE users SET traffic_total = ?, traffic_used = 0, traffic_expires_at = ?, traffic_last_reset_at = ? WHERE id = ?")
            ->execute([$defaultTraffic, $newExpires, $newResetAt, $userId]);
        $pdo->prepare("DELETE FROM video_unlocks WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("INSERT INTO traffic_logs (user_id, action, change_amount, before_total, before_used, after_total, after_used, remark, operator_id)
            VALUES (?, 'auto_reset', ?, ?, ?, ?, 0, ?, 0)")
            ->execute([$userId, $defaultTraffic - $beforeTotal, $beforeTotal, $beforeUsed, $defaultTraffic,
                       sprintf('自动重置（周期%d天）', $resetDays)]);
        if ($ownTransaction) {
            trafficCommit($pdo);
        }
        $done[$userId] = true;
        return true;
    } catch (Throwable $e) {
        if ($ownTransaction) {
            trafficRollback($pdo);
        } else {
            throw $e;
        }
        return false;
    }
}

// 批量检测并执行已到期的自动重置（供后台页面、定时任务等入口调用）
function runDueAutoResets(PDO $pdo): int
{
    if (!trafficFeatureEnabled($pdo)) {
        return 0;
    }
    if (!trafficColumnExists($pdo, 'users', 'auto_reset_days')) {
        return 0;
    }

    $resetDaysExpr = trafficEffectiveAutoResetDaysSql();

    // 启用自动重置但尚未开始计时的用户，先补建锚点
    $pdo->exec("
        UPDATE users u
        LEFT JOIN user_groups g ON g.id = u.group_id
        SET u.traffic_last_reset_at = NOW()
        WHERE u.traffic_last_reset_at IS NULL
          AND ({$resetDaysExpr}) > 0
    ");

    $sql = "SELECT u.id
        FROM users u
        LEFT JOIN user_groups g ON g.id = u.group_id
        WHERE u.traffic_last_reset_at IS NOT NULL
          AND ({$resetDaysExpr}) > 0
          AND u.traffic_last_reset_at <= DATE_SUB(NOW(), INTERVAL ({$resetDaysExpr}) DAY)";
    $ids = $pdo->query($sql)->fetchAll(PDO::FETCH_COLUMN);
    $count = 0;
    foreach ($ids as $uid) {
        if (maybeAutoResetTraffic($pdo, (int)$uid)) {
            $count++;
        }
    }
    return $count;
}

// 手动重置某个用户的流量（清除解锁、按分组默认值发放、刷新 last_reset_at）
function manualResetUserTraffic(PDO $pdo, int $userId, int $operatorId, string $action = 'manual_reset', string $remark = '手动重置'): bool {
    if (!trafficFeatureEnabled($pdo)) return false;

    $st = $pdo->prepare("SELECT u.traffic_total, u.traffic_used, g.default_traffic, g.traffic_validity_days
        FROM users u LEFT JOIN user_groups g ON g.id = u.group_id WHERE u.id = ?");
    $st->execute([$userId]);
    $row = $st->fetch();
    if (!$row) return false;

    $defaultTraffic = (int)($row['default_traffic'] ?? 0);
    $validityDays   = (int)($row['traffic_validity_days'] ?? 0);
    $now = time();
    $newExpires = $validityDays > 0 ? date('Y-m-d H:i:s', $now + $validityDays * 86400) : null;
    $newResetAt = date('Y-m-d H:i:s', $now);

    try {
        $pdo->beginTransaction();
        $pdo->prepare("UPDATE users SET traffic_total = ?, traffic_used = 0, traffic_expires_at = ?, traffic_last_reset_at = ? WHERE id = ?")
            ->execute([$defaultTraffic, $newExpires, $newResetAt, $userId]);
        $pdo->prepare("DELETE FROM video_unlocks WHERE user_id = ?")->execute([$userId]);
        $pdo->prepare("INSERT INTO traffic_logs (user_id, action, change_amount, before_total, before_used, after_total, after_used, remark, operator_id)
            VALUES (?, ?, ?, ?, ?, ?, 0, ?, ?)")
            ->execute([$userId, $action, $defaultTraffic - (int)$row['traffic_total'],
                       (int)$row['traffic_total'], (int)$row['traffic_used'], $defaultTraffic, $remark, $operatorId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        return false;
    }
}

// 一键重置某个用户组下所有用户的流量
function resetAllUsersInGroup(PDO $pdo, int $groupId, int $operatorId): int {
    if (!trafficFeatureEnabled($pdo)) return 0;
    $ids = $pdo->prepare("SELECT id FROM users WHERE group_id = ?");
    $ids->execute([$groupId]);
    $count = 0;
    foreach ($ids->fetchAll(PDO::FETCH_COLUMN) as $uid) {
        if (manualResetUserTraffic($pdo, (int)$uid, $operatorId, 'group_bulk_reset', '管理员一键重置本组用户流量')) {
            $count++;
        }
    }
    return $count;
}

function trafficColumnExists(PDO $pdo, string $table, string $column): bool
{
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !preg_match('/^[A-Za-z0-9_]+$/', $column)) {
        return false;
    }

    $stmt = $pdo->query('
        SELECT COUNT(*)
        FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = ' . $pdo->quote($table) . '
          AND COLUMN_NAME = ' . $pdo->quote($column) . '
    ');
    return (int)$stmt->fetchColumn() > 0;
}

function trafficInTransaction(PDO $pdo): bool
{
    try {
        return $pdo->inTransaction();
    } catch (Throwable $e) {
        return false;
    }
}

function trafficRollback(PDO $pdo): void
{
    if (trafficInTransaction($pdo)) {
        $pdo->rollBack();
    }
}

function trafficCommit(PDO $pdo): void
{
    if (trafficInTransaction($pdo)) {
        $pdo->commit();
    }
}

function ensureTrafficEarningsSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    if (!trafficColumnExists($pdo, 'users', 'traffic_earnings_total')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN traffic_earnings_total int(11) NOT NULL DEFAULT 0");
    }
    if (!trafficColumnExists($pdo, 'users', 'traffic_earnings_frozen')) {
        $pdo->exec("ALTER TABLE users ADD COLUMN traffic_earnings_frozen int(11) NOT NULL DEFAULT 0");
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `traffic_earning_logs` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `payer_user_id` int(11) DEFAULT NULL,
        `publisher_user_id` int(11) NOT NULL,
        `video_id` int(11) DEFAULT NULL,
        `upload_id` bigint unsigned DEFAULT NULL,
        `amount` int(11) NOT NULL DEFAULT 0,
        `status` enum('settled','frozen','reclaimed') NOT NULL DEFAULT 'settled',
        `reason` varchar(255) DEFAULT NULL,
        `operated_by` int(11) DEFAULT NULL,
        `operated_at` datetime DEFAULT NULL,
        `paid_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_publisher` (`publisher_user_id`),
        KEY `idx_payer` (`payer_user_id`),
        KEY `idx_video` (`video_id`),
        KEY `idx_status` (`status`),
        KEY `idx_paid_at` (`paid_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ready = true;
}

function getUserEarningTraffic(PDO $pdo, int $userId): array
{
    ensureTrafficEarningsSchema($pdo);
    $stmt = $pdo->prepare('SELECT traffic_earnings_total, traffic_earnings_frozen FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();

    return [
        'available' => $row ? (int)$row['traffic_earnings_total'] : 0,
        'frozen' => $row ? (int)$row['traffic_earnings_frozen'] : 0,
    ];
}

function fetchUserEarningLogs(PDO $pdo, int $userId, int $limit = 50): array
{
    ensureTrafficEarningsSchema($pdo);
    $stmt = $pdo->prepare("
        SELECT tel.*, payer.username AS payer_username, v.title AS video_title
        FROM traffic_earning_logs tel
        LEFT JOIN users payer ON payer.id = tel.payer_user_id
        LEFT JOIN videos v ON v.id = tel.video_id
        WHERE tel.publisher_user_id = ?
        ORDER BY tel.id DESC
        LIMIT {$limit}
    ");
    $stmt->execute([$userId]);
    return $stmt->fetchAll() ?: [];
}

function trafficFindVideoPublisher(PDO $pdo, int $videoId): ?array
{
    $raw = getSetting($pdo, 'video_sync_record_map', '{}');
    $map = json_decode((string)$raw, true);
    if (!is_array($map)) {
        return null;
    }

    foreach ($map as $recordId => $mappedVideoId) {
        if ((int)$mappedVideoId !== $videoId || !preg_match('/^upload_(\d+)$/', (string)$recordId, $m)) {
            continue;
        }
        if (!(bool)$pdo->query("SHOW TABLES LIKE 'user_video_uploads'")->fetch()) {
            return null;
        }
        $stmt = $pdo->prepare('
            SELECT vu.id AS upload_id, vu.user_id, u.username, u.email
            FROM user_video_uploads vu
            LEFT JOIN users u ON u.id = vu.user_id
            WHERE vu.id = ?
            LIMIT 1
        ');
        $stmt->execute([(int)$m[1]]);
        $row = $stmt->fetch();
        return $row ?: null;
    }

    return null;
}

function creditTrafficEarning(PDO $pdo, int $publisherUserId, int $payerUserId, int $videoId, int $uploadId, int $amount): void
{
    if ($publisherUserId <= 0 || $amount <= 0 || $publisherUserId === $payerUserId) {
        return;
    }
    $stmt = $pdo->prepare('SELECT traffic_earnings_total FROM users WHERE id = ? FOR UPDATE');
    $stmt->execute([$publisherUserId]);
    $row = $stmt->fetch();
    if (!$row) {
        return;
    }

    $newTotal = max(0, (int)$row['traffic_earnings_total'] + $amount);
    $pdo->prepare('UPDATE users SET traffic_earnings_total = ? WHERE id = ?')->execute([$newTotal, $publisherUserId]);
    $pdo->prepare('
        INSERT INTO traffic_earning_logs (payer_user_id, publisher_user_id, video_id, upload_id, amount, status)
        VALUES (?, ?, ?, ?, ?, "settled")
    ')->execute([$payerUserId, $publisherUserId, $videoId, $uploadId > 0 ? $uploadId : null, $amount]);
}

function trafficCreateUserNotification(PDO $pdo, int $userId, string $title, string $content, int $adminId = 0): void
{
    if ($userId <= 0) {
        return;
    }
    $pdo->exec("CREATE TABLE IF NOT EXISTS `notifications` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `title` varchar(200) NOT NULL,
        `content` text NOT NULL,
        `target_type` enum('all','user') NOT NULL DEFAULT 'all',
        `target_user_id` int(11) DEFAULT NULL,
        `created_by` int(11) NOT NULL DEFAULT 0,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_target` (`target_type`,`target_user_id`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    $pdo->prepare('INSERT INTO notifications (title, content, target_type, target_user_id, created_by) VALUES (?, ?, "user", ?, ?)')
        ->execute([$title, $content, $userId, $adminId]);
}

function trafficNotifyUserByMail(PDO $pdo, int $userId, string $subject, string $body): array
{
    $stmt = $pdo->prepare('SELECT email FROM users WHERE id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $email = (string)($stmt->fetchColumn() ?: '');
    if ($email === '') {
        return ['ok' => false, 'message' => '用户邮箱为空'];
    }
    require_once __DIR__ . '/mail_sender.php';
    return sendSiteMail($pdo, $email, $subject, $body, false);
}

function trafficEarningStatusLabel(string $status): string
{
    return match ($status) {
        'frozen' => '已冻结',
        'reclaimed' => '已收回',
        default => '已到账',
    };
}

function fetchTrafficEarningAdminRows(PDO $pdo): array
{
    ensureTrafficEarningsSchema($pdo);
    return $pdo->query("
        SELECT tel.*, payer.username AS payer_username, publisher.username AS publisher_username, v.title AS video_title
        FROM traffic_earning_logs tel
        LEFT JOIN users payer ON payer.id = tel.payer_user_id
        LEFT JOIN users publisher ON publisher.id = tel.publisher_user_id
        LEFT JOIN videos v ON v.id = tel.video_id
        ORDER BY tel.id DESC
        LIMIT 300
    ")->fetchAll() ?: [];
}

function fetchTrafficEarningUserSummary(PDO $pdo): array
{
    ensureTrafficEarningsSchema($pdo);
    return $pdo->query("
        SELECT id, username, email, traffic_earnings_total, traffic_earnings_frozen
        FROM users
        WHERE traffic_earnings_total > 0 OR traffic_earnings_frozen > 0
        ORDER BY (traffic_earnings_total + traffic_earnings_frozen) DESC, id DESC
    ")->fetchAll() ?: [];
}

function adjustTrafficEarningLog(PDO $pdo, int $logId, string $action, string $reason, int $adminId): array
{
    ensureTrafficEarningsSchema($pdo);
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'message' => '请填写原因'];
    }
    if (!in_array($action, ['freeze', 'reclaim'], true)) {
        return ['ok' => false, 'message' => '操作无效'];
    }

    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare('SELECT * FROM traffic_earning_logs WHERE id = ? FOR UPDATE');
        $stmt->execute([$logId]);
        $log = $stmt->fetch();
        if (!$log) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => '收益账单不存在'];
        }
        $userId = (int)$log['publisher_user_id'];
        $amount = (int)$log['amount'];
        $status = (string)$log['status'];

        $u = $pdo->prepare('SELECT traffic_earnings_total, traffic_earnings_frozen FROM users WHERE id = ? FOR UPDATE');
        $u->execute([$userId]);
        $user = $u->fetch();
        if (!$user) {
            $pdo->rollBack();
            return ['ok' => false, 'message' => '收益用户不存在'];
        }

        $available = (int)$user['traffic_earnings_total'];
        $frozen = (int)$user['traffic_earnings_frozen'];
        if ($action === 'freeze') {
            if ($status !== 'settled') {
                $pdo->rollBack();
                return ['ok' => false, 'message' => '只有已到账账单可以冻结'];
            }
            if ($available < $amount) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => '用户可用收益流量不足，无法冻结'];
            }
            $available -= $amount;
            $frozen += $amount;
            $newStatus = 'frozen';
        } else {
            if (!in_array($status, ['settled', 'frozen'], true)) {
                $pdo->rollBack();
                return ['ok' => false, 'message' => '该账单已收回'];
            }
            if ($status === 'frozen') {
                $frozen = max(0, $frozen - $amount);
            } else {
                if ($available < $amount) {
                    $pdo->rollBack();
                    return ['ok' => false, 'message' => '用户可用收益流量不足，无法收回'];
                }
                $available -= $amount;
            }
            $newStatus = 'reclaimed';
        }

        $pdo->prepare('UPDATE users SET traffic_earnings_total = ?, traffic_earnings_frozen = ? WHERE id = ?')
            ->execute([$available, $frozen, $userId]);
        $pdo->prepare('UPDATE traffic_earning_logs SET status = ?, reason = ?, operated_by = ?, operated_at = NOW() WHERE id = ?')
            ->execute([$newStatus, $reason, $adminId, $logId]);
        $pdo->commit();

        $title = $action === 'freeze' ? '收益流量已冻结' : '收益流量已收回';
        $content = $title . '：账单 #' . $logId . '，额度 ' . $amount . '。原因：' . $reason;
        trafficCreateUserNotification($pdo, $userId, $title, $content, $adminId);
        $mail = trafficNotifyUserByMail($pdo, $userId, '【竹叶云控】' . $title, $content);

        return [
            'ok' => true,
            'message' => $title . (!empty($mail['ok']) ? '，邮件已发送' : '，站内通知已发送（邮件未发送：' . ($mail['message'] ?? '未配置') . '）'),
        ];
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        return ['ok' => false, 'message' => $e->getMessage()];
    }
}

function adjustAllTrafficEarningsForUser(PDO $pdo, int $userId, string $action, string $reason, int $adminId): array
{
    ensureTrafficEarningsSchema($pdo);
    $reason = trim($reason);
    if ($reason === '') {
        return ['ok' => false, 'message' => '请填写原因'];
    }
    if (!in_array($action, ['freeze', 'reclaim'], true)) {
        return ['ok' => false, 'message' => '操作无效'];
    }

    $stmt = $pdo->prepare($action === 'freeze'
        ? 'SELECT id FROM traffic_earning_logs WHERE publisher_user_id = ? AND status = "settled" ORDER BY id'
        : 'SELECT id FROM traffic_earning_logs WHERE publisher_user_id = ? AND status IN ("settled","frozen") ORDER BY id');
    $stmt->execute([$userId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN) ?: []);
    if ($ids === []) {
        return ['ok' => false, 'message' => '没有可处理的收益账单'];
    }

    $ok = 0;
    $lastMessage = '';
    foreach ($ids as $id) {
        $result = adjustTrafficEarningLog($pdo, $id, $action, $reason, $adminId);
        if (!empty($result['ok'])) {
            $ok++;
            $lastMessage = (string)$result['message'];
        }
    }

    return $ok > 0
        ? ['ok' => true, 'message' => '已处理 ' . $ok . ' 条收益账单。' . $lastMessage]
        : ['ok' => false, 'message' => '处理失败'];
}

/** 是否为该视频的上传者（uploaded_by） */
function trafficIsVideoUploader(array $video, int $userId): bool
{
    if ($userId <= 0) {
        return false;
    }
    $uploaderId = (int)($video['uploaded_by'] ?? 0);

    return $uploaderId > 0 && $uploaderId === $userId;
}

const TRAFFIC_TRIAL_PERCENT = 15;

function trafficTrialPercent(): int
{
    return TRAFFIC_TRIAL_PERCENT;
}

/** 未解锁的流量视频是否允许试看（前端限制为总时长 3%） */
function trafficAllowsTrialWatch(array $unlockInfo): bool
{
    return !empty($unlockInfo['is_traffic'])
        && !empty($unlockInfo['need_pay'])
        && empty($unlockInfo['unlocked']);
}

function trafficTrialMaxSeconds(float $durationSeconds): float
{
    if ($durationSeconds <= 0) {
        return 0;
    }

    return $durationSeconds * trafficTrialPercent() / 100;
}

// 获取一个视频的解锁状态——解锁有效期跟随流量周期（流量被自动/手动重置时清除解锁记录）
// 当用户流量已过期（traffic_expires_at <= now）时也视为需要重新解锁
// 返回 ['unlocked' => bool, 'need_pay' => bool, 'is_traffic' => bool, 'reason' => ?, ...]
function getVideoUnlockStatus(PDO $pdo, int $userId, array $video): array {
    if (isAdmin()) {
        return [
            'unlocked' => true,
            'is_traffic' => !empty($video['is_traffic']),
            'need_pay' => false,
        ];
    }
    if (empty($video['is_traffic'])) {
        return ['unlocked' => true, 'is_traffic' => false, 'need_pay' => false];
    }
    if (trafficIsVideoUploader($video, $userId)) {
        return [
            'unlocked' => true,
            'is_traffic' => true,
            'need_pay' => false,
            'is_owner' => true,
        ];
    }
    $videoId = (int)$video['id'];

    // 用户流量是否过期？过期则视为需要重新解锁
    $u = $pdo->prepare("SELECT traffic_expires_at FROM users WHERE id = ?");
    $u->execute([$userId]);
    $row = $u->fetch();
    $trafficExpired = false;
    if ($row && !empty($row['traffic_expires_at']) && strtotime($row['traffic_expires_at']) <= time()) {
        $trafficExpired = true;
    }

    $st = $pdo->prepare("SELECT * FROM video_unlocks WHERE user_id = ? AND video_id = ?");
    $st->execute([$userId, $videoId]);
    $rec = $st->fetch();

    if ($rec && !empty($rec['expires_at']) && strtotime((string)$rec['expires_at']) > time()) {
        return [
            'unlocked' => true,
            'is_traffic' => true,
            'need_pay' => false,
            'paid_at' => $rec['paid_at'],
            'expires_at' => $rec['expires_at'],
            'temporary_unlock' => true,
        ];
    }

    if ($rec && !empty($rec['expires_at']) && strtotime((string)$rec['expires_at']) <= time()) {
        $rec = null;
    }

    if (!$rec || $trafficExpired) {
        return [
            'unlocked' => false,
            'is_traffic' => true,
            'need_pay' => true,
            'cost' => (int)$video['traffic_cost'],
            'reason' => $trafficExpired ? 'traffic_expired' : 'not_unlocked',
        ];
    }

    return [
        'unlocked' => true,
        'is_traffic' => true,
        'need_pay' => false,
        'paid_at' => $rec['paid_at'],
        'follow_traffic' => true,
    ];
}

// 执行支付解锁；解锁有效期跟随流量周期（流量被自动/手动重置时该解锁记录会被清除）
function payAndUnlockVideo(PDO $pdo, int $userId, array $video, int $operatorId = 0): array {
    if (empty($video['is_traffic'])) {
        return ['ok' => true, 'unlocked' => true, 'message' => '该视频无需流量'];
    }
    if (trafficIsVideoUploader($video, $userId)) {
        return ['ok' => true, 'unlocked' => true, 'message' => '您上传的视频无需解锁'];
    }
    ensureTrafficEarningsSchema($pdo);
    $videoId = (int)$video['id'];
    $cost = max(0, (int)$video['traffic_cost']);
    $publisher = trafficFindVideoPublisher($pdo, $videoId);

    $pdo->beginTransaction();
    try {
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
            trafficRollback($pdo);
            return ['ok' => false, 'message' => '用户不存在'];
        }
        $total = (int)$u['traffic_total'];
        $used = (int)$u['traffic_used'];
        $expires = $u['traffic_expires_at'];
        $expired = $expires && strtotime($expires) <= time();
        $earningAvailable = $hasEarningCols ? max(0, (int)($u['traffic_earnings_total'] ?? 0)) : 0;
        $baseLeft = $expired ? 0 : max(0, $total - $used);
        $combinedLeft = $baseLeft + $earningAvailable;

        if ($combinedLeft < $cost) {
            trafficRollback($pdo);
            return [
                'ok' => false,
                'message' => '剩余流量不足，需要 ' . $cost . '，当前剩余 ' . $combinedLeft
                    . '（基础 ' . $baseLeft . ' + 收益 ' . $earningAvailable . '）',
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

        // expires_at = NULL 表示有效期跟随流量周期（流量重置时此记录会被一并清除）
        $check = $pdo->prepare("SELECT id FROM video_unlocks WHERE user_id = ? AND video_id = ?");
        $check->execute([$userId, $videoId]);
        if ($row = $check->fetch()) {
            $pdo->prepare("UPDATE video_unlocks SET cost = ?, expires_at = NULL, refresh_count = 0, refresh_limit = 0, validity_minutes = 0, paid_at = NOW() WHERE id = ?")
                ->execute([$cost, (int)$row['id']]);
        } else {
            $pdo->prepare("INSERT INTO video_unlocks (user_id, video_id, cost, expires_at, refresh_count, refresh_limit, validity_minutes) VALUES (?, ?, ?, NULL, 0, 0, 0)")
                ->execute([$userId, $videoId, $cost]);
        }

        $unlockRemark = '解锁视频#' . $videoId;
        if ($fromEarning > 0) {
            $unlockRemark .= '（基础-' . $fromBase . '，收益-' . $fromEarning . '）';
        }
        $pdo->prepare("INSERT INTO traffic_logs (user_id, action, change_amount, before_total, before_used, after_total, after_used, remark, operator_id)
            VALUES (?, 'pay_unlock', ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$userId, -$cost, $total, $used, $total, $newUsed, $unlockRemark, $operatorId ?: $userId]);

        if ($publisher && (int)$publisher['user_id'] !== $userId) {
            creditTrafficEarning(
                $pdo,
                (int)$publisher['user_id'],
                $userId,
                $videoId,
                (int)$publisher['upload_id'],
                $cost
            );
        }

        trafficCommit($pdo);
        return [
            'ok' => true,
            'unlocked' => true,
            'cost' => $cost,
            'left' => max(0, $total - $newUsed) + $newEarning,
            'publisher_credited' => $publisher && (int)$publisher['user_id'] !== $userId,
            'follow_traffic' => true,
        ];
    } catch (Throwable $e) {
        trafficRollback($pdo);
        return ['ok' => false, 'message' => '解锁失败：' . $e->getMessage()];
    }
}

// 新用户注册时按所属分组发放初始流量
function grantInitialTrafficFromGroup(PDO $pdo, int $userId, int $groupId): bool
{
    if (!trafficFeatureEnabled($pdo)) {
        return false;
    }

    $st = $pdo->prepare("SELECT default_traffic, traffic_validity_days FROM user_groups WHERE id = ?");
    $st->execute([$groupId]);
    $row = $st->fetch();
    if (!$row) {
        return false;
    }

    $defaultTraffic = (int)($row['default_traffic'] ?? 0);
    $validityDays = (int)($row['traffic_validity_days'] ?? 0);
    $now = time();
    $newExpires = $validityDays > 0 ? date('Y-m-d H:i:s', $now + $validityDays * 86400) : null;
    $newResetAt = date('Y-m-d H:i:s', $now);

    try {
        $pdo->prepare("UPDATE users SET traffic_total = ?, traffic_used = 0, traffic_expires_at = ?, traffic_last_reset_at = ? WHERE id = ?")
            ->execute([$defaultTraffic, $newExpires, $newResetAt, $userId]);
        $pdo->prepare("INSERT INTO traffic_logs (user_id, action, change_amount, before_total, before_used, after_total, after_used, remark, operator_id)
            VALUES (?, 'register_grant', ?, 0, 0, ?, 0, ?, 0)")
            ->execute([$userId, $defaultTraffic, $defaultTraffic, '注册初始流量']);
        return true;
    } catch (Throwable $e) {
        return false;
    }
}
