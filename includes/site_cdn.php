<?php

declare(strict_types=1);

function siteCdnBase(): string
{
    return 'https://css.qinlinyun.cn';
}

function siteCdnAssetUrl(string $path, string $version = ''): string
{
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $url = rtrim(siteCdnBase(), '/') . '/' . $path;
    if ($version !== '') {
        $url .= (str_contains($url, '?') ? '&' : '?') . 'v=' . rawurlencode($version);
    }

    return $url;
}

function siteThemeStylesheetUrls(bool $useCdn, string $relativePrefix = ''): array
{
    $files = [
        ['path' => 'assets/css/layout.css', 'version' => '1'],
        ['path' => 'assets/css/theme-light.css', 'version' => '2'],
        ['path' => 'assets/css/theme-dark.css', 'version' => '4'],
    ];
    $urls = [];
    foreach ($files as $file) {
        if ($useCdn) {
            $urls[] = siteCdnAssetUrl($file['path'], $file['version']);
        } else {
            $urls[] = $relativePrefix . $file['path'] . '?v=' . rawurlencode($file['version']);
        }
    }

    return $urls;
}
