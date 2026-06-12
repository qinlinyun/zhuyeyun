<?php
/** @var array $scheduleConfig */
/** @var bool $registerEnabled */
/** @var string $message */
/** @var string $error */
$scheduleConfig = $scheduleConfig ?? defaultRegisterScheduleConfig();
$L = require __DIR__ . '/../../includes/register_schedule_labels.php';
$statusText = registerScheduleStatusText($scheduleConfig);
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

    <form method="POST" class="max-w-xl space-y-4" id="registerScheduleForm">
        <input type="hidden" name="panel" value="register_schedule">

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900"><?= htmlspecialchars($L['label_schedule'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-1 text-sm text-gray-500"><?= htmlspecialchars($L['help_schedule'], ENT_QUOTES, 'UTF-8') ?></p>
                    <p class="mt-2 text-xs text-gray-400"><?= htmlspecialchars($L['hint_datetime'], ENT_QUOTES, 'UTF-8') ?></p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input type="checkbox" name="schedule_enabled" value="1" class="peer sr-only" <?= !empty($scheduleConfig['enabled']) ? 'checked' : '' ?>>
                    <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-300"></span>
                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                </label>
            </div>
            <p class="mt-3 text-xs text-gray-500">
                当前状态：<span class="font-medium <?= !empty($scheduleConfig['enabled']) ? 'text-green-600' : 'text-gray-500' ?>">
                    <?= !empty($scheduleConfig['enabled']) ? $L['status_on'] : $L['status_off'] ?>
                </span>
            </p>
        </div>

        <div class="grid gap-4 sm:grid-cols-1">
            <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
                <label class="block text-sm font-medium text-gray-900" for="close_at"><?= htmlspecialchars($L['label_close_time'], ENT_QUOTES, 'UTF-8') ?></label>
                <p class="mt-1 text-xs text-gray-500"><?= htmlspecialchars($L['help_close_time'], ENT_QUOTES, 'UTF-8') ?></p>
                <input type="datetime-local" id="close_at" name="close_at" required
                       value="<?= htmlspecialchars(scheduleDatetimeToInput($scheduleConfig['close_at']), ENT_QUOTES, 'UTF-8') ?>"
                       class="mt-3 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
            </div>
            <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
                <label class="block text-sm font-medium text-gray-900" for="open_at"><?= htmlspecialchars($L['label_open_time'], ENT_QUOTES, 'UTF-8') ?></label>
                <p class="mt-1 text-xs text-gray-500"><?= htmlspecialchars($L['help_open_time'], ENT_QUOTES, 'UTF-8') ?></p>
                <input type="datetime-local" id="open_at" name="open_at" required
                       value="<?= htmlspecialchars(scheduleDatetimeToInput($scheduleConfig['open_at']), ENT_QUOTES, 'UTF-8') ?>"
                       class="mt-3 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:ring-2 focus:ring-blue-200">
            </div>
        </div>

        <div class="rounded-lg border border-blue-100 bg-blue-50 px-5 py-4">
            <p class="text-sm font-medium text-blue-900"><?= htmlspecialchars($L['label_status'], ENT_QUOTES, 'UTF-8') ?></p>
            <p class="mt-1 text-sm text-blue-700"><?= htmlspecialchars($statusText, ENT_QUOTES, 'UTF-8') ?></p>
        </div>

        <div>
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                <?= htmlspecialchars($L['btn_save'], ENT_QUOTES, 'UTF-8') ?>
            </button>
        </div>
    </form>
</div>
