<?php

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/mail_sender.php';
require_once __DIR__ . '/ban_notice.php';

const ACCOUNT_ACTIVATION_SETTING_KEY = 'mail_account_activation_config';

const ACTIVATION_BAN_TYPE_EMAIL = 'email_issue';
const ACTIVATION_BAN_TYPE_TEMP_EMAIL = 'temp_email_issue';

function accountActivationBanTypes(): array
{
    return [
        ACTIVATION_BAN_TYPE_EMAIL => '邮箱问题（疑似假邮箱）',
        ACTIVATION_BAN_TYPE_TEMP_EMAIL => '临时垃圾邮箱问题',
    ];
}

function accountActivationBanTypeLabel(string $banType): string
{
    $types = accountActivationBanTypes();

    return $types[$banType] ?? $banType;
}

function defaultAccountActivationHtmlTemplate(): string
{
    return <<<'HTML'
<div style="max-width:520px;margin:0 auto;padding:24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111827;line-height:1.6;">
  <h2 style="margin:0 0 16px;font-size:20px;">{{site_name}} 账号激活通知</h2>
  <p style="margin:0 0 12px;">尊敬的 {{username}}（{{email}}）：</p>
  <p style="margin:0 0 12px;">您的账号因「{{ban_type_label}}」需要完成激活验证，请点击下方按钮继续（链接 {{expire_minutes}} 分钟内有效）：</p>
  <p style="margin:0 0 20px;">
    <a href="{{activation_link}}" style="display:inline-block;padding:12px 24px;background:#dc2626;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;">前往激活</a>
  </p>
  <p style="margin:0 0 8px;font-size:13px;color:#6b7280;">若按钮无法点击，请复制以下链接到浏览器：</p>
  <p style="margin:0;font-size:12px;color:#9ca3af;word-break:break-all;">{{activation_link}}</p>
</div>
HTML;
}

function defaultAccountActivationConfirmTemplate(): string
{
    return <<<'HTML'
<div style="max-width:520px;margin:0 auto;padding:24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111827;line-height:1.6;">
  <h2 style="margin:0 0 16px;font-size:20px;">{{site_name}} 账号激活确认</h2>
  <p style="margin:0 0 12px;">尊敬的 {{username}}：</p>
  <p style="margin:0 0 16px;">您已提交邮箱 {{email}}，请点击下方按钮完成账号激活（链接 {{expire_minutes}} 分钟内有效）：</p>
  <p style="margin:0 0 20px;">
    <a href="{{activation_link}}" style="display:inline-block;padding:12px 24px;background:#16a34a;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;">确认激活</a>
  </p>
  <p style="margin:0 0 8px;font-size:13px;color:#6b7280;">若按钮无法点击，请复制以下链接到浏览器：</p>
  <p style="margin:0;font-size:12px;color:#9ca3af;word-break:break-all;">{{activation_link}}</p>
</div>
HTML;
}

function defaultAccountActivationConfig(): array
{
    return [
        'default_ban_type' => ACTIVATION_BAN_TYPE_EMAIL,
        'link_expire' => 86400,
        'subject' => '【竹叶云控】账号激活通知',
        'html_template' => defaultAccountActivationHtmlTemplate(),
        'confirm_subject' => '【竹叶云控】账号激活确认',
        'confirm_html_template' => defaultAccountActivationConfirmTemplate(),
    ];
}

function normalizeAccountActivationConfig(array $data): array
{
    $defaults = defaultAccountActivationConfig();
    $types = array_keys(accountActivationBanTypes());
    $banType = (string)($data['default_ban_type'] ?? $defaults['default_ban_type']);
    if (!in_array($banType, $types, true)) {
        $banType = ACTIVATION_BAN_TYPE_EMAIL;
    }

    $expire = (int)($data['link_expire'] ?? $defaults['link_expire']);
    $template = trim((string)($data['html_template'] ?? $defaults['html_template']));
    if ($template === '') {
        $template = $defaults['html_template'];
    }

    $confirmTemplate = trim((string)($data['confirm_html_template'] ?? $defaults['confirm_html_template']));
    if ($confirmTemplate === '') {
        $confirmTemplate = $defaults['confirm_html_template'];
    }

    $subject = trim((string)($data['subject'] ?? $defaults['subject']));
    if ($subject === '') {
        $subject = $defaults['subject'];
    }

    $confirmSubject = trim((string)($data['confirm_subject'] ?? $defaults['confirm_subject']));
    if ($confirmSubject === '') {
        $confirmSubject = $defaults['confirm_subject'];
    }

    return [
        'default_ban_type' => $banType,
        'link_expire' => max(300, min(604800, $expire > 0 ? $expire : 86400)),
        'subject' => $subject,
        'html_template' => $template,
        'confirm_subject' => $confirmSubject,
        'confirm_html_template' => $confirmTemplate,
    ];
}

function getAccountActivationConfig(PDO $pdo): array
{
    $raw = getSetting($pdo, ACCOUNT_ACTIVATION_SETTING_KEY, '');
    if ($raw === '') {
        return defaultAccountActivationConfig();
    }

    $data = json_decode($raw, true);

    return is_array($data) ? normalizeAccountActivationConfig($data) : defaultAccountActivationConfig();
}

function saveAccountActivationConfig(PDO $pdo, array $config): void
{
    $normalized = normalizeAccountActivationConfig($config);
    setSetting(
        $pdo,
        ACCOUNT_ACTIVATION_SETTING_KEY,
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function parseAccountActivationConfigFromPost(array $post): array
{
    return normalizeAccountActivationConfig([
        'default_ban_type' => $post['activation_default_ban_type'] ?? ACTIVATION_BAN_TYPE_EMAIL,
        'link_expire' => $post['activation_link_expire'] ?? 86400,
        'subject' => $post['activation_subject'] ?? '',
        'html_template' => $post['activation_template'] ?? '',
        'confirm_subject' => $post['activation_confirm_subject'] ?? '',
        'confirm_html_template' => $post['activation_confirm_template'] ?? '',
    ]);
}

function accountActivationConfigValidationError(array $config): ?string
{
    if (strpos($config['html_template'], '{{activation_link}}') === false) {
        return '通知邮件模板中必须包含 {{activation_link}} 占位符';
    }
    if (strpos($config['confirm_html_template'], '{{activation_link}}') === false) {
        return '确认邮件模板中必须包含 {{activation_link}} 占位符';
    }
    if ($config['subject'] === '') {
        return '请填写通知邮件主题';
    }
    if ($config['confirm_subject'] === '') {
        return '请填写确认邮件主题';
    }

    return null;
}

function ensureAccountActivationTokensTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `account_activation_tokens` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `ban_type` varchar(32) NOT NULL,
        `stage` enum('verify','activate') NOT NULL DEFAULT 'verify',
        `token_hash` char(64) NOT NULL,
        `target_email` varchar(100) DEFAULT NULL,
        `expires_at` datetime NOT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `used_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_token_hash` (`token_hash`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_stage` (`stage`),
        KEY `idx_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ready = true;
}

function accountActivationSiteBaseUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $root = rtrim($scriptDir, '/');
    if (substr($root, -6) === '/admin') {
        $root = substr($root, 0, -6);
    }
    if (substr($root, -4) === '/api') {
        $root = substr($root, 0, -4);
    }

    return rtrim($scheme . '://' . $host . $root, '/');
}

function accountActivationBuildLink(string $token): string
{
    return accountActivationSiteBaseUrl() . '/activate_account.php?token=' . urlencode($token);
}

function accountActivationTokenHash(string $token): string
{
    return hash('sha256', $token);
}

function renderAccountActivationEmailHtml(
    PDO $pdo,
    array $config,
    array $user,
    string $activationLink,
    string $banType,
    bool $isConfirm = false
): string {
    $smtp = getMailSmtpConfig($pdo);
    $siteName = trim((string)($smtp['from_name'] ?? '竹叶云控平台'));
    $expireMinutes = max(1, (int)ceil(((int)$config['link_expire']) / 60));
    $template = $isConfirm ? $config['confirm_html_template'] : $config['html_template'];

    $replacements = [
        '{{activation_link}}' => htmlspecialchars($activationLink, ENT_QUOTES, 'UTF-8'),
        '{{username}}' => htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{email}}' => htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{expire_minutes}}' => (string)$expireMinutes,
        '{{site_name}}' => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
        '{{ban_type_label}}' => htmlspecialchars(accountActivationBanTypeLabel($banType), ENT_QUOTES, 'UTF-8'),
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $template);
}

function userHasPendingAccountActivation(PDO $pdo, int $userId): bool
{
    ensureAccountActivationTokensTable($pdo);

    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM account_activation_tokens
        WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()
    ");
    $stmt->execute([$userId]);

    return (int)$stmt->fetchColumn() > 0;
}

function accountActivationLoginMessage(): string
{
    return '当前账号暂时封禁';
}

function accountActivationPageSizeOptions(): array
{
    return [10, 20, 30, 40, 50];
}

function normalizeAccountActivationPageSize(int $size): int
{
    return in_array($size, accountActivationPageSizeOptions(), true) ? $size : 10;
}

function normalizeAccountActivationPage(int $page): int
{
    return max(1, $page);
}

function accountActivationListUrl(int $page = 1, int $perPage = 10): string
{
    $query = http_build_query([
        'section' => 'account_activation',
        'activation_page' => normalizeAccountActivationPage($page),
        'activation_per_page' => normalizeAccountActivationPageSize($perPage),
    ]);

    return 'mail.php?' . $query;
}

function countAccountActivationCandidates(PDO $pdo): int
{
    $stmt = $pdo->query("SELECT COUNT(*) FROM users WHERE username <> 'admin'");

    return (int)$stmt->fetchColumn();
}

function listAccountActivationCandidates(PDO $pdo, int $page = 1, int $perPage = 10): array
{
    $page = normalizeAccountActivationPage($page);
    $perPage = normalizeAccountActivationPageSize($perPage);
    $offset = ($page - 1) * $perPage;

    $stmt = $pdo->prepare("
        SELECT u.id, u.username, u.email, u.status, u.created_at, g.name AS group_name
        FROM users u
        LEFT JOIN user_groups g ON g.id = u.group_id
        WHERE u.username <> 'admin'
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?
    ");
    $stmt->bindValue(1, $perPage, PDO::PARAM_INT);
    $stmt->bindValue(2, $offset, PDO::PARAM_INT);
    $stmt->execute();

    return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
}

/**
 * @return array{ok:bool,message?:string,sent?:int,failed?:int,errors?:array}
 */
function sendAccountActivationToUsers(PDO $pdo, array $userIds, string $banType): array
{
    if (!isMailConfigured($pdo)) {
        return ['ok' => false, 'message' => '请先在「邮局配置」中完成 SMTP 设置'];
    }

    $types = array_keys(accountActivationBanTypes());
    if (!in_array($banType, $types, true)) {
        return ['ok' => false, 'message' => '无效的封禁类型'];
    }

    $config = getAccountActivationConfig($pdo);
    if ($error = accountActivationConfigValidationError($config)) {
        return ['ok' => false, 'message' => $error];
    }

    $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
    if ($userIds === []) {
        return ['ok' => false, 'message' => '请至少选择一个用户'];
    }

    ensureAccountActivationTokensTable($pdo);

    $sent = 0;
    $failed = 0;
    $errors = [];

    foreach ($userIds as $userId) {
        $stmt = $pdo->prepare('SELECT id, username, email, status FROM users WHERE id = ? AND username <> ? LIMIT 1');
        $stmt->execute([$userId, 'admin']);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);

        if (!$user) {
            $failed++;
            $errors[] = "用户 ID {$userId} 不存在";
            continue;
        }

        $result = createAccountActivationRequest($pdo, $user, $banType, $config);
        if (!empty($result['ok'])) {
            $sent++;
        } else {
            $failed++;
            $errors[] = ($user['username'] ?? (string)$userId) . '：' . ($result['message'] ?? '发送失败');
        }
    }

    if ($sent === 0) {
        return [
            'ok' => false,
            'message' => '未能成功发送任何激活通知',
            'sent' => 0,
            'failed' => $failed,
            'errors' => $errors,
        ];
    }

    $msg = "已成功向 {$sent} 个用户发送激活通知";
    if ($failed > 0) {
        $msg .= "，{$failed} 个失败";
    }

    return ['ok' => true, 'message' => $msg, 'sent' => $sent, 'failed' => $failed, 'errors' => $errors];
}

/**
 * @return array{ok:bool,message?:string}
 */
function createAccountActivationRequest(PDO $pdo, array $user, string $banType, ?array $config = null): array
{
    $config = $config ?? getAccountActivationConfig($pdo);
    $userId = (int)$user['id'];
    $email = strtolower(trim((string)($user['email'] ?? '')));

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '用户邮箱无效'];
    }

    $token = bin2hex(random_bytes(32));
    $tokenHash = accountActivationTokenHash($token);
    $expiresAt = date('Y-m-d H:i:s', time() + (int)$config['link_expire']);

    $pdo->prepare("
        UPDATE account_activation_tokens
        SET used_at = NOW()
        WHERE user_id = ? AND used_at IS NULL
    ")->execute([$userId]);

    $insert = $pdo->prepare("
        INSERT INTO account_activation_tokens (user_id, ban_type, stage, token_hash, expires_at)
        VALUES (?, ?, 'verify', ?, ?)
    ");
    $insert->execute([$userId, $banType, $tokenHash, $expiresAt]);

    $pdo->prepare("UPDATE users SET status = 'banned', ban_until = NULL WHERE id = ?")
        ->execute([$userId]);

    trySendBanNotice(
        $pdo,
        $user,
        BAN_NOTICE_ACTION_ACTIVATION,
        null,
        accountActivationBanTypeLabel($banType)
    );

    $link = accountActivationBuildLink($token);
    $html = renderAccountActivationEmailHtml($pdo, $config, $user, $link, $banType, false);
    $send = sendSiteMail($pdo, $email, $config['subject'], $html, true);

    if (empty($send['ok'])) {
        return ['ok' => false, 'message' => $send['message'] ?? '邮件发送失败'];
    }

    return ['ok' => true, 'message' => '激活通知已发送'];
}

/**
 * @return array{ok:bool,message?:string,token?:array}
 */
function validateAccountActivationToken(PDO $pdo, string $token): array
{
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return ['ok' => false, 'message' => '激活链接无效'];
    }

    ensureAccountActivationTokensTable($pdo);

    $hash = accountActivationTokenHash($token);
    $stmt = $pdo->prepare("
        SELECT t.*, u.username, u.email AS user_email, u.status
        FROM account_activation_tokens t
        INNER JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.used_at IS NULL
        LIMIT 1
    ");
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'message' => '激活链接无效或已使用'];
    }

    if (strtotime((string)$row['expires_at']) < time()) {
        return ['ok' => false, 'message' => '激活链接已过期，请联系管理员重新发送'];
    }

    return [
        'ok' => true,
        'token' => $row,
        'user' => [
            'id' => (int)$row['user_id'],
            'username' => (string)$row['username'],
            'email' => (string)$row['user_email'],
        ],
    ];
}

function accountActivationVerifyPageMessage(string $banType): string
{
    if ($banType === ACTIVATION_BAN_TYPE_TEMP_EMAIL) {
        return '您使用的是临时邮箱，请填写真实有效的邮箱地址以完成账号激活。';
    }

    return '您的注册邮箱可能存在异常，请填写真实有效的邮箱地址以完成账号激活。';
}

/**
 * @return array{ok:bool,message?:string}
 */
function submitAccountActivationEmail(PDO $pdo, string $token, string $newEmail): array
{
    $newEmail = strtolower(trim($newEmail));
    if ($newEmail === '' || !filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '请填写有效的邮箱地址'];
    }

    $check = validateAccountActivationToken($pdo, $token);
    if (empty($check['ok'])) {
        return ['ok' => false, 'message' => $check['message'] ?? '激活链接无效'];
    }

    $row = $check['token'];
    if (($row['stage'] ?? '') !== 'verify') {
        return ['ok' => false, 'message' => '此链接不可用于提交邮箱'];
    }

    $config = getAccountActivationConfig($pdo);
    $userId = (int)$row['user_id'];
    $banType = (string)$row['ban_type'];

    $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    $dup->execute([$newEmail, $userId]);
    if ($dup->fetch()) {
        return ['ok' => false, 'message' => '该邮箱已被其他账号使用'];
    }

    $pdo->prepare('UPDATE account_activation_tokens SET used_at = NOW() WHERE id = ?')
        ->execute([(int)$row['id']]);

    $activateToken = bin2hex(random_bytes(32));
    $activateHash = accountActivationTokenHash($activateToken);
    $expiresAt = date('Y-m-d H:i:s', time() + (int)$config['link_expire']);

    $insert = $pdo->prepare("
        INSERT INTO account_activation_tokens (user_id, ban_type, stage, token_hash, target_email, expires_at)
        VALUES (?, ?, 'activate', ?, ?, ?)
    ");
    $insert->execute([$userId, $banType, $activateHash, $newEmail, $expiresAt]);

    $user = $check['user'];
    $user['email'] = $newEmail;
    $link = accountActivationBuildLink($activateToken);
    $html = renderAccountActivationEmailHtml($pdo, $config, $user, $link, $banType, true);
    $send = sendSiteMail($pdo, $newEmail, $config['confirm_subject'], $html, true);

    if (empty($send['ok'])) {
        return ['ok' => false, 'message' => $send['message'] ?? '确认邮件发送失败'];
    }

    return ['ok' => true, 'message' => '激活确认邮件已发送至 ' . $newEmail . '，请查收并点击链接完成激活'];
}

/**
 * @return array{ok:bool,message?:string}
 */
function completeAccountActivation(PDO $pdo, string $token): array
{
    $check = validateAccountActivationToken($pdo, $token);
    if (empty($check['ok'])) {
        return ['ok' => false, 'message' => $check['message'] ?? '激活链接无效'];
    }

    $row = $check['token'];
    if (($row['stage'] ?? '') !== 'activate') {
        return ['ok' => false, 'message' => '请先填写邮箱获取确认链接'];
    }

    $userId = (int)$row['user_id'];
    $newEmail = strtolower(trim((string)($row['target_email'] ?? '')));

    if ($newEmail !== '') {
        $dup = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
        $dup->execute([$newEmail, $userId]);
        if ($dup->fetch()) {
            return ['ok' => false, 'message' => '该邮箱已被其他账号使用'];
        }
    }

    $pdo->prepare('UPDATE account_activation_tokens SET used_at = NOW() WHERE id = ?')
        ->execute([(int)$row['id']]);

    $pdo->prepare("
        UPDATE account_activation_tokens SET used_at = NOW()
        WHERE user_id = ? AND used_at IS NULL
    ")->execute([$userId]);

    if ($newEmail !== '') {
        $pdo->prepare('UPDATE users SET email = ?, status = ?, ban_until = NULL WHERE id = ?')
            ->execute([$newEmail, 'active', $userId]);
    } else {
        $pdo->prepare("UPDATE users SET status = 'active', ban_until = NULL WHERE id = ?")
            ->execute([$userId]);
    }

    return ['ok' => true, 'message' => '账号已激活，请使用新邮箱登录'];
}

/**
 * @return array{ok:bool,message?:string}
 */
function sendAccountActivationTest(PDO $pdo, string $email, string $banType): array
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '请填写有效的测试邮箱'];
    }

    if (!isMailConfigured($pdo)) {
        return ['ok' => false, 'message' => '请先在「邮局配置」中完成 SMTP 设置'];
    }

    $types = array_keys(accountActivationBanTypes());
    if (!in_array($banType, $types, true)) {
        $banType = ACTIVATION_BAN_TYPE_EMAIL;
    }

    $config = getAccountActivationConfig($pdo);
    if ($error = accountActivationConfigValidationError($config)) {
        return ['ok' => false, 'message' => $error];
    }

    $user = ['username' => '测试用户', 'email' => $email];
    $link = accountActivationBuildLink('test_' . bin2hex(random_bytes(8)));
    $html = renderAccountActivationEmailHtml($pdo, $config, $user, $link, $banType, false);
    $result = sendSiteMail($pdo, $email, $config['subject'], $html, true);

    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => $result['message'] ?? '测试发送失败'];
    }

    return ['ok' => true, 'message' => '测试激活通知已发送至 ' . $email];
}
