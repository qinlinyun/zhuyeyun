<?php
/** @var array<string, mixed> $filterConfig */
$filterConfig = $filterConfig ?? defaultCommentFilterConfig();
?>
<div class="rounded-xl border border-gray-200 bg-white shadow-sm overflow-hidden">
    <div class="border-b border-gray-100 px-5 py-3">
        <p class="text-sm font-semibold text-gray-900">评论屏蔽设置</p>
        <p class="mt-1 text-xs text-gray-500">配置关键词屏蔽与链接屏蔽规则，保存后立即对用户发表评论生效。</p>
    </div>
    <form method="post" class="px-5 py-5 space-y-6">
        <input type="hidden" name="panel" value="comment_filter">

        <section class="space-y-3">
            <div class="flex items-start gap-3">
                <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-900">
                    <input type="checkbox" name="keywords_enabled" value="1" class="rounded border-gray-300"
                        <?= !empty($filterConfig['keywords_enabled']) ? 'checked' : '' ?>>
                    启用关键词屏蔽
                </label>
            </div>
            <div>
                <label for="blocked_keywords" class="block text-xs font-medium text-gray-700">屏蔽关键词</label>
                <p class="mt-1 text-xs text-gray-500">每行一个，不区分大小写，命中任意一条即拒绝发表。</p>
                <textarea id="blocked_keywords" name="blocked_keywords" rows="8"
                          class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono"
                          placeholder="广告&#10;加微信&#10;代刷"><?= htmlspecialchars(commentFilterKeywordsText($filterConfig), ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
        </section>

        <section class="border-t border-gray-100 pt-6 space-y-3">
            <div class="flex items-start gap-3">
                <label class="inline-flex items-center gap-2 text-sm font-medium text-gray-900">
                    <input type="checkbox" name="link_block_enabled" value="1" class="rounded border-gray-300"
                        <?= !empty($filterConfig['link_block_enabled']) ? 'checked' : '' ?>>
                    启用链接屏蔽
                </label>
            </div>
            <div>
                <label for="link_whitelist" class="block text-xs font-medium text-gray-700">链接白名单</label>
                <p class="mt-1 text-xs text-gray-500">每行一个域名或完整链接；白名单内及其子域名不屏蔽，其余链接一律拒绝。</p>
                <textarea id="link_whitelist" name="link_whitelist" rows="6"
                          class="mt-2 w-full rounded-lg border border-gray-300 px-3 py-2 text-sm font-mono"
                          placeholder="example.com&#10;https://t.me/your_channel"><?= htmlspecialchars(commentFilterWhitelistText($filterConfig), ENT_QUOTES, 'UTF-8') ?></textarea>
            </div>
            <div class="rounded-lg bg-gray-50 px-4 py-3 text-xs text-gray-600">
                <p class="font-medium text-gray-700">检测范围</p>
                <ul class="mt-1 list-disc pl-4 space-y-0.5">
                    <li><code class="text-[11px]">http://</code> / <code class="text-[11px]">https://</code> 开头的链接</li>
                    <li><code class="text-[11px]">www.</code> 开头的链接</li>
                    <li>形如 <code class="text-[11px]">example.com</code> 的裸域名</li>
                </ul>
            </div>
        </section>

        <div class="border-t border-gray-100 pt-4">
            <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">保存设置</button>
        </div>
    </form>
</div>
