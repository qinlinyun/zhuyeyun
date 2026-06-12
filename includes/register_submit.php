<?php

require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/register_page.php';
require_once __DIR__ . '/register_verify.php';
require_once __DIR__ . '/traffic.php';
require_once __DIR__ . '/user_growth_analytics.php';
require_once __DIR__ . '/settings.php';

/**
 * 处理注册提交，返回字段级错误供前端展示。
 *
 * @param array{username?:string,email?:string,password?:string,confirm?:string,verify_code?:string} $input
 * @return array{ok:bool,field_errors:array<string,string>,message?:string,redirect?:string}
 */
function processRegisterSubmission(PDO $pdo, array $input): array
{
    $empty = ['ok' => false, 'field_errors' => []];

    if (!isRegisterEnabled($pdo)) {
        $scheduleBlocking = isRegisterScheduleBlocking($pdo);
        return array_merge($empty, [
            'message' => $scheduleBlocking ? '注册功能暂时关闭，请稍后再试' : '注册功能已关闭',
        ]);
    }

    $registerPageConfig = getRegisterPageConfig($pdo);
    $registerVerifyEnabled = isRegisterVerifyEnabled($pdo);
    $mailConfigured = isMailConfigured($pdo);

    $username = trim((string)($input['username'] ?? ''));
    $email = trim((string)($input['email'] ?? ''));
    $password = (string)($input['password'] ?? '');
    $confirm = (string)($input['confirm'] ?? '');
    $verifyCode = trim((string)($input['verify_code'] ?? ''));

    $fieldErrors = [];

    if ($username === '') {
        $fieldErrors['username'] = '请填写用户名';
    }
    if ($email === '') {
        $fieldErrors['email'] = '请填写邮箱';
    }
    if ($password === '') {
        $fieldErrors['password'] = '请填写密码';
    }
    if ($confirm === '') {
        $fieldErrors['confirm'] = '请填写确认密码';
    }
    if ($registerVerifyEnabled && $verifyCode === '') {
        $fieldErrors['verify_code'] = '请填写邮箱验证码';
    }

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $fieldErrors['email'] = '邮箱格式不正确';
    }

    if ($password !== '' && $confirm !== '' && $password !== $confirm) {
        $fieldErrors['confirm'] = '两次输入的密码不一致';
    }

    if ($username !== '' && ($msg = validateRegisterUsername($username, $registerPageConfig))) {
        $fieldErrors['username'] = $msg;
    }

    if ($password !== '' && ($msg = validateRegisterPassword($password, $registerPageConfig))) {
        $fieldErrors['password'] = $msg;
    }

    if ($fieldErrors) {
        return ['ok' => false, 'field_errors' => $fieldErrors];
    }

    $existingUser = findExistingRegisterUser($pdo, $username, $email);
    if ($existingUser) {
        $field = ($existingUser['field'] ?? '') === 'email' ? 'email' : 'username';
        return [
            'ok' => false,
            'field_errors' => [
                $field => registerDuplicateUserMessage($existingUser, $username, $email),
            ],
        ];
    }

    if ($registerVerifyEnabled) {
        if (!$mailConfigured) {
            return array_merge($empty, ['message' => '邮箱验证功能暂不可用，请联系管理员']);
        }

        $verifyResult = verifyRegisterVerificationCode($pdo, $email, $verifyCode);
        if (empty($verifyResult['ok'])) {
            return [
                'ok' => false,
                'field_errors' => [
                    'verify_code' => $verifyResult['message'] ?? '验证码校验失败',
                ],
            ];
        }
    }

    $hash = password_hash($password, PASSWORD_DEFAULT);
    $groupId = 1;

    $stmt = $pdo->prepare("
        INSERT INTO users (username, email, password, status, group_id, created_at)
        VALUES (?, ?, ?, 'active', ?, ?)
    ");
    $stmt->execute([$username, $email, $hash, $groupId, chinaNow()]);

    $newUserId = (int)$pdo->lastInsertId();
    grantInitialTrafficFromGroup($pdo, $newUserId, $groupId);
    recordUserGrowthEvent($newUserId);

    if ($registerVerifyEnabled) {
        consumeRegisterVerificationCode($pdo, $email);
    }

    return [
        'ok' => true,
        'field_errors' => [],
        'redirect' => 'login.php',
    ];
}
