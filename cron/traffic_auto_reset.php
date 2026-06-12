<?php
/**
 * 流量自动重置定时任务
 *
 * CLI: php cron/traffic_auto_reset.php
 * 建议 crontab 每小时执行一次，例如:
 * 0 * * * * php /path/to/php-y/cron/traffic_auto_reset.php
 */
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/traffic.php';
require_once __DIR__ . '/../includes/settings.php';

$pdo = getDB();
$count = runDueAutoResets($pdo);
setSetting($pdo, 'traffic_auto_reset_last_run', (string)time());

if (php_sapi_name() === 'cli') {
    fwrite(STDOUT, "Traffic auto reset completed: {$count} user(s)\n");
} else {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => true, 'count' => $count], JSON_UNESCAPED_UNICODE);
}
