<?php
/**
 * 分组管理 — 左侧导航
 *
 * @param string $activeSection 当前 section
 * @param int    $groupCount    分组数量
 * @param int    $userCount     用户总数（可选）
 */
$activeSection = $activeSection ?? 'overview';
$groupCount = (int)($groupCount ?? 0);
$userCount = (int)($userCount ?? 0);

function groupsMenuIcon(string $id): string
{
    $icons = [
        'overview' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 11l8-7 8 7v9a1 1 0 01-1 1h-5v-6H10v6H5a1 1 0 01-1-1z"/>',
        'groups' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16v12H4z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 10h10M7 14h6"/>',
        'traffic' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v18"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 7H9a3 3 0 000 6h6a3 3 0 010 6H8"/>',
    ];
    $path = $icons[$id] ?? $icons['overview'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" aria-hidden="true">' . $path . '</svg>';
}

$items = [
    ['id' => 'overview', 'label' => '总览', 'hint' => '入口与状态', 'badge' => null],
    ['id' => 'groups', 'label' => '分组列表', 'hint' => '新增/删除/用户数', 'badge' => $groupCount ?: null],
    ['id' => 'traffic', 'label' => '流量配置', 'hint' => '默认流量/有效期/同步', 'badge' => null],
];
?>

<aside class="w-52 shrink-0">
    <nav class="sticky top-4 rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden" aria-label="分组管理导航">
        <div class="border-b border-gray-100 bg-gradient-to-b from-gray-50 to-white px-3 py-2">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-white text-gray-700 border border-gray-200 shadow-sm" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16v12H4z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 10h10M7 14h6"/>
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-gray-700 uppercase tracking-wide">分组管理</p>
                    <p class="mt-0.5 text-[11px] text-gray-400">用户组 / 流量</p>
                </div>
            </div>
            <?php if ($userCount > 0): ?>
                <div class="mt-2 flex flex-wrap gap-2">
                    <span class="inline-flex items-center gap-2 rounded-full bg-gray-50 px-2.5 py-1 text-[11px] text-gray-700 ring-1 ring-gray-200">
                        用户 <?= (int)$userCount ?>
                    </span>
                </div>
            <?php endif; ?>
        </div>

        <ul class="py-2 max-h-[70vh] overflow-auto">
            <?php foreach ($items as $it): ?>
                <?php $isActive = $activeSection === $it['id']; ?>
                <li>
                    <a
                        href="?section=<?= urlencode($it['id']) ?>"
                        class="group relative mx-2 flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-200 <?= $isActive ? 'bg-blue-50 text-blue-800 font-semibold ring-1 ring-blue-100 shadow-sm' : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900' ?>"
                        <?= $isActive ? 'aria-current="page"' : '' ?>
                    >
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-md <?= $isActive ? 'bg-white text-blue-700 border border-blue-100' : 'bg-white text-gray-500 border border-gray-200 group-hover:text-gray-700' ?>" aria-hidden="true">
                            <?= groupsMenuIcon($it['id']) ?>
                        </span>
                        <span class="min-w-0 flex-1">
                            <span class="block truncate"><?= htmlspecialchars($it['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <span class="block truncate text-[11px] <?= $isActive ? 'text-blue-600/70' : 'text-gray-400' ?>">
                                <?= htmlspecialchars($it['hint'], ENT_QUOTES, 'UTF-8') ?>
                            </span>
                        </span>
                        <?php if ($it['badge'] !== null): ?>
                            <span class="shrink-0 rounded-full bg-gray-50 px-2.5 py-1 text-[11px] text-gray-700 ring-1 ring-gray-200">
                                <?= (int)$it['badge'] ?>
                            </span>
                        <?php endif; ?>
                        <?php if ($isActive): ?>
                            <span class="absolute left-0 top-1 bottom-1 w-1 rounded-r bg-blue-600" aria-hidden="true"></span>
                        <?php endif; ?>
                    </a>
                </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</aside>

