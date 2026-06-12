<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/password_reset.php';

if (isLoggedIn()) {
    header('Location: /');
    exit;
}

$pdo = getDB();
$error = '';
$success = '';
applyFlash($success, $error);

$available = passwordResetAvailable($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!$available) {
        $error = '密码重置功能未开启';
    } else {
        $email = trim((string)($_POST['email'] ?? ''));
        $result = requestPasswordReset($pdo, $email);
        if (!empty($result['ok'])) {
            flashSet('success', $result['message']);
        } else {
            flashSet('error', $result['message'] ?? '请求失败');
        }
        header('Location: forgot_password.php');
        exit;
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
    <meta charset="UTF-8">
    <title>忘记密码 - 竹叶云控平台</title>
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
            <h1 class="text-2xl font-semibold text-gray-800 dark:text-white">忘记密码</h1>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">输入注册邮箱，我们将发送重置链接</p>
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

            <?php if (!$available): ?>
            <p class="text-center text-sm text-gray-500 py-6">密码重置功能暂未开放，请联系管理员。</p>
            <?php else: ?>
            <form method="POST" class="space-y-5">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">注册邮箱</label>
                    <input type="email" name="email" required
                           class="mt-2 w-full rounded-lg border border-gray-300 dark:border-gray-600 bg-gray-50 dark:bg-gray-700 px-4 py-3 text-sm focus:border-red-500 focus:ring-2 focus:ring-red-500">
                </div>
                <button type="submit"
                        class="w-full rounded-lg bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">
                    发送重置链接
                </button>
            </form>
            <?php endif; ?>
        </div>

        <div class="border-t border-gray-200/60 dark:border-gray-700 px-6 py-4 text-center text-sm">
            <a href="login.php" class="text-red-600 hover:underline">返回登录</a>
            <div class="mt-3 flex justify-center">
                <?php include __DIR__ . '/components/theme-toggle.php'; ?>
            </div>
        </div>
    </div>
</div>

<?php include __DIR__ . '/components/theme-toggle-script.php'; ?>
</body>
</html>
