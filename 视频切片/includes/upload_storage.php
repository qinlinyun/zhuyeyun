<?php

/**
 * 是否配置了独立上传域名（与当前访问域名区分，仍为本机存储）
 */
function isUploadDomainConfigured(): bool
{
    return getDomainSetting(DOMAIN_SETTING_UPLOAD) !== '';
}

/**
 * 上传表单提交地址：未配置则走当前域名；配置则走上传域名（同机不同站点/域名）
 */
function getUploadFormActionUrl(): string
{
    $uploadDomain = getDomainSetting(DOMAIN_SETTING_UPLOAD);
    if ($uploadDomain === '') {
        return 'index.php';
    }

    $scriptDir = dirname($_SERVER['SCRIPT_NAME'] ?? '/index.php');
    $scriptDir = str_replace('\\', '/', $scriptDir);
    if ($scriptDir === '/' || $scriptDir === '.') {
        $scriptDir = '';
    }

    return 'https://' . $uploadDomain . $scriptDir . '/index.php';
}

function buildUploadAssetUrl(string $directory, string $filename): string
{
    return buildHttpsUrl('upload', $directory . '/' . ltrim($filename, '/'));
}
