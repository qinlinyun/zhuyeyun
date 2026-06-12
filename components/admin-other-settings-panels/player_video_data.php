<?php
/** @var array $playerConfig */
/** @var bool $proxyEnabled */
/** @var string $message */
/** @var string $error */
$proxyEnabled = $proxyEnabled ?? false;
$tokenDisabled = !$proxyEnabled;
?>
<div class="px-4 py-4 space-y-6">
    <?php if ($message): ?>
    <div class="rounded-lg border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-600">
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>
    <?php if ($error): ?>
    <div class="rounded-lg border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-600">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <form method="POST" class="max-w-2xl space-y-5 border-b border-gray-100 pb-6">
        <input type="hidden" name="panel" value="player_video_data">

        <div>
            <h3 class="text-sm font-semibold text-gray-900 mb-3">播放器切换</h3>
            <div class="flex flex-wrap gap-4 text-sm">
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="player_engine" value="videojs" class="text-blue-600"
                        <?= ($playerConfig['engine'] ?? 'videojs') === 'videojs' ? 'checked' : '' ?>>
                    Video.js 播放器
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="player_engine" value="dplayer" class="text-blue-600"
                        <?= ($playerConfig['engine'] ?? '') === 'dplayer' ? 'checked' : '' ?>>
                    DPlayer 播放器
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="player_engine" value="xgplayer" class="text-blue-600"
                        <?= ($playerConfig['engine'] ?? '') === 'xgplayer' ? 'checked' : '' ?>>
                    西瓜播放器（xgplayer）
                </label>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4 space-y-3 <?= $tokenDisabled ? 'opacity-60' : '' ?>">
            <h3 class="text-sm font-semibold text-gray-900">Token 时效</h3>
            <p class="text-xs text-gray-500">
                需开启「后端代理」。用户播放时携带邮箱向后端申请带 token 的链接；
                开启下方选项后，后端解析 m3u8 索引，将 token 有效期设为<strong>视频总时长的 2 倍</strong>。
            </p>
            <?php if (!$proxyEnabled): ?>
            <p class="text-xs text-amber-700">请先在「开启/关闭后端代理」中启用并保存后端代理。</p>
            <?php endif; ?>
            <label class="flex items-start gap-2 text-sm <?= $tokenDisabled ? 'pointer-events-none' : '' ?>">
                <input
                    type="checkbox"
                    name="player_token_auto_duration"
                    value="1"
                    class="mt-0.5 rounded border-gray-300"
                    <?= !empty($playerConfig['token_auto_duration']) ? 'checked' : '' ?>
                    <?= $tokenDisabled ? 'disabled' : '' ?>
                >
                <span>按 m3u8 时长自动设置（2× 视频总时长）</span>
            </label>
        </div>

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-4 space-y-3 <?= $tokenDisabled ? 'opacity-60' : '' ?>">
            <h3 class="text-sm font-semibold text-gray-900">防下载</h3>
            <p class="text-xs text-gray-500">
                需开启后端代理。保存后通过 API 通知视频切片：禁止直接访问原始 m3u8/分片，必须携带后端签发的 token（<code class="text-xs">play_signed.php</code>）。
            </p>
            <label class="flex items-start gap-2 text-sm <?= $tokenDisabled ? 'pointer-events-none' : '' ?>">
                <input
                    type="checkbox"
                    name="player_anti_download"
                    value="1"
                    class="mt-0.5 rounded border-gray-300"
                    <?= !empty($playerConfig['anti_download']) ? 'checked' : '' ?>
                    <?= $tokenDisabled ? 'disabled' : '' ?>
                >
                <span>启用防下载（同步策略到视频切片）</span>
            </label>
        </div>

        <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            保存视频数据设置
        </button>
    </form>

    <div>
        <div class="flex items-center justify-between gap-3 mb-3">
            <h3 class="text-sm font-semibold text-gray-900">视频同步</h3>
            <button type="button" id="refreshSyncListBtn" class="rounded-lg border border-gray-300 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">
                刷新列表
            </button>
        </div>
        <p class="text-xs text-gray-500 mb-3">从视频切片后端拉取切片记录，手动添加到本站视频库。请先在「视频数据 API 同步」填写视频切片地址与密钥（或在「后端代理」中配置）。</p>
        <div id="syncListLoading" class="text-sm text-gray-500 py-4 hidden">加载中…</div>
        <div id="syncListError" class="text-sm text-red-600 py-2 hidden"></div>
        <div class="overflow-x-auto rounded-lg border border-gray-200">
            <table class="min-w-full text-sm">
                <thead class="bg-gray-50 text-left text-xs text-gray-500">
                    <tr>
                        <th class="px-3 py-2">视频名称</th>
                        <th class="px-3 py-2">m3u8</th>
                        <th class="px-3 py-2">封面</th>
                        <th class="px-3 py-2">时长</th>
                        <th class="px-3 py-2">状态</th>
                        <th class="px-3 py-2">操作</th>
                    </tr>
                </thead>
                <tbody id="syncListBody" class="divide-y divide-gray-100">
                    <tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">点击「刷新列表」加载数据</td></tr>
                </tbody>
            </table>
        </div>
    </div>
</div>

<!-- 添加到网站弹窗 -->
<div id="syncModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 p-4">
    <div class="w-full max-w-lg rounded-lg bg-white shadow-xl max-h-[90vh] overflow-y-auto">
        <div class="border-b px-4 py-3 flex justify-between items-center">
            <h3 class="text-sm font-semibold">添加到网站</h3>
            <button type="button" id="syncModalClose" class="text-gray-400 hover:text-gray-600 text-xl leading-none">&times;</button>
        </div>
        <form id="syncApplyForm" class="p-4 space-y-4">
            <input type="hidden" name="record_id" id="syncRecordId">
            <input type="hidden" name="m3u8_url" id="syncM3u8Url">
            <input type="hidden" name="cover_url" id="syncCoverUrl">

            <div class="flex gap-4 text-sm">
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="mode" value="new" checked class="sync-mode-radio">
                    新建视频
                </label>
                <label class="inline-flex items-center gap-2">
                    <input type="radio" name="mode" value="existing" class="sync-mode-radio">
                    已有视频
                </label>
            </div>

            <div id="syncNewFields" class="space-y-3">
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">视频名称</label>
                    <input type="text" name="title" id="syncTitle" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                </div>
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">简介</label>
                    <textarea name="description" id="syncDescription" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm"></textarea>
                </div>
            </div>

            <div id="syncExistingFields" class="space-y-3 hidden">
                <div>
                    <label class="block text-xs font-medium text-gray-700 mb-1">选择已有视频</label>
                    <select name="video_id" id="syncVideoId" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                        <option value="">加载中…</option>
                    </select>
                </div>
            </div>

            <div>
                <label class="block text-xs font-medium text-gray-700 mb-1">集数名称</label>
                <input type="text" name="episode_name" id="syncEpisodeName" value="1" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm">
                <p class="mt-1 text-xs text-gray-500">已有视频时：填写集数名可新增或更新对应分集。</p>
            </div>

            <p id="syncApplyMsg" class="text-sm hidden"></p>

            <div class="flex justify-end gap-2 pt-2">
                <button type="button" id="syncModalCancel" class="rounded-lg border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">取消</button>
                <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">确认添加</button>
            </div>
        </form>
    </div>
</div>

<script>
(function () {
    const listBody = document.getElementById('syncListBody');
    const loading = document.getElementById('syncListLoading');
    const listError = document.getElementById('syncListError');
    const modal = document.getElementById('syncModal');
    const form = document.getElementById('syncApplyForm');
    const applyMsg = document.getElementById('syncApplyMsg');
    let siteVideos = [];

    function esc(s) {
        const d = document.createElement('div');
        d.textContent = s;
        return d.innerHTML;
    }

    function formatDuration(sec) {
        sec = Math.floor(Number(sec) || 0);
        if (sec <= 0) return '—';
        const m = Math.floor(sec / 60);
        const s = sec % 60;
        return m + ':' + String(s).padStart(2, '0');
    }

    function toggleSyncMode() {
        const mode = document.querySelector('input[name="mode"]:checked')?.value || 'new';
        document.getElementById('syncNewFields').classList.toggle('hidden', mode !== 'new');
        document.getElementById('syncExistingFields').classList.toggle('hidden', mode !== 'existing');
        document.getElementById('syncTitle').required = mode === 'new';
    }

    document.querySelectorAll('.sync-mode-radio').forEach(r => r.addEventListener('change', toggleSyncMode));

    function openModal(item) {
        document.getElementById('syncRecordId').value = item.record_id;
        document.getElementById('syncM3u8Url').value = item.m3u8_url;
        document.getElementById('syncCoverUrl').value = item.cover_url || '';
        document.getElementById('syncTitle').value = item.title || '';
        document.getElementById('syncEpisodeName').value = '1';
        document.getElementById('syncDescription').value = '';
        applyMsg.classList.add('hidden');
        if (item.synced && item.video_id) {
            document.querySelector('input[name="mode"][value="existing"]').checked = true;
            document.getElementById('syncVideoId').value = String(item.video_id);
        } else {
            document.querySelector('input[name="mode"][value="new"]').checked = true;
        }
        toggleSyncMode();
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }

    function closeModal() {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }

    document.getElementById('syncModalClose').addEventListener('click', closeModal);
    document.getElementById('syncModalCancel').addEventListener('click', closeModal);

    function loadSiteVideos() {
        return fetch('../api/admin_videos_list.php', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (!d.ok) throw new Error(d.message || '加载视频列表失败');
                siteVideos = d.videos || [];
                const sel = document.getElementById('syncVideoId');
                sel.innerHTML = '<option value="">请选择</option>' + siteVideos.map(v =>
                    '<option value="' + v.id + '">' + esc(v.title) + ' (#' + v.id + ')</option>'
                ).join('');
            });
    }

    function renderList(items) {
        if (!items.length) {
            listBody.innerHTML = '<tr><td colspan="6" class="px-3 py-6 text-center text-gray-400">暂无切片记录</td></tr>';
            return;
        }
        listBody.innerHTML = items.map(item => {
            const cover = item.cover_url
                ? '<img src="' + esc(item.cover_url) + '" class="h-10 w-16 object-cover rounded" alt="">'
                : '<span class="text-gray-400">无</span>';
            const status = item.synced
                ? '<span class="text-green-600">已添加 #' + item.video_id + '</span>'
                : '<span class="text-gray-500">未添加</span>';
            return '<tr class="hover:bg-gray-50">' +
                '<td class="px-3 py-2 font-medium">' + esc(item.title) + '</td>' +
                '<td class="px-3 py-2 text-xs text-gray-500 max-w-[140px] truncate" title="' + esc(item.m3u8_url) + '">' + esc(item.m3u8_url) + '</td>' +
                '<td class="px-3 py-2">' + cover + '</td>' +
                '<td class="px-3 py-2 text-xs">' + formatDuration(item.duration_seconds) + '</td>' +
                '<td class="px-3 py-2 text-xs">' + status + '</td>' +
                '<td class="px-3 py-2"><button type="button" class="sync-add-btn text-blue-600 hover:underline text-xs" data-record="' + esc(item.record_id) + '">' +
                (item.synced ? '编辑/增集' : '添加到网站') + '</button></td></tr>';
        }).join('');

        const map = {};
        items.forEach(i => { map[i.record_id] = i; });

        listBody.querySelectorAll('.sync-add-btn').forEach(btn => {
            btn.addEventListener('click', () => {
                const rec = btn.getAttribute('data-record');
                if (map[rec]) openModal(map[rec]);
            });
        });
    }

    function loadSyncList() {
        loading.classList.remove('hidden');
        listError.classList.add('hidden');
        fetch('../api/admin_video_sync_items.php', { credentials: 'same-origin' })
            .then(async r => {
                const text = await r.text();
                try {
                    return JSON.parse(text);
                } catch (e) {
                    throw new Error('接口返回异常（非 JSON），请检查 PHP 错误或登录状态');
                }
            })
            .then(d => {
                loading.classList.add('hidden');
                if (!d.ok) throw new Error(d.message || '加载失败');
                renderList(d.items || []);
            })
            .catch(err => {
                loading.classList.add('hidden');
                listError.textContent = err.message;
                listError.classList.remove('hidden');
            });
    }

    document.getElementById('refreshSyncListBtn').addEventListener('click', loadSyncList);

    form.addEventListener('submit', e => {
        e.preventDefault();
        applyMsg.classList.add('hidden');
        const fd = new FormData(form);
        const body = Object.fromEntries(fd.entries());
        fetch('../api/admin_video_sync_apply.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/json' },
            credentials: 'same-origin',
            body: JSON.stringify(body),
        })
            .then(r => r.json())
            .then(d => {
                applyMsg.classList.remove('hidden');
                if (d.ok) {
                    applyMsg.className = 'text-sm text-green-600';
                    applyMsg.textContent = d.message + '（视频 ID: ' + d.video_id + '）';
                    setTimeout(() => { closeModal(); loadSyncList(); }, 800);
                } else {
                    applyMsg.className = 'text-sm text-red-600';
                    applyMsg.textContent = d.message || '添加失败';
                }
            })
            .catch(err => {
                applyMsg.classList.remove('hidden');
                applyMsg.className = 'text-sm text-red-600';
                applyMsg.textContent = err.message;
            });
    });

    loadSiteVideos();
})();
</script>
