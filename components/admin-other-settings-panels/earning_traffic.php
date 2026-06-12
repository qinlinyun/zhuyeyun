<?php
/** @var array $earningRows */
/** @var array $earningUsers */
/** @var bool $mailConfigured */
?>
<div class="px-4 py-4 space-y-6">
    <?php if (!empty($message)): ?>
        <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-600"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>
    <?php if (!empty($error)): ?>
        <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <div>
        <h2 class="text-base font-semibold">收益流量管理</h2>
        <p class="mt-1 text-sm text-gray-500">查看所有用户上传视频产生的收益流量，支持按账单或按用户冻结/收回。操作会发送站内通知，邮局可用时同步发送邮件。</p>
        <p class="mt-2 text-xs <?= $mailConfigured ? 'text-green-600' : 'text-amber-600' ?>">
            邮件通知：<?= $mailConfigured ? '邮局已配置' : '邮局未配置，仅发送站内通知' ?>
        </p>
    </div>

    <section class="rounded-lg border border-gray-200">
        <div class="border-b border-gray-100 px-4 py-3">
            <h3 class="text-sm font-semibold">用户收益汇总</h3>
        </div>
        <?php if (empty($earningUsers)): ?>
            <div class="p-6 text-center text-sm text-gray-500">暂无收益流量用户</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-2">用户</th>
                        <th class="px-3 py-2">可用收益</th>
                        <th class="px-3 py-2">冻结收益</th>
                        <th class="px-3 py-2 text-right">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($earningUsers as $user): ?>
                    <tr>
                        <td class="px-3 py-3">
                            <div class="font-medium text-gray-900"><?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="text-xs text-gray-500"><?= htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') ?></div>
                        </td>
                        <td class="px-3 py-3 text-green-600"><?= (int)$user['traffic_earnings_total'] ?></td>
                        <td class="px-3 py-3 text-amber-600"><?= (int)$user['traffic_earnings_frozen'] ?></td>
                        <td class="px-3 py-3">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="rounded border border-amber-300 px-3 py-1 text-xs text-amber-700 hover:bg-amber-50" onclick="openEarningUserModal(<?= (int)$user['id'] ?>, 'freeze_user', '<?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?>')">冻结全部</button>
                                <button type="button" class="rounded border border-red-300 px-3 py-1 text-xs text-red-600 hover:bg-red-50" onclick="openEarningUserModal(<?= (int)$user['id'] ?>, 'reclaim_user', '<?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?>')">收回全部</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <section class="rounded-lg border border-gray-200">
        <div class="border-b border-gray-100 px-4 py-3">
            <h3 class="text-sm font-semibold">收益账单明细</h3>
        </div>
        <?php if (empty($earningRows)): ?>
            <div class="p-6 text-center text-sm text-gray-500">暂无收益账单</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-100 text-sm">
                <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                    <tr>
                        <th class="px-3 py-2">支付用户</th>
                        <th class="px-3 py-2">发布用户</th>
                        <th class="px-3 py-2">视频</th>
                        <th class="px-3 py-2">支付额度</th>
                        <th class="px-3 py-2">支付时间</th>
                        <th class="px-3 py-2">是否到账</th>
                        <th class="px-3 py-2 text-right">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php foreach ($earningRows as $row): ?>
                    <tr>
                        <td class="px-3 py-3"><?= htmlspecialchars((string)($row['payer_username'] ?? '未知'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="px-3 py-3"><?= htmlspecialchars((string)($row['publisher_username'] ?? '未知'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="max-w-xs truncate px-3 py-3" title="<?= htmlspecialchars((string)($row['video_title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)($row['video_title'] ?? '已删除视频'), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="px-3 py-3 text-green-600"><?= (int)$row['amount'] ?></td>
                        <td class="px-3 py-3 text-xs text-gray-500"><?= htmlspecialchars((string)$row['paid_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="px-3 py-3">
                            <span class="rounded-full px-2 py-1 text-xs <?= $row['status'] === 'settled' ? 'bg-green-50 text-green-700' : ($row['status'] === 'frozen' ? 'bg-amber-50 text-amber-700' : 'bg-red-50 text-red-600') ?>">
                                <?= htmlspecialchars(trafficEarningStatusLabel((string)$row['status']), ENT_QUOTES, 'UTF-8') ?>
                            </span>
                            <?php if (!empty($row['reason'])): ?>
                                <div class="mt-1 max-w-xs truncate text-xs text-gray-500" title="<?= htmlspecialchars((string)$row['reason'], ENT_QUOTES, 'UTF-8') ?>"><?= htmlspecialchars((string)$row['reason'], ENT_QUOTES, 'UTF-8') ?></div>
                            <?php endif; ?>
                        </td>
                        <td class="px-3 py-3">
                            <div class="flex justify-end gap-2">
                                <button type="button" class="rounded border border-amber-300 px-3 py-1 text-xs text-amber-700 hover:bg-amber-50" <?= $row['status'] !== 'settled' ? 'disabled' : '' ?> onclick="openEarningLogModal(<?= (int)$row['id'] ?>, 'freeze')">冻结</button>
                                <button type="button" class="rounded border border-red-300 px-3 py-1 text-xs text-red-600 hover:bg-red-50" <?= $row['status'] === 'reclaimed' ? 'disabled' : '' ?> onclick="openEarningLogModal(<?= (int)$row['id'] ?>, 'reclaim')">收回</button>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
</div>

<div id="earningActionModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4">
    <form method="post" class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <input type="hidden" name="panel" value="earning_traffic_action">
        <input type="hidden" name="earning_action" id="earningAction">
        <input type="hidden" name="log_id" id="earningLogId">
        <input type="hidden" name="user_id" id="earningUserId">
        <h3 class="mb-2 text-base font-semibold" id="earningModalTitle">收益流量操作</h3>
        <p class="mb-4 text-sm text-gray-500" id="earningModalHint">请填写操作原因，系统会通知用户。</p>
        <label class="mb-1 block text-sm font-medium text-gray-700" for="earningReason">原因</label>
        <textarea id="earningReason" name="reason" rows="4" required class="mb-5 w-full rounded border border-gray-300 px-3 py-2 text-sm"></textarea>
        <div class="flex justify-end gap-2">
            <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50" onclick="closeEarningModal()">取消</button>
            <button class="rounded bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">确认操作</button>
        </div>
    </form>
</div>

<script>
function openEarningModal(action, title, hint) {
    document.getElementById('earningAction').value = action;
    document.getElementById('earningModalTitle').textContent = title;
    document.getElementById('earningModalHint').textContent = hint;
    document.getElementById('earningReason').value = '';
    const modal = document.getElementById('earningActionModal');
    modal.classList.remove('hidden');
    modal.classList.add('flex');
}
function openEarningLogModal(logId, action) {
    document.getElementById('earningLogId').value = logId;
    document.getElementById('earningUserId').value = '';
    openEarningModal(action, action === 'freeze' ? '冻结收益账单' : '收回收益账单', '账单 #' + logId + ' 将被处理，请填写原因。');
}
function openEarningUserModal(userId, action, username) {
    document.getElementById('earningLogId').value = '';
    document.getElementById('earningUserId').value = userId;
    openEarningModal(action, action === 'freeze_user' ? '冻结用户全部收益' : '收回用户全部收益', '用户 ' + username + ' 的可处理收益账单将被批量处理，请填写原因。');
}
function closeEarningModal() {
    const modal = document.getElementById('earningActionModal');
    modal.classList.add('hidden');
    modal.classList.remove('flex');
}
</script>
