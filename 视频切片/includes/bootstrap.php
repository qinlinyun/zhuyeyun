<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/domains.php';
require_once __DIR__ . '/upload_storage.php';
require_once __DIR__ . '/storage.php';
require_once __DIR__ . '/play_token.php';

/**
 * 未安装数据库时跳转到安装引导页（install.php 自身除外）
 */
function requireDatabaseInstalled(): void
{
    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if ($script === 'install.php') {
        return;
    }

    if (isDatabaseInstalled()) {
        ensureAppSettingsTable();
        seedDomainSettingsFromLegacyConfig();
        return;
    }

    $self = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#/api/#', $self)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'message' => '系统尚未完成安装，请先运行安装引导',
            'install_url' => '../install.php',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: install.php');
    exit;
}

$sliceScript = basename($_SERVER['SCRIPT_NAME'] ?? '');
if ($sliceScript !== 'install.php') {
    requireDatabaseInstalled();
}
