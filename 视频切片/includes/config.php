<?php
/**
 * 应用路径与运行配置（非数据库凭证）
 */
return [
    // 项目内 videos 目录，需对 Web 运行用户（如 www）可写
    'upload_dir' => __DIR__ . '/../videos/',
    'ffmpeg_path' => '/usr/bin/ffmpeg',
    'ffprobe_path' => '/usr/bin/ffprobe',
    'login_page' => 'login.php',
    'index_page' => 'index.php',
    'install_page' => 'install.php',
];
