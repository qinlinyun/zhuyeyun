<?php
/** @var array $passwordResetConfig */
/** @var string $message */
/** @var string $error */
/** @var bool $mailConfigured */
$enabled = !empty($passwordResetConfig['enabled']);
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

    <?php if (!$mailConfigured): ?>
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        请先在「邮局配置」中完成 SMTP 设置，否则无法发送密码重置邮件。
    </div>
    <?php endif; ?>

    <p class="mb-4 text-sm text-gray-500">
        开启后，用户可在登录页通过「忘记密码」提交邮箱；系统验证邮箱已注册后发送重置链接。未注册邮箱将提示「当前邮箱未注册」。
    </p>

    <form method="POST" class="max-w-3xl space-y-5">
        <input type="hidden" name="panel" value="password_reset_config">

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900">启用密码重置</p>
                    <p class="mt-1 text-sm text-gray-500">关闭后前台「忘记密码」入口不可用。</p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input type="checkbox" name="password_reset_enabled" value="1" class="peer sr-only"
                        <?= $enabled ? 'checked' : '' ?>>
                    <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-300"></span>
                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                </label>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="password_reset_link_expire">链接过期时间（秒）</label>
            <input type="number" name="password_reset_link_expire" id="password_reset_link_expire"
                   min="300" max="86400"
                   class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                   value="<?= (int)$passwordResetConfig['link_expire'] ?>">
            <p class="mt-1 text-xs text-gray-500">默认 3600 秒（1 小时）。</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="password_reset_subject">邮件主题</label>
            <input type="text" name="password_reset_subject" id="password_reset_subject"
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                   value="<?= htmlspecialchars($passwordResetConfig['subject'], ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="password_reset_template">HTML 邮件模板</label>
            <p class="mb-2 text-xs text-gray-500">
                占位符：<code>{{reset_link}}</code> 重置链接、
                <code>{{username}}</code>、<code>{{email}}</code>、
                <code>{{expire_minutes}}</code>、<code>{{site_name}}</code>（须包含 {{reset_link}}）
            </p>
            <textarea name="password_reset_template" id="password_reset_template" rows="14"
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs leading-relaxed focus:border-blue-500 focus:outline-none"
            ><?= htmlspecialchars($passwordResetConfig['html_template'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            保存密码重置设置
        </button>
    </form>

    <?php if ($mailConfigured): ?>
    <form method="POST" class="mt-6 max-w-3xl border-t border-gray-100 pt-6">
        <input type="hidden" name="panel" value="password_reset_test">
        <p class="text-sm font-medium text-gray-900">测试重置邮件</p>
        <p class="mt-1 text-xs text-gray-500">按已保存的配置发送一封测试邮件（链接为示例，不可用于实际重置）。</p>
        <div class="mt-3 flex flex-wrap items-end gap-3">
            <div class="min-w-[240px] flex-1">
                <label class="block text-xs text-gray-500 mb-1" for="password_reset_test_email">测试收件邮箱</label>
                <input type="email" name="password_reset_test_email" id="password_reset_test_email" required
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                       placeholder="your@example.com">
            </div>
            <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                发送测试
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>
