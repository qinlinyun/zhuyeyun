<?php
/** @var array $announcementConfig */
/** @var array $announcements */
/** @var array|null $editAnnouncement */
/** @var string $message */
/** @var string $error */
/** @var bool $mailConfigured */
$freqOptions = announcementFrequencyOptions();
$editId = (int)($editAnnouncement['id'] ?? 0);
$formTitle = $editAnnouncement['title'] ?? '';
$formContent = $editAnnouncement['content'] ?? '';
$formFreq = $editAnnouncement['popup_frequency'] ?? ANNOUNCEMENT_FREQ_UNREAD;
$formEmail = !empty($editAnnouncement['email_notify']);
$formStatus = $editAnnouncement['status'] ?? 'draft';
$styleDefaults = defaultAnnouncementStyleDefaults();
$formTitleFontSize = $editAnnouncement['title_font_size'] ?? $styleDefaults['title_font_size'];
$formTitleColor = $editAnnouncement['title_color'] ?? $styleDefaults['title_color'];
$formContentFontSize = $editAnnouncement['content_font_size'] ?? $styleDefaults['content_font_size'];
$formContentColor = $editAnnouncement['content_color'] ?? $styleDefaults['content_color'];
?>
<div class="px-4 py-4 space-y-8">
    <?php if ($message): ?>
    <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-600">
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="mb-4 rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="max-w-4xl space-y-4 border-b border-gray-100 pb-8">
        <input type="hidden" name="panel" value="announcement_template">
        <p class="text-sm font-medium text-gray-900">公告模板配置</p>
        <p class="text-xs text-gray-500">
            支持嵌套 HTML。占位符：<code>{{title}}</code>、<code>{{content}}</code>、<code>{{site_name}}</code>
        </p>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="announcement_mail_subject_prefix">邮件主题前缀</label>
            <input type="text" name="announcement_mail_subject_prefix" id="announcement_mail_subject_prefix"
                   class="w-full max-w-md rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                   value="<?= htmlspecialchars($announcementConfig['mail_subject_prefix'], ENT_QUOTES, 'UTF-8') ?>">
            <p class="mt-1 text-xs text-gray-500">开启邮件通知时，主题为「前缀 + 公告标题」，内容走邮局「全员通知」模板与批次设置。</p>
        </div>
        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="announcement_html_template">弹窗 HTML 模板</label>
            <textarea name="announcement_html_template" id="announcement_html_template" rows="10"
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs leading-relaxed focus:border-blue-500 focus:outline-none"
            ><?= htmlspecialchars($announcementConfig['html_template'], ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>
        <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            保存模板配置
        </button>
    </form>

    <form method="POST" class="max-w-4xl space-y-4">
        <input type="hidden" name="panel" value="announcement_save">
        <input type="hidden" name="announcement_id" value="<?= $editId ?>">
        <p class="text-sm font-medium text-gray-900"><?= $editId > 0 ? '编辑公告' : '新建公告' ?></p>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="announcement_title">公告标题</label>
            <input type="text" name="announcement_title" id="announcement_title" required
                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                   value="<?= htmlspecialchars($formTitle, ENT_QUOTES, 'UTF-8') ?>">
        </div>

        <div>
            <label class="block text-sm font-medium text-gray-700 mb-1" for="announcement_content">公告内容（支持 HTML）</label>
            <textarea name="announcement_content" id="announcement_content" rows="8" required
                      class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs leading-relaxed focus:border-blue-500 focus:outline-none"
            ><?= htmlspecialchars($formContent, ENT_QUOTES, 'UTF-8') ?></textarea>
        </div>

        <div class="grid gap-4 sm:grid-cols-2 max-w-2xl">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="announcement_title_font_size">标题字号</label>
                <input type="text" name="announcement_title_font_size" id="announcement_title_font_size"
                       placeholder="20px"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                       value="<?= htmlspecialchars($formTitleFontSize, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="announcement_title_color">标题颜色</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="announcement_title_color" id="announcement_title_color"
                           class="h-10 w-14 cursor-pointer rounded border border-gray-300"
                           value="<?= htmlspecialchars($formTitleColor, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="text" readonly
                           class="flex-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600"
                           value="<?= htmlspecialchars($formTitleColor, ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="announcement_content_font_size">内容字号</label>
                <input type="text" name="announcement_content_font_size" id="announcement_content_font_size"
                       placeholder="14px"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                       value="<?= htmlspecialchars($formContentFontSize, ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="announcement_content_color">内容颜色</label>
                <div class="flex items-center gap-2">
                    <input type="color" name="announcement_content_color" id="announcement_content_color"
                           class="h-10 w-14 cursor-pointer rounded border border-gray-300"
                           value="<?= htmlspecialchars($formContentColor, ENT_QUOTES, 'UTF-8') ?>">
                    <input type="text" readonly
                           class="flex-1 rounded-lg border border-gray-200 bg-gray-50 px-3 py-2 text-sm text-gray-600"
                           value="<?= htmlspecialchars($formContentColor, ENT_QUOTES, 'UTF-8') ?>">
                </div>
            </div>
        </div>
        <p class="text-xs text-gray-500">字号支持 px、em、rem、%，例如 16px、1.1rem。修改已发布公告的样式会重置用户已读状态（未读才弹时再次弹出）。</p>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="announcement_popup_frequency">弹窗频率</label>
                <select name="announcement_popup_frequency" id="announcement_popup_frequency"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                    <?php foreach ($freqOptions as $val => $label): ?>
                    <option value="<?= htmlspecialchars($val, ENT_QUOTES, 'UTF-8') ?>" <?= $formFreq === $val ? 'selected' : '' ?>>
                        <?= htmlspecialchars($label, ENT_QUOTES, 'UTF-8') ?>
                    </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="announcement_status">发布状态</label>
                <select name="announcement_status" id="announcement_status"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                    <option value="draft" <?= $formStatus === 'draft' ? 'selected' : '' ?>>草稿</option>
                    <option value="published" <?= $formStatus === 'published' ? 'selected' : '' ?>>已发布</option>
                </select>
            </div>
        </div>

        <label class="inline-flex items-center gap-2 text-sm text-gray-700">
            <input type="checkbox" name="announcement_email_notify" value="1" class="rounded border-gray-300"
                <?= $formEmail ? 'checked' : '' ?> <?= !$mailConfigured ? 'disabled' : '' ?>>
            发布时发送邮件全员通知（调用邮局管理 · 全员通知）
        </label>
        <?php if (!$mailConfigured): ?>
        <p class="text-xs text-amber-600">请先在「邮局配置」中完成邮局设置后再启用邮件通知。</p>
        <?php endif; ?>

        <div class="flex flex-wrap gap-3">
            <button type="submit" class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700">
                <?= $editId > 0 ? '保存修改' : '创建公告' ?>
            </button>
            <?php if ($editId > 0): ?>
            <a href="other_settings.php?section=announcement"
               class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                取消编辑
            </a>
            <?php endif; ?>
        </div>
    </form>

    <div class="max-w-4xl">
        <p class="text-sm font-medium text-gray-900 mb-3">已创建公告</p>
        <div class="overflow-x-auto rounded-lg border border-gray-200">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500">
                    <tr>
                        <th class="px-3 py-2">标题</th>
                        <th class="px-3 py-2">状态</th>
                        <th class="px-3 py-2">弹窗频率</th>
                        <th class="px-3 py-2">邮件</th>
                        <th class="px-3 py-2">更新时间</th>
                        <th class="px-3 py-2">操作</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-100">
                    <?php if ($announcements === []): ?>
                    <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">暂无公告</td></tr>
                    <?php else: ?>
                    <?php foreach ($announcements as $item): ?>
                    <tr class="hover:bg-gray-50">
                        <td class="px-3 py-2 font-medium text-gray-800"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="px-3 py-2">
                            <span class="rounded-full px-2 py-0.5 text-xs <?= ($item['status'] ?? '') === 'published' ? 'bg-green-100 text-green-700' : 'bg-gray-100 text-gray-600' ?>">
                                <?= ($item['status'] ?? '') === 'published' ? '已发布' : '草稿' ?>
                            </span>
                        </td>
                        <td class="px-3 py-2 text-gray-600"><?= htmlspecialchars($freqOptions[$item['popup_frequency']] ?? $item['popup_frequency'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="px-3 py-2 text-gray-600"><?= !empty($item['email_notify']) ? '是' : '否' ?></td>
                        <td class="px-3 py-2 text-gray-500"><?= htmlspecialchars(formatChinaDateTime($item['updated_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="px-3 py-2">
                            <div class="flex flex-wrap gap-2">
                                <a href="other_settings.php?section=announcement&edit=<?= (int)$item['id'] ?>"
                                   class="text-blue-600 hover:underline text-xs">编辑</a>
                                <form method="POST" class="inline" onsubmit="return confirm('确定删除该公告？将同步删除站内通知。');">
                                    <input type="hidden" name="panel" value="announcement_delete">
                                    <input type="hidden" name="announcement_id" value="<?= (int)$item['id'] ?>">
                                    <button type="submit" class="text-red-600 hover:underline text-xs">删除</button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    <?php endif; ?>
                </tbody>
            </table>
        </div>
    </div>
</div>
