<?php
/** @var string $analyticsDescription */
/** @var PDO $pdo */
require_once __DIR__ . '/../../includes/user_login_analytics.php';

$ranking = getUserLoginRanking($pdo);
?>
<div class="px-4 py-4">
    <p class="text-sm text-gray-500 mb-4">
        <?= htmlspecialchars($analyticsDescription, ENT_QUOTES, 'UTF-8') ?>
    </p>

    <?php if (empty($ranking)): ?>
    <div class="rounded-lg border border-dashed border-gray-200 bg-gray-50 px-6 py-12 text-center text-sm text-gray-400">
        暂无登录数据，用户成功登录后将自动统计。
    </div>
    <?php else: ?>
    <div class="overflow-x-auto rounded-lg border border-gray-200">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs font-semibold text-gray-500 uppercase tracking-wide">
                <tr>
                    <th class="px-4 py-3">用户名</th>
                    <th class="px-4 py-3">邮箱</th>
                    <th class="px-4 py-3 w-28 text-center">登录次数</th>
                    <th class="px-4 py-3 w-20 text-center">排行</th>
                </tr>
            </thead>
            <tbody class="divide-y divide-gray-100">
                <?php foreach ($ranking as $item): ?>
                <tr class="hover:bg-gray-50 transition-colors">
                    <td class="px-4 py-3">
                        <span class="font-medium <?= $item['exists'] ? 'text-gray-900' : 'text-gray-400' ?>">
                            <?= htmlspecialchars($item['username'], ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </td>
                    <td class="px-4 py-3 text-gray-600">
                        <?= htmlspecialchars($item['email'], ENT_QUOTES, 'UTF-8') ?>
                    </td>
                    <td class="px-4 py-3 text-center">
                        <span class="inline-flex min-w-[3rem] items-center justify-center rounded-full bg-blue-50 px-2.5 py-0.5 font-semibold text-blue-700">
                            <?= (int)$item['logins'] ?>
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
    <p class="mt-3 text-xs text-gray-400">按登录次数从高到低排序；每次用户成功登录计 1 次。</p>
    <?php endif; ?>
</div>
