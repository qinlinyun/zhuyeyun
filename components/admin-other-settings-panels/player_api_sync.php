<?php
/** @var array $videoSyncConfig */
/** @var string $syncEndpointUrl */
/** @var string $message */
/** @var string $error */
/** @var array $serverGroups */
/** @var bool $serverGroupFeature */
$enabled = !empty($videoSyncConfig['enabled']);
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

    <p class="text-sm text-gray-500 mb-5">
        网站与「视频切片」后端通过 API 同步视频元数据，主要传输 <strong>m3u8 链接</strong>、<strong>视频名称</strong>、<strong>封面图片链接</strong>。
        切片完成后可由视频后端自动推送，或由主站拉取（需在视频切片后台配置相同密钥）。
    </p>

    <form method="POST" class="max-w-2xl space-y-5">
        <input type="hidden" name="panel" value="player_api_sync">

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900">接收数据同步</p>
                    <p class="mt-1 text-sm text-gray-500">开启后，视频切片后端可向本站推送新切片或更新已有记录。</p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input
                        type="checkbox"
                        name="video_sync_enabled"
                        value="1"
                        class="peer sr-only"
                        id="videoSyncEnabled"
                        <?= $enabled ? 'checked' : '' ?>
                    >
                    <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-300"></span>
                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                </label>
            </div>
        </div>

        <div id="videoSyncFields" class="space-y-4 <?= $enabled ? '' : 'opacity-60' ?>">
            <?php
            $backendEntries = $videoSyncConfig['backends'] ?? [];
            $fieldPrefix = 'video_sync_backend';
            $listId = 'videoSyncBackendList';
            $hint = '可配置多个视频切片节点，拉取同步列表时会合并所有后端的记录。未填则使用「后端代理」中的地址。';
            include __DIR__ . '/../admin-player-backend-list.php';
            ?>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="video_sync_api_secret">API 密钥</label>
                <input
                    type="password"
                    name="video_sync_api_secret"
                    id="video_sync_api_secret"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    placeholder="与视频切片「数据同步 API」中保持一致"
                    value="<?= htmlspecialchars($videoSyncConfig['api_secret'], ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="new-password"
                >
                <p class="mt-1 text-xs text-gray-500">至少 16 位，用于请求签名校验。</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="video_sync_path_prefix">m3u8 路径前缀</label>
                <input
                    type="text"
                    name="video_sync_path_prefix"
                    id="video_sync_path_prefix"
                    class="w-full max-w-md rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    value="<?= htmlspecialchars($videoSyncConfig['path_prefix'], ENT_QUOTES, 'UTF-8') ?>"
                >
                <p class="mt-1 text-xs text-gray-500">远程审核通过后写入 <code>storage/m3u8/用户ID/10位目录/index.m3u8</code> 与 <code>screenshot.jpg</code>，一般填 <code>storage/</code> 即可。</p>
            </div>

            <?php if ($serverGroupFeature): ?>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="video_sync_server_group_id">默认服务器分组（可选）</label>
                <select
                    name="video_sync_server_group_id"
                    id="video_sync_server_group_id"
                    class="w-full max-w-md rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                >
                    <option value="">不指定</option>
                    <?php foreach ($serverGroups as $sg): ?>
                    <option
                        value="<?= (int)$sg['id'] ?>"
                        <?= (int)($videoSyncConfig['server_group_id'] ?? 0) === (int)$sg['id'] ? 'selected' : '' ?>
                    ><?= htmlspecialchars($sg['name'], ENT_QUOTES, 'UTF-8') ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>

            <div class="rounded-lg border border-blue-100 bg-blue-50 px-4 py-3 text-xs text-blue-900 space-y-2">
                <p class="font-semibold">主站接收接口</p>
                <p><code class="break-all"><?= htmlspecialchars($syncEndpointUrl, ENT_QUOTES, 'UTF-8') ?></code></p>
                <p class="text-blue-800">POST JSON 字段：</p>
                <ul class="list-disc pl-4 space-y-1 text-blue-800">
                    <li><code>record_id</code> — 切片记录 ID（用于更新同一条视频）</li>
                    <li><code>title</code> — 视频名称</li>
                    <li><code>m3u8_url</code> — m3u8 相对或完整路径</li>
                    <li><code>cover_url</code> — 封面图片链接（可选）</li>
                    <li><code>episode_name</code> — 集数名称，默认 <code>1</code></li>
                    <li><code>exp</code>、<code>sign</code> — 时效与 HMAC-SHA256 签名</li>
                </ul>
            </div>
        </div>

        <div>
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                保存设置
            </button>
        </div>
    </form>
</div>
<script>
(function () {
    const toggle = document.getElementById('videoSyncEnabled');
    const fields = document.getElementById('videoSyncFields');
    if (!toggle || !fields) return;
    toggle.addEventListener('change', () => {
        fields.classList.toggle('opacity-60', !toggle.checked);
    });
})();
</script>
