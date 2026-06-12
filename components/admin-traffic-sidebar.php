<?php
/**
 * 流量管理 — 左侧导航
 *
 * @param string $activeSection 当前 section
 * @param int    $userCount     用户数
 * @param int    $groupCount    分组数
 * @param int    $unlockCount   解锁记录数（当前页展示）
 * @param int    $logCount      日志数（当前页展示）
 */
$activeSection = $activeSection ?? 'users';
$userCount = (int)($userCount ?? 0);
$groupCount = (int)($groupCount ?? 0);
$unlockCount = (int)($unlockCount ?? 0);
$logCount = (int)($logCount ?? 0);

function trafficMenuIcon(string $id): string
{
    $icons = [
        'overview' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 11l8-7 8 7v9a1 1 0 01-1 1h-5v-6H10v6H5a1 1 0 01-1-1z"/>',
        'groups' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h10v10H7z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 4h16v16H4z" opacity=".35"/>',
        'users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 12a4 4 0 100-8 4 4 0 000 8z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 20a8 8 0 0116 0"/>',
        'unlocks' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16v12H4z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9l6 3-6 3z"/>',
        'logs' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 4h12v16H6z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 8h8M8 12h6"/>',
    ];
    $path = $icons[$id] ?? $icons['overview'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" aria-hidden="true">' . $path . '</svg>';
}

$items = [
    [
        'id' => 'overview',
        'label' => '总览',
        'hint' => '关键状态与入口',
        'badge' => null,
    ],
    [
        'id' => 'groups',
        'label' => '用户组配置',
        'hint' => '默认流量/有效期/重置',
        'badge' => $groupCount > 0 ? $groupCount : null,
    ],
    [
        'id' => 'users',
        'label' => '用户流量',
        'hint' => '查询与管理用户',
        'badge' => $userCount > 0 ? $userCount : null,
    ],
    [
        'id' => 'unlocks',
        'label' => '解锁记录',
        'hint' => '最近 50 条',
        'badge' => $unlockCount > 0 ? $unlockCount : null,
    ],
    [
        'id' => 'logs',
        'label' => '变更日志',
        'hint' => '最近 50 条',
        'badge' => $logCount > 0 ? $logCount : null,
    ],
];
?>

<aside class="w-52 shrink-0">
    <nav class="sticky top-4 rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden" aria-label="流量管理导航">
        <div class="border-b border-gray-100 bg-gradient-to-b from-gray-50 to-white px-3 py-2">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-white text-gray-700 border border-gray-200 shadow-sm" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 1v22"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 5H9.5a3.5 3.5 0 000 7H14a3.5 3.5 0 010 7H7"/>
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-gray-700 uppercase tracking-wide">流量管理</p>
                    <p class="mt-0.5 text-[11px] text-gray-400">分组 / 用户 / 记录</p>
                </div>
            </div>
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
                            <?= trafficMenuIcon($it['id']) ?>
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

