<?php

declare(strict_types=1);

require_once __DIR__ . '/Support.php';
require_once __DIR__ . '/Config.php';
require_once __DIR__ . '/Token.php';
require_once __DIR__ . '/ChunkUpload.php';
require_once __DIR__ . '/Storage.php';
require_once __DIR__ . '/Transcode.php';
require_once __DIR__ . '/Sync.php';
require_once __DIR__ . '/Review.php';

if (session_status() === PHP_SESSION_NONE) {
    session_set_cookie_params([
        'httponly' => true,
        'secure' => !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off',
        'samesite' => 'Lax',
    ]);
    session_start();
}
