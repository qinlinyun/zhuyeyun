<?php
// 数据库更新脚本 - 为已安装的系统添加新字段
require_once __DIR__ . '/config/database.php';

try {
    $pdo = getDB();
    
    // 检查并添加用户表的注册信息字段
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'display_name'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN display_name varchar(80) DEFAULT NULL AFTER username");
        echo "已添加 display_name 字段<br>";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'register_ip'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN register_ip varchar(45) DEFAULT NULL AFTER ban_until");
        echo "已添加 register_ip 字段<br>";
    }
    
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'register_device'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN register_device varchar(255) DEFAULT NULL AFTER register_ip");
        echo "已添加 register_device 字段<br>";
    }
    
    // 检查并添加域名表的显示名称字段
    $stmt = $pdo->query("SHOW COLUMNS FROM domains LIKE 'display_name'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE domains ADD COLUMN display_name varchar(100) DEFAULT NULL AFTER domain");
        echo "已添加 display_name 字段<br>";
    }

    // 创建站内通知表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `notifications` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `title` varchar(200) NOT NULL,
        `content` text NOT NULL,
        `target_type` enum('all','user') NOT NULL DEFAULT 'all',
        `target_user_id` int(11) DEFAULT NULL,
        `created_by` int(11) NOT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_target_user` (`target_user_id`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "已检查 notifications 表<br>";

    // 创建通知已读表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `notification_reads` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `notification_id` bigint unsigned NOT NULL,
        `user_id` int(11) NOT NULL,
        `read_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_read` (`notification_id`,`user_id`),
        KEY `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "已检查 notification_reads 表<br>";

    // 创建通知已读表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `notification_reads` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `notification_id` bigint unsigned NOT NULL,
        `read_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_user_notice` (`user_id`,`notification_id`),
        KEY `idx_user` (`user_id`),
        KEY `idx_notice` (`notification_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "已检查 notification_reads 表<br>";

    // 创建意见反馈表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `feedbacks` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `title` varchar(200) DEFAULT NULL,
        `content` text NOT NULL,
        `image_path` varchar(255) DEFAULT NULL,
        `status` enum('open','replied','closed') NOT NULL DEFAULT 'open',
        `user_last_read_at` datetime DEFAULT NULL,
        `admin_last_read_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user` (`user_id`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "已检查 feedbacks 表<br>";

    $stmt = $pdo->query("SHOW COLUMNS FROM feedbacks LIKE 'user_last_read_at'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE feedbacks ADD COLUMN user_last_read_at datetime DEFAULT NULL AFTER status");
        echo "已添加 feedbacks.user_last_read_at 字段<br>";
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM feedbacks LIKE 'admin_last_read_at'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE feedbacks ADD COLUMN admin_last_read_at datetime DEFAULT NULL AFTER user_last_read_at");
        echo "已添加 feedbacks.admin_last_read_at 字段<br>";
    }

    // 创建意见回复表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `feedback_replies` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `feedback_id` bigint unsigned NOT NULL,
        `user_id` int(11) NOT NULL,
        `role` enum('user','admin') NOT NULL,
        `content` text NOT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_feedback` (`feedback_id`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "已检查 feedback_replies 表<br>";

    // 创建反馈回复已读表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `feedback_reply_reads` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `reply_id` bigint unsigned NOT NULL,
        `user_id` int(11) NOT NULL,
        `read_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_reply_user` (`reply_id`,`user_id`),
        KEY `idx_user` (`user_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "已检查 feedback_reply_reads 表<br>";

    // 服务器组（域名按线路/机房归类，用户组分配时可按组勾选）
    $pdo->exec("CREATE TABLE IF NOT EXISTS `server_groups` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `name` varchar(100) NOT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `name` (`name`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "已检查 server_groups 表<br>";

    $stmt = $pdo->query("SHOW COLUMNS FROM domains LIKE 'server_group_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE domains ADD COLUMN server_group_id int(11) DEFAULT NULL AFTER display_name, ADD KEY `idx_server_group_id` (`server_group_id`)");
        echo "已添加 domains.server_group_id 字段<br>";
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM videos LIKE 'server_group_id'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE videos ADD COLUMN server_group_id int(11) DEFAULT NULL AFTER cover, ADD KEY `idx_videos_server_group` (`server_group_id`)");
        echo "已添加 videos.server_group_id 字段<br>";
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `group_server_groups` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `group_id` int(11) NOT NULL,
        `server_group_id` int(11) NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_group_server_group` (`group_id`,`server_group_id`),
        KEY `group_id` (`group_id`),
        KEY `server_group_id` (`server_group_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "已检查 group_server_groups 表<br>";

    // ========== 流量限制功能 ==========
    // 用户组：默认流量、流量有效期（天）
    $stmt = $pdo->query("SHOW COLUMNS FROM user_groups LIKE 'default_traffic'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE user_groups ADD COLUMN default_traffic int(11) NOT NULL DEFAULT 0");
        echo "已添加 user_groups.default_traffic 字段<br>";
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM user_groups LIKE 'traffic_validity_days'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE user_groups ADD COLUMN traffic_validity_days int(11) NOT NULL DEFAULT 0");
        echo "已添加 user_groups.traffic_validity_days 字段<br>";
    }

    // 用户：总流量、已用流量、流量到期时间
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'traffic_total'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN traffic_total int(11) NOT NULL DEFAULT 0");
        echo "已添加 users.traffic_total 字段<br>";
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'traffic_used'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN traffic_used int(11) NOT NULL DEFAULT 0");
        echo "已添加 users.traffic_used 字段<br>";
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'traffic_expires_at'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN traffic_expires_at datetime DEFAULT NULL");
        echo "已添加 users.traffic_expires_at 字段<br>";
    }

    // 视频：是否流量视频、解锁所需流量、单次有效期、刷新次数
    $stmt = $pdo->query("SHOW COLUMNS FROM videos LIKE 'is_traffic'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE videos ADD COLUMN is_traffic tinyint(1) NOT NULL DEFAULT 0");
        echo "已添加 videos.is_traffic 字段<br>";
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM videos LIKE 'traffic_cost'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE videos ADD COLUMN traffic_cost int(11) NOT NULL DEFAULT 0");
        echo "已添加 videos.traffic_cost 字段<br>";
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM videos LIKE 'unlock_validity_minutes'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE videos ADD COLUMN unlock_validity_minutes int(11) NOT NULL DEFAULT 0");
        echo "已添加 videos.unlock_validity_minutes 字段<br>";
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM videos LIKE 'refresh_limit'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE videos ADD COLUMN refresh_limit int(11) NOT NULL DEFAULT 0");
        echo "已添加 videos.refresh_limit 字段<br>";
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM videos LIKE 'skip_backend_proxy'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE videos ADD COLUMN skip_backend_proxy tinyint(1) NOT NULL DEFAULT 0");
        echo "已添加 videos.skip_backend_proxy 字段<br>";
    }

    // 视频解锁记录表
    $pdo->exec("CREATE TABLE IF NOT EXISTS `video_unlocks` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `video_id` int(11) NOT NULL,
        `cost` int(11) NOT NULL DEFAULT 0,
        `expires_at` datetime DEFAULT NULL,
        `refresh_count` int(11) NOT NULL DEFAULT 0,
        `refresh_limit` int(11) NOT NULL DEFAULT 0,
        `validity_minutes` int(11) NOT NULL DEFAULT 0,
        `paid_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_user_video` (`user_id`,`video_id`),
        KEY `idx_user` (`user_id`),
        KEY `idx_video` (`video_id`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "已检查 video_unlocks 表<br>";

    // 自动重置周期 / 上次重置时间
    $stmt = $pdo->query("SHOW COLUMNS FROM user_groups LIKE 'auto_reset_days'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE user_groups ADD COLUMN auto_reset_days int(11) NOT NULL DEFAULT 0");
        echo "已添加 user_groups.auto_reset_days 字段<br>";
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'auto_reset_days'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN auto_reset_days int(11) NOT NULL DEFAULT 0");
        echo "已添加 users.auto_reset_days 字段<br>";
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'traffic_last_reset_at'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN traffic_last_reset_at datetime DEFAULT NULL");
        echo "已添加 users.traffic_last_reset_at 字段<br>";
    }

    // 流量变更日志表（管理员调整流量记录）
    $pdo->exec("CREATE TABLE IF NOT EXISTS `traffic_logs` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `action` varchar(40) NOT NULL,
        `change_amount` int(11) NOT NULL DEFAULT 0,
        `before_total` int(11) NOT NULL DEFAULT 0,
        `before_used` int(11) NOT NULL DEFAULT 0,
        `after_total` int(11) NOT NULL DEFAULT 0,
        `after_used` int(11) NOT NULL DEFAULT 0,
        `remark` varchar(255) DEFAULT NULL,
        `operator_id` int(11) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user` (`user_id`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "已检查 traffic_logs 表<br>";

    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'traffic_earnings_total'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN traffic_earnings_total int(11) NOT NULL DEFAULT 0");
        echo "已添加 users.traffic_earnings_total 字段<br>";
    }
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'traffic_earnings_frozen'");
    if (!$stmt->fetch()) {
        $pdo->exec("ALTER TABLE users ADD COLUMN traffic_earnings_frozen int(11) NOT NULL DEFAULT 0");
        echo "已添加 users.traffic_earnings_frozen 字段<br>";
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
    echo "已检查 traffic_earning_logs 表<br>";

    $pdo->exec("CREATE TABLE IF NOT EXISTS `site_settings` (
        `setting_key` varchar(100) NOT NULL,
        `setting_value` text NOT NULL,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
    echo "已检查 site_settings 表<br>";

    require_once __DIR__ . '/includes/analytics_schema.php';
    ensureAnalyticsTables($pdo);
    migrateAnalyticsFromJson($pdo);
    echo "已检查 analytics 数据表（IP访问 / 用户登录 / 视频点击）<br>";

    require_once __DIR__ . '/includes/announcement.php';
    ensureAnnouncementTables($pdo);
    echo "已检查 site_announcements / site_announcement_reads 表<br>";

    require_once __DIR__ . '/includes/user_profile.php';
    ensureUserProfileSchema($pdo);
    echo "已检查 users.avatar / videos.uploaded_by 字段<br>";

    require_once __DIR__ . '/includes/comments.php';
    ensureCommentsSchema($pdo);
    echo "已检查 video_comments 表<br>";

    require_once __DIR__ . '/includes/settings.php';
    if (getSetting($pdo, 'users_created_at_cn_migrated_v1', '') !== '1') {
        $pdo->exec("UPDATE users SET created_at = CONVERT_TZ(created_at, '+00:00', '+08:00') WHERE created_at IS NOT NULL");
        $pdo->exec("UPDATE users SET ban_until = CONVERT_TZ(ban_until, '+00:00', '+08:00') WHERE ban_until IS NOT NULL");
        setSetting($pdo, 'users_created_at_cn_migrated_v1', '1');
        echo "已将历史用户注册/封禁时间校正为中国时间（UTC+8）<br>";
    }

    echo "数据库更新完成！";
} catch(PDOException $e) {
    echo "更新失败：" . $e->getMessage();
}
?>

