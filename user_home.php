<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/user_profile.php';
require_once __DIR__ . '/includes/play_domains.php';

requireLogin();
$viewer = getCurrentUser();
$pdo = getDB();
ensureUserProfileSchema($pdo);

$profileUserId = (int)($_GET['id'] ?? $viewer['id']);
if ($profileUserId <= 0) {
    $profileUserId = (int)$viewer['id'];
}

$profileUser = fetchUserById($pdo, $profileUserId);
if (!$profileUser) {
    header('Location: index.php?error=用户不存在');
    exit;
}

$isOwner = (int)$viewer['id'] === (int)$profileUser['id'];
$videos = fetchUserUploadedVideos($pdo, $profileUserId);
$hasTrafficCol = (bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'is_traffic'")->fetch();
$publicVideoCount = count($videos);
$publicVideoClicks = countUserUploadedVideoClicks($pdo, $videos);
$earningSummary = $isOwner ? userVideoEarningSummary($pdo, $profileUserId) : null;
$earningBills = $isOwner ? fetchUserVideoEarningBills($pdo, $profileUserId, 5) : [];
$profileDisplayName = userDisplayName($profileUser);

$statusText = ['active' => '正常', 'banned' => '已封禁', 'frozen' => '已冻结'];
$uploadMsg = '';
$uploadErr = '';

if ($isOwner && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['upload_avatar'])) {
    $baseDir = __DIR__;
    $uploadDir = $baseDir . '/uploads/avatars';
    $result = saveUserAvatar($_FILES['avatar'] ?? [], (int)$viewer['id'], $uploadDir, $profileUser['avatar'] ?? null, $baseDir);
    if ($result['error']) {
        $uploadErr = $result['error'];
    } else {
        $pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$result['path'], $viewer['id']]);
        $profileUser['avatar'] = $result['path'];
        $uploadMsg = '头像已更新';
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= htmlspecialchars($profileDisplayName) ?> 的主页 - 竹叶云控平台</title>
<?php include __DIR__ . '/components/theme-head.php'; ?>

<?php include __DIR__ . '/components/theme-dynamic.php'; ?>
</head>
<body class="bg-gray-100 text-gray-900">
<nav class="bg-white shadow-sm">
<div class="mx-auto max-w-screen-xl px-4 py-3 flex flex-wrap items-center justify-between gap-3 text-sm">
<a href="index.php" class="rounded-full px-3 py-1 hover:bg-gray-100">首页</a>
<div class="flex flex-wrap items-center gap-2">
<?php if ($isOwner): ?>
<a href="profile.php" class="rounded-full px-3 py-1 hover:bg-gray-100">个人中心</a>
<?php endif; ?>
<?php include __DIR__ . '/components/logout-nav-link.php'; ?>
<?php include __DIR__ . '/components/theme-toggle.php'; ?>
</div>
</div>
</nav>

<main class="mx-auto max-w-screen-xl px-4 py-6">
<?php if ($uploadMsg): ?>
<div class="mb-4 rounded-lg bg-green-50 px-4 py-2 text-sm text-green-700"><?= htmlspecialchars($uploadMsg) ?></div>
<?php endif; ?>
<?php if ($uploadErr): ?>
<div class="mb-4 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-600"><?= htmlspecialchars($uploadErr) ?></div>
<?php endif; ?>

<div class="mb-6 flex flex-col gap-6 rounded-lg bg-white p-6 shadow sm:flex-row sm:items-start">
<div class="flex shrink-0 flex-col items-center gap-3">
<?php $user = $profileUser; $imgClass = 'h-24 w-24 rounded-full border border-gray-200 object-cover bg-gray-50'; $svgClass = 'h-24 w-24 shrink-0'; include __DIR__ . '/components/user-avatar.php'; ?>
<?php if ($isOwner): ?>
<form method="post" enctype="multipart/form-data" class="flex flex-col items-center gap-2">
<input type="hidden" name="upload_avatar" value="1">
<label class="cursor-pointer rounded bg-gray-100 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-200">
更换头像
<input type="file" name="avatar" accept="image/jpeg,image/png,image/webp" class="hidden" onchange="this.form.submit()">
</label>
<span class="text-[10px] text-gray-400">jpg/png/webp，最大 2MB</span>
</form>
<?php endif; ?>
</div>

<div class="grid min-w-0 flex-1 gap-5 lg:grid-cols-2">
<div>
<h1 class="mb-1 text-xl font-semibold"><?= htmlspecialchars($profileDisplayName) ?></h1>
<?php if ($profileDisplayName !== (string)$profileUser['username']): ?>
<p class="mb-2 text-xs text-gray-400">@<?= htmlspecialchars($profileUser['username']) ?></p>
<?php endif; ?>
<div class="divide-y text-sm">
<div class="flex justify-between py-2"><span class="text-gray-500">名称</span><span><?= htmlspecialchars($profileDisplayName) ?></span></div>
<?php if ($isOwner): ?>
<div class="flex justify-between py-2"><span class="text-gray-500">邮箱</span><span><?= htmlspecialchars($profileUser['email']) ?></span></div>
<div class="flex justify-between py-2"><span class="text-gray-500">用户组</span><span><?= htmlspecialchars($profileUser['group_name'] ?? '未分组') ?></span></div>
<?php endif; ?>
<div class="flex justify-between py-2"><span class="text-gray-500">状态</span><span><?= htmlspecialchars($statusText[$profileUser['status']] ?? '未知') ?></span></div>
<div class="flex justify-between py-2"><span class="text-gray-500">注册时间</span><span><?= htmlspecialchars($profileUser['created_at'] ?? '-') ?></span></div>
</div>
<?php if ($isOwner): ?>
<div class="mt-4 flex flex-wrap gap-2">
<a href="profile.php" class="rounded border border-gray-300 px-3 py-1.5 text-sm hover:bg-gray-50">账户设置</a>
<a href="change_password.php" class="rounded bg-red-600 px-3 py-1.5 text-sm font-semibold text-white hover:bg-red-700">修改密码</a>
<a href="my_video_manage.php" class="rounded bg-gray-900 px-3 py-1.5 text-sm font-semibold text-white hover:bg-black">视频管理</a>
</div>
<?php endif; ?>
</div>

<div class="rounded-lg border border-gray-200 bg-gray-50 p-4">
    <div class="grid grid-cols-1 gap-3 sm:grid-cols-2">
        <div class="rounded bg-white p-3 shadow-sm">
            <div class="text-xs text-gray-500">视频发布数量</div>
            <div class="mt-1 text-2xl font-semibold text-gray-900"><?= (int)$publicVideoCount ?></div>
        </div>
        <div class="rounded bg-white p-3 shadow-sm">
            <div class="text-xs text-gray-500">视频观看次数</div>
            <div class="mt-1 text-2xl font-semibold text-blue-600"><?= (int)$publicVideoClicks ?></div>
        </div>
        <?php if ($isOwner): ?>
        <div class="rounded bg-white p-3 shadow-sm sm:col-span-2">
            <div class="text-xs text-gray-500">视频总收益</div>
            <div class="mt-1 flex flex-wrap items-end gap-3">
                <span class="text-2xl font-semibold text-green-600"><?= (int)$earningSummary['available'] ?></span>
                <span class="pb-1 text-xs text-gray-500">冻结 <?= (int)$earningSummary['frozen'] ?> · 累计 <?= (int)$earningSummary['total_amount'] ?> · 已收回 <?= (int)$earningSummary['reclaimed_amount'] ?></span>
            </div>
        </div>
        <div class="rounded bg-white p-3 shadow-sm sm:col-span-2">
            <div class="mb-2 flex items-center justify-between">
                <div class="text-xs text-gray-500">流量账单流水</div>
                <a href="traffic.php" class="text-[11px] text-red-600 hover:underline">查看全部</a>
            </div>
            <?php if (empty($earningBills)): ?>
                <div class="text-xs text-gray-400">暂无收益流水</div>
            <?php else: ?>
                <div class="space-y-2">
                    <?php foreach ($earningBills as $bill): ?>
                    <div class="flex items-center justify-between gap-2 text-xs">
                        <div class="min-w-0">
                            <div class="truncate text-gray-700"><?= htmlspecialchars((string)($bill['video_title'] ?? '已删除视频')) ?></div>
                            <div class="text-gray-400"><?= htmlspecialchars((string)$bill['paid_at']) ?> · <?= htmlspecialchars((string)($bill['payer_username'] ?? '未知用户')) ?></div>
                        </div>
                        <div class="shrink-0 text-right">
                            <div class="font-semibold text-green-600">+<?= (int)$bill['amount'] ?></div>
                            <div class="text-gray-400"><?= htmlspecialchars(trafficEarningStatusLabel((string)$bill['status'])) ?></div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
        <?php endif; ?>
    </div>
</div>
</div>
</div>

<h2 class="mb-4 text-base font-semibold"><?= $isOwner ? '我上传的视频' : 'TA 的视频' ?></h2>

<?php if (empty($videos)): ?>
<div class="rounded-lg bg-white p-8 text-center text-sm text-gray-500 shadow">暂无视频</div>
<?php if ($isOwner): ?>
<?php endif; ?>
<?php else: ?>
<div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
<?php foreach ($videos as $video): ?>
<div class="overflow-hidden rounded-lg bg-white shadow hover:shadow-lg transition">
<div class="aspect-video bg-black">
<?php if (!empty($video['cover'])): ?>
<?php $coverUrl = play_build_group_domain_url($pdo, $viewer, $video, (string)$video['cover'], isAdmin()); ?>
<img class="h-full w-full object-cover" src="<?= htmlspecialchars($coverUrl) ?>" alt="">
<?php else: ?>
<div class="flex h-full items-center justify-center text-gray-300 text-sm">暂无封面</div>
<?php endif; ?>
</div>
<div class="p-4">
<h3 class="mb-1 text-sm font-semibold line-clamp-1"><?= htmlspecialchars($video['title']) ?></h3>
<?php if ($isOwner && !empty($video['description'])): ?>
<p class="mb-3 text-xs text-gray-500 line-clamp-2"><?= htmlspecialchars($video['description']) ?></p>
<?php endif; ?>
<a href="play.php?id=<?= (int)$video['id'] ?>" class="inline-block rounded bg-red-600 px-3 py-1 text-xs font-semibold text-white hover:bg-red-700">观看</a>
</div>
</div>
<?php endforeach; ?>
</div>
<?php endif; ?>
</main>
<?php include __DIR__ . '/components/theme-toggle-script.php'; ?>
</body>
</html>
