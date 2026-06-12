<?php
/**
 * 邮局管理 — 左侧一级导航
 *
 * @param array  $menu           菜单配置（includes/mail_menu.php）
 * @param string $activeSection  当前一级 id
 */
$activeSection = $activeSection ?? '';

/** @return string SVG icon html */
function mailMenuIcon(string $id): string
{
    $icons = [
        'overview' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 11l8-7 8 7v9a1 1 0 01-1 1h-5v-6H10v6H5a1 1 0 01-1-1z"/>',
        'config' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.4 15a7.9 7.9 0 00.1-1l2-1.1-2-3.5-2.3.4a8 8 0 00-1.7-1l-.3-2.3H9.8l-.3 2.3a8 8 0 00-1.7 1L5.5 9.4l-2 3.5 2 1.1a7.9 7.9 0 00.1 1L3.5 16.1l2 3.5 2.3-.4a8 8 0 001.7 1l.3 2.3h4.4l.3-2.3a8 8 0 001.7-1l2.3.4 2-3.5-2.1-1.1z" opacity=".35"/>',
        'register_code' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 10h8M8 14h5"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 4h12v16H6z"/>',
        'broadcast' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 10h12M6 14h9"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16v12H4z"/>',
        'targeted' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 12a4 4 0 100-8 4 4 0 000 8z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 20a8 8 0 0116 0"/>',
        'feedback_notify' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M21 15a4 4 0 01-4 4H8l-5 3V7a4 4 0 014-4h10a4 4 0 014 4z"/>',
        'password_reset' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11V8a3 3 0 00-6 0v3"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 11h10v9H6z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M14 5l4 4m0-4l-4 4"/>',
        'account_activation' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12l2 2 6-6"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16v12H4z"/>',
        'ban_notice' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4a8 8 0 108 8 8 8 0 00-8-8z"/>',
    ];
    $path = $icons[$id] ?? '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16v12H4z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7l8 6 8-6"/>';
    return '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" aria-hidden="true">' . $path . '</svg>';
}
?>
<aside class="w-52 shrink-0">
    <nav class="sticky top-4 rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden" aria-label="邮局管理导航">
        <div class="border-b border-gray-100 bg-gradient-to-b from-gray-50 to-white px-3 py-2">
            <div class="flex items-center gap-2">
                <span class="inline-flex h-7 w-7 items-center justify-center rounded-lg bg-white text-gray-700 border border-gray-200 shadow-sm" aria-hidden="true">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-4 w-4" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16v12H4z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7l8 6 8-6"/>
                    </svg>
                </span>
                <div class="min-w-0">
                    <p class="text-xs font-semibold text-gray-700 uppercase tracking-wide">邮局菜单</p>
                    <p class="mt-0.5 text-[11px] text-gray-400">SMTP / 通知 / 模板</p>
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
                $isActive = $activeSection === $groupId && (!$hasChildren || ($activeItem ?? '') === '');
            ?>
            <li>
                <?php if ($hasChildren): ?>
                <button
                    type="button"
                    class="mail-l1 flex w-full items-center justify-between px-3 py-2 text-sm text-gray-700 hover:bg-gray-50 transition-colors"
                    data-section="<?= htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8') ?>"
                    aria-expanded="<?= $isOpen ? 'true' : 'false' ?>"
                >
                    <span><?= htmlspecialchars($group['label'], ENT_QUOTES, 'UTF-8') ?></span>
                    <svg class="mail-l1-icon w-4 h-4 text-gray-400 transition-transform <?= $isOpen ? 'rotate-90' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/>
                    </svg>
                </button>
                <ul class="mail-l2 overflow-hidden transition-all duration-200 <?= $isOpen ? '' : 'hidden' ?>" data-section-panel="<?= htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8') ?>">
                    <?php foreach ($children as $child): ?>
                    <?php $childActive = $isOpen && ($activeItem ?? '') === $child['id']; ?>
                    <li>
                        <a
                            href="?section=<?= urlencode($groupId) ?>&item=<?= urlencode($child['id']) ?>"
                            class="block pl-6 pr-3 py-1.5 text-sm transition-colors <?= $childActive ? 'bg-blue-50 text-blue-600 font-medium' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' ?>"
                        >
                            <?= htmlspecialchars($child['label'], ENT_QUOTES, 'UTF-8') ?>
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
                        <?= mailMenuIcon($groupId) ?>
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
document.querySelectorAll('.mail-l1').forEach(btn => {
    btn.addEventListener('click', () => {
        const section = btn.dataset.section;
        const panel = document.querySelector(`[data-section-panel="${section}"]`);
        if (!panel) return;
        const icon = btn.querySelector('.mail-l1-icon');
        const isOpen = !panel.classList.contains('hidden');

        document.querySelectorAll('[data-section-panel]').forEach(p => p.classList.add('hidden'));
        document.querySelectorAll('.mail-l1').forEach(b => {
            b.setAttribute('aria-expanded', 'false');
            b.querySelector('.mail-l1-icon')?.classList.remove('rotate-90');
        });

        if (!isOpen) {
            panel.classList.remove('hidden');
            btn.setAttribute('aria-expanded', 'true');
            icon?.classList.add('rotate-90');
        }
    });
});
</script>
