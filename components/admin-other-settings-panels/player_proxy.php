<?php
/** @var array $playerProxyConfig */
/** @var string $message */
/** @var string $error */
$enabled = !empty($playerProxyConfig['enabled']);
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

    <form method="POST" class="max-w-2xl space-y-5">
        <input type="hidden" name="panel" value="player_proxy">

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900">后端代理</p>
                    <p class="mt-1 text-sm text-gray-500">
                        开启后，播放器不再直接使用 CDN 直链，而是由本站携带当前用户邮箱向「视频切片」后端申请时效性播放链接（含签名 token），再交给播放器播放。
                    </p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input
                        type="checkbox"
                        name="player_proxy_enabled"
                        value="1"
                        class="peer sr-only"
                        id="playerProxyEnabled"
                        <?= $enabled ? 'checked' : '' ?>
                    >
                    <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-300"></span>
                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                </label>
            </div>
            <p class="mt-3 text-xs text-gray-500">
                当前状态：<span class="font-medium <?= $enabled ? 'text-green-600' : 'text-red-600' ?>"><?= $enabled ? '已开启' : '已关闭' ?></span>
            </p>
        </div>

        <div id="playerProxyFields" class="space-y-4 <?= $enabled ? '' : 'opacity-60' ?>">
            <?php
            $backendEntries = $playerProxyConfig['backends'] ?? [];
            $fieldPrefix = 'player_proxy_backend';
            $listId = 'playerProxyBackendList';
            $hint = '可配置多个视频切片节点。播放时按顺序尝试，直到获取播放链接成功。需能访问 <code class="text-xs">/api/play_token.php</code>。';
            include __DIR__ . '/../admin-player-backend-list.php';
            ?>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="player_proxy_api_secret">API 密钥</label>
                <input
                    type="password"
                    name="player_proxy_api_secret"
                    id="player_proxy_api_secret"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    placeholder="与视频后端 API 配置中保持一致"
                    value="<?= htmlspecialchars($playerProxyConfig['api_secret'], ENT_QUOTES, 'UTF-8') ?>"
                    autocomplete="new-password"
                >
                <p class="mt-1 text-xs text-gray-500">至少 16 位，请在视频切片后台「API 接口」中填写相同密钥。</p>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="player_proxy_token_ttl">播放链接有效期（秒）</label>
                <input
                    type="number"
                    name="player_proxy_token_ttl"
                    id="player_proxy_token_ttl"
                    min="300"
                    max="86400"
                    class="w-full max-w-xs rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                    value="<?= (int)$playerProxyConfig['token_ttl'] ?>"
                >
                <p class="mt-1 text-xs text-gray-500">建议 3600～7200（1～2 小时）。过期后播放器可刷新页面或切换集数重新获取。</p>
            </div>

            <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-xs text-amber-900">
                <p class="font-medium mb-1">部署提示</p>
                <ul class="list-disc pl-4 space-y-1">
                    <li>各播放线路域名需能访问视频切片目录下的 <code>play_signed.php</code>（可与切片站点同机部署）。</li>
                    <li>关闭后端代理时，仍按原逻辑使用线路域名 + 相对路径直链播放。</li>
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
    const toggle = document.getElementById('playerProxyEnabled');
    const fields = document.getElementById('playerProxyFields');
    if (!toggle || !fields) return;
    toggle.addEventListener('change', () => {
        fields.classList.toggle('opacity-60', !toggle.checked);
    });
})();
</script>
