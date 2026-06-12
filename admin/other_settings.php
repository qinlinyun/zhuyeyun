<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/flash.php';
require_once '../includes/settings.php';
require_once '../includes/player_proxy.php';
require_once '../includes/player_config.php';
require_once '../includes/video_data_sync.php';
require_once '../includes/player_video_token.php';
require_once '../includes/analytics_config.php';
require_once '../includes/redis_config.php';
require_once '../includes/announcement.php';
require_once '../includes/mail_config.php';
require_once '../includes/traffic.php';

requireAdmin();

$pdo = getDB();
ensureAnnouncementTables($pdo);
$message = '';
$error = '';
applyFlash($message, $error);

$menu = require __DIR__ . '/../includes/other_settings_menu.php';

$activeSection = trim((string)($_GET['section'] ?? ''));
$activeItem = trim((string)($_GET['item'] ?? ''));
$menuIds = array_column($menu, 'id');
if ($activeSection === '' || !in_array($activeSection, $menuIds, true)) {
    $activeSection = $menu[0]['id'] ?? 'overview';
    $activeItem = '';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'register_toggle') {
    if ($activeSection !== 'register' || $activeItem !== 'toggle') {
        $activeSection = 'register';
        $activeItem = 'toggle';
    }

    $enabled = isset($_POST['register_enabled']) ? '1' : '0';
    setSetting($pdo, 'register_enabled', $enabled);
    if ($enabled === '1') {
        disableRegisterSchedule($pdo);
    }
    finishPostRequest($enabled === '1' ? '注册开关已开启，定时开/关注册已自动关闭' : '注册开关已保存');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'register_closed_page') {
    if ($activeSection !== 'register' || $activeItem !== 'closed_page') {
        $activeSection = 'register';
        $activeItem = 'closed_page';
    }

    saveRegisterClosedPageConfig($pdo, parseRegisterClosedPageConfigFromPost($_POST));
    finishPostRequest('关闭注册页面配置已保存');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'register_schedule') {
    if ($activeSection !== 'register' || $activeItem !== 'schedule') {
        $activeSection = 'register';
        $activeItem = 'schedule';
    }

    $scheduleConfig = parseRegisterScheduleConfigFromPost($_POST, $pdo);
    if ($validationError = registerScheduleValidationError($scheduleConfig)) {
        flashSet('error', $validationError);
        header('Location: other_settings.php?section=register&item=schedule');
        exit;
    }

    saveRegisterScheduleConfig($pdo, $scheduleConfig);
    applyRegisterSchedule($pdo);
    finishPostRequest('定时开/关注册配置已保存');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'register_page') {
    if ($activeSection !== 'register' || $activeItem !== 'page') {
        $activeSection = 'register';
        $activeItem = 'page';
    }

    saveRegisterPageConfig($pdo, parseRegisterPageConfigFromPost($_POST));
    finishPostRequest('注册页面配置已保存');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'theme_background') {
    if ($activeSection !== 'theme' || $activeItem !== 'background') {
        $activeSection = 'theme';
        $activeItem = 'background';
    }

    if (isset($_POST['theme_background_reset'])) {
        saveThemeBackgroundConfig($pdo, defaultThemeBackgroundConfig());
        finishPostRequest('背景设置已恢复初始化值', null, 'other_settings.php?section=theme&item=background');
    }

    $bgConfig = parseThemeBackgroundConfigFromPost($_POST);
    $uploadDir = dirname(__DIR__) . '/uploads/theme';

    if ($bgConfig['mode'] === 'image') {
        $pcUpload = saveThemeBackgroundImage($_FILES['bg_image_pc_file'] ?? [], $uploadDir);
        if ($pcUpload['error']) {
            flashSet('error', $pcUpload['error']);
            header('Location: other_settings.php?section=theme&item=background');
            exit;
        }
        if ($pcUpload['path']) {
            $bgConfig['bg_image_pc'] = $pcUpload['path'];
        }

        $mobileUpload = saveThemeBackgroundImage($_FILES['bg_image_mobile_file'] ?? [], $uploadDir);
        if ($mobileUpload['error']) {
            flashSet('error', $mobileUpload['error']);
            header('Location: other_settings.php?section=theme&item=background');
            exit;
        }
        if ($mobileUpload['path']) {
            $bgConfig['bg_image_mobile'] = $mobileUpload['path'];
        }
    }

    if ($validationError = themeBackgroundValidationError($bgConfig)) {
        flashSet('error', $validationError);
        header('Location: other_settings.php?section=theme&item=background');
        exit;
    }

    saveThemeBackgroundConfig($pdo, $bgConfig);
    finishPostRequest('背景设置已保存', null, 'other_settings.php?section=theme&item=background');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'theme_dark_colors') {
    if ($activeSection !== 'theme' || $activeItem !== 'dark_colors') {
        $activeSection = 'theme';
        $activeItem = 'dark_colors';
    }

    if (isset($_POST['theme_colors_reset'])) {
        saveThemeDarkColorsConfig($pdo, defaultThemeDarkColorsConfig());
        finishPostRequest('深色主题颜色已恢复初始化值', null, 'other_settings.php?section=theme&item=dark_colors');
    }

    saveThemeDarkColorsConfig($pdo, parseThemeColorsConfigFromPost($_POST, 'dark'));
    finishPostRequest('深色主题颜色已保存', null, 'other_settings.php?section=theme&item=dark_colors');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'theme_light_colors') {
    if ($activeSection !== 'theme' || $activeItem !== 'light_colors') {
        $activeSection = 'theme';
        $activeItem = 'light_colors';
    }

    if (isset($_POST['theme_colors_reset'])) {
        saveThemeLightColorsConfig($pdo, defaultThemeLightColorsConfig());
        finishPostRequest('浅色主题颜色已恢复初始化值', null, 'other_settings.php?section=theme&item=light_colors');
    }

    saveThemeLightColorsConfig($pdo, parseThemeColorsConfigFromPost($_POST, 'light'));
    finishPostRequest('浅色主题颜色已保存', null, 'other_settings.php?section=theme&item=light_colors');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'font_global') {
    if ($activeSection !== 'font' || $activeItem !== 'global') {
        $activeSection = 'font';
        $activeItem = 'global';
    }

    $existing = getFontConfig($pdo);
    $fontConfig = parseFontConfigFromPost($_POST);
    $uploadDir = dirname(__DIR__) . '/uploads/fonts';

    if ($fontConfig['mode'] === 'upload') {
        $fontUpload = saveCustomFontFile($_FILES['font_file_upload'] ?? [], $uploadDir);
        if ($fontUpload['error']) {
            fontConfigDraftSet(mergeFontConfigPreserveFields($fontConfig, $existing));
            flashSet('error', $fontUpload['error']);
            header('Location: other_settings.php?section=font&item=global');
            exit;
        }
        if ($fontUpload['path']) {
            $fontConfig['font_file'] = $fontUpload['path'];
            $fontConfig['font_format'] = $fontUpload['format'] ?? $fontConfig['font_format'];
        }
    }

    $fontConfig = mergeFontConfigPreserveFields($fontConfig, $existing);

    if ($validationError = fontConfigValidationError($fontConfig)) {
        fontConfigDraftSet($fontConfig);
        flashSet('error', $validationError);
        header('Location: other_settings.php?section=font&item=global');
        exit;
    }

    saveFontConfig($pdo, $fontConfig);
    fontConfigDraftClear();
    finishPostRequest('全局字体设置已保存', null, 'other_settings.php?section=font&item=global');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'analytics_settings') {
    if ($activeSection !== 'analytics' || $activeItem !== 'settings') {
        $activeSection = 'analytics';
        $activeItem = 'settings';
    }

    saveAnalyticsConfig($pdo, parseAnalyticsConfigFromPost($_POST));
    finishPostRequest('数据分析减负设置已保存');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'announcement_template') {
    $announcementConfig = parseAnnouncementConfigFromPost($_POST);
    if ($validationError = announcementConfigValidationError($announcementConfig)) {
        flashSet('error', $validationError);
        header('Location: other_settings.php?section=announcement');
        exit;
    }
    saveAnnouncementConfig($pdo, $announcementConfig);
    finishPostRequest('公告模板配置已保存', null, 'other_settings.php?section=announcement');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'announcement_save') {
    $result = saveAnnouncementFromPost($pdo, $_POST, (int)$_SESSION['user_id']);
    if (empty($result['ok'])) {
        flashSet('error', $result['message'] ?? '保存失败');
        $redirect = 'other_settings.php?section=announcement';
        if (!empty($_POST['announcement_id'])) {
            $redirect .= '&edit=' . (int)$_POST['announcement_id'];
        }
        header('Location: ' . $redirect);
        exit;
    }
    finishPostRequest($result['message'] ?? '公告已保存', null, 'other_settings.php?section=announcement&edit=' . (int)($result['id'] ?? 0));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'announcement_delete') {
    $result = deleteAnnouncement($pdo, (int)($_POST['announcement_id'] ?? 0));
    if (empty($result['ok'])) {
        flashSet('error', $result['message'] ?? '删除失败');
    } else {
        flashSet('success', $result['message'] ?? '已删除');
    }
    header('Location: other_settings.php?section=announcement');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'redis_config') {
    if ($activeSection !== 'redis' || $activeItem !== 'config') {
        $activeSection = 'redis';
        $activeItem = 'config';
    }

    try {
        writeRedisConfigFile(parseRedisConfigFromPost($_POST));
        finishPostRequest('Redis 配置已保存', null, 'other_settings.php?section=redis&item=config');
    } catch (Throwable $e) {
        flashSet('error', $e->getMessage());
        header('Location: other_settings.php?section=redis&item=config');
        exit;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'earning_traffic_action') {
    $activeSection = 'earning_traffic';
    $activeItem = '';
    $action = (string)($_POST['earning_action'] ?? '');
    $reason = (string)($_POST['reason'] ?? '');
    $adminId = (int)$_SESSION['user_id'];
    if (in_array($action, ['freeze', 'reclaim'], true)) {
        $result = adjustTrafficEarningLog($pdo, (int)($_POST['log_id'] ?? 0), $action, $reason, $adminId);
    } elseif (in_array($action, ['freeze_user', 'reclaim_user'], true)) {
        $result = adjustAllTrafficEarningsForUser(
            $pdo,
            (int)($_POST['user_id'] ?? 0),
            $action === 'freeze_user' ? 'freeze' : 'reclaim',
            $reason,
            $adminId
        );
    } else {
        $result = ['ok' => false, 'message' => '未知收益流量操作'];
    }

    if (empty($result['ok'])) {
        flashSet('error', $result['message'] ?? '操作失败');
    } else {
        flashSet('success', $result['message'] ?? '操作成功');
    }
    header('Location: other_settings.php?section=earning_traffic');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'player_proxy') {
    if ($activeSection !== 'player' || $activeItem !== 'proxy') {
        $activeSection = 'player';
        $activeItem = 'proxy';
    }

    $proxyConfig = [
        'enabled' => isset($_POST['player_proxy_enabled']),
        'backends' => playerBackendEntriesFromPost(
            $_POST['player_proxy_backend_name'] ?? null,
            $_POST['player_proxy_backend_url'] ?? null
        ),
        'api_secret' => trim((string)($_POST['player_proxy_api_secret'] ?? '')),
        'token_ttl' => (int)($_POST['player_proxy_token_ttl'] ?? 7200),
    ];

    if ($validationError = playerProxyValidationError($proxyConfig)) {
        flashSet('error', $validationError);
        header('Location: other_settings.php?section=player&item=proxy');
        exit;
    }

    savePlayerProxyConfig($pdo, $proxyConfig);
    $policyMsg = '';
    if ($proxyConfig['enabled']) {
        $policyPush = pushPlayerPolicyToSliceBackend($pdo);
        if (!$policyPush['ok']) {
            $policyMsg = '（策略同步提示：' . $policyPush['message'] . '）';
        }
    }
    finishPostRequest(($proxyConfig['enabled'] ? '后端代理已开启' : '后端代理已关闭') . $policyMsg);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'player_video_data') {
    if ($activeSection !== 'player' || $activeItem !== 'video_data') {
        $activeSection = 'player';
        $activeItem = 'video_data';
    }

    $playerCfg = [
        'engine' => $_POST['player_engine'] ?? 'videojs',
        'token_auto_duration' => isset($_POST['player_token_auto_duration']),
        'anti_download' => isset($_POST['player_anti_download']),
    ];

    if ($validationError = playerConfigValidationError($pdo, $playerCfg)) {
        flashSet('error', $validationError);
        header('Location: other_settings.php?section=player&item=video_data');
        exit;
    }

    savePlayerConfig($pdo, $playerCfg);
    $policyMsg = '';
    if (isPlayerProxyEnabled($pdo)) {
        $policyPush = pushPlayerPolicyToSliceBackend($pdo);
        if (!$policyPush['ok']) {
            $policyMsg = '（' . $policyPush['message'] . '）';
        }
    }
    finishPostRequest('视频数据设置已保存' . $policyMsg);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'player_api_sync') {
    if ($activeSection !== 'player' || $activeItem !== 'api_sync') {
        $activeSection = 'player';
        $activeItem = 'api_sync';
    }

    $sgRaw = trim((string)($_POST['video_sync_server_group_id'] ?? ''));
    $syncConfig = [
        'enabled' => isset($_POST['video_sync_enabled']),
        'backends' => playerBackendEntriesFromPost(
            $_POST['video_sync_backend_name'] ?? null,
            $_POST['video_sync_backend_url'] ?? null
        ),
        'api_secret' => trim((string)($_POST['video_sync_api_secret'] ?? '')),
        'path_prefix' => trim((string)($_POST['video_sync_path_prefix'] ?? 'storage/')),
        'server_group_id' => $sgRaw === '' ? null : (int)$sgRaw,
    ];

    if ($validationError = videoDataSyncValidationError($pdo, $syncConfig)) {
        flashSet('error', $validationError);
        header('Location: other_settings.php?section=player&item=api_sync');
        exit;
    }

    saveVideoDataSyncConfig($pdo, $syncConfig);
    finishPostRequest($syncConfig['enabled'] ? '视频数据同步已开启' : '视频数据同步配置已保存');
}

$currentGroup = null;
$currentItem = null;

foreach ($menu as $group) {
    if ($group['id'] === $activeSection) {
        $currentGroup = $group;
        foreach ($group['children'] ?? [] as $child) {
            if ($child['id'] === $activeItem) {
                $currentItem = $child;
                break;
            }
        }
        break;
    }
}

$pageTitle = $currentItem
    ? $currentGroup['label'] . ' · ' . $currentItem['label']
    : ($currentGroup ? $currentGroup['label'] : '其它设置');
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> - 竹叶云控平台</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $themeAssetPrefix = '../'; include __DIR__ . '/../components/theme-head.php'; ?>

<?php include __DIR__ . '/../components/theme-dynamic.php'; ?>
</head>

<body class="bg-gray-100 text-gray-900">

<?php $adminNavActive = 'other_settings'; include __DIR__ . '/../components/admin-top-nav.php'; ?>

<main class="mx-auto max-w-screen-xl px-4 py-6">
    <div class="flex gap-4 items-start">
        <?php include __DIR__ . '/../components/admin-other-settings-sidebar.php'; ?>

        <section class="min-w-0 flex-1">
            <?php
            $panelTitle = $currentItem
                ? $currentItem['label']
                : ($currentGroup ? $currentGroup['label'] : '其它设置');
            $panelMessage = $currentItem
                ? '「' . $currentGroup['label'] . ' / ' . $currentItem['label'] . '」功能开发中，敬请期待…'
                : ($currentGroup
                    ? '「' . $currentGroup['label'] . '」功能开发中，敬请期待…'
                    : '请从左侧选择设置项');
            $isRegisterToggle = $activeSection === 'register' && $activeItem === 'toggle';
            $isOverview = $activeSection === 'overview';
            $isRegisterClosedPage = $activeSection === 'register' && $activeItem === 'closed_page';
            $isRegisterPage = $activeSection === 'register' && $activeItem === 'page';
            $isRegisterSchedule = $activeSection === 'register' && $activeItem === 'schedule';
            $isAnalyticsSettings = $activeSection === 'analytics' && $activeItem === 'settings';
            $isUserGrowth = $activeSection === 'analytics' && $activeItem === 'user_growth';
            $isVideoClicks = $activeSection === 'analytics' && $activeItem === 'video_clicks';
            $isUserVisits = $activeSection === 'analytics' && $activeItem === 'user_visits';
            $isIpVisits = $activeSection === 'analytics' && $activeItem === 'ip_visits';
            $isThemeBackground = $activeSection === 'theme' && $activeItem === 'background';
            $isThemeDarkColors = $activeSection === 'theme' && $activeItem === 'dark_colors';
            $isThemeLightColors = $activeSection === 'theme' && $activeItem === 'light_colors';
            $isFontGlobal = $activeSection === 'font' && $activeItem === 'global';
            $isPlayerProxy = $activeSection === 'player' && $activeItem === 'proxy';
            $isPlayerVideoData = $activeSection === 'player' && $activeItem === 'video_data';
            $isPlayerVideoToken = $activeSection === 'player' && $activeItem === 'video_token';
            $isPlayerApiSync = $activeSection === 'player' && $activeItem === 'api_sync';
            $isRedisConfig = $activeSection === 'redis' && $activeItem === 'config';
            $isAnnouncement = $activeSection === 'announcement';
            $isEarningTraffic = $activeSection === 'earning_traffic';
            $playerDescriptions = [];
            $isPlayerPlaceholder = $activeSection === 'player' && isset($playerDescriptions[$activeItem]);
            $playerVideoDataDescription = '管理播放器相关的视频数据配置与展示。';
            $videoSyncServerGroupFeature = (bool)$pdo->query("SHOW TABLES LIKE 'server_groups'")->fetch()
                && (bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'server_group_id'")->fetch();
            $videoSyncServerGroups = [];
            if ($videoSyncServerGroupFeature) {
                $videoSyncServerGroups = $pdo->query('SELECT id, name FROM server_groups ORDER BY id')->fetchAll();
            }
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
            $syncEndpointBase = $scheme . '://' . $host . rtrim(dirname($_SERVER['SCRIPT_NAME'] ?? '/admin'), '/\\');
            $videoSyncEndpointUrl = dirname($syncEndpointBase) . '/api/video_data_sync.php';
            $themeColorsDescriptions = [
                'dark_colors' => '自定义深色模式下的主色、文字色、边框色等，用于统一全站暗色视觉风格。',
                'light_colors' => '自定义浅色模式下的主色、文字色、边框色等，用于统一全站浅色视觉风格。',
            ];
            $analyticsDescriptions = [
                'user_growth' => '展示用户注册数量随时间变化的趋势，用于观察平台用户增长情况。',
                'video_clicks' => '展示各视频点击量排行，用于了解热门内容与用户偏好。',
                'user_visits' => '展示用户访问频次与活跃趋势，用于分析用户留存与活跃情况。',
                'ip_visits' => '展示 IP 访问分布与趋势，用于了解访问来源与异常流量。',
            ];
            $isAnalyticsPlaceholder = $activeSection === 'analytics'
                && isset($analyticsDescriptions[$activeItem])
                && !in_array($activeItem, ['settings', 'user_growth', 'video_clicks', 'user_visits', 'ip_visits'], true);
            ?>
            <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
                <div class="border-b border-gray-100 px-4 py-2 text-sm font-semibold text-gray-700">
                    <?= htmlspecialchars($panelTitle, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php if ($isOverview): ?>
                    <?php
                    $registerEnabled = isRegisterMasterEnabled($pdo);
                    $analyticsConfig = getAnalyticsConfig($pdo);
                    $bgConfig = getThemeBackgroundConfig($pdo);
                    $playerProxyEnabled = isPlayerProxyEnabled($pdo);
                    $redisConfig = loadRedisConfigArray();
                    $redisExtensionLoaded = isRedisExtensionLoaded();
                    $redisConfiguredEnabled = isRedisConfiguredEnabled();
                    $mailConfigured = isMailConfigured($pdo);
                    $announcementCount = 0;
                    try {
                        $announcementCount = (int)$pdo->query('SELECT COUNT(*) FROM announcements')->fetchColumn();
                    } catch (Throwable $e) {
                        $announcementCount = 0;
                    }
                    include __DIR__ . '/../components/admin-other-settings-panels/overview.php';
                    ?>
                <?php elseif ($isRegisterToggle): ?>
                    <?php
                    $registerEnabled = isRegisterMasterEnabled($pdo);
                    include __DIR__ . '/../components/admin-other-settings-panels/register_toggle.php';
                    ?>
                <?php elseif ($isRegisterClosedPage): ?>
                    <?php
                    $registerEnabled = isRegisterMasterEnabled($pdo);
                    $closedPageConfig = getRegisterClosedPageConfig($pdo);
                    include __DIR__ . '/../components/admin-other-settings-panels/register_closed_page.php';
                    ?>
                <?php elseif ($isRegisterPage): ?>
                    <?php
                    $registerEnabled = isRegisterMasterEnabled($pdo);
                    $pageConfig = getRegisterPageConfig($pdo);
                    include __DIR__ . '/../components/admin-other-settings-panels/register_page.php';
                    ?>
                <?php elseif ($isRegisterSchedule): ?>
                    <?php
                    $registerEnabled = isRegisterMasterEnabled($pdo);
                    $scheduleConfig = getRegisterScheduleConfig($pdo);
                    include __DIR__ . '/../components/admin-other-settings-panels/register_schedule.php';
                    ?>
                <?php elseif ($isAnalyticsSettings): ?>
                    <?php
                    $analyticsConfig = getAnalyticsConfig($pdo);
                    include __DIR__ . '/../components/admin-other-settings-panels/analytics_settings.php';
                    ?>
                <?php elseif ($isUserGrowth): ?>
                    <?php
                    $analyticsDescription = $analyticsDescriptions['user_growth'];
                    include __DIR__ . '/../components/admin-other-settings-panels/analytics_user_growth.php';
                    ?>
                <?php elseif ($isVideoClicks): ?>
                    <?php
                    $analyticsDescription = $analyticsDescriptions['video_clicks'];
                    include __DIR__ . '/../components/admin-other-settings-panels/analytics_video_clicks.php';
                    ?>
                <?php elseif ($isUserVisits): ?>
                    <?php
                    $analyticsDescription = $analyticsDescriptions['user_visits'];
                    include __DIR__ . '/../components/admin-other-settings-panels/analytics_user_visits.php';
                    ?>
                <?php elseif ($isIpVisits): ?>
                    <?php
                    $analyticsDescription = $analyticsDescriptions['ip_visits'];
                    include __DIR__ . '/../components/admin-other-settings-panels/analytics_ip_visits.php';
                    ?>
                <?php elseif ($isThemeBackground): ?>
                    <?php
                    $bgConfig = getThemeBackgroundConfig($pdo);
                    include __DIR__ . '/../components/admin-other-settings-panels/theme_background.php';
                    ?>
                <?php elseif ($isThemeDarkColors): ?>
                    <?php
                    $themeColorMode = 'dark';
                    $themeColorsDescription = $themeColorsDescriptions['dark_colors'];
                    $colorsConfig = getThemeDarkColorsConfig($pdo);
                    include __DIR__ . '/../components/admin-other-settings-panels/theme_colors.php';
                    ?>
                <?php elseif ($isThemeLightColors): ?>
                    <?php
                    $themeColorMode = 'light';
                    $themeColorsDescription = $themeColorsDescriptions['light_colors'];
                    $colorsConfig = getThemeLightColorsConfig($pdo);
                    include __DIR__ . '/../components/admin-other-settings-panels/theme_colors.php';
                    ?>
                <?php elseif ($isFontGlobal): ?>
                    <?php
                    $fontConfig = getFontConfig($pdo);
                    if (($fontConfigDraft = fontConfigDraftTake()) !== null) {
                        $fontConfig = $fontConfigDraft;
                    }
                    include __DIR__ . '/../components/admin-other-settings-panels/font_global.php';
                    ?>
                <?php elseif ($isPlayerProxy): ?>
                    <?php
                    $playerProxyConfig = getPlayerProxyConfig($pdo);
                    include __DIR__ . '/../components/admin-other-settings-panels/player_proxy.php';
                    ?>
                <?php elseif ($isPlayerVideoData): ?>
                    <?php
                    $playerConfig = getPlayerConfig($pdo);
                    $proxyEnabled = isPlayerProxyEnabled($pdo);
                    include __DIR__ . '/../components/admin-other-settings-panels/player_video_data.php';
                    ?>
                <?php elseif ($isPlayerVideoToken): ?>
                    <?php
                    $proxyEnabled = isPlayerProxyEnabled($pdo);
                    include __DIR__ . '/../components/admin-other-settings-panels/player_video_token.php';
                    ?>
                <?php elseif ($isPlayerApiSync): ?>
                    <?php
                    $videoSyncConfig = getVideoDataSyncConfig($pdo);
                    $syncEndpointUrl = $videoSyncEndpointUrl;
                    $serverGroups = $videoSyncServerGroups;
                    $serverGroupFeature = $videoSyncServerGroupFeature;
                    include __DIR__ . '/../components/admin-other-settings-panels/player_api_sync.php';
                    ?>
                <?php elseif ($isRedisConfig): ?>
                    <?php
                    $redisConfig = loadRedisConfigArray();
                    $redisExtensionLoaded = isRedisExtensionLoaded();
                    $redisWatchAvailable = isRedisWatchProgressAvailable() && (testRedisConnection()['ok'] ?? false);
                    include __DIR__ . '/../components/admin-other-settings-panels/redis_config.php';
                    ?>
                <?php elseif ($isAnnouncement): ?>
                    <?php
                    $announcementConfig = getAnnouncementConfig($pdo);
                    $announcements = listAnnouncements($pdo);
                    $mailConfigured = isMailConfigured($pdo);
                    $editAnnouncement = null;
                    $editId = (int)($_GET['edit'] ?? 0);
                    if ($editId > 0) {
                        $editAnnouncement = getAnnouncementById($pdo, $editId);
                    }
                    include __DIR__ . '/../components/admin-other-settings-panels/announcement_manage.php';
                    ?>
                <?php elseif ($isEarningTraffic): ?>
                    <?php
                    $earningRows = fetchTrafficEarningAdminRows($pdo);
                    $earningUsers = fetchTrafficEarningUserSummary($pdo);
                    $mailConfigured = isMailConfigured($pdo);
                    include __DIR__ . '/../components/admin-other-settings-panels/earning_traffic.php';
                    ?>
                <?php elseif ($isPlayerPlaceholder): ?>
                    <?php
                    $placeholderDescription = $playerDescriptions[$activeItem];
                    include __DIR__ . '/../components/admin-other-settings-panels/settings_placeholder.php';
                    ?>
                <?php elseif ($isAnalyticsPlaceholder): ?>
                    <?php
                    $analyticsDescription = $analyticsDescriptions[$activeItem];
                    include __DIR__ . '/../components/admin-other-settings-panels/analytics_placeholder.php';
                    ?>
                <?php else: ?>
                <div class="px-4 py-6 text-sm text-gray-500">
                    <?= htmlspecialchars($panelMessage, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

</body>
</html>
