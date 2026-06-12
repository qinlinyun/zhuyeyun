<?php

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/player_proxy.php';
require_once __DIR__ . '/upload_domain_group.php';

const VIDEO_SYNC_SETTING_ENABLED = 'video_sync_enabled';
const VIDEO_SYNC_SETTING_BACKEND = 'video_sync_backend_url';
const VIDEO_SYNC_SETTING_BACKENDS = 'video_sync_backend_urls';
const VIDEO_SYNC_SETTING_SECRET = 'video_sync_api_secret';
const VIDEO_SYNC_SETTING_PATH_PREFIX = 'video_sync_path_prefix';
const VIDEO_SYNC_SETTING_SERVER_GROUP = 'video_sync_server_group_id';
const VIDEO_SYNC_SETTING_MAP = 'video_sync_record_map';

function defaultVideoDataSyncConfig(): array
{
    return [
        'enabled' => false,
        'backend_url' => '',
        'backends' => [],
        'backend_urls' => [],
        'api_secret' => '',
        'path_prefix' => 'storage/',
        'server_group_id' => null,
    ];
}

function getVideoDataSyncConfig(PDO $pdo): array
{
    $cfg = defaultVideoDataSyncConfig();
    $cfg['enabled'] = getSetting($pdo, VIDEO_SYNC_SETTING_ENABLED, '0') === '1';
    $cfg['backends'] = loadPlayerBackendEntries($pdo, VIDEO_SYNC_SETTING_BACKENDS, VIDEO_SYNC_SETTING_BACKEND);
    $cfg['backend_urls'] = playerBackendUrlsOnly($cfg['backends']);
    $cfg['backend_url'] = firstPlayerBackendUrl($cfg['backends']);
    $cfg['api_secret'] = trim((string)getSetting($pdo, VIDEO_SYNC_SETTING_SECRET, ''));
    $prefix = trim((string)getSetting($pdo, VIDEO_SYNC_SETTING_PATH_PREFIX, $cfg['path_prefix']));
    $cfg['path_prefix'] = videoSyncNormalizePathPrefix($prefix !== '' ? $prefix : $cfg['path_prefix']);
    $sg = trim((string)getSetting($pdo, VIDEO_SYNC_SETTING_SERVER_GROUP, ''));
    $cfg['server_group_id'] = $sg === '' ? null : (int)$sg;

    return $cfg;
}

function saveVideoDataSyncConfig(PDO $pdo, array $config): void
{
    setSetting($pdo, VIDEO_SYNC_SETTING_ENABLED, !empty($config['enabled']) ? '1' : '0');
    $entries = normalizePlayerBackendEntries($config['backends'] ?? []);
    if ($entries === [] && trim((string)($config['backend_url'] ?? '')) !== '') {
        $entries = normalizePlayerBackendEntries([['name' => '', 'url' => $config['backend_url']]]);
    }
    savePlayerBackendEntries($pdo, VIDEO_SYNC_SETTING_BACKENDS, VIDEO_SYNC_SETTING_BACKEND, $entries);
    setSetting($pdo, VIDEO_SYNC_SETTING_SECRET, trim((string)($config['api_secret'] ?? '')));
    $prefix = videoSyncNormalizePathPrefix(trim((string)($config['path_prefix'] ?? 'storage/')));
    setSetting($pdo, VIDEO_SYNC_SETTING_PATH_PREFIX, $prefix);
    $sg = $config['server_group_id'] ?? null;
    setSetting($pdo, VIDEO_SYNC_SETTING_SERVER_GROUP, ($sg === null || $sg === '') ? '' : (string)(int)$sg);
}

function videoSyncNormalizePathPrefix(string $prefix): string
{
    $prefix = trim(str_replace('\\', '/', $prefix), '/');
    if ($prefix === '') {
        return 'storage/';
    }

    return rtrim($prefix, '/') . '/';
}

function videoDataSyncValidationError(PDO $pdo, array $config): ?string
{
    if (empty($config['enabled'])) {
        return null;
    }

    $secret = trim((string)($config['api_secret'] ?? ''));
    if ($secret === '') {
        $proxyCfg = getPlayerProxyConfig($pdo);
        $secret = trim((string)($proxyCfg['api_secret'] ?? ''));
    }
    if ($secret === '') {
        return '开启数据同步时，请填写 API 密钥（可在「视频数据 API 同步」或「后端代理」中填写）';
    }
    if (strlen($secret) < 16) {
        return 'API 密钥至少 16 个字符';
    }

    return null;
}

function videoSyncRequestSign(string $secret, string $recordId, string $title, string $m3u8Url, string $coverUrl, int $exp): string
{
    $payload = $recordId . '|' . $title . '|' . $m3u8Url . '|' . $coverUrl . '|' . $exp;

    return hash_hmac('sha256', $payload, $secret);
}

function videoSyncVerifyRequestSign(
    string $secret,
    string $recordId,
    string $title,
    string $m3u8Url,
    string $coverUrl,
    int $exp,
    string $sign
): bool {
    if ($secret === '' || $sign === '') {
        return false;
    }
    $expected = videoSyncRequestSign($secret, $recordId, $title, $m3u8Url, $coverUrl, $exp);

    return hash_equals($expected, $sign);
}

function videoSyncGetRecordMap(PDO $pdo): array
{
    $raw = getSetting($pdo, VIDEO_SYNC_SETTING_MAP, '{}');
    $map = json_decode((string)$raw, true);

    return is_array($map) ? $map : [];
}

function videoSyncSaveRecordMap(PDO $pdo, array $map): void
{
    setSetting($pdo, VIDEO_SYNC_SETTING_MAP, json_encode($map, JSON_UNESCAPED_UNICODE));
}

function videoSyncNormalizeM3u8Path(string $url, string $pathPrefix): string
{
    $url = trim(str_replace('\\', '/', $url));
    if ($url === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $url)) {
        $parts = parse_url($url);
        $url = (string)($parts['path'] ?? '');
    }

    $url = ltrim(str_replace('\\', '/', $url), '/');
    if (str_starts_with(strtolower($url), 'storage/')) {
        return $url;
    }

    $prefix = videoSyncNormalizePathPrefix($pathPrefix);
    // 统一用“无前导 /”的相对路径比较，避免 /videos/ + videos/... 变成 videos/videos/...
    if ($prefix !== '') {
        while (str_starts_with($url, $prefix . $prefix)) {
            $url = substr($url, strlen($prefix));
        }
        if (!str_starts_with($url, $prefix)) {
            $url = $prefix . $url;
        }
    }

    return $url;
}

function videoSyncNormalizeCoverUrl(string $url, string $pathPrefix = 'storage/', ?PDO $pdo = null): string
{
    if ($pdo instanceof PDO) {
        require_once __DIR__ . '/upload/Support.php';

        return UploadSupport::resolveCoverUrl($pdo, $url);
    }

    $url = trim(str_replace('\\', '/', $url));
    if ($url === '') {
        return '';
    }

    if (preg_match('#^https?://#i', $url)) {
        return $url;
    }

    return ltrim($url, '/');
}

/**
 * 处理来自视频切片后端的同步请求
 *
 * @return array{ok:bool,message?:string,video_id?:int,episode_id?:int,action?:string}
 */
function processVideoDataSync(PDO $pdo, array $input): array
{
    $cfg = getVideoDataSyncConfig($pdo);
    $proxyCfg = getPlayerProxyConfig($pdo);
    $enabled = !empty($cfg['enabled']) || !empty($proxyCfg['enabled']);
    if (!$enabled) {
        return ['ok' => false, 'message' => '主站未开启视频数据同步'];
    }

    $secret = $cfg['api_secret'] !== '' ? $cfg['api_secret'] : (string)($proxyCfg['api_secret'] ?? '');
    $secret = trim($secret);
    if ($secret === '') {
        return ['ok' => false, 'message' => '主站未配置 API 密钥'];
    }

    $recordId = trim((string)($input['record_id'] ?? ''));
    $title = trim((string)($input['title'] ?? ''));
    $m3u8Url = videoSyncNormalizeM3u8Path(
        (string)($input['m3u8_url'] ?? ''),
        $cfg['path_prefix']
    );
    $coverInput = trim((string)($input['cover_url'] ?? ''));
    $coverUrl = $coverInput !== ''
        ? videoSyncNormalizeCoverUrl($coverInput, $cfg['path_prefix'], $pdo)
        : '';
    $episodeName = trim((string)($input['episode_name'] ?? '1'));
    if ($episodeName === '') {
        $episodeName = '1';
    }
    $exp = (int)($input['exp'] ?? 0);
    $sign = (string)($input['sign'] ?? '');

    if ($recordId === '') {
        return ['ok' => false, 'message' => '缺少 record_id'];
    }
    if ($title === '') {
        return ['ok' => false, 'message' => '缺少视频名称 title'];
    }
    if ($m3u8Url === '') {
        return ['ok' => false, 'message' => '缺少 m3u8 链接'];
    }
    if ($exp <= time()) {
        return ['ok' => false, 'message' => '请求已过期'];
    }
    $signOk = videoSyncVerifyRequestSign($secret, $recordId, $title, $m3u8Url, $coverUrl, $exp, $sign);
    if (!$signOk && $coverInput !== $coverUrl) {
        $signOk = videoSyncVerifyRequestSign($secret, $recordId, $title, $m3u8Url, trim($coverInput), $exp, $sign);
    }
    if (!$signOk) {
        return ['ok' => false, 'message' => '签名校验失败'];
    }

    $map = videoSyncGetRecordMap($pdo);
    $videoId = isset($map[$recordId]) ? (int)$map[$recordId] : 0;
    $action = 'update';

    $hasSg = (bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'server_group_id'")->fetch();
    $serverGroupId = resolveVideoDefaultServerGroupId($pdo, $recordId, $cfg['server_group_id']);

    if ($videoId > 0) {
        $check = $pdo->prepare('SELECT id FROM videos WHERE id = ? LIMIT 1');
        $check->execute([$videoId]);
        if (!$check->fetch()) {
            $videoId = 0;
        }
    }

    if ($videoId <= 0) {
        $action = 'create';
        $cols = ['title', 'description', 'cover'];
        $vals = [$title, '', $coverUrl !== '' ? $coverUrl : null];
        $place = ['?', '?', '?'];
        if ($hasSg && $serverGroupId !== null) {
            $cols[] = 'server_group_id';
            $vals[] = $serverGroupId;
            $place[] = '?';
        }
        $sql = 'INSERT INTO videos (' . implode(',', $cols) . ') VALUES (' . implode(',', $place) . ')';
        $pdo->prepare($sql)->execute($vals);
        $videoId = (int)$pdo->lastInsertId();
        $map[$recordId] = $videoId;
        videoSyncSaveRecordMap($pdo, $map);
    } else {
        $sets = ['title = ?', 'cover = ?'];
        $vals = [$title, $coverUrl !== '' ? $coverUrl : null];
        if ($hasSg && $serverGroupId !== null) {
            $isUploadRecord = strncmp($recordId, 'upload_', 7) === 0;
            if (!$isUploadRecord) {
                $sets[] = 'server_group_id = ?';
                $vals[] = $serverGroupId;
            } else {
                $sgCheck = $pdo->prepare('SELECT server_group_id FROM videos WHERE id = ? LIMIT 1');
                $sgCheck->execute([$videoId]);
                $currentSg = $sgCheck->fetchColumn();
                if ($currentSg === false || $currentSg === null || $currentSg === '') {
                    $sets[] = 'server_group_id = ?';
                    $vals[] = $serverGroupId;
                }
            }
        }
        $vals[] = $videoId;
        $pdo->prepare('UPDATE videos SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }

    $epStmt = $pdo->prepare(
        'SELECT id FROM video_episodes WHERE video_id = ? AND episode_name = ? LIMIT 1'
    );
    $epStmt->execute([$videoId, $episodeName]);
    $episodeRow = $epStmt->fetch();

    if ($episodeRow) {
        $episodeId = (int)$episodeRow['id'];
        $pdo->prepare('UPDATE video_episodes SET video_url = ? WHERE id = ?')
            ->execute([$m3u8Url, $episodeId]);
    } else {
        $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(episode_order), 0) + 1 FROM video_episodes WHERE video_id = ?');
        $orderStmt->execute([$videoId]);
        $order = (int)$orderStmt->fetchColumn();
        $pdo->prepare(
            'INSERT INTO video_episodes (video_id, episode_name, video_url, episode_order) VALUES (?, ?, ?, ?)'
        )->execute([$videoId, $episodeName, $m3u8Url, $order]);
        $episodeId = (int)$pdo->lastInsertId();
    }

    return [
        'ok' => true,
        'message' => $action === 'create' ? '已创建视频并同步分集' : '已更新视频数据',
        'video_id' => $videoId,
        'episode_id' => $episodeId,
        'action' => $action,
    ];
}

/**
 * 从视频切片后端拉取待同步列表
 *
 * @return array{ok:bool,message?:string,items?:array}
 */
/**
 * @return string[]
 */
function videoSyncResolveBackendUrls(PDO $pdo): array
{
    $syncCfg = getVideoDataSyncConfig($pdo);
    $proxyCfg = getPlayerProxyConfig($pdo);
    $urls = $syncCfg['backend_urls'];
    if ($urls === []) {
        $urls = $proxyCfg['backend_urls'];
    }

    return $urls;
}

/**
 * @return array{ok:bool,message?:string,items?:array}
 */
function fetchVideoSyncItemsFromSingleBackend(string $backend, string $secret): array
{
    $backend = normalizePlayerBackendBaseUrl($backend);
    if ($backend === '') {
        return ['ok' => false, 'message' => '后端地址无效'];
    }

    $exp = time() + 300;
    $sign = hash_hmac('sha256', 'list|' . $exp, $secret);
    $endpoint = $backend . '/api/video_sync_list.php';

    $ch = curl_init($endpoint);
    if ($ch === false) {
        return ['ok' => false, 'message' => '无法初始化请求'];
    }

    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => http_build_query([
            'exp' => $exp,
            'sign' => $sign,
        ]),
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 20,
        CURLOPT_CONNECTTIMEOUT => 10,
    ]);

    $raw = curl_exec($ch);
    $errno = curl_errno($ch);
    $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($errno !== 0 || $raw === false) {
        return ['ok' => false, 'message' => '连接失败'];
    }

    $data = json_decode((string)$raw, true);
    if (!is_array($data)) {
        $snippet = trim(strip_tags(substr((string)$raw, 0, 120)));
        $hint = $snippet !== '' ? '：' . $snippet : '';

        return ['ok' => false, 'message' => '返回非 JSON' . $hint];
    }
    if ($httpCode >= 400 || empty($data['ok'])) {
        $msg = (string)($data['message'] ?? '拉取失败');
        if ($msg === '请使用 POST' || strpos($msg, 'POST') !== false) {
            $msg = '地址可能填错，应填写视频切片站点根地址';
        }

        return ['ok' => false, 'message' => $msg];
    }

    return ['ok' => true, 'items' => $data['items'] ?? [], 'backend' => $backend];
}

function fetchVideoSyncItemsFromBackend(PDO $pdo): array
{
    $proxyCfg = getPlayerProxyConfig($pdo);
    $syncCfg = getVideoDataSyncConfig($pdo);
    $secret = $syncCfg['api_secret'] !== '' ? $syncCfg['api_secret'] : $proxyCfg['api_secret'];
    $backends = videoSyncResolveBackendUrls($pdo);

    if ($backends === [] || $secret === '') {
        return [
            'ok' => false,
            'message' => '请先配置视频切片后端地址与 API 密钥（可在「视频数据 API 同步」或「后端代理」中填写）',
        ];
    }

    $merged = [];
    $errors = [];
    foreach ($backends as $backend) {
        $result = fetchVideoSyncItemsFromSingleBackend($backend, $secret);
        if (empty($result['ok'])) {
            $errors[] = normalizePlayerBackendBaseUrl($backend) . '：' . (string)($result['message'] ?? '失败');
            continue;
        }
        foreach ($result['items'] ?? [] as $row) {
            if (!is_array($row)) {
                continue;
            }
            $recordId = (string)($row['record_id'] ?? '');
            if ($recordId === '') {
                continue;
            }
            $row['source_backend'] = (string)($result['backend'] ?? $backend);
            if (!isset($merged[$recordId])) {
                $merged[$recordId] = $row;
            }
        }
    }

    if ($merged === []) {
        return [
            'ok' => false,
            'message' => $errors !== []
                ? '所有视频后端拉取失败（' . implode('；', $errors) . '）'
                : '未获取到同步记录',
        ];
    }

    $map = videoSyncGetRecordMap($pdo);
    $items = [];
    foreach ($merged as $row) {
        $recordId = (string)($row['record_id'] ?? '');
        $videoId = isset($map[$recordId]) ? (int)$map[$recordId] : 0;
        $videoTitle = '';
        if ($videoId > 0) {
            $st = $pdo->prepare('SELECT title FROM videos WHERE id = ? LIMIT 1');
            $st->execute([$videoId]);
            $videoTitle = (string)($st->fetchColumn() ?: '');
        }
        $items[] = [
            'record_id' => $recordId,
            'title' => (string)($row['title'] ?? ''),
            'm3u8_url' => (string)($row['m3u8_url'] ?? ''),
            'cover_url' => (string)($row['cover_url'] ?? ''),
            'created_at' => (string)($row['created_at'] ?? ''),
            'duration_seconds' => (float)($row['duration_seconds'] ?? 0),
            'synced' => $videoId > 0,
            'video_id' => $videoId,
            'site_video_title' => $videoTitle,
            'source_backend' => (string)($row['source_backend'] ?? ''),
        ];
    }

    $message = null;
    if ($errors !== []) {
        $message = '部分后端拉取失败：' . implode('；', $errors);
    }

    return ['ok' => true, 'items' => $items, 'message' => $message];
}

/**
 * 手动将切片记录添加到网站（弹窗提交）
 *
 * @return array{ok:bool,message?:string,video_id?:int,episode_id?:int}
 */
function applyVideoSyncItemToSite(PDO $pdo, array $input): array
{
    $recordId = trim((string)($input['record_id'] ?? ''));
    $mode = trim((string)($input['mode'] ?? 'new'));
    $title = trim((string)($input['title'] ?? ''));
    $description = trim((string)($input['description'] ?? ''));
    $episodeName = trim((string)($input['episode_name'] ?? '1'));
    $m3u8Url = videoSyncNormalizeM3u8Path(
        (string)($input['m3u8_url'] ?? ''),
        getVideoDataSyncConfig($pdo)['path_prefix']
    );
    $coverUrl = videoSyncNormalizeCoverUrl(
        (string)($input['cover_url'] ?? ''),
        getVideoDataSyncConfig($pdo)['path_prefix'],
        $pdo
    );
    $existingVideoId = (int)($input['video_id'] ?? 0);

    if ($recordId === '') {
        return ['ok' => false, 'message' => '缺少记录 ID'];
    }
    if ($m3u8Url === '') {
        return ['ok' => false, 'message' => '缺少 m3u8 链接'];
    }
    if ($episodeName === '') {
        $episodeName = '1';
    }

    $hasSg = (bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'server_group_id'")->fetch();
    $syncCfg = getVideoDataSyncConfig($pdo);
    $serverGroupId = resolveVideoDefaultServerGroupId($pdo, $recordId, $syncCfg['server_group_id']);
    $map = videoSyncGetRecordMap($pdo);
    $videoId = isset($map[$recordId]) ? (int)$map[$recordId] : 0;

    if ($mode === 'existing') {
        if ($existingVideoId <= 0) {
            return ['ok' => false, 'message' => '请选择已有视频'];
        }
        $check = $pdo->prepare('SELECT id, title FROM videos WHERE id = ? LIMIT 1');
        $check->execute([$existingVideoId]);
        $row = $check->fetch();
        if (!$row) {
            return ['ok' => false, 'message' => '所选视频不存在'];
        }
        $videoId = (int)$row['id'];
        if ($title === '') {
            $title = (string)$row['title'];
        }
        if ($coverUrl !== '') {
            $pdo->prepare('UPDATE videos SET cover = ? WHERE id = ?')->execute([$coverUrl, $videoId]);
        }
    } else {
        if ($title === '') {
            return ['ok' => false, 'message' => '请填写视频名称'];
        }
        if ($videoId <= 0) {
            $cols = ['title', 'description', 'cover'];
            $vals = [$title, $description, $coverUrl !== '' ? $coverUrl : null];
            $place = ['?', '?', '?'];
            if ($hasSg && $serverGroupId !== null) {
                $cols[] = 'server_group_id';
                $vals[] = $serverGroupId;
                $place[] = '?';
            }
            $sql = 'INSERT INTO videos (' . implode(',', $cols) . ') VALUES (' . implode(',', $place) . ')';
            $pdo->prepare($sql)->execute($vals);
            $videoId = (int)$pdo->lastInsertId();
        } else {
            $sets = ['title = ?', 'description = ?', 'cover = ?'];
            $updateVals = [$title, $description, $coverUrl !== '' ? $coverUrl : null];
            if ($hasSg && $serverGroupId !== null) {
                $sets[] = 'server_group_id = ?';
                $updateVals[] = $serverGroupId;
            }
            $updateVals[] = $videoId;
            $pdo->prepare('UPDATE videos SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($updateVals);
        }
    }

    $map[$recordId] = $videoId;
    videoSyncSaveRecordMap($pdo, $map);

    $epStmt = $pdo->prepare(
        'SELECT id FROM video_episodes WHERE video_id = ? AND episode_name = ? LIMIT 1'
    );
    $epStmt->execute([$videoId, $episodeName]);
    $episodeRow = $epStmt->fetch();

    if ($episodeRow) {
        $episodeId = (int)$episodeRow['id'];
        $pdo->prepare('UPDATE video_episodes SET video_url = ? WHERE id = ?')
            ->execute([$m3u8Url, $episodeId]);
    } else {
        $orderStmt = $pdo->prepare('SELECT COALESCE(MAX(episode_order), 0) + 1 FROM video_episodes WHERE video_id = ?');
        $orderStmt->execute([$videoId]);
        $order = (int)$orderStmt->fetchColumn();
        $pdo->prepare(
            'INSERT INTO video_episodes (video_id, episode_name, video_url, episode_order) VALUES (?, ?, ?, ?)'
        )->execute([$videoId, $episodeName, $m3u8Url, $order]);
        $episodeId = (int)$pdo->lastInsertId();
    }

    return [
        'ok' => true,
        'message' => '已添加到网站',
        'video_id' => $videoId,
        'episode_id' => $episodeId,
    ];
}
