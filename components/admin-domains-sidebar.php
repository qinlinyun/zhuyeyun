<?php
/**
 * 域名管理 — 左侧导航
 *
 * @param string $activeSection 当前 section
 * @param bool   $sgFeature     是否支持服务器组
 * @param bool   $bundleSgFeature 是否支持用户组绑定服务器组
 * @param int    $domainCount   域名数量
 * @param int    $serverGroupCount 服务器组数量
 * @param int    $groupCount    用户组数量
 */
$activeSection = $activeSection ?? 'overview';
$sgFeature = !empty($sgFeature);
$bundleSgFeature = !empty($bundleSgFeature);
$domainCount = (int)($domainCount ?? 0);
$serverGroupCount = (int)($serverGroupCount ?? 0);
$groupCount = (int)($groupCount ?? 0);

function domainsMenuIcon(string $id): string
{
    $icons = [
        'overview' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 11l8-7 8 7v9a1 1 0 01-1 1h-5v-6H10v6H5a1 1 0 01-1-1z"/>',
        'server_groups' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16v12H4z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 10h.01M7 14h.01M11 10h8M11 14h8"/>',
        'domains' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2a10 10 0 100 20 10 10 0 000-20z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2 12h20"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2c2.8 2.7 4.5 6.2 4.5 10S14.8 19.3 12 22c-2.8-2.7-4.5-6.2-4.5-10S9.2 4.7 12 2z" opacity=".35"/>',
        'assign' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 12a4 4 0 100-8 4 4 0 000 8z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 20a8 8 0 0116 0"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M16 11l2 2 4-4"/>',
        'upload_domain_group' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 16a4 4 0 01-.88-7.903A5 5 0 1115.9 6L16 6a5 5 0 011 9.9M15 13l-3-3m0 0l-3 3m3-3v12"/>',
    ];
    $path = $icons[$id] ?? $icons['overview'];
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" aria-hidden="true">' . $path . '</svg>';
}

$items = [
    ['id' => 'overview', 'label' => '总览', 'hint' => '入口与状态', 'badge' => null],
];
if ($sgFeature) {
    $items[] = ['id' => 'server_groups', 'label' => '服务器组', 'hint' => '线路分组管理', 'badge' => $serverGroupCount ?: null];
    $uploadPoolCount = (int)($uploadPoolCount ?? 0);
    $items[] = ['id' => 'upload_domain_group', 'label' => '上传域名组', 'hint' => '上传默认服务器组', 'badge' => $uploadPoolCount ?: null];
}
$items[] = ['id' => 'domains', 'label' => '域名管理', 'hint' => '新增/编辑/删除', 'badge' => $domainCount ?: null];
$items[] = ['id' => 'assign', 'label' => '分配线路', 'hint' => $bundleSgFeature ? '整组 + 手动勾选' : '按域名勾选', 'badge' => $groupCount ?: null];
?>

<aside class="w-52 shrink-0">
    <nav class="sticky top-4 rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden" aria-label="域名管理导航">
        <div class="border-b border-gray-100 bg-gradient-to-b from-gray-50 to-white px-3 py-2">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-white text-gray-700 border border-gray-200 shadow-sm" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 2a10 10 0 100 20 10 10 0 000-20z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M2 12h20"/>
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-gray-700 uppercase tracking-wide">域名管理</p>
                    <p class="mt-0.5 text-[11px] text-gray-400">线路 / 分组 / 分配</p>
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
                            <?= domainsMenuIcon($it['id']) ?>
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

