<?php
/** @var string $themeColorMode dark|light */
/** @var array $colorsConfig */
/** @var string $themeColorsDescription */
/** @var string $message */
/** @var string $error */
$themeColorMode = $themeColorMode ?? 'dark';
$colorsConfig = $colorsConfig ?? ($themeColorMode === 'light' ? defaultThemeLightColorsConfig() : defaultThemeDarkColorsConfig());
$panelName = $themeColorMode === 'light' ? 'theme_light_colors' : 'theme_dark_colors';
$defaults = $themeColorMode === 'light' ? defaultThemeLightColorsConfig() : defaultThemeDarkColorsConfig();
$L = require __DIR__ . '/../../includes/theme_color_labels.php';
$hexFields = ['body_bg', 'body_text', 'surface', 'surface_muted', 'text_primary', 'text_secondary', 'text_muted', 'border', 'input_bg', 'input_border', 'accent', 'glass'];
$alphaFields = ['surface_alpha', 'surface_muted_alpha', 'glass_alpha'];
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
        <?= htmlspecialchars($themeColorsDescription ?? '', ENT_QUOTES, 'UTF-8') ?>
    </p>

    <form method="POST" class="max-w-2xl space-y-4" id="themeColorsForm">
        <input type="hidden" name="panel" value="<?= htmlspecialchars($panelName, ENT_QUOTES, 'UTF-8') ?>">
        <input type="hidden" name="theme_color_mode" value="<?= htmlspecialchars($themeColorMode, ENT_QUOTES, 'UTF-8') ?>">

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($L['enabled']['label'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-xs text-gray-500"><?= htmlspecialchars($L['enabled']['help'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input type="checkbox" name="colors_enabled" value="1" class="peer sr-only" <?= !empty($colorsConfig['enabled']) ? 'checked' : '' ?>>
                    <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-300"></span>
                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                </label>
            </div>
        </div>

        <div class="grid gap-3 sm:grid-cols-2" id="themeColorFields">
            <?php foreach ($hexFields as $field): ?>
            <?php $meta = $L[$field] ?? ['label' => $field, 'help' => '']; ?>
            <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                <label for="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>" class="block text-sm font-medium text-gray-900">
                    <?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?>
                </label>
                <?php if (!empty($meta['help'])): ?>
                <p class="mt-0.5 text-xs text-gray-500"><?= htmlspecialchars($meta['help'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <div class="mt-2 flex items-center gap-2">
                    <input
                        type="color"
                        id="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>"
                        name="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>"
                        value="<?= htmlspecialchars($colorsConfig[$field], ENT_QUOTES, 'UTF-8') ?>"
                        class="h-9 w-12 cursor-pointer rounded border border-gray-300 bg-white"
                    >
                    <input
                        type="text"
                        data-color-sync="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>"
                        value="<?= htmlspecialchars($colorsConfig[$field], ENT_QUOTES, 'UTF-8') ?>"
                        class="w-28 rounded-lg border border-gray-300 bg-white px-2 py-1.5 text-xs font-mono"
                    >
                </div>
            </div>
            <?php endforeach; ?>

            <?php foreach ($alphaFields as $field): ?>
            <?php $meta = $L[$field] ?? ['label' => $field, 'help' => '']; ?>
            <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3">
                <label for="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>" class="block text-sm font-medium text-gray-900">
                    <?= htmlspecialchars($meta['label'], ENT_QUOTES, 'UTF-8') ?>
                </label>
                <?php if (!empty($meta['help'])): ?>
                <p class="mt-0.5 text-xs text-gray-500"><?= htmlspecialchars($meta['help'], ENT_QUOTES, 'UTF-8') ?></p>
                <?php endif; ?>
                <div class="mt-2 flex items-center gap-3">
                    <input
                        type="range"
                        id="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>"
                        name="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>"
                        min="0"
                        max="100"
                        value="<?= (int)$colorsConfig[$field] ?>"
                        class="flex-1"
                        data-alpha-out="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>_out"
                    >
                    <output id="<?= htmlspecialchars($field, ENT_QUOTES, 'UTF-8') ?>_out" class="w-10 text-xs font-mono text-gray-700"><?= (int)$colorsConfig[$field] ?></output>
                    <span class="text-xs text-gray-400">%</span>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                保存<?= $themeColorMode === 'light' ? '浅色' : '深色' ?>主题颜色
            </button>
            <button
                type="button"
                id="resetThemeColors"
                class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                data-defaults="<?= htmlspecialchars(json_encode($defaults), ENT_QUOTES, 'UTF-8') ?>"
            >
                恢复默认色值
            </button>
            <button
                type="submit"
                name="theme_colors_reset"
                value="1"
                class="rounded-lg border border-red-300 bg-white px-4 py-2 text-sm font-medium text-red-600 hover:bg-red-50"
                onclick="return confirm('确定恢复当前配置为初始化设置吗？已保存配置将被覆盖。');"
            >
                一键复原（初始化设置）
            </button>
        </div>
    </form>
</div>

<script>
(() => {
    document.querySelectorAll('[data-color-sync]').forEach(text => {
        const color = document.getElementById(text.dataset.colorSync);
        if (!color) return;
        color.addEventListener('input', () => { text.value = color.value; });
        text.addEventListener('input', () => {
            if (/^#[0-9a-fA-F]{6}$/.test(text.value)) color.value = text.value;
        });
    });

    document.querySelectorAll('[data-alpha-out]').forEach(range => {
        const out = document.getElementById(range.dataset.alphaOut);
        range.addEventListener('input', () => { if (out) out.textContent = range.value; });
    });

    const resetBtn = document.getElementById('resetThemeColors');
    resetBtn?.addEventListener('click', () => {
        let defaults;
        try { defaults = JSON.parse(resetBtn.dataset.defaults || '{}'); } catch { return; }
        Object.keys(defaults).forEach(key => {
            if (key === 'enabled') return;
            const el = document.getElementById(key) || document.querySelector(`[name="${key}"]`);
            if (!el) return;
            el.value = defaults[key];
            if (el.type === 'color') {
                const text = document.querySelector(`[data-color-sync="${key}"]`);
                if (text) text.value = defaults[key];
            }
            if (el.type === 'range') {
                const out = document.getElementById(key + '_out');
                if (out) out.textContent = defaults[key];
            }
        });
    });
})();
</script>
