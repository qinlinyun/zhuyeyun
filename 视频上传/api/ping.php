<?php

require_once __DIR__ . '/../common.php';

$config = BackendConfig::get();
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$host = (string)($_SERVER['HTTP_HOST'] ?? '');
$apiDir = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '')), '/\\');
$uploadVideoHere = $host !== '' ? ($scheme . '://' . $host . $apiDir . '/upload_video.php') : '';

uploadBackendJson([
    'ok' => true,
    'service' => 'upload_backend',
    'time' => time(),
    'request_origin' => $origin,
    'main_site_url' => (string)($config['MAIN_SITE_URL'] ?? ''),
    'upload_video_url' => $uploadVideoHere,
    'embed_upload_url' => uploadBackendResolveEmbedPageUrl(),
    'hint' => '主站内嵌请使用 embed_upload.php；在 config.php 配置 UPLOAD_DOMAIN。',
]);
