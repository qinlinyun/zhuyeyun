<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/flash.php';
require_once '../includes/mail_config.php';
require_once '../includes/mail_sender.php';
require_once '../includes/register_verify.php';
require_once '../includes/mail_broadcast.php';
require_once '../includes/mail_targeted.php';
require_once '../includes/password_reset.php';
require_once '../includes/account_activation.php';
require_once '../includes/ban_notice.php';

requireAdmin();

$pdo = getDB();
$message = '';
$error = '';
applyFlash($message, $error);

$menu = require __DIR__ . '/../includes/mail_menu.php';

$activeSection = trim((string)($_GET['section'] ?? ''));
$menuIds = array_column($menu, 'id');
if ($activeSection === '' || !in_array($activeSection, $menuIds, true)) {
    $activeSection = $menu[0]['id'] ?? 'config';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'mail_config') {
    $existing = getMailSmtpConfig($pdo);
    $mailConfig = parseMailSmtpConfigFromPost($_POST, $existing);

    if ($validationError = mailSmtpValidationError($mailConfig)) {
        flashSet('error', $validationError);
        header('Location: mail.php?section=config');
        exit;
    }

    saveMailSmtpConfig($pdo, $mailConfig);
    finishPostRequest(
        $mailConfig['enabled'] ? '邮局 SMTP 配置已保存并启用' : '邮局 SMTP 配置已保存',
        null,
        'mail.php?section=config'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'register_verify_config') {
    $registerVerifyConfig = parseRegisterVerifyConfigFromPost($_POST);

    if ($validationError = registerVerifyValidationError($registerVerifyConfig)) {
        flashSet('error', $validationError);
        header('Location: mail.php?section=register_code');
        exit;
    }

    if (!empty($registerVerifyConfig['enabled']) && !isMailConfigured($pdo)) {
        flashSet('error', '启用注册验证码前，请先在「邮局配置」中完成 SMTP 设置');
        header('Location: mail.php?section=register_code');
        exit;
    }

    saveRegisterVerifyConfig($pdo, $registerVerifyConfig);
    finishPostRequest(
        $registerVerifyConfig['enabled'] ? '注册验证码已启用并保存' : '注册验证码设置已保存',
        null,
        'mail.php?section=register_code'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'register_verify_test') {
    $testEmail = trim((string)($_POST['test_verify_email'] ?? ''));
    $result = sendRegisterVerificationCodeTest($pdo, $testEmail);
    if (!empty($result['ok'])) {
        finishPostRequest($result['message'], null, 'mail.php?section=register_code');
    }

    flashSet('error', $result['message'] ?? '测试发送失败');
    header('Location: mail.php?section=register_code');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'broadcast_config') {
    $broadcastConfig = parseMailBroadcastConfigFromPost($_POST);
    if ($validationError = mailBroadcastConfigValidationError($broadcastConfig)) {
        flashSet('error', $validationError);
        header('Location: mail.php?section=broadcast');
        exit;
    }
    saveMailBroadcastConfig($pdo, $broadcastConfig);
    finishPostRequest('全员通知配置已保存', null, 'mail.php?section=broadcast');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'broadcast_start') {
    if (mailBroadcastJobIsActive(getMailBroadcastJob($pdo))) {
        flashSet('error', '已有进行中的发送任务');
        header('Location: mail.php?section=broadcast');
        exit;
    }
    $result = startMailBroadcastJob($pdo);
    if (empty($result['ok'])) {
        flashSet('error', $result['message'] ?? '无法创建任务');
        header('Location: mail.php?section=broadcast');
        exit;
    }
    finishPostRequest('全员通知任务已开始，正在后台批次发送…', null, 'mail.php?section=broadcast');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'broadcast_cancel') {
    cancelMailBroadcastJob($pdo);
    finishPostRequest('全员通知任务已取消', null, 'mail.php?section=broadcast');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'broadcast_test') {
    $testEmail = trim((string)($_POST['broadcast_test_email'] ?? ''));
    $result = sendMailBroadcastTest($pdo, $testEmail);
    if (!empty($result['ok'])) {
        finishPostRequest($result['message'], null, 'mail.php?section=broadcast');
    }
    flashSet('error', $result['message'] ?? '测试发送失败');
    header('Location: mail.php?section=broadcast');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'targeted_template_save') {
    $tplId = trim((string)($_POST['targeted_tpl_id'] ?? ''));
    $tplName = trim((string)($_POST['targeted_tpl_name'] ?? ''));
    $tplHtml = (string)($_POST['targeted_tpl_html'] ?? '');

    $result = upsertMailTargetedTemplate($pdo, $tplId, $tplName, $tplHtml);
    if (!empty($result['ok'])) {
        finishPostRequest('邮件模板已保存', null, 'mail.php?section=targeted');
    }

    flashSet('error', $result['message'] ?? '保存失败');
    header('Location: mail.php?section=targeted');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'targeted_template_delete') {
    $tplId = trim((string)($_POST['targeted_tpl_id'] ?? ''));
    $result = deleteMailTargetedTemplate($pdo, $tplId);
    if (!empty($result['ok'])) {
        finishPostRequest('邮件模板已删除', null, 'mail.php?section=targeted');
    }
    flashSet('error', $result['message'] ?? '删除失败');
    header('Location: mail.php?section=targeted');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'targeted_send') {
    if (!isMailConfigured($pdo)) {
        flashSet('error', '请先在「邮局配置」中完成 SMTP 设置并启用');
        header('Location: mail.php?section=targeted');
        exit;
    }

    $subject = trim((string)($_POST['targeted_subject'] ?? ''));
    $content = (string)($_POST['targeted_content'] ?? '');
    $templateId = trim((string)($_POST['targeted_template_id'] ?? ''));

    $userIdsRaw = (string)($_POST['targeted_user_ids'] ?? '');
    $userIds = [];
    if ($userIdsRaw !== '') {
        foreach (preg_split('/\s*,\s*/', $userIdsRaw) as $part) {
            $part = trim((string)$part);
            if ($part === '') {
                continue;
            }
            $userIds[] = (int)$part;
        }
    }

    $result = sendMailTargetedToUsers(
        $pdo,
        $userIds,
        $subject,
        $content,
        $templateId !== '' ? $templateId : null
    );

    if (!empty($result['ok'])) {
        finishPostRequest($result['message'] ?? '发送成功', null, 'mail.php?section=targeted');
    }

    $msg = $result['message'] ?? '发送失败';
    if (!empty($result['stats']['failures']) && is_array($result['stats']['failures'])) {
        $lines = [];
        foreach ($result['stats']['failures'] as $f) {
            $lines[] = ($f['email'] ?? '') . '：' . ($f['message'] ?? '');
        }
        if ($lines !== []) {
            $msg .= '（部分失败：' . implode('；', $lines) . '）';
        }
    }

    flashSet('error', $msg);
    header('Location: mail.php?section=targeted');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'password_reset_config') {
    $passwordResetConfig = parsePasswordResetConfigFromPost($_POST);
    if ($validationError = passwordResetConfigValidationError($passwordResetConfig)) {
        flashSet('error', $validationError);
        header('Location: mail.php?section=password_reset');
        exit;
    }
    if (!empty($passwordResetConfig['enabled']) && !isMailConfigured($pdo)) {
        flashSet('error', '启用密码重置前，请先在「邮局配置」中完成 SMTP 设置');
        header('Location: mail.php?section=password_reset');
        exit;
    }
    savePasswordResetConfig($pdo, $passwordResetConfig);
    finishPostRequest(
        $passwordResetConfig['enabled'] ? '密码重置已启用并保存' : '密码重置设置已保存',
        null,
        'mail.php?section=password_reset'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'password_reset_test') {
    $testEmail = trim((string)($_POST['password_reset_test_email'] ?? ''));
    $result = sendPasswordResetTest($pdo, $testEmail);
    if (!empty($result['ok'])) {
        finishPostRequest($result['message'], null, 'mail.php?section=password_reset');
    }
    flashSet('error', $result['message'] ?? '测试发送失败');
    header('Location: mail.php?section=password_reset');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'account_activation_config') {
    $accountActivationConfig = parseAccountActivationConfigFromPost($_POST);
    if ($validationError = accountActivationConfigValidationError($accountActivationConfig)) {
        flashSet('error', $validationError);
        header('Location: ' . accountActivationListUrl(
            (int)($_POST['activation_page'] ?? 1),
            (int)($_POST['activation_per_page'] ?? 10)
        ));
        exit;
    }
    saveAccountActivationConfig($pdo, $accountActivationConfig);
    finishPostRequest('账号激活设置已保存', null, accountActivationListUrl(
        (int)($_POST['activation_page'] ?? 1),
        (int)($_POST['activation_per_page'] ?? 10)
    ));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'account_activation_send') {
    $activationRedirect = accountActivationListUrl(
        (int)($_POST['activation_page'] ?? 1),
        (int)($_POST['activation_per_page'] ?? 10)
    );
    if (!isMailConfigured($pdo)) {
        flashSet('error', '请先在「邮局配置」中完成 SMTP 设置');
        header('Location: ' . $activationRedirect);
        exit;
    }
    $banType = trim((string)($_POST['activation_send_ban_type'] ?? ''));
    $userIds = $_POST['activation_user_ids'] ?? [];
    if (!is_array($userIds)) {
        $userIds = [];
    }
    $result = sendAccountActivationToUsers($pdo, $userIds, $banType);
    if (!empty($result['ok'])) {
        finishPostRequest($result['message'], null, $activationRedirect);
    }
    flashSet('error', $result['message'] ?? '发送失败');
    header('Location: ' . $activationRedirect);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'account_activation_test') {
    $activationRedirect = accountActivationListUrl(
        (int)($_POST['activation_page'] ?? 1),
        (int)($_POST['activation_per_page'] ?? 10)
    );
    $testEmail = trim((string)($_POST['activation_test_email'] ?? ''));
    $banType = trim((string)($_POST['activation_test_ban_type'] ?? ACTIVATION_BAN_TYPE_EMAIL));
    $result = sendAccountActivationTest($pdo, $testEmail, $banType);
    if (!empty($result['ok'])) {
        finishPostRequest($result['message'], null, $activationRedirect);
    }
    flashSet('error', $result['message'] ?? '测试发送失败');
    header('Location: ' . $activationRedirect);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'ban_notice_config') {
    $banNoticeConfig = parseBanNoticeConfigFromPost($_POST);
    if ($validationError = banNoticeConfigValidationError($banNoticeConfig)) {
        flashSet('error', $validationError);
        header('Location: mail.php?section=ban_notice');
        exit;
    }
    if (!empty($banNoticeConfig['enabled']) && !isMailConfigured($pdo)) {
        flashSet('error', '启用封禁通知前，请先在「邮局配置」中完成 SMTP 设置');
        header('Location: mail.php?section=ban_notice');
        exit;
    }
    saveBanNoticeConfig($pdo, $banNoticeConfig);
    finishPostRequest(
        $banNoticeConfig['enabled'] ? '封禁通知已启用并保存' : '封禁通知设置已保存',
        null,
        'mail.php?section=ban_notice'
    );
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'ban_notice_test') {
    $testEmail = trim((string)($_POST['ban_notice_test_email'] ?? ''));
    $testAction = trim((string)($_POST['ban_notice_test_action'] ?? BAN_NOTICE_ACTION_BAN));
    $result = sendBanNoticeTest($pdo, $testEmail, $testAction);
    if (!empty($result['ok'])) {
        finishPostRequest($result['message'], null, 'mail.php?section=ban_notice');
    }
    flashSet('error', $result['message'] ?? '测试发送失败');
    header('Location: mail.php?section=ban_notice');
    exit;
}

$currentGroup = null;
foreach ($menu as $group) {
    if ($group['id'] === $activeSection) {
        $currentGroup = $group;
        break;
    }
}

$pageTitle = $currentGroup ? $currentGroup['label'] : '邮局管理';
$isOverview = $activeSection === 'overview';
$isMailConfig = $activeSection === 'config';
$isRegisterCode = $activeSection === 'register_code';
$isBroadcast = $activeSection === 'broadcast';
$isTargeted = $activeSection === 'targeted';
$isPasswordReset = $activeSection === 'password_reset';
$isAccountActivation = $activeSection === 'account_activation';
$isBanNotice = $activeSection === 'ban_notice';
$mailConfig = getMailSmtpConfig($pdo);
$mailConfigured = isMailConfigured($pdo);
$registerVerifyConfig = getRegisterVerifyConfig($pdo);
$broadcastConfig = getMailBroadcastConfig($pdo);
$broadcastJob = getMailBroadcastJob($pdo);
$broadcastRecipientCount = countMailBroadcastRecipients($pdo);
$targetedTemplates = getMailTargetedTemplates($pdo);
$passwordResetConfig = getPasswordResetConfig($pdo);
$accountActivationConfig = getAccountActivationConfig($pdo);
$activationPerPage = normalizeAccountActivationPageSize((int)($_GET['activation_per_page'] ?? $_POST['activation_per_page'] ?? 10));
$activationTotal = countAccountActivationCandidates($pdo);
$activationTotalPages = max(1, (int)ceil($activationTotal / $activationPerPage));
$activationPage = normalizeAccountActivationPage((int)($_GET['activation_page'] ?? $_POST['activation_page'] ?? 1));
if ($activationPage > $activationTotalPages) {
    $activationPage = $activationTotalPages;
}
$activationCandidates = listAccountActivationCandidates($pdo, $activationPage, $activationPerPage);
$activationBanTypes = accountActivationBanTypes();
$activationListUrl = accountActivationListUrl($activationPage, $activationPerPage);
$banNoticeConfig = getBanNoticeConfig($pdo);
$banNoticeActionLabels = banNoticeActionLabels();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> - 邮局管理 - 竹叶云控平台</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $themeAssetPrefix = '../'; include __DIR__ . '/../components/theme-head.php'; ?>

<?php include __DIR__ . '/../components/theme-dynamic.php'; ?>
</head>

<body class="bg-gray-100 text-gray-900">

<?php $adminNavActive = 'mail'; include __DIR__ . '/../components/admin-top-nav.php'; ?>

<main class="mx-auto max-w-screen-xl px-4 py-6">
    <div class="flex gap-4 items-start">
        <?php include __DIR__ . '/../components/admin-mail-sidebar.php'; ?>

        <section class="min-w-0 flex-1">
            <div class="rounded-lg border border-gray-200 bg-white overflow-hidden">
                <div class="border-b border-gray-100 px-4 py-2 text-sm font-semibold text-gray-700">
                    <?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?>
                </div>
                <?php if ($isOverview): ?>
                    <?php include __DIR__ . '/../components/admin-mail-panels/overview.php'; ?>
                <?php elseif ($isMailConfig): ?>
                    <?php include __DIR__ . '/../components/admin-mail-panels/mail_config.php'; ?>
                <?php elseif ($isRegisterCode): ?>
                    <?php include __DIR__ . '/../components/admin-mail-panels/register_code.php'; ?>
                <?php elseif ($isBroadcast): ?>
                    <?php include __DIR__ . '/../components/admin-mail-panels/broadcast.php'; ?>
                <?php elseif ($isTargeted): ?>
                    <?php include __DIR__ . '/../components/admin-mail-panels/targeted.php'; ?>
                <?php elseif ($isPasswordReset): ?>
                    <?php include __DIR__ . '/../components/admin-mail-panels/password_reset.php'; ?>
                <?php elseif ($isAccountActivation): ?>
                    <?php include __DIR__ . '/../components/admin-mail-panels/account_activation.php'; ?>
                <?php elseif ($isBanNotice): ?>
                    <?php include __DIR__ . '/../components/admin-mail-panels/ban_notice.php'; ?>
                <?php else: ?>
                    <?php
                    $panelTitle = $pageTitle;
                    include __DIR__ . '/../components/admin-mail-panels/mail_placeholder.php';
                    ?>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

</body>
</html>
