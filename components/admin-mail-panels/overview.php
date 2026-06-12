<?php
/** @var string $message */
/** @var string $error */
/** @var bool $mailConfigured */
/** @var array $mailConfig */
/** @var array $registerVerifyConfig */
/** @var array $broadcastConfig */
/** @var array|null $broadcastJob */
/** @var int $broadcastRecipientCount */
/** @var array $passwordResetConfig */
/** @var array $accountActivationConfig */
/** @var int $activationTotal */
/** @var array $banNoticeConfig */
/** @var array<int,array{id:string,name:string,html_template:string,updated_at:string}> $targetedTemplates */

$smtpEnabled = !empty($mailConfig['enabled']);
$smtpMode = !empty($mailConfig['api_enabled']) ? 'API' : 'SMTP';
$smtpHost = trim((string)($mailConfig['host'] ?? ''));
$smtpFrom = trim((string)($mailConfig['from_email'] ?? ''));
$smtpLabel = $mailConfigured ? '已配置' : ($smtpEnabled ? '未就绪' : '未启用');

$broadcastActive = function_exists('mailBroadcastJobIsActive') ? mailBroadcastJobIsActive($broadcastJob) : false;
$broadcastLabel = $broadcastActive ? '任务进行中' : '无进行中任务';
$broadcastProgress = function_exists('mailBroadcastJobProgressLabel') ? mailBroadcastJobProgressLabel($broadcastJob) : '';

$tplCount = is_array($targetedTemplates) ? count($targetedTemplates) : 0;
$tplLatest = '';
if ($tplCount > 0) {
    $tplLatest = (string)($targetedTemplates[0]['updated_at'] ?? '');
}
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
            <p class="text-sm font-semibold text-gray-900">邮局总览</p>
            <p class="mt-1 text-xs text-gray-500">快速查看邮件相关功能状态，并一键进入对应配置页。</p>
        </div>
        <a href="?section=overview"
           class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-600 hover:bg-gray-50">
            刷新
        </a>
    </div>

    <div class="mt-4 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
        <a href="?section=config"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 6.5a5.5 5.5 0 100 11 5.5 5.5 0 000-11z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19.4 15a7.9 7.9 0 00.1-1l2-1.1-2-3.5-2.3.4a8 8 0 00-1.7-1l-.3-2.3H9.8l-.3 2.3a8 8 0 00-1.7 1L5.5 9.4l-2 3.5 2 1.1a7.9 7.9 0 00.1 1L3.5 16.1l2 3.5 2.3-.4a8 8 0 001.7 1l.3 2.3h4.4l.3-2.3a8 8 0 001.7-1l2.3.4 2-3.5-2.1-1.1z" opacity=".35"/>
                    </svg>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium <?= $mailConfigured ? 'bg-green-50 text-green-700 ring-1 ring-green-100' : 'bg-amber-50 text-amber-700 ring-1 ring-amber-100' ?>">
                    <?= htmlspecialchars($smtpLabel, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">邮局配置</p>
            <p class="mt-1 text-xs text-gray-500">
                模式：<?= htmlspecialchars($smtpMode, ENT_QUOTES, 'UTF-8') ?>
                <?php if ($smtpHost !== ''): ?> · <?= htmlspecialchars($smtpHost, ENT_QUOTES, 'UTF-8') ?><?php endif; ?>
            </p>
            <p class="mt-1 text-xs text-gray-500">
                发件：<?= $smtpFrom !== '' ? htmlspecialchars($smtpFrom, ENT_QUOTES, 'UTF-8') : '未设置' ?>
            </p>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入配置 →</p>
        </a>

        <a href="?section=register_code"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 4h12v16H6z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 8h8M8 12h6"/>
                    </svg>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium <?= !empty($registerVerifyConfig['enabled']) ? 'bg-green-50 text-green-700 ring-1 ring-green-100' : 'bg-gray-50 text-gray-600 ring-1 ring-gray-200' ?>">
                    <?= !empty($registerVerifyConfig['enabled']) ? '已启用' : '未启用' ?>
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">注册验证码</p>
            <p class="mt-1 text-xs text-gray-500">注册邮件验证发送与测试</p>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入配置 →</p>
        </a>

        <a href="?section=broadcast"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16v12H4z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 10h12M6 14h9"/>
                    </svg>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium <?= $broadcastActive ? 'bg-amber-50 text-amber-700 ring-1 ring-amber-100' : 'bg-gray-50 text-gray-600 ring-1 ring-gray-200' ?>">
                    <?= htmlspecialchars($broadcastLabel, ENT_QUOTES, 'UTF-8') ?>
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">全员通知</p>
            <p class="mt-1 text-xs text-gray-500">可发送用户约 <?= (int)$broadcastRecipientCount ?> 人</p>
            <?php if ($broadcastProgress !== ''): ?>
                <p class="mt-1 text-xs text-gray-500 truncate"><?= htmlspecialchars($broadcastProgress, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入配置 →</p>
        </a>

        <a href="?section=targeted"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 12a4 4 0 100-8 4 4 0 000 8z"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 20a8 8 0 0116 0"/>
                    </svg>
                </div>
                <span class="rounded-full bg-blue-50 text-blue-700 ring-1 ring-blue-100 px-2.5 py-1 text-xs font-medium">
                    模板 <?= (int)$tplCount ?> 个
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">指定通知</p>
            <p class="mt-1 text-xs text-gray-500">用户多选 / 筛选 / 模板发送</p>
            <?php if ($tplLatest !== ''): ?>
                <p class="mt-1 text-xs text-gray-500">最近更新：<?= htmlspecialchars($tplLatest, ENT_QUOTES, 'UTF-8') ?></p>
            <?php endif; ?>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入发送 →</p>
        </a>

        <a href="?section=password_reset"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 11V8a3 3 0 00-6 0v3"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 11h10v9H6z"/>
                    </svg>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium <?= !empty($passwordResetConfig['enabled']) ? 'bg-green-50 text-green-700 ring-1 ring-green-100' : 'bg-gray-50 text-gray-600 ring-1 ring-gray-200' ?>">
                    <?= !empty($passwordResetConfig['enabled']) ? '已启用' : '未启用' ?>
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">密码重置</p>
            <p class="mt-1 text-xs text-gray-500">找回密码邮件与测试</p>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入配置 →</p>
        </a>

        <a href="?section=account_activation"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M8 12l2 2 6-6"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16v12H4z"/>
                    </svg>
                </div>
                <span class="rounded-full bg-gray-50 text-gray-700 ring-1 ring-gray-200 px-2.5 py-1 text-xs font-medium">
                    待处理 <?= (int)$activationTotal ?> 人
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">账号激活</p>
            <p class="mt-1 text-xs text-gray-500">候选列表 / 批量发送</p>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入列表 →</p>
        </a>

        <a href="?section=ban_notice"
           class="group rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
            <div class="flex items-center justify-between gap-3">
                <div class="inline-flex h-9 w-9 items-center justify-center rounded-lg bg-white border border-gray-200 text-gray-700">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-5 w-5" aria-hidden="true">
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M6 6l12 12"/>
                        <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 4a8 8 0 108 8 8 8 0 00-8-8z"/>
                    </svg>
                </div>
                <span class="rounded-full px-2.5 py-1 text-xs font-medium <?= !empty($banNoticeConfig['enabled']) ? 'bg-green-50 text-green-700 ring-1 ring-green-100' : 'bg-gray-50 text-gray-600 ring-1 ring-gray-200' ?>">
                    <?= !empty($banNoticeConfig['enabled']) ? '已启用' : '未启用' ?>
                </span>
            </div>
            <p class="mt-3 text-sm font-semibold text-gray-900">封禁通知</p>
            <p class="mt-1 text-xs text-gray-500">封禁/解封邮件与测试</p>
            <p class="mt-3 text-xs text-gray-400 group-hover:text-gray-500">进入配置 →</p>
        </a>
    </div>

    <div class="mt-6 rounded-xl border border-gray-200 bg-white p-4">
        <p class="text-sm font-semibold text-gray-900">快捷入口</p>
        <p class="mt-1 text-xs text-gray-500">常用操作一键直达。</p>
        <div class="mt-3 flex flex-wrap gap-2">
            <a class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
               href="?section=config">SMTP 配置</a>
            <a class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
               href="?section=register_code">注册验证码</a>
            <a class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
               href="?section=broadcast">全员通知</a>
            <a class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
               href="?section=targeted">指定通知</a>
            <a class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
               href="?section=password_reset">密码重置</a>
            <a class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
               href="?section=account_activation">账号激活</a>
            <a class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
               href="?section=ban_notice">封禁通知</a>
        </div>
    </div>
</div>

