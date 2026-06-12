<?php
/** @var string $panelTitle */
/** @var bool $mailConfigured */
?>
<div class="px-4 py-6">
    <?php if (!$mailConfigured): ?>
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        请先在「邮局配置」中完成 SMTP 服务器设置并启用，本功能发送邮件时将依赖该配置。
    </div>
    <?php endif; ?>

    <p class="text-sm text-gray-500 mb-4">
        「<?= htmlspecialchars($panelTitle, ENT_QUOTES, 'UTF-8') ?>」功能开发中，敬请期待…
    </p>
    <div
        class="flex min-h-[320px] items-center justify-center rounded-lg border-2 border-dashed border-gray-200 bg-gray-50"
        aria-hidden="true"
    >
        <div class="text-center px-6">
            <p class="text-sm font-medium text-gray-400">功能开发中，敬请期待</p>
        </div>
    </div>
</div>
