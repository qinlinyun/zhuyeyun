<?php

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mail_config.php';

const REGISTER_VERIFY_SETTING_KEY = 'mail_register_verify_config';

function defaultRegisterVerifyHtmlTemplate(): string
{
    return <<<'HTML'
<div style="max-width:520px;margin:0 auto;padding:24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111827;">
  <h2 style="margin:0 0 16px;font-size:20px;">{{site_name}} 注册验证码</h2>
  <p style="margin:0 0 12px;line-height:1.6;">您正在注册账号，邮箱：<strong>{{email}}</strong></p>
  <p style="margin:0 0 16px;line-height:1.6;">您的验证码为：</p>
  <div style="display:inline-block;padding:12px 24px;background:#f3f4f6;border-radius:8px;font-size:28px;font-weight:700;letter-spacing:6px;color:#dc2626;">{{code}}</div>
  <p style="margin:16px 0 0;line-height:1.6;font-size:14px;color:#6b7280;">验证码 {{expire_minutes}} 分钟内有效，请勿泄露给他人。</p>
  <p style="margin:12px 0 0;line-height:1.6;font-size:13px;color:#9ca3af;">如非本人操作，请忽略此邮件。</p>
</div>
HTML;
}

function defaultRegisterVerifyConfig(): array
{
    return [
        'enabled' => false,
        'resend_interval' => 60,
        'code_expire' => 600,
        'subject' => '【竹叶云控】注册验证码',
        'html_template' => defaultRegisterVerifyHtmlTemplate(),
    ];
}

function normalizeRegisterVerifyConfig(array $data): array
{
    $defaults = defaultRegisterVerifyConfig();

    $resend = (int)($data['resend_interval'] ?? $defaults['resend_interval']);
    $expire = (int)($data['code_expire'] ?? $defaults['code_expire']);

    $template = trim((string)($data['html_template'] ?? $defaults['html_template']));
    if ($template === '') {
        $template = $defaults['html_template'];
    }

    $subject = trim((string)($data['subject'] ?? $defaults['subject']));
    if ($subject === '') {
        $subject = $defaults['subject'];
    }

    return [
        'enabled' => !empty($data['enabled']),
        'resend_interval' => max(30, min(3600, $resend > 0 ? $resend : 60)),
        'code_expire' => max(60, min(86400, $expire > 0 ? $expire : 600)),
        'subject' => $subject,
        'html_template' => $template,
    ];
}

function getRegisterVerifyConfig(PDO $pdo): array
{
    $raw = getSetting($pdo, REGISTER_VERIFY_SETTING_KEY, '');
    if ($raw === '') {
        return defaultRegisterVerifyConfig();
    }

    $data = json_decode($raw, true);

    return is_array($data) ? normalizeRegisterVerifyConfig($data) : defaultRegisterVerifyConfig();
}

function saveRegisterVerifyConfig(PDO $pdo, array $config): void
{
    $normalized = normalizeRegisterVerifyConfig($config);
    setSetting(
        $pdo,
        REGISTER_VERIFY_SETTING_KEY,
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function parseRegisterVerifyConfigFromPost(array $post): array
{
    return normalizeRegisterVerifyConfig([
        'enabled' => isset($post['register_verify_enabled']),
        'resend_interval' => $post['resend_interval'] ?? 60,
        'code_expire' => $post['code_expire'] ?? 600,
        'subject' => $post['register_verify_subject'] ?? '',
        'html_template' => $post['register_verify_template'] ?? '',
    ]);
}

function registerVerifyValidationError(array $config): ?string
{
    if (empty($config['enabled'])) {
        return null;
    }

    if (strpos($config['html_template'], '{{code}}') === false) {
        return '邮件模板中必须包含 {{code}} 占位符';
    }

    if ($config['subject'] === '') {
        return '请填写邮件主题';
    }

    return null;
}

function isRegisterVerifyEnabled(PDO $pdo): bool
{
    $cfg = getRegisterVerifyConfig($pdo);

    return !empty($cfg['enabled']);
}

function registerVerifyRequiresMail(PDO $pdo): bool
{
    return isRegisterVerifyEnabled($pdo) && !isMailConfigured($pdo);
}

function renderRegisterVerifyEmailHtml(PDO $pdo, array $config, string $email, string $code): string
{
    $smtp = getMailSmtpConfig($pdo);
    $siteName = trim((string)($smtp['from_name'] ?? '竹叶云控平台'));
    $expireMinutes = max(1, (int)ceil(((int)$config['code_expire']) / 60));

    $replacements = [
        '{{code}}' => htmlspecialchars($code, ENT_QUOTES, 'UTF-8'),
        '{{email}}' => htmlspecialchars($email, ENT_QUOTES, 'UTF-8'),
        '{{expire_minutes}}' => (string)$expireMinutes,
        '{{site_name}}' => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $config['html_template']);
}
