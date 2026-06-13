<?php

const DOMAIN_SETTING_MAIN = 'main_domain';
const DOMAIN_SETTING_VIDEO = 'video_domain';
const DOMAIN_SETTING_IMAGE = 'image_domain';
const DOMAIN_SETTING_UPLOAD = 'upload_domain';

function ensureAppSettingsTable(): void
{
    $pdo = getDb();
    if (!$pdo) {
        return;
    }

    $pdo->exec(<<<'SQL'
CREATE TABLE IF NOT EXISTS `app_settings` (
    `setting_key` VARCHAR(64) NOT NULL,
    `setting_value` VARCHAR(512) NOT NULL DEFAULT '',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
SQL
    );
}

/**
 * 规范化域名输入：去掉协议与首尾斜杠，统一为 host/path 形式
 */
function normalizeDomainInput(string $domain): string
{
    $domain = trim($domain);
    if ($domain === '') {
        return '';
    }

    $domain = preg_replace('#^https?://#i', '', $domain);
    $domain = trim($domain, '/');

    return $domain;
}

function getDomainSetting(string $key): string
{
    $pdo = getDb();
    if (!$pdo) {
        return '';
    }

    ensureAppSettingsTable();

    $stmt = $pdo->prepare('SELECT setting_value FROM app_settings WHERE setting_key = ? LIMIT 1');
    $stmt->execute([$key]);
    $row = $stmt->fetch();

    return $row ? (string)$row['setting_value'] : '';
}

function setDomainSetting(string $key, string $value): bool
{
    $pdo = getDb();
    if (!$pdo) {
        return false;
    }

    ensureAppSettingsTable();

    $stmt = $pdo->prepare(
        'INSERT INTO app_settings (setting_key, setting_value) VALUES (?, ?)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)'
    );

    return $stmt->execute([$key, $value]);
}

function getDomainSettings(): array
{
    return [
        'main_domain' => getDomainSetting(DOMAIN_SETTING_MAIN),
        'video_domain' => getDomainSetting(DOMAIN_SETTING_VIDEO),
        'image_domain' => getDomainSetting(DOMAIN_SETTING_IMAGE),
        'upload_domain' => getDomainSetting(DOMAIN_SETTING_UPLOAD),
    ];
}

function saveDomainSettings(array $settings): array
{
    $main = normalizeDomainInput($settings['main_domain'] ?? '');
    $video = normalizeDomainInput($settings['video_domain'] ?? '');
    $image = normalizeDomainInput($settings['image_domain'] ?? '');
    $upload = normalizeDomainInput($settings['upload_domain'] ?? '');

    if ($main === '') {
        return ['success' => false, 'message' => '总域名不能为空'];
    }

    if (!setDomainSetting(DOMAIN_SETTING_MAIN, $main)) {
        return ['success' => false, 'message' => '保存总域名失败'];
    }
    if (!setDomainSetting(DOMAIN_SETTING_VIDEO, $video)) {
        return ['success' => false, 'message' => '保存视频域名失败'];
    }
    if (!setDomainSetting(DOMAIN_SETTING_IMAGE, $image)) {
        return ['success' => false, 'message' => '保存图片域名失败'];
    }
    if (!setDomainSetting(DOMAIN_SETTING_UPLOAD, $upload)) {
        return ['success' => false, 'message' => '保存上传域名失败'];
    }

    return ['success' => true, 'message' => '域名配置已保存'];
}

function isMainDomainConfigured(): bool
{
    return getDomainSetting(DOMAIN_SETTING_MAIN) !== '';
}

/**
 * 解析域名为 host + 路径前缀（如 example.com/videos → host + videos）
 *
 * @return array{host: string, base_path: string}
 */
function parseDomainSetting(string $domain): array
{
    $domain = normalizeDomainInput($domain);
    if ($domain === '') {
        return ['host' => '', 'base_path' => ''];
    }

    $slashPos = strpos($domain, '/');
    if ($slashPos === false) {
        return ['host' => $domain, 'base_path' => ''];
    }

    return [
        'host' => substr($domain, 0, $slashPos),
        'base_path' => trim(substr($domain, $slashPos + 1), '/'),
    ];
}

/** 本地存储目录对应的默认 URL 路径段 */
function getDefaultResourcePathPrefix(): string
{
    return 'videos';
}

/**
 * 子域名仅填主机时，继承总域名中的路径（避免丢失 /videos 等前缀）
 */
function mergeDomainWithMainPath(string $mainDomain, string $overrideDomain): string
{
    $overrideDomain = normalizeDomainInput($overrideDomain);
    if ($overrideDomain === '') {
        return normalizeDomainInput($mainDomain);
    }

    $mainParsed = parseDomainSetting($mainDomain);
    $overrideParsed = parseDomainSetting($overrideDomain);

    if ($overrideParsed['base_path'] !== '') {
        return $overrideParsed['host'] . '/' . $overrideParsed['base_path'];
    }

    if ($mainParsed['base_path'] !== '') {
        return $overrideParsed['host'] . '/' . $mainParsed['base_path'];
    }

    return $overrideParsed['host'];
}

function resolveDomainForType(string $type): string
{
    $settings = getDomainSettings();
    $main = $settings['main_domain'];

    if ($main === '') {
        return '';
    }

    if ($type === 'video') {
        return $settings['video_domain'] !== ''
            ? mergeDomainWithMainPath($main, $settings['video_domain'])
            : $main;
    }

    if ($type === 'image') {
        return $settings['image_domain'] !== ''
            ? mergeDomainWithMainPath($main, $settings['image_domain'])
            : $main;
    }

    if ($type === 'upload') {
        return $settings['upload_domain'] !== ''
            ? mergeDomainWithMainPath($main, $settings['upload_domain'])
            : $main;
    }

    return $main;
}

function buildHttpsUrl(string $type, string $relativePath): string
{
    $domainRaw = resolveDomainForType($type);
    if ($domainRaw === '') {
        return '';
    }

    $parsed = parseDomainSetting($domainRaw);
    if ($parsed['host'] === '') {
        return '';
    }

    $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
    $basePath = $parsed['base_path'];

    // 域名未配置路径时，默认补上 videos（与本地 videos/ 目录及 CDN 目录一致）
    if ($basePath === '' && in_array($type, ['video', 'image', 'upload'], true)) {
        $basePath = getDefaultResourcePathPrefix();
    }

    $segments = array_filter([$basePath, $relativePath], static fn ($part) => $part !== '');
    $fullPath = implode('/', $segments);

    return 'https://' . $parsed['host'] . '/' . $fullPath;
}

function buildVideoAssetUrl(string $directory, string $filename): string
{
    return buildHttpsUrl('video', $directory . '/' . ltrim($filename, '/'));
}

function buildImageAssetUrl(string $directory, string $filename = 'screenshot.jpg'): string
{
    return buildHttpsUrl('image', $directory . '/' . ltrim($filename, '/'));
}

function seedDomainSettingsFromLegacyConfig(): void
{
    if (isMainDomainConfigured()) {
        return;
    }

    $configPath = __DIR__ . '/config.php';
    if (!file_exists($configPath)) {
        return;
    }

    $config = require $configPath;
    if (empty($config['base_url'])) {
        return;
    }

    $legacy = normalizeDomainInput($config['base_url']);
    if ($legacy !== '') {
        setDomainSetting(DOMAIN_SETTING_MAIN, $legacy);
    }
}
