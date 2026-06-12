<?php

require_once __DIR__ . '/settings.php';

const MAIL_SMTP_SETTING_KEY = 'mail_smtp_config';

function defaultMailSmtpConfig(): array
{
    return [
        'enabled' => false,
        'send_mode' => 'smtp',
        'api_url' => '',
        'api_key' => '',
        'host' => '',
        'port' => 587,
        'encryption' => 'tls',
        'username' => '',
        'password' => '',
        'from_email' => '',
        'from_name' => '竹叶云控平台',
    ];
}

function normalizeMailSmtpConfig(array $data): array
{
    $defaults = defaultMailSmtpConfig();
    $encryption = strtolower(trim((string)($data['encryption'] ?? $defaults['encryption'])));
    if (!in_array($encryption, ['none', 'ssl', 'tls'], true)) {
        $encryption = 'tls';
    }

    $port = (int)($data['port'] ?? $defaults['port']);
    if ($port <= 0 || $port > 65535) {
        $port = $encryption === 'ssl' ? 465 : 587;
    }

    $fromEmail = trim((string)($data['from_email'] ?? ''));
    $fromName = trim((string)($data['from_name'] ?? $defaults['from_name']));
    if ($fromName === '') {
        $fromName = $defaults['from_name'];
    }

    return [
        'enabled' => !empty($data['enabled']),
        'send_mode' => in_array(($data['send_mode'] ?? 'smtp'), ['smtp', 'api'], true) ? $data['send_mode'] : 'smtp',
        'api_url' => trim((string)($data['api_url'] ?? '')),
        'api_key' => (string)($data['api_key'] ?? ''),
        'host' => trim((string)($data['host'] ?? '')),
        'port' => $port,
        'encryption' => $encryption,
        'username' => trim((string)($data['username'] ?? '')),
        'password' => (string)($data['password'] ?? ''),
        'from_email' => $fromEmail,
        'from_name' => $fromName,
    ];
}

function getMailSmtpConfig(PDO $pdo): array
{
    $defaults = defaultMailSmtpConfig();
    $raw = getSetting($pdo, MAIL_SMTP_SETTING_KEY, '');
    if ($raw === '') {
        return $defaults;
    }

    $data = json_decode($raw, true);

    return is_array($data) ? normalizeMailSmtpConfig($data) : $defaults;
}

function saveMailSmtpConfig(PDO $pdo, array $config): void
{
    $normalized = normalizeMailSmtpConfig($config);
    setSetting(
        $pdo,
        MAIL_SMTP_SETTING_KEY,
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function parseMailSmtpConfigFromPost(array $post, ?array $existing = null): array
{
    $existing = $existing ?? defaultMailSmtpConfig();
    $password = trim((string)($post['smtp_password'] ?? ''));
    if ($password === '') {
        $password = (string)($existing['password'] ?? '');
    }
    $apiKey = trim((string)($post['api_key'] ?? $post['mail_api_key'] ?? ''));
    if ($apiKey === '') {
        $apiKey = (string)($existing['api_key'] ?? '');
    }

    return normalizeMailSmtpConfig([
        'enabled' => isset($post['smtp_enabled']),
        'send_mode' => $post['send_mode'] ?? $post['mail_send_mode'] ?? 'smtp',
        'api_url' => $post['api_url'] ?? $post['mail_api_url'] ?? '',
        'api_key' => $apiKey,
        'host' => $post['smtp_host'] ?? '',
        'port' => $post['smtp_port'] ?? 587,
        'encryption' => $post['smtp_encryption'] ?? 'tls',
        'username' => $post['smtp_username'] ?? '',
        'password' => $password,
        'from_email' => $post['smtp_from_email'] ?? '',
        'from_name' => $post['smtp_from_name'] ?? '',
    ]);
}

function mailSmtpValidationError(array $config): ?string
{
    if (empty($config['enabled'])) {
        return null;
    }

    if (($config['send_mode'] ?? 'smtp') === 'api') {
        require_once __DIR__ . '/mail_api_client.php';
        if (mailApiNormalizeBaseUrl((string)($config['api_url'] ?? '')) === '') {
            return '请填写邮局 API 地址';
        }
        if (trim((string)($config['api_key'] ?? '')) === '') {
            return '请填写邮局 API 密钥';
        }
        return null;
    }

    if ($config['host'] === '') {
        return '请填写 SMTP 服务器地址';
    }
    if ($config['username'] === '') {
        return '请填写 SMTP 账号';
    }
    if ($config['password'] === '') {
        return '请填写 SMTP 密码';
    }
    if ($config['from_email'] === '' || !filter_var($config['from_email'], FILTER_VALIDATE_EMAIL)) {
        return '请填写有效的发件人邮箱';
    }

    return null;
}

function isMailConfigured(PDO $pdo): bool
{
    $cfg = getMailSmtpConfig($pdo);

    if (empty($cfg['enabled'])) {
        return false;
    }

    if (($cfg['send_mode'] ?? 'smtp') === 'api') {
        require_once __DIR__ . '/mail_api_client.php';

        return isMailApiConfigured($cfg);
    }

    return $cfg['host'] !== ''
        && $cfg['username'] !== ''
        && $cfg['password'] !== ''
        && $cfg['from_email'] !== ''
        && filter_var($cfg['from_email'], FILTER_VALIDATE_EMAIL);
}
