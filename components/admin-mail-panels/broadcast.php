<?php
/** @var array $broadcastConfig */
/** @var array|null $broadcastJob */
/** @var int $broadcastRecipientCount */
/** @var string $message */
/** @var string $error */
/** @var bool $mailConfigured */
$jobActive = mailBroadcastJobIsActive($broadcastJob);
$progressLabel = mailBroadcastJobProgressLabel($broadcastJob);
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
        请先在「邮局配置」中完成 SMTP 设置，否则无法发送全员通知。
    </div>
    <?php endif; ?>

    <p class="mb-4 text-sm text-gray-500">
        向所有有效邮箱用户发送邮件。支持按批次发送：每发送指定数量后暂停若干分钟再继续（暂停期间页面保持打开将自动续发）。
        当前可发送用户约 <strong><?= (int)$broadcastRecipientCount ?></strong> 人。
    </p>

    <?php if ($broadcastJob): ?>
    <div id="broadcastProgressBox" class="mb-4 rounded-lg border border-blue-200 bg-blue-50 px-4 py-3 text-sm text-blue-900">
        <p class="font-medium">任务进度</p>
        <p id="broadcastProgressText" class="mt-1"><?= htmlspecialchars($progressLabel, ENT_QUOTES, 'UTF-8') ?></p>
        <?php if (!empty($broadcastJob['last_error'])): ?>
        <p id="broadcastLastError" class="mt-1 text-xs text-red-600">最近错误：<?= htmlspecialchars((string)$broadcastJob['last_error'], ENT_QUOTES, 'UTF-8') ?></p>
        <?php endif; ?>
        <?php if ($jobActive): ?>
        <p id="broadcastPauseHint" class="mt-1 text-xs text-blue-700"></p>
        <?php endif; ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="max-w-3xl space-y-5" id="broadcastConfigForm">
        <input type="hidden" name="panel" value="broadcast_config">

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="broadcast_batch_size">每批发送数量</label>
                <input
                    type="number"
                    name="broadcast_batch_size"
                    id="broadcast_batch_size"
                    min="1"
                    max="500"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    value="<?= (int)$broadcastConfig['batch_size'] ?>"
                    <?= $jobActive ? 'readonly' : '' ?>
                >
                <p class="mt-1 text-xs text-gray-500">每发送该数量用户后进入批次暂停。</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="broadcast_batch_pause_minutes">批次暂停（分钟）</label>
                <input
                    type="number"
                    name="broadcast_batch_pause_minutes"
                    id="broadcast_batch_pause_minutes"
                    min="0"
                    max="1440"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    value="<?= (int)$broadcastConfig['batch_pause_minutes'] ?>"
                    <?= $jobActive ? 'readonly' : '' ?>
                >
                <p class="mt-1 text-xs text-gray-500">设为 0 表示批次间不暂停，连续发送。</p>
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="broadcast_subject">邮件主题</label>
            <input
                type="text"
                name="broadcast_subject"
                id="broadcast_subject"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                value="<?= htmlspecialchars($broadcastConfig['subject'], ENT_QUOTES, 'UTF-8') ?>"
                <?= $jobActive ? 'readonly' : '' ?>
            >
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="broadcast_content">发送内容（HTML）</label>
            <p class="mb-2 text-xs text-gray-500">将插入模板中的 <code>{{content}}</code> 位置，可写富文本 HTML。</p>
            <textarea
                name="broadcast_content"
                id="broadcast_content"
                rows="8"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm leading-relaxed focus:border-blue-500 focus:outline-none"
                <?= $jobActive ? 'readonly' : '' ?>
            ><?= htmlspecialchars($broadcastConfig['content'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="broadcast_template">HTML 邮件模板</label>
            <p class="mb-2 text-xs text-gray-500">
                占位符：<code>{{content}}</code> 正文、
                <code>{{username}}</code> 用户名、
                <code>{{email}}</code> 邮箱、
                <code>{{site_name}}</code> 站点名（须包含 {{content}}）
            </p>
            <textarea
                name="broadcast_template"
                id="broadcast_template"
                rows="12"
                class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs leading-relaxed focus:border-blue-500 focus:outline-none"
                <?= $jobActive ? 'readonly' : '' ?>
            ><?= htmlspecialchars($broadcastConfig['html_template'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <div class="flex flex-wrap items-center gap-3">
            <?php if (!$jobActive): ?>
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                保存配置
            </button>
            <?php endif; ?>
        </div>
    </form>

    <?php if ($mailConfigured && !$jobActive): ?>
    <form method="POST" class="mt-4 flex flex-wrap items-center gap-3">
        <input type="hidden" name="panel" value="broadcast_start">
        <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                onclick="return confirm('确定向全部用户发送全员通知吗？');">
            立即发送全员通知
        </button>
        <span class="text-xs text-gray-500">将使用上方已保存的配置创建发送任务</span>
    </form>
    <?php endif; ?>

    <?php if ($jobActive): ?>
    <form method="POST" class="mt-4">
        <input type="hidden" name="panel" value="broadcast_cancel">
        <button type="submit" class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50"
                onclick="return confirm('确定取消当前发送任务吗？');">
            取消当前任务
        </button>
    </form>
    <?php endif; ?>

    <?php if ($mailConfigured && !$jobActive): ?>
    <form method="POST" class="mt-6 max-w-3xl border-t border-gray-100 pt-6">
        <input type="hidden" name="panel" value="broadcast_test">
        <p class="text-sm font-medium text-gray-900">测试邮件</p>
        <p class="mt-1 text-xs text-gray-500">按已保存的配置向指定邮箱发送一封测试。</p>
        <div class="mt-3 flex flex-wrap items-end gap-3">
            <div class="min-w-[240px] flex-1">
                <label class="block text-xs text-gray-500 mb-1" for="broadcast_test_email">测试收件邮箱</label>
                <input type="email" name="broadcast_test_email" id="broadcast_test_email" required
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

<?php if ($jobActive): ?>
<script>
(() => {
    const progressText = document.getElementById('broadcastProgressText');
    const pauseHint = document.getElementById('broadcastPauseHint');
    let polling = true;

    function schedule(ms) {
        if (!polling) return;
        setTimeout(runStep, ms);
    }

    function runStep() {
        if (!polling) return;
        fetch('../api/mail_broadcast_process.php?action=step', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(data => {
                if (progressText && data.label) progressText.textContent = data.label;
                if (pauseHint) {
                    if (data.waiting && data.wait_seconds) {
                        pauseHint.textContent = '批次暂停中，约 ' + data.wait_seconds + ' 秒后继续…';
                    } else {
                        pauseHint.textContent = data.message || '';
                    }
                }
                if (data.done) {
                    polling = false;
                    setTimeout(() => window.location.reload(), 1200);
                    return;
                }
                if (data.waiting && data.wait_seconds) {
                    schedule(Math.min(Math.max(data.wait_seconds * 1000, 3000), 60000));
                } else {
                    schedule(800);
                }
            })
            .catch(() => schedule(3000));
    }

    runStep();
})();
</script>
<?php endif; ?>
