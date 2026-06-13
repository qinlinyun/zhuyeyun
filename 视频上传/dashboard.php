<?php
require_once __DIR__ . '/common.php';
uploadBackendRequireLogin();
$config = uploadBackendConfig();
$admin = $_SESSION['upload_backend_admin'] ?? [];
$username = (string)($admin['username'] ?? 'admin');
$embedPage = uploadBackendResolveEmbedPageUrl();
$card = uploadBackendTwCard();
$btnPri = uploadBackendBtnPrimary();
$btnSec = uploadBackendBtnSecondary();

uploadBackendPageHead('远程上传后端');
uploadBackendAdminNav('远程上传后端', [
    ['href' => 'dashboard.php', 'label' => '概览', 'active' => true],
    ['href' => 'upload.php', 'label' => '上传'],
    ['href' => 'originals.php', 'label' => '原始文件'],
], $username);
?>
<main class="mx-auto max-w-screen-xl px-4 py-6">
    <header class="mb-6">
        <h1 class="text-2xl font-bold tracking-tight text-slate-900">控制台概览</h1>
        <p class="mt-1 text-sm text-slate-500">管理远程视频存储、上传与转码同步。</p>
    </header>

    <div class="grid gap-4 md:grid-cols-2 lg:grid-cols-3">
        <section class="<?= $card ?>">
            <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-blue-100 text-blue-600">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/></svg>
            </div>
            <h2 class="text-base font-semibold text-slate-900">上传配置</h2>
            <dl class="mt-3 space-y-2 text-sm">
                <div class="flex flex-col gap-0.5 sm:flex-row sm:justify-between">
                    <dt class="text-slate-500">内嵌上传域名</dt>
                    <dd class="font-medium text-slate-800"><?= htmlspecialchars((string)$config['UPLOAD_DOMAIN'] !== '' ? (string)$config['UPLOAD_DOMAIN'] : '未配置', ENT_QUOTES, 'UTF-8') ?></dd>
                </div>
                <?php if ($embedPage !== ''): ?>
                <div>
                    <dt class="text-slate-500">内嵌页</dt>
                    <dd class="mt-1 break-all"><a class="text-blue-600 hover:underline" href="<?= htmlspecialchars($embedPage, ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noopener"><?= htmlspecialchars($embedPage, ENT_QUOTES, 'UTF-8') ?></a></dd>
                </div>
                <?php endif; ?>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">视频域名</dt><dd class="text-slate-800"><?= htmlspecialchars((string)$config['VIDEO_DOMAIN'] ?: '未配置', ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">图片域名</dt><dd class="text-slate-800"><?= htmlspecialchars((string)$config['IMAGE_DOMAIN'] ?: '未配置', ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">m3u8</dt><dd class="text-slate-800"><?= htmlspecialchars((string)$config['M3U8_DIR'], ENT_QUOTES, 'UTF-8') ?></dd></div>
                <div class="flex justify-between gap-2"><dt class="text-slate-500">mp4</dt><dd class="text-slate-800"><?= htmlspecialchars((string)$config['MP4_DIR'], ENT_QUOTES, 'UTF-8') ?></dd></div>
            </dl>
            <a href="config_guide.php" class="<?= $btnSec ?> mt-4">查看配置引导</a>
        </section>

        <section class="<?= $card ?>">
            <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-100 text-emerald-600">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/></svg>
            </div>
            <h2 class="text-base font-semibold text-slate-900">视频上传</h2>
            <p class="mt-2 text-sm leading-relaxed text-slate-500">审核通过后自动将 mp4 转为 m3u8，并同步视频数据与封面至主站。</p>
            <a href="upload.php" class="<?= $btnPri ?> mt-4">进入上传页</a>
        </section>

        <section class="<?= $card ?> md:col-span-2 lg:col-span-1">
            <div class="mb-3 flex h-10 w-10 items-center justify-center rounded-lg bg-indigo-100 text-indigo-600">
                <svg class="h-5 w-5" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 19a2 2 0 01-2-2V7a2 2 0 012-2h4l2 2h4a2 2 0 012 2v1M5 19h14a2 2 0 002-2v-5a2 2 0 00-2-2H9a2 2 0 00-2 2v5a2 2 0 01-2 2z"/></svg>
            </div>
            <h2 class="text-base font-semibold text-slate-900">原始文件管理</h2>
            <p class="mt-2 text-sm leading-relaxed text-slate-500">主站下发「保存原始文件」后，在此保留源 mp4，支持下载与删除。</p>
            <a href="originals.php" class="<?= $btnSec ?> mt-4">管理原始文件</a>
        </section>
    </div>
</main>
<?php uploadBackendPageFoot(); ?>
