<?php
/** @var array<int,array{name:string,url:string}> $backendEntries */
/** @var string $fieldPrefix 例如 player_proxy_backend / video_sync_backend */
/** @var string $listId */
/** @var string $hint */
$backendEntries = $backendEntries ?? [];
if ($backendEntries === []) {
    $backendEntries = [['name' => '', 'url' => '']];
}
$listId = $listId ?? 'backendUrlList';
$fieldPrefix = $fieldPrefix ?? 'player_backend';
$nameField = $fieldPrefix . '_name';
$urlField = $fieldPrefix . '_url';
?>
<div class="space-y-2">
    <div class="flex items-center justify-between gap-3">
        <label class="block text-sm font-medium text-gray-700">视频切片后端地址</label>
        <button
            type="button"
            class="backend-add-btn rounded border border-gray-300 px-2 py-1 text-xs text-gray-700 hover:bg-gray-50"
            data-target="<?= htmlspecialchars($listId, ENT_QUOTES, 'UTF-8') ?>"
        >添加后端</button>
    </div>
    <div id="<?= htmlspecialchars($listId, ENT_QUOTES, 'UTF-8') ?>" class="space-y-2">
        <?php foreach ($backendEntries as $entry): ?>
        <div class="backend-row grid grid-cols-1 gap-2 sm:grid-cols-[140px_minmax(0,1fr)_auto] sm:items-center">
            <input
                type="text"
                name="<?= htmlspecialchars($nameField, ENT_QUOTES, 'UTF-8') ?>[]"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                placeholder="备注名（可选）"
                value="<?= htmlspecialchars((string)($entry['name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            >
            <input
                type="url"
                name="<?= htmlspecialchars($urlField, ENT_QUOTES, 'UTF-8') ?>[]"
                class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                placeholder="https://域名/视频切片"
                value="<?= htmlspecialchars((string)($entry['url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
            >
            <button
                type="button"
                class="backend-remove-btn rounded border border-gray-300 px-2 py-2 text-xs text-gray-600 hover:bg-gray-50"
            >删除</button>
        </div>
        <?php endforeach; ?>
    </div>
    <?php if (!empty($hint)): ?>
    <p class="text-xs text-gray-500"><?= $hint ?></p>
    <?php endif; ?>
</div>
<template id="<?= htmlspecialchars($listId, ENT_QUOTES, 'UTF-8') ?>Tpl">
    <div class="backend-row grid grid-cols-1 gap-2 sm:grid-cols-[140px_minmax(0,1fr)_auto] sm:items-center">
        <input
            type="text"
            name="<?= htmlspecialchars($nameField, ENT_QUOTES, 'UTF-8') ?>[]"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
            placeholder="备注名（可选）"
        >
        <input
            type="url"
            name="<?= htmlspecialchars($urlField, ENT_QUOTES, 'UTF-8') ?>[]"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
            placeholder="https://域名/视频切片"
        >
        <button
            type="button"
            class="backend-remove-btn rounded border border-gray-300 px-2 py-2 text-xs text-gray-600 hover:bg-gray-50"
        >删除</button>
    </div>
</template>
<script>
(function () {
    document.querySelectorAll('.backend-add-btn').forEach(btn => {
        if (btn.dataset.bound) return;
        btn.dataset.bound = '1';
        btn.addEventListener('click', () => {
            const listId = btn.getAttribute('data-target');
            const list = document.getElementById(listId);
            const tpl = document.getElementById(listId + 'Tpl');
            if (!list || !tpl) return;
            list.appendChild(tpl.content.cloneNode(true));
            bindBackendRemove(list);
        });
    });

    function bindBackendRemove(scope) {
        scope.querySelectorAll('.backend-remove-btn').forEach(btn => {
            if (btn.dataset.bound) return;
            btn.dataset.bound = '1';
            btn.addEventListener('click', () => {
                const row = btn.closest('.backend-row');
                const list = row ? row.parentElement : null;
                if (!row || !list) return;
                if (list.querySelectorAll('.backend-row').length <= 1) {
                    row.querySelectorAll('input').forEach(input => { input.value = ''; });
                    return;
                }
                row.remove();
            });
        });
    }

    document.querySelectorAll('#playerProxyBackendList, #videoSyncBackendList').forEach(el => bindBackendRemove(el));
})();
</script>
