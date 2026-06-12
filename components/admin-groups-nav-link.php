<?php
/**
 * 分组管理导航链接（SVG 图标 + 悬停显示名称）
 *
 * @param string $href       链接地址
 * @param bool   $active     是否为当前页
 * @param string $hoverClass 悬停背景类名
 * @param string $label      悬停提示文字
 */
require_once __DIR__ . '/admin-nav-link-helper.php';
extract(adminNavLinkResolve(get_defined_vars(), [
    'href' => 'groups.php',
    'label' => '分组管理',
]), EXTR_OVERWRITE);
$linkClass = $active ? 'bg-gray-100' : $hoverClass;
$paddingClass = $paddingClass ?? 'px-3 py-1';
$linkExtraClass = $linkExtraClass ?? '';
?>
<a class="group relative inline-flex items-center justify-center rounded-full <?= htmlspecialchars($paddingClass, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($linkExtraClass, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
    <svg class="w-5 h-5 shrink-0" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M863.008 384C916.576 384 960 341.024 960 288V160c0-53.024-43.424-96-96.992-96H160.992C107.424 64 64 106.976 64 160v128c0 53.024 43.424 96 96.992 96H320v128a32 32 0 0 0 32 32h288v96H160.992C107.424 640 64 682.976 64 736v128c0 53.024 43.424 96 96.992 96h702.016C916.576 960 960 917.024 960 864v-128c0-53.024-43.424-96-96.992-96H704v-128a32 32 0 0 0-32-32h-288v-96h479.008z m0 320c17.856 0 32.32 14.336 32.32 32v128c0 17.664-14.464 32-32.32 32H160.992c-17.856 0-32.32-14.336-32.32-32v-128c0-17.664 14.464-32 32.32-32h702.016zM128.672 288V160c0-17.664 14.464-32 32.32-32h702.016c17.856 0 32.32 14.336 32.32 32v128c0 17.664-14.464 32-32.32 32H160.992c-17.856 0-32.32-14.336-32.32-32z" fill="#1296db"/><path d="M320 832h384a32 32 0 0 0 0-64H320a32 32 0 0 0 0 64zM736 224a32 32 0 0 0-32-32H320a32 32 0 0 0 0 64h384a32 32 0 0 0 32-32z" fill="#1296db"/></svg>
    <span class="absolute top-full left-1/2 -translate-x-1/2 mt-1 z-50 pointer-events-none whitespace-nowrap text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
</a>
<?php adminNavLinkCleanup(); ?>
