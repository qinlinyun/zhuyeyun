<?php
/**
 * 注册关闭时的前台展示内容
 *
 * @var array $closedPageConfig normalizeRegisterClosedPageConfig 返回值
 */
$closedPageConfig = $closedPageConfig ?? defaultRegisterClosedPageConfig();
$text = $closedPageConfig['text'];
$textColor = sanitizeSettingColor($closedPageConfig['text_color']);
$textSize = sanitizeSettingFontSize($closedPageConfig['text_size'] ?? 14);
$blocks = $closedPageConfig['blocks'];
?>
<div class="register-closed-content space-y-4 text-center">
    <p class="leading-relaxed whitespace-pre-line" style="color: <?= htmlspecialchars($textColor, ENT_QUOTES, 'UTF-8') ?>; font-size: <?= (int)$textSize ?>px">
        <?= htmlspecialchars($text, ENT_QUOTES, 'UTF-8') ?>
    </p>

    <?php if (!empty($blocks)): ?>
    <div class="space-y-3">
        <?php foreach ($blocks as $block): ?>
            <?php if ($block['type'] === 'link'): ?>
            <div>
                <a
                    href="<?= htmlspecialchars($block['url'], ENT_QUOTES, 'UTF-8') ?>"
                    class="inline-flex items-center justify-center rounded-lg border border-gray-200 bg-white px-4 py-2 text-sm font-medium hover:bg-gray-50 transition"
                    style="color: <?= htmlspecialchars(sanitizeSettingColor($block['color']), ENT_QUOTES, 'UTF-8') ?>"
                    target="_blank"
                    rel="noopener noreferrer"
                >
                    <?= htmlspecialchars($block['label'], ENT_QUOTES, 'UTF-8') ?>
                </a>
            </div>
            <?php elseif ($block['type'] === 'image'): ?>
            <div class="flex justify-center">
                <img
                    src="<?= htmlspecialchars($block['url'], ENT_QUOTES, 'UTF-8') ?>"
                    alt="<?= htmlspecialchars($block['alt'], ENT_QUOTES, 'UTF-8') ?>"
                    class="max-w-full rounded-lg border border-gray-200"
                    loading="lazy"
                >
            </div>
            <?php endif; ?>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>
