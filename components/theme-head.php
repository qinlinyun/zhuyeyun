<?php
$themeAssetPrefix = $themeAssetPrefix ?? '';
$themeUseCdn = !empty($themeUseCdn);
require_once __DIR__ . '/../includes/theme_context.php';
require_once __DIR__ . '/../includes/site_cdn.php';
$themeForceLightPage = themeIsAdminArea();
$themeStylesheets = siteThemeStylesheetUrls($themeUseCdn, $themeAssetPrefix);
?>
<script>(function(){try{var h=document.documentElement;<?php if ($themeForceLightPage): ?>h.classList.remove('dark');<?php else: ?>var t=localStorage.theme;if(t==='light'){h.classList.remove('dark');}else{h.classList.add('dark');}<?php endif; ?>}catch(e){}})();</script>
<?php foreach ($themeStylesheets as $stylesheetUrl): ?>
<link rel="stylesheet" href="<?= htmlspecialchars($stylesheetUrl, ENT_QUOTES, 'UTF-8') ?>">
<?php endforeach; ?>
