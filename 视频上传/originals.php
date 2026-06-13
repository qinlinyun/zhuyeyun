<?php
require_once __DIR__ . '/common.php';
uploadBackendRequireLogin();

$message = '';
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    if (!uploadBackendVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = '表单已过期，请刷新后重试';
    } else {
        $deleted = uploadBackendDeleteOriginalRecord((string)($_POST['id'] ?? ''));
        if ($deleted) {
            $message = '原始文件已删除';
        } else {
            $error = '未找到要删除的原始文件';
        }
    }
}

$records = uploadBackendReadOriginalRecords();

uploadBackendPageHead('原始文件管理 - 远程上传后端');
uploadBackendAdminNav('远程上传后端', [
    ['href' => 'dashboard.php', 'label' => '概览'],
    ['href' => 'upload.php', 'label' => '上传'],
    ['href' => 'originals.php', 'label' => '原始文件', 'active' => true],
]);
?>
<main class="mx-auto max-w-screen-xl px-4 py-6">
    <?php if ($message): uploadBackendAlert('success', $message); endif; ?>
    <?php if ($error): uploadBackendAlert('error', $error); endif; ?>

    <section class="overflow-hidden rounded-xl bg-white shadow-sm ring-1 ring-slate-200/60">
        <div class="border-b border-slate-100 bg-slate-50/80 px-5 py-4">
            <h1 class="text-lg font-bold text-slate-900">已保存的原始文件</h1>
            <p class="mt-1 text-sm text-slate-500">仅展示主站发送「保存原始文件」命令后保留的 mp4 源文件。</p>
        </div>

        <?php if (empty($records)): ?>
            <div class="flex flex-col items-center justify-center px-6 py-16 text-center">
                <div class="mb-3 flex h-14 w-14 items-center justify-center rounded-full bg-slate-100 text-slate-400">
                    <svg class="h-7 w-7" fill="none" stroke="currentColor" viewBox="0 0 24 24" aria-hidden="true"><path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20 13V6a2 2 0 00-2-2H6a2 2 0 00-2 2v7m16 0v5a2 2 0 01-2 2H6a2 2 0 01-2-2v-5m16 0h-2.586a1 1 0 00-.707.293l-2.414 2.414a1 1 0 01-.707.293h-3.172a1 1 0 01-.707-.293l-2.414-2.414A1 1 0 006.586 13H4"/></svg>
                </div>
                <p class="text-sm font-medium text-slate-600">暂无已保存的原始文件</p>
                <p class="mt-1 text-xs text-slate-400">主站下发保存命令后，文件会出现在此列表</p>
            </div>
        <?php else: ?>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-slate-100 text-sm">
                    <thead class="bg-slate-50 text-left text-xs font-semibold uppercase tracking-wide text-slate-500">
                        <tr>
                            <th class="px-4 py-3">视频原始名称</th>
                            <th class="px-4 py-3">上传者</th>
                            <th class="px-4 py-3">大小</th>
                            <th class="px-4 py-3">上传时间</th>
                            <th class="px-4 py-3 text-right">操作</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-slate-100 bg-white">
                        <?php foreach ($records as $record): ?>
                            <tr class="transition-colors hover:bg-slate-50">
                                <td class="px-4 py-3">
                                    <div class="font-medium text-slate-900"><?= htmlspecialchars((string)($record['original_filename'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php if (!empty($record['title'])): ?>
                                        <div class="mt-0.5 text-xs text-slate-500"><?= htmlspecialchars((string)$record['title'], ENT_QUOTES, 'UTF-8') ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string)($record['uploader'] ?? '未知'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-4 py-3 tabular-nums text-slate-600"><?= htmlspecialchars(uploadBackendFormatBytes((int)($record['size_bytes'] ?? 0)), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-4 py-3 text-slate-600"><?= htmlspecialchars((string)($record['uploaded_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?></td>
                                <td class="px-4 py-3">
                                    <div class="flex justify-end gap-2">
                                        <?php
                                        $directUrl = uploadBackendStorageDirectUrl((string)($record['saved_relative'] ?? ''));
                                        $downloadName = (string)($record['original_filename'] ?? 'video.mp4');
                                        ?>
                                        <?php if ($directUrl !== ''): ?>
                                            <a href="<?= htmlspecialchars($directUrl, ENT_QUOTES, 'UTF-8') ?>"
                                               download="<?= htmlspecialchars($downloadName, ENT_QUOTES, 'UTF-8') ?>"
                                               target="_blank"
                                               rel="noopener noreferrer"
                                               class="<?= uploadBackendBtnPrimary('px-3 py-1 text-xs') ?>">下载</a>
                                        <?php else: ?>
                                            <span class="px-3 py-1 text-xs text-slate-400">无直链</span>
                                        <?php endif; ?>
                                        <form method="post" onsubmit="return confirm('确定删除这个原始文件吗？');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(uploadBackendCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                                            <input type="hidden" name="id" value="<?= htmlspecialchars((string)$record['id'], ENT_QUOTES, 'UTF-8') ?>">
                                            <button type="submit" class="<?= uploadBackendBtnDanger() ?>">删除</button>
                                        </form>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </section>
</main>
<?php uploadBackendPageFoot(); ?>
