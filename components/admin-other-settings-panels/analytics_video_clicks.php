<?php
/** @var string $analyticsDescription */
/** @var PDO $pdo */
require_once __DIR__ . '/../../includes/video_click_analytics.php';

$ranking = getVideoClickRanking($pdo);
?>
<div class="px-4 py-4">
    <p class="text-sm text-gray-500 mb-4">
        <?= htmlspecialchars($analyticsDescription, ENT_QUOTES, 'UTF-8') ?>
    </p>

    <?php if (empty($ranking)): ?>
    <div class="rounded-lg border border-dashed border-gray-200 bg-gray-50 px-6 py-12 text-center text-sm text-gray-400">
        暂无点击数据，用户访问播放页后将自动统计。
    </div>
    <?php else: ?>
    <div class="overflow-x-auto rounded-lg border border-gray-200">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-3 w-24">缩略图</th>
                    <th class="px-4 py-3">视频名称</th>
                    <th class="px-4 py-3 w-24 text-center">集数</th>
                    <th class="px-4 py-3 w-28 text-center">点击量</th>
                    <th class="px-4 py-3 w-20 text-center">排行</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($ranking as $item): ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3">
                        <?php if ($item['exists']): ?>
                        <a
                            href="<?= htmlspecialchars($item['play_url'], ENT_QUOTES, 'UTF-8') ?>"
                            class="block w-20 aspect-video overflow-hidden rounded-md border border-gray-200 bg-gray-100 hover:ring-2 hover:ring-blue-400 transition"
                            title="前往播放：<?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>"
                        >
                            <?php if ($item['cover']): ?>
                            <img
                                src="<?= htmlspecialchars($item['cover'], ENT_QUOTES, 'UTF-8') ?>"
                                alt=""
                                class="h-full w-full object-cover"
                                loading="lazy"
                            >
                            <?php else: ?>
                            <span class="flex h-full items-center justify-center text-[10px] text-gray-400">无封面</span>
                            <?php endif; ?>
                        </a>
                        <?php else: ?>
                        <div class="flex w-20 aspect-video items-center justify-center rounded-md border border-dashed border-gray-200 bg-gray-50 text-[10px] text-gray-400">
                            已删除
                        </div>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3">
                        <?php if ($item['exists']): ?>
                        <a
                            href="<?= htmlspecialchars($item['play_url'], ENT_QUOTES, 'UTF-8') ?>"
                            class="font-medium text-gray-900 hover:text-blue-600 line-clamp-2"
                        >
                            <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                        </a>
                        <?php else: ?>
                        <span class="font-medium text-gray-400 line-clamp-2">
                            <?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                        <?php endif; ?>
                    </td>
                    <td class="px-4 py-3 text-center text-gray-700">
                        <?= (int)$item['episode_count'] ?> 集
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="inline-flex min-w-[3rem] items-center justify-center rounded-full bg-blue-50 px-2.5 py-0.5 font-semibold text-blue-700">
                            <?= (int)$item['clicks'] ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <?php
                        $rank = (int)$item['rank'];
                        $rankClass = $rank <= 3 ? 'bg-amber-100 text-amber-800' : 'bg-gray-100 text-gray-700';
                        ?>
                        <span class="inline-flex h-8 w-8 items-center justify-center rounded-full text-sm font-bold <?= $rankClass ?>">
                            <?= $rank ?>
                        </span>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
    <p class="mt-3 text-xs text-gray-400">按点击量从高到低排序；点击缩略图或名称可跳转至对应视频播放页。</p>
    <?php endif; ?>
</div>
