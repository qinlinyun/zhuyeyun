<?php
/**
 * 记录管理导航链接（SVG 图标 + 悬停显示名称）
 *
 * @param string $href       链接地址
 * @param bool   $active     是否为当前页
 * @param string $hoverClass 悬停背景类名
 * @param string $label      悬停提示文字
 */
require_once __DIR__ . '/admin-nav-link-helper.php';
extract(adminNavLinkResolve(get_defined_vars(), [
    'href' => 'watch_records.php',
    'label' => '记录管理',
]), EXTR_OVERWRITE);
$linkClass = $active ? 'bg-gray-100' : $hoverClass;
?>
<a class="group relative inline-flex items-center justify-center rounded-full px-3 py-1 <?= htmlspecialchars($linkClass, ENT_QUOTES, 'UTF-8') ?>" href="<?= htmlspecialchars($href, ENT_QUOTES, 'UTF-8') ?>" aria-label="<?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>">
    <svg class="w-5 h-5 shrink-0" viewBox="0 0 1024 1024" xmlns="http://www.w3.org/2000/svg" aria-hidden="true"><path d="M432.808 893.463H240.965c-48.61 0-88.157-39.548-88.157-88.158V229.62c0-48.61 39.547-88.157 88.157-88.157H721.65c48.61 0 88.157 39.547 88.157 88.157v227.843c0 13.255-10.745 24-24 24s-24-10.745-24-24V229.62c0-22.143-18.015-40.157-40.157-40.157H240.965c-22.143 0-40.157 18.015-40.157 40.157v575.685c0 22.144 18.015 40.158 40.157 40.158h191.843c13.255 0 24 10.745 24 24s-10.746 24-24 24z" fill="#1296db"/><path d="M304.808 301.462h368.71v48h-368.71zM304.808 493.463h246.768v48H304.808z" fill="#1296db"/><path d="M715.5 904.812c-97.598 0-177-79.402-177-177s79.402-177 177-177 177 79.402 177 177-79.402 177-177 177z m0-310c-73.337 0-133 59.663-133 133s59.663 133 133 133 133-59.663 133-133-59.663-133-133-133z" fill="#1296db"/><path d="M687.5 789.812c-11.046 0-20-8.954-20-20v-97c0-11.046 8.954-20 20-20s20 8.954 20 20v97c0 11.045-8.954 20-20 20z" fill="#1296db"/><path d="M764.5 789.812h-77c-11.046 0-20-8.954-20-20s8.954-20 20-20h77c11.046 0 20 8.954 20 20s-8.954 20-20 20z" fill="#1296db"/></svg>
    <span class="absolute top-full left-1/2 -translate-x-1/2 mt-1 z-50 pointer-events-none whitespace-nowrap text-xs bg-black/70 text-white px-2 py-1 rounded opacity-0 group-hover:opacity-100 transition-opacity"><?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?></span>
</a>
<?php adminNavLinkCleanup(); ?>
