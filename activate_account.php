<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/account_activation.php';

$pdo = getDB();
$error = '';
$success = '';
applyFlash($success, $error);

$token = trim((string)($_GET['token'] ?? ''));
$submitted = isset($_GET['submitted']);
$tokenData = null;
$user = null;
$stage = '';
$banType = '';
$pageMessage = '';

if ($submitted) {
    // 邮箱已提交，等待用户查收确认邮件
} elseif ($token !== '') {
    $check = validateAccountActivationToken($pdo, $token);
    if (!empty($check['ok'])) {
        $tokenData = $check['token'];
        $user = $check['user'];
        $stage = (string)($tokenData['stage'] ?? '');
        $banType = (string)($tokenData['ban_type'] ?? '');

        if ($stage === 'verify') {
            $pageMessage = accountActivationVerifyPageMessage($banType);
        } elseif ($stage === 'activate') {
            $result = completeAccountActivation($pdo, $token);
            if (!empty($result['ok'])) {
                flashSet('success', $result['message']);
                header('Location: login.php');
                exit;
            }
            $error = $result['message'] ?? '激活失败';
        }
    } else {
        $error = $check['message'] ?? '激活链接无效';
    }
} else {
    $error = '缺少激活令牌';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && $token !== '' && $stage === 'verify') {
    $newEmail = trim((string)($_POST['new_email'] ?? ''));
    $result = submitAccountActivationEmail($pdo, $token, $newEmail);
    if (!empty($result['ok'])) {
        header('Location: activate_account.php?submitted=1');
        exit;
    }
    $error = $result['message'] ?? '提交失败';
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
    <meta charset="UTF-8">
    <title>账号激活 - 竹叶云控平台</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/components/theme-head.php'; ?>

    <?php include __DIR__ . '/components/theme-dynamic.php'; ?>
    <style>
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .fade-in-up { animation: fadeInUp 0.8s ease-out forwards; }
    </style>
</head>
<body class="bg-gray-100 text-gray-900 dark:text-gray-100">

<div class="flex min-h-screen items-center justify-center px-4">
    <div class="w-full max-w-md rounded-2xl bg-white/90 dark:bg-gray-800/85 backdrop-blur-md shadow-2xl fade-in-up">
        <div class="border-b border-gray-200/60 dark:border-gray-700 px-6 py-4 text-center">
            <h1 class="text-2xl font-semibold text-gray-800 dark:text-white">账号激活</h1>
            <?php if ($user && !$submitted): ?>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">
                账号：<?= htmlspecialchars($user['username'], ENT_QUOTES, 'UTF-8') ?>
            </p>
            <?php endif; ?>
        </div>

        <div class="px-6 py-6">
            <?php if ($error): ?>
            <div class="mb-4 rounded-lg bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-600 dark:text-red-400">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="mb-4 rounded-lg bg-green-50 dark:bg-green-900/30 px-4 py-3 text-sm text-green-600 dark:text-green-400">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <?php if ($submitted): ?>
            <p class="text-center text-sm text-gray-600 dark:text-gray-300 py-4">
                激活确认邮件已发送，请前往新邮箱查收并点击链接完成激活。
            </p>
            <?php elseif ($stage === 'verify' && $tokenData): ?>
            <p class="mb-4 text-sm text-gray-600 dark:text-gray-300"><?= htmlspecialchars($pageMessage, ENT_QUOTES, 'UTF-8') ?></p>
            <form method="POST" class="space-y-5">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">真实有效邮箱</label>
                    <input type="email" name="new_email" required
                           class="mt-2 w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 px-4 py-3 text-sm focus:border-red-500 focus:ring-2 focus:ring-red-500"
                           placeholder="请输入可正常收信的邮箱">
                </div>
                <button type="submit"
                        class="w-full rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                    提交并发送确认邮件
                </button>
            </form>
            <?php elseif (!$submitted && $token === ''): ?>
            <p class="text-center text-sm text-gray-500 py-4">
                <a href="login.php" class="text-red-600 hover:underline">返回登录</a>
            </p>
            <?php endif; ?>
        </div>

        <div class="border-t border-gray-200/60 dark:border-gray-700 px-6 py-4 text-center text-sm">
            <a href="login.php" class="text-red-600 hover:underline">返回登录</a>
        </div>
    </div>
</div>

<?php include __DIR__ . '/components/theme-toggle-script.php'; ?>
</body>
</html>
