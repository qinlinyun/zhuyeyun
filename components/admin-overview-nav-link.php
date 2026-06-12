<?php
/**
 * 后台总览导航链接（SVG 图标 + 悬停显示名称）
 *
 * @param string $href
 * @param bool   $active
 * @param string $hoverClass
 */
require_once __DIR__ . '/admin-nav-link-helper.php';
extract(adminNavLinkResolve(get_defined_vars(), [
    'href' => 'index.php',
    'label' => '总览',
]), EXTR_OVERWRITE);
$linkClass = $active ? 'bg-gray-100' : $hoverClass;
$paddingClass = $paddingClass ?? 'px-3 py-1';
$linkExtraClass = $linkExtraClass ?? '';
?>
<a class="group relative inline-flex items-center justify-center rounded-full <?= htmlspecialchars($paddingClass, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($linkExtraClass, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
    <svg class="w-5 h-5 shrink-0" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true">
        <path d="M128 192c0-35.3 28.7-64 64-64h640c35.3 0 64 28.7 64 64v512c0 35.3-28.7 64-64 64H192c-35.3 0-64-28.7-64-64V192z m64 0v512h640V192H192z" fill="#1296db"/>
        <path d="M320 352h384v64H320v-64zM320 512h256v64H320v-64zM320 672h384v64H320v-64z" fill="#1296db"/>
        <path d="M832 256l96 64-96 64v-128z" fill="#1296db" opacity=".85"/>
    </svg>
    <span class="absolute top-full left-1/2 -translate-x-1/2 mt-1 z-50 pointer-events-none whitespace-nowrap text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
</a>
<?php adminNavLinkCleanup(); ?>
