<?php
/** @var bool $registerEnabled */
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

    <form method="POST" class="max-w-xl">
        <input type="hidden" name="panel" value="register_toggle">

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900">注册功能</p>
                    <p class="mt-1 text-sm text-gray-500">用于开启和关闭注册功能。关闭后用户将无法提交新账号注册。手动开启注册时，定时开/关注册会自动关闭。</p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input
                        type="checkbox"
                        name="register_enabled"
                        value="1"
                        class="peer sr-only"
                        <?= $registerEnabled ? 'checked' : '' ?>
                    >
                    <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-300"></span>
                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                </label>
            </div>
            <p class="mt-3 text-xs text-gray-500">
                当前状态：<span class="font-medium <?= $registerEnabled ? 'text-green-600' : 'text-red-600' ?>"><?= $registerEnabled ? '已开启' : '已关闭' ?></span>
            </p>
        </div>

        <div class="mt-5">
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                保存设置
            </button>
        </div>
    </form>
</div>
