<?php

/**
 * 上传管理 — 侧边导航（新版）
 *
 * @var list<array<string, mixed>> $menu
 * @var string $activeSection
 * @var string $activeItem
 * @var int    $pendingCount
 */
require_once __DIR__ . '/../includes/upload_menu_helper.php';

$menu = $menu ?? uploadMenuItems();
$activeSection = $activeSection ?? '';
$activeItem = $activeItem ?? '';
$pendingCount = $pendingCount ?? 0;
?>
<aside class="w-56 shrink-0">
    <nav class="sticky top-4 rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden" aria-label="上传管理">
        <div class="border-b border-gray-100 bg-gradient-to-r from-slate-50 to-white px-4 py-3">
            <p class="text-sm font-semibold text-gray-900">视频上传</p>
            <p class="mt-0.5 text-xs text-gray-500">PHP 上传 · 审核 · 转码</p>
        </div>

        <ul class="py-2">
            <?php foreach ($menu as $group): ?>
                <?php
                $groupId = (string)($group['id'] ?? '');
                $children = is_array($group['children'] ?? null) ? $group['children'] : [];
                $hasChildren = $children !== [];
                $icon = (string)($group['icon'] ?? 'default');
                $isGroupOpen = $activeSection === $groupId;
                $groupHref = uploadMenuUrl($groupId);
                $isTopActive = $isGroupOpen && (!$hasChildren || $activeItem === '');
                ?>
                <li class="px-2">
                    <?php if ($hasChildren): ?>
                        <button
                            type="button"
                            class="upload-nav-group flex w-full items-center gap-2 rounded-lg px-2.5 py-2 text-left text-sm transition-colors <?= $isGroupOpen ? 'bg-slate-100 text-slate-900' : 'text-gray-700 hover:bg-gray-50' ?>"
                            data-panel="<?= htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8') ?>"
                            aria-expanded="<?= $isGroupOpen ? 'true' : 'false' ?>"
                        >
                            <svg class="h-5 w-5 shrink-0 text-gray-500" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><?= uploadMenuIconSvg($icon) ?></svg>
                            <span class="min-w-0 flex-1 font-medium truncate"><?= htmlspecialchars((string)$group['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <svg class="upload-nav-chevron h-4 w-4 shrink-0 text-gray-400 transition-transform <?= $isGroupOpen ? 'rotate-90' : '' ?>" fill="none" stroke="currentColor" viewBox="0 0 24 24"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M9 5l7 7-7 7"/></svg>
                        </button>
                        <ul
                            class="upload-nav-children mt-0.5 space-y-0.5 pl-2 <?= $isGroupOpen ? '' : 'hidden' ?>"
                            data-nav-children="<?= htmlspecialchars($groupId, ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php foreach ($children as $child): ?>
                                <?php
                                $childId = (string)($child['id'] ?? '');
                                $childActive = $isGroupOpen && $activeItem === $childId;
                                $badgeType = (string)($child['badge'] ?? '');
                                $showBadge = $badgeType === 'pending' && $pendingCount > 0;
                                ?>
                                <li>
                                    <a
                                        href="<?= htmlspecialchars(uploadMenuUrl($groupId, $childId), ENT_QUOTES, 'UTF-8') ?>"
                                        class="flex items-center gap-2 rounded-lg py-1.5 pl-7 pr-2 text-sm transition-colors <?= $childActive ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-600 hover:bg-gray-50 hover:text-gray-900' ?>"
                                        <?= $childActive ? 'aria-current="page"' : '' ?>
                                    >
                                        <span class="min-w-0 flex-1 truncate"><?= htmlspecialchars((string)$child['label'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if ($showBadge): ?>
                                            <span class="shrink-0 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800"><?= (int)$pendingCount ?></span>
                                        <?php endif; ?>
                                    </a>
                                </li>
                            <?php endforeach; ?>
                        </ul>
                    <?php else: ?>
                        <a
                            href="<?= htmlspecialchars($groupHref, ENT_QUOTES, 'UTF-8') ?>"
                            class="flex items-center gap-2 rounded-lg px-2.5 py-2 text-sm transition-colors <?= $isTopActive ? 'bg-blue-50 text-blue-700 font-medium' : 'text-gray-700 hover:bg-gray-50' ?>"
                            <?= $isTopActive ? 'aria-current="page"' : '' ?>
                        >
                            <svg class="h-5 w-5 shrink-0" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><?= uploadMenuIconSvg($icon) ?></svg>
                            <span class="truncate"><?= htmlspecialchars((string)$group['label'], ENT_QUOTES, 'UTF-8') ?></span>
                            <?php if ($groupId === 'overview' && $pendingCount > 0): ?>
                                <span class="ml-auto shrink-0 rounded-full bg-amber-100 px-1.5 py-0.5 text-[10px] font-semibold text-amber-800"><?= (int)$pendingCount ?></span>
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                </li>
            <?php endforeach; ?>
        </ul>

        <div class="border-t border-gray-100 px-3 py-3">
            <a href="../upload.php" target="_blank" rel="noreferrer" class="flex items-center justify-center gap-1 rounded-lg border border-dashed border-gray-300 px-3 py-2 text-xs font-medium text-gray-600 hover:border-blue-300 hover:bg-blue-50 hover:text-blue-700">
                打开用户上传页 ↗
            </a>
        </div>
    </nav>
</aside>
<script>
document.querySelectorAll('.upload-nav-group').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var id = btn.dataset.panel;
        var panel = document.querySelector('[data-nav-children="' + id + '"]');
        if (!panel) return;
        var willOpen = panel.classList.contains('hidden');
        document.querySelectorAll('[data-nav-children]').forEach(function (p) { p.classList.add('hidden'); });
        document.querySelectorAll('.upload-nav-group').forEach(function (b) {
            b.setAttribute('aria-expanded', 'false');
            b.querySelector('.upload-nav-chevron')?.classList.remove('rotate-90');
            b.classList.remove('bg-slate-100', 'text-slate-900');
        });
        if (willOpen) {
            panel.classList.remove('hidden');
            btn.setAttribute('aria-expanded', 'true');
            btn.querySelector('.upload-nav-chevron')?.classList.add('rotate-90');
            btn.classList.add('bg-slate-100', 'text-slate-900');
        }
    });
});
</script>
