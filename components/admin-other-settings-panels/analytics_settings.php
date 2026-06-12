<?php
/** @var array $analyticsConfig */
/** @var string $message */
/** @var string $error */
?>
<div class="px-4 py-4">
    <?php if ($message): ?>
    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-600">
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <p class="text-sm text-gray-500 mb-4">
        低成本减负：关闭统计、限制 IP 写入频率、自动清理过期 IP、后台排行榜仅展示 Top N。
    </p>

    <form method="POST" class="max-w-2xl space-y-5">
        <input type="hidden" name="panel" value="analytics_settings">

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4 space-y-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900">数据分析总开关</p>
                    <p class="mt-1 text-xs text-gray-500">关闭后停止一切统计写入（后台仍可查看已有数据）。</p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input type="checkbox" name="analytics_enabled" value="1" class="peer sr-only" <?= !empty($analyticsConfig['enabled']) ? 'checked' : '' ?>>
                    <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600"></span>
                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                </label>
            </div>

            <div class="border-t border-gray-200 pt-4 space-y-3">
                <p class="text-xs font-medium text-gray-700">分项开关（需总开关开启）</p>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="analytics_ip_enabled" value="1" <?= !empty($analyticsConfig['ip_enabled']) ? 'checked' : '' ?>>
                    IP 访问统计
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="analytics_login_enabled" value="1" <?= !empty($analyticsConfig['login_enabled']) ? 'checked' : '' ?>>
                    用户登录统计
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="analytics_clicks_enabled" value="1" <?= !empty($analyticsConfig['clicks_enabled']) ? 'checked' : '' ?>>
                    视频点击统计
                </label>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="analytics_ip_throttle_minutes">IP 统计间隔（分钟）</label>
                <input type="number" name="analytics_ip_throttle_minutes" id="analytics_ip_throttle_minutes" min="1" max="1440"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                       value="<?= (int)$analyticsConfig['ip_throttle_minutes'] ?>">
                <p class="mt-1 text-xs text-gray-500">同一会话内最短间隔，建议 15。</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="analytics_ip_retention_days">IP 数据保留（天）</label>
                <input type="number" name="analytics_ip_retention_days" id="analytics_ip_retention_days" min="7" max="3650"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                       value="<?= (int)$analyticsConfig['ip_retention_days'] ?>">
                <p class="mt-1 text-xs text-gray-500">超期自动删除，建议 90。</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="analytics_ranking_limit">排行榜展示条数</label>
                <input type="number" name="analytics_ranking_limit" id="analytics_ranking_limit" min="10" max="500"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                       value="<?= (int)$analyticsConfig['ranking_limit'] ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="analytics_growth_max_days">用户增长「全部」最多天数</label>
                <input type="number" name="analytics_growth_max_days" id="analytics_growth_max_days" min="30" max="3650"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                       value="<?= (int)$analyticsConfig['growth_max_days'] ?>">
            </div>
        </div>

        <div class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-xs text-blue-900">
            IP 仅在 <strong>首页、播放页、登录页</strong> 记录；管理后台与 API 不写入。IP 归属地仅在首次出现该 IP 时查询。
        </div>

        <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            保存设置
        </button>
    </form>
</div>
