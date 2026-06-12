<?php
/**
 * 评论管理 — 左侧导航
 *
 * @param string $activeSection
 * @param array  $stats
 */
$activeSection = $activeSection ?? 'overview';
$stats = $stats ?? ['total' => 0, 'visible' => 0, 'hidden' => 0, 'today' => 0];

function commentsMenuIcon(string $id): string
{
    $icons = [
        'overview' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 11l8-7 8 7v9a1 1 0 01-1 1h-5v-6H10v6H5a1 1 0 01-1-1z"/>',
        'all' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 15a4 4 0 01-4 4H7l-4 3V7a4 4 0 014-4h10a4 4 0 014 4z"/>',
        'visible' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 6L9 17l-5-5"/>',
        'hidden' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M3 3l18 18"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10.6 10.6a2 2 0 002.8 2.8"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6.7 6.7C5.2 7.9 4 9.4 3 11c2.5 4 6.5 6 9 6 1.1 0 2.2-.3 3.3-.8"/>',
        'filter' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3l8 4v6c0 3.5-2.7 6.7-8 8-5.3-1.3-8-4.5-8-8V7l8-4z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 12l2 2 4-4"/>',
    ];
    $path = $icons[$id] ?? $icons['overview'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" aria-hidden="true">' . $path . '</svg>';
}

$items = [
    ['id' => 'overview', 'label' => '总览', 'hint' => '数据统计', 'badge' => null],
    ['id' => 'all', 'label' => '全部评论', 'hint' => '最新列表', 'badge' => (int)($stats['total'] ?? 0) ?: null],
    ['id' => 'visible', 'label' => '正常显示', 'hint' => '前台可见', 'badge' => (int)($stats['visible'] ?? 0) ?: null],
    ['id' => 'hidden', 'label' => '已隐藏', 'hint' => '审核隐藏', 'badge' => (int)($stats['hidden'] ?? 0) ?: null],
    ['id' => 'filter', 'label' => '屏蔽设置', 'hint' => '关键词/链接', 'badge' => null],
];
?>

<aside class="w-52 shrink-0">
    <nav class="sticky top-4 rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden" aria-label="评论管理导航">
        <div class="border-b border-gray-100 bg-gradient-to-b from-gray-50 to-white px-3 py-2">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-white text-gray-700 border border-gray-200 shadow-sm" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 15a4 4 0 01-4 4H7l-4 3V7a4 4 0 014-4h10a4 4 0 014 4z"/>
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-gray-700 uppercase tracking-wide">评论管理</p>
                    <p class="mt-0.5 text-[11px] text-gray-400">今日 +<?= (int)($stats['today'] ?? 0) ?></p>
                </div>
            </div>
        </div>
        <ul class="py-2 max-h-[70vh] overflow-auto">
            <?php foreach ($items as $it): ?>
                <?php $isActive = $activeSection === $it['id']; ?>
                <li>
                    <a href="?section=<?= urlencode($it['id']) ?>"
                       class="group relative mx-2 flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition-all duration-150 <?= $isActive ? 'bg-blue-50 text-blue-800 font-semibold ring-1 ring-blue-100 shadow-sm' : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900' ?>"
                       <?= $isActive ? 'aria-current="page"' : '' ?>>
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-md <?= $isActive ? 'bg-white text-blue-700 border border-blue-100' : 'bg-white text-gray-500 border border-gray-200 group-hover:text-gray-700' ?>" aria-hidden="true">
                            <?= commentsMenuIcon($it['id']) ?>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate"><?= htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="block truncate text-[11px] <?= $isActive ? 'text-blue-600/70' : 'text-gray-400' ?>"><?= htmlspecialchars($it['hint'], ENT_QUOTES, 'UTF-8') ?></span>
                        </span>
                        <?php if ($it['badge'] !== null): ?>
                            <span class="shrink-0 rounded-full bg-gray-50 px-2.5 py-1 text-[11px] text-gray-700 ring-1 ring-gray-200"><?= (int)$it['badge'] ?></span>
                        <?php endif; ?>
                        <?php if ($isActive): ?><span class="absolute left-0 top-1 bottom-1 w-1 rounded-r bg-blue-600" aria-hidden="true"></span><?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</aside>
