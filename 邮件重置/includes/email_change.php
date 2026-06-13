<?php

declare(strict_types=1);

function ensureEmailChangeSchema(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'email_change_used'");
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE users ADD COLUMN email_change_used tinyint(1) NOT NULL DEFAULT 0 AFTER email');
    }
}

function userEmailChangeUsed(array $user): bool
{
    return !empty($user['email_change_used']);
}

function maskEmailAddress(string $email): string
{
    $email = trim($email);
    if ($email === '' || !str_contains($email, '@')) {
        return '';
    }

    [$local, $domain] = explode('@', $email, 2);
    $len = function_exists('mb_strlen') ? mb_strlen($local, 'UTF-8') : strlen($local);
    if ($len <= 1) {
        $maskedLocal = '*';
    } elseif ($len === 2) {
        $maskedLocal = (function_exists('mb_substr') ? mb_substr($local, 0, 1, 'UTF-8') : $local[0]) . '*';
    } else {
        $head = function_exists('mb_substr') ? mb_substr($local, 0, 1, 'UTF-8') : $local[0];
        $tail = function_exists('mb_substr') ? mb_substr($local, -1, 1, 'UTF-8') : $local[strlen($local) - 1];
        $maskedLocal = $head . str_repeat('*', max(1, $len - 2)) . $tail;
    }

    return $maskedLocal . '@' . $domain;
}

function fetchUserByUsername(PDO $pdo, string $username): ?array
{
    ensureEmailChangeSchema($pdo);
    $username = trim($username);
    if ($username === '') {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $row = $stmt->fetch();

    return $row ?: null;
}

/**
 * @return array{ok:bool,message?:string,can_change?:bool,used?:bool,current_email?:string,masked_email?:string}
 */
function getEmailChangeStatus(PDO $pdo, string $username): array
{
    $user = fetchUserByUsername($pdo, $username);
    if (!$user) {
        return ['ok' => false, 'message' => '账号不存在'];
    }

    $used = userEmailChangeUsed($user);
    $currentEmail = (string)($user['email'] ?? '');

    return [
        'ok' => true,
        'can_change' => !$used,
        'used' => $used,
        'current_email' => $currentEmail,
        'masked_email' => maskEmailAddress($currentEmail),
    ];
}

/**
 * @return array{ok:bool,message?:string,field_errors?:array<string,string>}
 */
function processEmailChange(PDO $pdo, string $username, string $newEmail, int $actorUserId): array
{
    ensureEmailChangeSchema($pdo);

    $username = trim($username);
    $newEmail = trim($newEmail);
    $fieldErrors = [];

    if ($username === '') {
        $fieldErrors['username'] = '请填写账号';
    }
    if ($newEmail === '') {
        $fieldErrors['email'] = '请填写新邮箱';
    } elseif (!filter_var($newEmail, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = '邮箱格式不正确';
    } elseif (strlen($newEmail) > 100) {
        $fieldErrors['email'] = '邮箱过长';
    }

    if ($fieldErrors !== []) {
        return ['ok' => false, 'message' => '请检查表单', 'field_errors' => $fieldErrors];
    }

    $user = fetchUserByUsername($pdo, $username);
    if (!$user) {
        return ['ok' => false, 'message' => '账号不存在', 'field_errors' => ['username' => '账号不存在']];
    }

    if ((int)$user['id'] !== $actorUserId) {
        return ['ok' => false, 'message' => '只能修改当前登录账号的邮箱'];
    }

    if (userEmailChangeUsed($user)) {
        return ['ok' => false, 'message' => '该账号已使用过邮箱修改机会，无法再次修改'];
    }

    if (strcasecmp((string)$user['email'], $newEmail) === 0) {
        return ['ok' => false, 'message' => '新邮箱不能与当前邮箱相同', 'field_errors' => ['email' => '新邮箱不能与当前邮箱相同']];
    }

    $check = $pdo->prepare('SELECT id FROM users WHERE email = ? AND id <> ? LIMIT 1');
    $check->execute([$newEmail, (int)$user['id']]);
    if ($check->fetch()) {
        return ['ok' => false, 'message' => '该邮箱已被其他账号使用', 'field_errors' => ['email' => '该邮箱已被其他账号使用']];
    }

    $stmt = $pdo->prepare('UPDATE users SET email = ?, email_change_used = 1 WHERE id = ? AND email_change_used = 0');
    $stmt->execute([$newEmail, (int)$user['id']]);
    if ($stmt->rowCount() <= 0) {
        return ['ok' => false, 'message' => '邮箱修改失败，可能已使用过修改机会'];
    }

    return ['ok' => true, 'message' => '邮箱已更新，本次修改机会已使用'];
}
