<?php
/** @var string $analyticsDescription */
?>
<div class="px-4 py-6">
    <p class="text-sm text-gray-500 mb-4">
        <?= htmlspecialchars($analyticsDescription, ENT_QUOTES, 'UTF-8') ?>
    </p>
    <div
        class="flex min-h-[320px] items-center justify-center rounded-lg border-2 border-dashed border-gray-200 bg-gray-50"
        aria-hidden="true"
    >
        <div class="text-center px-6">
            <svg class="mx-auto h-12 w-12 text-gray-300" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.5" d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"/>
            </svg>
            <p class="mt-3 text-sm font-medium text-gray-400">图表占位</p>
            <p class="mt-1 text-xs text-gray-400">功能开发中，敬请期待</p>
        </div>
    </div>
</div>
