<?php

const ACCOUNT_STATUS_ADMIN_EMAIL = 'qinlin659@outlook.com';

const ACCOUNT_STATUS_ACTIVATION = 'activation';
const ACCOUNT_STATUS_BANNED = 'banned';
const ACCOUNT_STATUS_FROZEN = 'frozen';
const ACCOUNT_STATUS_DELETED = 'deleted';
const ACCOUNT_STATUS_BAN_TIMED = 'ban_timed';

function accountStatusPopupLabels(): array
{
    return [
        ACCOUNT_STATUS_ACTIVATION => '账号激活',
        ACCOUNT_STATUS_BANNED => '账号封禁',
        ACCOUNT_STATUS_FROZEN => '账号冻结',
        ACCOUNT_STATUS_DELETED => '账号删除',
        ACCOUNT_STATUS_BAN_TIMED => '账号定时封禁',
    ];
}

function accountStatusPopupLabel(string $type): string
{
    $labels = accountStatusPopupLabels();

    return $labels[$type] ?? '状态异常';
}

function accountStatusPopupMessage(string $type, array $extra = []): string
{
    $admin = ACCOUNT_STATUS_ADMIN_EMAIL;

    switch ($type) {
        case ACCOUNT_STATUS_ACTIVATION:
            return '你的账号暂时被风控，已经发送了账号激活链接到你预留的邮箱里，如果没有看见邮箱请在：垃圾邮箱/垃圾邮件中寻找，为了您能正常接受平台邮件通知，还请您把本平台邮件设置为不是垃圾邮件，如还是没有收到邮件请联系管理员：' . $admin . '。';

        case ACCOUNT_STATUS_BANNED:
            return '你的账号已被封禁请联系管理员解除管理员：' . $admin . '。';

        case ACCOUNT_STATUS_FROZEN:
            return '你的账号已被冻结请联系管理员解除管理员：' . $admin . '。';

        case ACCOUNT_STATUS_DELETED:
            return '你的账号已被管理员删除，如有疑问请联系管理员：' . $admin . '。';

        case ACCOUNT_STATUS_BAN_TIMED:
            $until = trim((string)($extra['ban_until'] ?? ''));
            if ($until === '') {
                $until = '指定时间';
            }

            return '你的账号已被定时封禁，' . $until . ' 后自动解除封禁。';

        default:
            return '您的账号当前无法正常使用，如有疑问请联系管理员：' . $admin . '。';
    }
}

/**
 * @return array{type:string,title:string,message:string,ban_until?:string}|null
 */
function resolveAccountStatusPopup(?array $user, PDO $pdo): ?array
{
    if (isAdmin()) {
        return null;
    }

    if (isLoggedIn() && !$user) {
        return buildAccountStatusPopup(ACCOUNT_STATUS_DELETED);
    }

    if (!$user) {
        return null;
    }

    if (($user['status'] ?? '') === 'frozen') {
        return buildAccountStatusPopup(ACCOUNT_STATUS_FROZEN);
    }

    if (($user['status'] ?? '') === 'banned') {
        $banUntil = $user['ban_until'] ?? null;
        if ($banUntil && strtotime((string)$banUntil) > time()) {
            return buildAccountStatusPopup(ACCOUNT_STATUS_BAN_TIMED, [
                'ban_until' => (string)$banUntil,
            ]);
        }

        if ($banUntil && strtotime((string)$banUntil) <= time()) {
            return null;
        }

        if (function_exists('authAccountActivationLoaded')) {
            authAccountActivationLoaded();
        } else {
            require_once __DIR__ . '/account_activation.php';
        }
        if (userIsPendingActivationBan($user)) {
            return buildAccountStatusPopup(ACCOUNT_STATUS_ACTIVATION);
        }

        return buildAccountStatusPopup(ACCOUNT_STATUS_BANNED);
    }

    return null;
}

/**
 * @return array{type:string,title:string,message:string,ban_until?:string}
 */
function buildAccountStatusPopup(string $type, array $extra = []): array
{
    $label = accountStatusPopupLabel($type);
    $popup = [
        'type' => $type,
        'title' => '当前账号' . $label,
        'message' => accountStatusPopupMessage($type, $extra),
    ];

    if ($type === ACCOUNT_STATUS_BAN_TIMED && !empty($extra['ban_until'])) {
        $popup['ban_until'] = (string)$extra['ban_until'];
    }

    return $popup;
}

function accountStatusRestrictedPages(): array
{
    return [
        'index.php',
        'profile.php',
        'user_home.php',
        'upload.php',
        'logout.php',
        'activate_account.php',
        'login.php',
    ];
}

function enforceAccountStatusPageAccess(?array $user): void
{
    if (isAdmin()) {
        return;
    }

    $pdo = getDB();
    $popup = resolveAccountStatusPopup($user, $pdo);
    if ($popup === null) {
        return;
    }

    $script = basename($_SERVER['SCRIPT_NAME'] ?? '');
    if (in_array($script, accountStatusRestrictedPages(), true)) {
        return;
    }

    $basePath = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? '../' : '';
    header('Location: ' . $basePath . 'index.php');
    exit;
}

function getAccountStatusPopupForSession(?array $user = null, ?PDO $pdo = null): ?array
{
    if (!isLoggedIn() || isAdmin()) {
        return null;
    }

    $pdo = $pdo ?? getDB();
    if ($user === null) {
        $user = getCurrentUser();
    }

    return resolveAccountStatusPopup($user, $pdo);
}
