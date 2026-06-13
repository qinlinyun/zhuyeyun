<?php

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/domains.php';
require_once __DIR__ . '/play_token.php';

const VIDEO_SYNC_SETTING_ENABLED = 'video_sync_enabled';
const VIDEO_SYNC_SETTING_SITE_URL = 'video_sync_site_url';
const VIDEO_SYNC_SETTING_SECRET = 'video_sync_api_secret';
const VIDEO_SYNC_SETTING_AUTO = 'video_sync_auto_push';
const VIDEO_SYNC_SETTING_PATH_PREFIX = 'video_sync_path_prefix';

function videoSyncDefaultConfig(): array
{
    return [
        'enabled' => false,
        'site_url' => '',
        'api_secret' => '',
        'auto_push' => true,
        'path_prefix' => '/videos/',
    ];
}

function getVideoSyncConfig(): array
{
    $cfg = videoSyncDefaultConfig();
    $cfg['enabled'] = getDomainSetting(VIDEO_SYNC_SETTING_ENABLED) === '1';
    $cfg['site_url'] = rtrim(trim(getDomainSetting(VIDEO_SYNC_SETTING_SITE_URL)), '/');
    $cfg['api_secret'] = trim(getDomainSetting(VIDEO_SYNC_SETTING_SECRET));
    $cfg['auto_push'] = getDomainSetting(VIDEO_SYNC_SETTING_AUTO) !== '0';
    $prefix = trim(getDomainSetting(VIDEO_SYNC_SETTING_PATH_PREFIX));
    $cfg['path_prefix'] = $prefix !== '' ? videoSyncNormalizePrefix($prefix) : $cfg['path_prefix'];

    return $cfg;
}

function saveVideoSyncConfig(array $config): array
{
    $siteUrl = rtrim(trim((string)($config['site_url'] ?? '')), '/');
    $secret = trim((string)($config['api_secret'] ?? ''));
    $enabled = !empty($config['enabled']);

    if ($enabled) {
        if ($siteUrl === '') {
            return ['success' => false, 'message' => '请填写主站地址'];
        }
        if (!preg_match('#^https?://#i', $siteUrl)) {
            return ['success' => false, 'message' => '主站地址需以 http:// 或 https:// 开头'];
        }
        if ($secret === '') {
            return ['success' => false, 'message' => '请填写 API 密钥'];
        }
        if (strlen($secret) < 16) {
            return ['success' => false, 'message' => 'API 密钥至少 16 个字符'];
        }
    }

    $prefix = videoSyncNormalizePrefix(trim((string)($config['path_prefix'] ?? '/videos/')));

    if (!setDomainSetting(VIDEO_SYNC_SETTING_ENABLED, $enabled ? '1' : '0')) {
        return ['success' => false, 'message' => '保存开关失败'];
    }
    if (!setDomainSetting(VIDEO_SYNC_SETTING_SITE_URL, $siteUrl)) {
        return ['success' => false, 'message' => '保存主站地址失败'];
    }
    if (!setDomainSetting(VIDEO_SYNC_SETTING_SECRET, $secret)) {
        return ['success' => false, 'message' => '保存 API 密钥失败'];
    }
    if (!setDomainSetting(VIDEO_SYNC_SETTING_AUTO, !empty($config['auto_push']) ? '1' : '0')) {
        return ['success' => false, 'message' => '保存自动推送设置失败'];
    }
    if (!setDomainSetting(VIDEO_SYNC_SETTING_PATH_PREFIX, $prefix)) {
        return ['success' => false, 'message' => '保存路径前缀失败'];
    }

    return ['success' => true, 'message' => '数据同步 API 配置已保存'];
}

function videoSyncNormalizePrefix(string $prefix): string
{
    $prefix = '/' . trim(str_replace('\\', '/', $prefix), '/');
    if ($prefix === '/') {
        return '/videos/';
    }

    return rtrim($prefix, '/') . '/';
}

function videoSyncBuildM3u8Path(string $directory, string $pathPrefix): string
{
    $directory = trim(str_replace('\\', '/', $directory), '/');
    $prefix = videoSyncNormalizePrefix($pathPrefix);

    return $prefix . $directory . '/index.m3u8';
}

function videoSyncRequestSign(
    string $secret,
    string $recordId,
    string $title,
    string $m3u8Url,
    string $coverUrl,
    int $exp
): string {
    $payload = $recordId . '|' . $title . '|' . $m3u8Url . '|' . $coverUrl . '|' . $exp;

    return hash_hmac('sha256', $payload, $secret);
}

/**
 * 将切片记录推送到竹叶云主站
 *
 * @param array{id:string,title:string,directory:string,screenshot?:bool} $record
 * @return array{ok:bool,message?:string,video_id?:int}
 */
function pushVideoRecordToSite(array $record): array
{
    $cfg = getVideoSyncConfig();
    if (!$cfg['enabled'] || $cfg['site_url'] === '' || $cfg['api_secret'] === '') {
        return ['ok' => false, 'message' => '未开启或未配置数据同步'];
    }

    if (!isMainDomainConfigured()) {
        return ['ok' => false, 'message' => '请先配置域名，以生成 m3u8 与封面链接'];
    }

    $recordId = (string)($record['id'] ?? '');
    $title = trim((string)($record['title'] ?? ''));
    $directory = trim((string)($record['directory'] ?? ''));
    if ($recordId === '' || $title === '' || $directory === '') {
        return ['ok' => false, 'message' => '记录数据不完整'];
    }

    $m3u8Path = videoSyncBuildM3u8Path($directory, $cfg['path_prefix']);
    $coverUrl = !empty($record['screenshot']) ? buildImageAssetUrl($directory) : '';
    $exp = time() + 300;
    $sign = videoSyncRequestSign($cfg['api_secret'], $recordId, $title, $m3u8Path, $coverUrl, $exp);

    $endpoint = $cfg['site_url'] . '/api/video_data_sync.php';
    $body = json_encode([
        'record_id' => $recordId,
        'title' => $title,
        'm3u8_url' => $m3u8Path,
        'cover_url' => $coverUrl,
        'episode_name' => '1',
        'exp' => $exp,
        'sign' => $sign,
    ], JSON_UNESCAPED_UNICODE);

    $ch = curl_init($endpoint);
    if ($ch === false) {
        return ['ok' => false, 'message' => '无法初始化请求'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => $body,
        CURLOPT_HTTPHEADER => [
            'Content-Type: application/json',
            'Accept: application/json',
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $raw === false) {
        return ['ok' => false, 'message' => '连接主站失败'];
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => '主站返回格式错误'];
    }

    if ($httpCode >= 400 || empty($data['ok'])) {
        return ['ok' => false, 'message' => (string)($data['message'] ?? '同步失败')];
    }

    return [
        'ok' => true,
        'message' => (string)($data['message'] ?? '同步成功'),
        'video_id' => isset($data['video_id']) ? (int)$data['video_id'] : 0,
    ];
}

/**
 * 导出全部切片记录（供主站拉取）
 *
 * @return array<int, array<string, mixed>>
 */
function videoSyncExportRecords(): array
{
    if (!isMainDomainConfigured()) {
        return [];
    }

    $cfg = getVideoSyncConfig();
    $records = readRecords();
    $items = [];

    foreach ($records as $record) {
        $directory = (string)($record['directory'] ?? '');
        if ($directory === '') {
            continue;
        }
        $items[] = [
            'record_id' => (string)($record['id'] ?? ''),
            'title' => (string)($record['title'] ?? ''),
            'm3u8_url' => videoSyncBuildM3u8Path($directory, $cfg['path_prefix']),
            'cover_url' => !empty($record['screenshot']) ? buildImageAssetUrl($directory) : '',
            'created_at' => (string)($record['created_at'] ?? ''),
            'duration_seconds' => playTokenParseM3u8Duration($directory . '/index.m3u8'),
        ];
    }

    return $items;
}

function videoSyncVerifyListRequest(string $secret, int $exp, string $sign): bool
{
    if ($secret === '' || $sign === '' || $exp <= time()) {
        return false;
    }
    $expected = hash_hmac('sha256', 'list|' . $exp, $secret);

    return hash_equals($expected, $sign);
}
