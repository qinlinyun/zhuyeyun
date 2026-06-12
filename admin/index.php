<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/admin_overview.php';
requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    exit('403 Forbidden');
}

$pdo = getDB();
$stats = getAdminOverviewStats($pdo);
$modules = getAdminOverviewModules('', $stats);

$grouped = [];
foreach ($modules as $module) {
    $grouped[$module['group']][] = $module;
}

$quickStats = [
    ['label' => '注册用户', 'value' => (int)$stats['users'], 'color' => 'text-gray-900'],
    ['label' => '视频数量', 'value' => (int)$stats['videos'], 'color' => 'text-blue-600'],
    ['label' => '评论总数', 'value' => (int)$stats['comments'], 'color' => 'text-emerald-600'],
    ['label' => '待处理反馈', 'value' => (int)$stats['feedback_open'], 'color' => 'text-amber-600'],
];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>后台总览 - 竹叶云控平台</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php $themeAssetPrefix = '../'; include __DIR__ . '/../components/theme-head.php'; ?>
    <?php include __DIR__ . '/../components/theme-dynamic.php'; ?>
    <style>
        .admin-overview-grid {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(1, minmax(0, 1fr));
        }
        @media (min-width: 640px) {
            .admin-overview-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); }
        }
        @media (min-width: 1024px) {
            .admin-overview-grid { grid-template-columns: repeat(3, minmax(0, 1fr)); }
        }
        .admin-overview-stats {
            display: grid;
            gap: 1rem;
            grid-template-columns: repeat(2, minmax(0, 1fr));
        }
        @media (min-width: 640px) {
            .admin-overview-stats { grid-template-columns: repeat(4, minmax(0, 1fr)); }
        }
    </style>
</head>
<body class="bg-gray-100 text-gray-900">
<?php $adminNavActive = 'overview'; include __DIR__ . '/../components/admin-top-nav.php'; ?>

<main class="mx-auto max-w-screen-xl px-4 py-6 space-y-6">
    <div>
        <h1 class="text-lg font-semibold text-gray-900">后台总览</h1>
        <p class="mt-1 text-xs text-gray-500">集中入口：用户、内容、流量、互动与系统配置。</p>
    </div>

    <div class="admin-overview-stats">
        <?php foreach ($quickStats as $item): ?>
            <div class="rounded-xl border border-gray-200 bg-white p-4 shadow-sm">
                <p class="text-xs text-gray-500"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></p>
                <p class="mt-1 text-2xl font-semibold <?= htmlspecialchars($item['color'], ENT_QUOTES, 'UTF-8') ?>">
                    <?= (int)$item['value'] ?>
                </p>
            </div>
        <?php endforeach; ?>
    </div>

    <?php foreach ($grouped as $groupName => $items): ?>
        <section class="space-y-3">
            <h2 class="text-sm font-semibold text-gray-700"><?= htmlspecialchars($groupName, ENT_QUOTES, 'UTF-8') ?></h2>
            <div class="admin-overview-grid">
                <?php foreach ($items as $module): ?>
                    <a href="<?= htmlspecialchars($module['href'], ENT_QUOTES, 'UTF-8') ?>"
                       class="group block rounded-xl border border-gray-200 bg-white p-4 shadow-sm transition hover:border-blue-200 hover:shadow-md">
                        <div class="flex items-start justify-between gap-3">
                            <div class="min-w-0">
                                <p class="text-sm font-semibold text-gray-900 group-hover:text-blue-600">
                                    <?= htmlspecialchars($module['title'], ENT_QUOTES, 'UTF-8') ?>
                                </p>
                                <p class="mt-1 text-xs text-gray-500 leading-relaxed">
                                    <?= htmlspecialchars($module['desc'], ENT_QUOTES, 'UTF-8') ?>
                                </p>
                            </div>
                            <?php if ($module['badge'] !== null): ?>
                                <span class="shrink-0 rounded-full bg-gray-100 px-2.5 py-0.5 text-xs font-medium text-gray-700">
                                    <?= (int)$module['badge'] ?>
                                </span>
                            <?php endif; ?>
                        </div>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endforeach; ?>
</main>
</body>
</html>
