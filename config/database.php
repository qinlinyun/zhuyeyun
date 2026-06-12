<?php
// 数据库连接入口：读取 install.php 写入的 database_config.php，并提供 getDB()
require_once __DIR__ . '/../includes/install_guard.php';
require_once __DIR__ . '/../includes/datetime.php';
initAppTimezone();
siteRequireInstalledOrExit();

$configFile = __DIR__ . '/database_config.php';
if (!file_exists($configFile)) {
    siteRequireInstalledOrExit();
}
require_once $configFile;

if (!function_exists('getDB')) {
    function getDB(): PDO {
        static $pdo = null;
        if ($pdo instanceof PDO) {
            return $pdo;
        }
        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
        $dsn = 'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . $charset;
        try {
            $pdo = new PDO($dsn, DB_USER, DB_PASS, [
                PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                PDO::ATTR_EMULATE_PREPARES   => false,
            ]);
            $pdo->exec("SET NAMES '{$charset}'");
            initDbTimezone($pdo);
        } catch (PDOException $e) {
            http_response_code(500);
            die('数据库连接失败：' . htmlspecialchars($e->getMessage()));
        }
        return $pdo;
    }
}
