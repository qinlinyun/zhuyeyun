<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/includes/email_change.php';
require_once __DIR__ . '/includes/bootstrap.php';

emailChangeRequireLogin();
$user = getCurrentUser();
$pdo = getDB();
ensureEmailChangeSchema($pdo);
$alreadyUsed = userEmailChangeUsed($user);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>修改邮箱 - 竹叶云控平台</title>
<?php $emailResetUseCdn = true; include __DIR__ . '/includes/head.php'; ?>
</head>
<body class="er-body">
<nav class="er-nav">
    <div class="er-nav__inner">
        <div class="er-nav__links">
            <a href="../index.php">首页</a>
            <a href="../profile.php"><?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?></a>
            <?php $href = 'logout.php'; include __DIR__ . '/../components/logout-nav-link.php'; ?>
            <?php include __DIR__ . '/../components/theme-toggle.php'; ?>
        </div>
    </div>
</nav>

<main class="er-main">
    <div class="er-card er-card--wide">
        <h1 class="er-page-title">修改邮箱</h1>
        <p class="er-page-desc">每个账号仅有一次修改邮箱的机会，提交成功后不可再次修改，请仔细核对。</p>

        <div id="pageAlert" class="er-alert er-hidden"></div>

        <div id="statusBox" class="er-status-box">
            <p class="er-status-box__title">账号状态</p>
            <p class="er-status-box__text" id="statusText">正在加载…</p>
        </div>

        <?php if ($alreadyUsed): ?>
            <div class="er-alert er-alert--warning er-section">
                您已使用过邮箱修改机会，当前邮箱为：<span class="er-text-strong"><?= htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') ?></span>
            </div>
            <div class="er-section">
                <a href="../profile.php" class="er-btn er-btn--secondary">返回个人中心</a>
            </div>
        <?php else: ?>
            <form id="changeEmailForm" class="er-form er-form--spaced">
                <div>
                    <label class="er-field__label" for="username">账号</label>
                    <input
                        type="text"
                        id="username"
                        name="username"
                        required
                        readonly
                        value="<?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?>"
                        class="er-input er-input--readonly"
                    >
                </div>
                <div>
                    <label class="er-field__label" for="email">新邮箱</label>
                    <input
                        type="email"
                        id="email"
                        name="email"
                        required
                        maxlength="100"
                        autocomplete="email"
                        placeholder="name@example.com"
                        class="er-input"
                    >
                    <p id="emailError" class="er-field__error er-hidden"></p>
                </div>
                <div class="er-actions">
                    <button type="submit" id="submitBtn" class="er-btn er-btn--primary">确认修改</button>
                    <button type="button" id="refreshBtn" class="er-btn er-btn--secondary">刷新状态</button>
                    <a href="../profile.php" class="er-text-muted er-link">返回</a>
                </div>
            </form>
        <?php endif; ?>
    </div>
</main>

<?php if (!$alreadyUsed): ?>
<script>
(function () {
    const form = document.getElementById('changeEmailForm');
    const usernameInput = document.getElementById('username');
    const emailInput = document.getElementById('email');
    const submitBtn = document.getElementById('submitBtn');
    const refreshBtn = document.getElementById('refreshBtn');
    const statusText = document.getElementById('statusText');
    const pageAlert = document.getElementById('pageAlert');
    const emailError = document.getElementById('emailError');
    let canChange = false;

    function showAlert(message, type) {
        pageAlert.textContent = message;
        pageAlert.className = 'er-alert ' + (type === 'ok' ? 'er-alert--success' : 'er-alert--error');
        pageAlert.classList.remove('er-hidden');
    }

    function hideAlert() {
        pageAlert.classList.add('er-hidden');
    }

    function setFieldError(message) {
        if (!message) {
            emailError.classList.add('er-hidden');
            emailError.textContent = '';
            return;
        }
        emailError.textContent = message;
        emailError.classList.remove('er-hidden');
    }

    async function loadStatus() {
        hideAlert();
        statusText.textContent = '正在查询账号信息…';
        submitBtn.disabled = true;

        try {
            const qs = new URLSearchParams({ username: usernameInput.value.trim() });
            const res = await fetch('api/change_email_status.php?' + qs.toString(), {
                credentials: 'same-origin',
            });
            const data = await res.json();
            if (!data.ok) {
                if (data.login_url) {
                    location.href = data.login_url;
                    return;
                }
                statusText.textContent = data.message || '查询失败';
                canChange = false;
                return;
            }

            canChange = !!data.can_change;
            if (canChange) {
                statusText.innerHTML = '当前邮箱：<span class="er-text-strong">' +
                    (data.masked_email || '未设置') + '</span> · 您仍可使用 <span class="er-text-success">1</span> 次修改机会';
                submitBtn.disabled = false;
            } else {
                statusText.innerHTML = '当前邮箱：<span class="er-text-strong">' +
                    (data.current_email || data.masked_email || '未设置') + '</span> · <span class="er-text-warning">修改机会已用完</span>';
                submitBtn.disabled = true;
            }
        } catch (e) {
            statusText.textContent = '无法连接服务器，请稍后重试';
            canChange = false;
        }
    }

    refreshBtn.addEventListener('click', loadStatus);

    form.addEventListener('submit', async function (e) {
        e.preventDefault();
        hideAlert();
        setFieldError('');

        if (!canChange) {
            showAlert('该账号已无法再次修改邮箱', 'err');
            return;
        }

        submitBtn.disabled = true;
        submitBtn.textContent = '提交中…';

        try {
            const body = new FormData();
            body.append('username', usernameInput.value.trim());
            body.append('email', emailInput.value.trim());

            const res = await fetch('api/change_email.php', {
                method: 'POST',
                body,
                credentials: 'same-origin',
            });
            const data = await res.json();

            if (data.login_url) {
                location.href = data.login_url;
                return;
            }

            if (data.ok) {
                showAlert(data.message || '邮箱修改成功', 'ok');
                canChange = false;
                emailInput.value = '';
                await loadStatus();
            } else {
                showAlert(data.message || '修改失败', 'err');
                const fieldErrors = data.field_errors || {};
                if (fieldErrors.email) {
                    setFieldError(fieldErrors.email);
                }
                submitBtn.disabled = !canChange;
            }
        } catch (err) {
            showAlert('请求失败，请稍后重试', 'err');
            submitBtn.disabled = !canChange;
        } finally {
            submitBtn.textContent = '确认修改';
        }
    });

    loadStatus();
})();
</script>
<?php endif; ?>
<?php include __DIR__ . '/../components/theme-toggle-script.php'; ?>
</body>
</html>
