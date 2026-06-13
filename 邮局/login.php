<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/session_auth.php';
require_once __DIR__ . '/includes/web_auth.php';

if (!mailServerIsInstalled()) {
    header('Location: install.php');
    exit;
}

if (mailServerIsLoggedIn()) {
    header('Location: install.php');
    exit;
}

$error = '';
$adminHint = mailServerFetchSiteAdminInfo();
$defaultUsername = !empty($adminHint['ok']) ? ($adminHint['username'] ?? 'admin') : 'admin';
$siteHint = mailServerSiteBaseUrl();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim((string)($_POST['username'] ?? ''));
    $password = (string)($_POST['password'] ?? '');

    if ($username === '' || $password === '') {
        $error = '请填写用户名和密码';
    } else {
        $result = mailServerVerifyAdminViaSite($username, $password);
        if (!empty($result['ok'])) {
            mailServerLogin($result['username'] ?? $username);
            mailServerRedirectAfterLogin();
        } else {
            $error = $result['message'] ?? '用户名或密码错误';
        }
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>竹叶云控独立邮局 - 管理员登录</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#111827; min-height:100vh; display:flex; align-items:center; justify-content:center; }
        .card { width:100%; max-width:420px; background:#fff; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.08); overflow:hidden; }
        .head { padding:24px 24px 0; text-align:center; }
        .body { padding:24px; }
        h1 { margin:0; font-size:22px; }
        .sub { margin:8px 0 0; color:#6b7280; font-size:14px; }
        label { display:block; font-size:14px; font-weight:600; margin:14px 0 6px; }
        input { width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; }
        .btn { width:100%; margin-top:20px; background:#2563eb; color:#fff; border:0; padding:11px 16px; border-radius:8px; font-size:14px; cursor:pointer; }
        .btn:hover { background:#1d4ed8; }
        .alert { padding:12px 14px; border-radius:8px; margin-bottom:12px; font-size:14px; background:#fef2f2; color:#b91c1c; }
        .hint { font-size:12px; color:#6b7280; margin-top:16px; line-height:1.6; }
        code { background:#f3f4f6; padding:2px 6px; border-radius:4px; font-size:12px; word-break:break-all; }
    </style>
</head>
<body>
<div class="card">
    <div class="head">
        <h1>独立邮局登录</h1>
        <p class="sub">使用与网站后台相同的管理员账号密码</p>
    </div>
    <div class="body">
        <?php if ($error): ?>
        <div class="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <form method="POST">
            <?php if (!empty($_GET['redirect'])): ?>
            <input type="hidden" name="redirect" value="<?= htmlspecialchars((string)$_GET['redirect'], ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>

            <label for="username">管理员账号</label>
            <input
                type="text"
                name="username"
                id="username"
                value="<?= htmlspecialchars($defaultUsername, ENT_QUOTES, 'UTF-8') ?>"
                autocomplete="username"
                required
            >

            <label for="password">密码</label>
            <input type="password" name="password" id="password" autocomplete="current-password" required>

            <button type="submit" class="btn">登录</button>
        </form>

        <p class="hint">
            登录凭据通过网站 API 校验，不会保存在邮局服务器上。
            <?php if ($siteHint !== ''): ?>
            <br>关联网站：<code><?= htmlspecialchars($siteHint, ENT_QUOTES, 'UTF-8') ?></code>
            <?php else: ?>
            <br>请先在安装配置中填写关联网站地址。
            <?php endif; ?>
            <?php if (empty($adminHint['ok']) && $siteHint !== ''): ?>
            <br><span style="color:#b45309;">无法获取网站管理员信息：<?= htmlspecialchars($adminHint['message'] ?? '请检查 API 密钥是否与网站一致', ENT_QUOTES, 'UTF-8') ?></span>
            <?php endif; ?>
        </p>
    </div>
</div>
</body>
</html>
