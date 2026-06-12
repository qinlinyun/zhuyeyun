<?php

function defaultThemeBackgroundConfig(): array
{
    return [
        'mode' => 'none',
        'bg_color' => '#f1f1f1',
        'bg_image_pc' => '',
        'bg_image_mobile' => '',
        'blur_px' => 14,
    ];
}

function normalizeThemeBackgroundConfig(array $data): array
{
    $defaults = defaultThemeBackgroundConfig();
    $mode = (string)($data['mode'] ?? $defaults['mode']);
    if (!in_array($mode, ['none', 'color', 'image'], true)) {
        $mode = 'none';
    }

    $blur = (int)($data['blur_px'] ?? $defaults['blur_px']);
    $blur = max(0, min(40, $blur));

    $color = trim((string)($data['bg_color'] ?? $defaults['bg_color']));
    if (!preg_match('/^#[0-9a-fA-F]{6}$/', $color)) {
        $color = $defaults['bg_color'];
    }

    return [
        'mode' => $mode,
        'bg_color' => $color,
        'bg_image_pc' => trim((string)($data['bg_image_pc'] ?? '')),
        'bg_image_mobile' => trim((string)($data['bg_image_mobile'] ?? '')),
        'blur_px' => $blur,
    ];
}

function getThemeBackgroundConfig(PDO $pdo): array
{
    $raw = getSetting($pdo, 'theme_background_config', '');
    if ($raw === '') {
        return defaultThemeBackgroundConfig();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return defaultThemeBackgroundConfig();
    }

    return normalizeThemeBackgroundConfig($data);
}

function saveThemeBackgroundConfig(PDO $pdo, array $config): void
{
    $normalized = normalizeThemeBackgroundConfig($config);
    setSetting(
        $pdo,
        'theme_background_config',
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function parseThemeBackgroundConfigFromPost(array $post): array
{
    $mode = (string)($post['bg_mode'] ?? 'none');
    if (!in_array($mode, ['none', 'color', 'image'], true)) {
        $mode = 'none';
    }

    return normalizeThemeBackgroundConfig([
        'mode' => $mode,
        'bg_color' => $post['bg_color'] ?? '#f1f1f1',
        'bg_image_pc' => $post['bg_image_pc'] ?? '',
        'bg_image_mobile' => $post['bg_image_mobile'] ?? '',
        'blur_px' => $post['blur_px'] ?? 14,
    ]);
}

function themeBackgroundValidationError(array $config): ?string
{
    if ($config['mode'] === 'image') {
        if ($config['bg_image_pc'] === '') {
            return '请填写或上传 PC 端背景图片';
        }
    }

    return null;
}

function saveThemeBackgroundImage(array $file, string $uploadDir): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['path' => null, 'error' => null];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['path' => null, 'error' => '图片上传失败，请重试'];
    }

    if (($file['size'] ?? 0) > 10 * 1024 * 1024) {
        return ['path' => null, 'error' => '图片大小不能超过 10MB'];
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['path' => null, 'error' => '无效的上传文件'];
    }

    $imgInfo = @getimagesize($file['tmp_name']);
    if (!$imgInfo) {
        return ['path' => null, 'error' => '无法识别图片格式，请上传 jpg / png / webp 图片'];
    }

    $extMap = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];
    if (!isset($extMap[$imgInfo[2]])) {
        return ['path' => null, 'error' => '仅支持 jpg / jpeg / png / webp 格式'];
    }
    $ext = $extMap[$imgInfo[2]];

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['path' => null, 'error' => '无法创建上传目录'];
    }

    $fileName = 'bg_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $uploadDir), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['path' => null, 'error' => '图片保存失败'];
    }

    return ['path' => 'uploads/theme/' . $fileName, 'error' => null];
}

function themeImageUrlForCss(string $pathOrUrl): string
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

function renderThemeDynamicStyles(PDO $pdo): string
{
    $cfg = getThemeBackgroundConfig($pdo);
    $blur = (int)$cfg['blur_px'];
    $css = ":root{--theme-blur:{$blur}px;}\n";

    if ($cfg['mode'] === 'color') {
        $color = htmlspecialchars($cfg['bg_color'], ENT_QUOTES, 'UTF-8');
        $css .= "body{background-color:{$color}!important;background-image:none!important;}\n";
        $css .= ".dark body{background-color:{$color}!important;}\n";
    } elseif ($cfg['mode'] === 'image') {
        $pc = themeImageUrlForCss($cfg['bg_image_pc']);
        $mobile = themeImageUrlForCss($cfg['bg_image_mobile'] !== '' ? $cfg['bg_image_mobile'] : $cfg['bg_image_pc']);
        if ($pc !== '') {
            $pcEsc = htmlspecialchars($pc, ENT_QUOTES, 'UTF-8');
            $mobileEsc = htmlspecialchars($mobile, ENT_QUOTES, 'UTF-8');
            $css .= ":root{--bg-pc:url(\"{$pcEsc}\");--bg-mobile:url(\"{$mobileEsc}\");}\n";
            $css .= "body{background:linear-gradient(rgba(0,0,0,.25),rgba(0,0,0,.25)),var(--bg-pc)!important;background-size:cover!important;background-attachment:fixed!important;}\n";
            $css .= "@media (max-width:768px){body{background:linear-gradient(rgba(0,0,0,.25),rgba(0,0,0,.25)),var(--bg-mobile)!important;background-attachment:scroll!important;}}\n";
            $css .= ".dark body{background:linear-gradient(rgba(0,0,0,.25),rgba(0,0,0,.25)),var(--bg-pc)!important;background-size:cover!important;}\n";
        }
    }

    $css .= ".glass{backdrop-filter:blur(var(--theme-blur));-webkit-backdrop-filter:blur(var(--theme-blur));}\n";
    $css .= ".platform-managed-badge{backdrop-filter:blur(var(--theme-blur));-webkit-backdrop-filter:blur(var(--theme-blur));}\n";

    // body 上的 bg-gray-100 / bg-slate-100 应使用页面背景色，而非 surface-muted
    $css .= "html:not(.dark) body.bg-gray-100,html:not(.dark) body.bg-slate-100{background-color:var(--theme-body-bg,#f1f1f1)!important;color:var(--theme-body-text,#0f0f0f)!important;}\n";
    $css .= "html.dark body.bg-gray-100,html.dark body.bg-slate-100{background-color:var(--theme-body-bg,#0f172a)!important;color:var(--theme-body-text,#e5e7eb)!important;}\n";

    if (function_exists('renderThemeColorsDynamicCss')) {
        $css .= renderThemeColorsDynamicCss($pdo);
    }

    return '<style id="theme-dynamic">' . $css . '</style>';
}
