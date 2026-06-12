<?php
/**
 * 后台统一顶部导航
 *
 * @param string $adminNavActive 当前高亮：overview|users|groups|domains|videos|upload|traffic|notifications|feedback|comments|mail|other_settings
 * @param string $adminNavPrefix  链接前缀，admin 目录内为 ''，站点首页为 'admin/'
 * @param string $adminNavStyle   default|home（首页玻璃风格）
 * @param int|null $notificationBadgeCount 通知角标（可选）
 * @param bool $adminNavShowThemeToggle  是否在导航栏显示主题切换（可选）
 */
$adminNavActive = $adminNavActive ?? '';
$adminNavPrefix = $adminNavPrefix ?? '';
$adminNavStyle = $adminNavStyle ?? 'default';
$notificationBadgeCount = isset($notificationBadgeCount) ? (int)$notificationBadgeCount : null;
$adminNavShowThemeToggle = !empty($adminNavShowThemeToggle);

$homeHref = $adminNavPrefix === 'admin/' ? 'index.php' : '../index.php';
$navClass = $adminNavStyle === 'home'
    ? 'bg-white/80 glass shadow-sm sticky top-0 z-50'
    : 'bg-white shadow-sm';
$innerClass = $adminNavStyle === 'home'
    ? 'mx-auto max-w-screen-xl px-4 py-3 flex justify-between items-center'
    : 'mx-auto max-w-screen-xl px-4 py-3 flex flex-wrap items-center gap-3 text-sm';
$homeLinkClass = $adminNavStyle === 'home'
    ? 'rounded-full px-3 py-1 hover:bg-gray-100/50'
    : 'rounded-full px-3 py-1 hover:bg-gray-100';
$hoverClass = $adminNavStyle === 'home' ? 'hover:bg-gray-100/50' : 'hover:bg-gray-100';
$p = $adminNavPrefix;

$renderNavLinks = static function () use ($p, $hoverClass, $adminNavActive, $notificationBadgeCount): void {
    $href = $p . 'index.php';
    $active = $adminNavActive === 'overview';
    include __DIR__ . '/admin-overview-nav-link.php';

    $href = $p . 'users.php';
    $active = $adminNavActive === 'users';
    include __DIR__ . '/admin-users-nav-link.php';

    $href = $p . 'groups.php';
    $active = $adminNavActive === 'groups';
    include __DIR__ . '/admin-groups-nav-link.php';

    $href = $p . 'domains.php';
    $active = $adminNavActive === 'domains';
    include __DIR__ . '/admin-domains-nav-link.php';

    $href = $p . 'videos.php';
    $active = $adminNavActive === 'videos';
    include __DIR__ . '/admin-videos-nav-link.php';

    $href = $p . 'upload_manage.php?section=overview';
    $active = $adminNavActive === 'upload';
    include __DIR__ . '/admin-upload-nav-link.php';

    $href = $p . 'traffic.php';
    $active = $adminNavActive === 'traffic';
    include __DIR__ . '/admin-traffic-nav-link.php';

    $href = $p . 'notifications.php';
    $active = $adminNavActive === 'notifications';
    $badgeCount = $notificationBadgeCount;
    include __DIR__ . '/admin-notifications-nav-link.php';
    unset($badgeCount);

    $href = $p . 'feedback.php';
    $active = $adminNavActive === 'feedback';
    include __DIR__ . '/admin-feedback-nav-link.php';

    $href = $p . 'comments.php';
    $active = $adminNavActive === 'comments';
    include __DIR__ . '/admin-comments-nav-link.php';

    $href = $p . 'mail.php';
    $active = $adminNavActive === 'mail';
    include __DIR__ . '/admin-mail-nav-link.php';

    $href = $p . 'other_settings.php';
    $active = $adminNavActive === 'other_settings';
    include __DIR__ . '/admin-other-settings-nav-link.php';
};
?>
<nav class="<?= htmlspecialchars($navClass, ENT_QUOTES, 'UTF-8') ?>">
    <div class="<?= htmlspecialchars($innerClass, ENT_QUOTES, 'UTF-8') ?>">
        <?php if ($adminNavStyle === 'home'): ?>
            <div class="flex flex-wrap items-center gap-2 text-sm">
                <a href="<?= htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8') ?>" class="<?= htmlspecialchars($homeLinkClass, ENT_QUOTES, 'UTF-8') ?>">首页</a>
                <?php $renderNavLinks(); ?>
            </div>
            <div class="flex items-center gap-3">
                <?php $href = 'logout.php'; include __DIR__ . '/logout-nav-link.php'; ?>
            </div>
        <?php else: ?>
            <a href="<?= htmlspecialchars($homeHref, ENT_QUOTES, 'UTF-8') ?>" class="<?= htmlspecialchars($homeLinkClass, ENT_QUOTES, 'UTF-8') ?>">首页</a>
            <?php $renderNavLinks(); ?>
            <?php if ($adminNavShowThemeToggle): ?>
                <?php include __DIR__ . '/theme-toggle.php'; ?>
            <?php endif; ?>
            <?php $href = '../logout.php'; include __DIR__ . '/logout-nav-link.php'; ?>
        <?php endif; ?>
    </div>
</nav>
