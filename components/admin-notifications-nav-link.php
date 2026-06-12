<?php
/**
 * 站内通知导航链接（SVG 图标 + 悬停显示名称）
 *
 * @param string $href       链接地址
 * @param bool   $active     是否为当前页
 * @param string $hoverClass 悬停背景类名
 * @param string $label      悬停提示文字
 */
require_once __DIR__ . '/admin-nav-link-helper.php';
extract(adminNavLinkResolve(get_defined_vars(), [
    'href' => 'notifications.php',
    'label' => '站内通知',
]), EXTR_OVERWRITE);
$linkClass = $active ? 'bg-gray-100' : $hoverClass;
$paddingClass = $paddingClass ?? 'px-3 py-1';
$linkExtraClass = $linkExtraClass ?? '';
$badgeCount = isset($badgeCount) ? (int)$badgeCount : (int)($_SESSION['unread_notification_count'] ?? 0);
$badgeCount = function_exists('isAdmin') && isAdmin() ? 0 : $badgeCount;
$showBadge = $badgeCount > 0;
?>
<a class="group relative inline-flex items-center justify-center rounded-full <?= htmlspecialchars($paddingClass, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($linkExtraClass, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
    <svg class="w-5 h-5 shrink-0" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M322.37312 204.1344l165.1712-120.32 175.104 36.7104L784.81152 273.92l87.9616 334.7456 8.3968 33.6384a461.0048 461.0048 0 0 1-98.1504 10.496c-249.9072 0-453.376-199.68-460.5952-448.6656z" fill="#20C997"/><path d="M266.05312 734.3104h1.8944c35.1744 0 63.6928 28.5696 63.6928 63.8976a63.7952 63.7952 0 0 1-67.4304 63.7952H212.70272a136.5504 136.5504 0 0 1-120.32-71.168 131.7376 131.7376 0 0 1 5.12-135.8848l5.12-8.0384a511.0272 511.0272 0 0 0 81.7152-278.1696V364.544a32.4608 32.4608 0 0 1-0.1024-2.7136V351.5904a39.936 39.936 0 0 1 0.3584-4.7616 330.3424 330.3424 0 0 1 102.2976-224.0512A340.6336 340.6336 0 0 1 524.15232 27.2896c88.9344 0 173.2096 33.8944 237.2608 95.488a330.3424 330.3424 0 0 1 102.2976 224.0512 40.2432 40.2432 0 0 1 0.3072 4.7616v8.8064c0.1024 1.536 0.0512 3.072-0.0512 4.5568v3.7376a510.976 510.976 0 0 0 81.664 278.2208l5.12 8.0384c26.8288 41.6256 28.7744 92.416 5.12 135.8848a136.5504 136.5504 0 0 1-120.32 71.168H527.07072A63.7952 63.7952 0 0 1 454.21312 798.72c0-34.9184 27.904-63.2832 62.5152-63.8976v-0.3584h260.7616c22.1696 0 41.5744-11.4688 51.9168-30.6176a55.7056 55.7056 0 0 0-2.1504-57.2416l-4.1984-6.5536a472.2688 472.2688 0 0 1-74.752-267.0592c-2.9696-118.784-103.424-215.3472-224.1536-215.3472s-221.184 96.5632-224.1024 215.3472v10.8544a472.2688 472.2688 0 0 1-74.8032 256.2048l-4.1984 6.5536c-11.264 17.5616-12.0832 38.912-2.2016 57.2416 9.6256 17.8176 27.0336 28.928 47.2064 30.464zM358.31552 921.6h307.2a51.2 51.2 0 0 1 0 102.4H358.31552a51.2 51.2 0 0 1 0-102.4z" fill="#2C6DD2"/></svg>
    <?php if ($showBadge): ?>
        <span class="pointer-events-none absolute -top-1 -right-1 inline-flex h-5 min-w-5 items-center justify-center rounded-full bg-red-600 px-1.5 text-[10px] font-semibold text-white ring-2 ring-white">
            <?= $badgeCount > 99 ? '99+' : (int)$badgeCount ?>
        </span>
    <?php endif; ?>
    <span class="absolute top-full left-1/2 -translate-x-1/2 mt-1 z-50 pointer-events-none whitespace-nowrap text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
</a>
<?php adminNavLinkCleanup(); ?>
