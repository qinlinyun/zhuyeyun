<?php

require_once __DIR__ . '/register_verify_config.php';
require_once __DIR__ . '/mail_sender.php';
require_once __DIR__ . '/redis_config.php';

const REGISTER_VERIFY_MAX_RESEND_AFTER_FIRST = 3;
const REGISTER_VERIFY_MAX_SENDS_PER_EMAIL = 4;
const REGISTER_VERIFY_IP_LIMIT_PER_MINUTE = 15;
const REGISTER_VERIFY_IP_WINDOW_SECONDS = 60;

function registerVerifyClientIp(): string
{
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $raw = (string)$_SERVER[$key];
        $ip = trim(explode(',', $raw)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }

    return '0.0.0.0';
}

function registerVerifyRedisReady(): bool
{
    return isRedisWatchProgressAvailable();
}

function registerVerifyRedisKey(string $suffix): string
{
    return getRedisPrefix() . 'register_verify:' . $suffix;
}

function registerVerifyGetEmailSendCount(string $email): int
{
    if (!registerVerifyRedisReady()) {
        return 0;
    }

    try {
        $redis = getRedisConnection();
        $count = $redis->get(registerVerifyRedisKey('email_count:' . md5(strtolower($email))));

        return max(0, (int)$count);
    } catch (Throwable $e) {
        return 0;
    }
}

function registerVerifyEmailResendRemaining(string $email): int
{
    $count = registerVerifyGetEmailSendCount($email);

    return max(0, REGISTER_VERIFY_MAX_SENDS_PER_EMAIL - $count);
}

function registerVerifyIncrEmailSendCount(string $email, int $ttlSeconds): int
{
    if (!registerVerifyRedisReady()) {
        return 1;
    }

    $key = registerVerifyRedisKey('email_count:' . md5(strtolower($email)));
    $redis = getRedisConnection();
    $count = (int)$redis->incr($key);
    if ($count === 1 && $ttlSeconds > 0) {
        $redis->expire($key, $ttlSeconds);
    }

    return $count;
}

function registerVerifyGetIpSendCount(string $ip): int
{
    if (!registerVerifyRedisReady()) {
        return 0;
    }

    try {
        $redis = getRedisConnection();
        $count = $redis->get(registerVerifyRedisKey('ip_count:' . md5($ip)));

        return max(0, (int)$count);
    } catch (Throwable $e) {
        return 0;
    }
}

function registerVerifyIncrIpSendCount(string $ip): int
{
    if (!registerVerifyRedisReady()) {
        return 1;
    }

    $key = registerVerifyRedisKey('ip_count:' . md5($ip));
    $redis = getRedisConnection();
    $count = (int)$redis->incr($key);
    if ($count === 1) {
        $redis->expire($key, REGISTER_VERIFY_IP_WINDOW_SECONDS);
    }

    return $count;
}

/**
 * @return array{ok:bool,message?:string}
 */
function registerVerifyCheckSendLimits(string $email, ?string $ip = null): array
{
    if (!registerVerifyRedisReady()) {
        return ['ok' => false, 'message' => '验证码限流服务暂不可用，请联系管理员启用 Redis'];
    }

    $emailCount = registerVerifyGetEmailSendCount($email);
    if ($emailCount >= REGISTER_VERIFY_MAX_SENDS_PER_EMAIL) {
        return [
            'ok' => false,
            'message' => '该邮箱验证码发送次数已达上限（倒计时结束后最多重发 '
                . REGISTER_VERIFY_MAX_RESEND_AFTER_FIRST . ' 次），请稍后再试',
        ];
    }

    $ip = $ip ?? registerVerifyClientIp();
    $ipCount = registerVerifyGetIpSendCount($ip);
    if ($ipCount >= REGISTER_VERIFY_IP_LIMIT_PER_MINUTE) {
        return [
            'ok' => false,
            'message' => '发送过于频繁，请 1 分钟后再试',
        ];
    }

    return ['ok' => true];
}

function registerVerifyClearEmailSendCount(string $email): void
{
    if (!registerVerifyRedisReady()) {
        return;
    }

    try {
        getRedisConnection()->del(registerVerifyRedisKey('email_count:' . md5(strtolower(trim($email)))));
    } catch (Throwable $e) {
        // ignore
    }
}

function findExistingRegisterUser(PDO $pdo, string $username, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT username, email FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return ['field' => 'username', 'username' => $row['username'], 'email' => $row['email'] ?? ''];
    }

    $stmt = $pdo->prepare('SELECT username, email FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row) {
        return ['field' => 'email', 'username' => $row['username'] ?? '', 'email' => $row['email']];
    }

    return null;
}

function registerDuplicateUserMessage(array $existing, string $username, string $email): string
{
    if (($existing['field'] ?? '') === 'username' || strcasecmp((string)($existing['username'] ?? ''), $username) === 0) {
        return '用户名 ' . $username . ' 已存在，请登录';
    }

    return '邮箱 ' . $email . ' 已存在，请登录';
}

function findExistingRegisterEmail(PDO $pdo, string $email): ?array
{
    $stmt = $pdo->prepare('SELECT email FROM users WHERE email = ? LIMIT 1');
    $stmt->execute([strtolower(trim($email))]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function ensureEmailVerificationsTable(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `email_verifications` (
        `id` int(11) NOT NULL AUTO_INCREMENT,
        `email` varchar(100) NOT NULL,
        `code` varchar(6) NOT NULL,
        `expires_at` datetime NOT NULL,
        `created_at` datetime DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        UNIQUE KEY `uniq_email` (`email`),
        KEY `idx_expires` (`expires_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ready = true;
}

function generateRegisterVerifyCode(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

function getRegisterVerifyRecord(PDO $pdo, string $email): ?array
{
    ensureEmailVerificationsTable($pdo);
    $stmt = $pdo->prepare('SELECT * FROM email_verifications WHERE email = ? LIMIT 1');
    $stmt->execute([$email]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    return $row ?: null;
}

function registerVerifyResendWaitSeconds(PDO $pdo, string $email, array $config): int
{
    $record = getRegisterVerifyRecord($pdo, $email);
    if (!$record) {
        return 0;
    }

    $elapsed = time() - strtotime((string)$record['created_at']);
    $wait = (int)$config['resend_interval'] - $elapsed;

    return $wait > 0 ? $wait : 0;
}

/**
 * @return array{ok:bool,retry_after?:int,resend_remaining?:int,resend_interval?:int}
 */
function getRegisterVerifySendStatus(PDO $pdo, string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '邮箱格式不正确'];
    }

    $config = getRegisterVerifyConfig($pdo);

    return [
        'ok' => true,
        'retry_after' => registerVerifyResendWaitSeconds($pdo, $email, $config),
        'resend_remaining' => registerVerifyEmailResendRemaining($email),
        'resend_interval' => (int)$config['resend_interval'],
    ];
}

/**
 * @return array{ok:bool,message?:string,retry_after?:int}
 */
function sendRegisterVerificationCode(PDO $pdo, string $email, bool $skipEnabledCheck = false): array
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '邮箱格式不正确'];
    }

    $config = getRegisterVerifyConfig($pdo);
    if (!$skipEnabledCheck && empty($config['enabled'])) {
        return ['ok' => false, 'message' => '注册验证码功能未启用'];
    }

    if (!isMailConfigured($pdo)) {
        return ['ok' => false, 'message' => '邮局 SMTP 未配置，无法发送验证码'];
    }

    ensureEmailVerificationsTable($pdo);

    $existingEmail = findExistingRegisterEmail($pdo, $email);
    if ($existingEmail) {
        return ['ok' => false, 'message' => '邮箱 ' . $email . ' 已存在，请登录'];
    }

    $wait = registerVerifyResendWaitSeconds($pdo, $email, $config);
    if ($wait > 0) {
        return [
            'ok' => false,
            'message' => '发送过于频繁，请 ' . $wait . ' 秒后再试',
            'retry_after' => $wait,
            'resend_remaining' => registerVerifyEmailResendRemaining($email),
        ];
    }

    $limitCheck = registerVerifyCheckSendLimits($email);
    if (empty($limitCheck['ok'])) {
        return [
            'ok' => false,
            'message' => $limitCheck['message'] ?? '发送受限',
            'resend_remaining' => registerVerifyEmailResendRemaining($email),
        ];
    }

    $clientIp = registerVerifyClientIp();
    $code = generateRegisterVerifyCode();
    $expiresAt = date('Y-m-d H:i:s', time() + (int)$config['code_expire']);
    $html = renderRegisterVerifyEmailHtml($pdo, $config, $email, $code);

    $send = sendSiteMail($pdo, $email, $config['subject'], $html, true);
    if (empty($send['ok'])) {
        return ['ok' => false, 'message' => $send['message'] ?? '验证码邮件发送失败'];
    }

    $stmt = $pdo->prepare('
        INSERT INTO email_verifications (email, code, expires_at, created_at)
        VALUES (?, ?, ?, NOW())
        ON DUPLICATE KEY UPDATE code = VALUES(code), expires_at = VALUES(expires_at), created_at = NOW()
    ');
    $stmt->execute([$email, $code, $expiresAt]);

    if (registerVerifyRedisReady()) {
        registerVerifyIncrEmailSendCount($email, (int)$config['code_expire']);
        registerVerifyIncrIpSendCount($clientIp);
    }

    return [
        'ok' => true,
        'message' => '验证码已发送，请查收邮件',
        'retry_after' => (int)$config['resend_interval'],
        'resend_remaining' => registerVerifyEmailResendRemaining($email),
    ];
}

/**
 * @return array{ok:bool,message?:string}
 */
function verifyRegisterVerificationCode(PDO $pdo, string $email, string $code): array
{
    $email = strtolower(trim($email));
    $code = trim($code);

    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '邮箱格式不正确'];
    }
    if (!preg_match('/^\d{6}$/', $code)) {
        return ['ok' => false, 'message' => '请输入 6 位数字验证码'];
    }

    ensureEmailVerificationsTable($pdo);

    $record = getRegisterVerifyRecord($pdo, $email);
    if (!$record) {
        return ['ok' => false, 'message' => '请先获取邮箱验证码'];
    }

    if (strtotime((string)$record['expires_at']) < time()) {
        return ['ok' => false, 'message' => '验证码已过期，请重新获取'];
    }

    if (!hash_equals((string)$record['code'], $code)) {
        return ['ok' => false, 'message' => '验证码错误'];
    }

    return ['ok' => true];
}

function consumeRegisterVerificationCode(PDO $pdo, string $email): void
{
    ensureEmailVerificationsTable($pdo);
    $email = strtolower(trim($email));
    $stmt = $pdo->prepare('DELETE FROM email_verifications WHERE email = ?');
    $stmt->execute([$email]);
    registerVerifyClearEmailSendCount($email);
}

/**
 * 管理后台测试发送（不校验邮箱是否已注册、不校验功能开关）
 *
 * @return array{ok:bool,message?:string}
 */
function sendRegisterVerificationCodeTest(PDO $pdo, string $email): array
{
    $email = strtolower(trim($email));
    if ($email === '' || !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        return ['ok' => false, 'message' => '请填写有效的测试邮箱'];
    }

    if (!isMailConfigured($pdo)) {
        return ['ok' => false, 'message' => '请先在「邮局配置」中完成 SMTP 设置'];
    }

    $config = getRegisterVerifyConfig($pdo);
    $code = generateRegisterVerifyCode();
    $html = renderRegisterVerifyEmailHtml($pdo, $config, $email, $code);

    $send = sendSiteMail($pdo, $email, $config['subject'], $html, true);
    if (empty($send['ok'])) {
        return ['ok' => false, 'message' => $send['message'] ?? '测试邮件发送失败'];
    }

    return ['ok' => true, 'message' => '测试验证码邮件已发送至 ' . $email . '（验证码：' . $code . '，仅用于测试）'];
}
