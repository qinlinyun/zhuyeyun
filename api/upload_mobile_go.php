<?php

/**
 * 手机端：已登录用户携带令牌跳转到远程上传后端的独立上传页
 */
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/upload_config.php';

requireLogin();

if (isAdmin()) {
    header('Location: admin/upload_manage.php?section=overview');
    exit;
}

$pdo = getDB();
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

if (!UploadConfig::isPhpUploadReady($pdo)) {
    $_SESSION['upload_error_flash'] = '上传服务未就绪，请联系管理员配置远程上传';
    header('Location: ../upload.php');
    exit;
}

$apiConfig = getUploadApiConfig($pdo);
$mobilePage = UploadConfig::resolveMobileUploadPageUrl($apiConfig);
if ($mobilePage === '') {
    $_SESSION['upload_error_flash'] = '未配置手机上传页地址，请设置上传域名';
    header('Location: ../upload.php');
    exit;
}

$prepare = UploadService::prepareUserUpload($pdo, $userId, 'video.mp4');
if (empty($prepare['ok'])) {
    $_SESSION['upload_error_flash'] = (string)($prepare['error'] ?? $prepare['message'] ?? '无法准备上传');
    header('Location: ../upload.php');
    exit;
}

$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$mainHost = (string)($_SERVER['HTTP_HOST'] ?? '');
$scriptDir = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')));
$siteRoot = preg_replace('#/api$#', '', rtrim($scriptDir, '/')) ?: '';
$returnUrl = $scheme . '://' . $mainHost . $siteRoot . '/upload.php?mobile_done=1';

$userLabel = trim((string)($user['username'] ?? $user['email'] ?? ''));
if ($userLabel === '') {
    $userLabel = '用户 #' . $userId;
}

$trafficConfig = getUploadVideoConfig($pdo);
$mobileUrl = UploadConfig::buildMobileUploadUrl(
    $apiConfig,
    (string)$prepare['upload_token'],
    (string)$prepare['stored_filename'],
    [
        'return_url' => $returnUrl,
        'user_label' => $userLabel,
        'traffic_enabled' => !empty($trafficConfig['traffic_enabled']) ? '1' : '0',
    ]
);

header('Location: ' . $mobileUrl);
exit;
