<?php

require_once __DIR__ . '/mail_config.php';

function mailServerExtractRequestApiKey(): string
{
    $header = $_SERVER['HTTP_X_MAIL_API_KEY'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($header !== '') {
        return trim($header);
    }

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(\S+)/i', $auth, $m)) {
        return trim($m[1]);
    }

    return trim((string)($_GET['api_key'] ?? $_POST['api_key'] ?? ''));
}

function mailServerConfiguredApiKey(PDO $pdo): string
{
    $cfg = getMailSmtpConfig($pdo);

    return trim((string)($cfg['api_key'] ?? ''));
}

function mailServerApiKeyValid(PDO $pdo): bool
{
    $expected = mailServerConfiguredApiKey($pdo);
    if ($expected === '') {
        return false;
    }

    $provided = mailServerExtractRequestApiKey();

    return $provided !== '' && hash_equals($expected, $provided);
}

function mailServerRequireApiKey(PDO $pdo): void
{
    if (!mailServerApiKeyValid($pdo)) {
        http_response_code(401);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => false, 'message' => 'API 密钥无效或未配置'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function getSiteAdminUser(PDO $pdo): ?array
{
    $stmt = $pdo->prepare("SELECT id, username, password, email FROM users WHERE username = 'admin' LIMIT 1");
    $stmt->execute();
    $user = $stmt->fetch();

    return $user ?: null;
}

/**
 * @return array{ok:bool,message?:string,username?:string,email?:string}
 */
function verifySiteAdminCredentials(PDO $pdo, string $username, string $password): array
{
    $username = trim($username);
    $password = (string)$password;

    if ($username === '' || $password === '') {
        return ['ok' => false, 'message' => '请提供用户名和密码'];
    }

    $admin = getSiteAdminUser($pdo);
    if (!$admin) {
        return ['ok' => false, 'message' => '网站管理员账号不存在'];
    }

    if ($username !== $admin['username'] && $username !== ($admin['email'] ?? '')) {
        return ['ok' => false, 'message' => '用户名或密码错误'];
    }

    if (!password_verify($password, $admin['password'])) {
        return ['ok' => false, 'message' => '用户名或密码错误'];
    }

    return [
        'ok' => true,
        'message' => '验证成功',
        'username' => $admin['username'],
        'email' => (string)($admin['email'] ?? ''),
    ];
}

/**
 * @return array{ok:bool,message?:string,username?:string,email?:string}
 */
function getSiteAdminPublicInfo(PDO $pdo): array
{
    $admin = getSiteAdminUser($pdo);
    if (!$admin) {
        return ['ok' => false, 'message' => '网站管理员账号不存在'];
    }

    return [
        'ok' => true,
        'username' => $admin['username'],
        'email' => (string)($admin['email'] ?? ''),
    ];
}
