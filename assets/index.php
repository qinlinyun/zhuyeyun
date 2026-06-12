<?php
require_once dirname(__DIR__) . '/includes/auth.php';
require_once dirname(__DIR__) . '/config/database.php';

requireLogin();
$user = getCurrentUser();

// 获取视频列表
$pdo = getDB();
$stmt = $pdo->query("SELECT * FROM videos ORDER BY created_at DESC");
$videos = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>首页 - 竹叶云控平台</title>
    <?php $themeAssetPrefix = '../'; include dirname(__DIR__) . '/components/theme-head.php'; ?>
    <?php include dirname(__DIR__) . '/components/theme-dynamic.php'; ?>
</head>
<body class="bg-gray-100 text-gray-900">
    <nav class="bg-white shadow-sm">
        <div class="mx-auto max-w-screen-xl px-4 py-3">
            <div class="flex flex-wrap items-center gap-3 text-sm">
                <?php if (isAdmin()): ?>
                    <?php $href = 'admin/users.php'; include __DIR__ . '/../components/admin-users-nav-link.php'; ?>
                    <?php $href = 'admin/groups.php'; include __DIR__ . '/../components/admin-groups-nav-link.php'; ?>
                    <?php $href = 'admin/domains.php'; include __DIR__ . '/../components/admin-domains-nav-link.php'; ?>
                    <?php $href = 'admin/videos.php'; include __DIR__ . '/../components/admin-videos-nav-link.php'; ?>
                <?php endif; ?>
                <a class="rounded-full px-3 py-1 hover:bg-gray-100" href="profile.php"><?php echo htmlspecialchars($user['username']); ?></a>
                <?php include __DIR__ . '/../components/logout-nav-link.php'; ?>
                <a class="rounded-full px-3 py-1 hover:bg-gray-100" href="GF-token/">视频下载</a>
            </div>
        </div>
    </nav>
    
    <main class="mx-auto max-w-screen-xl px-4 py-6">
        <div class="mb-6 rounded-lg bg-white p-4 shadow">
            <h3 class="text-lg font-semibold">由竹叶云控平台代管理</h3>
        </div>
        <?php if (empty($videos)): ?>
            <div class="rounded-lg bg-white p-8 text-center text-sm text-gray-500 shadow">暂无视频</div>
        <?php else: ?>
            <div class="grid grid-cols-1 gap-6 sm:grid-cols-2 lg:grid-cols-4">
                <?php foreach ($videos as $video): ?>
                    <div class="overflow-hidden rounded-lg bg-white shadow transition hover:shadow-md">
                        <div class="aspect-video w-full bg-black">
                            <?php if ($video['cover']): ?>
                                <img class="h-full w-full object-cover" src="<?php echo htmlspecialchars($video['cover']); ?>" alt="<?php echo htmlspecialchars($video['title']); ?>">
                            <?php else: ?>
                                <div class="flex h-full w-full items-center justify-center text-sm text-gray-300">暂无封面</div>
                            <?php endif; ?>
                        </div>
                        <div class="p-4">
                            <h3 class="mb-1 line-clamp-1 text-sm font-semibold text-gray-900"><?php echo htmlspecialchars($video['title']); ?></h3>
                            <p class="mb-3 line-clamp-2 text-xs text-gray-500"><?php echo htmlspecialchars($video['description'] ?? ''); ?></p>
                            <a href="play.php?id=<?php echo $video['id']; ?>" class="inline-block rounded bg-red-600 px-3 py-1 text-xs font-semibold text-white hover:bg-red-700">观看</a>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </main>
</body>
</html>

