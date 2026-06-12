<?php
/** @var array{type:string,title:string,message:string} $accountStatusPopup */
?>
<div id="accountStatusModal" class="fixed inset-0 z-[100] flex items-center justify-center bg-black/50 px-4">
    <div class="w-full max-w-lg rounded-2xl bg-white p-6 shadow-2xl dark:bg-gray-800">
        <h2 class="text-lg font-semibold text-gray-900 dark:text-white">
            <?= htmlspecialchars($accountStatusPopup['title'], ENT_QUOTES, 'UTF-8') ?>
        </h2>
        <p class="mt-4 text-sm leading-relaxed text-gray-600 dark:text-gray-300">
            <?= htmlspecialchars($accountStatusPopup['message'], ENT_QUOTES, 'UTF-8') ?>
        </p>
        <div class="mt-6 flex justify-end gap-3">
            <?php if (($accountStatusPopup['type'] ?? '') === 'deleted'): ?>
            <a href="logout.php"
               class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                退出登录
            </a>
            <?php else: ?>
            <button type="button" id="accountStatusModalClose"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                我知道了
            </button>
            <?php endif; ?>
        </div>
    </div>
</div>
<script>
(() => {
    const modal = document.getElementById('accountStatusModal');
    const closeBtn = document.getElementById('accountStatusModalClose');
    closeBtn?.addEventListener('click', () => modal?.remove());
    modal?.addEventListener('click', (e) => {
        if (e.target === modal) modal.remove();
    });
})();
</script>
