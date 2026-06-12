<?php
/**
 * 其它设置 — 左侧一级 / 二级导航
 *
 * @param array  $menu           菜单配置（includes/other_settings_menu.php）
 * @param string $activeSection  当前一级 id
 * @param string $activeItem     当前二级 id
 */
$activeSection = $activeSection ?? '';
$activeItem = $activeItem ?? '';

/** @return string SVG icon html */
function otherSettingsMenuIcon(string $id): string
{
    $icons = [
        'overview' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 11l8-7 8 7v9a1 1 0 01-1 1h-5v-6H10v6H5a1 1 0 01-1-1z"/>',
        'register' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 4h12v16H6z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 8h8M8 12h6"/>',
        'analytics' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12v7m5-10v10m5-13v13m5-8v8"/>',
        'theme' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3a9 9 0 100 18 7 7 0 010-18z"/>',
        'font' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 20h16"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 20l4-16 4 16"/>',
        'player' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16v12H4z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9l6 3-6 3z"/>',
        'redis' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3c4 0 7 1.5 7 3.5S16 10 12 10 5 8.5 5 6.5 8 3 12 3z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 6.5V17.5C5 19.5 8 21 12 21s7-1.5 7-3.5V6.5"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12c0 2 3 3.5 7 3.5s7-1.5 7-3.5"/>',
        'announcement' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 11l14-6v14L4 13v-2z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 13l1 6"/>',
        'earning_traffic' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 1v22"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 5H9.5a3.5 3.5 0 000 7H14a3.5 3.5 0 010 7H7"/>',
    ];
    $path = $icons[$id] ?? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.4 15a7.9 7.9 0 00.1-1l2-1.1-2-3.5-2.3.4a8 8 0 00-1.7-1l-.3-2.3H9.8l-.3 2.3a8 8 0 00-1.7 1L5.5 9.4l-2 3.5 2 1.1a7.9 7.9 0 00.1 1L3.5 16.1l2 3.5 2.3-.4a8 8 0 001.7 1l.3 2.3h4.4l.3-2.3a8 8 0 001.7-1l2.3.4 2-3.5-2.1-1.1z" opacity=".35"/>';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" aria-hidden="true">' . $path . '</svg>';
}
?>
<aside class="w-52 shrink-0">
    <nav class="sticky top-4 rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden" aria-label="其它设置导航">
        <div class="border-b border-gray-100 bg-gradient-to-b from-gray-50 to-white px-3 py-2">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-white text-gray-700 border border-gray-200 shadow-sm" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.4 15a7.9 7.9 0 00.1-1l2-1.1-2-3.5-2.3.4a8 8 0 00-1.7-1l-.3-2.3H9.8l-.3 2.3a8 8 0 00-1.7 1L5.5 9.4l-2 3.5 2 1.1a7.9 7.9 0 00.1 1L3.5 16.1l2 3.5 2.3-.4a8 8 0 001.7 1l.3 2.3h4.4l.3-2.3a8 8 0 001.7-1l2.3.4 2-3.5-2.1-1.1z" opacity=".35"/>
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-gray-700 uppercase tracking-wide">设置菜单</p>
                    <p class="mt-0.5 text-[11px] text-gray-400">系统 / 主题 / 数据</p>
                </div>
            </div>
        </div>
        <ul class="py-2 max-h-[70vh] overflow-auto">
            <?php foreach ($menu as $group): ?>
            <?php
                $groupId = $group['id'];
                $children = $group['children'] ?? [];
                $hasChildren = !empty($children);
                $isOpen = $activeSection === $groupId;
                $isActive = $isOpen && (!$hasChildren || $activeItem === '');
            ?>
            <li>
                <?php if ($hasChildren): ?>
                <button
                    type="button"
                    class="settings-l1 mx-2 flex w-[calc(100%-1rem)] items-center justify-between rounded-lg px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-200"
                    data-section="<?= htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8') ?>"
                    aria-expanded="<?= $isOpen ? 'true' : 'false' ?>"
                >
                    <span class="flex items-center gap-2 min-w-0">
                        <span class="inline-flex h-7 w-7 items-center justify-center rounded-md bg-white text-gray-500 border border-gray-200" aria-hidden="true">
                            <?= otherSettingsMenuIcon($groupId) ?>
                        </span>
                        <span class="truncate"><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    </span>
                    <svg class="settings-l1-icon w-4 h-4 text-gray-400 transition-transform <?= $isOpen ? 'rotate-90' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
                <ul class="settings-l2 overflow-hidden transition-all duration-200 <?= $isOpen ? '' : 'hidden' ?>" data-section-panel="<?= htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8') ?>">
                    <?php foreach ($children as $child): ?>
                    <?php $childActive = $isOpen && $activeItem === $child['id']; ?>
                    <li>
                        <a
                            href="?section=<?= urlencode($groupId) ?>&item=<?= urlencode($child['id']) ?>"
                            class="group relative mx-2 mt-1 block rounded-lg pl-11 pr-3 py-2 text-sm transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-200 <?= $childActive ? 'bg-blue-50 text-blue-800 font-semibold ring-1 ring-blue-100 shadow-sm' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' ?>"
                            <?= $childActive ? 'aria-current="page"' : '' ?>
                        >
                            <?= htmlspecialchars($child['label'], ENT_QUOTES, 'UTF-8') ?>
                            <?php if ($childActive): ?>
                                <span class="absolute left-0 top-1 bottom-1 w-1 rounded-r bg-blue-600" aria-hidden="true"></span>
                            <?php endif; ?>
                        </a>
                    </li>
                    <?php endforeach; ?>
                </ul>
                <?php else: ?>
                <a
                    href="?section=<?= urlencode($groupId) ?>"
                    class="group relative mx-2 flex items-center gap-2 rounded-lg px-3 py-2 text-sm transition-all duration-150 focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-blue-200 <?= $isActive ? 'bg-blue-50 text-blue-800 font-semibold ring-1 ring-blue-100 shadow-sm' : 'text-gray-700 hover:bg-gray-50 hover:text-gray-900' ?>"
                    <?= $isActive ? 'aria-current="page"' : '' ?>
                >
                    <span class="inline-flex h-7 w-7 items-center justify-center rounded-md <?= $isActive ? 'bg-white text-blue-700 border border-blue-100' : 'bg-white text-gray-500 border border-gray-200 group-hover:text-gray-700' ?>" aria-hidden="true">
                        <?= otherSettingsMenuIcon($groupId) ?>
                    </span>
                    <span class="min-w-0 truncate"><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <span class="ml-auto text-gray-300 transition-colors group-hover:text-gray-400 <?= $isActive ? 'text-blue-300' : '' ?>" aria-hidden="true">
                        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4">
                            <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                        </svg>
                    </span>
                    <?php if ($isActive): ?>
                        <span class="absolute left-0 top-1 bottom-1 w-1 rounded-r bg-blue-600" aria-hidden="true"></span>
                    <?php endif; ?>
                </a>
                <?php endif; ?>
            </li>
            <?php endforeach; ?>
        </ul>
    </nav>
</aside>
<script>
document.querySelectorAll('.settings-l1').forEach(btn => {
    btn.addEventListener('click', () => {
        const section = btn.dataset.section;
        const panel = document.querySelector(`[data-section-panel="${section}"]`);
        if (!panel) return;
        const icon = btn.querySelector('.settings-l1-icon');
        const isOpen = !panel.classList.contains('hidden');

        document.querySelectorAll('[data-section-panel]').forEach(p => p.classList.add('hidden'));
        document.querySelectorAll('.settings-l1').forEach(b => {
            b.setAttribute('aria-expanded', 'false');
            b.querySelector('.settings-l1-icon')?.classList.remove('rotate-90');
        });

        if (!isOpen) {
            panel.classList.remove('hidden');
            btn.setAttribute('aria-expanded', 'true');
            icon?.classList.add('rotate-90');
        }
    });
});
</script>
