<?php
/** @var array<int, array<string, mixed>> $comments */
$comments = $comments ?? [];
$redirectBase = 'comments.php?section=' . urlencode($activeSection ?? 'all');
if (($keyword ?? '') !== '') {
    $redirectBase .= '&q=' . urlencode($keyword);
}
if (($videoFilter ?? 0) > 0) {
    $redirectBase .= '&video_id=' . (int)$videoFilter;
}
?>
<div class="overflow-x-auto">
    <table class="min-w-full divide-y divide-gray-100 text-sm">
        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
            <tr>
                <th class="px-4 py-3">ID</th>
                <th class="px-4 py-3">视频</th>
                <th class="px-4 py-3">用户</th>
                <th class="px-4 py-3">内容</th>
                <th class="px-4 py-3">状态</th>
                <th class="px-4 py-3">时间</th>
                <th class="px-4 py-3 text-right">操作</th>
            </tr>
        </thead>
        <tbody class="divide-y divide-gray-100 bg-white">
            <?php foreach ($comments as $c): ?>
                <?php
                $isHidden = ($c['status'] ?? '') === 'hidden';
                $isReply = !empty($c['parent_id']);
                ?>
                <tr class="hover:bg-gray-50/70">
                    <td class="px-4 py-3 text-gray-500">#<?= (int)$c['id'] ?></td>
                    <td class="px-4 py-3">
                        <a href="../play.php?id=<?= (int)$c['video_id'] ?>" target="_blank" rel="noopener" class="font-medium text-blue-600 hover:underline">
                            #<?= (int)$c['video_id'] ?>
                        </a>
                        <div class="mt-0.5 max-w-[160px] truncate text-xs text-gray-500" title="<?= htmlspecialchars((string)($c['video_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars((string)($c['video_title'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                        </div>
                    </td>
                    <td class="px-4 py-3">
                        <div class="font-medium text-gray-900"><?= htmlspecialchars(userDisplayName($c), ENT_QUOTES, 'UTF-8') ?></div>
                        <div class="text-xs text-gray-500">@<?= htmlspecialchars((string)($c['username'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                        <?php if ($isReply): ?>
                            <div class="mt-1 text-xs text-gray-400">回复 #<?= (int)$c['parent_id'] ?></div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 max-w-xs">
                        <p class="line-clamp-3 whitespace-pre-wrap break-words text-gray-700"><?= htmlspecialchars((string)$c['content'], ENT_QUOTES, 'UTF-8') ?></p>
                    </td>
                    <td class="px-4 py-3">
                        <span class="rounded-full px-2 py-1 text-xs <?= $isHidden ? 'bg-amber-50 text-amber-700 ring-1 ring-amber-100' : 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100' ?>">
                            <?= htmlspecialchars(commentStatusLabel((string)$c['status']), ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-xs text-gray-500 whitespace-nowrap"><?= htmlspecialchars(formatChinaDateTime((string)$c['created_at']), ENT_QUOTES, 'UTF-8') ?></td>
                    <td class="px-4 py-3">
                        <div class="flex flex-wrap justify-end gap-2">
                            <?php if ($isHidden): ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="show">
                                    <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectBase, ENT_QUOTES, 'UTF-8') ?>">
                                    <button class="rounded border border-emerald-300 px-2.5 py-1 text-xs font-medium text-emerald-700 hover:bg-emerald-50">恢复</button>
                                </form>
                            <?php else: ?>
                                <form method="post">
                                    <input type="hidden" name="action" value="hide">
                                    <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                                    <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectBase, ENT_QUOTES, 'UTF-8') ?>">
                                    <button class="rounded border border-amber-300 px-2.5 py-1 text-xs font-medium text-amber-700 hover:bg-amber-50">隐藏</button>
                                </form>
                            <?php endif; ?>
                            <form method="post" onsubmit="return confirm('确定删除该评论？<?= $isReply ? '' : '其回复也会一并删除。' ?>');">
                                <input type="hidden" name="action" value="delete">
                                <input type="hidden" name="comment_id" value="<?= (int)$c['id'] ?>">
                                <input type="hidden" name="redirect" value="<?= htmlspecialchars($redirectBase, ENT_QUOTES, 'UTF-8') ?>">
                                <button class="rounded border border-red-300 px-2.5 py-1 text-xs font-medium text-red-600 hover:bg-red-50">删除</button>
                            </form>
                        </div>
                    </td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
