<?php
/** @var array $accountActivationConfig */
/** @var array $activationCandidates */
/** @var array $activationBanTypes */
/** @var int $activationPage */
/** @var int $activationPerPage */
/** @var int $activationTotal */
/** @var int $activationTotalPages */
/** @var string $message */
/** @var string $error */
/** @var bool $mailConfigured */
$defaultBanType = (string)($accountActivationConfig['default_ban_type'] ?? ACTIVATION_BAN_TYPE_EMAIL);
$activationPageSizeOptions = accountActivationPageSizeOptions();
$activationRangeStart = $activationTotal > 0 ? (($activationPage - 1) * $activationPerPage + 1) : 0;
$activationRangeEnd = min($activationPage * $activationPerPage, $activationTotal);
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
        请先在「邮局配置」中完成 SMTP 设置，否则无法发送账号激活邮件。
    </div>
    <?php endif; ?>

    <p class="mb-4 text-sm text-gray-500">
        向选定用户发送账号激活通知后，账号将被暂时封禁；用户登录时将提示「当前账号暂时封禁」。
        用户需通过邮件链接填写真实邮箱并完成二次确认激活。
    </p>

    <form method="POST" class="max-w-4xl space-y-5">
        <input type="hidden" name="panel" value="account_activation_config">

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="activation_default_ban_type">默认封禁类型</label>
            <select name="activation_default_ban_type" id="activation_default_ban_type"
                    class="w-full max-w-md rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                <?php foreach ($activationBanTypes as $typeId => $typeLabel): ?>
                <option value="<?= htmlspecialchars($typeId, ENT_QUOTES, 'UTF-8') ?>"
                    <?= $defaultBanType === $typeId ? 'selected' : '' ?>>
                    <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
                </option>
                <?php endforeach; ?>
            </select>
            <p class="mt-1 text-xs text-gray-500">发送激活通知时可覆盖此默认类型。</p>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="activation_link_expire">链接过期时间（秒）</label>
            <input type="number" name="activation_link_expire" id="activation_link_expire"
                   min="300" max="604800"
                   class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                   value="<?= (int)$accountActivationConfig['link_expire'] ?>">
            <p class="mt-1 text-xs text-gray-500">默认 86400 秒（24 小时）。</p>
        </div>

        <div class="grid gap-5 lg:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="activation_subject">通知邮件主题</label>
                <input type="text" name="activation_subject" id="activation_subject"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                       value="<?= htmlspecialchars($accountActivationConfig['subject'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="activation_confirm_subject">确认邮件主题</label>
                <input type="text" name="activation_confirm_subject" id="activation_confirm_subject"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                       value="<?= htmlspecialchars($accountActivationConfig['confirm_subject'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="activation_template">通知邮件模板（发送至当前注册邮箱）</label>
            <p class="mb-2 text-xs text-gray-500">
                占位符：<code>{{activation_link}}</code>、<code>{{username}}</code>、<code>{{email}}</code>、
                <code>{{expire_minutes}}</code>、<code>{{site_name}}</code>、<code>{{ban_type_label}}</code>
            </p>
            <textarea name="activation_template" id="activation_template" rows="10"
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs leading-relaxed focus:border-blue-500 focus:outline-none"
            ><?= htmlspecialchars($accountActivationConfig['html_template'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="activation_confirm_template">确认邮件模板（发送至用户填写的新邮箱）</label>
            <p class="mb-2 text-xs text-gray-500">
                占位符同上，<code>{{activation_link}}</code> 为最终激活链接。
            </p>
            <textarea name="activation_confirm_template" id="activation_confirm_template" rows="10"
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs leading-relaxed focus:border-blue-500 focus:outline-none"
            ><?= htmlspecialchars($accountActivationConfig['confirm_html_template'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            保存账号激活设置
        </button>
    </form>

    <?php if ($mailConfigured): ?>
    <div class="mt-8 max-w-4xl border-t border-gray-100 pt-6 space-y-4">
        <p class="text-sm font-medium text-gray-900">发送账号激活通知</p>
        <p class="text-xs text-gray-500">选择单个或多个用户，发送后账号将被暂时封禁。可通过分页浏览全部用户。</p>

        <div class="flex flex-wrap items-center justify-between gap-3">
            <form method="GET" class="flex flex-wrap items-center gap-2 text-sm text-gray-600">
                <input type="hidden" name="section" value="account_activation">
                <input type="hidden" name="activation_page" value="1">
                <label for="activation_per_page_select" class="text-xs text-gray-500">每页显示</label>
                <select name="activation_per_page" id="activation_per_page_select"
                        class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:border-blue-500 focus:outline-none"
                        onchange="this.form.submit()">
                    <?php foreach ($activationPageSizeOptions as $size): ?>
                    <option value="<?= $size ?>" <?= $activationPerPage === $size ? 'selected' : '' ?>><?= $size ?> 条</option>
                    <?php endforeach; ?>
                </select>
            </form>
            <p class="text-xs text-gray-500">
                共 <?= (int)$activationTotal ?> 条，当前第 <?= (int)$activationPage ?> / <?= (int)$activationTotalPages ?> 页
                <?php if ($activationTotal > 0): ?>
                （<?= (int)$activationRangeStart ?>–<?= (int)$activationRangeEnd ?>）
                <?php endif; ?>
            </p>
        </div>

        <form method="POST" class="space-y-4">
            <input type="hidden" name="panel" value="account_activation_send">
            <input type="hidden" name="activation_page" value="<?= (int)$activationPage ?>">
            <input type="hidden" name="activation_per_page" value="<?= (int)$activationPerPage ?>">

            <div class="flex flex-wrap items-end gap-3">
                <div>
                    <label class="block text-xs text-gray-500 mb-1" for="activation_send_ban_type">封禁类型</label>
                    <select name="activation_send_ban_type" id="activation_send_ban_type"
                            class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                        <?php foreach ($activationBanTypes as $typeId => $typeLabel): ?>
                        <option value="<?= htmlspecialchars($typeId, ENT_QUOTES, 'UTF-8') ?>"
                            <?= $defaultBanType === $typeId ? 'selected' : '' ?>>
                            <?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700"
                        onclick="return confirm('确定向所选用户发送激活通知并暂时封禁账号？');">
                    发送激活通知
                </button>
            </div>

            <div class="overflow-x-auto rounded-lg border border-gray-200">
                <table class="min-w-full text-sm">
                    <thead class="bg-gray-50 text-left text-xs text-gray-500">
                        <tr>
                            <th class="px-3 py-2 w-10">
                                <input type="checkbox" id="activation_select_all" class="rounded border-gray-300">
                            </th>
                            <th class="px-3 py-2">用户名</th>
                            <th class="px-3 py-2">邮箱</th>
                            <th class="px-3 py-2">分组</th>
                            <th class="px-3 py-2">状态</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                        <?php if ($activationCandidates === []): ?>
                        <tr><td colspan="5" class="px-3 py-6 text-center text-gray-400">暂无用户</td></tr>
                        <?php else: ?>
                        <?php foreach ($activationCandidates as $candidate): ?>
                        <tr class="hover:bg-gray-50">
                            <td class="px-3 py-2">
                                <input type="checkbox" name="activation_user_ids[]"
                                       value="<?= (int)$candidate['id'] ?>"
                                       class="activation-user-cb rounded border-gray-300">
                            </td>
                            <td class="px-3 py-2 font-medium text-gray-800"><?= htmlspecialchars($candidate['username'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars($candidate['email'], ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="px-3 py-2 text-gray-500"><?= htmlspecialchars($candidate['group_name'] ?? '-', ENT_QUOTES, 'UTF-8') ?></td>
                            <td class="px-3 py-2">
                                <?php
                                $statusMap = ['active' => '正常', 'banned' => '封禁', 'frozen' => '冻结'];
                                echo htmlspecialchars($statusMap[$candidate['status']] ?? $candidate['status'], ENT_QUOTES, 'UTF-8');
                                ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($activationTotalPages > 1): ?>
            <div class="flex flex-wrap items-center justify-center gap-2 pt-2">
                <?php if ($activationPage > 1): ?>
                <a href="<?= htmlspecialchars(accountActivationListUrl(1, $activationPerPage), ENT_QUOTES, 'UTF-8') ?>"
                   class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">首页</a>
                <a href="<?= htmlspecialchars(accountActivationListUrl($activationPage - 1, $activationPerPage), ENT_QUOTES, 'UTF-8') ?>"
                   class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">上一页</a>
                <?php endif; ?>

                <?php
                $pageWindowStart = max(1, $activationPage - 2);
                $pageWindowEnd = min($activationTotalPages, $activationPage + 2);
                for ($p = $pageWindowStart; $p <= $pageWindowEnd; $p++):
                ?>
                <a href="<?= htmlspecialchars(accountActivationListUrl($p, $activationPerPage), ENT_QUOTES, 'UTF-8') ?>"
                   class="rounded px-3 py-1.5 text-xs <?= $p === $activationPage ? 'bg-blue-600 text-white' : 'border border-gray-300 text-gray-700 hover:bg-gray-50' ?>">
                    <?= $p ?>
                </a>
                <?php endfor; ?>

                <?php if ($activationPage < $activationTotalPages): ?>
                <a href="<?= htmlspecialchars(accountActivationListUrl($activationPage + 1, $activationPerPage), ENT_QUOTES, 'UTF-8') ?>"
                   class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">下一页</a>
                <a href="<?= htmlspecialchars(accountActivationListUrl($activationTotalPages, $activationPerPage), ENT_QUOTES, 'UTF-8') ?>"
                   class="rounded border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">末页</a>
                <?php endif; ?>
            </div>
            <?php endif; ?>
        </form>
    </div>

    <form method="POST" class="mt-6 max-w-4xl border-t border-gray-100 pt-6">
        <input type="hidden" name="panel" value="account_activation_test">
        <input type="hidden" name="activation_page" value="<?= (int)$activationPage ?>">
        <input type="hidden" name="activation_per_page" value="<?= (int)$activationPerPage ?>">
        <p class="text-sm font-medium text-gray-900">测试激活通知邮件</p>
        <div class="mt-3 flex flex-wrap items-end gap-3">
            <div class="min-w-[200px]">
                <label class="block text-xs text-gray-500 mb-1" for="activation_test_ban_type">封禁类型</label>
                <select name="activation_test_ban_type" id="activation_test_ban_type"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                    <?php foreach ($activationBanTypes as $typeId => $typeLabel): ?>
                    <option value="<?= htmlspecialchars($typeId, ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars($typeLabel, ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="min-w-[240px] flex-1">
                <label class="block text-xs text-gray-500 mb-1" for="activation_test_email">测试收件邮箱</label>
                <input type="email" name="activation_test_email" id="activation_test_email" required
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

<script>
(function () {
    var selectAll = document.getElementById('activation_select_all');
    if (!selectAll) return;
    selectAll.addEventListener('change', function () {
        document.querySelectorAll('.activation-user-cb').forEach(function (cb) {
            cb.checked = selectAll.checked;
        });
    });
})();
</script>
