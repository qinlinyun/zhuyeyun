<?php
/** @var array $bgConfig */
/** @var string $message */
/** @var string $error */
$bgConfig = $bgConfig ?? defaultThemeBackgroundConfig();
$mode = $bgConfig['mode'];
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

    <p class="mb-4 text-sm text-gray-500">
        背景颜色与背景图片只能启用一种；关闭后使用系统默认背景。毛玻璃模糊度作用于卡片、导航等半透明区域。
    </p>

    <form method="POST" enctype="multipart/form-data" class="max-w-xl space-y-4" id="themeBackgroundForm">
        <input type="hidden" name="panel" value="theme_background">

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4 space-y-3">
            <p class="text-sm font-medium text-gray-900">背景类型</p>
            <div class="flex flex-wrap gap-4 text-sm">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="bg_mode" value="none" class="text-blue-600" <?= $mode === 'none' ? 'checked' : '' ?>>
                    <span>默认（不自定义）</span>
                </label>
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="bg_mode" value="color" class="text-blue-600" <?= $mode === 'color' ? 'checked' : '' ?>>
                    <span>纯色背景</span>
                </label>
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="bg_mode" value="image" class="text-blue-600" <?= $mode === 'image' ? 'checked' : '' ?>>
                    <span>图片背景</span>
                </label>
            </div>
        </div>

        <div id="bgColorPanel" class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4 <?= $mode !== 'color' ? 'hidden' : '' ?>">
            <label for="bg_color" class="block text-sm font-medium text-gray-900">背景颜色</label>
            <p class="mt-1 text-xs text-gray-500">启用后全站页面 body 使用该纯色作为背景</p>
            <div class="mt-2 flex items-center gap-3">
                <input type="color" id="bg_color" name="bg_color" value="<?= htmlspecialchars($bgConfig['bg_color'], ENT_QUOTES, 'UTF-8') ?>" class="h-10 w-14 cursor-pointer rounded border border-gray-300 bg-white">
                <input type="text" data-color-sync="bg_color" value="<?= htmlspecialchars($bgConfig['bg_color'], ENT_QUOTES, 'UTF-8') ?>" class="w-32 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-mono">
            </div>
        </div>

        <div id="bgImagePanel" class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4 space-y-4 <?= $mode !== 'image' ? 'hidden' : '' ?>">
            <p class="text-sm font-medium text-gray-900">背景图片</p>
            <p class="text-xs text-gray-500">可填写外链 URL，或上传本地图片；移动端留空则使用 PC 图</p>

            <div>
                <label for="bg_image_pc" class="block text-xs text-gray-500 mb-1">PC 端图片 URL</label>
                <input type="text" id="bg_image_pc" name="bg_image_pc" value="<?= htmlspecialchars($bgConfig['bg_image_pc'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm" placeholder="https://... 或 uploads/theme/...">
                <label class="mt-2 block text-xs text-gray-500">或上传 PC 端图片</label>
                <input type="file" name="bg_image_pc_file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-sm text-gray-600">
            </div>

            <div>
                <label for="bg_image_mobile" class="block text-xs text-gray-500 mb-1">移动端图片 URL（可选）</label>
                <input type="text" id="bg_image_mobile" name="bg_image_mobile" value="<?= htmlspecialchars($bgConfig['bg_image_mobile'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm" placeholder="留空则与 PC 相同">
                <label class="mt-2 block text-xs text-gray-500">或上传移动端图片</label>
                <input type="file" name="bg_image_mobile_file" accept="image/jpeg,image/png,image/webp" class="mt-1 block w-full text-sm text-gray-600">
            </div>

            <?php if ($bgConfig['bg_image_pc'] !== ''): ?>
            <p class="text-xs text-gray-500">当前 PC 图：<span class="font-mono break-all"><?= htmlspecialchars($bgConfig['bg_image_pc'], ENT_QUOTES, 'UTF-8') ?></span></p>
            <?php endif; ?>
        </div>

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
            <label for="blur_px" class="block text-sm font-medium text-gray-900">毛玻璃模糊度</label>
            <p class="mt-1 text-xs text-gray-500">0–40 px，作用于 glass 卡片等半透明区域</p>
            <div class="mt-3 flex items-center gap-4">
                <input type="range" id="blur_px" name="blur_px" min="0" max="40" value="<?= (int)$bgConfig['blur_px'] ?>" class="flex-1">
                <output for="blur_px" id="blur_px_out" class="w-12 text-sm font-mono text-gray-700"><?= (int)$bgConfig['blur_px'] ?></output>
                <span class="text-xs text-gray-400">px</span>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                保存背景设置
            </button>
            <button
                type="submit"
                name="theme_background_reset"
                value="1"
                class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50"
                onclick="return confirm('确定恢复背景配置为初始化设置吗？当前背景设置将被覆盖。');"
            >
                一键复原（初始化设置）
            </button>
        </div>
    </form>
</div>

<script>
(() => {
    const form = document.getElementById('themeBackgroundForm');
    const colorPanel = document.getElementById('bgColorPanel');
    const imagePanel = document.getElementById('bgImagePanel');
    const blurRange = document.getElementById('blur_px');
    const blurOut = document.getElementById('blur_px_out');

    function syncPanels() {
        const mode = form.querySelector('input[name="bg_mode"]:checked')?.value || 'none';
        colorPanel.classList.toggle('hidden', mode !== 'color');
        imagePanel.classList.toggle('hidden', mode !== 'image');
    }

    form.querySelectorAll('input[name="bg_mode"]').forEach(r => r.addEventListener('change', syncPanels));

    blurRange?.addEventListener('input', () => {
        blurOut.textContent = blurRange.value;
    });

    document.querySelectorAll('[data-color-sync]').forEach(text => {
        const colorId = text.dataset.colorSync;
        const color = document.getElementById(colorId);
        if (!color) return;
        color.addEventListener('input', () => { text.value = color.value; });
        text.addEventListener('input', () => {
            if (/^#[0-9a-fA-F]{6}$/.test(text.value)) color.value = text.value;
        });
    });
})();
</script>
