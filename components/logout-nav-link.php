<?php
/**
 * 退出登录导航链接（SVG 图标 + 悬停显示名称）
 *
 * @param string $href            链接地址
 * @param bool   $active            是否为当前页
 * @param string $hoverClass        悬停背景类名
 * @param string $paddingClass      内边距类名
 * @param string $linkExtraClass    额外链接类名
 */
require_once __DIR__ . '/admin-nav-link-helper.php';
extract(adminNavLinkResolve(get_defined_vars(), [
    'href' => 'logout.php',
    'label' => '退出',
]), EXTR_OVERWRITE);
$linkClass = trim(($active ? 'bg-gray-100' : $hoverClass) . ' ' . ($linkExtraClass ?? ''));
$paddingClass = $paddingClass ?? 'px-3 py-1';
?>
<a class="group relative inline-flex items-center justify-center rounded-full <?= htmlspecialchars($paddingClass, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
    <svg class="w-5 h-5 shrink-0" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M168.8064 111.5648a114.432 114.432 0 0 0-114.3808 114.432v572.0576c0 63.1296 51.2 114.3296 114.3296 114.3296h314.6752a28.5696 28.5696 0 1 0-0.0512-57.1904H168.7552c-31.5392 0-57.1392-25.6-57.1392-57.1904V225.9968c0-31.5904 25.6-57.1904 57.1392-57.1904h314.6752a28.672 28.672 0 0 0 0.0512-57.2416H168.8064z" fill="#00A0E9"/><path d="M960 533.1968l-323.84 292.7104c-36.7616 33.28-95.5392 7.2192-95.5392-42.3936V666.624a7.168 7.168 0 0 0-7.2192-7.1168H254.6176a28.672 28.672 0 0 1-28.5696-28.7232V393.1136c0-15.872 12.8-28.6208 28.5696-28.6208h278.8352a7.168 7.168 0 0 0 7.2192-7.168V240.384c0-49.5616 58.7776-75.7248 95.5392-42.4448l323.7888 292.7616a28.4672 28.4672 0 0 1 0 42.496z" fill="#00A0E9"/></svg>
    <span class="absolute top-full left-1/2 -translate-x-1/2 mt-1 z-50 pointer-events-none whitespace-nowrap text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
</a>
<?php adminNavLinkCleanup(); ?>
