<?php
/**
 * 用户管理 — 左侧导航
 *
 * @param string $activeSection
 * @param int    $userCount
 * @param int    $bannedCount
 * @param int    $frozenCount
 * @param int    $timedBanCount
 */
$activeSection = $activeSection ?? 'overview';
$userCount = (int)($userCount ?? 0);
$bannedCount = (int)($bannedCount ?? 0);
$frozenCount = (int)($frozenCount ?? 0);
$timedBanCount = (int)($timedBanCount ?? 0);

function usersMenuIcon(string $id): string
{
    $icons = [
        'overview' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 11l8-7 8 7v9a1 1 0 01-1 1h-5v-6H10v6H5a1 1 0 01-1-1z"/>',
        'users' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="8.5" cy="7" r="4"/>',
        'banned' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M18 6L6 18"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12"/>',
        'frozen' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v18"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 7h8"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 11h12"/>',
        'timed_ban' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 8v4l3 3"/><circle cx="12" cy="12" r="9"/>',
    ];
    $path = $icons[$id] ?? $icons['overview'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" aria-hidden="true">' . $path . '</svg>';
}

$items = [
    ['id' => 'overview', 'label' => '总览', 'hint' => '统计与入口', 'badge' => null],
    ['id' => 'users', 'label' => '用户列表', 'hint' => '管理状态/分组/密码', 'badge' => $userCount ?: null],
    ['id' => 'banned', 'label' => '封禁用户', 'hint' => '已封禁账号', 'badge' => $bannedCount ?: null],
    ['id' => 'frozen', 'label' => '冻结用户', 'hint' => '已冻结账号', 'badge' => $frozenCount ?: null],
    ['id' => 'timed_ban', 'label' => '定时封禁', 'hint' => '限时封禁账号', 'badge' => $timedBanCount ?: null],
];
?>

<aside class="w-52 shrink-0">
    <nav class="sticky top-4 rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden" aria-label="用户管理导航">
        <div class="border-b border-gray-100 bg-gradient-to-b from-gray-50 to-white px-3 py-2">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-white text-gray-700 border border-gray-200 shadow-sm" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/>
                        <circle cx="8.5" cy="7" r="4"/>
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-gray-700 uppercase tracking-wide">用户管理</p>
                    <p class="mt-0.5 text-[11px] text-gray-400">封禁 / 冻结 / 分组</p>
                </div>
            </div>

            <div class="mt-2 flex flex-wrap gap-2">
                <span class="inline-flex items-center gap-2 rounded-full bg-gray-50 px-2.5 py-1 text-[11px] text-gray-700 ring-1 ring-gray-200">
                    总数 <?= (int)$userCount ?>
                </span>
                <?php if ($bannedCount > 0): ?>
                    <span class="inline-flex items-center gap-2 rounded-full bg-red-50 px-2.5 py-1 text-[11px] text-red-700 ring-1 ring-red-100">
                        封禁 <?= (int)$bannedCount ?>
                    </span>
                <?php endif; ?>
                <?php if ($frozenCount > 0): ?>
                    <span class="inline-flex items-center gap-2 rounded-full bg-amber-50 px-2.5 py-1 text-[11px] text-amber-800 ring-1 ring-amber-100">
                        冻结 <?= (int)$frozenCount ?>
                    </span>
                <?php endif; ?>
                <?php if ($timedBanCount > 0): ?>
                    <span class="inline-flex items-center gap-2 rounded-full bg-orange-50 px-2.5 py-1 text-[11px] text-orange-700 ring-1 ring-orange-100">
                        定时 <?= (int)$timedBanCount ?>
                    </span>
                <?php endif; ?>
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
                            <?= usersMenuIcon($it['id']) ?>
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
