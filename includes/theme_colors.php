<?php

function defaultThemeDarkColorsConfig(): array
{
    return [
        'enabled' => false,
        'body_bg' => '#0f172a',
        'body_text' => '#e5e7eb',
        'surface' => '#1e293b',
        'surface_alpha' => 75,
        'surface_muted' => '#334155',
        'surface_muted_alpha' => 55,
        'text_primary' => '#e5e7eb',
        'text_secondary' => '#cbd5e1',
        'text_muted' => '#9ca3af',
        'border' => '#334155',
        'input_bg' => '#0f172a',
        'input_border' => '#334155',
        'accent' => '#ef4444',
        'glass' => '#1e293b',
        'glass_alpha' => 65,
    ];
}

function defaultThemeLightColorsConfig(): array
{
    return [
        'enabled' => false,
        'body_bg' => '#f1f1f1',
        'body_text' => '#0f0f0f',
        'surface' => '#ffffff',
        'surface_alpha' => 100,
        'surface_muted' => '#f9fafb',
        'surface_muted_alpha' => 100,
        'text_primary' => '#111827',
        'text_secondary' => '#4b5563',
        'text_muted' => '#6b7280',
        'border' => '#e5e7eb',
        'input_bg' => '#ffffff',
        'input_border' => '#d1d5db',
        'accent' => '#2563eb',
        'glass' => '#ffffff',
        'glass_alpha' => 90,
    ];
}

function themeNormalizeHexColor(string $value, string $fallback): string
{
    $value = trim($value);
    if (preg_match('/^#[0-9a-fA-F]{6}$/', $value)) {
        return strtolower($value);
    }

    return strtolower($fallback);
}

function normalizeThemeColorsConfig(array $data, array $defaults): array
{
    $out = ['enabled' => !empty($data['enabled'])];

    $hexKeys = [
        'body_bg', 'body_text', 'surface', 'surface_muted',
        'text_primary', 'text_secondary', 'text_muted', 'border',
        'input_bg', 'input_border', 'accent', 'glass',
    ];
    foreach ($hexKeys as $key) {
        $out[$key] = themeNormalizeHexColor((string)($data[$key] ?? ''), $defaults[$key]);
    }

    foreach (['surface_alpha', 'surface_muted_alpha', 'glass_alpha'] as $key) {
        $out[$key] = max(0, min(100, (int)($data[$key] ?? $defaults[$key])));
    }

    return $out;
}

function getThemeDarkColorsConfig(PDO $pdo): array
{
    $defaults = defaultThemeDarkColorsConfig();
    $raw = getSetting($pdo, 'theme_dark_colors_config', '');
    if ($raw === '') {
        return $defaults;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? normalizeThemeColorsConfig($data, $defaults) : $defaults;
}

function getThemeLightColorsConfig(PDO $pdo): array
{
    $defaults = defaultThemeLightColorsConfig();
    $raw = getSetting($pdo, 'theme_light_colors_config', '');
    if ($raw === '') {
        return $defaults;
    }
    $data = json_decode($raw, true);

    return is_array($data) ? normalizeThemeColorsConfig($data, $defaults) : $defaults;
}

function saveThemeDarkColorsConfig(PDO $pdo, array $config): void
{
    $normalized = normalizeThemeColorsConfig($config, defaultThemeDarkColorsConfig());
    setSetting(
        $pdo,
        'theme_dark_colors_config',
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function saveThemeLightColorsConfig(PDO $pdo, array $config): void
{
    $normalized = normalizeThemeColorsConfig($config, defaultThemeLightColorsConfig());
    setSetting(
        $pdo,
        'theme_light_colors_config',
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function parseThemeColorsConfigFromPost(array $post, string $mode): array
{
    $defaults = $mode === 'light' ? defaultThemeLightColorsConfig() : defaultThemeDarkColorsConfig();

    // 保存后默认启用自定义颜色
    $data = ['enabled' => true];
    foreach (array_keys($defaults) as $key) {
        if ($key === 'enabled') {
            continue;
        }
        if (isset($post[$key])) {
            $data[$key] = $post[$key];
        }
    }

    return normalizeThemeColorsConfig($data, $defaults);
}

function themeHexToRgba(string $hex, int $alphaPercent): string
{
    $hex = ltrim($hex, '#');
    $r = hexdec(substr($hex, 0, 2));
    $g = hexdec(substr($hex, 2, 2));
    $b = hexdec(substr($hex, 4, 2));
    $a = max(0, min(100, $alphaPercent)) / 100;

    return sprintf('rgba(%d,%d,%d,%.2f)', $r, $g, $b, $a);
}

function themeEscCss(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function renderThemeColorsVariablesBlock(string $selector, array $cfg): string
{
    $surface = themeHexToRgba($cfg['surface'], (int)$cfg['surface_alpha']);
    $surfaceMuted = themeHexToRgba($cfg['surface_muted'], (int)$cfg['surface_muted_alpha']);
    $glass = themeHexToRgba($cfg['glass'], (int)$cfg['glass_alpha']);

    $vars = [
        '--theme-body-bg' => $cfg['body_bg'],
        '--theme-body-text' => $cfg['body_text'],
        '--theme-surface' => $surface,
        '--theme-surface-muted' => $surfaceMuted,
        '--theme-text-primary' => $cfg['text_primary'],
        '--theme-text-secondary' => $cfg['text_secondary'],
        '--theme-text-muted' => $cfg['text_muted'],
        '--theme-border' => $cfg['border'],
        '--theme-input-bg' => $cfg['input_bg'],
        '--theme-input-border' => $cfg['input_border'],
        '--theme-accent' => $cfg['accent'],
        '--theme-glass' => $glass,
    ];

    $lines = [];
    foreach ($vars as $name => $value) {
        $lines[] = $name . ':' . themeEscCss($value);
    }

    return $selector . '{' . implode(';', $lines) . "}\n";
}

function renderThemeColorsDynamicCss(PDO $pdo): string
{
    $dark = getThemeDarkColorsConfig($pdo);
    $light = getThemeLightColorsConfig($pdo);
    $css = '';
    $darkSaved = getSetting($pdo, 'theme_dark_colors_config', '') !== '';
    $lightSaved = getSetting($pdo, 'theme_light_colors_config', '') !== '';

    if (!empty($dark['enabled']) || $darkSaved) {
        $css .= renderThemeColorsVariablesBlock('html.dark', $dark);
    }
    if (!empty($light['enabled']) || $lightSaved) {
        $css .= renderThemeColorsVariablesBlock('html:not(.dark)', $light);
    }

    return $css;
}
