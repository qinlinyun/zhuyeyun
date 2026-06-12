<?php
/**
 * 意见反馈导航链接（SVG 图标 + 悬停显示名称）
 *
 * @param string $href       链接地址
 * @param bool   $active     是否为当前页
 * @param string $hoverClass 悬停背景类名
 * @param string $label      悬停提示文字
 */
require_once __DIR__ . '/admin-nav-link-helper.php';
extract(adminNavLinkResolve(get_defined_vars(), [
    'href' => 'feedback.php',
    'label' => '意见反馈',
]), EXTR_OVERWRITE);
$linkClass = $active ? 'bg-gray-100' : $hoverClass;
$paddingClass = $paddingClass ?? 'px-3 py-1';
$linkExtraClass = $linkExtraClass ?? '';
?>
<a class="group relative inline-flex items-center justify-center rounded-full <?= htmlspecialchars($paddingClass, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($linkExtraClass, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
    <svg class="w-5 h-5 shrink-0" viewBox="0 0 1060 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M963.2 408c-13.6 0-24 11.2-24 24v432c0 27.2-21.6 48.8-48.8 48.8H159.2c-27.2 0-48.8-21.6-48.8-48.8V159.2c0-27.2 21.6-48.8 48.8-48.8h470.4c13.6 0 24-11.2 24-24 0-13.6-11.2-24-24-24H154.4C103.2 62.4 61.6 104 61.6 155.2v715.2c0 51.2 41.6 92.8 92.8 92.8h740.8c51.2 0 92.8-41.6 92.8-92.8V432c0-12.8-11.2-24-24.8-24z" fill="#1296db"/><path d="M968 151.2l-44-44c-30.4-30.4-78.4-33.6-105.6-5.6L355.2 564.8 510.4 720l463.2-463.2c28-27.2 25.6-75.2-5.6-105.6z m-116.8 159.2l-344 344-86.4-85.6 345.6-345.6 84.8 87.2z m88-88l-51.2 51.2-85.6-86.4 50.4-50.4c10.4-10.4 28.8-9.6 40 2.4l44 44c12 10.4 12.8 28.8 2.4 39.2zM355.2 566.4l-48 174.4c-2.4 8 0 16 5.6 21.6 5.6 5.6 13.6 8 21.6 5.6l174.4-48-36-36L360 715.2l31.2-113.6-36-35.2z" fill="#1296db"/></svg>
    <span class="absolute top-full left-1/2 -translate-x-1/2 mt-1 z-50 pointer-events-none whitespace-nowrap text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
</a>
<?php adminNavLinkCleanup(); ?>
