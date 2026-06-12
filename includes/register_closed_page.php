<?php

function defaultRegisterClosedPageConfig(): array
{
    return [
        'text' => '注册功能已关闭，暂不支持新用户注册。',
        'text_color' => '#4b5563',
        'text_size' => 14,
        'blocks' => [],
    ];
}

function normalizeRegisterClosedPageConfig(array $data): array
{
    $defaults = defaultRegisterClosedPageConfig();
    $text = trim((string)($data['text'] ?? $defaults['text']));
    $textColor = sanitizeSettingColor((string)($data['text_color'] ?? $defaults['text_color']), $defaults['text_color']);
    $textSize = sanitizeSettingFontSize($data['text_size'] ?? $defaults['text_size'], (int)$defaults['text_size']);

    $blocks = [];
    foreach ($data['blocks'] ?? [] as $block) {
        if (!is_array($block)) {
            continue;
        }

        $type = (string)($block['type'] ?? '');
        if ($type === 'link') {
            $label = trim((string)($block['label'] ?? ''));
            $url = trim((string)($block['url'] ?? ''));
            if ($label === '' || !isValidEmbedUrl($url)) {
                continue;
            }
            $blocks[] = [
                'type' => 'link',
                'label' => $label,
                'url' => $url,
                'color' => sanitizeSettingColor((string)($block['color'] ?? '#2563eb')),
            ];
        } elseif ($type === 'image') {
            $url = trim((string)($block['url'] ?? ''));
            if (!isValidEmbedUrl($url)) {
                continue;
            }
            $blocks[] = [
                'type' => 'image',
                'url' => $url,
                'alt' => trim((string)($block['alt'] ?? '')),
            ];
        }
    }

    return [
        'text' => $text !== '' ? $text : $defaults['text'],
        'text_color' => $textColor,
        'text_size' => $textSize,
        'blocks' => $blocks,
    ];
}

function getRegisterClosedPageConfig(PDO $pdo): array
{
    $raw = getSetting($pdo, 'register_closed_page_config', '');
    if ($raw === '') {
        return defaultRegisterClosedPageConfig();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return defaultRegisterClosedPageConfig();
    }

    return normalizeRegisterClosedPageConfig($data);
}

function saveRegisterClosedPageConfig(PDO $pdo, array $config): void
{
    $normalized = normalizeRegisterClosedPageConfig($config);
    setSetting(
        $pdo,
        'register_closed_page_config',
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function parseRegisterClosedPageConfigFromPost(array $post): array
{
    $blocks = [];
    foreach ($post['blocks'] ?? [] as $block) {
        if (!is_array($block)) {
            continue;
        }
        $blocks[] = $block;
    }

    return normalizeRegisterClosedPageConfig([
        'text' => $post['closed_text'] ?? '',
        'text_color' => $post['closed_text_color'] ?? '',
        'text_size' => $post['closed_text_size'] ?? '',
        'blocks' => $blocks,
    ]);
}

function isValidSettingColor(string $color): bool
{
    return (bool)preg_match('/^#([0-9a-fA-F]{3}|[0-9a-fA-F]{6}|[0-9a-fA-F]{8})$/', $color);
}

function sanitizeSettingColor(string $color, string $fallback = '#4b5563'): string
{
    return isValidSettingColor($color) ? $color : $fallback;
}

function sanitizeSettingFontSize($size, int $fallback = 14): int
{
    $size = (int)$size;
    if ($size < 12) {
        return 12;
    }
    if ($size > 48) {
        return 48;
    }
    return $size > 0 ? $size : $fallback;
}

function isValidEmbedUrl(string $url): bool
{
    if ($url === '') {
        return false;
    }

    if (preg_match('#^(/|\./|\../)#', $url)) {
        return true;
    }

    if (!preg_match('#^https?://#i', $url)) {
        return false;
    }

    return filter_var($url, FILTER_VALIDATE_URL) !== false;
}
