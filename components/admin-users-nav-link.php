<?php
/**
 * 用户管理导航链接（SVG 图标 + 悬停显示名称）
 *
 * @param string $href       链接地址
 * @param bool   $active     是否为当前页
 * @param string $hoverClass 悬停背景类名
 */
require_once __DIR__ . '/admin-nav-link-helper.php';
extract(adminNavLinkResolve(get_defined_vars(), [
    'href' => 'users.php',
    'label' => '用户管理',
]), EXTR_OVERWRITE);
$linkClass = $active ? 'bg-gray-100' : $hoverClass;
$paddingClass = $paddingClass ?? 'px-3 py-1';
$linkExtraClass = $linkExtraClass ?? '';
?>
<a class="group relative inline-flex items-center justify-center rounded-full <?= htmlspecialchars($paddingClass, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8') ?> <?= htmlspecialchars($linkExtraClass, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
    <svg class="w-5 h-5 shrink-0" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M749.4 376.2c0-150.2-120.1-270.3-270.3-270.3S208.8 226.1 208.8 376.2c0 102.1 54.1 189.2 138.2 234.3-114.1 45.1-201.2 144.2-225.3 270.3-3 15 6 33 24 36h6c15 0 27-9 30-24 27-141.2 150.2-243.3 294.3-246.3h6c144.2 0.1 267.4-123.1 267.4-270.3z m-480.6 0C268.8 259.1 361.9 166 479 166s210.2 93.1 210.2 210.2c0 114.1-93.1 207.2-207.2 210.3h-9c-114.1-6-204.2-99.1-204.2-210.3z m417.5 270.4c0 18 12 30 30 30h150.2c18 0 30-12 30-30s-12-30-30-30H716.4c-15.1-0.1-30.1 11.9-30.1 30z m180.2 87.1H626.2c-18 0-30 12-30 30s12 30 30 30h240.3c18 0 30-12 30-30 0.1-18-11.9-30-30-30z m0 120.1H629.3c-18 0-30 12-30 30s12 30 30 30h237.3c18 0 30-12 30-30s-15.1-30-30.1-30z m0 0" fill="#1296db"/></svg>
    <span class="absolute top-full left-1/2 -translate-x-1/2 mt-1 z-50 pointer-events-none whitespace-nowrap text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
</a>
<?php adminNavLinkCleanup(); ?>
