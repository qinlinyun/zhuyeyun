<?php

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/mail_sender.php';

const BAN_NOTICE_SETTING_KEY = 'mail_ban_notice_config';

const BAN_NOTICE_ACTION_BAN = 'ban';
const BAN_NOTICE_ACTION_BAN_TIMED = 'ban_timed';
const BAN_NOTICE_ACTION_FREEZE = 'freeze';
const BAN_NOTICE_ACTION_DELETE = 'delete';
const BAN_NOTICE_ACTION_ACTIVATION = 'activation';

function banNoticeActionLabels(): array
{
    return [
        BAN_NOTICE_ACTION_BAN => '账号封禁',
        BAN_NOTICE_ACTION_BAN_TIMED => '定时封禁',
        BAN_NOTICE_ACTION_FREEZE => '账号冻结',
        BAN_NOTICE_ACTION_DELETE => '账号删除',
        BAN_NOTICE_ACTION_ACTIVATION => '账号激活封禁',
    ];
}

function banNoticeActionLabel(string $action): string
{
    $labels = banNoticeActionLabels();

    return $labels[$action] ?? $action;
}

function defaultBanNoticeHtmlTemplate(): string
{
    return <<<'HTML'
<div style="max-width:520px;margin:0 auto;padding:24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111827;line-height:1.6;">
  <h2 style="margin:0 0 16px;font-size:20px;">{{site_name}} 账号状态通知</h2>
  <p style="margin:0 0 12px;">尊敬的 {{username}}（{{email}}）：</p>
  <p style="margin:0 0 12px;">您的账号已被执行以下操作：<strong>{{action_label}}</strong></p>
  <p style="margin:0 0 8px;font-size:14px;color:#374151;">{{action_detail}}</p>
  <p style="margin:16px 0 0;font-size:13px;color:#9ca3af;">如有疑问，请联系站点管理员。</p>
</div>
HTML;
}

function defaultBanNoticeConfig(): array
{
    return [
        'enabled' => false,
        'subject' => '【竹叶云控】账号状态变更通知',
        'html_template' => defaultBanNoticeHtmlTemplate(),
    ];
}

function normalizeBanNoticeConfig(array $data): array
{
    $defaults = defaultBanNoticeConfig();
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
        'subject' => $subject,
        'html_template' => $template,
    ];
}

function getBanNoticeConfig(PDO $pdo): array
{
    $raw = getSetting($pdo, BAN_NOTICE_SETTING_KEY, '');
    if ($raw === '') {
        return defaultBanNoticeConfig();
    }

    $data = json_decode($raw, true);

    return is_array($data) ? normalizeBanNoticeConfig($data) : defaultBanNoticeConfig();
}

function saveBanNoticeConfig(PDO $pdo, array $config): void
{
    $normalized = normalizeBanNoticeConfig($config);
    setSetting(
        $pdo,
        BAN_NOTICE_SETTING_KEY,
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function parseBanNoticeConfigFromPost(array $post): array
{
    return normalizeBanNoticeConfig([
        'enabled' => isset($post['ban_notice_enabled']),
        'subject' => $post['ban_notice_subject'] ?? '',
        'html_template' => $post['ban_notice_template'] ?? '',
    ]);
}

function banNoticeConfigValidationError(array $config): ?string
{
    if (empty($config['enabled'])) {
        return null;
    }
    if ($config['subject'] === '') {
        return '请填写邮件主题';
    }
    if (strpos($config['html_template'], '{{action_label}}') === false) {
        return '邮件模板中必须包含 {{action_label}} 占位符';
    }

    return null;
}

function isBanNoticeEnabled(PDO $pdo): bool
{
    $cfg = getBanNoticeConfig($pdo);

    return !empty($cfg['enabled']) && isMailConfigured($pdo);
}

function banNoticeActionDetail(string $action, ?string $banUntil = null, ?string $extra = null): string
{
    switch ($action) {
        case BAN_NOTICE_ACTION_BAN:
            return '您的账号已被永久封禁，暂时无法登录使用。';
        case BAN_NOTICE_ACTION_BAN_TIMED:
            $until = $banUntil ? htmlspecialchars($banUntil, ENT_QUOTES, 'UTF-8') : '指定时间';
            return '您的账号已被定时封禁，解封时间：' . $until . '。';
        case BAN_NOTICE_ACTION_FREEZE:
            return '您的账号已被冻结，暂时无法登录使用。';
        case BAN_NOTICE_ACTION_DELETE:
            return '您的账号已被管理员删除，将无法再登录本平台。';
        case BAN_NOTICE_ACTION_ACTIVATION:
            $detail = '您的账号因需完成激活验证已被暂时封禁，请查收激活通知邮件并按指引操作。';
            if ($extra !== null && $extra !== '') {
                $detail .= '（' . htmlspecialchars($extra, ENT_QUOTES, 'UTF-8') . '）';
            }
            return $detail;
        default:
            return $extra ?? '';
    }
}

function renderBanNoticeEmailHtml(
    PDO $pdo,
    array $config,
    array $user,
    string $action,
    ?string $banUntil = null,
    ?string $extra = null
): string {
    $smtp = getMailSmtpConfig($pdo);
    $siteName = trim((string)($smtp['from_name'] ?? '竹叶云控平台'));
    $actionLabel = banNoticeActionLabel($action);
    $actionDetail = banNoticeActionDetail($action, $banUntil, $extra);

    $replacements = [
        '{{username}}' => htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{email}}' => htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{action_label}}' => htmlspecialchars($actionLabel, ENT_QUOTES, 'UTF-8'),
        '{{action_detail}}' => $actionDetail,
        '{{ban_until}}' => htmlspecialchars((string)($banUntil ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{site_name}}' => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $config['html_template']);
}

/**
 * @return array{ok:bool,message?:string}
 */
function sendBanNotice(
    PDO $pdo,
    array $user,
    string $action,
    ?string $banUntil = null,
    ?string $extra = null
): array {
    if (!isBanNoticeEnabled($pdo)) {
        return ['ok' => false, 'message' => '封禁通知未启用'];
    }

    $email = strtolower(trim((string)($user['email'] ?? '')));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '用户邮箱无效'];
    }

    if (($user['username'] ?? '') === 'admin') {
        return ['ok' => false, 'message' => '跳过管理员'];
    }

    $labels = banNoticeActionLabels();
    if (!isset($labels[$action])) {
        return ['ok' => false, 'message' => '无效的操作类型'];
    }

    $config = getBanNoticeConfig($pdo);
    if ($error = banNoticeConfigValidationError($config)) {
        return ['ok' => false, 'message' => $error];
    }

    $html = renderBanNoticeEmailHtml($pdo, $config, $user, $action, $banUntil, $extra);
    $result = sendSiteMail($pdo, $email, $config['subject'], $html, true);

    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => $result['message'] ?? '发送失败'];
    }

    return ['ok' => true, 'message' => '封禁通知已发送'];
}

/**
 * 静默发送，不影响主流程。
 */
function trySendBanNotice(
    PDO $pdo,
    array $user,
    string $action,
    ?string $banUntil = null,
    ?string $extra = null
): void {
    sendBanNotice($pdo, $user, $action, $banUntil, $extra);
}

/**
 * @return array{ok:bool,message?:string}
 */
function sendBanNoticeTest(PDO $pdo, string $email, string $action): array
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '请填写有效的测试邮箱'];
    }

    if (!isMailConfigured($pdo)) {
        return ['ok' => false, 'message' => '请先在「邮局配置」中完成 SMTP 设置'];
    }

    $config = getBanNoticeConfig($pdo);
    if ($error = banNoticeConfigValidationError($config)) {
        return ['ok' => false, 'message' => $error];
    }

    $labels = banNoticeActionLabels();
    if (!isset($labels[$action])) {
        $action = BAN_NOTICE_ACTION_BAN;
    }

    $user = ['username' => '测试用户', 'email' => $email];
    $html = renderBanNoticeEmailHtml($pdo, $config, $user, $action, '2099-01-01 00:00:00', '测试说明');
    $result = sendSiteMail($pdo, $email, $config['subject'], $html, true);

    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => $result['message'] ?? '测试发送失败'];
    }

    return ['ok' => true, 'message' => '测试封禁通知已发送至 ' . $email];
}

function fetchUserForBanNotice(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE id = ? AND username <> ? LIMIT 1');
    $stmt->execute([$userId, 'admin']);

    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    return $user ?: null;
}
