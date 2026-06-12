<?php

require_once __DIR__ . '/../config/database.php';

function ensureSiteSettingsTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `site_settings` (
        `setting_key` varchar(100) NOT NULL,
        `setting_value` text NOT NULL,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`setting_key`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ready = true;
}

function getSetting(PDO $pdo, string $key, ?string $default = null): ?string
{
    ensureSiteSettingsTable($pdo);

    $stmt = $pdo->prepare('SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $value = $stmt->fetchColumn();

    if ($value === false) {
        return $default;
    }

    return (string)$value;
}

function setSetting(PDO $pdo, string $key, string $value): void
{
    ensureSiteSettingsTable($pdo);

    $stmt = $pdo->prepare('
        INSERT INTO site_settings (setting_key, setting_value)
        VALUES (?, ?)
        ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
    ');
    $stmt->execute([$key, $value]);
}

require_once __DIR__ . '/register_closed_page.php';
require_once __DIR__ . '/register_page.php';
require_once __DIR__ . '/register_schedule.php';
require_once __DIR__ . '/theme_config.php';
require_once __DIR__ . '/theme_colors.php';
require_once __DIR__ . '/font_config.php';

function isRegisterMasterEnabled(PDO $pdo): bool
{
    applyRegisterSchedule($pdo);
    return getSetting($pdo, 'register_enabled', '1') === '1';
}

function isRegisterEnabled(PDO $pdo): bool
{
    applyRegisterSchedule($pdo);

    if (getSetting($pdo, 'register_enabled', '1') !== '1') {
        return false;
    }

    $schedule = getRegisterScheduleConfig($pdo);
    if (isRegisterScheduleActive($schedule) && isRegisterScheduleClosed($schedule)) {
        return false;
    }

    return true;
}

function isRegisterScheduleBlocking(PDO $pdo): bool
{
    applyRegisterSchedule($pdo);

    $schedule = getRegisterScheduleConfig($pdo);
    if (!isRegisterScheduleActive($schedule)) {
        return false;
    }

    return isRegisterScheduleClosed($schedule);
}
