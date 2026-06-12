<?php
/**
 * 邮局管理导航链接（SVG 图标 + 悬停显示名称）
 *
 * @param string $href       链接地址
 * @param bool   $active     是否为当前页
 * @param string $hoverClass 悬停背景类名
 */
require_once __DIR__ . '/admin-nav-link-helper.php';
extract(adminNavLinkResolve(get_defined_vars(), [
    'href' => 'mail.php',
    'label' => '邮局管理',
]), EXTR_OVERWRITE);
$linkClass = $active ? 'bg-gray-100' : $hoverClass;
$paddingClass = $paddingClass ?? 'px-3 py-1';
?>
<a class="group relative inline-flex items-center justify-center rounded-full <?= htmlspecialchars($paddingClass, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
    <svg class="w-5 h-5 shrink-0" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M896 160c35.328 0 64 26.24 64 58.688v586.624c0 32.448-28.672 58.688-64 58.688H128c-35.328 0-64-26.24-64-58.688V218.688c0-32.448 28.672-58.688 64-58.688h768z m0 254.464L512.128 640 512 639.68 511.872 640 128 414.464V800h768V414.464zM896 224H128v114.56l384 225.6 384-225.6V224z" fill="#1296db"/></svg>
    <span class="absolute top-full left-1/2 -translate-x-1/2 mt-1 z-50 pointer-events-none whitespace-nowrap text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
</a>
<?php adminNavLinkCleanup(); ?>
