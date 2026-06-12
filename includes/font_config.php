<?php

function defaultFontConfig(): array
{
    return [
        'mode' => 'default',
        'family_name' => '',
        'font_url' => '',
        'font_file' => '',
        'font_format' => 'woff2',
        'fallback' => "-apple-system, BlinkMacSystemFont, 'Segoe UI', Roboto, 'Helvetica Neue', Arial, sans-serif",
    ];
}

function normalizeFontConfig(array $data): array
{
    $defaults = defaultFontConfig();
    $mode = (string)($data['mode'] ?? $defaults['mode']);
    if (!in_array($mode, ['default', 'url', 'upload'], true)) {
        $mode = 'default';
    }

    $format = strtolower((string)($data['font_format'] ?? $defaults['font_format']));
    if (!in_array($format, ['woff2', 'woff', 'truetype', 'opentype'], true)) {
        $format = 'woff2';
    }

    return [
        'mode' => $mode,
        'family_name' => trim((string)($data['family_name'] ?? '')),
        'font_url' => trim((string)($data['font_url'] ?? '')),
        'font_file' => trim((string)($data['font_file'] ?? '')),
        'font_format' => $format,
        'fallback' => trim((string)($data['fallback'] ?? $defaults['fallback'])) ?: $defaults['fallback'],
    ];
}

function getFontConfig(PDO $pdo): array
{
    $raw = getSetting($pdo, 'font_config', '');
    if ($raw === '') {
        return defaultFontConfig();
    }

    $data = json_decode($raw, true);

    return is_array($data) ? normalizeFontConfig($data) : defaultFontConfig();
}

function saveFontConfig(PDO $pdo, array $config): void
{
    setSetting(
        $pdo,
        'font_config',
        json_encode(normalizeFontConfig($config), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function fontConfigDraftSet(array $config): void
{
    $_SESSION['_font_config_draft'] = normalizeFontConfig($config);
}

function fontConfigDraftTake(): ?array
{
    if (empty($_SESSION['_font_config_draft']) || !is_array($_SESSION['_font_config_draft'])) {
        return null;
    }
    $draft = normalizeFontConfig($_SESSION['_font_config_draft']);
    unset($_SESSION['_font_config_draft']);

    return $draft;
}

function fontConfigDraftClear(): void
{
    unset($_SESSION['_font_config_draft']);
}

/** 保存时合并：保留各来源已填写的 URL / 上传路径，不因切换 mode 清空 */
function mergeFontConfigPreserveFields(array $incoming, array $existing): array
{
    $merged = normalizeFontConfig($incoming);

    if ($merged['font_url'] === '' && $existing['font_url'] !== '') {
        $merged['font_url'] = $existing['font_url'];
    }

    if ($merged['font_file'] === '' && $existing['font_file'] !== '') {
        $merged['font_file'] = $existing['font_file'];
        $merged['font_format'] = $existing['font_format'] ?: $merged['font_format'];
    }

    return $merged;
}

function parseFontConfigFromPost(array $post): array
{
    $mode = (string)($post['font_mode'] ?? 'default');
    if (!in_array($mode, ['default', 'url', 'upload'], true)) {
        $mode = 'default';
    }

    return normalizeFontConfig([
        'mode' => $mode,
        'family_name' => $post['family_name'] ?? '',
        'font_url' => $post['font_url'] ?? '',
        'font_file' => $post['font_file'] ?? '',
        'font_format' => $post['font_format'] ?? 'woff2',
        'fallback' => $post['fallback'] ?? defaultFontConfig()['fallback'],
    ]);
}

function fontConfigValidationError(array $config): ?string
{
    if ($config['mode'] === 'default') {
        return null;
    }

    if ($config['family_name'] === '') {
        return '请填写字体名称（font-family 使用的名称）';
    }

    if ($config['mode'] === 'url' && $config['font_url'] === '') {
        return '请填写字体 URL';
    }

    if ($config['mode'] === 'upload' && $config['font_file'] === '') {
        return '请上传字体文件或保留已有文件';
    }

    if ($config['mode'] === 'url' && !filter_var($config['font_url'], FILTER_VALIDATE_URL)) {
        return '字体 URL 格式不正确';
    }

    return null;
}

function fontFormatFromExtension(string $ext): string
{
    $map = [
        'woff2' => 'woff2',
        'woff' => 'woff',
        'ttf' => 'truetype',
        'otf' => 'opentype',
    ];
    $ext = strtolower($ext);

    return $map[$ext] ?? 'woff2';
}

function fontUrlAssetPath(string $pathOrUrl): string
{
    $pathOrUrl = trim($pathOrUrl);
    if ($pathOrUrl === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $pathOrUrl)) {
        return $pathOrUrl;
    }

    return '/' . ltrim(str_replace('\\', '/', $pathOrUrl), '/');
}

function fontUrlKind(string $url): string
{
    $path = strtolower(parse_url($url, PHP_URL_PATH) ?? '');
    if (preg_match('/\.(woff2?|ttf|otf)(\?|$)/i', $path)) {
        return 'file';
    }
    if (preg_match('/\.css(\?|$)/i', $path) || str_contains($url, 'fonts.googleapis.com') || str_contains($url, 'fonts.gstatic.com')) {
        return 'stylesheet';
    }

    return 'stylesheet';
}

function saveCustomFontFile(array $file, string $uploadDir): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['path' => null, 'format' => null, 'error' => null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['path' => null, 'format' => null, 'error' => '字体文件上传失败，请重试'];
    }

    if (($file['size'] ?? 0) > 15 * 1024 * 1024) {
        return ['path' => null, 'format' => null, 'error' => '字体文件大小不能超过 15MB'];
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['path' => null, 'format' => null, 'error' => '无效的上传文件'];
    }

    $ext = strtolower(pathinfo($file['name'] ?? '', PATHINFO_EXTENSION));
    $allowed = ['woff2', 'woff', 'ttf', 'otf'];
    if (!in_array($ext, $allowed, true)) {
        return ['path' => null, 'format' => null, 'error' => '仅支持 woff2 / woff / ttf / otf 格式'];
    }

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['path' => null, 'format' => null, 'error' => '无法创建上传目录'];
    }

    $fileName = 'font_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $uploadDir), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['path' => null, 'format' => null, 'error' => '字体文件保存失败'];
    }

    return [
        'path' => 'uploads/fonts/' . $fileName,
        'format' => fontFormatFromExtension($ext),
        'error' => null,
    ];
}

function fontEscHtml(string $value): string
{
    return htmlspecialchars($value, ENT_QUOTES, 'UTF-8');
}

function fontEscCss(string $value): string
{
    $value = str_replace(["\r", "\n"], '', $value);
    $value = str_replace('\\', '\\\\', $value);
    $value = str_replace("'", "\\'", $value);

    return $value;
}

function renderFontFamilyCss(array $cfg): string
{
    $family = fontEscCss($cfg['family_name']);
    $fallback = fontEscCss($cfg['fallback']);

    return "html,body,button,input,textarea,select,.font-sans{font-family:'{$family}',{$fallback}!important;}\n";
}

function renderFontDynamicOutput(PDO $pdo): string
{
    $cfg = getFontConfig($pdo);
    if ($cfg['mode'] === 'default' || $cfg['family_name'] === '') {
        return '';
    }

    $out = '';

    if ($cfg['mode'] === 'url') {
        $url = $cfg['font_url'];
        if ($url === '') {
            return '';
        }

        $kind = fontUrlKind($url);
        if ($kind === 'stylesheet') {
            $out .= '<link rel="stylesheet" href="' . fontEscHtml($url) . '" id="site-custom-font-css">' . "\n";
            $out .= '<style id="site-custom-font">' . renderFontFamilyCss($cfg) . '</style>';
        } else {
            $src = fontEscCss(fontUrlAssetPath($url));
            $format = fontEscCss($cfg['font_format'] ?: fontFormatFromExtension(pathinfo($url, PATHINFO_EXTENSION)));
            $family = fontEscCss($cfg['family_name']);
            $css = "@font-face{font-family:'{$family}';src:url('{$src}') format('{$format}');font-display:swap;}\n";
            $css .= renderFontFamilyCss($cfg);
            $out .= '<style id="site-custom-font">' . $css . '</style>';
        }

        return $out;
    }

    if ($cfg['mode'] === 'upload' && $cfg['font_file'] !== '') {
        $src = fontEscCss(fontUrlAssetPath($cfg['font_file']));
        $format = fontEscCss($cfg['font_format']);
        $family = fontEscCss($cfg['family_name']);
        $css = "@font-face{font-family:'{$family}';src:url('{$src}') format('{$format}');font-display:swap;}\n";
        $css .= renderFontFamilyCss($cfg);
        $out .= '<style id="site-custom-font">' . $css . '</style>';
    }

    return $out;
}
