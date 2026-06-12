<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, user-scalable=yes">
    <title>安装向导 | 数据库配置</title>
    <!-- Google Fonts & Tailwind + 自定义微调 -->
    <script src="https://css.qinlinyun.cn/uploads/css/68cd3c66163ff-20260129212429-5fce6eb9.css?v=20260129212429"></script>
    <link href="https://fonts.googleapis.com/css2?family=Inter:opsz,wght@14..32,300;14..32,400;14..32,500;14..32,600;14..32,700&display=swap" rel="stylesheet">
    <style>
        * { font-family: 'Inter', system-ui, -apple-system, BlinkMacSystemFont, 'Segoe UI', sans-serif; }
        body {
            background: linear-gradient(135deg, #f5f7fc 0%, #eef2f8 100%);
        }
        .card-shadow {
            box-shadow: 0 20px 35px -12px rgba(0, 0, 0, 0.08), 0 1px 2px rgba(0,0,0,0.02);
        }
        .step-circle {
            transition: all 0.2s ease;
        }
        input, select, button {
            transition: all 0.2s ease;
        }
        .focus-ring:focus {
            outline: none;
            ring: 2px solid #ef4444;
            ring-offset: 2px;
        }
        code.inline-code {
            background-color: #f3f4f6;
            padding: 0.2rem 0.4rem;
            border-radius: 0.375rem;
            font-size: 0.75rem;
            font-family: monospace;
            color: #dc2626;
        }
        .badge-soft {
            background-color: rgba(239, 68, 68, 0.08);
            color: #b91c1c;
        }
        .transition-smooth {
            transition-property: all;
            transition-timing-function: cubic-bezier(0.2, 0, 0, 1);
            transition-duration: 0.2s;
        }
    </style>
</head>
<body class="antialiased">

<?php
require_once __DIR__ . '/includes/install_guard.php';

$db_file = __DIR__ . '/.installed';
if (file_exists($db_file) && isSiteInstalled()) {
    die('<div class="min-h-screen flex items-center justify-center"><div class="bg-white rounded-2xl shadow-xl p-8 max-w-md text-center"><div class="mx-auto w-12 h-12 rounded-full bg-red-100 flex items-center justify-center mb-4"><svg class="w-6 h-6 text-red-600" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 9v2m0 4h.01m-6.938 4h13.856c1.54 0 2.502-1.667 1.732-3L13.732 4c-.77-1.333-2.694-1.333-3.464 0L3.34 16c-.77 1.333.192 3 1.732 3z"/></svg></div><h2 class="text-xl font-semibold text-gray-800">系统已安装</h2><p class="text-gray-500 mt-2 text-sm">如需重新安装请删除 <code class="bg-gray-100 px-1.5 py-0.5 rounded text-red-600">.installed</code> 文件</p></div></div>');
}

$message = '';
$error = '';
$testReport = null;
$redisTestReport = null;

/** 后续所有函数保持不变，保持安装逻辑完整性 (原样保留 function 区域) **/
function install_connect(string $host, string $dbname, string $username, string $password): PDO
{
    $pdo = new PDO(
        'mysql:host=' . $host . ';dbname=' . $dbname . ';charset=utf8mb4',
        $username,
        $password,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
    return $pdo;
}

function install_table_exists(PDO $pdo, string $table): bool
{
    $st = $pdo->prepare('SHOW TABLES LIKE ?');
    $st->execute([$table]);
    return (bool)$st->fetch();
}

function install_column_exists(PDO $pdo, string $table, string $column): bool
{
    if (!install_table_exists($pdo, $table)) {
        return false;
    }
    $st = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
    return (bool)$st->fetch();
}

function install_create_tables(PDO $pdo): void
{
    $pdo->exec("CREATE TABLE IF NOT EXISTS `users` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `username` varchar(50) NOT NULL UNIQUE,
            `display_name` varchar(80) DEFAULT NULL,
            `email` varchar(100) NOT NULL UNIQUE,
            `password` varchar(255) NOT NULL,
            `group_id` int(11) NOT NULL DEFAULT 1,
            `status` enum('active','banned','frozen') NOT NULL DEFAULT 'active',
            `ban_until` datetime DEFAULT NULL,
            `register_ip` varchar(45) DEFAULT NULL,
            `register_device` varchar(255) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `group_id` (`group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `user_groups` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(50) NOT NULL UNIQUE,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `server_groups` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `name` varchar(100) NOT NULL UNIQUE,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `domains` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `domain` varchar(255) NOT NULL,
            `display_name` varchar(100) DEFAULT NULL,
            `server_group_id` int(11) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `server_group_id` (`server_group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `email_verifications` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `email` varchar(100) NOT NULL,
            `code` varchar(6) NOT NULL,
            `expires_at` datetime NOT NULL,
            `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_email` (`email`),
            KEY `idx_expires` (`expires_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `group_domains` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `group_id` int(11) NOT NULL,
            `domain_id` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `group_domain` (`group_id`,`domain_id`),
            KEY `group_id` (`group_id`),
            KEY `domain_id` (`domain_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `group_server_groups` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `group_id` int(11) NOT NULL,
            `server_group_id` int(11) NOT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_group_server_group` (`group_id`,`server_group_id`),
            KEY `group_id` (`group_id`),
            KEY `server_group_id` (`server_group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        $pdo->exec("CREATE TABLE IF NOT EXISTS `videos` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `title` varchar(255) NOT NULL,
            `description` text,
            `cover` varchar(255) DEFAULT NULL,
            `server_group_id` int(11) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_videos_server_group` (`server_group_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `video_episodes` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `video_id` int(11) NOT NULL,
            `episode_name` varchar(255) NOT NULL,
            `video_url` text NOT NULL,
            `episode_order` int(11) NOT NULL DEFAULT 0,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `video_id` (`video_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `video_progress` (
            `id` int(11) NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `video_id` int(11) NOT NULL,
            `episode_id` int(11) NOT NULL,
            `progress_seconds` int(11) NOT NULL DEFAULT 0,
            `updated_at` timestamp NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_user_video_episode` (`user_id`,`video_id`,`episode_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `video_watch_progress` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `user_id` int(11) NOT NULL,
            `video_id` int(11) NOT NULL,
            `episode_id` int(11) NOT NULL,
            `progress_seconds` int unsigned NOT NULL DEFAULT 0,
            `duration_seconds` int unsigned NOT NULL DEFAULT 0,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_user_video_ep` (`user_id`,`video_id`,`episode_id`),
            KEY `idx_video_ep` (`video_id`,`episode_id`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `watch_progress_events` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `actor_user_id` int(11) DEFAULT NULL,
            `target_user_id` int(11) DEFAULT NULL,
            `action` varchar(50) NOT NULL,
            `video_id` int(11) DEFAULT NULL,
            `episode_id` int(11) DEFAULT NULL,
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_target_user` (`target_user_id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

    $pdo->exec("CREATE TABLE IF NOT EXISTS `notification_reads` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `notification_id` bigint unsigned NOT NULL,
            `user_id` int(11) NOT NULL,
            `read_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_read` (`notification_id`,`user_id`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

    $pdo->exec("CREATE TABLE IF NOT EXISTS `video_comments` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `video_id` int(11) NOT NULL,
            `user_id` int(11) NOT NULL,
            `parent_id` bigint unsigned DEFAULT NULL,
            `content` varchar(1000) NOT NULL,
            `status` enum('visible','hidden') NOT NULL DEFAULT 'visible',
            `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            KEY `idx_video_status` (`video_id`, `status`),
            KEY `idx_user` (`user_id`),
            KEY `idx_parent` (`parent_id`),
            KEY `idx_created` (`created_at`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

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

    $pdo->exec("CREATE TABLE IF NOT EXISTS `feedback_reply_reads` (
            `id` bigint unsigned NOT NULL AUTO_INCREMENT,
            `reply_id` bigint unsigned NOT NULL,
            `user_id` int(11) NOT NULL,
            `read_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (`id`),
            UNIQUE KEY `uniq_reply_user` (`reply_id`,`user_id`),
            KEY `idx_user` (`user_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function install_migrate_columns(PDO $pdo): void
{
    if (install_table_exists($pdo, 'users')) {
        if (!install_column_exists($pdo, 'users', 'display_name')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN display_name varchar(80) DEFAULT NULL AFTER username');
        }
        if (!install_column_exists($pdo, 'users', 'register_ip')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN register_ip varchar(45) DEFAULT NULL AFTER ban_until');
        }
        if (!install_column_exists($pdo, 'users', 'register_device')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN register_device varchar(255) DEFAULT NULL AFTER register_ip');
        }
        if (!install_column_exists($pdo, 'users', 'avatar')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN avatar varchar(255) DEFAULT NULL AFTER register_device');
        }
    }
    if (install_table_exists($pdo, 'domains')) {
        if (!install_column_exists($pdo, 'domains', 'display_name')) {
            $pdo->exec('ALTER TABLE domains ADD COLUMN display_name varchar(100) DEFAULT NULL AFTER domain');
        }
        if (!install_column_exists($pdo, 'domains', 'server_group_id')) {
            $pdo->exec('ALTER TABLE domains ADD COLUMN server_group_id int(11) DEFAULT NULL AFTER display_name, ADD KEY `idx_server_group_id` (`server_group_id`)');
        }
    }
    if (install_table_exists($pdo, 'feedbacks')) {
        if (!install_column_exists($pdo, 'feedbacks', 'user_last_read_at')) {
            $pdo->exec('ALTER TABLE feedbacks ADD COLUMN user_last_read_at datetime DEFAULT NULL AFTER status');
        }
        if (!install_column_exists($pdo, 'feedbacks', 'admin_last_read_at')) {
            $pdo->exec('ALTER TABLE feedbacks ADD COLUMN admin_last_read_at datetime DEFAULT NULL AFTER user_last_read_at');
        }
    }
    if (install_table_exists($pdo, 'videos') && !install_column_exists($pdo, 'videos', 'server_group_id')) {
        $pdo->exec('ALTER TABLE videos ADD COLUMN server_group_id int(11) DEFAULT NULL AFTER cover, ADD KEY `idx_videos_server_group` (`server_group_id`)');
    }
    if (install_table_exists($pdo, 'videos') && !install_column_exists($pdo, 'videos', 'uploaded_by')) {
        $pdo->exec('ALTER TABLE videos ADD COLUMN uploaded_by int(11) DEFAULT NULL AFTER server_group_id, ADD KEY `idx_videos_uploaded_by` (`uploaded_by`)');
    }
    if (install_table_exists($pdo, 'user_groups')) {
        if (!install_column_exists($pdo, 'user_groups', 'default_traffic')) {
            $pdo->exec('ALTER TABLE user_groups ADD COLUMN default_traffic int(11) NOT NULL DEFAULT 0');
        }
        if (!install_column_exists($pdo, 'user_groups', 'traffic_validity_days')) {
            $pdo->exec('ALTER TABLE user_groups ADD COLUMN traffic_validity_days int(11) NOT NULL DEFAULT 0');
        }
        if (!install_column_exists($pdo, 'user_groups', 'auto_reset_days')) {
            $pdo->exec('ALTER TABLE user_groups ADD COLUMN auto_reset_days int(11) NOT NULL DEFAULT 0');
        }
    }
    if (install_table_exists($pdo, 'users')) {
        if (!install_column_exists($pdo, 'users', 'traffic_total')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN traffic_total int(11) NOT NULL DEFAULT 0');
        }
        if (!install_column_exists($pdo, 'users', 'traffic_used')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN traffic_used int(11) NOT NULL DEFAULT 0');
        }
        if (!install_column_exists($pdo, 'users', 'traffic_expires_at')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN traffic_expires_at datetime DEFAULT NULL');
        }
        if (!install_column_exists($pdo, 'users', 'auto_reset_days')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN auto_reset_days int(11) NOT NULL DEFAULT 0');
        }
        if (!install_column_exists($pdo, 'users', 'traffic_last_reset_at')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN traffic_last_reset_at datetime DEFAULT NULL');
        }
        if (!install_column_exists($pdo, 'users', 'traffic_earnings_total')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN traffic_earnings_total int(11) NOT NULL DEFAULT 0');
        }
        if (!install_column_exists($pdo, 'users', 'traffic_earnings_frozen')) {
            $pdo->exec('ALTER TABLE users ADD COLUMN traffic_earnings_frozen int(11) NOT NULL DEFAULT 0');
        }
    }
    if (install_table_exists($pdo, 'videos')) {
        if (!install_column_exists($pdo, 'videos', 'is_traffic')) {
            $pdo->exec('ALTER TABLE videos ADD COLUMN is_traffic tinyint(1) NOT NULL DEFAULT 0');
        }
        if (!install_column_exists($pdo, 'videos', 'traffic_cost')) {
            $pdo->exec('ALTER TABLE videos ADD COLUMN traffic_cost int(11) NOT NULL DEFAULT 0');
        }
        if (!install_column_exists($pdo, 'videos', 'unlock_validity_minutes')) {
            $pdo->exec('ALTER TABLE videos ADD COLUMN unlock_validity_minutes int(11) NOT NULL DEFAULT 0');
        }
        if (!install_column_exists($pdo, 'videos', 'refresh_limit')) {
            $pdo->exec('ALTER TABLE videos ADD COLUMN refresh_limit int(11) NOT NULL DEFAULT 0');
        }
    }

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

    $pdo->exec("CREATE TABLE IF NOT EXISTS `site_settings` (
        `setting_key` varchar(100) NOT NULL,
        `setting_value` text NOT NULL,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function install_seed_if_empty(PDO $pdo): void
{
    $n = (int)$pdo->query('SELECT COUNT(*) FROM user_groups')->fetchColumn();
    if ($n === 0) {
        $pdo->exec("INSERT INTO `user_groups` (`name`) VALUES ('注册用户组')");
    }
    $n = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
    if ($n === 0) {
        $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
        $stmt = $pdo->prepare('INSERT INTO `users` (`username`, `email`, `password`, `group_id`) VALUES (?,?,?,1)');
        $stmt->execute(['admin', 'admin@example.com', $adminPassword]);
    }
}

function install_seed_fresh(PDO $pdo): void
{
    $pdo->exec("INSERT INTO `user_groups` (`name`) VALUES ('注册用户组')");
    $adminPassword = password_hash('admin123', PASSWORD_DEFAULT);
    $pdo->exec("INSERT INTO `users` (`username`, `email`, `password`, `group_id`) 
               VALUES ('admin', 'admin@example.com', " . $pdo->quote($adminPassword) . ', 1)');
}

function install_write_config(string $host, string $dbname, string $username, string $password): void
{
    $config = "<?php\n";
    $config .= "define('DB_HOST', " . var_export($host, true) . ");\n";
    $config .= "define('DB_NAME', " . var_export($dbname, true) . ");\n";
    $config .= "define('DB_USER', " . var_export($username, true) . ");\n";
    $config .= "define('DB_PASS', " . var_export($password, true) . ");\n";
    $config .= "define('DB_CHARSET', 'utf8mb4');\n";
    file_put_contents(__DIR__ . '/config/database_config.php', $config);
}

function install_write_redis_from_post(array $post): void
{
    require_once __DIR__ . '/includes/redis_config.php';
    writeRedisConfigFile([
        'enabled' => isset($post['redis_enabled']),
        'host' => trim((string)($post['redis_host'] ?? '127.0.0.1')),
        'port' => (int)($post['redis_port'] ?? 6379),
        'password' => (string)($post['redis_password'] ?? ''),
        'database' => (int)($post['redis_database'] ?? 0),
        'prefix' => trim((string)($post['redis_prefix'] ?? 'phpy:')),
        'publish_throttle_sec' => (int)($post['redis_publish_throttle_sec'] ?? 15),
    ]);
}

function install_expected_tables(): array
{
    return [
        'users', 'user_groups', 'server_groups', 'domains', 'email_verifications',
        'group_domains', 'group_server_groups', 'videos', 'video_episodes',
        'video_progress', 'video_watch_progress', 'watch_progress_events',
        'notifications', 'notification_reads', 'feedbacks', 'feedback_replies',
        'feedback_reply_reads', 'video_comments', 'video_unlocks', 'traffic_logs', 'site_settings',
    ];
}

function install_detect_existing(PDO $pdo): array
{
    $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $existingMap = array_fill_keys($existing, true);
    $expected = install_expected_tables();
    $missing = array_values(array_filter($expected, static fn($t) => !isset($existingMap[$t])));

    $dataSummary = [];
    foreach (['users', 'videos', 'video_episodes', 'domains'] as $table) {
        if (isset($existingMap[$table])) {
            $dataSummary[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        }
    }

    $userRows = $dataSummary['users'] ?? null;
    $hasDataRows = false;
    foreach ($dataSummary as $cnt) {
        if ($cnt > 0) {
            $hasDataRows = true;
            break;
        }
    }

    return [
        'table_count' => count($existing),
        'expected_count' => count($expected),
        'missing_tables' => $missing,
        'missing_count' => count($missing),
        'data_summary' => $dataSummary,
        'user_rows' => $userRows,
        'has_structure' => count($existing) > 0,
        'has_expected_structure' => count($missing) < count($expected),
        'has_user_data' => $userRows !== null && $userRows > 0,
        'has_data' => $hasDataRows,
        'tables_sample' => array_slice($existing, 0, 15),
    ];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $host = trim($_POST['host'] ?? 'localhost');
    $dbname = trim($_POST['dbname'] ?? 'video_system');
    $username = trim($_POST['username'] ?? 'root');
    $password = $_POST['password'] ?? '';

    if ($action === 'test_redis') {
        require_once __DIR__ . '/includes/redis_config.php';
        $redisTestReport = testRedisConnection([
            'enabled' => true,
            'host' => trim((string)($_POST['redis_host'] ?? '127.0.0.1')),
            'port' => (int)($_POST['redis_port'] ?? 6379),
            'password' => (string)($_POST['redis_password'] ?? ''),
            'database' => (int)($_POST['redis_database'] ?? 0),
        ]);
    }

    if ($action === 'test') {
        // 测试逻辑不变
        try {
            $pdoRoot = new PDO('mysql:host=' . $host . ';charset=utf8mb4', $username, $password);
            $pdoRoot->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $version = $pdoRoot->query('SELECT VERSION()')->fetchColumn();
            $dbs = $pdoRoot->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
            $dbExists = in_array($dbname, $dbs, true);
            $testReport = ['ok' => true, 'version' => $version, 'db_exists' => $dbExists, 'table_count' => 0, 'expected_count' => count(install_expected_tables()), 'missing_tables' => install_expected_tables(), 'missing_count' => count(install_expected_tables()), 'data_summary' => [], 'user_rows' => null, 'has_structure' => false, 'has_data' => false, 'has_user_data' => false, 'tables_sample' => []];
            if ($dbExists) {
                $pdoDb = install_connect($host, $dbname, $username, $password);
                $info = install_detect_existing($pdoDb);
                $testReport = array_merge($testReport, $info);
                $testReport['ok'] = true;
                $testReport['version'] = $version;
                $testReport['db_exists'] = true;
            }
        } catch (PDOException $e) {
            $testReport = ['ok' => false, 'error' => $e->getMessage()];
        }
    }

    if ($action === 'install') {
        $installMode = $_POST['install_mode'] ?? '';
        try {
            $pdoRoot = new PDO('mysql:host=' . $host . ';charset=utf8mb4', $username, $password);
            $pdoRoot->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
            $pdoRoot->exec('CREATE DATABASE IF NOT EXISTS `' . str_replace('`', '``', $dbname) . '` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
            $pdo = install_connect($host, $dbname, $username, $password);
            $before = install_detect_existing($pdo);
            $hasExisting = $before['has_structure'];

            if ($hasExisting && !in_array($installMode, ['fresh', 'migrate'], true)) {
                $error = '检测到该库中已有数据表，请选择「覆盖安装」或「补充安装」后再继续。建议先点击「测试数据库连通性」查看详情。';
            } else {
                if ($hasExisting && $installMode === 'fresh') {
                    $quotedDb = '`' . str_replace('`', '``', $dbname) . '`';
                    $pdoRoot->exec('DROP DATABASE ' . $quotedDb);
                    $pdoRoot->exec('CREATE DATABASE ' . $quotedDb . ' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
                    $pdo = install_connect($host, $dbname, $username, $password);
                    $hasExisting = false;
                }
                install_create_tables($pdo);
                if ($installMode === 'migrate') {
                    install_migrate_columns($pdo);
                    install_seed_if_empty($pdo);
                } else {
                    install_migrate_columns($pdo);
                    install_seed_fresh($pdo);
                }
                install_write_config($host, $dbname, $username, $password);
                try {
                    install_write_redis_from_post($_POST);
                } catch (Throwable $redisEx) { $message .= ' Redis 配置未写入（' . $redisEx->getMessage() . '），可稍后在「其它设置 → Redis 配置」中填写。'; }
                file_put_contents($db_file, date('Y-m-d H:i:s'));
                if ($installMode === 'migrate') $message = '补充安装完成：已保留原有数据，并补齐缺失的表与字段。';
                elseif ($installMode === 'fresh' && $before['has_structure']) $message = '覆盖安装完成！数据库已清空并重建。默认管理员账号：admin，密码：admin123';
                else $message = '安装成功！默认管理员账号：admin，密码：admin123';
            }
        } catch (PDOException $e) { $error = '安装失败：' . $e->getMessage(); }
    }
}

$testOk = $testReport && !empty($testReport['ok']);
$dbExists = $testOk && !empty($testReport['db_exists']);
$needsChoice = $testOk && $dbExists && (!empty($testReport['has_structure']) || (int)($testReport['missing_count'] ?? 0) < (int)($testReport['expected_count'] ?? 0));
$tested = $testReport !== null;
?>

<div class="min-h-screen flex items-center justify-center px-4 py-10">
    <div class="w-full max-w-2xl bg-white/90 backdrop-blur-sm rounded-2xl card-shadow transition-all">
        <div class="p-6 md:p-8">
            <!-- 头部标识 -->
            <div class="text-center mb-6">
                <div class="inline-flex h-12 w-12 items-center justify-center rounded-full bg-red-50 text-red-600 mb-3">
                    <svg class="h-6 w-6" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.8" d="M4 7v10c0 2.21 3.582 4 8 4s8-1.79 8-4V7M4 7c0 2.21 3.582 4 8 4s8-1.79 8-4M4 7c0-2.21 3.582-4 8-4s8 1.79 8 4"/></svg>
                </div>
                <h2 class="text-xl font-bold tracking-tight text-gray-800">数据库安装向导</h2>
                <p class="text-xs text-gray-500 mt-1">配置 MySQL 连接，完成系统初始化</p>
            </div>

            <?php if ($message): ?>
                <div class="mb-6 rounded-xl border border-emerald-200 bg-emerald-50/80 px-4 py-3 flex items-start gap-2 text-emerald-800">
                    <svg class="w-5 h-5 shrink-0 mt-0.5" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4m6 2a9 9 0 11-18 0 9 9 0 0118 0z" /></svg>
                    <span class="text-sm"><?php echo htmlspecialchars($message); ?></span>
                </div>
                <div class="text-center"><a href="index.php" class="inline-flex items-center gap-1 text-sm font-medium text-red-600 hover:text-red-800 transition">前往首页 →</a></div>
            <?php else: ?>
                <?php if ($error): ?>
                    <div class="mb-5 rounded-xl border border-rose-200 bg-rose-50 px-4 py-3 text-sm text-rose-700 flex gap-2">⚠️ <?php echo htmlspecialchars($error); ?></div>
                <?php endif; ?>
                
                <!-- 步骤条 -->
                <div class="mb-7 flex items-center justify-between">
                    <div class="flex flex-col items-center gap-1 flex-1"><div class="step-circle w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold <?php echo !$tested ? 'bg-red-600 text-white shadow' : ($testOk ? 'bg-green-500 text-white' : 'bg-red-600 text-white'); ?>">1</div><span class="text-[11px] text-gray-500 mt-1">连接信息</span></div>
                    <div class="h-px flex-1 bg-gray-200 mx-2"></div>
                    <div class="flex flex-col items-center gap-1 flex-1"><div class="step-circle w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold <?php echo $testOk ? 'bg-green-500 text-white' : 'bg-gray-200 text-gray-500'; ?>">2</div><span class="text-[11px] text-gray-500">连通测试</span></div>
                    <div class="h-px flex-1 bg-gray-200 mx-2"></div>
                    <div class="flex flex-col items-center gap-1 flex-1"><div class="step-circle w-8 h-8 rounded-full flex items-center justify-center text-sm font-semibold <?php echo $testOk ? 'bg-red-600 text-white' : 'bg-gray-200 text-gray-500'; ?>">3</div><span class="text-[11px] text-gray-500">执行安装</span></div>
                </div>
                
                <!-- 测试结果显示区域 简洁美观 -->
                <?php if ($tested && $testOk): ?>
                <div class="mb-6 rounded-xl border <?php echo $dbExists ? 'border-amber-200 bg-amber-50/60' : 'border-green-200 bg-green-50/60'; ?> px-4 py-3 text-sm">
                    <div class="flex items-center gap-2 font-medium <?php echo $dbExists ? 'text-amber-800' : 'text-green-800'; ?>">
                        <span>✅</span> 数据库连通成功 · MySQL <?php echo htmlspecialchars((string)$testReport['version']); ?>
                    </div>
                    <?php if ($dbExists && $testReport['has_structure']): ?>
                        <div class="mt-2 text-xs text-gray-700 space-y-1">
                            <p>📋 已存在 <strong class="font-semibold"><?php echo (int)$testReport['table_count']; ?></strong> 张表，缺失 <strong><?php echo (int)$testReport['missing_count']; ?></strong> 张系统表。</p>
                            <?php if (!empty($testReport['data_summary'])): ?><p class="text-gray-600">📊 数据快照：<?php foreach($testReport['data_summary'] as $k=>$v){echo "{$k}:{$v} "; } ?></p><?php endif; ?>
                        </div>
                    <?php elseif($dbExists && !$testReport['has_structure']): ?>
                        <p class="text-xs text-green-700 mt-1">📁 数据库已存在但无业务表，可直接执行全新安装。</p>
                    <?php elseif(!$dbExists): ?>
                        <p class="text-xs text-green-700 mt-1">✨ 数据库即将自动创建，安装后生成所有表结构。</p>
                    <?php endif; ?>
                </div>
                <?php elseif($tested && !$testOk): ?>
                    <div class="mb-6 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700 flex items-start gap-2">❌ <?php echo htmlspecialchars($testReport['error'] ?? '未知错误'); ?></div>
                <?php endif; ?>
                
                <form method="POST" class="space-y-5" id="dbform">
                    <div class="grid grid-cols-1 gap-4 sm:grid-cols-2">
                        <div><label class="block text-xs font-medium text-gray-600 mb-1">数据库主机</label><input type="text" name="host" value="<?php echo htmlspecialchars($_POST['host'] ?? 'localhost'); ?>" class="w-full rounded-xl border border-gray-200 bg-gray-50/50 px-4 py-2.5 text-sm focus:border-red-400 focus:ring-1 focus:ring-red-200 transition"></div>
                        <div><label class="block text-xs font-medium text-gray-600 mb-1">数据库名称</label><input type="text" name="dbname" value="<?php echo htmlspecialchars($_POST['dbname'] ?? 'video_system'); ?>" class="w-full rounded-xl border border-gray-200 bg-gray-50/50 px-4 py-2.5 text-sm focus:border-red-400"></div>
                        <div><label class="block text-xs font-medium text-gray-600 mb-1">用户名</label><input type="text" name="username" value="<?php echo htmlspecialchars($_POST['username'] ?? 'root'); ?>" class="w-full rounded-xl border border-gray-200 bg-gray-50/50 px-4 py-2.5 text-sm"></div>
                        <div><label class="block text-xs font-medium text-gray-600 mb-1">密码</label><input type="password" name="password" value="<?php echo htmlspecialchars($_POST['password'] ?? ''); ?>" class="w-full rounded-xl border border-gray-200 bg-gray-50/50 px-4 py-2.5 text-sm" autocomplete="off"></div>
                    </div>
                    
                    <!-- Redis 折叠卡片简化美观 -->
                    <div class="border border-gray-100 rounded-xl bg-gray-50/40 p-4 space-y-3">
                        <div class="flex items-center justify-between"><div><p class="text-sm font-medium">⚡ Redis 增强（可选）</p><p class="text-[11px] text-gray-500">播放进度、缓存队列支持</p></div><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="redis_enabled" value="1" <?php echo !empty($_POST['redis_enabled']) ? 'checked' : ''; ?> class="rounded border-gray-300 text-red-500 focus:ring-red-300"> 启用</label></div>
                        <div class="grid gap-3 sm:grid-cols-2">
                            <input type="text" name="redis_host" placeholder="主机" value="<?php echo htmlspecialchars($_POST['redis_host'] ?? '127.0.0.1'); ?>" class="rounded-lg border-gray-200 bg-white text-sm px-3 py-2">
                            <input type="number" name="redis_port" placeholder="端口" value="<?php echo (int)($_POST['redis_port'] ?? 6379); ?>" class="rounded-lg border-gray-200 bg-white text-sm px-3 py-2">
                            <input type="password" name="redis_password" placeholder="密码(可选)" value="<?php echo htmlspecialchars($_POST['redis_password'] ?? ''); ?>" class="rounded-lg border-gray-200 bg-white text-sm px-3 py-2">
                            <div class="flex gap-2"><input type="number" name="redis_database" placeholder="库编号" value="<?php echo (int)($_POST['redis_database'] ?? 0); ?>" class="w-24 rounded-lg border-gray-200"><input type="text" name="redis_prefix" placeholder="前缀" value="<?php echo htmlspecialchars($_POST['redis_prefix'] ?? 'phpy'); ?>" class="flex-1 rounded-lg border-gray-200"></div>
                        </div>
                        <?php if ($redisTestReport): ?><div class="text-xs rounded-lg p-2 <?php echo !empty($redisTestReport['ok']) ? 'bg-green-100 text-green-800' : 'bg-rose-100 text-rose-800'; ?>"><?php echo htmlspecialchars($redisTestReport['message'] ?? ($redisTestReport['ok'] ? 'Redis连接成功' : '连接失败')); ?></div><?php endif; ?>
                        <button type="submit" name="action" value="test_redis" class="text-xs bg-white border border-gray-200 hover:bg-gray-100 rounded-lg px-3 py-1.5 transition">测试 Redis</button>
                    </div>
                    
                    <div class="flex flex-col gap-3 pt-2">
                        <?php if (!$testOk): ?>
                            <button type="submit" name="action" value="test" class="w-full bg-indigo-600 hover:bg-indigo-700 text-white font-semibold py-2.5 rounded-xl shadow-sm transition">🔌 测试数据库连通性</button>
                        <?php else: ?>
                            <button type="submit" name="action" value="test" class="w-full bg-white border border-gray-300 text-gray-700 hover:bg-gray-50 py-2.5 rounded-xl text-sm">↻ 重新测试 / 修改信息</button>
                        <?php endif; ?>
                        
                        <?php if ($testOk): ?>
                            <?php if (!$needsChoice): ?>
                                <div class="bg-gray-50/80 rounded-xl p-4 text-center"><p class="text-sm font-medium text-gray-700">✨ 全新安装环境</p><p class="text-xs text-gray-500 mt-1">将创建所有数据表，并初始化管理员账号 <span class="font-mono text-red-600">admin / admin123</span></p></div>
                                <button type="submit" name="action" value="install" onclick="this.form.querySelector('[name=install_mode]').value='fresh'" class="w-full bg-red-600 hover:bg-red-700 text-white font-semibold py-3 rounded-xl transition shadow-md">开始全新安装</button>
                                <input type="hidden" name="install_mode" value="fresh">
                            <?php else: ?>
                                <div class="space-y-3 mt-1">
                                    <p class="text-sm font-medium text-gray-700">检测到已有数据，选择安装策略：</p>
                                    <button type="submit" name="action" value="install" onclick="this.form.querySelector('[name=install_mode]').value='migrate'" class="w-full rounded-xl border-2 border-emerald-500 bg-emerald-50 p-4 text-left hover:bg-emerald-100 transition group"><div class="flex items-center gap-3"><div class="rounded-full bg-emerald-500 w-7 h-7 flex items-center justify-center text-white text-sm">✓</div><div><span class="font-semibold text-emerald-800">补充安装（推荐）</span><p class="text-xs text-emerald-700 mt-0.5">保留数据，只补齐缺失的表/字段，平滑升级</p></div></div></button>
                                    <button type="submit" name="action" value="install" onclick="if(!confirm('⚠️ 覆盖安装将清空整个数据库，所有现存数据将永久丢失！\n确定继续吗？')){event.preventDefault();return false;} this.form.querySelector('[name=install_mode]').value='fresh'" class="w-full rounded-xl border-2 border-red-300 bg-white p-4 text-left hover:border-red-500 hover:bg-red-50 transition"><div class="flex items-center gap-3"><div class="rounded-full bg-red-500 w-7 h-7 flex items-center justify-center text-white text-sm">🗑️</div><div><span class="font-semibold text-red-700">覆盖安装</span><p class="text-xs text-red-600">清空数据库完全重建，回到初始状态</p></div></div></button>
                                    <input type="hidden" name="install_mode" value="">
                                </div>
                            <?php endif; ?>
                        <?php endif; ?>
                    </div>
                </form>
            <?php endif; ?>
        </div>
        <div class="border-t border-gray-100 px-6 py-3 bg-gray-50/30 rounded-b-2xl text-center text-[11px] text-gray-400">安装过程不会影响已有配置 · 请确保数据库权限足够</div>
    </div>
</div>
</body>
</html>