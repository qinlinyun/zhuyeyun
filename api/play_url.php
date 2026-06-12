<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/traffic.php';
require_once __DIR__ . '/../includes/player_proxy.php';
require_once __DIR__ . '/../includes/player_config.php';
require_once __DIR__ . '/../includes/play_domains.php';
require_once __DIR__ . '/../includes/player_video_token.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    echo json_encode(['ok' => false, 'message' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = getCurrentUser();
if (!$user || !canUseSiteFeatures($user)) {
    echo json_encode(['ok' => false, 'message' => '账户不可用'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();

if (!isPlayerProxyEnabled($pdo)) {
    echo json_encode(['ok' => false, 'message' => '后端代理未开启'], JSON_UNESCAPED_UNICODE);
    exit;
}

$proxyCfg = getPlayerProxyConfig($pdo);
$videoId = (int)($_GET['video_id'] ?? $_POST['video_id'] ?? 0);
$episodeId = (int)($_GET['episode_id'] ?? $_POST['episode_id'] ?? 0);
$domainId = (int)($_GET['domain_id'] ?? $_POST['domain_id'] ?? 0);

if ($videoId <= 0 || $episodeId <= 0 || $domainId <= 0) {
    echo json_encode(['ok' => false, 'message' => '参数错误'], JSON_UNESCAPED_UNICODE);
    exit;
}

$st = $pdo->prepare('SELECT * FROM videos WHERE id = ? LIMIT 1');
$st->execute([$videoId]);
$video = $st->fetch();
if (!$video) {
    echo json_encode(['ok' => false, 'message' => '视频不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (videoSkipsBackendProxy($pdo, $video)) {
    $message = (int)($video['uploaded_by'] ?? 0) > 0
        ? '用户上传视频走本站播放（自签 Token 或 CDN 直链），无需切片后端代理'
        : '该视频已设置为直链播放，无需申请代理链接';
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!isAdmin()) {
    $trafficEnabled = trafficFeatureEnabled($pdo);
    if ($trafficEnabled && !empty($video['is_traffic'])) {
        $unlockInfo = getVideoUnlockStatus($pdo, (int)$user['id'], $video);
        if (empty($unlockInfo['unlocked']) && !trafficAllowsTrialWatch($unlockInfo)) {
            echo json_encode(['ok' => false, 'message' => '请先解锁该视频'], JSON_UNESCAPED_UNICODE);
            exit;
        }
    }
}

$st = $pdo->prepare('SELECT * FROM video_episodes WHERE id = ? AND video_id = ? LIMIT 1');
$st->execute([$episodeId, $videoId]);
$episode = $st->fetch();
if (!$episode) {
    echo json_encode(['ok' => false, 'message' => '集数不存在'], JSON_UNESCAPED_UNICODE);
    exit;
}

$st = $pdo->prepare('SELECT domain FROM domains WHERE id = ? LIMIT 1');
$st->execute([$domainId]);
$domainRow = $st->fetch();
if (!$domainRow || trim((string)$domainRow['domain']) === '') {
    echo json_encode(['ok' => false, 'message' => '线路无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

if (!play_domain_allowed_for_playback($pdo, $user, $video, $domainId, isAdmin())) {
    echo json_encode(['ok' => false, 'message' => '无权使用该线路'], JSON_UNESCAPED_UNICODE);
    exit;
}

$relativePath = playerProxyNormalizeStoragePath((string)$episode['video_url']);
$playHost = playerProxyNormalizePlayHost((string)$domainRow['domain']);

if ($relativePath === '' || $playHost === '') {
    echo json_encode(['ok' => false, 'message' => '播放路径或线路无效'], JSON_UNESCAPED_UNICODE);
    exit;
}

$email = trim((string)($user['email'] ?? ''));
if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    if (isAdmin()) {
        $host = preg_replace('/[^a-zA-Z0-9.-]/', '', (string)($_SERVER['HTTP_HOST'] ?? 'localhost'));
        $host = $host !== '' ? $host : 'localhost';
        $email = 'admin@' . $host;
    } else {
        echo json_encode(['ok' => false, 'message' => '用户邮箱无效，无法申请播放链接'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

$ttlOpts = resolvePlayTokenTtl($pdo, (int)$proxyCfg['token_ttl']);

$result = fetchSignedPlayUrlFromAnyBackend(
    $proxyCfg['backend_urls'],
    $proxyCfg['api_secret'],
    $email,
    $relativePath,
    $playHost,
    (int)$ttlOpts['ttl'],
    !empty($ttlOpts['auto_ttl'])
);

if (!$result['ok'] || empty($result['play_url'])) {
    echo json_encode([
        'ok' => false,
        'message' => $result['message'] ?? '获取播放链接失败',
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

$mime = $result['mime'] ?? 'video/mp4';
if (preg_match('/\.m3u8(\?.*)?$/i', $result['play_url'])) {
    $mime = 'application/x-mpegURL';
}

echo json_encode([
    'ok' => true,
    'play_url' => $result['play_url'],
    'mime' => $mime,
    'expires_at' => (int)($result['expires_at'] ?? 0),
], JSON_UNESCAPED_UNICODE);
