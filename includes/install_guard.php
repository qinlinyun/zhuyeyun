<?php

function siteInstallMarkerPath(): string
{
    return dirname(__DIR__) . '/.installed';
}

function siteDbConfigPath(): string
{
    return dirname(__DIR__) . '/config/database_config.php';
}

function siteInstallScriptBasename(): string
{
    return basename($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? '');
}

function siteInstallRedirectUrl(): string
{
    $self = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#/(admin|api)/#', $self)) {
        return '../install.php';
    }

    return 'install.php';
}

function siteIsApiRequest(): bool
{
    $self = str_replace('\\', '/', (string)($_SERVER['PHP_SELF'] ?? $_SERVER['SCRIPT_NAME'] ?? ''));

    return (bool)preg_match('#/api/#', $self);
}

function siteShouldSkipInstallGuard(): bool
{
    $skip = ['install.php'];
    $base = siteInstallScriptBasename();

    return in_array($base, $skip, true);
}

function siteVerifyDatabaseReady(): bool
{
    if (!defined('DB_HOST')) {
        if (!is_file(siteDbConfigPath())) {
            return false;
        }
        require siteDbConfigPath();
    }

    try {
        $charset = defined('DB_CHARSET') ? DB_CHARSET : 'utf8mb4';
        $pdo = new PDO(
            'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=' . $charset,
            DB_USER,
            DB_PASS,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
        $st = $pdo->query("SHOW TABLES LIKE 'users'");

        return $st && (bool)$st->fetch();
    } catch (Throwable $e) {
        return false;
    }
}

function isSiteInstalled(): bool
{
    static $cached = null;
    if ($cached !== null) {
        return $cached;
    }

    if (!is_file(siteInstallMarkerPath()) || !is_file(siteDbConfigPath())) {
        $cached = false;

        return false;
    }

    $cached = siteVerifyDatabaseReady();

    return $cached;
}

function siteRequireInstalledOrExit(): void
{
    if (siteShouldSkipInstallGuard()) {
        return;
    }

    if (isSiteInstalled()) {
        return;
    }

    if (siteIsApiRequest()) {
        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            http_response_code(503);
        }
        echo json_encode([
            'ok' => false,
            'message' => '系统尚未完成安装，请先运行安装引导',
            'install_url' => siteInstallRedirectUrl(),
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    if (!headers_sent()) {
        header('Location: ' . siteInstallRedirectUrl());
    }
    exit;
}
