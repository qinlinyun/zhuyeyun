<?php

require_once __DIR__ . '/../includes/upload_config.php';

header('Content-Type: application/json; charset=utf-8');
http_response_code(410);
echo json_encode([
    'ok' => false,
    'message' => '已改为浏览器直传远程后端，请使用上传页面提交（不经主站转发视频文件）',
], JSON_UNESCAPED_UNICODE);
