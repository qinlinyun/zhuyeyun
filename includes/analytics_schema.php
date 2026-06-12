<?php

require_once __DIR__ . '/../config/database.php';

function ensureAnalyticsTables(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `analytics_ip_visits` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `ip` varchar(45) NOT NULL,
        `location` varchar(255) NOT NULL DEFAULT '未知',
        `username` varchar(50) DEFAULT NULL,
        `visits` int NOT NULL DEFAULT 0,
        `first_visit_at` datetime NOT NULL,
        `last_visit_at` datetime NOT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_ip` (`ip`),
        KEY `idx_visits` (`visits`),
        KEY `idx_last_visit` (`last_visit_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `analytics_user_logins` (
        `user_id` int NOT NULL,
        `username` varchar(50) NOT NULL,
        `email` varchar(100) NOT NULL DEFAULT '',
        `logins` int NOT NULL DEFAULT 0,
        `first_login_at` datetime NOT NULL,
        `last_login_at` datetime NOT NULL,
        PRIMARY KEY (`user_id`),
        KEY `idx_logins` (`logins`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `analytics_video_clicks` (
        `video_id` int NOT NULL,
        `clicks` int NOT NULL DEFAULT 0,
        `first_clicked_at` datetime NOT NULL,
        `last_clicked_at` datetime NOT NULL,
        PRIMARY KEY (`video_id`),
        KEY `idx_clicks` (`clicks`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $pdo->exec("CREATE TABLE IF NOT EXISTS `site_settings` (
        `setting_key` varchar(100) NOT NULL,
        `setting_value` text NOT NULL,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ready = true;
}

function analyticsJsonDataDir(): string
{
    return __DIR__ . '/../data/analytics';
}

function migrateAnalyticsFromJson(PDO $pdo): void
{
    static $migrated = false;
    if ($migrated) {
        return;
    }

    ensureAnalyticsTables($pdo);

    $flagKey = 'analytics_json_migrated_v1';
    $stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$flagKey]);
    if ($stmt->fetchColumn() === '1') {
        $migrated = true;
        return;
    }

    $dir = analyticsJsonDataDir();

    $ipPath = $dir . '/ip_visits.json';
    if (is_file($ipPath)) {
        $store = json_decode((string)file_get_contents($ipPath), true);
        if (is_array($store) && !empty($store['items']) && is_array($store['items'])) {
            $insert = $pdo->prepare("
                INSERT INTO analytics_ip_visits (ip, location, username, visits, first_visit_at, last_visit_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    location = VALUES(location),
                    username = COALESCE(VALUES(username), username),
                    visits = GREATEST(visits, VALUES(visits)),
                    first_visit_at = LEAST(first_visit_at, VALUES(first_visit_at)),
                    last_visit_at = GREATEST(last_visit_at, VALUES(last_visit_at))
            ");
            foreach ($store['items'] as $meta) {
                if (!is_array($meta)) {
                    continue;
                }
                $ip = trim((string)($meta['ip'] ?? ''));
                if ($ip === '') {
                    continue;
                }
                $first = (string)($meta['first_visit_at'] ?? date('Y-m-d H:i:s'));
                $last = (string)($meta['last_visit_at'] ?? $first);
                $insert->execute([
                    $ip,
                    (string)($meta['location'] ?? '未知'),
                    trim((string)($meta['username'] ?? '')) ?: null,
                    max(0, (int)($meta['visits'] ?? 0)),
                    $first,
                    $last,
                ]);
            }
        }
        @rename($ipPath, $ipPath . '.bak');
    }

    $loginPath = $dir . '/user_logins.json';
    if (is_file($loginPath)) {
        $store = json_decode((string)file_get_contents($loginPath), true);
        if (is_array($store) && !empty($store['items']) && is_array($store['items'])) {
            $insert = $pdo->prepare("
                INSERT INTO analytics_user_logins (user_id, username, email, logins, first_login_at, last_login_at)
                VALUES (?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    username = VALUES(username),
                    email = VALUES(email),
                    logins = GREATEST(logins, VALUES(logins)),
                    first_login_at = LEAST(first_login_at, VALUES(first_login_at)),
                    last_login_at = GREATEST(last_login_at, VALUES(last_login_at))
            ");
            foreach ($store['items'] as $userId => $meta) {
                if (!is_array($meta)) {
                    continue;
                }
                $id = (int)($meta['user_id'] ?? $userId);
                if ($id <= 0) {
                    continue;
                }
                $first = (string)($meta['first_login_at'] ?? date('Y-m-d H:i:s'));
                $last = (string)($meta['last_login_at'] ?? $first);
                $insert->execute([
                    $id,
                    (string)($meta['username'] ?? ''),
                    (string)($meta['email'] ?? ''),
                    max(0, (int)($meta['logins'] ?? 0)),
                    $first,
                    $last,
                ]);
            }
        }
        @rename($loginPath, $loginPath . '.bak');
    }

    $clickPath = $dir . '/video_clicks.json';
    if (is_file($clickPath)) {
        $store = json_decode((string)file_get_contents($clickPath), true);
        if (is_array($store) && !empty($store['items']) && is_array($store['items'])) {
            $insert = $pdo->prepare("
                INSERT INTO analytics_video_clicks (video_id, clicks, first_clicked_at, last_clicked_at)
                VALUES (?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    clicks = GREATEST(clicks, VALUES(clicks)),
                    first_clicked_at = LEAST(first_clicked_at, VALUES(first_clicked_at)),
                    last_clicked_at = GREATEST(last_clicked_at, VALUES(last_clicked_at))
            ");
            foreach ($store['items'] as $videoId => $meta) {
                if (!is_array($meta)) {
                    continue;
                }
                $id = (int)$videoId;
                if ($id <= 0) {
                    continue;
                }
                $first = (string)($meta['first_clicked_at'] ?? date('Y-m-d H:i:s'));
                $last = (string)($meta['last_clicked_at'] ?? $first);
                $insert->execute([
                    $id,
                    max(0, (int)($meta['clicks'] ?? 0)),
                    $first,
                    $last,
                ]);
            }
        }
        @rename($clickPath, $clickPath . '.bak');
    }

    $growthPath = $dir . '/user_growth.json';
    if (is_file($growthPath)) {
        @rename($growthPath, $growthPath . '.bak');
    }
    $growthDir = $dir . '/user_growth';
    if (is_dir($growthDir)) {
        foreach (glob($growthDir . '/*') ?: [] as $file) {
            if (is_file($file)) {
                @rename($file, $file . '.bak');
            }
        }
    }

    $pdo->prepare("
        INSERT INTO site_settings (setting_key, setting_value)
        VALUES (?, '1')
        ON DUPLICATE KEY UPDATE setting_value = '1'
    ")->execute([$flagKey]);

    $migrated = true;
}

function analyticsDb(PDO $pdo = null): PDO
{
    $pdo = $pdo ?? getDB();
    ensureAnalyticsTables($pdo);
    migrateAnalyticsFromJson($pdo);

    return $pdo;
}
