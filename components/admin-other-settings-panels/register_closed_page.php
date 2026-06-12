<?php
/** @var array $closedPageConfig */
/** @var bool $registerEnabled */
/** @var string $message */
/** @var string $error */
$closedPageConfig = $closedPageConfig ?? defaultRegisterClosedPageConfig();
$blocks = $closedPageConfig['blocks'];
$L = require __DIR__ . '/../../includes/register_closed_labels.php';
?>
<div class="px-4 py-3 overflow-y-auto max-h-[calc(100vh-12rem)]">
    <?php if ($message): ?>
    <div class="mb-2 rounded-md border border-green-200 bg-green-50 px-3 py-1.5 text-sm leading-snug text-green-600">
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="mb-2 rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-sm leading-snug text-red-600">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if ($registerEnabled): ?>
    <div class="mb-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-1.5 text-sm leading-snug text-amber-700">
        <?= htmlspecialchars($L['notice_enabled'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php else: ?>
    <div class="mb-2 rounded-md border border-blue-600 bg-blue-600 px-3 py-1.5 text-sm leading-snug text-white">
        <?= htmlspecialchars($L['notice_disabled'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="max-w-3xl space-y-4" id="closedPageForm">
        <input type="hidden" name="panel" value="register_closed_page">

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4 space-y-4">
            <div>
                <label for="closed_text" class="block text-sm font-medium text-gray-900"><?= htmlspecialchars($L['label_text'], ENT_QUOTES, 'UTF-8') ?></label>
                <p class="mt-1 text-xs text-gray-500"><?= htmlspecialchars($L['help_text'], ENT_QUOTES, 'UTF-8') ?></p>
                <textarea id="closed_text" name="closed_text" rows="4" class="mt-2 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-200"><?= htmlspecialchars($closedPageConfig['text'], ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div>
                <label for="closed_text_color" class="block text-sm font-medium text-gray-900"><?= htmlspecialchars($L['label_color'], ENT_QUOTES, 'UTF-8') ?></label>
                <div class="mt-2 flex items-center gap-3">
                    <input type="color" id="closed_text_color" name="closed_text_color" value="<?= htmlspecialchars($closedPageConfig['text_color'], ENT_QUOTES, 'UTF-8') ?>" class="h-10 w-14 cursor-pointer rounded border border-gray-300 bg-white">
                    <input type="text" data-color-sync="closed_text_color" value="<?= htmlspecialchars($closedPageConfig['text_color'], ENT_QUOTES, 'UTF-8') ?>" class="w-32 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-mono">
                </div>
            </div>
            <div>
                <label for="closed_text_size" class="block text-sm font-medium text-gray-900"><?= htmlspecialchars($L['label_text_size'], ENT_QUOTES, 'UTF-8') ?></label>
                <p class="mt-1 text-xs text-gray-500"><?= htmlspecialchars($L['help_text_size'], ENT_QUOTES, 'UTF-8') ?></p>
                <div class="mt-2 flex items-center gap-3">
                    <input
                        type="number"
                        id="closed_text_size"
                        name="closed_text_size"
                        min="12"
                        max="48"
                        step="1"
                        value="<?= (int)($closedPageConfig['text_size'] ?? 14) ?>"
                        class="w-32 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
                    >
                    <span class="text-sm text-gray-500">px</span>
                </div>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-white px-5 py-4">
            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($L['label_blocks'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-xs text-gray-500"><?= htmlspecialchars($L['help_blocks'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <div class="flex gap-2">
                    <button type="button" data-add-block="link" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm hover:bg-gray-50"><?= htmlspecialchars($L['btn_link'], ENT_QUOTES, 'UTF-8') ?></button>
                    <button type="button" data-add-block="image" class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-sm hover:bg-gray-50"><?= htmlspecialchars($L['btn_image'], ENT_QUOTES, 'UTF-8') ?></button>
                </div>
            </div>
            <div id="blocksContainer" class="mt-4 space-y-3">
                <?php foreach ($blocks as $index => $block): ?>
                    <?php $blockIndex = (int)$index; ?>
                    <?php if ($block['type'] === 'link'): ?>
                    <div class="block-item rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <div class="mb-3 flex items-center justify-between">
                            <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars($L['block_link'], ENT_QUOTES, 'UTF-8') ?></span>
                            <button type="button" class="text-xs text-red-600 hover:underline" data-remove-block><?= htmlspecialchars($L['btn_delete'], ENT_QUOTES, 'UTF-8') ?></button>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1"><?= htmlspecialchars($L['label_link_text'], ENT_QUOTES, 'UTF-8') ?></label>
                                <input type="text" name="blocks[<?= $blockIndex ?>][label]" value="<?= htmlspecialchars($block['label'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                                <input type="hidden" name="blocks[<?= $blockIndex ?>][type]" value="link">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1"><?= htmlspecialchars($L['label_link_url'], ENT_QUOTES, 'UTF-8') ?></label>
                                <input type="text" name="blocks[<?= $blockIndex ?>][url]" value="<?= htmlspecialchars($block['url'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1"><?= htmlspecialchars($L['label_link_color'], ENT_QUOTES, 'UTF-8') ?></label>
                                <div class="flex items-center gap-2">
                                    <input type="color" name="blocks[<?= $blockIndex ?>][color]" value="<?= htmlspecialchars($block['color'], ENT_QUOTES, 'UTF-8') ?>" class="h-9 w-12 cursor-pointer rounded border border-gray-300 bg-white">
                                    <input type="text" data-color-sync="blocks[<?= $blockIndex ?>][color]" value="<?= htmlspecialchars($block['color'], ENT_QUOTES, 'UTF-8') ?>" class="w-28 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs font-mono">
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php elseif ($block['type'] === 'image'): ?>
                    <div class="block-item rounded-lg border border-gray-200 bg-gray-50 p-4">
                        <div class="mb-3 flex items-center justify-between">
                            <span class="text-sm font-medium text-gray-700"><?= htmlspecialchars($L['block_image'], ENT_QUOTES, 'UTF-8') ?></span>
                            <button type="button" class="text-xs text-red-600 hover:underline" data-remove-block><?= htmlspecialchars($L['btn_delete'], ENT_QUOTES, 'UTF-8') ?></button>
                        </div>
                        <div class="grid gap-3 md:grid-cols-2">
                            <div>
                                <label class="block text-xs text-gray-500 mb-1"><?= htmlspecialchars($L['label_image_url'], ENT_QUOTES, 'UTF-8') ?></label>
                                <input type="text" name="blocks[<?= $blockIndex ?>][url]" value="<?= htmlspecialchars($block['url'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                                <input type="hidden" name="blocks[<?= $blockIndex ?>][type]" value="image">
                            </div>
                            <div>
                                <label class="block text-xs text-gray-500 mb-1"><?= htmlspecialchars($L['label_image_alt'], ENT_QUOTES, 'UTF-8') ?></label>
                                <input type="text" name="blocks[<?= $blockIndex ?>][alt]" value="<?= htmlspecialchars($block['alt'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                <?php endforeach; ?>
            </div>
            <p id="blocksEmptyHint" class="mt-3 text-xs text-gray-400 <?= empty($blocks) ? '' : 'hidden' ?>"><?= htmlspecialchars($L['empty_blocks'], ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div class="rounded-lg border border-dashed border-gray-300 bg-gray-50 px-5 py-4">
            <p class="mb-3 text-sm font-medium text-gray-700"><?= htmlspecialchars($L['label_preview'], ENT_QUOTES, 'UTF-8') ?></p>
            <div class="rounded-lg bg-white px-4 py-6">
                <?php include __DIR__ . '/../register-closed-content.php'; ?>
            </div>
        </div>

        <div>
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700"><?= htmlspecialchars($L['btn_save'], ENT_QUOTES, 'UTF-8') ?></button>
        </div>
    </form>
</div>

<template id="linkBlockTemplate">
<div class="block-item rounded-lg border border-gray-200 bg-gray-50 p-4">
<div class="mb-3 flex items-center justify-between"><span class="text-sm font-medium text-gray-700"><?= htmlspecialchars($L['block_link'], ENT_QUOTES, 'UTF-8') ?></span><button type="button" class="text-xs text-red-600 hover:underline" data-remove-block><?= htmlspecialchars($L['btn_delete'], ENT_QUOTES, 'UTF-8') ?></button></div>
<div class="grid gap-3 md:grid-cols-2">
<div><label class="block text-xs text-gray-500 mb-1"><?= htmlspecialchars($L['label_link_text'], ENT_QUOTES, 'UTF-8') ?></label><input type="text" data-field="label" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"><input type="hidden" data-field="type" value="link"></div>
<div><label class="block text-xs text-gray-500 mb-1"><?= htmlspecialchars($L['label_link_url'], ENT_QUOTES, 'UTF-8') ?></label><input type="text" data-field="url" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"></div>
<div><label class="block text-xs text-gray-500 mb-1"><?= htmlspecialchars($L['label_link_color'], ENT_QUOTES, 'UTF-8') ?></label><div class="flex items-center gap-2"><input type="color" data-field="color" value="#2563eb" class="h-9 w-12 cursor-pointer rounded border border-gray-300 bg-white"><input type="text" data-color-text value="#2563eb" class="w-28 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs font-mono"></div></div>
</div></div>
</template>

<template id="imageBlockTemplate">
<div class="block-item rounded-lg border border-gray-200 bg-gray-50 p-4">
<div class="mb-3 flex items-center justify-between"><span class="text-sm font-medium text-gray-700"><?= htmlspecialchars($L['block_image'], ENT_QUOTES, 'UTF-8') ?></span><button type="button" class="text-xs text-red-600 hover:underline" data-remove-block><?= htmlspecialchars($L['btn_delete'], ENT_QUOTES, 'UTF-8') ?></button></div>
<div class="grid gap-3 md:grid-cols-2">
<div><label class="block text-xs text-gray-500 mb-1"><?= htmlspecialchars($L['label_image_url'], ENT_QUOTES, 'UTF-8') ?></label><input type="text" data-field="url" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"><input type="hidden" data-field="type" value="image"></div>
<div><label class="block text-xs text-gray-500 mb-1"><?= htmlspecialchars($L['label_image_alt'], ENT_QUOTES, 'UTF-8') ?></label><input type="text" data-field="alt" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"></div>
</div></div>
</template>

<script>
(() => {
    const container = document.getElementById('blocksContainer');
    const emptyHint = document.getElementById('blocksEmptyHint');
    let blockIndex = <?= count($blocks) ?>;
    function updateEmptyHint(){ if(emptyHint) emptyHint.classList.toggle('hidden', container.children.length > 0); }
    function bindColorSync(root){
        root.querySelectorAll('input[type="color"]').forEach(colorInput => {
            const textInput = colorInput.parentElement?.querySelector('[data-color-text]') || document.querySelector('[data-color-sync="' + colorInput.name + '"]');
            if(!textInput) return;
            colorInput.addEventListener('input', () => { textInput.value = colorInput.value; });
            textInput.addEventListener('input', () => { if(/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(textInput.value)) colorInput.value = textInput.value; });
        });
    }
    function assignBlockNames(blockEl){
        const idx = blockIndex++;
        blockEl.querySelectorAll('[data-field]').forEach(input => {
            input.name = 'blocks[' + idx + '][' + input.dataset.field + ']';
            if(input.dataset.field === 'color'){
                const text = blockEl.querySelector('[data-color-text]');
                if(text) text.setAttribute('data-color-sync', input.name);
            }
        });
        bindColorSync(blockEl);
    }
    function bindRemove(blockEl){
        blockEl.querySelector('[data-remove-block]')?.addEventListener('click', () => { blockEl.remove(); updateEmptyHint(); });
    }
    document.querySelectorAll('.block-item').forEach(item => bindRemove(item));
    bindColorSync(document);
    updateEmptyHint();
    document.querySelectorAll('[data-add-block]').forEach(btn => {
        btn.addEventListener('click', () => {
            const template = document.getElementById(btn.dataset.addBlock === 'link' ? 'linkBlockTemplate' : 'imageBlockTemplate');
            if(!template) return;
            const node = template.content.firstElementChild.cloneNode(true);
            assignBlockNames(node);
            bindRemove(node);
            container.appendChild(node);
            updateEmptyHint();
        });
    });
    document.querySelectorAll('[data-color-sync]').forEach(textInput => {
        const colorInput = document.querySelector('[name="' + textInput.dataset.colorSync + '"]');
        if(!colorInput) return;
        textInput.addEventListener('input', () => { if(/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6})$/.test(textInput.value)) colorInput.value = textInput.value; });
        colorInput.addEventListener('input', () => { textInput.value = colorInput.value; });
    });
})();
</script>
