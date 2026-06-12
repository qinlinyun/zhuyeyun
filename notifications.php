<?php
$authPath = __DIR__ . '/includes/auth.php';
$configPath = __DIR__ . '/config/database.php';
if (!file_exists($authPath)) {
    $authPath = dirname(__DIR__) . '/includes/auth.php';
}
if (!file_exists($configPath)) {
    $configPath = dirname(__DIR__) . '/config/database.php';
}
if (!file_exists($authPath) || !file_exists($configPath)) {
    die('找不到认证或数据库配置文件，请确认 includes/auth.php 与 config/database.php 是否存在。');
}
require_once $authPath;
require_once $configPath;

requireLogin();
$user = getCurrentUser();
$pdo = getDB();

$stmt = $pdo->prepare("
    SELECT n.*, u.username AS creator_name,
           IF(r.id IS NULL, 0, 1) AS is_read
    FROM notifications n
    LEFT JOIN users u ON n.created_by = u.id
    LEFT JOIN notification_reads r
      ON r.notification_id = n.id AND r.user_id = ?
    WHERE n.target_type = 'all' OR n.target_user_id = ?
    ORDER BY n.created_at DESC
");
$stmt->execute([$user['id'], $user['id']]);
$notifications = $stmt->fetchAll();

$unreadCount = 0;
if (!empty($notifications)) {
    foreach ($notifications as $notice) {
        if ((int)$notice['is_read'] === 0) {
            $unreadCount++;
        }
    }

    $notificationIds = array_column($notifications, 'id');
    $values = implode(',', array_fill(0, count($notificationIds), '(?, ?, NOW())'));
    $params = [];
    foreach ($notificationIds as $nid) {
        $params[] = $user['id'];
        $params[] = $nid;
    }
    $markStmt = $pdo->prepare("INSERT IGNORE INTO notification_reads (user_id, notification_id, read_at) VALUES $values");
    $markStmt->execute($params);
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
    <meta charset="UTF-8">
    <title>站内通知</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/components/theme-head.php'; ?>

    <?php include __DIR__ . '/components/theme-dynamic.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.css">
</head>
<body class="bg-gray-100 text-gray-900">
<nav class="bg-white shadow-sm">
    <div class="mx-auto max-w-screen-xl px-4 py-3 flex gap-2 items-center">

        <!-- 首页 -->
        <a href="index.php" class="group rounded-full p-2 hover:bg-gray-100">
            <svg class="w-5 h-5 text-gray-500 group-hover:text-gray-900 transition"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                      d="M3 10l9-7 9 7v10a2 2 0 01-2 2h-4v-6H9v6H5a2 2 0 01-2-2z"/>
            </svg>
        </a>

        <!-- 当前页：站内通知 -->
        <a href="notifications.php" class="rounded-full p-2 bg-gray-100">
            <svg class="w-5 h-5 text-gray-900"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-width="1.8" stroke-linecap="round"
                      d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5"/>
                <path stroke-width="1.8" stroke-linecap="round" d="M9 17a3 3 0 006 0"/>
            </svg>
        </a>

        <!-- 意见反馈 -->
        <a href="feedback.php" class="group rounded-full p-2 hover:bg-gray-100">
            <svg class="w-5 h-5 text-gray-500 group-hover:text-gray-900 transition"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                      d="M7 8h10M7 12h6m-2 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
            </svg>
        </a>

        <!-- 用户 -->
        <a href="profile.php" class="group rounded-full p-2 hover:bg-gray-100">
            <svg class="w-5 h-5 text-gray-500 group-hover:text-gray-900 transition"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <circle cx="12" cy="8" r="4" stroke-width="1.8"/>
                <path stroke-width="1.8" stroke-linecap="round"
                      d="M4 20a8 8 0 0116 0"/>
            </svg>
        </a>

        <?php include __DIR__ . '/components/logout-nav-link.php'; ?>
        <?php include __DIR__ . '/components/theme-toggle.php'; ?>

    </div>
</nav>

<main class="mx-auto max-w-screen-xl px-4 py-6">
    <div class="mb-6 flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold animate__animated animate__fadeInDown">站内通知</h1>
            <p class="text-xs text-gray-500">仅显示你可见的通知</p>
        </div>
        <div class="flex gap-2 text-xs">
            <span class="rounded-full bg-blue-50 px-2 py-0.5 text-blue-600">📣 总数 <?= count($notifications) ?></span>
            <span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-600">未读 <?= (int)$unreadCount ?></span>
        </div>
    </div>

    <?php if (empty($notifications)): ?>
        <div class="rounded-lg bg-white p-6 text-sm text-gray-500 shadow animate__animated animate__fadeIn">
            暂无通知。
        </div>
    <?php else: ?>
        <div class="grid gap-5 md:grid-cols-2">
            <?php foreach ($notifications as $notice): ?>
                <div class="group rounded-2xl bg-white p-5 shadow transition hover:-translate-y-1 hover:shadow-lg" data-aos="fade-up">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <h2 class="text-base font-semibold text-gray-900 group-hover:text-red-600"><?= htmlspecialchars($notice['title']) ?></h2>
                        <span class="text-xs text-gray-500"><?= htmlspecialchars($notice['created_at']) ?></span>
                    </div>
                    <div class="mt-3 text-sm text-gray-700 whitespace-pre-line leading-relaxed"><?= htmlspecialchars($notice['content']) ?></div>
                    <div class="mt-4 flex items-center justify-between text-xs text-gray-400">
                        <span>发布者：<?= htmlspecialchars($notice['creator_name'] ?? '系统') ?></span>
                        <div class="flex flex-wrap items-center gap-2">
                            <?php if ($notice['target_type'] === 'user'): ?>
                                <span class="rounded-full bg-blue-50 px-2 py-0.5 text-blue-600">指定用户</span>
                            <?php else: ?>
                                <span class="rounded-full bg-green-50 px-2 py-0.5 text-green-600">全员通知</span>
                            <?php endif; ?>
                            <?php if ((int)$notice['is_read'] === 0): ?>
                                <span class="rounded-full bg-amber-50 px-2 py-0.5 text-amber-600">未读</span>
                            <?php else: ?>
                                <span class="rounded-full bg-gray-100 px-2 py-0.5 text-gray-500">已读</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</main>
<script src="https://cdn.jsdelivr.net/npm/aos@2.3.4/dist/aos.js"></script>
<script>
  AOS.init({ duration: 600, once: true });
</script>
<?php include __DIR__ . '/components/theme-toggle-script.php'; ?>
</body>
</html>

