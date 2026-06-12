<?php
/** @var array $registerVerifyConfig */
/** @var string $message */
/** @var string $error */
/** @var bool $mailConfigured */
$enabled = !empty($registerVerifyConfig['enabled']);
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
        请先在「邮局配置」中完成 SMTP 设置，否则无法发送注册验证码。
    </div>
    <?php endif; ?>

    <p class="mb-4 text-sm text-gray-500">
        开启后，用户注册前须先验证邮箱验证码；发信依赖「邮局配置」中的 SMTP。
    </p>

    <form method="POST" class="max-w-3xl space-y-5">
        <input type="hidden" name="panel" value="register_verify_config">

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900">启用注册邮箱验证</p>
                    <p class="mt-1 text-sm text-gray-500">关闭后注册页不再要求验证码，与原先流程一致。</p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input
                        type="checkbox"
                        name="register_verify_enabled"
                        value="1"
                        class="peer sr-only"
                        id="registerVerifyEnabled"
                        <?= $enabled ? 'checked' : '' ?>
                    >
                    <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-300"></span>
                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                </label>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="resend_interval">重新发送间隔（秒）</label>
                <input
                    type="number"
                    name="resend_interval"
                    id="resend_interval"
                    min="30"
                    max="3600"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    value="<?= (int)$registerVerifyConfig['resend_interval'] ?>"
                >
                <p class="mt-1 text-xs text-gray-500">同一邮箱两次发送的最小间隔，建议 60 秒。</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="code_expire">验证码过期时间（秒）</label>
                <input
                    type="number"
                    name="code_expire"
                    id="code_expire"
                    min="60"
                    max="86400"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    value="<?= (int)$registerVerifyConfig['code_expire'] ?>"
                >
                <p class="mt-1 text-xs text-gray-500">默认 600 秒（10 分钟）。</p>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="register_verify_subject">邮件主题</label>
            <input
                type="text"
                name="register_verify_subject"
                id="register_verify_subject"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                value="<?= htmlspecialchars($registerVerifyConfig['subject'], ENT_QUOTES, 'UTF-8') ?>"
            >
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="register_verify_template">验证码 HTML 邮件模板</label>
            <p class="mb-2 text-xs text-gray-500">
                可用占位符：<code class="text-xs">{{code}}</code> 验证码、
                <code class="text-xs">{{email}}</code> 收件邮箱、
                <code class="text-xs">{{expire_minutes}}</code> 有效分钟数、
                <code class="text-xs">{{site_name}}</code> 站点名称（须包含 {{code}}）
            </p>
            <textarea
                name="register_verify_template"
                id="register_verify_template"
                rows="14"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs leading-relaxed focus:border-blue-500 focus:outline-none"
            ><?= htmlspecialchars($registerVerifyConfig['html_template'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            保存注册验证码设置
        </button>
    </form>

    <?php if ($mailConfigured): ?>
    <form method="POST" class="mt-6 max-w-3xl border-t border-gray-100 pt-6">
        <input type="hidden" name="panel" value="register_verify_test">
        <p class="text-sm font-medium text-gray-900">测试验证码发送</p>
        <p class="mt-1 text-xs text-gray-500">按当前模板向指定邮箱发送一封测试验证码邮件（不会写入注册验证记录）。</p>
        <div class="mt-3 flex flex-wrap items-end gap-3">
            <div class="min-w-[240px] flex-1">
                <label class="block text-xs text-gray-500 mb-1" for="test_verify_email">测试收件邮箱</label>
                <input
                    type="email"
                    name="test_verify_email"
                    id="test_verify_email"
                    required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    placeholder="your@example.com"
                >
            </div>
            <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                发送测试验证码
            </button>
        </div>
    </form>
    <?php endif; ?>
</div>
