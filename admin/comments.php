<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/flash.php';
require_once '../includes/comments.php';
require_once '../includes/comment_filter.php';
require_once '../includes/user_profile.php';

requireAdmin();
$pdo = getDB();
ensureCommentsSchema($pdo);
ensureUserProfileSchema($pdo);

$message = '';
$error = '';
applyFlash($message, $error);

$activeSection = trim((string)($_GET['section'] ?? ''));
if (!in_array($activeSection, ['overview', 'all', 'visible', 'hidden', 'filter'], true)) {
    $activeSection = 'overview';
}

$keyword = trim((string)($_GET['q'] ?? ''));
$videoFilter = max(0, (int)($_GET['video_id'] ?? 0));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['panel'] ?? '') === 'comment_filter') {
    saveCommentFilterConfig($pdo, parseCommentFilterConfigFromPost($_POST));
    finishPostRequest('评论屏蔽设置已保存', null, 'comments.php?section=filter');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = trim((string)($_POST['action'] ?? ''));
    $commentId = (int)($_POST['comment_id'] ?? 0);
    $redirect = trim((string)($_POST['redirect'] ?? ''));
    if ($redirect === '' || strpos($redirect, 'comments.php') !== 0) {
        $redirect = 'comments.php?section=' . urlencode($activeSection);
        if ($keyword !== '') {
            $redirect .= '&q=' . urlencode($keyword);
        }
        if ($videoFilter > 0) {
            $redirect .= '&video_id=' . $videoFilter;
        }
    }

    if ($commentId <= 0) {
        $error = '无效的评论 ID';
    } elseif ($action === 'hide') {
        $result = commentSetStatus($pdo, $commentId, 'hidden');
        finishPostRequest($result['ok'] ? ($result['message'] ?? '已隐藏') : null, $result['ok'] ? null : ($result['message'] ?? '操作失败'), $redirect);
    } elseif ($action === 'show') {
        $result = commentSetStatus($pdo, $commentId, 'visible');
        finishPostRequest($result['ok'] ? ($result['message'] ?? '已恢复') : null, $result['ok'] ? null : ($result['message'] ?? '操作失败'), $redirect);
    } elseif ($action === 'delete') {
        $result = commentDelete($pdo, $commentId, (int)$_SESSION['user_id'], true);
        finishPostRequest($result['ok'] ? ($result['message'] ?? '已删除') : null, $result['ok'] ? null : ($result['message'] ?? '操作失败'), $redirect);
    } else {
        $error = '未知操作';
    }

    if ($error) {
        finishPostRequest(null, $error, $redirect);
    }
}

$stats = commentAdminStats($pdo);
$filterConfig = getCommentFilterConfig($pdo);
$listSection = $activeSection === 'overview' ? 'all' : $activeSection;
$comments = $activeSection === 'filter'
    ? []
    : ($activeSection === 'overview'
    ? commentAdminList($pdo, 'all', $keyword, $videoFilter, 8)
    : commentAdminList($pdo, $listSection, $keyword, $videoFilter, 200));
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>评论管理</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $themeAssetPrefix = '../'; include __DIR__ . '/../components/theme-head.php'; ?>
    <?php include __DIR__ . '/../components/theme-dynamic.php'; ?>
</head>
<body class="bg-gray-100 text-gray-900">
<?php $adminNavActive = 'comments'; include __DIR__ . '/../components/admin-top-nav.php'; ?>

<main class="mx-auto max-w-screen-xl px-4 py-6 space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-lg font-semibold text-gray-900">评论管理</h1>
            <p class="mt-1 text-xs text-gray-500">审核、隐藏或删除视频评论，支持按视频与关键词搜索。</p>
        </div>
        <form method="get" class="flex flex-wrap items-center gap-2">
            <input type="hidden" name="section" value="<?= htmlspecialchars($activeSection, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($activeSection !== 'filter'): ?>
            <input type="number" name="video_id" min="0" placeholder="视频 ID" value="<?= $videoFilter > 0 ? (int)$videoFilter : '' ?>"
                   class="w-24 rounded-lg border border-gray-300 px-3 py-2 text-sm">
            <input type="search" name="q" value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>" placeholder="搜索内容/用户/视频"
                   class="w-48 rounded-lg border border-gray-300 px-3 py-2 text-sm">
            <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">搜索</button>
            <?php endif; ?>
        </form>
    </div>

    <?php if ($message): ?>
        <div class="rounded-lg bg-green-50 px-4 py-3 text-sm text-green-700 ring-1 ring-green-100"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="rounded-lg bg-red-50 px-4 py-3 text-sm text-red-700 ring-1 ring-red-100"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div class="flex gap-4 items-start">
        <?php include __DIR__ . '/../components/admin-comments-sidebar.php'; ?>

        <div class="min-w-0 flex-1 space-y-5">
            <?php if ($activeSection === 'filter'): ?>
                <?php include __DIR__ . '/../components/admin-comments-filter-panel.php'; ?>
            <?php elseif ($activeSection === 'overview'): ?>
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">评论总数</p>
                        <p class="mt-1 text-2xl font-semibold text-gray-900"><?= (int)$stats['total'] ?></p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">正常显示</p>
                        <p class="mt-1 text-2xl font-semibold text-emerald-600"><?= (int)$stats['visible'] ?></p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">已隐藏</p>
                        <p class="mt-1 text-2xl font-semibold text-amber-600"><?= (int)$stats['hidden'] ?></p>
                    </div>
                    <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                        <p class="text-xs text-gray-500">今日新增</p>
                        <p class="mt-1 text-2xl font-semibold text-blue-600"><?= (int)$stats['today'] ?></p>
                    </div>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <p class="text-sm font-semibold text-gray-900">最新评论</p>
                    </div>
                    <?php if (empty($comments)): ?>
                        <p class="px-5 py-10 text-center text-sm text-gray-500">暂无评论</p>
                    <?php else: ?>
                        <?php include __DIR__ . '/../components/admin-comments-table.php'; ?>
                    <?php endif; ?>
                </div>
            <?php else: ?>
                <div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-3 flex items-center justify-between">
                        <p class="text-sm font-semibold text-gray-900">
                            <?php
                            echo match ($activeSection) {
                                'visible' => '正常显示的评论',
                                'hidden' => '已隐藏的评论',
                                default => '全部评论',
                            };
                            ?>
                        </p>
                        <span class="text-xs text-gray-500">共 <?= count($comments) ?> 条</span>
                    </div>
                    <?php if (empty($comments)): ?>
                        <p class="px-5 py-10 text-center text-sm text-gray-500">没有符合条件的评论</p>
                    <?php else: ?>
                        <?php include __DIR__ . '/../components/admin-comments-table.php'; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</main>
</body>
</html>
