<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/user_login_analytics.php';
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/password_reset.php';

$error = '';
$success = '';
applyFlash($success, $error);
$usernameValue = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = $_POST['username'] ?? '';
    $password = $_POST['password'] ?? '';
    $usernameValue = (string)$username;

    if ($username && $password) {
        $pdo = getDB();
        $stmt = $pdo->prepare("SELECT * FROM users WHERE username = ? OR email = ?");
        $stmt->execute([$username, $username]);
        $user = $stmt->fetch();

        if ($user && password_verify($password, $user['password'])) {
            $isAdminUser = ($user['username'] === 'admin');

            $_SESSION['user_id']  = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['is_admin'] = $isAdminUser;

            if (!$isAdminUser) {
                recordUserLogin($user);
            }

            updateUnreadCounts($pdo, $user['id']);

            header('Location: /');
            exit;
        } else {
            $error = '用户名或密码错误';
        }
    } else {
        $error = '请填写用户名和密码';
    }
}

if (isLoggedIn()) {
    header('Location: /');
    exit;
}

$pdo = getDB();
$showForgotPassword = passwordResetAvailable($pdo);

function updateUnreadCounts($pdo, $userId) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) 
        FROM notifications n
        LEFT JOIN notification_reads r 
          ON r.notification_id = n.id AND r.user_id = ?
        WHERE (n.target_type = 'all' OR n.target_user_id = ?)
          AND r.id IS NULL
    ");
    $stmt->execute([$userId, $userId]);
    $_SESSION['unread_notification_count'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare("
        SELECT COUNT(*)
        FROM feedback_replies r
        JOIN feedbacks f ON f.id = r.feedback_id
        LEFT JOIN feedback_reply_reads rr 
          ON rr.reply_id = r.id AND rr.user_id = ?
        WHERE f.user_id = ?
          AND r.role = 'admin'
          AND rr.id IS NULL
    ");
    $stmt->execute([$userId, $userId]);
    $_SESSION['unread_feedback_reply_count'] = (int)$stmt->fetchColumn();
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>登录 - 竹叶云控平台</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">

    <link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
    <?php include __DIR__ . '/components/theme-head.php'; ?>

    <?php include __DIR__ . '/components/theme-dynamic.php'; ?>

    <style>
        @keyframes fadeInUp {
            from { opacity: 0; transform: translateY(30px); }
            to   { opacity: 1; transform: translateY(0); }
        }
        .fade-in-up {
            animation: fadeInUp 0.8s ease-out forwards;
        }
        @media (prefers-reduced-motion: reduce) {
            .fade-in-up { animation: none; }
        }

        #login-vue-bg-app {
            background: linear-gradient(135deg, #f8fafc 0%, #ffffff 45%, #f1f5f9 100%);
        }
        html.dark #login-vue-bg-app {
            background: linear-gradient(135deg, #0f172a 0%, #111827 50%, #020617 100%);
        }

        .login-logo-lottie svg {
            display: block;
            width: 100% !important;
            height: 100% !important;
        }
    </style>
</head>

<body class="bg-gray-100 text-gray-900 dark:text-gray-100">

<!-- Vue 动态背景 -->
<div id="login-vue-bg-app" aria-hidden="true" class="fixed inset-0 -z-10 overflow-hidden"></div>

<div class="flex min-h-screen flex-col items-center justify-center gap-4 px-4 relative z-10">

    <!-- 登录卡片（半透明 + 毛玻璃） -->
    <div class="w-full max-w-md rounded-2xl
                bg-white/90 dark:bg-gray-800/85
                backdrop-blur-md
                shadow-2xl
                transform transition-all duration-500 hover:-translate-y-1 hover:shadow-2xl
                fade-in-up">

        <!-- Header -->
        <div class="border-b border-gray-200/60 dark:border-gray-700 px-6 py-4 text-center">
            <div
                id="loginLogoLottie"
                class="login-logo-lottie mx-auto mb-3 h-12 w-12 overflow-hidden rounded-xl bg-gradient-to-br from-red-600 to-orange-500 shadow-lg ring-2 ring-white/60 dark:ring-white/20"
                role="img"
                aria-label="平台标识"
            ></div>
            <h1 class="text-3xl font-semibold text-gray-800 dark:text-white">竹叶云控平台</h1>
            <p class="mt-2 text-sm text-gray-500 dark:text-gray-400">欢迎回来，请使用账号登录</p>
        </div>

        <!-- Body -->
        <div class="px-6 py-6">
            <?php if ($error): ?>
                <div class="mb-4 rounded-lg bg-red-50 dark:bg-red-900/30 px-4 py-3 text-sm text-red-600 dark:text-red-400">
                    <?= htmlspecialchars($error) ?>
                </div>
            <?php endif; ?>

            <?php if ($success): ?>
            <div class="mb-4 rounded-lg bg-green-50 dark:bg-green-900/30 px-4 py-3 text-sm text-green-600 dark:text-green-400">
                <?= htmlspecialchars($success, ENT_QUOTES, 'UTF-8') ?>
            </div>
            <?php endif; ?>

            <form method="POST" class="space-y-6">
                <div>
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">
                        用户名 / 邮箱
                    </label>
                    <div class="mt-2 relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 pl-3 flex items-center">
                            <svg class="h-5 w-5 text-gray-400" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                <path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/>
                                <circle cx="9" cy="7" r="4" stroke-width="1.8"/>
                                <path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M17 11l2 2 4-4"/>
                            </svg>
                        </div>
                        <input
                            name="username"
                            value="<?= htmlspecialchars($usernameValue, ENT_QUOTES, 'UTF-8') ?>"
                            required
                            autocomplete="username"
                            spellcheck="false"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600
                                   bg-gray-50 dark:bg-gray-700 pl-11 pr-4 py-3 text-sm
                                   focus:border-red-500 focus:ring-2 focus:ring-red-500 transition"
                            placeholder="请输入用户名或邮箱"
                        >
                    </div>
                </div>

                <div class="">
                    <label class="block text-sm font-semibold text-gray-700 dark:text-gray-300">
                        密码
                    </label>
                    <div class="mt-2 relative">
                        <div class="pointer-events-none absolute inset-y-0 left-0 pl-3 flex items-center">
                            <svg class="h-5 w-5 text-gray-400" viewBox="0 0 1024 1024" fill="currentColor" aria-hidden="true">
                                <path d="M881.627409 367.4273l-33.152051 0c0.353041-2.012843 0.470721-4.104481 0.314155-6.245238-2.741437-37.419238-11.31572-73.77526-25.590841-108.396779-17.012466-41.262776-41.362037-78.314647-72.371284-110.125143-31.008224-31.810496-67.125817-56.789401-107.348913-74.241888-41.659819-18.076704-85.895299-27.241434-131.478474-27.241434-45.583175 0-89.818655 9.165754-131.479497 27.241434-40.223097 17.452487-76.340689 42.431392-107.348913 74.241888-31.009248 31.810496-55.357795 68.862367-72.371284 110.125143-14.275121 34.621519-22.848381 70.977541-25.590841 108.396779-0.156566 2.140757-0.038886 4.232394 0.314155 6.245238l-49.682539 0c-50.327222 0-91.27175 40.944528-91.27175 91.27175l0 431.924243c0 50.328245 40.944528 91.27175 91.27175 91.27175l755.785306 0c50.327222 0 91.270726-40.944528 91.270726-91.27175L972.897112 458.69905C972.898135 408.371828 931.953607 367.4273 881.627409 367.4273zM237.227959 364.884384c5.167696-66.944691 33.06507-129.168872 79.859839-177.173189 52.062749-53.40942 121.284297-82.82231 194.912202-82.82231s142.848429 29.413913 194.911178 82.82231c46.793746 48.004317 74.692143 110.227474 79.859839 177.173189 0.066515 0.861624 0.178055 1.708921 0.327458 2.542916L236.901525 367.4273C237.049904 366.593306 237.161445 365.746008 237.227959 364.884384zM906.383232 890.623293c0 13.650905-11.105942 24.756847-24.755824 24.756847L125.842103 915.38014c-13.650905 0-24.756847-11.106965-24.756847-24.756847L101.085256 458.69905c0-13.650905 11.105942-24.756847 24.756847-24.756847l755.785306 0c13.650905 0 24.755824 11.105942 24.755824 24.756847L906.383232 890.623293z"></path>
                                <path d="M507.520989 537.665543c-28.492938 2.116197-51.582819 24.984021-53.946656 53.457516-1.706875 20.553105 7.198959 39.111786 21.832238 50.811246 10.066263 8.049327 15.652492 20.453844 15.652492 33.342386L491.059062 789.299607c0 10.666944 7.378038 20.208251 17.86795 22.143323 13.882172 2.561335 26.107611-8.16496 26.107611-21.60711L535.034623 673.529907c0-12.583596 5.417383-24.604374 14.992459-32.769334 12.610202-10.752902 20.60734-26.755364 20.60734-44.626383C570.634422 562.269917 541.92659 535.110347 507.520989 537.665543z"></path>
                            </svg>
                        </div>
                        <input
                            id="passwordInput"
                            type="password"
                            name="password"
                            required
                            autocomplete="current-password"
                            class="w-full rounded-lg border border-gray-300 dark:border-gray-600
                                   bg-gray-50 dark:bg-gray-700 pl-11 pr-11 py-3 text-sm
                                   focus:border-red-500 focus:ring-2 focus:ring-red-500 transition"
                            placeholder="请输入密码"
                        >
                        <button
                            type="button"
                            id="togglePassword"
                            class="absolute inset-y-0 right-3 z-10 inline-flex items-center justify-center p-0 text-gray-500 hover:text-gray-900 dark:hover:text-white transition"
                            aria-label="显示或隐藏密码"
                        >
                            <svg id="eyeIcon" class="block h-5 w-5" viewBox="0 0 24 24" fill="none" stroke="currentColor" aria-hidden="true">
                                <path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/>
                                <circle cx="12" cy="12" r="3" stroke-width="1.8"/>
                            </svg>
                        </button>
                    </div>
                    <?php if ($showForgotPassword): ?>
                    <div class="mt-2 text-right">
                        <a href="forgot_password.php" class="text-sm text-red-600 hover:underline">忘记密码？</a>
                    </div>
                    <?php endif; ?>
                </div>

                <button type="submit"
                        class="w-full rounded-lg bg-red-600 px-4 py-3 text-sm font-semibold
                               text-white hover:bg-red-700 focus:ring-4 focus:ring-red-300 transition">
                    登录
                </button>

                <p class="text-center text-xs text-gray-500 dark:text-gray-400">
                    提示：输入框支持自动填充，按 Enter 也可直接登录
                </p>
            </form>
        </div>

        <!-- Footer -->
        <div class="border-t border-gray-200/60 dark:border-gray-700 px-6 py-4 text-center text-sm">
            <span class="text-gray-500 dark:text-gray-400">没有账号？</span>
            <a href="register.php" class="text-red-600 hover:underline">
                立即注册
            </a>
            <p class="mt-3 text-gray-500 dark:text-gray-400">
                需要更换绑定邮箱？
                <a href="邮件重置/login.php" class="text-red-600 hover:underline">修改邮箱</a>
                <span class="text-xs text-gray-400">（需登录，每账号仅一次机会）</span>
            </p>
            <div class="mt-3 flex justify-center">
                <?php include __DIR__ . '/components/theme-toggle.php'; ?>
            </div>
        </div>
    </div>

    <!-- 广告位（独立于登录表单） -->
    <div class="w-full max-w-md rounded-2xl border border-dashed border-amber-300/80
                bg-white/75 dark:bg-gray-800/70 backdrop-blur-md
                px-5 py-4 text-center shadow-lg fade-in-up">
        <p class="text-xs font-medium uppercase tracking-wide text-amber-700/80 dark:text-amber-400/80">广告位</p>
        <p class="mt-1.5 text-sm text-amber-900 dark:text-amber-100">
            广告位招租，联系 TG：
            <a href="https://t.me/qinlin5200" target="_blank" rel="noopener noreferrer"
               class="font-semibold text-red-600 underline decoration-amber-400/60 underline-offset-2 hover:text-red-700 dark:text-red-400 dark:hover:text-red-300">
                t.me/qinlin5200
            </a>
        </p>
    </div>
</div>

<?php include __DIR__ . '/components/theme-toggle-script.php'; ?>
<script src="https://unpkg.com/vue@3/dist/vue.global.prod.js" crossorigin="anonymous"></script>
<script src="assets/js/login-vue-bg.js?v=1"></script>
<script src="https://cdnjs.cloudflare.com/ajax/libs/lottie-web/5.12.2/lottie.min.js" crossorigin="anonymous" referrerpolicy="no-referrer"></script>
<script>
(function () {
    var el = document.getElementById('loginLogoLottie');
    if (!el || typeof lottie === 'undefined') return;

    var anim = lottie.loadAnimation({
        container: el,
        renderer: 'svg',
        loop: true,
        autoplay: true,
        path: 'https://ac.901235.xyz/ico-2.json'
    });

    if (window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
        anim.addEventListener('DOMLoaded', function () {
            anim.goToAndStop(anim.totalFrames - 1, true);
            anim.pause();
        });
    }
})();
</script>
<script>
(() => {
    const input = document.getElementById('passwordInput');
    const btn = document.getElementById('togglePassword');
    const icon = document.getElementById('eyeIcon');
    if (!input || !btn || !icon) return;

    btn.addEventListener('click', () => {
        const show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        icon.innerHTML = show
            ? '<path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M4 4l16 16"/><path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M9.9 9.9a3 3 0 0 0 4.2 4.2"/>'
            : '<path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" d="M2 12s3.5-7 10-7 10 7 10 7-3.5 7-10 7-10-7-10-7z"/><circle cx="12" cy="12" r="3" stroke-width="1.8"/>';
    });
})();
</script>
</body>
</html>
