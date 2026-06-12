<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/user_profile.php';

requireLogin();
$user = getCurrentUser();
$pdo = getDB();
ensureUserProfileSchema($pdo);
$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_profile_name') {
    try {
        updateUserDisplayName($pdo, (int)$user['id'], (string)($_POST['display_name'] ?? ''));
        $user = getCurrentUser();
        $message = '名称已保存';
    } catch (Throwable $e) {
        $error = '保存失败：' . $e->getMessage();
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>个人中心 - 竹叶云控平台</title>
    <?php include __DIR__ . '/components/theme-head.php'; ?>
    <?php include __DIR__ . '/components/theme-dynamic.php'; ?>
</head>
<body class="bg-gray-100 text-gray-900">
    <nav class="bg-white shadow-sm">
        <div class="mx-auto max-w-screen-xl px-4 py-3">
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <!-- 首页 -->
        <a href="index.php" class="group rounded-full p-2 hover:bg-gray-100">
            <svg class="w-5 h-5 text-gray-500 group-hover:text-gray-900 transition"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                      d="M3 10l9-7 9 7v10a2 2 0 01-2 2h-4v-6H9v6H5a2 2 0 01-2-2z"/>
            </svg>
        </a>
                <?php if (isAdmin()): ?>
                    <?php $href = 'admin/users.php'; include __DIR__ . '/components/admin-users-nav-link.php'; ?>
                    <?php $href = 'admin/groups.php'; include __DIR__ . '/components/admin-groups-nav-link.php'; ?>
                    <?php $href = 'admin/domains.php'; include __DIR__ . '/components/admin-domains-nav-link.php'; ?>
                    <?php $href = 'admin/videos.php'; include __DIR__ . '/components/admin-videos-nav-link.php'; ?>
                <?php endif; ?>
                <a class="rounded-full bg-gray-100 px-3 py-1 inline-flex items-center gap-1.5" href="user_home.php">
                    <?php $imgClass = 'h-6 w-6 rounded-full object-cover border border-gray-200 bg-gray-50'; $svgClass = 'h-6 w-6 shrink-0'; include __DIR__ . '/components/user-avatar.php'; ?>
                    <?php echo htmlspecialchars($user['username']); ?>
                </a>
                    <?php include __DIR__ . '/components/logout-nav-link.php'; ?>
                <?php include __DIR__ . '/components/theme-toggle.php'; ?>
            </div>
        </div>
    </nav>
    
    <main class="mx-auto max-w-screen-xl px-4 py-6">
        <h1 class="mb-4 text-lg font-semibold">个人中心</h1>
        <?php if ($message): ?>
            <div class="mb-4 rounded-lg bg-green-50 px-4 py-2 text-sm text-green-700"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="mb-4 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-600"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <div class="mb-4">
            <a href="user_home.php" class="inline-flex items-center gap-2 rounded-lg bg-white px-4 py-3 text-sm shadow hover:bg-gray-50">
                <?php $imgClass = 'h-10 w-10 rounded-full object-cover border border-gray-200 bg-gray-50'; $svgClass = 'h-10 w-10 shrink-0'; include __DIR__ . '/components/user-avatar.php'; ?>
                <span>我的个人主页</span>
                <span class="text-gray-400">→</span>
            </a>
        </div>
        <div class="rounded-lg bg-white p-6 shadow">
            <h2 class="mb-4 text-base font-semibold">个人信息</h2>
            <form method="post" class="mb-5 rounded-lg border border-gray-200 bg-gray-50 p-4">
                <input type="hidden" name="action" value="save_profile_name">
                <label class="mb-1 block text-sm font-medium text-gray-700" for="display_name">名称</label>
                <div class="flex flex-col gap-2 sm:flex-row">
                    <input id="display_name" name="display_name" maxlength="40" value="<?php echo htmlspecialchars((string)($user['display_name'] ?? ''), ENT_QUOTES, 'UTF-8'); ?>" placeholder="给你的账号取一个名称" class="min-w-0 flex-1 rounded border border-gray-300 px-3 py-2 text-sm">
                    <button class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">保存名称</button>
                </div>
                <p class="mt-1 text-xs text-gray-500">名称会展示在个人主页；为空时使用用户名。</p>
            </form>
            <div class="divide-y text-sm">
                <div class="flex items-center justify-between py-3">
                    <span class="text-gray-500">名称</span>
                    <span><?php echo htmlspecialchars(userDisplayName($user)); ?></span>
                </div>
                <div class="flex items-center justify-between py-3">
                    <span class="text-gray-500">用户名</span>
                    <span><?php echo htmlspecialchars($user['username']); ?></span>
                </div>
                <div class="flex items-center justify-between py-3">
                    <span class="text-gray-500">邮箱</span>
                    <span><?php echo htmlspecialchars($user['email']); ?></span>
                </div>
                <div class="flex items-center justify-between py-3">
                    <span class="text-gray-500">用户组</span>
                    <span><?php echo htmlspecialchars($user['group_name'] ?? '未分组'); ?></span>
                </div>
                <div class="flex items-center justify-between py-3">
                    <span class="text-gray-500">状态</span>
                    <span>
                        <?php
                        $statusText = ['active' => '正常', 'banned' => '已封禁', 'frozen' => '已冻结'];
                        echo $statusText[$user['status']] ?? '未知';
                        ?>
                    </span>
                </div>
            </div>
            <div class="mt-6 flex flex-wrap gap-2">
                <a href="change_password.php" class="inline-block rounded bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">修改密码</a>
                <a href="邮件重置/" class="inline-block rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">修改邮箱</a>
                <a href="my_video_manage.php" class="inline-block rounded bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black">视频管理</a>
            </div>
        </div>
    </main>
<?php include __DIR__ . '/components/theme-toggle-script.php'; ?>
</body>
</html>

