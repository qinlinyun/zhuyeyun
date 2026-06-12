<?php
/** @var array $mailConfig */
/** @var string $message */
/** @var string $error */
/** @var bool $mailConfigured */
$enabled = !empty($mailConfig['enabled']);
$hasPassword = ($mailConfig['password'] ?? '') !== '';
$hasApiKey = ($mailConfig['api_key'] ?? '') !== '';
$sendMode = ($mailConfig['send_mode'] ?? 'smtp') === 'api' ? 'api' : 'smtp';
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

    <p class="mb-4 text-sm text-gray-500">
        支持 <strong>直连 SMTP</strong> 或 <strong>邮局 API</strong>（调用独立部署的 <code>邮局/</code> 系统，适合网站与邮局分机部署）。
    </p>

    <form method="POST" class="max-w-2xl space-y-5">
        <input type="hidden" name="panel" value="mail_config">

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900">启用邮件发信</p>
                    <p class="mt-1 text-sm text-gray-500">关闭后全站邮件功能暂停。</p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input
                        type="checkbox"
                        name="smtp_enabled"
                        value="1"
                        class="peer sr-only"
                        id="smtpEnabled"
                        <?= $enabled ? 'checked' : '' ?>
                    >
                    <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-300"></span>
                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                </label>
            </div>
            <p class="mt-3 text-xs text-gray-500">
                当前状态：
                <span class="font-medium <?= $mailConfigured ? 'text-green-600' : 'text-red-600' ?>">
                    <?= $mailConfigured ? '已就绪，可发送邮件' : ($enabled ? '配置不完整' : '未启用') ?>
                </span>
            </p>
        </div>

        <div id="mailModeBlock" class="space-y-3 <?= $enabled ? '' : 'opacity-60 pointer-events-none' ?>">
            <p class="text-sm font-medium text-gray-900">发信方式</p>
            <div class="flex flex-wrap gap-4">
                <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-gray-700">
                    <input type="radio" name="send_mode" value="smtp" class="text-blue-600" id="modeSmtp" <?= $sendMode === 'smtp' ? 'checked' : '' ?>>
                    直连 SMTP
                </label>
                <label class="inline-flex cursor-pointer items-center gap-2 text-sm text-gray-700">
                    <input type="radio" name="send_mode" value="api" class="text-blue-600" id="modeApi" <?= $sendMode === 'api' ? 'checked' : '' ?>>
                    邮局 API
                </label>
            </div>
            <p class="text-xs text-gray-500">API 模式：在邮局服务器部署 <code>邮局/</code> 目录，安装后填写 API 根地址与密钥即可。</p>
        </div>

        <div id="apiFields" class="space-y-4 <?= $enabled ? '' : 'opacity-60' ?> <?= $sendMode === 'api' ? '' : 'hidden' ?>">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="api_url">邮局 API 根地址</label>
                <input
                    type="url"
                    name="api_url"
                    id="api_url"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    placeholder="https://mail.example.com/邮局"
                    value="<?= htmlspecialchars($mailConfig['api_url'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
                >
                <p class="mt-1 text-xs text-gray-500">填写邮局系统安装目录的 URL，无需带 <code>/api/send.php</code>。此密钥需与邮局服务器配置一致，并用于邮局后台管理员登录校验。</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="api_key">API 密钥</label>
                <input
                    type="password"
                    name="api_key"
                    id="api_key"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    placeholder="<?= $hasApiKey ? '留空则保持当前密钥不变' : '在邮局 install.php 安装时生成' ?>"
                    autocomplete="new-password"
                >
            </div>
            <div>
                <button type="button" id="apiPingBtn"
                        class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                    测试 API 连接
                </button>
                <span id="apiPingResult" class="ml-3 text-sm text-gray-500"></span>
            </div>
        </div>

        <div id="smtpFields" class="space-y-4 <?= $enabled ? '' : 'opacity-60' ?> <?= $sendMode === 'smtp' ? '' : 'hidden' ?>">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="smtp_host">SMTP 服务器地址</label>
                <input
                    type="text"
                    name="smtp_host"
                    id="smtp_host"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    placeholder="例如 smtp.qq.com、smtp.gmail.com"
                    value="<?= htmlspecialchars($mailConfig['host'], ENT_QUOTES, 'UTF-8') ?>"
                >
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="smtp_port">端口</label>
                    <input
                        type="number"
                        name="smtp_port"
                        id="smtp_port"
                        min="1"
                        max="65535"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                        value="<?= (int)$mailConfig['port'] ?>"
                    >
                    <p class="mt-1 text-xs text-gray-500">常用：465（SSL）、587（TLS）、25（无加密）</p>
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="smtp_encryption">加密方式</label>
                    <select
                        name="smtp_encryption"
                        id="smtp_encryption"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    >
                        <?php foreach (['tls' => 'TLS（推荐，通常配合 587）', 'ssl' => 'SSL（通常配合 465）', 'none' => '无加密'] as $val => $label): ?>
                        <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= ($mailConfig['encryption'] ?? '') === $val ? 'selected' : '' ?>>
                            <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="smtp_username">SMTP 账号</label>
                <input
                    type="text"
                    name="smtp_username"
                    id="smtp_username"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    placeholder="通常为完整邮箱地址"
                    value="<?= htmlspecialchars($mailConfig['username'], ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="username"
                >
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="smtp_password">SMTP 密码</label>
                <input
                    type="password"
                    name="smtp_password"
                    id="smtp_password"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    placeholder="<?= $hasPassword ? '留空则保持当前密码不变' : '邮箱授权码或 SMTP 密码' ?>"
                    autocomplete="new-password"
                >
                <p class="mt-1 text-xs text-gray-500">QQ / 163 等邮箱请使用授权码，而非登录密码。</p>
            </div>

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="smtp_from_email">发件人邮箱</label>
                    <input
                        type="email"
                        name="smtp_from_email"
                        id="smtp_from_email"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                        placeholder="与 SMTP 账号一致或同域名邮箱"
                        value="<?= htmlspecialchars($mailConfig['from_email'], ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="smtp_from_name">发件人名称</label>
                    <input
                        type="text"
                        name="smtp_from_name"
                        id="smtp_from_name"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                        placeholder="竹叶云控平台"
                        value="<?= htmlspecialchars($mailConfig['from_name'], ENT_QUOTES, 'UTF-8') ?>"
                    >
                </div>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                保存邮局配置
            </button>
        </div>
    </form>

    <?php if ($mailConfigured): ?>
    <div class="mt-6 max-w-2xl border-t border-gray-100 pt-6" id="smtpTestBlock">
        <p class="text-sm font-medium text-gray-900">发送测试邮件</p>
        <p class="mt-1 text-xs text-gray-500">保存配置后异步发送测试邮件，不会导致页面超时。</p>
        <div id="smtpTestAlert" class="hidden mt-3 rounded-lg px-4 py-3 text-sm"></div>
        <div class="mt-3 flex flex-wrap items-end gap-3">
            <div class="min-w-[240px] flex-1">
                <label class="block text-xs text-gray-500 mb-1" for="test_email">收件邮箱</label>
                <input
                    type="email"
                    id="test_email"
                    required
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    placeholder="your@example.com"
                >
            </div>
            <button type="button" id="smtpTestBtn"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50 disabled:opacity-50">
                发送测试
            </button>
        </div>
    </div>
    <?php endif; ?>
</div>

<script>
(() => {
    const toggle = document.getElementById('smtpEnabled');
    const modeBlock = document.getElementById('mailModeBlock');
    const smtpFields = document.getElementById('smtpFields');
    const apiFields = document.getElementById('apiFields');
    const modeSmtp = document.getElementById('modeSmtp');
    const modeApi = document.getElementById('modeApi');

    function setEnabledState(on) {
        modeBlock?.classList.toggle('opacity-60', !on);
        modeBlock?.classList.toggle('pointer-events-none', !on);
        smtpFields?.classList.toggle('opacity-60', !on);
        apiFields?.classList.toggle('opacity-60', !on);
    }

    function setSendMode(mode) {
        const isApi = mode === 'api';
        smtpFields?.classList.toggle('hidden', isApi);
        apiFields?.classList.toggle('hidden', !isApi);
    }

    toggle?.addEventListener('change', () => setEnabledState(toggle.checked));
    modeSmtp?.addEventListener('change', () => setSendMode('smtp'));
    modeApi?.addEventListener('change', () => setSendMode('api'));
    setSendMode(modeApi?.checked ? 'api' : 'smtp');

    const apiPingBtn = document.getElementById('apiPingBtn');
    const apiPingResult = document.getElementById('apiPingResult');
    apiPingBtn?.addEventListener('click', async () => {
        const url = document.getElementById('api_url')?.value.trim() || '';
        const key = document.getElementById('api_key')?.value.trim() || '';
        if (!url) {
            apiPingResult.textContent = '请先填写 API 根地址';
            apiPingResult.className = 'ml-3 text-sm text-red-600';
            return;
        }

        apiPingBtn.disabled = true;
        apiPingResult.textContent = '检测中…';
        apiPingResult.className = 'ml-3 text-sm text-gray-500';
        try {
            const body = new URLSearchParams();
            body.set('api_url', url);
            if (key) body.set('api_key', key);
            const res = await fetch('../api/mail_api_ping.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin',
            });
            const data = await res.json();
            apiPingResult.textContent = data.message || (data.ok ? '连接正常' : '连接失败');
            apiPingResult.className = 'ml-3 text-sm ' + (data.ok ? 'text-green-600' : 'text-red-600');
        } catch (e) {
            apiPingResult.textContent = '请求失败：' + (e.message || '网络错误');
            apiPingResult.className = 'ml-3 text-sm text-red-600';
        } finally {
            apiPingBtn.disabled = false;
        }
    });

    const testBtn = document.getElementById('smtpTestBtn');
    const testEmail = document.getElementById('test_email');
    const testAlert = document.getElementById('smtpTestAlert');
    if (!testBtn || !testEmail) return;

    testBtn.addEventListener('click', async () => {
        const email = testEmail.value.trim();
        if (!email) {
            showTestAlert('请填写收件邮箱', false);
            return;
        }

        testBtn.disabled = true;
        const oldText = testBtn.textContent;
        testBtn.textContent = '发送中…';
        showTestAlert('正在发送，请稍候…', true);

        try {
            const body = new URLSearchParams();
            body.set('test_email', email);
            const res = await fetch('../api/mail_smtp_test.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: body.toString(),
                credentials: 'same-origin',
            });
            const data = await res.json();
            showTestAlert(data.message || (data.ok ? '发送成功' : '发送失败'), !!data.ok);
        } catch (e) {
            showTestAlert('请求失败：' + (e.message || '网络错误'), false);
        } finally {
            testBtn.disabled = false;
            testBtn.textContent = oldText;
        }
    });

    function showTestAlert(msg, ok) {
        if (!testAlert) return;
        testAlert.textContent = msg;
        testAlert.classList.remove('hidden', 'bg-green-50', 'text-green-600', 'border-green-200', 'bg-red-50', 'text-red-600', 'border-red-200', 'border');
        testAlert.classList.add('border');
        if (ok) {
            testAlert.classList.add('bg-green-50', 'text-green-600', 'border-green-200');
        } else {
            testAlert.classList.add('bg-red-50', 'text-red-600', 'border-red-200');
        }
    }
})();
</script>
