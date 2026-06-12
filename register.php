<?php
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/traffic.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/settings.php';
require_once __DIR__ . '/includes/user_growth_analytics.php';
require_once __DIR__ . '/includes/register_verify.php';
require_once __DIR__ . '/includes/register_submit.php';

if (isLoggedIn()) {
    header('Location: index.php');
    exit;
}

$error = '';
$success = '';
applyFlash($success, $error);

$pdo = getDB();
$registerFieldErrors = [];
$registerPost = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $wantsJson = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '')) === 'xmlhttprequest'
        || str_contains((string)($_SERVER['HTTP_ACCEPT'] ?? ''), 'application/json');

    $result = processRegisterSubmission($pdo, $_POST);

    if (!empty($result['ok'])) {
        flashSet('success', '注册成功，请登录');
        if ($wantsJson) {
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => true,
                'redirect' => $result['redirect'] ?? 'login.php',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: ' . ($result['redirect'] ?? 'login.php'));
        exit;
    }

    if ($wantsJson) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode([
            'ok' => false,
            'field_errors' => $result['field_errors'] ?? [],
            'message' => $result['message'] ?? '',
        ], JSON_UNESCAPED_UNICODE);
        exit;
    }

    $registerFieldErrors = $result['field_errors'] ?? [];
    $registerPost = $_POST;
    if (!empty($result['message'])) {
        $error = (string)$result['message'];
    } elseif ($registerFieldErrors !== []) {
        $error = '请根据下方提示修正后重试';
    } else {
        $error = '注册失败，请稍后重试';
    }
}

$registerMasterEnabled = isRegisterMasterEnabled($pdo);
$registerEnabled = isRegisterEnabled($pdo);
$scheduleBlocking = isRegisterScheduleBlocking($pdo);
$scheduleConfig = getRegisterScheduleConfig($pdo);
$scheduleReopenAt = $scheduleBlocking ? getRegisterScheduleReopenTimestamp($scheduleConfig) : null;
$registerPageConfig = getRegisterPageConfig($pdo);
$closedPageConfig = getRegisterClosedPageConfig($pdo);
$usernameHint = registerPageUsernameHint($registerPageConfig);
$passwordHint = registerPagePasswordHint($registerPageConfig);
$registerVerifyEnabled = isRegisterVerifyEnabled($pdo);
$registerVerifyConfig = getRegisterVerifyConfig($pdo);
$mailConfigured = isMailConfigured($pdo);
$showScheduleCountdown = $scheduleBlocking && $scheduleReopenAt !== null;
$showRegisterForm = $registerMasterEnabled
    && !$scheduleBlocking
    && !($registerVerifyEnabled && !$mailConfigured);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
    <meta charset="UTF-8">
    <title>注册 - 竹叶云控平台</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/components/theme-head.php'; ?>

    <?php include __DIR__ . '/components/theme-dynamic.php'; ?>

    <style>
        @keyframes fadeInUp {
            0% { opacity: 0; transform: translateY(30px); }
            100% { opacity: 1; transform: translateY(0); }
        }
        .fade-in-up { animation: fadeInUp 0.8s ease-out forwards; }
        @keyframes zoomIn {
            0% { transform: scale(0.8); opacity: 0; }
            100% { transform: scale(1); opacity: 1; }
        }
        .zoom-in { animation: zoomIn 0.6s ease-out forwards; }
        @keyframes registerHeroSlideOut {
            0% {
                opacity: 0;
                transform: translateX(120px) scaleX(.88) rotateY(-4deg) rotateZ(-.6deg);
                clip-path: inset(0 0 0 100% round 28px 0 0 28px);
                filter: blur(10px);
            }
            55% {
                opacity: 1;
                filter: blur(2px);
            }
            100% {
                opacity: 1;
                transform: translateX(0) scaleX(1) rotateY(-4deg) rotateZ(-.6deg);
                clip-path: inset(0 0 0 0 round 28px 0 0 28px);
                filter: blur(0);
            }
        }
        @keyframes registerPanelSettle {
            0% { transform: translateX(-18px); }
            100% { transform: translateX(0); }
        }
        @media (prefers-reduced-motion: reduce) {
            .fade-in-up,
            .zoom-in,
            .register-hero,
            .register-panel { animation: none !important; }
        }

        .register-shell {
            position: relative;
            z-index: 10;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 32px 16px;
            overflow: hidden;
        }

        .register-bg {
            position: fixed;
            inset: 0;
            z-index: -10;
            overflow: hidden;
            background:
                radial-gradient(circle at 12% 12%, rgba(14, 165, 233, .20), transparent 30%),
                radial-gradient(circle at 88% 20%, rgba(239, 68, 68, .14), transparent 32%),
                radial-gradient(circle at 58% 92%, rgba(16, 185, 129, .12), transparent 34%),
                linear-gradient(135deg, #f8fafc 0%, #ffffff 45%, #f1f5f9 100%);
        }

        .dark .register-bg {
            background:
                radial-gradient(circle at 12% 12%, rgba(14, 165, 233, .20), transparent 30%),
                radial-gradient(circle at 88% 20%, rgba(239, 68, 68, .18), transparent 32%),
                radial-gradient(circle at 58% 92%, rgba(16, 185, 129, .12), transparent 34%),
                linear-gradient(135deg, #0f172a 0%, #111827 45%, #020617 100%);
        }

        .register-card {
            width: min(100%, 980px);
            display: grid;
            grid-template-columns: minmax(0, 1fr) minmax(420px, .98fr);
            align-items: center;
            overflow: visible;
            border-radius: 30px;
            background: transparent;
            filter: drop-shadow(0 30px 70px rgba(15, 23, 42, .16));
            perspective: 1200px;
        }

        .dark .register-card {
            filter: drop-shadow(0 30px 80px rgba(0, 0, 0, .40));
        }

        .register-hero {
            position: relative;
            z-index: 1;
            transform-origin: right center;
            transform: rotateY(-4deg) rotateZ(-.6deg);
            min-height: 730px;
            padding: 34px;
            color: #fff;
            border-radius: 30px;
            overflow: hidden;
            background:
                linear-gradient(135deg, rgba(15, 23, 42, .90), rgba(185, 28, 28, .80)),
                radial-gradient(circle at 20% 20%, rgba(255, 255, 255, .22), transparent 34%);
            animation: registerHeroSlideOut .95s cubic-bezier(.16, 1, .3, 1) .08s both;
            will-change: transform, opacity, clip-path, filter;
            box-shadow: 0 18px 50px rgba(15, 23, 42, .18);
            transition:
                transform 0.9s cubic-bezier(0.22, 1, 0.36, 1),
                box-shadow 0.9s cubic-bezier(0.22, 1, 0.36, 1),
                filter 0.9s cubic-bezier(0.22, 1, 0.36, 1);
        }

        .register-hero::before {
            content: "";
            position: absolute;
            inset: 0;
            border-radius: inherit;
            background: radial-gradient(ellipse 80% 55% at 18% 12%, rgba(255, 255, 255, .16), transparent 62%);
            opacity: 0;
            transition: opacity 1s cubic-bezier(0.22, 1, 0.36, 1);
            pointer-events: none;
            z-index: 0;
        }

        @media (hover: hover) and (pointer: fine) {
            .register-hero:hover {
                z-index: 8;
                transform: rotateY(-1.5deg) rotateZ(-.12deg) translateX(-14px) translateY(-5px);
                filter: brightness(1.04);
                box-shadow:
                    0 28px 64px rgba(15, 23, 42, .26),
                    0 0 0 1px rgba(255, 255, 255, .10);
            }

            .register-hero:hover::before {
                opacity: 1;
            }

            .register-card:has(.register-hero:hover) .register-panel {
                transform: translateX(26px);
                opacity: 0.94;
                transition-delay: 0.08s;
            }
        }

        .register-hero::after {
            content: "";
            position: absolute;
            inset: auto -70px -90px auto;
            width: 240px;
            height: 240px;
            border-radius: 999px;
            background: rgba(255, 255, 255, .14);
            filter: blur(8px);
        }

        .register-logo {
            position: relative;
            z-index: 1;
            width: 56px;
            height: 56px;
            display: block;
            border-radius: 18px;
            background: rgba(255, 255, 255, .12);
            border: 1px solid rgba(255, 255, 255, .28);
            box-shadow: inset 0 1px 0 rgba(255, 255, 255, .25);
            overflow: hidden;
            pointer-events: none;
        }

        .register-logo svg {
            display: block;
            width: 100% !important;
            height: 100% !important;
        }

        .register-hero-title {
            margin-top: 28px;
            font-size: 34px;
            line-height: 1.15;
            font-weight: 800;
            letter-spacing: -.04em;
        }

        .register-hero-desc {
            margin-top: 14px;
            max-width: 310px;
            color: rgba(255, 255, 255, .76);
            font-size: 14px;
            line-height: 1.8;
        }

        .register-feature-list {
            position: relative;
            z-index: 1;
            margin-top: 34px;
            display: grid;
            gap: 12px;
            font-size: 13px;
            color: rgba(255, 255, 255, .84);
        }

        .register-feature-list span {
            display: flex;
            align-items: center;
            gap: 10px;
        }

        .register-feature-list i {
            width: 8px;
            height: 8px;
            border-radius: 999px;
            background: #fca5a5;
            box-shadow: 0 0 0 5px rgba(252, 165, 165, .12);
        }

        .register-panel {
            position: relative;
            z-index: 3;
            min-width: 0;
            margin-left: -82px;
            border-radius: 26px;
            border: 1px solid rgba(255, 255, 255, .70);
            background: rgba(255, 255, 255, .88);
            box-shadow: 0 24px 70px rgba(15, 23, 42, .22);
            overflow: hidden;
            backdrop-filter: blur(18px);
            -webkit-backdrop-filter: blur(18px);
            animation: registerPanelSettle .7s cubic-bezier(.16, 1, .3, 1) .08s both;
            transition:
                transform 0.95s cubic-bezier(0.22, 1, 0.36, 1),
                opacity 0.95s cubic-bezier(0.22, 1, 0.36, 1);
        }

        .dark .register-panel {
            border-color: rgba(255, 255, 255, .12);
            background: rgba(30, 41, 59, .88);
            box-shadow: 0 24px 70px rgba(0, 0, 0, .38);
        }

        .register-panel input {
            box-shadow: 0 1px 0 rgba(255, 255, 255, .60) inset, 0 10px 24px rgba(15, 23, 42, .04);
        }

        .register-panel input:focus {
            outline: none;
            box-shadow: 0 0 0 4px rgba(239, 68, 68, .15), 0 10px 24px rgba(15, 23, 42, .06);
        }

        .register-submit {
            box-shadow: 0 14px 32px rgba(220, 38, 38, .28);
        }

        @media (max-width: 860px) {
            .register-card {
                grid-template-columns: 1fr;
                width: min(100%, 480px);
                filter: drop-shadow(0 24px 60px rgba(15, 23, 42, .18));
            }

            .register-hero {
                min-height: auto;
                padding: 26px 24px;
                border-radius: 26px 26px 0 0;
                animation: fadeInUp .65s ease-out both;
            }

            .register-panel {
                margin-left: 0;
                margin-top: -18px;
                border-radius: 26px;
            }

            .register-hero-title {
                margin-top: 18px;
                font-size: 28px;
            }

            .register-feature-list {
                display: none;
            }
        }
    </style>
</head>

<body class="bg-gray-100 dark:bg-gray-900 text-gray-900 dark:text-gray-100">

<div class="register-bg" aria-hidden="true"></div>

<div class="register-shell">
    <div class="register-card fade-in-up">
        <aside class="register-hero">
            <div class="register-logo" id="registerLogoLottie" role="img" aria-label="平台标识"></div>
            <h2 class="register-hero-title">视频管理平台</h2>
            <p class="register-hero-desc">
                支持用户上传视频
            </p>
            <div class="register-feature-list" aria-hidden="true">
                <span><i></i> 全局主题与字体统一管理</span>
                <span><i></i> 邮箱验证与账户安全策略</span>
                <span><i></i> 视频、通知、流量能力一站式接入</span>
            </div>
        </aside>

        <section class="register-panel">

        <div class="border-b border-gray-200 dark:border-gray-700 px-6 py-4 text-center">
            <h1 class="text-3xl font-semibold text-gray-800 dark:text-white zoom-in">创建账户</h1>
            <p class="mt-2 text-lg text-gray-500 dark:text-gray-400">加入竹叶云控平台</p>
        </div>

        <div class="px-6 py-6">
            <?php if ($error): ?>
                <div class="mb-4 rounded-lg bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-600 dark:text-red-400">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
                <div class="mb-4 rounded-lg bg-green-50 dark:bg-green-900/30 px-4 py-3 text-sm text-green-600 dark:text-green-400">
                    <?= htmlspecialchars($success) ?>
                </div>
            <?php endif; ?>

            <?php if ($showScheduleCountdown): ?>
                <div class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/30 px-4 py-8 text-center">
                    <p class="text-sm font-medium text-amber-800 dark:text-amber-200">注册功能暂时关闭</p>
                    <p class="mt-3 text-xs text-amber-700 dark:text-amber-300"><?= htmlspecialchars(registerSchedulePeriodLabel($scheduleConfig)) ?></p>
                    <p class="mt-2 text-xs text-amber-600 dark:text-amber-400">将于 <?= htmlspecialchars(formatScheduleDatetimeDisplay($scheduleConfig['open_at'])) ?> 自动开启注册</p>
                    <p class="mt-4 text-sm text-amber-900 dark:text-amber-100">
                        距离开放还有 <span id="registerScheduleCountdown" class="font-semibold tabular-nums">--</span>
                    </p>
                </div>
            <?php elseif (!$registerMasterEnabled): ?>
                <div class="rounded-lg bg-gray-50 dark:bg-gray-700/50 px-4 py-8">
                    <?php include __DIR__ . '/components/register-closed-content.php'; ?>
                </div>
            <?php elseif ($scheduleBlocking): ?>
                <div class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/30 px-4 py-8 text-center">
                    <p class="text-sm font-medium text-amber-800 dark:text-amber-200">注册功能暂时关闭</p>
                    <p class="mt-2 text-xs text-amber-700 dark:text-amber-300"><?= htmlspecialchars(registerSchedulePeriodLabel($scheduleConfig)) ?></p>
                </div>
            <?php elseif ($registerVerifyEnabled && !$mailConfigured): ?>
                <div class="rounded-lg border border-amber-200 bg-amber-50 dark:border-amber-800 dark:bg-amber-900/30 px-4 py-8 text-center">
                    <p class="text-sm text-amber-800 dark:text-amber-200">注册邮箱验证已开启，但邮件服务尚未配置，暂无法注册。</p>
                    <p class="mt-2 text-xs text-amber-700 dark:text-amber-300">请联系管理员完成邮局 SMTP 配置。</p>
                </div>
            <?php elseif ($showRegisterForm): ?>
            <div id="registerFormMessage" class="mb-4 hidden rounded-lg bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-600 dark:text-red-400" role="alert"></div>
            <form method="POST" action="register.php" class="space-y-6" id="registerForm" novalidate data-ajax="0">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300" for="registerUsername">用户名</label>
                    <?php if ($usernameHint): ?>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($usernameHint) ?></p>
                    <?php endif; ?>
                    <input id="registerUsername" name="username" required minlength="<?= !empty($registerPageConfig['username_restrict_enabled']) ? max(5, (int)$registerPageConfig['username_min_length']) : 1 ?>"
                           pattern="<?= !empty($registerPageConfig['username_restrict_enabled']) ? '[A-Za-z0-9]+' : '.*' ?>"
                           value="<?= htmlspecialchars((string)($registerPost['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           class="mt-2 w-full rounded-lg border <?= registerFieldErrorClass('username', $registerFieldErrors) ?> bg-gray-50 dark:bg-gray-700 px-4 py-3 text-sm focus:border-red-500 focus:ring-2 focus:ring-red-500 transition duration-200 ease-in-out zoom-in">
                    <?= registerFieldErrorHtml('username', $registerFieldErrors) ?>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300" for="registerEmail">邮箱</label>
                    <div class="mt-2 flex gap-2">
                        <input type="email" name="email" id="registerEmail" required
                               value="<?= htmlspecialchars((string)($registerPost['email'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                               class="min-w-0 flex-1 rounded-lg border <?= registerFieldErrorClass('email', $registerFieldErrors) ?> bg-gray-50 dark:bg-gray-700 px-4 py-3 text-sm focus:border-red-500 focus:ring-2 focus:ring-red-500 transition duration-200 ease-in-out zoom-in">
                        <?php if ($registerVerifyEnabled): ?>
                        <button type="button" id="sendVerifyCodeBtn"
                                class="shrink-0 rounded-lg border border-red-300 bg-white px-3 py-2 text-xs font-medium text-red-600 hover:bg-red-50 dark:border-red-700 dark:bg-gray-700 dark:text-red-400 dark:hover:bg-gray-600">
                            发送验证码
                        </button>
                        <?php endif; ?>
                    </div>
                    <?= registerFieldErrorHtml('email', $registerFieldErrors) ?>
                    <?php if ($registerVerifyEnabled): ?>
                    <p id="sendVerifyCodeHint" class="mt-1 text-xs text-gray-500 dark:text-gray-400"></p>
                    <?php endif; ?>
                </div>

                <?php if ($registerVerifyEnabled): ?>
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300" for="verifyCode">邮箱验证码</label>
                    <input type="text" name="verify_code" id="verifyCode" required maxlength="6" pattern="\d{6}"
                           inputmode="numeric" autocomplete="one-time-code"
                           placeholder="6 位数字验证码"
                           value="<?= htmlspecialchars((string)($registerPost['verify_code'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                           class="mt-2 w-full rounded-lg border <?= registerFieldErrorClass('verify_code', $registerFieldErrors) ?> bg-gray-50 dark:bg-gray-700 px-4 py-3 text-sm tracking-widest focus:border-red-500 focus:ring-2 focus:ring-red-500 transition duration-200 ease-in-out zoom-in">
                    <?= registerFieldErrorHtml('verify_code', $registerFieldErrors) ?>
                </div>
                <?php endif; ?>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300" for="registerPassword">密码</label>
                    <?php if ($passwordHint): ?>
                    <p class="mt-1 text-xs text-gray-500 dark:text-gray-400"><?= htmlspecialchars($passwordHint) ?></p>
                    <?php endif; ?>
                    <input type="password" name="password" id="registerPassword" required minlength="<?= !empty($registerPageConfig['password_strong_enabled']) ? 8 : 6 ?>"
                           class="mt-2 w-full rounded-lg border <?= registerFieldErrorClass('password', $registerFieldErrors) ?> bg-gray-50 dark:bg-gray-700 px-4 py-3 text-sm focus:border-red-500 focus:ring-2 focus:ring-red-500 transition duration-200 ease-in-out zoom-in">
                    <?= registerFieldErrorHtml('password', $registerFieldErrors) ?>
                </div>

                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300" for="registerConfirm">确认密码</label>
                    <input type="password" name="confirm" id="registerConfirm" required minlength="<?= !empty($registerPageConfig['password_strong_enabled']) ? 8 : 6 ?>"
                           class="mt-2 w-full rounded-lg border <?= registerFieldErrorClass('confirm', $registerFieldErrors) ?> bg-gray-50 dark:bg-gray-700 px-4 py-3 text-sm focus:border-red-500 focus:ring-2 focus:ring-red-500 transition duration-200 ease-in-out zoom-in">
                    <?= registerFieldErrorHtml('confirm', $registerFieldErrors) ?>
                </div>

                <button type="submit" id="registerSubmitBtn"
                        class="register-submit w-full rounded-lg bg-red-600 px-4 py-3 text-sm font-semibold text-white hover:bg-red-700 focus:ring-4 focus:ring-red-300 transition duration-300 zoom-in disabled:cursor-not-allowed disabled:opacity-60">
                    注册
                </button>
            </form>
            <?php else: ?>
                <div class="rounded-lg bg-gray-50 dark:bg-gray-700/50 px-4 py-8">
                    <?php include __DIR__ . '/components/register-closed-content.php'; ?>
                </div>
            <?php endif; ?>
        </div>

        <div class="border-t border-gray-200 dark:border-gray-700 px-6 py-4 text-center text-sm">
            <span class="text-gray-500 dark:text-gray-400">已有账号？</span>
            <a href="login.php" class="text-red-600 hover:underline">立即登录</a>
            <div class="mt-3 flex justify-center">
                <?php include __DIR__ . '/components/theme-toggle.php'; ?>
            </div>
        </div>
        </section>
    </div>
</div>

<?php if ($showRegisterForm && $registerVerifyEnabled): ?>
<div id="verifyCodeSentModal" class="fixed inset-0 z-[110] hidden items-center justify-center bg-black/50 px-4 py-6" role="dialog" aria-modal="true" aria-labelledby="verifyCodeSentModalTitle">
    <div class="w-full max-w-md overflow-hidden rounded-2xl bg-white shadow-2xl dark:bg-gray-800">
        <div class="px-6 py-5">
            <h3 id="verifyCodeSentModalTitle" class="text-base font-semibold text-gray-900 dark:text-gray-100">验证码已发送</h3>
            <p class="mt-3 text-sm leading-6 text-gray-600 dark:text-gray-300">
                如果没有收到验证码，请到<strong class="font-medium text-gray-800 dark:text-gray-100">垃圾邮箱</strong>或<strong class="font-medium text-gray-800 dark:text-gray-100">垃圾邮件</strong>里查看。
            </p>
        </div>
        <div class="border-t border-gray-100 px-6 py-4 flex justify-end dark:border-gray-700">
            <button type="button" id="verifyCodeSentModalCloseBtn"
                    class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                我知道了
            </button>
        </div>
    </div>
</div>
<?php endif; ?>

<?php include __DIR__ . '/components/theme-toggle-script.php'; ?>
<?php if ($showScheduleCountdown): ?>
<script>
(function () {
    var reopenAt = <?= (int)$scheduleReopenAt ?> * 1000;
    var el = document.getElementById('registerScheduleCountdown');
    if (!el) return;

    function pad(n) { return n < 10 ? '0' + n : String(n); }

    function formatRemaining(ms) {
        if (ms <= 0) return '0秒';
        var total = Math.floor(ms / 1000);
        var days = Math.floor(total / 86400);
        total %= 86400;
        var hours = Math.floor(total / 3600);
        total %= 3600;
        var minutes = Math.floor(total / 60);
        var seconds = total % 60;
        var parts = [];
        if (days > 0) parts.push(days + '天');
        if (hours > 0 || days > 0) parts.push(hours + '小时');
        if (minutes > 0 || hours > 0 || days > 0) parts.push(minutes + '分钟');
        parts.push(seconds + '秒');
        return parts.join('');
    }

    function tick() {
        var diff = reopenAt - Date.now();
        if (diff <= 0) {
            el.textContent = '0秒';
            window.location.reload();
            return;
        }
        el.textContent = formatRemaining(diff);
    }

    tick();
    setInterval(tick, 1000);
})();
</script>
<?php endif; ?>
<script src="https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function () {
    var el = document.getElementById('registerLogoLottie');
    if (!el || typeof lottie === 'undefined') return;

    var anim = lottie.loadAnimation({
        container: el,
        renderer: 'svg',
        loop: true,
        autoplay: true,
        path: 'https://ac.901235.xyz/ico-1.json'
    });

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        anim.addEventListener('DOMLoaded', function () {
            anim.goToAndStop(anim.totalFrames - 1, true);
            anim.pause();
        });
    }
})();
</script>
<?php if ($showRegisterForm): ?>
<script src="assets/js/register-form.js?v=1"></script>
<?php endif; ?>
<?php if ($showRegisterForm && $registerVerifyEnabled): ?>
<script>
(function () {
    const btn = document.getElementById('sendVerifyCodeBtn');
    const emailInput = document.getElementById('registerEmail');
    const hint = document.getElementById('sendVerifyCodeHint');
    const resendInterval = <?= (int)$registerVerifyConfig['resend_interval'] ?>;
    const STORAGE_KEY = 'phpy_register_verify_cd';
    if (!btn || !emailInput) return;

    let timer = null;
    let cooldownUntil = 0;

    function setHint(text, isError) {
        if (!hint) return;
        hint.textContent = text || '';
        hint.classList.toggle('text-red-500', !!isError);
        hint.classList.toggle('text-gray-500', !isError);
        hint.classList.toggle('dark:text-red-400', !!isError);
        hint.classList.toggle('dark:text-gray-400', !isError);
    }

    function remainSeconds() {
        if (!cooldownUntil) return 0;
        return Math.max(0, Math.ceil((cooldownUntil - Date.now()) / 1000));
    }

    function updateBtn() {
        const remain = remainSeconds();
        if (remain > 0) {
            btn.disabled = true;
            btn.textContent = remain + 's 后重发';
        } else {
            btn.disabled = false;
            btn.textContent = '发送验证码';
        }
    }

    function saveCooldown(email, seconds) {
        const sec = Math.max(0, parseInt(seconds, 10) || 0);
        if (sec <= 0 || !email) return;
        cooldownUntil = Date.now() + sec * 1000;
        try {
            localStorage.setItem(STORAGE_KEY, JSON.stringify({
                email: email,
                until: cooldownUntil
            }));
        } catch (e) {}
        updateBtn();
    }

    function loadStoredCooldown(email) {
        try {
            const raw = localStorage.getItem(STORAGE_KEY);
            if (!raw) return 0;
            const data = JSON.parse(raw);
            if (!data || data.email !== email || !data.until) return 0;
            const remain = Math.max(0, Math.ceil((data.until - Date.now()) / 1000));
            if (remain > 0) {
                cooldownUntil = data.until;
            }
            return remain;
        } catch (e) {
            return 0;
        }
    }

    function startCountdownTimer() {
        if (timer) clearInterval(timer);
        updateBtn();
        timer = setInterval(function () {
            updateBtn();
            if (remainSeconds() <= 0) {
                clearInterval(timer);
                timer = null;
            }
        }, 1000);
    }

    function applyResendHint(data) {
        if (!data) return;
        const left = typeof data.resend_remaining === 'number' ? data.resend_remaining : null;
        if (left !== null && left >= 0 && remainSeconds() <= 0) {
            setHint('还可重发 ' + left + ' 次', false);
        }
    }

    const verifyCodeSentModal = document.getElementById('verifyCodeSentModal');
    const verifyCodeSentModalCloseBtn = document.getElementById('verifyCodeSentModalCloseBtn');

    function showVerifyCodeSentModal() {
        if (!verifyCodeSentModal) return;
        verifyCodeSentModal.classList.remove('hidden');
        verifyCodeSentModal.classList.add('flex');
    }

    function hideVerifyCodeSentModal() {
        if (!verifyCodeSentModal) return;
        verifyCodeSentModal.classList.add('hidden');
        verifyCodeSentModal.classList.remove('flex');
    }

    if (verifyCodeSentModalCloseBtn) {
        verifyCodeSentModalCloseBtn.addEventListener('click', hideVerifyCodeSentModal);
    }
    if (verifyCodeSentModal) {
        verifyCodeSentModal.addEventListener('click', function (e) {
            if (e.target === verifyCodeSentModal) hideVerifyCodeSentModal();
        });
    }

    function syncCooldownFromServer(email, silent) {
        if (!email) return Promise.resolve();

        const body = new URLSearchParams();
        body.set('email', email);

        return fetch('api/register_send_code_status.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.ok) {
                    if (!silent && data.message) setHint(data.message, true);
                    return;
                }
                if (data.retry_after > 0) {
                    saveCooldown(email, data.retry_after);
                    startCountdownTimer();
                } else {
                    loadStoredCooldown(email);
                    if (remainSeconds() > 0) {
                        startCountdownTimer();
                    } else {
                        cooldownUntil = 0;
                        updateBtn();
                    }
                }
                applyResendHint(data);
            })
            .catch(function () {
                const stored = loadStoredCooldown(email);
                if (stored > 0) {
                    startCountdownTimer();
                }
            });
    }

    btn.addEventListener('click', function () {
        const email = emailInput.value.trim();
        if (!email) {
            setHint('请先填写邮箱', true);
            emailInput.focus();
            return;
        }

        btn.disabled = true;
        btn.textContent = '发送中…';
        setHint('', false);

        const body = new URLSearchParams();
        body.set('email', email);

        fetch('api/register_send_code.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: body.toString()
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data.ok) {
                    const wait = data.retry_after || resendInterval;
                    saveCooldown(email, wait);
                    startCountdownTimer();
                    let msg = data.message || '验证码已发送';
                    if (typeof data.resend_remaining === 'number') {
                        msg += '（还可重发 ' + data.resend_remaining + ' 次）';
                    }
                    setHint(msg, false);
                    showVerifyCodeSentModal();
                    return;
                }
                setHint(data.message || '发送失败', true);
                if (data.retry_after) {
                    saveCooldown(email, data.retry_after);
                    startCountdownTimer();
                } else {
                    cooldownUntil = 0;
                    updateBtn();
                }
                applyResendHint(data);
            })
            .catch(function () {
                setHint('网络错误，请稍后重试', true);
                cooldownUntil = 0;
                updateBtn();
            });
    });

    emailInput.addEventListener('change', function () {
        cooldownUntil = 0;
        syncCooldownFromServer(emailInput.value.trim(), true);
    });
    emailInput.addEventListener('blur', function () {
        syncCooldownFromServer(emailInput.value.trim(), true);
    });

    document.addEventListener('visibilitychange', function () {
        if (document.hidden) return;
        const email = emailInput.value.trim();
        if (!email) return;
        loadStoredCooldown(email);
        updateBtn();
        syncCooldownFromServer(email, true);
    });

    window.addEventListener('pageshow', function () {
        const email = emailInput.value.trim();
        if (!email) return;
        loadStoredCooldown(email);
        updateBtn();
        syncCooldownFromServer(email, true);
    });

    const initialEmail = emailInput.value.trim();
    if (initialEmail) {
        loadStoredCooldown(initialEmail);
        if (remainSeconds() > 0) {
            startCountdownTimer();
        }
        syncCooldownFromServer(initialEmail, true);
    }
})();
</script>
<?php endif; ?>
</body>
</html>
