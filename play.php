<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/traffic.php';
require_once __DIR__ . '/includes/video_click_analytics.php';
require_once __DIR__ . '/includes/player_proxy.php';
require_once __DIR__ . '/includes/player_config.php';
require_once __DIR__ . '/includes/player_video_token.php';
require_once __DIR__ . '/includes/site_play_token.php';
require_once __DIR__ . '/includes/play_domains.php';
require_once __DIR__ . '/includes/user_profile.php';
require_once __DIR__ . '/includes/comments.php';

requireLogin();
$user = getCurrentUser();

$videoId = $_GET['id'] ?? 0;
if (!$videoId) { header('Location: index.php'); exit; }

$pdo = getDB();
ensureUserProfileSchema($pdo);
ensureCommentsSchema($pdo);

/* 视频信息 */
$stmt = $pdo->prepare("SELECT * FROM videos WHERE id = ?");
$stmt->execute([$videoId]);
$video = $stmt->fetch();
if (!$video) { header('Location: index.php'); exit; }

if (!isAdmin()) {
    recordVideoClick((int)$video['id']);
}

$publisherUser = null;
$publisherVideos = [];
$publisherVideoCount = 0;
$publisherClickCount = 0;
$publisherIsAdmin = empty($video['uploaded_by']);
if (!$publisherIsAdmin) {
    $publisherUser = fetchUserById($pdo, (int)$video['uploaded_by']);
    if ($publisherUser) {
        $publisherVideos = fetchUserUploadedVideos($pdo, (int)$publisherUser['id']);
        $publisherVideoCount = count($publisherVideos);
        $publisherClickCount = countUserUploadedVideoClicks($pdo, $publisherVideos);
    } else {
        $publisherIsAdmin = true;
    }
}
$adminAvatarPath = __DIR__ . '/tx.jpg';
$adminAvatarUrl = is_file($adminAvatarPath) ? 'tx.jpg' : '';

/* === 流量视频解锁检测 === */
$trafficEnabled = trafficFeatureEnabled($pdo);
$videoIsTraffic = $trafficEnabled && !empty($video['is_traffic']);
$videoUnlocked = true;
$trafficTrialMode = false;
$unlockInfo = ['need_pay' => false];
$userTraffic = ['total'=>0,'used'=>0,'left'=>0,'expires_at'=>null,'expired'=>false];
if ($trafficEnabled) {
    // getUserTraffic 内部已经会触发到期自动重置
    $userTraffic = getUserTraffic($pdo, (int)$user['id']);
}

if ($videoIsTraffic && !isAdmin()) {
    // 自动重置可能在上面已经清掉解锁记录，这里再判断会得到最新状态
    $unlockInfo = getVideoUnlockStatus($pdo, (int)$user['id'], $video);
    $videoUnlocked = !empty($unlockInfo['unlocked']);
    $trafficTrialMode = trafficAllowsTrialWatch($unlockInfo);
} elseif (isAdmin()) {
    $videoUnlocked = true;
}

/* 集数 */
$stmt = $pdo->prepare("SELECT * FROM video_episodes WHERE video_id = ? ORDER BY episode_order");
$stmt->execute([$videoId]);
$episodes = $stmt->fetchAll();

/* 播放线路：均遵守用户组/服务器组分配；视频指定服务器组时管理员可用该组下全部线路 */
$playDomains = play_domains_for_playback($pdo, $user, $video, isAdmin());

/* 当前域名 */
$currentDomainId = isset($_GET['domain_id']) && $_GET['domain_id'] !== '' ? (int)$_GET['domain_id'] : 0;
if ($currentDomainId <= 0) {
    $currentDomainId = null;
}
if ($currentDomainId === null && !empty($playDomains)) {
    $currentDomainId = (int)$playDomains[0]['id'];
}

$currentDomain = null;
if ($currentDomainId) {
    $stmt = $pdo->prepare('SELECT * FROM domains WHERE id = ?');
    $stmt->execute([$currentDomainId]);
    $currentDomain = $stmt->fetch();
}

if (!empty($playDomains)) {
    $allowedIds = array_map('intval', array_column($playDomains, 'id'));
    if (!$currentDomain || !in_array((int)$currentDomain['id'], $allowedIds, true)) {
        $currentDomain = $playDomains[0];
        $currentDomainId = (int)$currentDomain['id'];
    }
}

/* 当前集 */
$episodeId = $_GET['episode'] ?? ($episodes[0]['id'] ?? 0);
$currentEpisode = null;
foreach ($episodes as $ep) {
    if ($ep['id'] == $episodeId) {
        $currentEpisode = $ep;
        break;
    }
}
if (!$currentEpisode && !empty($episodes)) {
    $currentEpisode = $episodes[0];
    $episodeId = $currentEpisode['id'];
}

/* 播放地址 + MIME（未解锁的流量视频可试看 3%） */
$usePlayerProxy = shouldUsePlayerProxyForVideo($pdo, $video);
$playerConfig = getPlayerConfig($pdo);
$playerEngine = $playerConfig['engine'] ?? 'videojs';
$canPlay = $currentEpisode && $currentDomain && ($videoUnlocked || $trafficTrialMode);
$useSitePlayToken = $canPlay && !$usePlayerProxy && videoShouldUseSitePlayToken($pdo, $video);
$playUrl = '';
$mime = 'video/mp4';
if ($canPlay && !$usePlayerProxy) {
    $relativePath = ltrim((string)$currentEpisode['video_url'], '/');
    if ($useSitePlayToken) {
        $playUrl = buildSitePlayMediaUrl(
            $pdo,
            (int)$user['id'],
            (int)$videoId,
            (int)$currentEpisode['id'],
            (int)$currentDomainId,
            $relativePath
        );
    } else {
        $path = '/' . $relativePath;
        $playUrl = 'https://' . rtrim($currentDomain['domain'], '/') . $path;
    }

    if (preg_match('/\.m3u8(\?.*)?$/i', $playUrl)) {
        $mime = 'application/x-mpegURL';
    }
} elseif ($canPlay && $usePlayerProxy) {
    $path = ltrim((string)$currentEpisode['video_url'], '/');
    if (preg_match('/\.m3u8(\?.*)?$/i', $path)) {
        $mime = 'application/x-mpegURL';
    }
}

/* 从观看进度页面跳转过来的定位时间 t=秒（优先） */
$jumpT = (int)($_GET['t'] ?? 0);
if ($jumpT < 0) $jumpT = 0;

/* 各集 × 各线路直链（无后端代理时供前端切集/切线） */
$episodeSources = [];
if ($canPlay && !$usePlayerProxy) {
    foreach ($episodes as $ep) {
        $epId = (int)$ep['id'];
        $relativePath = ltrim((string)$ep['video_url'], '/');
        if ($relativePath === '') {
            continue;
        }
        foreach ($playDomains as $d) {
            $dId = (int)$d['id'];
            if ($useSitePlayToken) {
                $src = buildSitePlayMediaUrl(
                    $pdo,
                    (int)$user['id'],
                    (int)$videoId,
                    $epId,
                    $dId,
                    $relativePath
                );
            } else {
                $path = '/' . $relativePath;
                $src = 'https://' . rtrim((string)$d['domain'], '/') . $path;
            }
            $episodeSources[$epId][$dId] = [
                'src' => $src,
                'type' => preg_match('/\.m3u8(\?.*)?$/i', $src) ? 'application/x-mpegURL' : 'video/mp4',
            ];
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN" class="scroll-smooth">
<head>
<link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?=htmlspecialchars($video['title'])?> - 播放</title>
<?php include __DIR__ . '/components/theme-head.php'; ?>
<link rel="stylesheet" href="assets/css/video-comments.css?v=2">

<!-- 你原来的静态资源：不动 -->


<!-- ✅ video.js CSS（Video.js 模式） -->
<?php if ($playerEngine === 'videojs'): ?>
<link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/video.js/8.16.1/video-js.min.css">
<?php elseif ($playerEngine === 'dplayer'): ?>
<link rel="stylesheet" href="https://cdn.bootcdn.net/ajax/libs/dplayer/1.24.0/DPlayer.min.css">
<?php endif; ?>

<style>
/* ================= 选集暗色 ================= */
.dark .episode-item{
    color:#e5e7eb;
    border-color:#334155;
}
.dark .episode-item:hover{
    background:rgba(51,65,85,.4);
}
.dark .episode-active{
    background:rgba(239,68,68,.15);
    color:#ef4444;
}

/* ================= 动画 ================= */
.fade-up{
    opacity:0;
    transform: translateY(24px);
    transition: .6s ease;
}
.fade-up.show{
    opacity:1;
    transform:none;
}

/* 播放区：16:9 外框，画面 cover 铺满（移动端无大块黑边） */
.player-stage{
    position:relative;
    width:100%;
    aspect-ratio:16/9;
    max-height:min(56.25vw, 72vh);
    margin:0 auto;
    background:#000;
    overflow:hidden;
}
.player-stage .player-layer{
    position:absolute;
    inset:0;
    width:100%;
    height:100%;
}

/* Video.js */
.player-stage .video-js{
    position:absolute !important;
    inset:0 !important;
    width:100% !important;
    height:100% !important;
    padding-top:0 !important;
    background:#000 !important;
}
.player-stage .video-js .vjs-tech,
.player-stage .video-js video{
    position:absolute !important;
    inset:0 !important;
    width:100% !important;
    height:100% !important;
    object-fit:cover;
    object-position:center center;
}
.player-stage .video-js .vjs-poster{
    background-size:cover;
    background-position:center;
}
.player-stage .video-js .vjs-control-bar{
    display:flex !important;
    background:linear-gradient(transparent, rgba(0,0,0,.75)) !important;
}
.player-stage .video-js .vjs-progress-control{ display:flex !important; }
.player-stage .video-js .vjs-slider{ display:block !important; }
/* DPlayer */
.player-stage #dplayerContainer,
.player-stage #dplayerContainer .dplayer{
    width:100% !important;
    height:100% !important;
    overflow:hidden;
}
.player-stage #xgplayerContainer{
    width:100% !important;
    height:100% !important;
    overflow:hidden;
}
.player-stage .dplayer-video-wrap{
    position:absolute !important;
    inset:0 !important;
    width:100% !important;
    height:100% !important;
    padding:0 !important;
}
.player-stage .dplayer-video,
.player-stage .dplayer-video-current{
    width:100% !important;
    height:100% !important;
}
.player-stage .dplayer-video video{
    width:100% !important;
    height:100% !important;
    object-fit:cover;
    object-position:center center;
}
.player-stage .dplayer-controller{
    position:absolute !important;
    left:0 !important;
    right:0 !important;
    bottom:0 !important;
    z-index:3;
    display:flex !important;
    flex-direction:row !important;
    align-items:center !important;
    flex-wrap:nowrap !important;
    box-sizing:border-box !important;
    height:44px !important;
    padding:0 6px 4px !important;
    background:linear-gradient(transparent, rgba(0,0,0,.75)) !important;
    font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,"Helvetica Neue",Arial,sans-serif !important;
}
.player-stage .dplayer-controller .dplayer-icons-left,
.player-stage .dplayer-controller .dplayer-icons-right,
.player-stage .dplayer-controller .dplayer-bar-wrap{
    position:static !important;
    top:auto !important;
    bottom:auto !important;
    left:auto !important;
    right:auto !important;
    transform:none !important;
}
.player-stage .dplayer-controller .dplayer-icons-left,
.player-stage .dplayer-controller .dplayer-icons-right{
    display:flex !important;
    align-items:center !important;
    flex:0 0 auto !important;
    height:auto !important;
}
.player-stage .dplayer-controller .dplayer-icons-left{
    flex-shrink:0;
    max-width:38%;
    order:1 !important;
}
.player-stage .dplayer-controller .dplayer-bar-wrap{
    flex:1 1 auto !important;
    min-width:40px !important;
    width:auto !important;
    margin:0 6px !important;
    padding:0 !important;
    order:2 !important;
}
.player-stage .dplayer-controller .dplayer-bar{
    position:relative !important;
    left:auto !important;
    right:auto !important;
    width:100% !important;
    height:3px !important;
}
.player-stage .dplayer-controller .dplayer-icons-right{
    flex:0 0 auto !important;
    min-width:0;
    justify-content:flex-end;
    margin-left:0;
    order:3 !important;
}
.player-stage .dplayer-controller .dplayer-time,
.player-stage .dplayer-controller .dplayer-ptime,
.player-stage .dplayer-controller .dplayer-dtime{
    font-size:11px !important;
    line-height:1.2 !important;
    font-variant-numeric:tabular-nums;
    white-space:nowrap;
}
.player-stage .dplayer-controller .dplayer-icon{
    width:32px !important;
    height:32px !important;
    line-height:32px !important;
}
.player-stage .dplayer-controller .dplayer-icons-left{
    align-items:center !important;
    flex-wrap:nowrap !important;
    overflow:visible !important;
}
.player-stage .dplayer-controller .dplayer-volume{
    display:inline-flex !important;
    align-items:center !important;
    height:32px !important;
    line-height:32px !important;
    flex:0 0 auto !important;
    overflow:visible !important;
}
.player-stage .dplayer-controller .dplayer-volume .dplayer-icon{
    display:inline-flex !important;
    align-items:center !important;
    justify-content:center !important;
    flex:0 0 32px !important;
}
.player-stage .dplayer-controller .dplayer-volume-bar-wrap{
    display:inline-flex !important;
    align-items:center !important;
    height:32px !important;
    margin:0 6px 0 -2px !important;
    vertical-align:middle !important;
    overflow:hidden !important;
}
.player-stage .dplayer-controller .dplayer-volume-bar{
    position:relative !important;
    top:auto !important;
    height:3px !important;
    margin:0 !important;
}
.player-stage .dplayer-controller .dplayer-volume-bar-inner{
    top:0 !important;
    height:3px !important;
}
.player-stage .dplayer-notice,
.player-stage .dplayer-info-panel{ max-width:100%; }
/* DPlayer 控制栏：线路 / 选集 */
.player-stage .dplayer-controller,
.player-stage .dplayer-icons-right,
.player-stage .dp-bar-tool{
    overflow:visible !important;
}
.player-stage .dplayer-icons-right{
    display:flex !important;
    align-items:center;
    gap:4px;
    flex-wrap:nowrap;
}
.player-stage .dp-bar-tools{
    display:inline-flex;
    align-items:center;
    gap:5px;
    margin-right:0;
    flex-shrink:0;
    order:1;
}
.player-stage .dp-bar-system{
    display:inline-flex;
    align-items:center;
    gap:2px;
    flex-shrink:0;
    order:2;
}
.player-stage .dplayer-icons-right > .dplayer-full{
    order:3;
    flex-shrink:0;
}
.player-stage .dp-bar-tool{
    position:relative;
    height:26px;
    display:inline-flex;
    align-items:center;
}
.player-stage .dp-bar-tool-btn{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:5px;
    min-width:48px;
    max-width:64px;
    height:24px;
    padding:0 8px;
    border-radius:999px;
    border:1px solid rgba(255,255,255,.18);
    background:linear-gradient(180deg, rgba(255,255,255,.18), rgba(255,255,255,.07));
    color:rgba(255,255,255,.96);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.16), 0 6px 16px rgba(0,0,0,.18);
    backdrop-filter:blur(10px);
    -webkit-backdrop-filter:blur(10px);
    font-size:11px;
    font-weight:600;
    line-height:1.2;
    white-space:nowrap;
    cursor:pointer;
    transition:background .18s ease, border-color .18s ease, transform .18s ease, box-shadow .18s ease;
}
.player-stage .dp-bar-tool-btn:hover,
.player-stage .dp-bar-tool.is-open .dp-bar-tool-btn{
    transform:translateY(-1px);
    background:linear-gradient(180deg, rgba(255,255,255,.28), rgba(255,255,255,.12));
    border-color:rgba(255,255,255,.36);
    box-shadow:inset 0 1px 0 rgba(255,255,255,.22), 0 8px 20px rgba(0,0,0,.24);
}
.player-stage .dp-bar-tool-btn svg{
    width:9px;
    height:9px;
    opacity:.72;
    flex-shrink:0;
    transition:transform .18s ease;
}
.player-stage .dp-bar-tool.is-open .dp-bar-tool-btn svg{
    transform:rotate(180deg);
}
.player-stage .dp-bar-tool-menu{
    position:absolute;
    right:0;
    bottom:32px;
    min-width:132px;
    max-width:180px;
    max-height:min(260px, 46vh);
    overflow-x:hidden;
    overflow-y:auto;
    padding:6px;
    border-radius:13px;
    border:1px solid rgba(255,255,255,.16);
    background:linear-gradient(180deg, rgba(17,24,39,.96), rgba(3,7,18,.94));
    box-shadow:0 18px 42px rgba(0,0,0,.46), inset 0 1px 0 rgba(255,255,255,.08);
    backdrop-filter:blur(14px);
    -webkit-backdrop-filter:blur(14px);
    z-index:40;
    box-sizing:border-box;
}
.player-stage .dp-bar-tool-menu.hidden{ display:none; }
.player-stage .dp-bar-tool-menu::-webkit-scrollbar{ width:4px; }
.player-stage .dp-bar-tool-menu::-webkit-scrollbar-thumb{
    border-radius:999px;
    background:rgba(255,255,255,.22);
}
.player-stage .dp-bar-tool-item{
    display:flex;
    align-items:center;
    width:100%;
    min-height:30px;
    padding:7px 10px;
    border:none;
    border-radius:9px;
    background:transparent;
    color:rgba(229,231,235,.92);
    font-size:12px;
    line-height:1.25;
    text-align:left;
    cursor:pointer;
    white-space:nowrap;
    overflow:hidden;
    text-overflow:ellipsis;
    box-sizing:border-box;
}
.player-stage .dp-bar-tool-item:hover{
    background:rgba(255,255,255,.09);
    color:#fff;
}
.player-stage .dp-bar-tool-item.is-active{
    background:linear-gradient(135deg, rgba(239,68,68,.38), rgba(249,115,22,.24));
    color:#fff;
    font-weight:600;
    box-shadow:inset 0 0 0 1px rgba(255,255,255,.08);
}
/* DPlayer — 手机竖屏内嵌：隐藏栏内线路/选集，用页面下方切换 */
@media (max-width:768px){
    .player-stage .dplayer:not(.dplayer-full) .dp-bar-tools{
        display:none !important;
    }
    .player-stage .dplayer:not(.dplayer-full) .dplayer-controller{
        height:38px !important;
        padding:0 4px 2px !important;
    }
    .player-stage .dplayer:not(.dplayer-full) .dplayer-controller .dplayer-icons-left{
        max-width:46%;
    }
    .player-stage .dplayer:not(.dplayer-full) .dplayer-controller .dplayer-bar-wrap{
        flex:1 1 0 !important;
        min-width:36px !important;
        margin:0 4px !important;
    }
    .player-stage .dplayer:not(.dplayer-full) .dplayer-controller .dplayer-time,
    .player-stage .dplayer:not(.dplayer-full) .dplayer-controller .dplayer-ptime,
    .player-stage .dplayer:not(.dplayer-full) .dplayer-controller .dplayer-dtime{
        font-size:10px !important;
    }
    .player-stage .dplayer:not(.dplayer-full) .dplayer-controller .dplayer-icon{
        width:28px !important;
        height:28px !important;
        line-height:28px !important;
    }
    .player-stage .dplayer:not(.dplayer-full) .dplayer-controller .dplayer-volume{
        height:28px !important;
        line-height:28px !important;
    }
    .player-stage .dplayer:not(.dplayer-full) .dplayer-controller .dplayer-volume .dplayer-icon{
        flex-basis:28px !important;
    }
    .player-stage .dplayer:not(.dplayer-full) .dplayer-controller .dplayer-volume-bar-wrap{
        height:28px !important;
        margin:0 4px 0 -2px !important;
    }
    .player-stage .dplayer:not(.dplayer-full) .dplayer-icons-right{
        gap:0;
        flex-shrink:0;
    }
}
/* DPlayer — 全屏（横屏空间更大） */
.player-stage .dplayer.dplayer-full{
    width:100vw !important;
    height:100vh !important;
    max-width:none !important;
    max-height:none !important;
}
.player-stage .dplayer.dplayer-full .dplayer-controller{
    height:52px !important;
    padding:0 14px 10px !important;
}
.player-stage .dplayer.dplayer-full .dplayer-controller .dplayer-icons-left{
    max-width:none;
}
.player-stage .dplayer.dplayer-full .dplayer-controller .dplayer-bar-wrap{
    margin:0 12px !important;
    min-width:80px !important;
}
.player-stage .dplayer.dplayer-full .dplayer-controller .dplayer-icon{
    width:36px !important;
    height:36px !important;
    line-height:36px !important;
}
.player-stage .dplayer.dplayer-full .dp-bar-tools{
    display:inline-flex !important;
    gap:8px;
}
.player-stage .dplayer.dplayer-full .dp-bar-system{
    gap:4px;
}
.player-stage .dplayer.dplayer-full .dp-bar-tool-btn{
    min-width:52px;
    max-width:72px;
    height:28px;
    padding:0 10px;
    font-size:12px;
}
.player-stage .dplayer.dplayer-full .dp-bar-tool-menu{
    bottom:40px;
    min-width:140px;
    max-width:min(70vw, 240px);
}
</style>
<?php include __DIR__ . '/components/theme-dynamic.php'; ?>
</head>

<body class="bg-gray-100 text-gray-900">

<nav class="bg-white shadow-sm sticky top-0 z-50">
<div class="max-w-screen-xl mx-auto px-4 py-3 flex justify-between items-center">

<!-- 左侧导航 -->
<div class="flex items-center gap-4 text-sm">

<?php if (isAdmin()): ?>
    <a href="/" class="font-semibold">前台首页</a>
    <?php $href = 'admin/groups.php'; include __DIR__ . '/components/admin-groups-nav-link.php'; ?>
    <?php $href = 'admin/users.php'; include __DIR__ . '/components/admin-users-nav-link.php'; ?>
    <?php $href = 'admin/videos.php'; include __DIR__ . '/components/admin-videos-nav-link.php'; ?>
    <?php $href = 'admin/domains.php'; $navLinkLabel = '线路管理'; include __DIR__ . '/components/admin-domains-nav-link.php'; ?>
<?php else: ?>
    <!-- 首页 -->
        <a href="index.php" class="group rounded-full p-2 hover:bg-gray-100">
            <svg class="w-5 h-5 text-gray-500 group-hover:text-gray-900 transition"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                      d="M3 10l9-7 9 7v10a2 2 0 01-2 2h-4v-6H9v6H5a2 2 0 01-2-2z"/>
            </svg>
        </a>
        <!-- 用户 -->
        <a href="upload.php" class="group relative rounded-full p-2 hover:bg-gray-100/50 inline-flex items-center justify-center">
            <svg class="w-5 h-5 text-gray-500 group-hover:text-gray-900 transition" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0l-4 4m4-4l4 4M4 20h16"/>
            </svg>
            <span class="absolute top-full mt-1 hidden md:block text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 whitespace-nowrap">视频上传</span>
        </a>

        <a href="user_home.php" class="group rounded-full p-2 hover:bg-gray-100 inline-flex items-center justify-center">
            <?php include __DIR__ . '/components/user-avatar.php'; ?>
        </a>

    <a href="/progress.php" class="group relative rounded-full p-2 hover:bg-gray-100/50">
<svg class="nav-icon w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
<circle cx="12" cy="12" r="9" stroke-width="1.8"/>
<path d="M12 6v6l4 2" stroke-width="1.8" stroke-linecap="round"/>
</svg>
<span class="absolute top-full mt-1 hidden md:block text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100">观看记录</span>
</a>
<?php endif; ?>

    <?php include __DIR__ . '/components/logout-nav-link.php'; ?>
</div>

<!-- 右侧 -->
<div class="flex items-center gap-3">
    <?php if (isAdmin()): ?>
        <span class="text-xs text-gray-500">管理员</span>
    <?php endif; ?>
    <?php include __DIR__ . '/components/theme-toggle.php'; ?>
</div>

</div>
</nav>

<main class="max-w-screen-xl mx-auto px-4 py-6 fade-up">
<div class="grid grid-cols-1 lg:grid-cols-3 gap-6">

<!-- 左侧播放器 -->
<section class="lg:col-span-2 space-y-4">
<div class="bg-white rounded shadow p-4 fade-up">
<div id="playerStage" class="player-stage rounded">
<?php if ($canPlay): ?>
<div id="playUrlLoading" class="player-layer <?= $usePlayerProxy ? 'flex items-center justify-center text-white text-sm' : 'hidden' ?>">
    正在获取播放链接…
</div>
<div id="playUrlError" class="player-layer hidden flex items-center justify-center text-red-300 text-sm px-4 text-center"></div>
<?php if ($playerEngine === 'dplayer'): ?>
<div id="dplayerContainer"
    class="player-layer <?= $usePlayerProxy ? 'hidden' : '' ?>"
    <?php if (!$usePlayerProxy && $playUrl): ?>
    data-play-src="<?= htmlspecialchars($playUrl, ENT_QUOTES, 'UTF-8') ?>"
    data-play-type="<?= htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') ?>"
    <?php endif; ?>
></div>
<?php elseif ($playerEngine === 'xgplayer'): ?>
<div id="xgplayerContainer"
    class="player-layer <?= $usePlayerProxy ? 'hidden' : '' ?>"
    <?php if (!$usePlayerProxy && $playUrl): ?>
    data-play-src="<?= htmlspecialchars($playUrl, ENT_QUOTES, 'UTF-8') ?>"
    data-play-type="<?= htmlspecialchars($mime, ENT_QUOTES, 'UTF-8') ?>"
    <?php endif; ?>
></div>
<?php else: ?>
<video
    id="videoPlayer"
    class="video-js vjs-default-skin player-layer <?= $usePlayerProxy ? 'hidden' : '' ?>"
    controls
    autoplay
    preload="auto"
    playsinline
    controlsList="nodownload"
    disablePictureInPicture
    oncontextmenu="return false;"
    <?php if (!$usePlayerProxy && $playUrl): ?>
    data-src="<?=htmlspecialchars($playUrl)?>"
    data-type="<?=htmlspecialchars($mime)?>"
    <?php endif; ?>
></video>
<?php endif; ?>
<?php if ($trafficTrialMode): ?>
<div id="trafficTrialBadge" class="pointer-events-none absolute left-2 top-2 z-20 rounded bg-black/65 px-2 py-0.5 text-[11px] font-medium text-amber-200">
    试看中 · 仅可观看 <?= (int)trafficTrialPercent() ?>%
</div>
<div id="trafficTrialEndedTip" class="pointer-events-none absolute inset-x-0 bottom-14 z-20 hidden px-3 text-center">
    <span class="inline-block rounded-lg bg-black/80 px-4 py-2 text-xs font-medium text-amber-200 shadow-lg">
        试看已结束，点击播放可重新试看
    </span>
</div>
<?php endif; ?>
<?php else: ?>
<div class="flex h-full items-center justify-center text-white">暂无可用视频源</div>
<?php endif; ?>
</div>
<?php if ($trafficTrialMode): ?>
<div id="trafficStatusBar" class="mt-2 text-xs text-amber-700">试看模式：可免费观看前 <?= (int)trafficTrialPercent() ?>%，解锁后可观看完整内容</div>
<?php elseif ($videoIsTraffic && $videoUnlocked && !isAdmin()): ?>
<div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-amber-700">
    <?php if (!empty($unlockInfo['is_owner'])): ?>
    <span class="rounded-full bg-green-100 px-2 py-0.5 text-green-800">您是上传者，可直接观看</span>
    <?php else: ?>
    <span class="rounded-full bg-amber-100 px-2 py-0.5">流量视频已解锁</span>
    <span>有效期：跟随流量周期</span>
    <?php endif; ?>
    <?php if (!empty($userTraffic['next_reset_at'])): ?>
        <span class="text-gray-500">下次自动重置：<?= htmlspecialchars($userTraffic['next_reset_at']) ?></span>
    <?php elseif (!empty($userTraffic['expires_at'])): ?>
        <span class="text-gray-500">流量到期：<?= htmlspecialchars($userTraffic['expires_at']) ?></span>
    <?php endif; ?>
</div>
<?php endif; ?>
</div>

<div class="bg-white rounded shadow p-4 fade-up">
<div class="flex flex-wrap items-center justify-between gap-3">
    <h1 class="min-w-0 flex-1 text-lg font-semibold"><?=htmlspecialchars($video['title'])?></h1>
    <?php if ($trafficTrialMode): ?>
    <button type="button" id="trafficBuyBtn"
            class="shrink-0 rounded-lg bg-amber-500 px-4 py-2 text-sm font-semibold text-white shadow hover:bg-amber-600">
        购买视频
    </button>
    <?php endif; ?>
</div>
<?php if ($video['description']): ?>
<p class="mt-3 text-sm leading-7"><?=nl2br(htmlspecialchars($video['description']))?></p>
<?php endif; ?>
</div>

<div id="videoCommentsRoot" class="bg-white rounded shadow p-4 fade-up video-comments">
    <div class="video-comments__head">
        <h2 class="text-base font-semibold">评论区</h2>
        <span class="video-comments__count" data-comments-count>加载中...</span>
    </div>
    <form class="video-comments__form" data-main-comment-form>
        <?php
        $imgClass = 'video-comments__avatar';
        $svgClass = 'video-comments__avatar video-comments__avatar--svg';
        include __DIR__ . '/components/user-avatar.php';
        ?>
        <div class="video-comments__input-wrap">
            <textarea class="video-comments__textarea" maxlength="<?= (int)COMMENT_MAX_LENGTH ?>" placeholder="写下你的看法，友善交流..." required></textarea>
            <div class="video-comments__actions">
                <span class="video-comments__hint">最多 <?= (int)COMMENT_MAX_LENGTH ?> 字 · 支持回复</span>
                <button type="submit" class="video-comments__submit">发表评论</button>
            </div>
        </div>
    </form>
    <div data-comments-list><div class="video-comments__loading">加载评论中...</div></div>
    <div class="video-comments__pager" data-comments-pager hidden></div>
</div>
<div id="videoCommentsToast" class="video-comments__toast" hidden aria-live="polite"></div>
</section>

<!-- 右侧 -->
<aside class="space-y-4 fade-up">

<!-- 线路切换 -->
<?php if (!empty($playDomains)): ?>
<div class="bg-white rounded shadow">
<div class="border-b px-4 py-3 text-sm font-semibold">线路切换-(播放卡顿时切换线路)</div>
<div class="p-4">
<select class="w-full rounded border px-3 py-2 text-sm"
onchange="switchDomain(this.value)">
<?php foreach ($playDomains as $d): ?>
<option value="<?=$d['id']?>" <?=$d['id']==$currentDomainId?'selected':''?>>
<?=htmlspecialchars($d['display_name'] ?: ('线路'.$d['id']))?>
</option>
<?php endforeach; ?>
</select>
</div>
</div>
<?php endif; ?>

<!-- 视频发布者 -->
<div class="bg-white rounded shadow">
<div class="flex justify-between items-center border-b px-4 py-3">
<h3 class="text-sm font-semibold">视频发布者</h3>
<?php if (!$publisherIsAdmin && $publisherUser): ?>
<a href="user_home.php?id=<?= (int)$publisherUser['id'] ?>" class="text-xs text-red-600 hover:underline">查看主页</a>
<?php endif; ?>
</div>
<div class="p-4">
<div class="flex items-center gap-3">
<?php if ($publisherIsAdmin): ?>
    <?php if ($adminAvatarUrl): ?>
        <img src="<?= htmlspecialchars($adminAvatarUrl) ?>" alt="" class="h-12 w-12 rounded-full border border-gray-200 object-cover bg-gray-50">
    <?php else: ?>
        <?php $publisherAvatarUser = ['avatar' => null]; $userBak = $user; $user = $publisherAvatarUser; $imgClass = 'h-12 w-12 rounded-full border border-gray-200 object-cover bg-gray-50'; $svgClass = 'h-12 w-12 shrink-0'; include __DIR__ . '/components/user-avatar.php'; $user = $userBak; ?>
    <?php endif; ?>
    <div class="min-w-0">
        <div class="truncate text-sm font-semibold text-gray-900">管理员</div>
        <div class="mt-0.5 text-xs text-gray-500">官方发布</div>
    </div>
<?php else: ?>
    <?php $publisherAvatarUser = $publisherUser; $userBak = $user; $user = $publisherAvatarUser; $imgClass = 'h-12 w-12 rounded-full border border-gray-200 object-cover bg-gray-50'; $svgClass = 'h-12 w-12 shrink-0'; include __DIR__ . '/components/user-avatar.php'; $user = $userBak; ?>
    <div class="min-w-0">
        <div class="truncate text-sm font-semibold text-gray-900"><?= htmlspecialchars(userDisplayName($publisherUser)) ?></div>
        <div class="mt-0.5 text-xs text-gray-500">@<?= htmlspecialchars((string)$publisherUser['username']) ?></div>
    </div>
<?php endif; ?>
</div>
<?php if (!$publisherIsAdmin && $publisherUser): ?>
<div class="mt-4 grid grid-cols-2 gap-3">
    <div class="rounded bg-gray-50 p-3">
        <div class="text-xs text-gray-500">上传视频数量</div>
        <div class="mt-1 text-xl font-semibold text-gray-900"><?= (int)$publisherVideoCount ?></div>
    </div>
    <div class="rounded bg-gray-50 p-3">
        <div class="text-xs text-gray-500">视频点击量</div>
        <div class="mt-1 text-xl font-semibold text-blue-600"><?= (int)$publisherClickCount ?></div>
    </div>
</div>
<?php endif; ?>
</div>
</div>

<!-- 选集 -->
<?php if (!empty($episodes)): ?>
<div class="bg-white rounded shadow">
<div class="flex justify-between items-center border-b px-4 py-3">
<h3 class="text-sm font-semibold">视频选集-新增Pro高级线路</h3>
<span class="text-xs text-gray-500"><?=count($episodes)?> 集</span>
</div>

<div class="max-h-[600px] overflow-y-auto">
<?php foreach ($episodes as $ep): ?>
<?php
$name = trim($ep['episode_name']);
if (preg_match('/^\d+$/', $name)) $name = '第'.$name.'集';
?>
<a
href="?id=<?=$videoId?>&episode=<?=$ep['id']?>&domain_id=<?=$currentDomainId?>"
data-episode-id="<?=(int)$ep['id']?>"
class="episode-item block border-b px-4 py-3 text-sm truncate
<?=$ep['id']==$episodeId?'episode-active':''?>">
<?=htmlspecialchars($name)?>
</a>
<?php endforeach; ?>
</div>
</div>
<?php endif; ?>

</aside>
</div>

<?php if ($trafficTrialMode): ?>
<div id="trafficUnlockModal" class="fixed inset-0 z-[200] hidden items-center justify-center bg-black/60 px-4 py-6" role="dialog" aria-modal="true" aria-labelledby="trafficUnlockModalTitle">
    <div class="max-h-[90vh] w-full max-w-md overflow-y-auto rounded-2xl bg-white shadow-2xl dark:bg-gray-800">
        <div class="p-6 text-center">
            <svg class="mx-auto mb-3 h-12 w-12 text-amber-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M12 15v2m-6 4h12a2 2 0 002-2v-7a2 2 0 00-2-2H6a2 2 0 00-2 2v7a2 2 0 002 2zm10-9V7a4 4 0 00-8 0v4h8z"/>
            </svg>
            <h3 id="trafficUnlockModalTitle" class="text-lg font-semibold text-gray-900 dark:text-gray-100">解锁完整视频</h3>
            <p class="mt-2 text-sm text-gray-600 dark:text-gray-300">
                支付 <span class="text-base font-bold text-amber-600 dark:text-amber-400"><?= (int)$video['traffic_cost'] ?></span> 流量解锁
            </p>
            <div class="mt-4 space-y-1 text-left text-xs text-gray-500 dark:text-gray-400">
                <p>解锁有效期：<span class="text-amber-700 dark:text-amber-300">跟随流量周期</span>（流量被自动/手动重置时失效）</p>
                <?php if ($trafficEnabled): ?>
                    <p class="mt-2">您的剩余流量：<span class="font-semibold text-gray-800 dark:text-gray-200"><?= (int)$userTraffic['left'] ?></span>
                        / 合计 <?= (int)$userTraffic['total'] ?>
                        <span class="text-gray-400">（基础 <?= (int)($userTraffic['base_left'] ?? 0) ?> + 收益 <?= (int)($userTraffic['earning_available'] ?? 0) ?>）</span>
                    </p>
                    <?php if (!empty($userTraffic['next_reset_at'])): ?>
                        <p>下次自动重置：<?= htmlspecialchars($userTraffic['next_reset_at']) ?></p>
                    <?php endif; ?>
                    <?php if (!empty($userTraffic['expired'])): ?>
                        <p class="text-red-500 dark:text-red-400">⚠ 基础流量已过期</p>
                        <?php if ((int)($userTraffic['earning_available'] ?? 0) > 0): ?>
                        <p class="text-green-600 dark:text-green-400">仍可使用收益流量 <?= (int)$userTraffic['earning_available'] ?></p>
                        <?php endif; ?>
                    <?php endif; ?>
                <?php endif; ?>
                <?php if (!empty($unlockInfo['reason']) && $unlockInfo['reason'] === 'traffic_expired' && (int)($userTraffic['left'] ?? 0) <= 0): ?>
                    <p class="text-amber-600 dark:text-amber-400">⚠ 您的流量已到期，请等待自动重置或联系管理员</p>
                <?php elseif (!empty($userTraffic['expired']) && (int)($userTraffic['earning_available'] ?? 0) > 0): ?>
                    <p class="text-amber-600 dark:text-amber-400">基础流量已到期，解锁将优先消耗收益流量</p>
                <?php endif; ?>
            </div>
            <div id="trafficUnlockMsg" class="mt-3 hidden text-xs text-red-500 dark:text-red-400"></div>
            <div class="mt-5 flex flex-wrap justify-center gap-2">
                <button type="button" id="trafficUnlockBtn"
                        class="rounded-lg bg-amber-500 px-5 py-2.5 text-sm font-semibold text-white hover:bg-amber-600">
                    确认支付 <?= (int)$video['traffic_cost'] ?> 流量解锁
                </button>
                <button type="button" id="trafficUnlockModalCloseBtn"
                        class="rounded-lg border border-gray-300 bg-white px-5 py-2.5 text-sm text-gray-700 hover:bg-gray-50 dark:border-gray-600 dark:bg-gray-700 dark:text-gray-200 dark:hover:bg-gray-600">
                    稍后再说
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

</main>

<!-- 播放器脚本 -->
<?php if ($playerEngine === 'videojs'): ?>
<script src="https://cdn.bootcdn.net/ajax/libs/video.js/8.16.1/video.min.js"></script>
<?php elseif ($playerEngine === 'xgplayer'): ?>
<script src="https://unpkg.byted-static.com/xgplayer/2.31.2/browser/index.js" type="text/javascript" charset="utf-8"></script>
<script src="https://unpkg.byted-static.com/xgplayer-hls/2.5.2/dist/index.min.js" type="text/javascript" charset="utf-8"></script>
<?php else: ?>
<script src="https://cdn.bootcdn.net/ajax/libs/hls.js/1.5.7/hls.min.js"></script>
<script src="https://cdn.bootcdn.net/ajax/libs/dplayer/1.24.0/DPlayer.min.js"></script>
<?php endif; ?>

<script>
const PLAYER_ENGINE = <?= json_encode($playerEngine, JSON_UNESCAPED_UNICODE) ?>;
const PLAY_VIDEO_ID = <?= (int)$videoId ?>;
let PLAY_CURRENT_DOMAIN_ID = <?= (int)($currentDomainId ?? 0) ?>;
let PLAY_CURRENT_EPISODE_ID = <?= (int)$episodeId ?>;
const WATCH_INITIAL_EPISODE_ID = <?= (int)$episodeId ?>;
const PLAY_LINES = <?= json_encode(array_map(function ($d) use ($canPlay, $usePlayerProxy, $useSitePlayToken, $pdo, $user, $videoId, $currentEpisode) {
    $line = [
        'id' => (int)$d['id'],
        'name' => (string)($d['display_name'] ?: ('线路' . $d['id'])),
    ];
    if ($canPlay && !$usePlayerProxy && $currentEpisode) {
        $relativePath = ltrim((string)$currentEpisode['video_url'], '/');
        if ($useSitePlayToken) {
            $src = buildSitePlayMediaUrl(
                $pdo,
                (int)$user['id'],
                (int)$videoId,
                (int)$currentEpisode['id'],
                (int)$d['id'],
                $relativePath
            );
        } else {
            $path = '/' . $relativePath;
            $src = 'https://' . rtrim((string)$d['domain'], '/') . $path;
        }
        $line['src'] = $src;
        $line['type'] = preg_match('/\.m3u8(\?.*)?$/i', $src) ? 'application/x-mpegURL' : 'video/mp4';
    }
    return $line;
}, $playDomains), JSON_UNESCAPED_UNICODE) ?>;
const PLAY_EPISODES = <?= json_encode(array_map(static function ($ep) {
    $name = trim((string)$ep['episode_name']);
    if (preg_match('/^\d+$/', $name)) {
        $name = '第' . $name . '集';
    }
    return ['id' => (int)$ep['id'], 'name' => $name];
}, $episodes), JSON_UNESCAPED_UNICODE) ?>;
const PLAY_EPISODE_SOURCES = <?= json_encode($episodeSources, JSON_UNESCAPED_UNICODE) ?>;

function findPlayLine(id) {
    const lineId = Number(id);
    return (PLAY_LINES || []).find(line => Number(line.id) === lineId) || null;
}

function findEpisodeSource(episodeId, domainId) {
    const epMap = PLAY_EPISODE_SOURCES[String(episodeId)] || PLAY_EPISODE_SOURCES[episodeId];
    if (!epMap) return null;
    return epMap[String(domainId)] || epMap[domainId] || null;
}

function findEpisodeName(episodeId) {
    const ep = (PLAY_EPISODES || []).find(item => Number(item.id) === Number(episodeId));
    return ep ? ep.name : '该集';
}

function getCurrentPlayerTime() {
    try {
        return window.player ? Math.floor(Number(window.player.currentTime()) || 0) : 0;
    } catch (e) {
        return 0;
    }
}

function syncDomainState(id) {
    PLAY_CURRENT_DOMAIN_ID = Number(id);
    if (typeof PLAY_API_PARAMS !== 'undefined') {
        PLAY_API_PARAMS.domain_id = PLAY_CURRENT_DOMAIN_ID;
    }

    const u = new URL(location.href);
    u.searchParams.set('domain_id', String(PLAY_CURRENT_DOMAIN_ID));
    history.replaceState(null, '', u.toString());

    document.querySelectorAll('select[onchange*="switchDomain"]').forEach(select => {
        select.value = String(PLAY_CURRENT_DOMAIN_ID);
    });

    mountDPlayerBarTools();
}

function syncEpisodeState(episodeId) {
    PLAY_CURRENT_EPISODE_ID = Number(episodeId);
    if (typeof PLAY_API_PARAMS !== 'undefined') {
        PLAY_API_PARAMS.episode_id = PLAY_CURRENT_EPISODE_ID;
    }

    const u = new URL(location.href);
    u.searchParams.set('id', String(PLAY_VIDEO_ID));
    u.searchParams.set('episode', String(PLAY_CURRENT_EPISODE_ID));
    if (PLAY_CURRENT_DOMAIN_ID > 0) {
        u.searchParams.set('domain_id', String(PLAY_CURRENT_DOMAIN_ID));
    }
    history.replaceState(null, '', u.toString());

    document.querySelectorAll('.episode-item').forEach(link => {
        const linkEp = Number(link.dataset.episodeId || 0);
        link.classList.toggle('episode-active', linkEp === PLAY_CURRENT_EPISODE_ID);
    });

    WATCH_EPISODE_ID = PLAY_CURRENT_EPISODE_ID;
    WATCH_LS_KEY = `watch_prog_${WATCH_VIDEO_ID}_${WATCH_EPISODE_ID}`;

    mountDPlayerBarTools();
}

function flushCurrentWatchProgress() {
    if (!window.player || typeof window.player.currentTime !== 'function') {
        return Promise.resolve();
    }
    const progress = Math.floor(window.player.currentTime() || 0);
    const duration = Math.floor(window.player.duration() || 0);
    writeWatchLocalProgress(progress, duration);
    const fd = new FormData();
    fd.append('video_id', WATCH_VIDEO_ID);
    fd.append('episode_id', WATCH_EPISODE_ID);
    fd.append('progress', progress);
    fd.append('duration', duration);
    fd.append('flush', '1');
    return fetch('api/save_progress.php', { method: 'POST', body: fd, credentials: 'same-origin' }).catch(() => {});
}

async function switchEpisode(episodeId) {
    const epId = Number(episodeId);
    if (!epId || epId === PLAY_CURRENT_EPISODE_ID) return;

    try {
        await flushCurrentWatchProgress();
        showPlayerNotice('正在切换集数...');

        const source = USE_PLAYER_PROXY
            ? await loadProxiedPlayUrl(PLAY_CURRENT_DOMAIN_ID, epId)
            : findEpisodeSource(epId, PLAY_CURRENT_DOMAIN_ID);

        if (!source || !source.src) {
            throw new Error('该集没有可用播放地址');
        }

        if (typeof window.applyPlayerSource !== 'function') {
            throw new Error('播放器尚未准备好，请稍后再试');
        }

        const resumeAt = await resolveResumeSeconds(epId);
        syncEpisodeState(epId);
        await window.applyPlayerSource(source, resumeAt);
        if (isTrafficTrialActive()) {
            hideTrafficUnlockModal();
            resetTrafficTrialState();
        }
        showPlayerNotice('已切换到：' + findEpisodeName(epId));
    } catch (err) {
        showPlayerNotice(err.message || '集数切换失败');
    }
}

function showPlayerNotice(message) {
    if (window.dp && typeof window.dp.notice === 'function') {
        window.dp.notice(message);
        return;
    }
    const errBox = document.getElementById('playUrlError');
    if (errBox) {
        errBox.textContent = message;
        errBox.classList.remove('hidden');
        setTimeout(() => errBox.classList.add('hidden'), 2400);
    }
}

function isMobilePlayViewport() {
    return window.matchMedia('(max-width: 768px)').matches;
}

function isDPlayerFullscreen() {
    const box = document.getElementById('dplayerContainer');
    const root = box ? box.querySelector('.dplayer') : null;
    if (!root) return false;
    if (root.classList.contains('dplayer-full')) return true;
    const fsEl = document.fullscreenElement || document.webkitFullscreenElement;
    return fsEl === root || fsEl === box;
}

function lockPlayLandscape() {
    if (!isMobilePlayViewport()) return Promise.resolve();
    const orient = screen.orientation;
    if (orient && typeof orient.lock === 'function') {
        return orient.lock('landscape').catch(() => {});
    }
    return Promise.resolve();
}

function unlockPlayLandscape() {
    try {
        const orient = screen.orientation;
        if (orient && typeof orient.unlock === 'function') orient.unlock();
    } catch (e) {}
}

function bindDPlayerFullscreen(dp) {
    const box = document.getElementById('dplayerContainer');
    if (!dp || !box || box.dataset.fsBound === '1') return;
    box.dataset.fsBound = '1';

    let fsBusy = false;
    const afterFs = entering => {
        if (fsBusy) return;
        fsBusy = true;
        const done = () => {
            if (typeof dp.resize === 'function') dp.resize();
            mountDPlayerBarTools();
            fsBusy = false;
        };
        if (entering) {
            lockPlayLandscape().finally(() => setTimeout(done, 150));
        } else {
            unlockPlayLandscape();
            setTimeout(done, 120);
        }
    };

    dp.on('fullscreen', () => afterFs(true));
    dp.on('fullscreen_cancel', () => afterFs(false));

    const syncFs = () => {
        afterFs(isDPlayerFullscreen());
    };
    document.addEventListener('fullscreenchange', syncFs);
    document.addEventListener('webkitfullscreenchange', syncFs);

    const fullBtn = box.querySelector('.dplayer-full');
    if (fullBtn) {
        fullBtn.addEventListener('click', () => lockPlayLandscape(), true);
    }
}

function ensureDPlayerControllerLayout(box) {
    const controller = box.querySelector('.dplayer-controller');
    if (!controller) return null;
    const left = controller.querySelector('.dplayer-icons-left');
    const barWrap = controller.querySelector('.dplayer-bar-wrap');
    const right = controller.querySelector('.dplayer-icons-right');
    if (!left || !barWrap || !right) return null;
    controller.appendChild(left);
    controller.appendChild(barWrap);
    controller.appendChild(right);
    return { controller, left, barWrap, right };
}

function layoutDPlayerIconsRight(right, toolsEl) {
    if (!right) return;
    let system = right.querySelector('.dp-bar-system');
    if (!system) {
        system = document.createElement('div');
        system.className = 'dp-bar-system';
        right.appendChild(system);
    }
    const full = right.querySelector(':scope > .dplayer-full');
    Array.from(right.children).forEach(child => {
        if (child === toolsEl || child.classList.contains('dp-bar-tools')) return;
        if (child === system || child === full) return;
        system.appendChild(child);
    });
    if (toolsEl) {
        right.insertBefore(toolsEl, system);
    }
    if (full) {
        right.appendChild(full);
    }
}

function mountDPlayerBarTools() {
    if (PLAYER_ENGINE !== 'dplayer') return;
    const box = document.getElementById('dplayerContainer');
    if (!box) return;
    const layout = ensureDPlayerControllerLayout(box);
    if (!layout) return;
    const { right } = layout;

    box.querySelectorAll('.dp-bar-tools').forEach(el => el.remove());

    const lines = PLAY_LINES || [];
    const episodes = PLAY_EPISODES || [];
    if (!lines.length && !episodes.length) {
        layoutDPlayerIconsRight(right, null);
        return;
    }

    const chevron = '<svg viewBox="0 0 10 10" fill="currentColor" aria-hidden="true"><path d="M2 3.5 5 6.5 8 3.5z"/></svg>';
    const barCompact = isMobilePlayViewport() && !isDPlayerFullscreen();
    const barLabel = (full, short) => (barCompact ? short : full);
    let openMenu = null;

    const closeMenus = () => {
        box.querySelectorAll('.dp-bar-tool.is-open').forEach(w => w.classList.remove('is-open'));
        box.querySelectorAll('.dp-bar-tool-menu').forEach(m => m.classList.add('hidden'));
        openMenu = null;
    };

    const buildDropdown = (key, label, items, onPick) => {
        const wrap = document.createElement('div');
        wrap.className = 'dp-bar-tool';
        const active = items.find(it => it.active);
        const btn = document.createElement('button');
        btn.type = 'button';
        btn.className = 'dp-bar-tool-btn';
        btn.setAttribute('aria-haspopup', 'listbox');
        btn.setAttribute('aria-expanded', 'false');
        btn.title = active ? (label + '：' + active.label) : label;
        btn.innerHTML = '<span class="truncate">' + label + '</span>' + chevron;

        const menu = document.createElement('div');
        menu.className = 'dp-bar-tool-menu hidden';
        menu.setAttribute('role', 'listbox');
        items.forEach(it => {
            const item = document.createElement('button');
            item.type = 'button';
            item.className = 'dp-bar-tool-item' + (it.active ? ' is-active' : '');
            item.textContent = it.label;
            item.setAttribute('role', 'option');
            item.setAttribute('aria-selected', it.active ? 'true' : 'false');
            item.addEventListener('click', e => {
                e.stopPropagation();
                closeMenus();
                if (!it.active) onPick(it.id);
            });
            menu.appendChild(item);
        });

        btn.addEventListener('click', e => {
            e.stopPropagation();
            const willOpen = menu.classList.contains('hidden');
            closeMenus();
            if (willOpen) {
                wrap.classList.add('is-open');
                menu.classList.remove('hidden');
                btn.setAttribute('aria-expanded', 'true');
                openMenu = menu;
            }
        });

        wrap.appendChild(btn);
        wrap.appendChild(menu);
        return wrap;
    };

    const tools = document.createElement('div');
    tools.className = 'dp-bar-tools' + (barCompact ? ' dp-bar-tools--compact' : '');

    if (lines.length) {
        tools.appendChild(buildDropdown(
            'line',
            barLabel('线路', '线'),
            lines.map(l => ({
                id: l.id,
                label: l.name,
                active: l.id === PLAY_CURRENT_DOMAIN_ID,
            })),
            id => switchDomain(id)
        ));
    }

    if (episodes.length) {
        tools.appendChild(buildDropdown(
            'episode',
            barLabel('选集', '集'),
            episodes.map(ep => ({
                id: ep.id,
                label: ep.name,
                active: ep.id === PLAY_CURRENT_EPISODE_ID,
            })),
            id => switchEpisode(id)
        ));
    }

    layoutDPlayerIconsRight(right, tools);

    if (!box.dataset.dpToolsBound) {
        box.dataset.dpToolsBound = '1';
        document.addEventListener('click', closeMenus);
        box.addEventListener('click', e => {
            if (openMenu && !e.target.closest('.dp-bar-tool')) closeMenus();
        });
    }
}

/* 动画 */
const ob = new IntersectionObserver(es=>{
    es.forEach(e=>e.isIntersecting && e.target.classList.add('show'))
},{threshold:.15});
document.querySelectorAll('.fade-up').forEach(el=>ob.observe(el));

async function switchDomain(id){
    const line = findPlayLine(id);
    if (!line || Number(line.id) === PLAY_CURRENT_DOMAIN_ID) return;

    const resumeAt = getCurrentPlayerTime();
    try {
        showPlayerNotice('正在切换线路...');
        const source = USE_PLAYER_PROXY
            ? await loadProxiedPlayUrl(Number(line.id))
            : findEpisodeSource(PLAY_CURRENT_EPISODE_ID, Number(line.id));

        if (!source.src) {
            throw new Error('该线路没有可用播放地址');
        }

        if (typeof window.applyPlayerSource !== 'function') {
            throw new Error('播放器尚未准备好，请稍后再试');
        }

        await window.applyPlayerSource(source, resumeAt);
        syncDomainState(line.id);
        showPlayerNotice('已切换到：' + line.name);
    } catch (err) {
        showPlayerNotice(err.message || '线路切换失败');
    }
}

// 流量视频解锁弹窗
const trafficUnlockModal = document.getElementById('trafficUnlockModal');
const trafficUnlockModalTitle = document.getElementById('trafficUnlockModalTitle');
const trafficUnlockBtn = document.getElementById('trafficUnlockBtn');
const trafficUnlockModalCloseBtn = document.getElementById('trafficUnlockModalCloseBtn');
const trafficBuyBtn = document.getElementById('trafficBuyBtn');
const trafficUnlockMsg = document.getElementById('trafficUnlockMsg');
const TRAFFIC_UNLOCK_BTN_DEFAULT = trafficUnlockBtn
    ? trafficUnlockBtn.textContent
    : '确认支付解锁';

function showTrafficUnlockMsg(text) {
    if (!trafficUnlockMsg) return;
    if (!text) {
        trafficUnlockMsg.textContent = '';
        trafficUnlockMsg.classList.add('hidden');
        return;
    }
    trafficUnlockMsg.textContent = text;
    trafficUnlockMsg.classList.remove('hidden');
}

function showTrafficUnlockModal(trialEnded) {
    if (!trafficUnlockModal) return;
    if (trafficUnlockModalTitle) {
        trafficUnlockModalTitle.textContent = trialEnded ? '试看已结束' : '解锁完整视频';
    }
    showTrafficUnlockMsg('');
    trafficUnlockModal.classList.remove('hidden');
    trafficUnlockModal.classList.add('flex');
    document.body.style.overflow = 'hidden';
}

function hideTrafficUnlockModal() {
    if (!trafficUnlockModal) return;
    trafficUnlockModal.classList.add('hidden');
    trafficUnlockModal.classList.remove('flex');
    document.body.style.overflow = '';
}

if (trafficBuyBtn) {
    trafficBuyBtn.addEventListener('click', () => showTrafficUnlockModal(false));
}
if (trafficUnlockModalCloseBtn) {
    trafficUnlockModalCloseBtn.addEventListener('click', hideTrafficUnlockModal);
}
if (trafficUnlockModal) {
    trafficUnlockModal.addEventListener('click', (e) => {
        if (e.target === trafficUnlockModal) hideTrafficUnlockModal();
    });
}
if (trafficUnlockBtn) {
    trafficUnlockBtn.addEventListener('click', () => {
        if (!confirm('确认支付流量解锁该视频？')) return;
        trafficUnlockBtn.disabled = true;
        trafficUnlockBtn.textContent = '处理中...';
        showTrafficUnlockMsg('');
        const fd = new FormData();
        fd.append('video_id', '<?= (int)$videoId ?>');
        fetch('api/unlock_video.php', { method: 'POST', body: fd, credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (d.ok) {
                    activateVideoUnlock(d);
                    return;
                }
                showTrafficUnlockMsg(d.message || '解锁失败');
                trafficUnlockBtn.disabled = false;
                trafficUnlockBtn.textContent = TRAFFIC_UNLOCK_BTN_DEFAULT;
            })
            .catch(e => {
                showTrafficUnlockMsg('请求失败：' + (e && e.message ? e.message : e));
                trafficUnlockBtn.disabled = false;
                trafficUnlockBtn.textContent = TRAFFIC_UNLOCK_BTN_DEFAULT;
            });
    });
}

const USE_PLAYER_PROXY = <?= $usePlayerProxy && $canPlay ? 'true' : 'false' ?>;
let trafficTrialModeActive = <?= $trafficTrialMode ? 'true' : 'false' ?>;
const TRAFFIC_TRIAL_PERCENT = <?= (int)trafficTrialPercent() ?>;

function isTrafficTrialActive() {
    return trafficTrialModeActive;
}

function trafficTrialMaxTime(duration) {
    const d = Number(duration) || 0;
    if (!isTrafficTrialActive() || d <= 0) return Infinity;
    return d * TRAFFIC_TRIAL_PERCENT / 100;
}

function resumeActivePlayer() {
    if (window.dp && typeof window.dp.play === 'function') {
        const p = window.dp.play();
        if (p && typeof p.catch === 'function') p.catch(() => {});
        return;
    }
    if (window.xg && typeof window.xg.play === 'function') {
        const p = window.xg.play();
        if (p && typeof p.catch === 'function') p.catch(() => {});
        return;
    }
    if (window.player && typeof window.player.play === 'function') {
        const p = window.player.play();
        if (p && typeof p.catch === 'function') p.catch(() => {});
        return;
    }
    const video = window.dp && window.dp.video ? window.dp.video : document.querySelector('#videoPlayer video');
    if (video && typeof video.play === 'function') {
        const p = video.play();
        if (p && typeof p.catch === 'function') p.catch(() => {});
    }
}

function activateVideoUnlock(result) {
    trafficTrialModeActive = false;
    window.__trafficTrialEnded = false;
    if (typeof window.__trafficTrialCleanup === 'function') {
        window.__trafficTrialCleanup();
        window.__trafficTrialCleanup = null;
    }

    ['trafficTrialBadge', 'trafficTrialEndedTip', 'trafficBuyBtn'].forEach((id) => {
        const el = document.getElementById(id);
        if (el) el.classList.add('hidden');
    });

    const statusBar = document.getElementById('trafficStatusBar');
    if (statusBar) {
        statusBar.className = 'mt-3 flex flex-wrap items-center gap-2 text-xs text-amber-700';
        let html = '<span class="rounded-full bg-amber-100 px-2 py-0.5">流量视频已解锁</span><span>有效期：跟随流量周期</span>';
        if (result && typeof result.left === 'number') {
            html += '<span class="text-gray-500">剩余流量：' + result.left + '</span>';
        }
        statusBar.innerHTML = html;
    }

    hideTrafficUnlockModal();
    if (trafficUnlockBtn) {
        trafficUnlockBtn.disabled = false;
        trafficUnlockBtn.textContent = TRAFFIC_UNLOCK_BTN_DEFAULT;
    }

    const msg = (result && result.message) ? result.message : '解锁成功，可观看完整视频';
    showPlayerNotice(msg);
    resumeActivePlayer();
}

function pauseActivePlayer() {
    if (window.dp && typeof window.dp.pause === 'function') {
        window.dp.pause();
        return;
    }
    if (window.xg && typeof window.xg.pause === 'function') {
        window.xg.pause();
        return;
    }
    if (window.player && typeof window.player.pause === 'function') {
        window.player.pause();
        return;
    }
    const video = window.dp && window.dp.video ? window.dp.video : document.querySelector('#videoPlayer video');
    if (video && typeof video.pause === 'function') video.pause();
}

window.__trafficTrialEnded = false;

function resetTrafficTrialState() {
    window.__trafficTrialEnded = false;
    const badge = document.getElementById('trafficTrialBadge');
    const tip = document.getElementById('trafficTrialEndedTip');
    if (badge) badge.classList.remove('hidden');
    if (tip) tip.classList.add('hidden');
}

function showTrafficTrialEndedNotice() {
    window.__trafficTrialEnded = true;
    const badge = document.getElementById('trafficTrialBadge');
    const tip = document.getElementById('trafficTrialEndedTip');
    if (badge) badge.classList.add('hidden');
    if (tip) tip.classList.remove('hidden');
    showPlayerNotice('试看已结束');
}

function bindTrafficTrialLimit() {
    if (!isTrafficTrialActive() || !window.player || typeof window.player.on !== 'function') return;
    if (typeof window.__trafficTrialCleanup === 'function') {
        window.__trafficTrialCleanup();
    }

    const clampTime = () => {
        const duration = Number(window.player.duration()) || 0;
        const maxT = trafficTrialMaxTime(duration);
        if (!isFinite(maxT) || maxT === Infinity || duration <= 0) return;
        const current = Number(window.player.currentTime()) || 0;
        if (window.__trafficTrialEnded) {
            if (current > maxT + 0.05) {
                window.player.currentTime(maxT);
            }
            return;
        }
        if (current > maxT + 0.05) {
            window.player.currentTime(maxT);
        }
        if (current >= maxT - 0.2) {
            window.player.currentTime(maxT);
            pauseActivePlayer();
            showTrafficTrialEndedNotice();
        }
    };

    const onPlay = () => {
        if (!isTrafficTrialActive() || !window.__trafficTrialEnded) return;
        resetTrafficTrialState();
        window.player.currentTime(0);
    };

    const onTimeUpdate = () => clampTime();
    const onSeeking = () => clampTime();
    window.player.on('timeupdate', onTimeUpdate);
    window.player.on('seeking', onSeeking);
    window.player.on('play', onPlay);
    window.__trafficTrialCleanup = () => {
        if (window.player && typeof window.player.off === 'function') {
            window.player.off('timeupdate', onTimeUpdate);
            window.player.off('seeking', onSeeking);
            window.player.off('play', onPlay);
        }
    };
}

const PLAY_API_PARAMS = {
    video_id: <?= (int)$videoId ?>,
    episode_id: <?= (int)$episodeId ?>,
    domain_id: <?= (int)($currentDomainId ?? 0) ?>
};

function loadProxiedPlayUrl(domainId = PLAY_CURRENT_DOMAIN_ID, episodeId = PLAY_CURRENT_EPISODE_ID) {
    const loading = document.getElementById('playUrlLoading');
    const errBox = document.getElementById('playUrlError');
    const el = document.getElementById('videoPlayer');
    const qs = new URLSearchParams({
        ...PLAY_API_PARAMS,
        episode_id: episodeId,
        domain_id: domainId,
    });
    if (loading) {
        loading.textContent = '正在切换线路...';
        loading.classList.remove('hidden');
    }
    return fetch('api/play_url.php?' + qs.toString(), { credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            if (!data.ok || !data.play_url) {
                throw new Error(data.message || '获取播放链接失败');
            }
            if (loading) loading.classList.add('hidden');
            if (errBox) errBox.classList.add('hidden');
            const dpBox = document.getElementById('dplayerContainer');
            const vEl = document.getElementById('videoPlayer');
            if (PLAYER_ENGINE === 'dplayer' && dpBox) dpBox.classList.remove('hidden');
            else if (vEl) vEl.classList.remove('hidden');
            return { src: data.play_url, type: data.mime || 'application/x-mpegURL' };
        })
        .catch(err => {
            if (loading) loading.classList.add('hidden');
            if (errBox) {
                errBox.textContent = err.message || '获取播放链接失败';
                errBox.classList.remove('hidden');
            }
            throw err;
        });
}

const WATCH_VIDEO_ID = <?= (int)$videoId ?>;
let WATCH_EPISODE_ID = <?= (int)$episodeId ?>;
const WATCH_JUMP_T = <?= (int)$jumpT ?>;
let WATCH_LS_KEY = `watch_prog_${WATCH_VIDEO_ID}_${WATCH_EPISODE_ID}`;

function readWatchLocalProgress() {
    try {
        const raw = localStorage.getItem(WATCH_LS_KEY);
        if (!raw) return 0;
        const o = JSON.parse(raw);
        return Number(o.p || o.progress || 0);
    } catch (e) {
        return 0;
    }
}

function writeWatchLocalProgress(progress, duration) {
    try {
        localStorage.setItem(WATCH_LS_KEY, JSON.stringify({ p: progress, d: duration, t: Date.now() }));
    } catch (e) {}
}

async function resolveResumeSeconds(episodeId = WATCH_EPISODE_ID) {
    if (episodeId === WATCH_INITIAL_EPISODE_ID && WATCH_JUMP_T > 0) return WATCH_JUMP_T;
    let localP = 0;
    try {
        const raw = localStorage.getItem(`watch_prog_${WATCH_VIDEO_ID}_${episodeId}`);
        if (raw) {
            const o = JSON.parse(raw);
            localP = Number(o.p || o.progress || 0);
        }
    } catch (e) {}
    if (localP > 5) return localP;
    try {
        const r = await fetch(
            `api/get_progress.php?video_id=${WATCH_VIDEO_ID}&episode_id=${episodeId}`,
            { credentials: 'same-origin' }
        );
        const data = await r.json();
        const p = Number(data.progress || 0);
        if (data.ok && p > 5) return p;
    } catch (e) {}
    return 0;
}

function setupResume(seekTo) {
    let target = Number(seekTo || 0);
    if (target <= 5 || !window.player) return;

    let applied = false;
    const tryApply = () => {
        if (applied) return true;
        const player = window.player;
        let duration = 0;
        try {
            duration = Number(player.duration()) || 0;
        } catch (e) {}
        if (!isFinite(duration) || duration < 0) duration = 0;
        if (isTrafficTrialActive() && duration > 0) {
            const maxT = trafficTrialMaxTime(duration);
            if (target > maxT) target = Math.max(0, maxT);
        }
        if (duration > 0 && target >= duration - 10) {
            applied = true;
            return true;
        }
        try {
            player.currentTime(target);
            const now = Number(player.currentTime()) || 0;
            if (now >= target - 2) {
                applied = true;
                return true;
            }
        } catch (e) {}
        return false;
    };

    const bindMediaEvents = (on, off) => {
        let cleaned = false;
        const cleanup = () => {
            if (cleaned) return;
            cleaned = true;
            ['loadedmetadata', 'loadeddata', 'canplay', 'durationchange'].forEach(ev => off(ev, handler));
        };
        const handler = () => {
            if (tryApply()) cleanup();
        };
        ['loadedmetadata', 'loadeddata', 'canplay', 'durationchange'].forEach(ev => on(ev, handler));
        setTimeout(() => {
            tryApply();
            cleanup();
        }, 12000);
    };

    if (PLAYER_ENGINE === 'videojs' && typeof window.player.ready === 'function') {
        window.player.ready(() => {
            if (tryApply()) return;
            bindMediaEvents(
                (ev, fn) => window.player.on(ev, fn),
                (ev, fn) => window.player.off(ev, fn)
            );
        });
        return;
    }

    const video = window.dp && window.dp.video ? window.dp.video : null;
    if (!video) return;
    if (tryApply()) return;
    bindMediaEvents(
        (ev, fn) => video.addEventListener(ev, fn),
        (ev, fn) => video.removeEventListener(ev, fn)
    );
}

document.addEventListener('DOMContentLoaded', () => {
    const CAN_SWITCH_EPISODE = <?= $canPlay ? 'true' : 'false' ?>;
    if (CAN_SWITCH_EPISODE) {
        document.querySelectorAll('.episode-item').forEach(link => {
            link.addEventListener('click', e => {
                e.preventDefault();
                const epId = Number(link.dataset.episodeId || 0);
                if (epId) switchEpisode(epId);
            });
        });
    }

    const el = document.getElementById('videoPlayer');
    const dpContainer = document.getElementById('dplayerContainer');
    const xgContainer = document.getElementById('xgplayerContainer');
    const initVideoJs = (source, resumeAt) => {
        if (!el || typeof videojs === 'undefined') return;
        window.player = videojs(el, {
            controls: true,
            autoplay: true,
            preload: 'auto',
            fluid: false,
            fill: true,
            responsive: true,
            playbackRates: [0.5, 0.75, 1, 1.25, 1.5, 2],
            controlBar: {
                children: [
                    'playToggle', 'volumePanel', 'currentTimeDisplay', 'timeDivider',
                    'durationDisplay', 'progressControl', 'remainingTimeDisplay',
                    'playbackRateMenuButton', 'fullscreenToggle'
                ]
            },
            html5: {
                vhs: { enableLowInitialPlaylist: true },
                nativeAudioTracks: false,
                nativeVideoTracks: false
            }
        });
        setupResume(resumeAt);
        if (source && source.src) {
            window.player.src({ src: source.src, type: source.type });
        } else {
            const directSrc = el.getAttribute('data-src');
            const directType = el.getAttribute('data-type');
            if (directSrc) {
                window.player.src({ src: directSrc, type: directType || 'video/mp4' });
            }
        }
        const wrap = el.closest('.video-js') || el.parentElement;
        if (wrap) wrap.addEventListener('contextmenu', e => e.preventDefault());
        window.player.ready(() => {
            if (typeof window.player.dimensions === 'function') {
                const stage = document.getElementById('playerStage');
                if (stage) window.player.dimensions(stage.clientWidth, stage.clientHeight);
            }
        });
        bindPlayerProgressHandlers();
    };

    const destroyDPlayer = () => {
        if (window.__dpHls) {
            try { window.__dpHls.destroy(); } catch (e) {}
            window.__dpHls = null;
        }
        if (window.dp) {
            try {
                if (typeof window.dp.destroy === 'function') window.dp.destroy();
                else if (typeof window.dp.pause === 'function') window.dp.pause();
            } catch (e) {}
            window.dp = null;
        }
        if (dpContainer) {
            dpContainer.innerHTML = '';
            delete dpContainer.dataset.fsBound;
        }
    };

    const initDPlayer = (source, resumeAt) => {
        if (!dpContainer || typeof DPlayer === 'undefined') return;
        let url = '';
        let mimeType = '';
        if (source && source.src) {
            url = source.src;
            mimeType = source.type || '';
        } else {
            url = dpContainer.getAttribute('data-play-src') || '';
            mimeType = dpContainer.getAttribute('data-play-type') || '';
        }
        if (!url) return;

        destroyDPlayer();

        const isHls = /\.m3u8(\?|$)/i.test(url) || mimeType.indexOf('mpegURL') >= 0;
        const useHlsJs = isHls && typeof Hls !== 'undefined';
        const dpOptions = {
            container: dpContainer,
            autoplay: true,
            contextmenu: [],
            video: useHlsJs
                ? { url, type: 'customHls' }
                : { url, type: isHls ? 'hls' : 'auto' },
        };
        if (useHlsJs) {
            dpOptions.customType = {
                customHls(video) {
                    if (window.__dpHls) {
                        try { window.__dpHls.destroy(); } catch (e) {}
                    }
                    const hls = new Hls();
                    window.__dpHls = hls;
                    hls.loadSource(url);
                    hls.attachMedia(video);
                },
            };
        }

        window.dp = new DPlayer(dpOptions);
        bindDPlayerFullscreen(window.dp);
        const resizeDp = () => {
            if (window.dp && typeof window.dp.resize === 'function') window.dp.resize();
        };
        setTimeout(() => {
            resizeDp();
            mountDPlayerBarTools();
        }, 80);
        if (!window.__dpResizeBound) {
            window.__dpResizeBound = true;
            window.addEventListener('resize', resizeDp);
            window.addEventListener('orientationchange', () => {
                setTimeout(() => {
                    resizeDp();
                    mountDPlayerBarTools();
                }, 200);
            });
        }
        if (!dpContainer.dataset.ctxBound) {
            dpContainer.addEventListener('contextmenu', e => e.preventDefault());
            dpContainer.dataset.ctxBound = '1';
        }

        const video = window.dp.video;

        window.player = {
            currentTime(t) {
                if (typeof t === 'number') video.currentTime = t;
                return video.currentTime || 0;
            },
            duration() { return video.duration || 0; },
            on(ev, fn) {
                const map = { timeupdate: 'timeupdate', seeking: 'seeking', play: 'play', pause: 'pause', ended: 'ended', loadedmetadata: 'loadedmetadata' };
                if (map[ev]) video.addEventListener(map[ev], fn);
            },
            off(ev, fn) {
                const map = { timeupdate: 'timeupdate', seeking: 'seeking', play: 'play', pause: 'pause', ended: 'ended', loadedmetadata: 'loadedmetadata' };
                if (map[ev]) video.removeEventListener(map[ev], fn);
            },
            one(ev, fn) {
                const map = { loadedmetadata: 'loadedmetadata' };
                if (!map[ev]) return;
                const once = () => { fn(); video.removeEventListener(map[ev], once); };
                video.addEventListener(map[ev], once);
            },
        };
        setupResume(resumeAt);
        bindPlayerProgressHandlers();
    };

    const destroyXgPlayer = () => {
        if (window.xg) {
            try {
                try { if ('src' in window.xg) window.xg.src = ''; } catch (e) {}
                if (typeof window.xg.destroy === 'function') window.xg.destroy();
                else if (typeof window.xg.pause === 'function') window.xg.pause();
            } catch (e) {}
            window.xg = null;
        }
        if (xgContainer) xgContainer.innerHTML = '';
    };

    const initXgPlayer = (source, resumeAt) => {
        if (!xgContainer || typeof window.Player === 'undefined') return;
        let url = '';
        let mimeType = '';
        if (source && source.src) {
            url = source.src;
            mimeType = source.type || '';
        } else {
            url = xgContainer.getAttribute('data-play-src') || '';
            mimeType = xgContainer.getAttribute('data-play-type') || '';
        }
        if (!url) return;

        destroyXgPlayer();

        const isHls = /\.m3u8(\?|$)/i.test(url) || String(mimeType).indexOf('mpegURL') >= 0;
        const Ctor = (isHls && typeof window.HlsPlayer !== 'undefined') ? window.HlsPlayer : window.Player;

        try {
            window.xg = new Ctor({
                id: 'xgplayerContainer',
                url,
                width: '100%',
                height: '100%',
                autoplay: true,
                playsinline: true,
                lang: 'zh-cn',
                // 尽量贴合现有体验
                closeVideoClick: true,
                playbackRate: [0.5, 0.75, 1, 1.25, 1.5, 2],
                fluid: false,
            });
        } catch (e) {
            return;
        }

        // 统一进度接口
        const p = window.xg;
        window.player = {
            currentTime(t) {
                try {
                    if (typeof t === 'number') p.currentTime = t;
                    return Number(p.currentTime || 0);
                } catch (e) {
                    return 0;
                }
            },
            duration() {
                try { return Number(p.duration || 0); } catch (e) { return 0; }
            },
            on(ev, fn) {
                const map = { timeupdate: 'timeupdate', seeking: 'seeking', play: 'play', pause: 'pause', ended: 'ended', loadedmetadata: 'loadedmetadata' };
                const e2 = map[ev] || ev;
                try { if (typeof p.on === 'function') p.on(e2, fn); } catch (e) {}
            },
            off(ev, fn) {
                const map = { timeupdate: 'timeupdate', seeking: 'seeking', play: 'play', pause: 'pause', ended: 'ended', loadedmetadata: 'loadedmetadata' };
                const e2 = map[ev] || ev;
                try { if (typeof p.off === 'function') p.off(e2, fn); } catch (e) {}
            },
            one(ev, fn) {
                const map = { loadedmetadata: 'loadedmetadata' };
                const e2 = map[ev] || ev;
                if (typeof p.once === 'function') {
                    try { p.once(e2, fn); } catch (e) {}
                    return;
                }
                const once = () => {
                    fn();
                    try { if (typeof p.off === 'function') p.off(e2, once); } catch (e) {}
                };
                try { if (typeof p.on === 'function') p.on(e2, once); } catch (e) {}
            },
        };

        setupResume(resumeAt);
        bindPlayerProgressHandlers();
    };

    const initPlayer = (source, resumeAt) => {
        if (PLAYER_ENGINE === 'dplayer') initDPlayer(source, resumeAt);
        else if (PLAYER_ENGINE === 'xgplayer') initXgPlayer(source, resumeAt);
        else initVideoJs(source, resumeAt);
    };

    window.applyPlayerSource = async (source, resumeAt) => {
        if (!source || !source.src) return;

        if (PLAYER_ENGINE === 'dplayer') {
            initDPlayer(source, resumeAt);
            return;
        }
        if (PLAYER_ENGINE === 'xgplayer') {
            initXgPlayer(source, resumeAt);
            return;
        }

        if (!window.player || typeof window.player.src !== 'function') {
            initVideoJs(source, resumeAt);
            return;
        }

        window.player.src({ src: source.src, type: source.type || 'video/mp4' });
        window.player.load();
        setupResume(resumeAt);
        bindPlayerProgressHandlers();
        try {
            const p = window.player.play();
            if (p && typeof p.catch === 'function') p.catch(() => {});
        } catch (e) {}
    };

    const bindPlayerProgressHandlers = () => {
    if (typeof window.__playerProgressCleanup === 'function') {
        window.__playerProgressCleanup();
    }
    let lastSentAt = 0;
    let lastSavedProgress = -1;

    function buildProgressBody(flush) {
        const progress = Math.floor(window.player.currentTime() || 0);
        const duration = Math.floor(window.player.duration() || 0);
        writeWatchLocalProgress(progress, duration);

        const fd = new FormData();
        fd.append('video_id', WATCH_VIDEO_ID);
        fd.append('episode_id', WATCH_EPISODE_ID);
        fd.append('progress', progress);
        fd.append('duration', duration);
        if (flush) fd.append('flush', '1');
        return fd;
    }

    function sendProgress(flush = false) {
        const now = Date.now();
        const progress = Math.floor(window.player.currentTime() || 0);
        if (!flush) {
            if (now - lastSentAt < 5000) return;
            if (lastSavedProgress >= 0 && Math.abs(progress - lastSavedProgress) < 15) return;
        }
        lastSentAt = now;
        lastSavedProgress = progress;

        const fd = buildProgressBody(flush);
        fetch('api/save_progress.php', {
            method: 'POST',
            body: fd,
            credentials: 'same-origin',
        }).catch(() => {});
    }

    function sendProgressBeacon() {
        const fd = buildProgressBody(true);
        if (navigator.sendBeacon) {
            navigator.sendBeacon('api/save_progress.php', fd);
        } else {
            fetch('api/save_progress.php', { method: 'POST', body: fd, credentials: 'same-origin', keepalive: true }).catch(() => {});
        }
    }

    const progressTimer = setInterval(() => sendProgress(false), 5000);
    const pauseHandler = () => sendProgress(true);
    const endedHandler = () => sendProgress(true);
    const beforeUnloadHandler = () => {
        clearInterval(progressTimer);
        sendProgressBeacon();
    };
    window.player.on('pause', pauseHandler);
    window.player.on('ended', endedHandler);
    window.addEventListener('beforeunload', beforeUnloadHandler);
    window.__playerProgressCleanup = () => {
        clearInterval(progressTimer);
        if (window.player && typeof window.player.off === 'function') {
            window.player.off('pause', pauseHandler);
            window.player.off('ended', endedHandler);
        }
        window.removeEventListener('beforeunload', beforeUnloadHandler);
    };
    bindTrafficTrialLimit();
    };

    (async () => {
        try {
            const resumeAt = await resolveResumeSeconds();
            if (USE_PLAYER_PROXY) {
                const source = await loadProxiedPlayUrl();
                initPlayer(source, resumeAt);
                return;
            }
            if (PLAYER_ENGINE === 'dplayer') {
                if (dpContainer && dpContainer.getAttribute('data-play-src')) {
                    initPlayer(null, resumeAt);
                }
                return;
            }
            if (PLAYER_ENGINE === 'xgplayer') {
                if (xgContainer && xgContainer.getAttribute('data-play-src')) {
                    initPlayer(null, resumeAt);
                }
                return;
            }
            if (el && (el.getAttribute('data-src') || typeof videojs !== 'undefined')) {
                initPlayer(null, resumeAt);
            }
        } catch (e) {}
    })();
});
</script>
<?php include __DIR__ . '/components/theme-toggle-script.php'; ?>
<script>
window.VIDEO_COMMENTS_CONFIG = <?= json_encode([
    'videoId' => (int)$videoId,
    'maxLength' => COMMENT_MAX_LENGTH,
    'listUrl' => 'api/comments_list.php',
    'postUrl' => 'api/comments_post.php',
    'deleteUrl' => 'api/comments_delete.php',
    'currentUser' => [
        'id' => (int)$user['id'],
        'username' => (string)$user['username'],
        'display_name' => userDisplayName($user),
        'avatar' => userAvatarUrl($user),
    ],
], JSON_UNESCAPED_UNICODE) ?>;
</script>
<script src="assets/js/video-comments.js?v=1"></script>

</body>
</html>
