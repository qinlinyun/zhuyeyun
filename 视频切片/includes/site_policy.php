<?php

require_once __DIR__ . '/domains.php';

const SITE_POLICY_PROXY = 'site_policy_proxy_enabled';
const SITE_POLICY_ANTI_DOWNLOAD = 'site_policy_anti_download';
const SITE_POLICY_TOKEN_AUTO = 'site_policy_token_auto_duration';

function getSitePolicy(): array
{
    return [
        'proxy_enabled' => getDomainSetting(SITE_POLICY_PROXY) === '1',
        'anti_download' => getDomainSetting(SITE_POLICY_ANTI_DOWNLOAD) === '1',
        'token_auto_duration' => getDomainSetting(SITE_POLICY_TOKEN_AUTO) === '1',
    ];
}

function saveSitePolicy(array $policy): array
{
    if (!setDomainSetting(SITE_POLICY_PROXY, !empty($policy['proxy_enabled']) ? '1' : '0')) {
        return ['success' => false, 'message' => '保存代理状态失败'];
    }
    if (!setDomainSetting(SITE_POLICY_ANTI_DOWNLOAD, !empty($policy['anti_download']) ? '1' : '0')) {
        return ['success' => false, 'message' => '保存防下载状态失败'];
    }
    if (!setDomainSetting(SITE_POLICY_TOKEN_AUTO, !empty($policy['token_auto_duration']) ? '1' : '0')) {
        return ['success' => false, 'message' => '保存 Token 策略失败'];
    }

    applyAntiDownloadHtaccess(!empty($policy['anti_download']));

    return ['success' => true, 'message' => '站点策略已更新'];
}

function sitePolicyVerifySign(
    string $secret,
    string $proxyEnabled,
    string $antiDownload,
    string $tokenAuto,
    int $exp,
    string $sign
): bool {
    if ($secret === '' || $sign === '' || $exp <= time()) {
        return false;
    }
    $expected = hash_hmac(
        'sha256',
        $proxyEnabled . '|' . $antiDownload . '|' . $tokenAuto . '|' . $exp,
        $secret
    );

    return hash_equals($expected, $sign);
}

function applyAntiDownloadHtaccess(bool $enabled): void
{
    $config = require __DIR__ . '/config.php';
    $uploadDir = rtrim((string)$config['upload_dir'], '/\\') . DIRECTORY_SEPARATOR;
    $dirInit = ensureUploadDirectory($uploadDir);
    $uploadDir = $dirInit['path'];
    if (!$dirInit['ok']) {
        return;
    }

    $htaccess = $uploadDir . '.htaccess';
    if ($enabled) {
        $content = <<<'HTACCESS'
# 防下载：禁止直接 HTTP 访问，仅允许 play_signed.php 读取文件
<IfModule mod_authz_core.c>
    Require all denied
</IfModule>
<IfModule !mod_authz_core.c>
    Order deny,allow
    Deny from all
</IfModule>
HTACCESS;
        file_put_contents($htaccess, $content);
    } elseif (is_file($htaccess)) {
        @unlink($htaccess);
    }
}

function isAntiDownloadEnabled(): bool
{
    $policy = getSitePolicy();

    return $policy['anti_download'] && $policy['proxy_enabled'];
}

function isDirectMediaAccessBlocked(): bool
{
    return isAntiDownloadEnabled();
}
