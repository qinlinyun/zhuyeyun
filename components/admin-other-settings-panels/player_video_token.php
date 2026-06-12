<?php
/** @var string $message */
/** @var string $error */
/** @var bool $proxyEnabled */
$proxyEnabled = $proxyEnabled ?? false;
?>
<div class="px-4 py-4 space-y-4">
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

    <p class="text-sm text-gray-500">
        自动列出本站全部视频。勾选「直链播放」的视频在播放时将<strong>跳过后端代理</strong>，使用线路域名 + 分集路径直接播放（适用于旧视频或未接入切片的资源）。
        未勾选的视频在开启后端代理后仍走 Token 申请流程。
    </p>

    <?php if (!$proxyEnabled): ?>
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        当前全站后端代理未开启，所有视频均为直链播放。下方设置将在开启后端代理后生效。
    </div>
    <?php endif; ?>

    <div class="flex flex-wrap items-center gap-3">
        <input
            type="search"
            id="videoTokenSearch"
            class="rounded-lg border border-gray-300 px-3 py-2 text-sm w-full max-w-xs focus:border-blue-500 focus:outline-none"
            placeholder="搜索视频名称或 ID…"
        >
        <button type="button" id="videoTokenReloadBtn" class="rounded-lg border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50">
            刷新列表
        </button>
        <button type="button" id="videoTokenSaveBtn" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
            保存设置
        </button>
        <span id="videoTokenSaveMsg" class="text-sm hidden"></span>
    </div>

    <div id="videoTokenLoading" class="text-sm text-gray-500 py-6">正在加载视频列表…</div>
    <div id="videoTokenError" class="text-sm text-red-600 py-2 hidden"></div>

    <div id="videoTokenTableWrap" class="hidden overflow-x-auto rounded-lg border border-gray-200">
        <table class="min-w-full text-sm">
            <thead class="bg-gray-50 text-left text-xs text-gray-500">
                <tr>
                    <th class="px-3 py-2 w-16">ID</th>
                    <th class="px-3 py-2">视频名称</th>
                    <th class="px-3 py-2 w-24">集数</th>
                    <th class="px-3 py-2 w-32">服务器组</th>
                    <th class="px-3 py-2 w-36 text-center">直链播放<br><span class="font-normal text-gray-400">跳过后端代理</span></th>
                </tr>
            </thead>
            <tbody id="videoTokenListBody" class="divide-y divide-gray-100"></tbody>
        </table>
    </div>
</div>
<script>
(function () {
    const loading = document.getElementById('videoTokenLoading');
    const errBox = document.getElementById('videoTokenError');
    const wrap = document.getElementById('videoTokenTableWrap');
    const tbody = document.getElementById('videoTokenListBody');
    const search = document.getElementById('videoTokenSearch');
    const saveBtn = document.getElementById('videoTokenSaveBtn');
    const reloadBtn = document.getElementById('videoTokenReloadBtn');
    const saveMsg = document.getElementById('videoTokenSaveMsg');

    let allItems = [];

    function renderRows(items) {
        if (!tbody) return;
        tbody.innerHTML = '';
        if (!items.length) {
            tbody.innerHTML = '<tr><td colspan="5" class="px-3 py-6 text-center text-gray-500">暂无视频</td></tr>';
            return;
        }
        items.forEach(item => {
            const tr = document.createElement('tr');
            tr.className = 'hover:bg-gray-50';
            tr.dataset.videoId = String(item.id);
            tr.innerHTML =
                '<td class="px-3 py-2 text-gray-500">' + item.id + '</td>' +
                '<td class="px-3 py-2 font-medium text-gray-900">' + escapeHtml(item.title) + '</td>' +
                '<td class="px-3 py-2 text-gray-600">' + item.episode_count + '</td>' +
                '<td class="px-3 py-2 text-gray-600">' + escapeHtml(item.server_group_name || '—') + '</td>' +
                '<td class="px-3 py-2 text-center">' +
                    '<input type="checkbox" class="skip-proxy-cb rounded border-gray-300" data-id="' + item.id + '"' +
                    (item.skip_backend_proxy ? ' checked' : '') + '>' +
                '</td>';
            tbody.appendChild(tr);
        });
    }

    function escapeHtml(s) {
        return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
    }

    function filterItems() {
        const q = (search && search.value ? search.value : '').trim().toLowerCase();
        if (!q) {
            renderRows(allItems);
            return;
        }
        renderRows(allItems.filter(i =>
            String(i.id).includes(q) || (i.title || '').toLowerCase().includes(q)
        ));
    }

    function loadList() {
        if (loading) loading.classList.remove('hidden');
        if (errBox) errBox.classList.add('hidden');
        if (wrap) wrap.classList.add('hidden');

        fetch('../api/admin_video_token_settings.php', { credentials: 'same-origin' })
            .then(r => r.json())
            .then(d => {
                if (loading) loading.classList.add('hidden');
                if (!d.ok) throw new Error(d.message || '加载失败');
                allItems = d.items || [];
                filterItems();
                if (wrap) wrap.classList.remove('hidden');
            })
            .catch(err => {
                if (loading) loading.classList.add('hidden');
                if (errBox) {
                    errBox.textContent = err.message || '加载失败';
                    errBox.classList.remove('hidden');
                }
            });
    }

    function saveSettings() {
        const videoIds = allItems.map(i => i.id);
        const skipIds = [];
        document.querySelectorAll('.skip-proxy-cb:checked').forEach(cb => {
            skipIds.push(parseInt(cb.getAttribute('data-id'), 10));
        });

        saveBtn.disabled = true;
        saveMsg.classList.add('hidden');

        fetch('../api/admin_video_token_settings.php', {
            method: 'POST',
            credentials: 'same-origin',
            headers: { 'Content-Type': 'application/json' },
            body: JSON.stringify({ video_ids: videoIds, skip_ids: skipIds }),
        })
            .then(r => r.json())
            .then(d => {
                saveBtn.disabled = false;
                saveMsg.classList.remove('hidden');
                if (d.ok) {
                    saveMsg.className = 'text-sm text-green-600';
                    saveMsg.textContent = d.message || '已保存';
                    allItems.forEach(i => {
                        i.skip_backend_proxy = skipIds.indexOf(i.id) >= 0;
                    });
                } else {
                    saveMsg.className = 'text-sm text-red-600';
                    saveMsg.textContent = d.message || '保存失败';
                }
            })
            .catch(err => {
                saveBtn.disabled = false;
                saveMsg.classList.remove('hidden');
                saveMsg.className = 'text-sm text-red-600';
                saveMsg.textContent = err.message || '保存失败';
            });
    }

    if (search) search.addEventListener('input', filterItems);
    if (reloadBtn) reloadBtn.addEventListener('click', loadList);
    if (saveBtn) saveBtn.addEventListener('click', saveSettings);

    loadList();
})();
</script>
