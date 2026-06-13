<?php
require_once __DIR__ . '/includes/config.php';
require_once __DIR__ . '/includes/session_auth.php';
require_once __DIR__ . '/includes/web_auth.php';

$message = '';
$error = '';
$done = isset($_GET['done']);
$config = mailServerLoadConfig();

if (mailServerIsInstalled()) {
    mailServerRequireLogin();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $existing = mailServerLoadConfig();
    $apiKey = trim((string)($_POST['api_key'] ?? ''));
    if ($apiKey === '' && ($existing['api_key'] ?? '') !== '') {
        $apiKey = $existing['api_key'];
    }
    if ($apiKey === '') {
        $apiKey = mailServerGenerateApiKey();
    }

    $smtpPassword = trim((string)($_POST['smtp_password'] ?? ''));
    if ($smtpPassword === '') {
        $smtpPassword = $existing['smtp']['password'] ?? '';
    }

    $config = mailServerNormalizeConfig([
        'installed' => true,
        'api_key' => $apiKey,
        'site_url' => $_POST['site_url'] ?? '',
        'smtp' => [
            'host' => $_POST['smtp_host'] ?? '127.0.0.1',
            'port' => $_POST['smtp_port'] ?? 587,
            'encryption' => $_POST['smtp_encryption'] ?? 'tls',
            'username' => $_POST['smtp_username'] ?? '',
            'password' => $smtpPassword,
            'from_email' => $_POST['smtp_from_email'] ?? '',
            'from_name' => $_POST['smtp_from_name'] ?? '竹叶云控邮局',
        ],
    ]);

    if ($config['site_url'] === '') {
        $error = '请填写关联网站地址';
    } elseif ($config['smtp']['host'] === '') {
        $error = '请填写 SMTP 服务器地址';
    } elseif ($config['smtp']['username'] === '') {
        $error = '请填写 SMTP 账号';
    } elseif ($config['smtp']['password'] === '') {
        $error = '请填写 SMTP 密码';
    } elseif (!filter_var($config['smtp']['from_email'], FILTER_VALIDATE_EMAIL)) {
        $error = '请填写有效的发件人邮箱';
    } else {
        mailServerSaveConfig($config);
        $message = '邮局配置已保存';
        $done = true;
    }
}

$smtp = $config['smtp'];
$hasPassword = ($smtp['password'] ?? '') !== '';
$apiEndpoints = mailServerPublicEndpoints();
$loggedInUser = mailServerCurrentUsername();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>竹叶云控独立邮局 - 安装配置</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <style>
        body { font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", sans-serif; background:#f3f4f6; margin:0; padding:24px; color:#111827; }
        .card { max-width:720px; margin:0 auto; background:#fff; border-radius:12px; box-shadow:0 10px 30px rgba(0,0,0,.08); overflow:hidden; }
        .head { padding:20px 24px; border-bottom:1px solid #e5e7eb; display:flex; justify-content:space-between; align-items:flex-start; gap:12px; }
        .body { padding:24px; }
        label { display:block; font-size:14px; font-weight:600; margin:12px 0 6px; }
        input, select { width:100%; box-sizing:border-box; padding:10px 12px; border:1px solid #d1d5db; border-radius:8px; font-size:14px; }
        .grid { display:grid; grid-template-columns:1fr 1fr; gap:12px; }
        .btn { margin-top:20px; background:#2563eb; color:#fff; border:0; padding:10px 16px; border-radius:8px; font-size:14px; cursor:pointer; }
        .alert { padding:12px 14px; border-radius:8px; margin-bottom:16px; font-size:14px; }
        .ok { background:#ecfdf5; color:#047857; }
        .err { background:#fef2f2; color:#b91c1c; }
        .hint { font-size:12px; color:#6b7280; margin-top:4px; }
        code { background:#f3f4f6; padding:2px 6px; border-radius:4px; font-size:12px; word-break:break-all; }
        ul { font-size:13px; line-height:1.7; color:#374151; }
        .logout { font-size:13px; color:#2563eb; text-decoration:none; white-space:nowrap; }
        .section { margin-top:8px; padding-top:8px; border-top:1px solid #f3f4f6; }
    </style>
</head>
<body>
<div class="card">
    <div class="head">
        <div>
            <h1 style="margin:0;font-size:22px;">竹叶云控独立邮局</h1>
            <p style="margin:8px 0 0;color:#6b7280;font-size:14px;">部署在邮局服务器上，通过 HTTP API 为网站提供发信能力。</p>
        </div>
        <?php if ($loggedInUser !== ''): ?>
        <div style="text-align:right;font-size:13px;color:#6b7280;">
            <?= htmlspecialchars($loggedInUser, ENT_QUOTES, 'UTF-8') ?>
            <br><a class="logout" href="logout.php">退出登录</a>
        </div>
        <?php endif; ?>
    </div>
    <div class="body">
        <?php if ($message): ?><div class="alert ok"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>
        <?php if ($error): ?><div class="alert err"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div><?php endif; ?>

        <?php if ($done && mailServerIsInstalled()): ?>
        <div class="alert ok">安装完成。请将下方 API 信息填入网站后台「邮局管理 → 邮局配置」。</div>
        <p><strong>API 根地址：</strong><code><?= htmlspecialchars($apiEndpoints['base'], ENT_QUOTES, 'UTF-8') ?></code></p>
        <p><strong>API 密钥：</strong><code><?= htmlspecialchars($config['api_key'], ENT_QUOTES, 'UTF-8') ?></code></p>
        <ul>
            <li>健康检查：<code>GET <?= htmlspecialchars($apiEndpoints['ping'], ENT_QUOTES, 'UTF-8') ?></code></li>
            <li>发送邮件：<code>POST <?= htmlspecialchars($apiEndpoints['send'], ENT_QUOTES, 'UTF-8') ?></code></li>
            <li>测试邮件：<code>POST <?= htmlspecialchars($apiEndpoints['test'], ENT_QUOTES, 'UTF-8') ?></code></li>
            <li>管理员校验：<code>POST <?= htmlspecialchars(mailServerSiteApiUrl('mail_admin_verify.php'), ENT_QUOTES, 'UTF-8') ?></code></li>
        </ul>
        <p class="hint">请求头：<code>X-Mail-Api-Key: 您的密钥</code>，或 <code>Authorization: Bearer 密钥</code></p>
        <p class="hint"><a href="login.php">前往登录</a> 后可再次修改配置（需先在网站后台填入相同 API 密钥）。</p>
        <?php endif; ?>

        <form method="POST">
            <div class="section">
                <label for="site_url">关联网站地址</label>
                <input type="url" name="site_url" id="site_url" value="<?= htmlspecialchars($config['site_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>" placeholder="https://www.example.com" required>
                <p class="hint">用于登录时通过 API 校验网站管理员账号，需与网站「邮局配置」中的 API 密钥一致。</p>
            </div>

            <label for="api_key">API 密钥（留空则自动生成）</label>
            <input type="text" name="api_key" id="api_key" value="<?= htmlspecialchars($config['api_key'], ENT_QUOTES, 'UTF-8') ?>" placeholder="自动生成">

            <label for="smtp_host">SMTP 服务器</label>
            <input type="text" name="smtp_host" id="smtp_host" value="<?= htmlspecialchars($smtp['host'], ENT_QUOTES, 'UTF-8') ?>" required>

            <div class="grid">
                <div>
                    <label for="smtp_port">端口</label>
                    <input type="number" name="smtp_port" id="smtp_port" value="<?= (int)$smtp['port'] ?>">
                </div>
                <div>
                    <label for="smtp_encryption">加密</label>
                    <select name="smtp_encryption" id="smtp_encryption">
                        <?php foreach (['tls' => 'TLS', 'ssl' => 'SSL', 'none' => '无'] as $v => $l): ?>
                        <option value="<?= $v ?>" <?= ($smtp['encryption'] ?? '') === $v ? 'selected' : '' ?>><?= $l ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <label for="smtp_username">SMTP 账号</label>
            <input type="text" name="smtp_username" id="smtp_username" value="<?= htmlspecialchars($smtp['username'], ENT_QUOTES, 'UTF-8') ?>">

            <label for="smtp_password">SMTP 密码</label>
            <input type="password" name="smtp_password" id="smtp_password" placeholder="<?= $hasPassword ? '留空保持不变' : '授权码或密码' ?>">

            <div class="grid">
                <div>
                    <label for="smtp_from_email">发件人邮箱</label>
                    <input type="email" name="smtp_from_email" id="smtp_from_email" value="<?= htmlspecialchars($smtp['from_email'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
                <div>
                    <label for="smtp_from_name">发件人名称</label>
                    <input type="text" name="smtp_from_name" id="smtp_from_name" value="<?= htmlspecialchars($smtp['from_name'], ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>

            <button type="submit" class="btn">保存邮局配置</button>
        </form>
    </div>
</div>
</body>
</html>
