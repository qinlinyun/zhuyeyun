<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/upload_config.php';
require_once __DIR__ . '/../includes/video_data_sync.php';

function installerMainSiteUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '') {
        return '';
    }
    return $scheme . '://' . $host;
}

function installerEmitFile(string $targetPath, string $content): string
{
    $content = str_replace("\r\n", "\n", $content);
    $content = str_replace("\r", "\n", $content);
    return "cat > \"{$targetPath}\" <<'EOF'\n{$content}\nEOF\n";
}

$pdo = getDB();
$apiConfig = getUploadApiConfig($pdo);
$secret = (string)($apiConfig['remote_api_token'] ?? '');
$token = trim((string)($_GET['token'] ?? ''));

$payload = uploadParseSignedToken($token, $secret);
if (!is_array($payload) || (string)($payload['scope'] ?? '') !== 'upload_backend_install') {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "invalid token\n";
    exit;
}
if ((int)($payload['exp'] ?? 0) <= time()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "token expired\n";
    exit;
}

$mainSite = installerMainSiteUrl();
if ($mainSite === '') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "cannot resolve main site url\n";
    exit;
}

$videoSync = getVideoDataSyncConfig($pdo);

$backendConfig = [
    'MAIN_SITE_URL' => $mainSite,
    'API_TOKEN' => (string)($apiConfig['remote_api_token'] ?? ''),
    'UPLOAD_DOMAIN' => (string)($apiConfig['upload_domain'] ?? ''),
    'VIDEO_DOMAIN' => (string)($apiConfig['video_domain'] ?? ''),
    'IMAGE_DOMAIN' => (string)($apiConfig['image_domain'] ?? ''),
    'M3U8_DIR' => (string)($apiConfig['m3u8_dir'] ?? 'm3u8'),
    'MP4_DIR' => (string)($apiConfig['mp4_dir'] ?? 'mp4'),
    'FTP_SOURCE_ROOT' => '',
    'ORIGINALS_DIR' => 'originals',
    'FFMPEG_PATH' => '/usr/bin/ffmpeg',
    'FFPROBE_PATH' => '/usr/bin/ffprobe',
    'VIDEO_SYNC_SECRET' => (string)($videoSync['api_secret'] ?? ''),
    'VIDEO_SYNC_PATH_PREFIX' => (string)($videoSync['path_prefix'] ?? 'storage/'),
    'MAX_UPLOAD_BYTES' => 21474836480,
    'ALLOWED_VIDEO_EXTENSIONS' => ['mp4'],
];

$configPhpBody = "<?php\nreturn " . var_export($backendConfig, true) . ";\n";

header('Content-Type: text/plain; charset=utf-8');

echo "#!/usr/bin/env bash\n";
echo "set -euo pipefail\n\n";
echo 'INSTALL_DIR=""' . "\n";
echo 'while [ $# -gt 0 ]; do' . "\n";
echo '  case "$1" in' . "\n";
echo '    --dir)' . "\n";
echo '      INSTALL_DIR="$2"; shift 2;;' . "\n";
echo '    *) shift;;' . "\n";
echo '  esac' . "\n";
echo 'done' . "\n";
echo 'if [ -z "$INSTALL_DIR" ]; then INSTALL_DIR="/www/wwwroot/upload-backend"; fi' . "\n";
echo 'if [ ! -d "$INSTALL_DIR" ]; then' . "\n";
echo '  echo "[错误] 目录不存在: $INSTALL_DIR（请先将「视频上传」后端部署到该目录）" >&2' . "\n";
echo '  exit 1' . "\n";
echo 'fi' . "\n";
echo 'CONFIG_PATH="$INSTALL_DIR/config.php"' . "\n";
echo 'if [ -f "$CONFIG_PATH" ]; then cp -a "$CONFIG_PATH" "$CONFIG_PATH.bak"; fi' . "\n\n";

echo installerEmitFile('${CONFIG_PATH}', $configPhpBody);
echo 'chmod 644 "$CONFIG_PATH" 2>/dev/null || true' . "\n";
echo 'echo "[OK] 已更新 config.php：$CONFIG_PATH"' . "\n";
echo 'echo "未修改其它程序文件。可访问 config_guide.php 运行开箱自检。"' . "\n";
