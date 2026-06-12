<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/traffic.php';
require_once __DIR__ . '/includes/announcement.php';
require_once __DIR__ . '/includes/user_profile.php';
require_once __DIR__ . '/includes/play_domains.php';
require_once __DIR__ . '/includes/m3u8_duration.php';

requireLogin();
$user = getCurrentUser();
$pdo = getDB();
ensureUserProfileSchema($pdo);

$homepageAnnouncement = null;
$announcementPopupHtml = '';
$announcementPopupFreq = '';
if ($user && !isAdmin()) {
    $homepageAnnouncement = getHomepageAnnouncementForUser($pdo, (int)$user['id']);
    if ($homepageAnnouncement) {
        $announcementPopupHtml = renderAnnouncementHtml($pdo, $homepageAnnouncement);
        $announcementPopupFreq = (string)($homepageAnnouncement['popup_frequency'] ?? ANNOUNCEMENT_FREQ_UNREAD);
    }
}
$stmt = $pdo->query("SELECT * FROM videos ORDER BY created_at DESC");
$videos = $stmt->fetchAll();
$videoDurations = m3u8_durations_for_videos($pdo, $user, $videos, isAdmin());

$trafficEnabled = trafficFeatureEnabled($pdo);
$userTraffic = ['total'=>0,'used'=>0,'left'=>0,'expires_at'=>null,'expired'=>false];
if ($trafficEnabled && !isAdmin()) {
    $userTraffic = getUserTraffic($pdo, (int)$user['id']);
}
$hasTrafficCol = (bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'is_traffic'")->fetch();

$unreadNotifications = 0;
try {
    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM notifications n
        LEFT JOIN notification_reads r
          ON r.notification_id = n.id AND r.user_id = ?
        WHERE (n.target_type = 'all' OR n.target_user_id = ?)
          AND r.id IS NULL
    ");
    $stmt->execute([(int)$user['id'], (int)$user['id']]);
    $unreadNotifications = (int)$stmt->fetchColumn();
    $_SESSION['unread_notification_count'] = $unreadNotifications;
} catch (Throwable $e) {
    $unreadNotifications = (int)($_SESSION['unread_notification_count'] ?? 0);
}
$unreadFeedbackReplies = (int)($_SESSION['unread_feedback_reply_count'] ?? 0);
?>
<!DOCTYPE html>
<html lang="zh-CN" class="scroll-smooth">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>首页 - 竹叶云控平台</title>
<link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
<?php include __DIR__ . '/components/theme-head.php'; ?>
<?php include __DIR__ . '/components/theme-dynamic.php'; ?>

<style>
.platform-managed-badge{
    background:rgba(30,41,59,.75);
    color:#e5e7eb;
}
.fade-up{opacity:0;transform:translateY(30px);transition:.6s}
.fade-up.show{opacity:1;transform:none}

/* === 导航图标统一风格（关键） === */
.nav-icon{
    color: rgb(107 114 128);
    transition: color .2s ease, transform .2s ease;
}
.group:hover .nav-icon{
    color: rgb(17 24 39);
    transform: translateY(-1px);
}
</style>
</head>

<body class="bg-gray-100 text-gray-900">

<!-- 顶部导航 -->
<nav class="bg-white/80 glass shadow-sm sticky top-0 z-50">
<div class="mx-auto max-w-screen-xl px-4 py-3 flex justify-between items-center">

<div class="flex flex-wrap items-center gap-2 text-sm">

<?php if (isAdmin()): ?>
    <?php $href = 'admin/users.php'; $hoverClass = 'hover:bg-gray-100/50'; include __DIR__ . '/components/admin-users-nav-link.php'; ?>
    <?php $href = 'admin/groups.php'; $hoverClass = 'hover:bg-gray-100/50'; include __DIR__ . '/components/admin-groups-nav-link.php'; ?>
    <?php $href = 'admin/domains.php'; $hoverClass = 'hover:bg-gray-100/50'; include __DIR__ . '/components/admin-domains-nav-link.php'; ?>
    <?php $href = 'admin/videos.php'; $hoverClass = 'hover:bg-gray-100/50'; include __DIR__ . '/components/admin-videos-nav-link.php'; ?>
    <?php $href = 'admin/upload_manage.php?section=overview'; $hoverClass = 'hover:bg-gray-100/50'; include __DIR__ . '/components/admin-upload-nav-link.php'; ?>
    <?php $href = 'admin/traffic.php'; $hoverClass = 'hover:bg-gray-100/50'; include __DIR__ . '/components/admin-traffic-nav-link.php'; ?>
    <?php $href = 'admin/index.php'; $hoverClass = 'hover:bg-gray-100/50'; include __DIR__ . '/components/admin-overview-nav-link.php'; ?>
<?php $href = 'admin/notifications.php'; $hoverClass = 'hover:bg-gray-100/50'; $badgeCount = $unreadNotifications; include __DIR__ . '/components/admin-notifications-nav-link.php'; ?>
    <?php $href = 'admin/feedback.php'; $hoverClass = 'hover:bg-gray-100/50'; include __DIR__ . '/components/admin-feedback-nav-link.php'; ?>
    <?php $href = 'admin/comments.php'; $hoverClass = 'hover:bg-gray-100/50'; include __DIR__ . '/components/admin-comments-nav-link.php'; ?>
    <?php $href = 'admin/mail.php'; $hoverClass = 'hover:bg-gray-100/50'; include __DIR__ . '/components/admin-mail-nav-link.php'; ?>
    <?php $href = 'admin/other_settings.php'; $hoverClass = 'hover:bg-gray-100/50'; include __DIR__ . '/components/admin-other-settings-nav-link.php'; ?>
<?php else: ?>

<a href="index.php" class="group relative rounded-full p-2 hover:bg-gray-100/50 inline-flex items-center justify-center">
    <img
        src="https://css.qinlinyun.cn/ico/index.png"
        alt="竹叶云控"
        class="h-4 w-4 rounded-sm"
    >
    <span class="absolute top-full left-1/2 -translate-x-1/2 mt-1 hidden md:block text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 whitespace-nowrap">首页</span>
</a>

<a href="upload.php" class="group relative rounded-full p-2 hover:bg-gray-100/50 inline-flex items-center justify-center">
    <svg class="w-5 h-5 shrink-0 nav-icon" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
        <path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M12 16V4m0 0l-4 4m4-4l4 4M4 20h16"/>
    </svg>
    <span class="absolute top-full left-1/2 -translate-x-1/2 mt-1 hidden md:block text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 whitespace-nowrap">视频上传</span>
</a>

<a href="/progress.php" class="group relative rounded-full p-2 hover:bg-gray-100/50 inline-flex items-center justify-center">
    <svg class="w-5 h-5 shrink-0" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M782.7968 239.36c-6.0416 0-12.0832-2.0992-16.9472-6.4a368.70144 368.70144 0 0 0-24.6784-19.968 25.6 25.6 0 0 1-5.3248-35.7888 25.6512 25.6512 0 0 1 35.84-5.3248c9.6256 7.168 19.0464 14.7968 28.0576 22.7328a25.60512 25.60512 0 0 1 2.2528 36.1472 25.8048 25.8048 0 0 1-19.2 8.6016z" fill="#1296db"/><path d="M519.68 935.3216c-196.864 0-366.1824-133.5296-411.8016-324.7616a25.58464 25.58464 0 0 1 18.944-30.8224c13.7728-3.328 27.5456 5.2224 30.8224 18.944 40.0896 168.0896 188.928 285.44 361.984 285.44 205.2096 0 372.1216-166.912 372.1216-372.1216 0-85.0432-27.904-165.0688-80.6912-231.424-8.8064-11.0592-6.9632-27.1872 4.096-35.9936s27.1872-6.9632 35.9936 4.096c60.0576 75.52 91.8528 166.6048 91.8528 263.3216 0 233.4208-189.9008 423.3216-423.3216 423.3216zM683.264 121.4464c-51.8656-21.76-106.9056-32.768-163.584-32.768-152.064 0-290.2528 80.7424-365.4656 209.6128V233.6768c0-14.1312-11.4688-25.6-25.6-25.6s-25.6 11.4688-25.6 25.6v126.464c0 14.1312 11.4688 25.6 25.6 25.6h129.6384c14.1312 0 25.6-11.4688 25.6-25.6s-11.4688-25.6-25.6-25.6H192.5632a371.51744 371.51744 0 0 1 327.1168-194.6624c49.8688 0 98.2016 9.6768 143.7696 28.7744a25.53856 25.53856 0 0 0 33.4848-13.7216c5.5296-13.0048-0.6144-28.0064-13.6704-33.4848z" fill="#1296db"/><path d="M704.6656 547.84H487.0144c-14.1312 0-25.6-11.4688-25.6-25.6V254.9248c0-14.1312 11.4688-25.6 25.6-25.6s25.6 11.4688 25.6 25.6V496.64h192.0512c14.1312 0 25.6 11.4688 25.6 25.6s-11.4688 25.6-25.6 25.6z" fill="#1296db"/></svg>
    <span class="absolute top-full left-1/2 -translate-x-1/2 mt-1 hidden md:block text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 whitespace-nowrap">观看记录</span>
</a>

<?php $href = 'notifications.php'; $navLinkLabel = '通知'; $hoverClass = 'hover:bg-gray-100/50'; $paddingClass = 'p-2'; $badgeCount = $unreadNotifications; include __DIR__ . '/components/admin-notifications-nav-link.php'; ?>

<?php $href = 'feedback.php'; $navLinkLabel = '反馈'; $hoverClass = 'hover:bg-gray-100/50'; $paddingClass = 'p-2'; include __DIR__ . '/components/admin-feedback-nav-link.php'; ?>

<?php if ($trafficEnabled): ?>
<a href="traffic.php" class="group relative rounded-full px-3 py-1.5 hover:bg-gray-100/50 flex items-center gap-1.5 text-xs">
    <svg class="w-5 h-5 shrink-0" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M512 128l133.12 102.4 5.12 5.12 5.12 5.12c61.44 30.72 112.64 71.68 158.72 128 107.52 163.84 61.44 384-102.4 491.52-61.44 40.96-128 61.44-199.68 61.44-117.76 0-230.4-61.44-296.96-158.72-81.92-117.76-81.92-276.48 0-394.24 46.08-51.2 97.28-97.28 153.6-133.12l5.12-5.12 5.12-5.12L512 128M512 0L317.44 148.48C245.76 189.44 179.2 240.64 128 307.2c-102.4 153.6-102.4 358.4 0 512 87.04 133.12 235.52 204.8 384 204.8 87.04 0 179.2-25.6 256-76.8 209.92-143.36 266.24-430.08 128-640-51.2-66.56-117.76-117.76-189.44-158.72L512 0z" fill="#00D6D6"/><path d="M512 716.8c-15.36 0-25.6-5.12-35.84-15.36L409.6 634.88l-66.56 66.56c-20.48 20.48-51.2 20.48-71.68 0s-20.48-51.2 0-71.68l102.4-102.4c20.48-20.48 51.2-20.48 71.68 0l66.56 66.56 168.96-168.96c20.48-20.48 51.2-20.48 71.68 0s20.48 51.2 0 71.68l-204.8 204.8c-10.24 10.24-20.48 15.36-35.84 15.36z" fill="#00D6D6"/></svg>
    <span class="font-semibold">
        流量
        <span class="<?= $userTraffic['left'] > 0 ? 'text-green-600' : 'text-red-500' ?>">
            <?= (int)$userTraffic['left'] ?>
        </span>
        / <?= (int)$userTraffic['total'] ?>
    </span>
    <span class="absolute top-full mt-1 hidden md:block text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 whitespace-nowrap">
        合计:<?= (int)$userTraffic['total'] ?>（基础<?= (int)($userTraffic['base_total'] ?? 0) ?>+收益<?= (int)($userTraffic['earning_available'] ?? 0) ?>）
        · 剩余:<?= (int)$userTraffic['left'] ?>
        <?php if ($userTraffic['expired']): ?> · 基础已过期<?php endif; ?>
    </span>
</a>
<?php endif; ?>

<?php endif; ?>

<a href="GF-token/" class="group relative rounded-full p-2 hover:bg-gray-100/50 inline-flex items-center justify-center">
<svg class="w-5 h-5 shrink-0" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M307.173388 679.532423c0 28.903692-22.882089 52.26751-51.183621 52.267509h-51.123405C101.174367 731.920364 13.800914 652.796507 1.456629 547.599112-10.82744 442.401716 55.771484 344.49046 156.57311 319.561025 177.046558 156.255165 301.69373 27.031576 461.266196 3.727974c159.452034-23.243386 314.568515 65.213955 378.939445 216.17553 121.997667 36.731775 199.074179 159.452034 180.828723 288.073463-18.245456 128.621429-126.212788 224.003613-253.449249 223.822965a51.725565 51.725565 0 0 1-51.123405-52.267509c0-28.903692 22.882089-52.26751 51.183621-52.26751 76.35392 0.180648 141.266795-57.024576 152.226111-134.281736 10.959317-77.196944-35.346807-150.901359-108.569493-172.880208l-45.764179-13.849685-19.08848-44.559859c-45.884611-107.846901-156.682097-171.073727-270.671032-154.51432-113.928719 16.619623-203.048436 108.930789-217.68093 225.56923L249.305788 404.224756l-68.525836 16.920703c-49.979301 12.826013-82.917466 61.600994-76.835648 113.92872 6.142035 52.387942 49.37714 91.829438 100.982274 92.190734h51.183621c28.241316 0 51.183621 23.424034 51.183621 52.26751z m385.743856 107.003876a53.050318 53.050318 0 0 1 0 73.885063l-145.000188 148.010989a50.461029 50.461029 0 0 1-72.379662 0L330.597422 860.421362a53.050318 53.050318 0 0 1 0.60216-73.222687 50.400813 50.400813 0 0 1 71.777502-0.662376l57.747168 59.011704V418.25509c0-28.903692 22.882089-52.26751 51.183621-52.26751 28.241316 0 51.123405 23.363818 51.123405 52.26751v427.112265l57.626736-58.831056a50.400813 50.400813 0 0 1 72.25923 0z" fill="#1296db"/></svg>
<span class="absolute top-full left-1/2 -translate-x-1/2 mt-1 hidden md:block text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 whitespace-nowrap">视频下载</span>
</a>

<a href="user_home.php" class="group relative rounded-full p-2 hover:bg-gray-100/50 inline-flex items-center justify-center">
<?php include __DIR__ . '/components/user-avatar.php'; ?>
<span class="absolute top-full left-1/2 -translate-x-1/2 mt-1 hidden md:block text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 whitespace-nowrap">
<?=htmlspecialchars($user['username'])?>
</span>
</a>

<?php $hoverClass = 'hover:bg-gray-100/50'; include __DIR__ . '/components/logout-nav-link.php'; ?>

</div>

<!-- 深色模式 -->
<button id="darkToggle" class="theme-btn rounded-full p-2 hover:bg-gray-100/50">
<svg id="themeIcon" class="nav-icon w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24"></svg>
</button>

</div>
</nav>

<?php
$navNotificationsHref = isAdmin() ? 'admin/notifications.php' : 'notifications.php';
?>
<?php if ($unreadNotifications > 0 && !isAdmin()): ?>
    <div id="navUnreadNotifToast" class="fixed top-16 right-4 z-[105] hidden w-[320px] rounded-2xl border border-gray-200 bg-white/95 p-4 shadow-xl backdrop-blur">
        <div class="flex items-start justify-between gap-3">
            <div class="min-w-0">
                <p class="text-sm font-semibold text-gray-900">你有未读通知</p>
                <p class="mt-1 text-xs text-gray-600">
                    当前未读 <span class="font-semibold text-red-600"><?= (int)$unreadNotifications ?></span> 条，点击查看。
                </p>
            </div>
            <button type="button" class="shrink-0 rounded-lg px-2 py-1 text-sm text-gray-500 hover:bg-gray-100" aria-label="关闭" onclick="window.__hideUnreadNotifToast && window.__hideUnreadNotifToast()">&times;</button>
        </div>
        <div class="mt-3 flex justify-end gap-2">
            <a href="<?= htmlspecialchars($navNotificationsHref, ENT_QUOTES, 'UTF-8') ?>" class="rounded-xl bg-gray-900 px-3 py-2 text-xs font-semibold text-white hover:bg-black">
                立即查看
            </a>
        </div>
    </div>
    <script>
    (() => {
        const unread = <?= (int)$unreadNotifications ?>;
        if (!unread) return;
        const toast = document.getElementById('navUnreadNotifToast');
        if (!toast) return;

        function showToast() {
            toast.style.display = 'block';
            window.__hideUnreadNotifToast = () => { toast.style.display = 'none'; };
            // 自动隐藏：8 秒
            window.setTimeout(() => { try { toast.style.display = 'none'; } catch (e) {} }, 8000);
        }

        function scheduleToast() {
            const ann = document.getElementById('siteAnnouncementModal');
            if (!ann) {
                window.setTimeout(showToast, 450);
                return;
            }
            // 公告优先：等公告 modal 被移除后再显示
            const obs = new MutationObserver(() => {
                if (!document.getElementById('siteAnnouncementModal')) {
                    obs.disconnect();
                    window.setTimeout(showToast, 450);
                }
            });
            obs.observe(document.body, { childList: true, subtree: true });
        }

        if (document.readyState === 'loading') {
            document.addEventListener('DOMContentLoaded', scheduleToast);
        } else {
            scheduleToast();
        }
    })();
    </script>
<?php endif; ?>

<!-- 主体 -->
<main class="mx-auto max-w-screen-xl px-4 py-6">

<div class="mb-4 inline-flex items-center gap-2 rounded-lg platform-managed-badge px-3 py-1.5 shadow fade-up">
    <img
        src="https://css.qinlinyun.cn/ico/index.png"
        alt="竹叶云控"
        class="h-4 w-4 rounded-sm shrink-0"
    >
    <span class="text-sm font-medium text-gray-200">由竹叶云控平台代管理</span>
</div>

<?php if (empty($videos)): ?>
<div class="rounded-lg glass p-8 text-center text-sm shadow fade-up">暂无视频</div>
<?php else: ?>
<div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
<?php foreach ($videos as $video): ?>
<div class="fade-up">
<div class="overflow-hidden rounded-lg glass shadow hover:shadow-lg transition">
<div class="aspect-video bg-black">
<?php if ($video['cover']): ?>
<?php $coverUrl = play_build_group_domain_url($pdo, $user, $video, (string)$video['cover'], isAdmin()); ?>
<img class="h-full w-full object-cover" src="<?=htmlspecialchars($coverUrl)?>">
<?php else: ?>
<div class="flex h-full items-center justify-center text-gray-300">暂无封面</div>
<?php endif; ?>
</div>
<div class="p-4">
<h3 class="mb-1 text-sm font-semibold line-clamp-1 flex items-center gap-1.5">
<span class="line-clamp-1 flex-1"><?=htmlspecialchars($video['title'])?></span>
<?php if ($hasTrafficCol && !empty($video['is_traffic'])): ?>
    <span class="rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-normal text-amber-700">流量 <?= (int)$video['traffic_cost'] ?> · 试看<?= (int)trafficTrialPercent() ?>%</span>
<?php endif; ?>
</h3>
<p class="mb-3 text-xs text-gray-500 line-clamp-2"><?=htmlspecialchars($video['description'] ?? '')?></p>
<div class="flex flex-wrap items-center gap-2">
<a href="play.php?id=<?=$video['id']?>" class="inline-block rounded bg-red-600 px-3 py-1 text-xs font-semibold text-white hover:bg-red-700">
观看
</a>
<?php $durationLabel = $videoDurations[(int)$video['id']] ?? ''; ?>
<span
    class="video-duration-badge inline-flex items-center gap-1 rounded-full bg-gray-100 px-2 py-0.5 text-[11px] font-medium text-gray-600<?= $durationLabel === '' ? ' hidden' : '' ?>"
    data-video-id="<?= (int)$video['id'] ?>"
>
<svg class="h-3.5 w-3.5 shrink-0" viewBox="0 0 1037 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M557.277405 779.762162H195.597838c-49.165838 0-88.492973-39.866811-88.492973-89.046486v-452.774054c0-48.889081 39.61773-89.046486 88.492973-89.046487h511.16973a53.275676 53.275676 0 0 1 53.234162 53.510919v22.500324l60.886486-24.105513q21.946811-8.814703 42.219243 4.857081 20.300108 13.699459 20.300109 37.472865v176.390919a247.904865 247.904865 0 0 0-35.978379-16.688433V243.130811q0-4.649514-4.428108-7.638487-4.469622-3.002811-8.870054-1.245405l-110.107676 43.616865v-75.45773c0-9.686486-7.569297-17.532541-17.255783-17.53254h-511.16973c-29.004108 0-52.514595 24.050162-52.514595 53.05427v452.787892c0 29.308541 23.219892 53.068108 52.514595 53.068108H536.216216c6.033297 12.60627 13.090595 24.631351 21.075027 35.978378z" fill="#1296db"/><path d="M557.277405 783.221622H195.597838c-51.075459 0-91.952432-41.416649-91.952433-92.505946v-452.774054c0-50.784865 41.167568-92.505946 91.952433-92.505946h511.16973a56.735135 56.735135 0 0 1 56.693621 56.970378v22.500324h-3.459459l-1.259244-3.210378 60.858811-24.105514q23.662703-9.492757 45.44346 5.203028 21.82227 14.723459 21.82227 40.337297v182.313513l-5.161513-2.905946 5.161513 2.905946-5.161513-2.905946q-17.08973-9.658811-35.480217-16.467027l-2.255567-0.83027V243.130811q0-2.809081-2.905946-4.774054-2.933622-1.978811-5.645838-0.885622l-114.854054 45.484973v-80.536216c0-7.638486-6.171676-14.086919-13.796324-14.086919h-511.16973c-27.398919 0-49.055135 22.20973-49.055135 49.594811v452.787892c0 27.094486 21.960649 49.608649 49.055135 49.608648h342.790919l0.940973 1.964973-0.940973-1.964973 0.940973 1.964973q8.883892 18.584216 20.770594 35.480217l3.846919 5.452108h-6.669838z m0-6.918919v3.459459l-2.836756 1.992649q-12.218811-17.366486-21.351784-36.490379l3.113513-1.480648-3.113513 1.480648 3.113513-1.480648V747.243243H195.597838c-30.91373 0-55.974054-25.6-55.974054-56.527567v-452.774054c0-31.218162 24.76973-56.527568 55.974054-56.527568h511.16973c11.443892 0 20.715243 9.548108 20.715243 20.992v75.45773h-3.45946l-1.273081-3.224216 110.093838-43.58919q6.102486-2.463135 12.080433 1.577514 5.964108 4.012973 5.964108 10.502919v159.702486h-3.45946l1.203892-3.238054q18.902486 6.988108 36.476541 16.923676l-1.702054 3.002811 1.702054-3.016649-1.702054 3.016649h-3.45946V243.130811q0-21.932973-18.777946-34.594595-18.75027-12.661622-39.022703-4.511135l-65.591351 25.98746v-27.606487a49.816216 49.816216 0 0 0-49.78854-50.051459h-511.16973c-46.965622 0-85.033514 38.607568-85.033514 85.573189v452.787892c0 47.270054 37.777297 85.587027 85.033514 85.587027h361.679567z" fill="#1296db"/><path d="M761.081081 477.405405c-87.884108 0-159.135135 71.251027-159.135135 159.135136S673.196973 795.675676 761.081081 795.675676 920.216216 724.424649 920.216216 636.540541 848.965189 477.405405 761.081081 477.405405z m0-41.513513c110.813405 0 200.648649 89.835243 200.648649 200.648649S871.894486 837.189189 761.081081 837.189189 560.432432 747.353946 560.432432 636.540541 650.267676 435.891892 761.081081 435.891892z" fill="#1296db"/><path d="M779.900541 541.612973v92.713513h-20.756757l14.875675-14.474378 79.014055 81.228108-29.751352 28.948757-84.895135-87.275243V541.612973h41.513514z" fill="#1296db"/><path d="M249.081081 249.081081c-22.970811 0-41.513514 18.542703-41.513513 41.513514s18.542703 41.513514 41.513513 41.513513 41.513514-18.542703 41.513514-41.513513-18.542703-41.513514-41.513514-41.513514z" fill="#1296db"/></svg>
<span class="video-duration-text"><?= htmlspecialchars($durationLabel, ENT_QUOTES, 'UTF-8') ?></span>
</span>
</div>
</div>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>

</main>

<script>
const observer=new IntersectionObserver(e=>e.forEach(i=>i.isIntersecting&&i.target.classList.add('show')),{threshold:.15});
document.querySelectorAll('.fade-up').forEach(el=>observer.observe(el));

(function loadMissingVideoDurations(){
    const badges=document.querySelectorAll('.video-duration-badge.hidden[data-video-id]');
    if(!badges.length)return;
    badges.forEach(badge=>{
        const id=badge.dataset.videoId;
        if(!id)return;
        fetch('api/video_duration.php?video_id='+encodeURIComponent(id),{credentials:'same-origin'})
            .then(r=>r.json())
            .then(data=>{
                if(!data.ok||!data.duration)return;
                const text=badge.querySelector('.video-duration-text');
                if(text)text.textContent=data.duration;
                badge.classList.remove('hidden');
            })
            .catch(()=>{});
    });
})();
</script>
<?php if ($homepageAnnouncement): ?>
    <?php include __DIR__ . '/components/announcement-popup.php'; ?>
<?php endif; ?>
<?php include __DIR__ . '/components/theme-toggle-script.php'; ?>

</body>
</html>
