<?php

/**
 * @return array<string, mixed>
 */
function getAdminOverviewStats(PDO $pdo): array
{
    $stats = [
        'users' => 0,
        'groups' => 0,
        'videos' => 0,
        'domains' => 0,
        'comments' => 0,
        'feedback_open' => 0,
        'notifications' => 0,
    ];

    try {
        $stats['users'] = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    } catch (Throwable $e) {
    }

    try {
        $stats['groups'] = (int)$pdo->query('SELECT COUNT(*) FROM user_groups')->fetchColumn();
    } catch (Throwable $e) {
    }

    try {
        $stats['videos'] = (int)$pdo->query('SELECT COUNT(*) FROM videos')->fetchColumn();
    } catch (Throwable $e) {
    }

    try {
        $stats['domains'] = (int)$pdo->query('SELECT COUNT(*) FROM domains')->fetchColumn();
    } catch (Throwable $e) {
    }

    try {
        if ($pdo->query("SHOW TABLES LIKE 'video_comments'")->fetchColumn()) {
            $stats['comments'] = (int)$pdo->query('SELECT COUNT(*) FROM video_comments')->fetchColumn();
        }
    } catch (Throwable $e) {
    }

    try {
        if ($pdo->query("SHOW TABLES LIKE 'feedbacks'")->fetchColumn()) {
            $stats['feedback_open'] = (int)$pdo->query("SELECT COUNT(*) FROM feedbacks WHERE status = 'open'")->fetchColumn();
        }
    } catch (Throwable $e) {
    }

    try {
        if ($pdo->query("SHOW TABLES LIKE 'notifications'")->fetchColumn()) {
            $stats['notifications'] = (int)$pdo->query('SELECT COUNT(*) FROM notifications')->fetchColumn();
        }
    } catch (Throwable $e) {
    }

    return $stats;
}

/**
 * @param string $prefix admin 目录前缀，如 '' 或 'admin/'
 * @param array<string, mixed> $stats
 * @return list<array<string, mixed>>
 */
function getAdminOverviewModules(string $prefix, array $stats): array
{
    return [
        [
            'id' => 'users',
            'title' => '用户管理',
            'desc' => '封禁、冻结、分组、密码重置',
            'href' => $prefix . 'users.php',
            'badge' => (int)($stats['users'] ?? 0) ?: null,
            'group' => '用户与权限',
        ],
        [
            'id' => 'groups',
            'title' => '用户组',
            'desc' => '分组权限与默认流量',
            'href' => $prefix . 'groups.php',
            'badge' => (int)($stats['groups'] ?? 0) ?: null,
            'group' => '用户与权限',
        ],
        [
            'id' => 'domains',
            'title' => '线路管理',
            'desc' => '播放域名与服务器分组',
            'href' => $prefix . 'domains.php',
            'badge' => (int)($stats['domains'] ?? 0) ?: null,
            'group' => '内容与播放',
        ],
        [
            'id' => 'videos',
            'title' => '视频管理',
            'desc' => '视频列表、分类与属性',
            'href' => $prefix . 'videos.php',
            'badge' => (int)($stats['videos'] ?? 0) ?: null,
            'group' => '内容与播放',
        ],
        [
            'id' => 'upload',
            'title' => '上传管理',
            'desc' => '转码、存储与上传配置',
            'href' => $prefix . 'upload_manage.php?section=overview',
            'badge' => null,
            'group' => '内容与播放',
        ],
        [
            'id' => 'traffic',
            'title' => '流量管理',
            'desc' => '用户流量、解锁与日志',
            'href' => $prefix . 'traffic.php',
            'badge' => null,
            'group' => '运营数据',
        ],
        [
            'id' => 'watch_records',
            'title' => '观看记录',
            'desc' => '全站用户观看行为',
            'href' => $prefix . 'watch_records.php',
            'badge' => null,
            'group' => '运营数据',
        ],
        [
            'id' => 'wheel',
            'title' => '幸运大转盘',
            'desc' => '奖品概率、流量与视频解锁奖励',
            'href' => '../zhuanpan/admin.php',
            'badge' => null,
            'group' => '互动与通知',
        ],
        [
            'id' => 'comments',
            'title' => '评论管理',
            'desc' => '审核、屏蔽与评论设置',
            'href' => $prefix . 'comments.php',
            'badge' => (int)($stats['comments'] ?? 0) ?: null,
            'group' => '互动与通知',
        ],
        [
            'id' => 'notifications',
            'title' => '通知管理',
            'desc' => '站内通知发布与管理',
            'href' => $prefix . 'notifications.php',
            'badge' => (int)($stats['notifications'] ?? 0) ?: null,
            'group' => '互动与通知',
        ],
        [
            'id' => 'feedback',
            'title' => '意见反馈',
            'desc' => '用户反馈处理与回复',
            'href' => $prefix . 'feedback.php',
            'badge' => (int)($stats['feedback_open'] ?? 0) ?: null,
            'group' => '互动与通知',
        ],
        [
            'id' => 'mail',
            'title' => '邮件管理',
            'desc' => 'SMTP、广播与指定通知',
            'href' => $prefix . 'mail.php',
            'badge' => null,
            'group' => '系统配置',
        ],
        [
            'id' => 'other_settings',
            'title' => '其它设置',
            'desc' => '注册、主题、播放器与统计',
            'href' => $prefix . 'other_settings.php',
            'badge' => null,
            'group' => '系统配置',
        ],
    ];
}
