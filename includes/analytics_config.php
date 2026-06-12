<?php

require_once __DIR__ . '/settings.php';

const ANALYTICS_SETTING_ENABLED = 'analytics_enabled';
const ANALYTICS_SETTING_IP_ENABLED = 'analytics_ip_enabled';
const ANALYTICS_SETTING_LOGIN_ENABLED = 'analytics_login_enabled';
const ANALYTICS_SETTING_CLICKS_ENABLED = 'analytics_clicks_enabled';
const ANALYTICS_SETTING_IP_THROTTLE = 'analytics_ip_throttle_minutes';
const ANALYTICS_SETTING_IP_RETENTION = 'analytics_ip_retention_days';
const ANALYTICS_SETTING_RANKING_LIMIT = 'analytics_ranking_limit';
const ANALYTICS_SETTING_GROWTH_MAX_DAYS = 'analytics_growth_max_days';
const ANALYTICS_SETTING_IP_CLEANUP_AT = 'analytics_ip_cleanup_at';

function defaultAnalyticsConfig(): array
{
    return [
        'enabled' => true,
        'ip_enabled' => true,
        'login_enabled' => true,
        'clicks_enabled' => true,
        'ip_throttle_minutes' => 15,
        'ip_retention_days' => 90,
        'ranking_limit' => 100,
        'growth_max_days' => 730,
    ];
}

function getAnalyticsConfig(?PDO $pdo = null): array
{
    $pdo = $pdo ?? getDB();
    $cfg = defaultAnalyticsConfig();

    $cfg['enabled'] = getSetting($pdo, ANALYTICS_SETTING_ENABLED, '1') !== '0';
    $cfg['ip_enabled'] = getSetting($pdo, ANALYTICS_SETTING_IP_ENABLED, '1') !== '0';
    $cfg['login_enabled'] = getSetting($pdo, ANALYTICS_SETTING_LOGIN_ENABLED, '1') !== '0';
    $cfg['clicks_enabled'] = getSetting($pdo, ANALYTICS_SETTING_CLICKS_ENABLED, '1') !== '0';

    $throttle = (int)getSetting($pdo, ANALYTICS_SETTING_IP_THROTTLE, (string)$cfg['ip_throttle_minutes']);
    $cfg['ip_throttle_minutes'] = max(1, min(1440, $throttle > 0 ? $throttle : 15));

    $retention = (int)getSetting($pdo, ANALYTICS_SETTING_IP_RETENTION, (string)$cfg['ip_retention_days']);
    $cfg['ip_retention_days'] = max(7, min(3650, $retention > 0 ? $retention : 90));

    $limit = (int)getSetting($pdo, ANALYTICS_SETTING_RANKING_LIMIT, (string)$cfg['ranking_limit']);
    $cfg['ranking_limit'] = max(10, min(500, $limit > 0 ? $limit : 100));

    $growthMax = (int)getSetting($pdo, ANALYTICS_SETTING_GROWTH_MAX_DAYS, (string)$cfg['growth_max_days']);
    $cfg['growth_max_days'] = max(30, min(3650, $growthMax > 0 ? $growthMax : 730));

    return $cfg;
}

function isAnalyticsEnabled(?PDO $pdo = null): bool
{
    return getAnalyticsConfig($pdo)['enabled'];
}

function isAnalyticsIpEnabled(?PDO $pdo = null): bool
{
    $cfg = getAnalyticsConfig($pdo);
    return $cfg['enabled'] && $cfg['ip_enabled'];
}

function isAnalyticsLoginEnabled(?PDO $pdo = null): bool
{
    $cfg = getAnalyticsConfig($pdo);
    return $cfg['enabled'] && $cfg['login_enabled'];
}

function isAnalyticsClicksEnabled(?PDO $pdo = null): bool
{
    $cfg = getAnalyticsConfig($pdo);
    return $cfg['enabled'] && $cfg['clicks_enabled'];
}

function getAnalyticsRankingLimit(?PDO $pdo = null): int
{
    return (int)getAnalyticsConfig($pdo)['ranking_limit'];
}

function getAnalyticsIpThrottleMinutes(?PDO $pdo = null): int
{
    return (int)getAnalyticsConfig($pdo)['ip_throttle_minutes'];
}

function getAnalyticsIpRetentionDays(?PDO $pdo = null): int
{
    return (int)getAnalyticsConfig($pdo)['ip_retention_days'];
}

function getAnalyticsGrowthMaxDays(?PDO $pdo = null): int
{
    return (int)getAnalyticsConfig($pdo)['growth_max_days'];
}

function saveAnalyticsConfig(PDO $pdo, array $config): void
{
    setSetting($pdo, ANALYTICS_SETTING_ENABLED, !empty($config['enabled']) ? '1' : '0');
    setSetting($pdo, ANALYTICS_SETTING_IP_ENABLED, !empty($config['ip_enabled']) ? '1' : '0');
    setSetting($pdo, ANALYTICS_SETTING_LOGIN_ENABLED, !empty($config['login_enabled']) ? '1' : '0');
    setSetting($pdo, ANALYTICS_SETTING_CLICKS_ENABLED, !empty($config['clicks_enabled']) ? '1' : '0');

    $throttle = max(1, min(1440, (int)($config['ip_throttle_minutes'] ?? 15)));
    $retention = max(7, min(3650, (int)($config['ip_retention_days'] ?? 90)));
    $limit = max(10, min(500, (int)($config['ranking_limit'] ?? 100)));
    $growthMax = max(30, min(3650, (int)($config['growth_max_days'] ?? 730)));

    setSetting($pdo, ANALYTICS_SETTING_IP_THROTTLE, (string)$throttle);
    setSetting($pdo, ANALYTICS_SETTING_IP_RETENTION, (string)$retention);
    setSetting($pdo, ANALYTICS_SETTING_RANKING_LIMIT, (string)$limit);
    setSetting($pdo, ANALYTICS_SETTING_GROWTH_MAX_DAYS, (string)$growthMax);
}

function parseAnalyticsConfigFromPost(array $post): array
{
    return [
        'enabled' => isset($post['analytics_enabled']),
        'ip_enabled' => isset($post['analytics_ip_enabled']),
        'login_enabled' => isset($post['analytics_login_enabled']),
        'clicks_enabled' => isset($post['analytics_clicks_enabled']),
        'ip_throttle_minutes' => (int)($post['analytics_ip_throttle_minutes'] ?? 15),
        'ip_retention_days' => (int)($post['analytics_ip_retention_days'] ?? 90),
        'ranking_limit' => (int)($post['analytics_ranking_limit'] ?? 100),
        'growth_max_days' => (int)($post['analytics_growth_max_days'] ?? 730),
    ];
}

/** 同一会话内 IP 统计最短间隔（分钟） */
function shouldRecordIpVisitThisRequest(?PDO $pdo = null): bool
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        return true;
    }

    $minutes = getAnalyticsIpThrottleMinutes($pdo);
    $key = 'analytics_ip_tracked_at';
    $now = time();
    if (isset($_SESSION[$key]) && ($now - (int)$_SESSION[$key]) < $minutes * 60) {
        return false;
    }

    $_SESSION[$key] = $now;
    return true;
}

/** 仅首页、播放页、登录页记录 IP，避免 API/管理后台写放大 */
function isAnalyticsKeyPageRequest(): bool
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    return in_array($script, ['index.php', 'play.php', 'login.php'], true);
}

/** 删除超过保留期的 IP 记录（每小时最多执行一次） */
function maybeCleanupAnalyticsIpVisits(PDO $pdo): void
{
    $last = (int)getSetting($pdo, ANALYTICS_SETTING_IP_CLEANUP_AT, '0');
    if (time() - $last < 3600) {
        return;
    }

    $days = getAnalyticsIpRetentionDays($pdo);
    $stmt = $pdo->prepare('
        DELETE FROM analytics_ip_visits
        WHERE last_visit_at < DATE_SUB(NOW(), INTERVAL ? DAY)
    ');
    $stmt->execute([$days]);
    setSetting($pdo, ANALYTICS_SETTING_IP_CLEANUP_AT, (string)time());
}
