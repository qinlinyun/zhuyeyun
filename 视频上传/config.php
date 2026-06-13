<?php
/**
 * 远程上传后端配置
 * MAIN_SITE_URL 填主站地址，例如：https://www.example.com
 * API_TOKEN 填主站「上传管理 -> 上传后端配置 -> API配置」中生成的 Token
 */
return [
    'MAIN_SITE_URL' => '',
    'API_TOKEN' => '',
    /** 内嵌上传页对外域名/根路径，主站 upload.php 用 iframe 加载；例 https://upload.example.com/视频上传 */
    'UPLOAD_DOMAIN' => '',
    'VIDEO_DOMAIN' => '',
    'IMAGE_DOMAIN' => '',
    'M3U8_DIR' => 'm3u8',
    'MP4_DIR' => 'mp4',
    /** FTP 上传时原始 mp4 在转码服务器上的绝对根目录（与主站「转码服务器原始目录」一致，主站 API 会优先传递） */
    'FTP_SOURCE_ROOT' => '',
    'ORIGINALS_DIR' => 'originals',
    'FFMPEG_PATH' => '/usr/bin/ffmpeg',
    'FFPROBE_PATH' => '/usr/bin/ffprobe',
    'VIDEO_SYNC_SECRET' => '',
    /** 已弃用：同步路径现固定为 storage/m3u8/... 相对路径 */
    'VIDEO_SYNC_PATH_PREFIX' => 'storage/',
    'MAX_UPLOAD_BYTES' => 21474836480,
    'ALLOWED_VIDEO_EXTENSIONS' => ['mp4'],
];
