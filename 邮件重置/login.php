<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/includes/bootstrap.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$usernameValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $usernameValue = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');
    $pdo = getDB();
    $result = emailChangeAttemptLogin($pdo, $usernameValue, $password);
    if (!empty($result['ok'])) {
        header('Location: index.php');
        exit;
    }
    $error = (string)($result['message'] ?? '登录失败');
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>登录 - 修改邮箱</title>
<?php $emailResetUseCdn = true; include __DIR__ . '/includes/head.php'; ?>
</head>
<body class="er-body">
<div class="er-login-wrap">
    <div class="er-card">
        <div class="er-card__header">
            <h1 class="er-card__title">修改邮箱</h1>
            <p class="er-card__desc">请使用网站账号和密码登录后继续</p>
        </div>

        <?php if ($error !== ''): ?>
            <div class="er-alert er-alert--error">
                <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
            </div>
        <?php endif; ?>

        <form method="post" class="er-form er-form--spaced">
            <div>
                <label class="er-field__label" for="username">账号 / 邮箱</label>
                <input
                    type="text"
                    id="username"
                    name="username"
                    required
                    autocomplete="username"
                    value="<?= htmlspecialchars($usernameValue, ENT_QUOTES, 'UTF-8') ?>"
                    placeholder="请输入用户名或邮箱"
                    class="er-input"
                >
            </div>
            <div>
                <label class="er-field__label" for="password">密码</label>
                <input
                    type="password"
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                    placeholder="请输入密码"
                    class="er-input"
                >
            </div>
            <button type="submit" class="er-btn er-btn--primary er-btn--block">登录并继续</button>
        </form>

        <div class="er-card__footer">
            <a href="../index.php" class="er-link">返回网站首页</a>
            <span class="er-card__footer-sep">·</span>
            <a href="../login.php" class="er-link">主站登录</a>
        </div>
    </div>
</div>
<?php include __DIR__ . '/../components/theme-toggle-script.php'; ?>
</body>
</html>
