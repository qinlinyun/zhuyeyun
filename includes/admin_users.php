<?php

require_once __DIR__ . '/ban_notice.php';
require_once __DIR__ . '/datetime.php';

/** @return array{total:int,active:int,banned:int,frozen:int,timed_ban:int} */
function adminUsersCountStats(PDO $pdo, string $keyword = ''): array
{
    $whereSql = '';
    $params = [];
    if ($keyword !== '') {
        $whereSql = ' WHERE u.username LIKE ? OR u.email LIKE ?';
        $params[] = "%{$keyword}%";
        $params[] = "%{$keyword}%";
    }

    $countStmt = $pdo->prepare('SELECT COUNT(*) FROM users u' . $whereSql);
    $countStmt->execute($params);
    $total = (int)$countStmt->fetchColumn();

    $stats = ['total' => $total, 'active' => 0, 'banned' => 0, 'frozen' => 0, 'timed_ban' => 0];
    $statusStmt = $pdo->prepare('SELECT u.status, COUNT(*) AS cnt FROM users u' . $whereSql . ' GROUP BY u.status');
    $statusStmt->execute($params);
    foreach ($statusStmt->fetchAll() ?: [] as $row) {
        $s = (string)($row['status'] ?? '');
        $cnt = (int)($row['cnt'] ?? 0);
        if ($s === 'active') {
            $stats['active'] = $cnt;
        } elseif ($s === 'banned') {
            $stats['banned'] = $cnt;
        } elseif ($s === 'frozen') {
            $stats['frozen'] = $cnt;
        }
    }

    $timedSql = 'SELECT COUNT(*) FROM users u' . ($whereSql === '' ? ' WHERE' : $whereSql . ' AND')
        . " u.status = 'banned' AND u.ban_until IS NOT NULL";
    $timedStmt = $pdo->prepare($timedSql);
    $timedStmt->execute($params);
    $stats['timed_ban'] = (int)$timedStmt->fetchColumn();

    return $stats;
}

/** @return list<string> */
function adminUsersSections(): array
{
    return ['overview', 'users', 'banned', 'frozen', 'timed_ban'];
}

/** @return list<string> */
function adminUsersListSections(): array
{
    return ['users', 'banned', 'frozen', 'timed_ban'];
}

function adminUsersSectionTitle(string $section): string
{
    return match ($section) {
        'banned' => '封禁用户',
        'frozen' => '冻结用户',
        'timed_ban' => '定时封禁',
        default => '用户列表',
    };
}

function adminUsersSectionEmptyText(string $section, bool $hasKeyword): string
{
    if ($hasKeyword) {
        return '未找到匹配用户';
    }

    return match ($section) {
        'banned' => '暂无封禁用户',
        'frozen' => '暂无冻结用户',
        'timed_ban' => '暂无定时封禁用户',
        default => '暂无用户',
    };
}

/** @return array{0:string,1:list<mixed>} */
function adminUsersBuildListWhere(string $section, string $keyword): array
{
    $conditions = [];
    $params = [];

    if ($keyword !== '') {
        $conditions[] = '(u.username LIKE ? OR u.email LIKE ?)';
        $params[] = "%{$keyword}%";
        $params[] = "%{$keyword}%";
    }

    switch ($section) {
        case 'banned':
            $conditions[] = "u.status = 'banned'";
            break;
        case 'frozen':
            $conditions[] = "u.status = 'frozen'";
            break;
        case 'timed_ban':
            $conditions[] = "u.status = 'banned' AND u.ban_until IS NOT NULL";
            break;
    }

    $whereSql = $conditions === [] ? '' : ' WHERE ' . implode(' AND ', $conditions);

    return [$whereSql, $params];
}

function adminUsersFetchRow(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('
        SELECT u.*, g.name AS group_name
        FROM users u
        LEFT JOIN user_groups g ON u.group_id = g.id
        WHERE u.id = ?
        LIMIT 1
    ');
    $stmt->execute([$userId]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function adminUsersFormatRowForApi(array $row): array
{
    return [
        'id' => (int)$row['id'],
        'username' => (string)($row['username'] ?? ''),
        'email' => (string)($row['email'] ?? ''),
        'status' => (string)($row['status'] ?? ''),
        'ban_until' => $row['ban_until'] ? (string)$row['ban_until'] : '',
        'ban_until_fmt' => $row['ban_until'] ? formatChinaDateTime((string)$row['ban_until']) : '',
        'group_id' => (string)($row['group_id'] ?? ''),
        'group_name' => (string)($row['group_name'] ?? '未分组'),
        'created_at' => (string)($row['created_at'] ?? ''),
        'created_at_fmt' => formatChinaDateTime((string)($row['created_at'] ?? '')),
    ];
}

/**
 * @param array<string, mixed> $payload
 * @return array{ok:bool,message?:string,deleted?:bool,user_id?:int,user?:array,stats?:array}
 */
function adminUsersPerformAction(PDO $pdo, string $action, int $userId, array $payload = [], string $keyword = ''): array
{
    if ($userId <= 0) {
        return ['ok' => false, 'message' => '无效的用户 ID'];
    }

    $message = '';
    $deleted = false;

    switch ($action) {
        case 'ban':
            $user = fetchUserForBanNotice($pdo, $userId);
            $pdo->prepare("UPDATE users SET status='banned', ban_until=NULL WHERE id=?")->execute([$userId]);
            if ($user) {
                trySendBanNotice($pdo, $user, BAN_NOTICE_ACTION_BAN);
            }
            $message = '用户已封禁';
            break;

        case 'ban_custom':
            $banUntil = trim((string)($payload['ban_until'] ?? ''));
            if ($banUntil === '') {
                return ['ok' => false, 'message' => '请选择封禁截止时间'];
            }
            $user = fetchUserForBanNotice($pdo, $userId);
            $pdo->prepare("UPDATE users SET status='banned', ban_until=? WHERE id=?")
                ->execute([$banUntil, $userId]);
            if ($user) {
                trySendBanNotice($pdo, $user, BAN_NOTICE_ACTION_BAN_TIMED, $banUntil);
            }
            $message = '用户已定时封禁';
            break;

        case 'delete':
            $user = fetchUserForBanNotice($pdo, $userId);
            if ($user) {
                trySendBanNotice($pdo, $user, BAN_NOTICE_ACTION_DELETE);
            }
            $stmt = $pdo->prepare("DELETE FROM users WHERE id=? AND username!='admin'");
            $stmt->execute([$userId]);
            if ($stmt->rowCount() === 0) {
                return ['ok' => false, 'message' => '无法删除该用户'];
            }
            $message = '用户已删除';
            $deleted = true;
            break;

        case 'freeze':
            $user = fetchUserForBanNotice($pdo, $userId);
            $pdo->prepare("UPDATE users SET status='frozen' WHERE id=?")->execute([$userId]);
            if ($user) {
                trySendBanNotice($pdo, $user, BAN_NOTICE_ACTION_FREEZE);
            }
            $message = '用户已冻结';
            break;

        case 'unfreeze':
        case 'unban':
            $pdo->prepare("UPDATE users SET status='active', ban_until=NULL WHERE id=?")->execute([$userId]);
            $message = '用户状态已恢复';
            break;

        case 'reset_password':
            $newPassword = (string)($payload['new_password'] ?? '');
            if ($newPassword === '' || strlen($newPassword) < 6) {
                return ['ok' => false, 'message' => '密码至少 6 位'];
            }
            $pdo->prepare('UPDATE users SET password=? WHERE id=?')
                ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);
            $message = '密码已重置';
            break;

        case 'change_group':
            $groupId = (int)($payload['group_id'] ?? 0);
            if ($groupId <= 0) {
                return ['ok' => false, 'message' => '请选择用户组'];
            }
            $pdo->prepare('UPDATE users SET group_id=? WHERE id=?')->execute([$groupId, $userId]);
            $message = '用户分组已更改';
            break;

        default:
            return ['ok' => false, 'message' => '未知操作'];
    }

    $result = [
        'ok' => true,
        'message' => $message,
        'stats' => adminUsersCountStats($pdo, $keyword),
    ];

    if ($deleted) {
        $result['deleted'] = true;
        $result['user_id'] = $userId;

        return $result;
    }

    $row = adminUsersFetchRow($pdo, $userId);
    if (!$row) {
        return ['ok' => false, 'message' => '用户不存在'];
    }

    $result['user'] = adminUsersFormatRowForApi($row);

    return $result;
}
