<?php
/** @var array $pageConfig */
/** @var bool $registerEnabled */
/** @var string $message */
/** @var string $error */
$pageConfig = $pageConfig ?? defaultRegisterPageConfig();
$L = require __DIR__ . '/../../includes/register_page_labels.php';
?>
<div class="px-4 py-3 overflow-y-auto max-h-[calc(100vh-12rem)]">
    <?php if ($message): ?>
    <div class="mb-2 rounded-md border border-green-200 bg-green-50 px-3 py-1.5 text-sm leading-snug text-green-600">
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="mb-2 rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-sm leading-snug text-red-600">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if ($registerEnabled): ?>
    <div class="mb-2 rounded-md border border-green-600 bg-green-600 px-3 py-1.5 text-sm leading-snug text-white">
        <?= htmlspecialchars($L['notice_enabled'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php else: ?>
    <div class="mb-2 rounded-md border border-amber-200 bg-amber-50 px-3 py-1.5 text-sm leading-snug text-amber-700">
        <?= htmlspecialchars($L['notice_disabled'], ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="max-w-xl space-y-4" id="registerPageForm">
        <input type="hidden" name="panel" value="register_page">

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($L['label_username_restrict'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-sm text-gray-500"><?= htmlspecialchars($L['help_username_restrict'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input type="checkbox" name="username_restrict_enabled" value="1" class="peer sr-only" <?= !empty($pageConfig['username_restrict_enabled']) ? 'checked' : '' ?>>
                    <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-300"></span>
                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                </label>
            </div>
            <p class="mt-3 text-xs text-gray-500">
                当前状态：<span class="font-medium <?= !empty($pageConfig['username_restrict_enabled']) ? 'text-green-600' : 'text-gray-500' ?>">
                    <?= !empty($pageConfig['username_restrict_enabled']) ? $L['status_on'] : $L['status_off'] ?>
                </span>
            </p>
        </div>

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($L['label_password_strong'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-sm text-gray-500"><?= htmlspecialchars($L['help_password_strong'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-2 text-xs text-gray-400"><?= htmlspecialchars($L['password_hint_detail'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input type="checkbox" name="password_strong_enabled" value="1" class="peer sr-only" <?= !empty($pageConfig['password_strong_enabled']) ? 'checked' : '' ?>>
                    <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-300"></span>
                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                </label>
            </div>
            <p class="mt-3 text-xs text-gray-500">
                当前状态：<span class="font-medium <?= !empty($pageConfig['password_strong_enabled']) ? 'text-green-600' : 'text-gray-500' ?>">
                    <?= !empty($pageConfig['password_strong_enabled']) ? $L['status_on'] : $L['status_off'] ?>
                </span>
            </p>
        </div>

        <input type="hidden" name="username_min_length" value="5">

        <div>
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                <?= htmlspecialchars($L['btn_save'], ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </form>
</div>
