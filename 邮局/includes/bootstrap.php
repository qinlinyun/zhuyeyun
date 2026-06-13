<?php

require_once __DIR__ . '/config.php';

$script = basename($_SERVER['SCRIPT_NAME'] ?? '');
$publicScripts = ['install.php', 'login.php', 'logout.php'];
if (!in_array($script, $publicScripts, true) && !mailServerIsInstalled()) {
    $self = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? ''));
    if (preg_match('#/api/#', $self)) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(503);
        echo json_encode([
            'ok' => false,
            'message' => '邮局尚未安装，请先访问 install.php',
            'install_url' => '../install.php',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    header('Location: install.php');
    exit;
}
