<?php
/** @var string $message */
/** @var string $error */
/** @var bool $registerEnabled */
/** @var array $analyticsConfig */
/** @var array $bgConfig */
/** @var bool $playerProxyEnabled */
/** @var array $redisConfig */
/** @var bool $redisExtensionLoaded */
/** @var bool $redisConfiguredEnabled */
/** @var bool $mailConfigured */
/** @var int $announcementCount */

$bgMode = (string)($bgConfig['mode'] ?? 'none');
$bgModeLabel = [
    'none' => '未启用',
    'color' => '纯色背景',
    'image' => '图片背景',
][$bgMode] ?? $bgMode;

$redisEnabled = !empty($redisConfig['enabled']);
$redisLabel = $redisEnabled ? '已启用' : '未启用';
$redisHint = $redisExtensionLoaded ? '扩展已安装' : '未安装扩展';
?>

<div class="px-4 py-5">
    <?php if ($message): ?>
        <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <?php if ($error): ?>
        <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="flex flex-wrap items-start justify-between gap-4">
        <div class="min-w-0">
            <p class="text-sm font-semibold text-gray-900">设置总览</p>
            <p class="mt-1 text-xs text-gray-500">快速查看关键开关状态，并一键进入对应配置页。</p>
        </div>
        <a href="?section=overview"
           class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
            刷新
        </a>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <a href="?section=register&item=toggle"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 4h12v16H6z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 8h8M8 12h6"/>
                    </svg>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium <?= $registerEnabled ? 'bg-green-50 text-green-700 ring-1 ring-green-100' : 'bg-amber-50 text-amber-700 ring-1 ring-amber-100' ?>">
                    <?= $registerEnabled ? '已开启' : '已关闭' ?>
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">注册功能</p>
            <p class="mt-1 text-xs text-gray-500">注册开关 / 页面配置 / 定时开关</p>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入配置 →</p>
        </a>

        <a href="?section=analytics&item=settings"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12v7m5-10v10m5-13v13m5-8v8"/>
                    </svg>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium <?= !empty($analyticsConfig['enabled']) ? 'bg-green-50 text-green-700 ring-1 ring-green-100' : 'bg-gray-50 text-gray-600 ring-1 ring-gray-200' ?>">
                    <?= !empty($analyticsConfig['enabled']) ? '已启用' : '未启用' ?>
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">数据分析</p>
            <p class="mt-1 text-xs text-gray-500">统计开关 / 减负设置 / 趋势报表</p>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入配置 →</p>
        </a>

        <a href="?section=theme&item=background"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3a9 9 0 100 18 7 7 0 010-18z"/>
                    </svg>
                </div>
                <span class="rounded-full bg-blue-50 text-blue-700 ring-1 ring-blue-100 px-2.5 py-1 text-xs font-medium">
                    <?= htmlspecialchars($bgModeLabel, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">主题外观</p>
            <p class="mt-1 text-xs text-gray-500">背景 / 深浅色配色 / 全局字体</p>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入配置 →</p>
        </a>

        <a href="?section=player&item=proxy"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16v12H4z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M10 9l6 3-6 3z"/>
                    </svg>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium <?= $playerProxyEnabled ? 'bg-green-50 text-green-700 ring-1 ring-green-100' : 'bg-gray-50 text-gray-600 ring-1 ring-gray-200' ?>">
                    <?= $playerProxyEnabled ? '代理已就绪' : '未就绪' ?>
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">播放器管理</p>
            <p class="mt-1 text-xs text-gray-500">后端代理 / Token / 数据同步</p>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入配置 →</p>
        </a>

        <a href="?section=redis&item=config"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3c4 0 7 1.5 7 3.5S16 10 12 10 5 8.5 5 6.5 8 3 12 3z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 6.5V17.5C5 19.5 8 21 12 21s7-1.5 7-3.5V6.5"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M5 12c0 2 3 3.5 7 3.5s7-1.5 7-3.5"/>
                    </svg>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium <?= ($redisEnabled && $redisExtensionLoaded) ? 'bg-green-50 text-green-700 ring-1 ring-green-100' : 'bg-amber-50 text-amber-700 ring-1 ring-amber-100' ?>">
                    <?= htmlspecialchars($redisLabel . ' · ' . $redisHint, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">Redis</p>
            <p class="mt-1 text-xs text-gray-500">进度监听 / 缓存（需要启用 + 安装扩展）</p>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入配置 →</p>
        </a>

        <a href="?section=announcement"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 11l14-6v14L4 13v-2z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 13l1 6"/>
                    </svg>
                </div>
                <span class="rounded-full bg-gray-50 text-gray-700 ring-1 ring-gray-200 px-2.5 py-1 text-xs font-medium">
                    <?= (int)$announcementCount ?> 条
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">公告管理</p>
            <p class="mt-1 text-xs text-gray-500">公告模板 / 发布与编辑 / 发送通知</p>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入配置 →</p>
        </a>

        <a href="?section=earning_traffic"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 1v22"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 5H9.5a3.5 3.5 0 000 7H14a3.5 3.5 0 010 7H7"/>
                    </svg>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium <?= trafficFeatureEnabled($pdo) ? 'bg-green-50 text-green-700 ring-1 ring-green-100' : 'bg-gray-50 text-gray-600 ring-1 ring-gray-200' ?>">
                    <?= trafficFeatureEnabled($pdo) ? '已启用流量功能' : '未启用' ?>
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">收益流量管理</p>
            <p class="mt-1 text-xs text-gray-500">冻结 / 回收 / 按用户处理</p>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入管理 →</p>
        </a>

        <a href="../admin/mail.php?section=config"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16v12H4z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7l8 6 8-6"/>
                    </svg>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium <?= $mailConfigured ? 'bg-green-50 text-green-700 ring-1 ring-green-100' : 'bg-amber-50 text-amber-700 ring-1 ring-amber-100' ?>">
                    <?= $mailConfigured ? 'SMTP 已配置' : '未配置' ?>
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">邮局 / 邮件</p>
            <p class="mt-1 text-xs text-gray-500">SMTP / 通知 / 邮件模板</p>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入邮局 →</p>
        </a>
    </div>

    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-4">
        <p class="text-sm font-semibold text-gray-900">快捷入口</p>
        <p class="mt-1 text-xs text-gray-500">常用子项一键直达。</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <a class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
               href="?section=register&item=page">注册页面配置</a>
            <a class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
               href="?section=register&item=schedule">定时开关注册</a>
            <a class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
               href="?section=theme&item=dark_colors">深色主题颜色</a>
            <a class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
               href="?section=theme&item=light_colors">浅色主题颜色</a>
            <a class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
               href="?section=font&item=global">全局字体</a>
            <a class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
               href="?section=player&item=video_token">视频 Token</a>
            <a class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
               href="?section=player&item=api_sync">视频数据同步</a>
        </div>
    </div>
</div>

