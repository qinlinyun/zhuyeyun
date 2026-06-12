<?php
/** @var array $redisConfig */
/** @var string $message */
/** @var string $error */
/** @var bool $redisExtensionLoaded */
/** @var bool $redisWatchAvailable */
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

    <p class="text-sm text-gray-500 mb-4">
        方案 B：播放进度优先写入 Redis，暂停/结束时刷入 MySQL；管理端通过 Redis 发布订阅实时更新观看记录。
        需安装 <strong>PHP Redis 扩展（phpredis）</strong>。
    </p>

    <?php if (!$redisExtensionLoaded): ?>
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        <i class="fa-solid fa-triangle-exclamation mr-1"></i>
        当前 PHP 未加载 Redis 扩展，保存后也无法启用热缓存。请安装 phpredis 后重启 PHP-FPM / Apache。
    </div>
    <?php elseif ($redisConfig['enabled'] && $redisWatchAvailable): ?>
    <div class="mb-4 rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-800">
        <i class="fa-solid fa-circle-check mr-1"></i> Redis 观看进度已就绪（配置已启用且连接正常）。
    </div>
    <?php elseif ($redisConfig['enabled']): ?>
    <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
        已启用 Redis，但连接测试未通过。请检查主机、端口、密码后点击「测试连接」。
    </div>
    <?php endif; ?>

    <form method="POST" id="redisConfigForm" class="max-w-2xl space-y-5">
        <input type="hidden" name="panel" value="redis_config">

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
            <div class="flex items-start justify-between gap-4">
                <div>
                    <p class="text-sm font-medium text-gray-900">启用 Redis 观看进度</p>
                    <p class="mt-1 text-xs text-gray-500">关闭后回退为直接写 MySQL + 事件表轮询（旧模式）。</p>
                </div>
                <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                    <input type="checkbox" name="redis_enabled" value="1" class="peer sr-only" <?= !empty($redisConfig['enabled']) ? 'checked' : '' ?>>
                    <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600"></span>
                    <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                </label>
            </div>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="redis_host">主机</label>
                <input type="text" name="redis_host" id="redis_host" required
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                       value="<?= htmlspecialchars($redisConfig['host'], ENT_QUOTES, 'UTF-8') ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="redis_port">端口</label>
                <input type="number" name="redis_port" id="redis_port" min="1" max="65535" required
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                       value="<?= (int)$redisConfig['port'] ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="redis_password">密码</label>
                <input type="password" name="redis_password" id="redis_password" autocomplete="new-password"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                       value="<?= htmlspecialchars($redisConfig['password'], ENT_QUOTES, 'UTF-8') ?>"
                       placeholder="无密码可留空">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="redis_database">数据库编号</label>
                <input type="number" name="redis_database" id="redis_database" min="0" max="15"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                       value="<?= (int)$redisConfig['database'] ?>">
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="redis_prefix">键前缀</label>
                <input type="text" name="redis_prefix" id="redis_prefix"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                       value="<?= htmlspecialchars(rtrim($redisConfig['prefix'], ':'), ENT_QUOTES, 'UTF-8') ?>">
                <p class="mt-1 text-xs text-gray-500">多站点共用 Redis 时请使用不同前缀。</p>
            </div>
            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="redis_publish_throttle_sec">推送节流（秒）</label>
                <input type="number" name="redis_publish_throttle_sec" id="redis_publish_throttle_sec" min="5" max="300"
                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"
                       value="<?= (int)$redisConfig['publish_throttle_sec'] ?>">
                <p class="mt-1 text-xs text-gray-500">同一集最短间隔，减轻管理端刷新频率。</p>
            </div>
        </div>

        <div id="redisTestResult" class="hidden rounded-lg border px-4 py-3 text-sm"></div>

        <div class="flex flex-wrap gap-3">
            <button type="button" id="btnRedisTest"
                    class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm font-medium text-gray-700 hover:bg-gray-50">
                测试连接
            </button>
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                保存配置
            </button>
        </div>
    </form>
</div>

<script>
(function () {
  const btn = document.getElementById('btnRedisTest');
  const box = document.getElementById('redisTestResult');
  const form = document.getElementById('redisConfigForm');
  if (!btn || !form) return;

  btn.addEventListener('click', async function () {
    box.classList.remove('hidden', 'border-green-200', 'bg-green-50', 'text-green-800', 'border-red-200', 'bg-red-50', 'text-red-700');
    box.textContent = '测试中…';
    box.classList.add('border-gray-200', 'bg-gray-50', 'text-gray-700');

    const fd = new FormData(form);
    try {
      const r = await fetch('../api/admin_redis_test.php', { method: 'POST', body: fd, credentials: 'same-origin' });
      const d = await r.json();
      box.classList.remove('border-gray-200', 'bg-gray-50', 'text-gray-700');
      if (d.ok) {
        box.classList.add('border-green-200', 'bg-green-50', 'text-green-800');
        box.textContent = '✓ ' + (d.message || '成功') + (d.detail ? ' — ' + d.detail : '');
      } else {
        box.classList.add('border-red-200', 'bg-red-50', 'text-red-700');
        let msg = '✗ ' + (d.message || '失败');
        if (d.detail) msg += ' — ' + d.detail;
        if (!d.extension_loaded) msg += '（未安装 phpredis 扩展）';
        box.textContent = msg;
      }
    } catch (e) {
      box.classList.add('border-red-200', 'bg-red-50', 'text-red-700');
      box.textContent = '✗ 请求失败';
    }
  });
})();
</script>
