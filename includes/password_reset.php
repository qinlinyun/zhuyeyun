<?php

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/mail_config.php';
require_once __DIR__ . '/mail_sender.php';

const PASSWORD_RESET_SETTING_KEY = 'mail_password_reset_config';

function defaultPasswordResetHtmlTemplate(): string
{
    return <<<'HTML'
<div style="max-width:520px;margin:0 auto;padding:24px;font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,sans-serif;color:#111827;line-height:1.6;">
  <h2 style="margin:0 0 16px;font-size:20px;">{{site_name}} 密码重置</h2>
  <p style="margin:0 0 12px;">尊敬的 {{username}}（{{email}}）：</p>
  <p style="margin:0 0 16px;">您申请了重置密码，请点击下方按钮完成操作（链接 {{expire_minutes}} 分钟内有效）：</p>
  <p style="margin:0 0 20px;">
    <a href="{{reset_link}}" style="display:inline-block;padding:12px 24px;background:#dc2626;color:#ffffff;text-decoration:none;border-radius:8px;font-weight:600;">重置密码</a>
  </p>
  <p style="margin:0 0 8px;font-size:13px;color:#6b7280;">若按钮无法点击，请复制以下链接到浏览器：</p>
  <p style="margin:0;font-size:12px;color:#9ca3af;word-break:break-all;">{{reset_link}}</p>
  <p style="margin:16px 0 0;font-size:13px;color:#9ca3af;">如非本人操作，请忽略此邮件。</p>
</div>
HTML;
}

function defaultPasswordResetConfig(): array
{
    return [
        'enabled' => false,
        'link_expire' => 3600,
        'subject' => '【竹叶云控】密码重置',
        'html_template' => defaultPasswordResetHtmlTemplate(),
    ];
}

function normalizePasswordResetConfig(array $data): array
{
    $defaults = defaultPasswordResetConfig();
    $expire = (int)($data['link_expire'] ?? $defaults['link_expire']);

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
        'link_expire' => max(300, min(86400, $expire > 0 ? $expire : 3600)),
        'subject' => $subject,
        'html_template' => $template,
    ];
}

function getPasswordResetConfig(PDO $pdo): array
{
    $raw = getSetting($pdo, PASSWORD_RESET_SETTING_KEY, '');
    if ($raw === '') {
        return defaultPasswordResetConfig();
    }

    $data = json_decode($raw, true);

    return is_array($data) ? normalizePasswordResetConfig($data) : defaultPasswordResetConfig();
}

function savePasswordResetConfig(PDO $pdo, array $config): void
{
    $normalized = normalizePasswordResetConfig($config);
    setSetting(
        $pdo,
        PASSWORD_RESET_SETTING_KEY,
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function parsePasswordResetConfigFromPost(array $post): array
{
    return normalizePasswordResetConfig([
        'enabled' => isset($post['password_reset_enabled']),
        'link_expire' => $post['password_reset_link_expire'] ?? 3600,
        'subject' => $post['password_reset_subject'] ?? '',
        'html_template' => $post['password_reset_template'] ?? '',
    ]);
}

function passwordResetConfigValidationError(array $config): ?string
{
    if (empty($config['enabled'])) {
        return null;
    }

    if (strpos($config['html_template'], '{{reset_link}}') === false) {
        return '邮件模板中必须包含 {{reset_link}} 占位符';
    }
    if ($config['subject'] === '') {
        return '请填写邮件主题';
    }

    return null;
}

function isPasswordResetEnabled(PDO $pdo): bool
{
    $cfg = getPasswordResetConfig($pdo);

    return !empty($cfg['enabled']);
}

function passwordResetAvailable(PDO $pdo): bool
{
    return isPasswordResetEnabled($pdo) && isMailConfigured($pdo);
}

function ensurePasswordResetTokensTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `password_reset_tokens` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `email` varchar(100) NOT NULL,
        `token_hash` char(64) NOT NULL,
        `expires_at` datetime NOT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `used_at` datetime DEFAULT NULL,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_token_hash` (`token_hash`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_email` (`email`),
        KEY `idx_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ready = true;
}

function passwordResetSiteBaseUrl(): string
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

function passwordResetBuildLink(string $token): string
{
    return passwordResetSiteBaseUrl() . '/reset_password.php?token=' . urlencode($token);
}

function renderPasswordResetEmailHtml(PDO $pdo, array $config, array $user, string $resetLink): string
{
    $smtp = getMailSmtpConfig($pdo);
    $siteName = trim((string)($smtp['from_name'] ?? '竹叶云控平台'));
    $expireMinutes = max(1, (int)ceil(((int)$config['link_expire']) / 60));

    $replacements = [
        '{{reset_link}}' => htmlspecialchars($resetLink, ENT_QUOTES, 'UTF-8'),
        '{{username}}' => htmlspecialchars((string)($user['username'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{email}}' => htmlspecialchars((string)($user['email'] ?? ''), ENT_QUOTES, 'UTF-8'),
        '{{expire_minutes}}' => (string)$expireMinutes,
        '{{site_name}}' => htmlspecialchars($siteName, ENT_QUOTES, 'UTF-8'),
    ];

    return str_replace(array_keys($replacements), array_values($replacements), $config['html_template']);
}

function passwordResetTokenHash(string $token): string
{
    return hash('sha256', $token);
}

/**
 * @return array{ok:bool,message?:string}
 */
function requestPasswordReset(PDO $pdo, string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '邮箱格式不正确'];
    }

    if (!isPasswordResetEnabled($pdo)) {
        return ['ok' => false, 'message' => '密码重置功能未开启'];
    }

    if (!isMailConfigured($pdo)) {
        return ['ok' => false, 'message' => '邮件服务未配置，请联系管理员'];
    }

    $stmt = $pdo->prepare('SELECT id, username, email FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$user) {
        return ['ok' => false, 'message' => '当前邮箱未注册'];
    }

    $config = getPasswordResetConfig($pdo);
    ensurePasswordResetTokensTable($pdo);

    $token = bin2hex(random_bytes(32));
    $tokenHash = passwordResetTokenHash($token);
    $expiresAt = date('Y-m-d H:i:s', time() + (int)$config['link_expire']);

    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL')
        ->execute([(int)$user['id']]);

    $insert = $pdo->prepare('
        INSERT INTO password_reset_tokens (user_id, email, token_hash, expires_at)
        VALUES (?, ?, ?, ?)
    ');
    $insert->execute([(int)$user['id'], $email, $tokenHash, $expiresAt]);

    $resetLink = passwordResetBuildLink($token);
    $html = renderPasswordResetEmailHtml($pdo, $config, $user, $resetLink);
    $send = sendSiteMail($pdo, $email, $config['subject'], $html, true);

    if (empty($send['ok'])) {
        return ['ok' => false, 'message' => $send['message'] ?? '重置邮件发送失败'];
    }

    return ['ok' => true, 'message' => '重置链接已发送到您的邮箱，请查收'];
}

/**
 * @return array{ok:bool,message?:string,user?:array,token_id?:int}
 */
function validatePasswordResetToken(PDO $pdo, string $token): array
{
    $token = trim($token);
    if ($token === '' || !preg_match('/^[a-f0-9]{64}$/i', $token)) {
        return ['ok' => false, 'message' => '重置链接无效'];
    }

    ensurePasswordResetTokensTable($pdo);

    $hash = passwordResetTokenHash($token);
    $stmt = $pdo->prepare('
        SELECT t.*, u.username, u.email AS user_email
        FROM password_reset_tokens t
        INNER JOIN users u ON u.id = t.user_id
        WHERE t.token_hash = ? AND t.used_at IS NULL
        LIMIT 1
    ');
    $stmt->execute([$hash]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$row) {
        return ['ok' => false, 'message' => '重置链接无效或已使用'];
    }

    if (strtotime((string)$row['expires_at']) < time()) {
        return ['ok' => false, 'message' => '重置链接已过期，请重新申请'];
    }

    return [
        'ok' => true,
        'user' => [
            'id' => (int)$row['user_id'],
            'username' => (string)$row['username'],
            'email' => (string)$row['user_email'],
        ],
        'token_id' => (int)$row['id'],
    ];
}

/**
 * @return array{ok:bool,message?:string}
 */
function completePasswordReset(PDO $pdo, string $token, string $newPassword): array
{
    if (strlen($newPassword) < 6) {
        return ['ok' => false, 'message' => '密码至少 6 位'];
    }

    $check = validatePasswordResetToken($pdo, $token);
    if (empty($check['ok'])) {
        return ['ok' => false, 'message' => $check['message'] ?? '重置链接无效'];
    }

    $userId = (int)$check['user']['id'];
    $tokenId = (int)$check['token_id'];

    $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')
        ->execute([password_hash($newPassword, PASSWORD_DEFAULT), $userId]);

    $pdo->prepare('UPDATE password_reset_tokens SET used_at = NOW() WHERE id = ?')
        ->execute([$tokenId]);

    $pdo->prepare('DELETE FROM password_reset_tokens WHERE user_id = ? AND used_at IS NULL AND id <> ?')
        ->execute([$userId, $tokenId]);

    return ['ok' => true, 'message' => '密码已重置，请使用新密码登录'];
}

/**
 * @return array{ok:bool,message?:string}
 */
function sendPasswordResetTest(PDO $pdo, string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '请填写有效的测试邮箱'];
    }

    if (!isMailConfigured($pdo)) {
        return ['ok' => false, 'message' => '请先在「邮局配置」中完成 SMTP 设置'];
    }

    $config = getPasswordResetConfig($pdo);
    if ($error = passwordResetConfigValidationError($config)) {
        return ['ok' => false, 'message' => $error];
    }

    $user = ['username' => '测试用户', 'email' => $email];
    $resetLink = passwordResetBuildLink('test_' . bin2hex(random_bytes(8)));
    $html = renderPasswordResetEmailHtml($pdo, $config, $user, $resetLink);

    $result = sendSiteMail($pdo, $email, $config['subject'], $html, true);
    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => $result['message'] ?? '测试发送失败'];
    }

    return ['ok' => true, 'message' => '测试重置邮件已发送至 ' . $email];
}
