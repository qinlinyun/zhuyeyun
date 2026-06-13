<?php

require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/play_token.php';

$cfg = getPlayTokenConfig();
if ($cfg['api_secret'] === '') {
    http_response_code(503);
    header('Content-Type: text/plain; charset=utf-8');
    echo '播放服务未配置';
    exit;
}

$path = playTokenDecodePath((string)($_GET['p'] ?? ''));
$email = playTokenDecodeEmail((string)($_GET['e'] ?? ''));
$exp = (int)($_GET['exp'] ?? 0);
$sig = (string)($_GET['sig'] ?? '');

if ($path === '' || $email === '' || $exp <= 0 || $sig === '') {
    http_response_code(400);
    header('Content-Type: text/plain; charset=utf-8');
    echo '参数错误';
    exit;
}

if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '无权播放';
    exit;
}

if (!playTokenVerifyPlay($cfg['api_secret'], $email, $path, $exp, $sig)) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo '链接无效或已过期';
    exit;
}

$file = playTokenResolveMediaFile($path);
if ($file === null) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo '文件不存在';
    exit;
}

$mime = playTokenMimeForPath($path);
header('Content-Type: ' . $mime);
header('Cache-Control: no-store, no-cache, must-revalidate');
header('Pragma: no-cache');

if (preg_match('/\.m3u8$/i', $path)) {
    $content = file_get_contents($file);
    if ($content === false) {
        http_response_code(500);
        echo '读取失败';
        exit;
    }
    echo playTokenRewriteM3u8(
        $content,
        $cfg['api_secret'],
        $email,
        $path,
        $exp,
        $cfg['signed_script_path']
    );
    exit;
}

header('Content-Length: ' . (string)filesize($file));
readfile($file);
