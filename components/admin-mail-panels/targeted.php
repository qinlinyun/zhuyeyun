<?php
/** @var string $message */
/** @var string $error */
/** @var bool $mailConfigured */
/** @var array<int,array{id:string,name:string,html_template:string,updated_at:string}> $targetedTemplates */
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

    <?php if (!$mailConfigured): ?>
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800">
        请先在「邮局配置」中完成 SMTP 设置并启用，否则无法发送指定通知。
    </div>
    <?php endif; ?>

    <p class="text-sm text-gray-500">
        选择一个或多个用户，发送自定义内容邮件。默认显示全部用户并分页浏览；也可按用户名/邮箱筛选。支持创建并选择 HTML 邮件模板（模板需包含 <code>{{content}}</code> 占位符）。
    </p>

    <form method="POST" id="targetedSendForm" class="space-y-4">
        <input type="hidden" name="panel" value="targeted_send">
        <input type="hidden" name="targeted_user_ids" id="targeted_user_ids" value="">

        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-lg border border-gray-200 bg-white p-4">
                <div class="flex flex-wrap items-end gap-3">
                    <div class="flex-1 min-w-[220px]">
                        <label class="block text-sm font-medium text-gray-700 mb-1" for="targetedUserQuery">用户筛选（用户名/邮箱）</label>
                        <input
                            type="text"
                            id="targetedUserQuery"
                            class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                            placeholder="例如：test、example.com、张三"
                            autocomplete="off"
                        >
                    </div>
                    <div class="flex items-center gap-2">
                        <button type="button" id="targetedUserSearchBtn"
                                class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                            搜索
                        </button>
                        <button type="button" id="targetedUserClearBtn"
                                class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                            清空已选
                        </button>
                    </div>
                </div>

                <div class="mt-3 text-xs text-gray-500">
                    已选择 <strong id="targetedSelectedCount">0</strong> 人
                </div>

                <div class="mt-3">
                    <div id="targetedSelectedList" class="flex flex-wrap gap-2"></div>
                </div>

                <div class="mt-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <p class="text-sm font-medium text-gray-900">用户列表</p>
                        <p class="text-xs text-gray-500" id="targetedUserSummary">加载中…</p>
                    </div>
                    <div class="mt-2 flex flex-wrap items-center gap-2">
                        <label class="text-xs text-gray-500" for="targetedUserPerPage">每页</label>
                        <select id="targetedUserPerPage"
                                class="rounded-lg border border-gray-300 px-2 py-1.5 text-sm focus:border-blue-500 focus:outline-none">
                            <option value="10">10 条</option>
                            <option value="20">20 条</option>
                            <option value="30">30 条</option>
                            <option value="50">50 条</option>
                        </select>
                    </div>
                    <div id="targetedUserLoading" class="mt-2 hidden text-sm text-gray-500">正在加载…</div>
                    <div id="targetedUserEmpty" class="mt-2 hidden text-sm text-gray-500">暂无用户</div>
                    <ul id="targetedUserResults" class="mt-2 divide-y divide-gray-100 rounded-lg border border-gray-200 bg-white"></ul>
                    <div id="targetedUserPager" class="mt-3 hidden flex flex-wrap items-center justify-center gap-2"></div>
                </div>
            </div>

            <div class="rounded-lg border border-gray-200 bg-white p-4 space-y-4">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="targeted_subject">邮件主题</label>
                    <input
                        type="text"
                        name="targeted_subject"
                        id="targeted_subject"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                        placeholder="例如：系统通知 / 活动提醒"
                        required
                        <?= !$mailConfigured ? 'disabled' : '' ?>
                    >
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="targeted_template_id">选择邮件模板（可选）</label>
                    <select
                        name="targeted_template_id"
                        id="targeted_template_id"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                        <?= !$mailConfigured ? 'disabled' : '' ?>
                    >
                        <option value="">不使用模板（直接发送下方 HTML）</option>
                        <?php foreach ($targetedTemplates as $tpl): ?>
                        <option value="<?= htmlspecialchars($tpl['id'], ENT_QUOTES, 'UTF-8') ?>">
                            <?= htmlspecialchars($tpl['name'], ENT_QUOTES, 'UTF-8') ?>
                        </option>
                        <?php endforeach; ?>
                    </select>
                    <p class="mt-1 text-xs text-gray-500">
                        使用模板时，将把下方内容插入模板的 <code>{{content}}</code> 位置；同时支持 <code>{{username}}</code>/<code>{{email}}</code>/<code>{{site_name}}</code>。
                    </p>
                </div>

                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="targeted_content">发送内容（HTML）</label>
                    <textarea
                        name="targeted_content"
                        id="targeted_content"
                        rows="10"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm leading-relaxed focus:border-blue-500 focus:outline-none"
                        placeholder="<p>您好，这是一封指定通知。</p>"
                        required
                        <?= !$mailConfigured ? 'disabled' : '' ?>
                    ></textarea>
                </div>

                <div class="flex flex-wrap items-center gap-3">
                    <button
                        type="submit"
                        class="rounded-lg bg-red-600 px-4 py-2 text-sm font-medium text-white hover:bg-red-700 disabled:opacity-50"
                        <?= !$mailConfigured ? 'disabled' : '' ?>
                        onclick="return confirm('确定向已选择用户发送邮件吗？');"
                    >
                        发送指定通知
                    </button>
                    <span class="text-xs text-gray-500">建议先选择少量用户进行验证。</span>
                </div>
            </div>
        </div>
    </form>

    <div class="rounded-lg border border-gray-200 bg-white p-4">
        <div class="flex items-start justify-between gap-4">
            <div>
                <p class="text-sm font-semibold text-gray-900">预设 HTML 邮件模板</p>
                <p class="mt-1 text-xs text-gray-500">填写模板名称和 HTML 模板代码（必须包含 <code>{{content}}</code>）。</p>
            </div>
        </div>

        <form method="POST" class="mt-4 space-y-4" id="targetedTemplateForm">
            <input type="hidden" name="panel" value="targeted_template_save">
            <input type="hidden" name="targeted_tpl_id" id="targeted_tpl_id" value="">

            <div class="grid gap-4 sm:grid-cols-2">
                <div>
                    <label class="block text-sm font-medium text-gray-700 mb-1" for="targeted_tpl_name">模板名称</label>
                    <input
                        type="text"
                        name="targeted_tpl_name"
                        id="targeted_tpl_name"
                        class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none"
                        placeholder="例如：活动通知模板"
                        required
                    >
                </div>
                <div class="flex items-end gap-2">
                    <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                        保存模板
                    </button>
                    <button type="button" id="targetedTplResetBtn"
                            class="rounded-lg border border-gray-300 bg-white px-4 py-2 text-sm text-gray-700 hover:bg-gray-50">
                        新建
                    </button>
                </div>
            </div>

            <div>
                <label class="block text-sm font-medium text-gray-700 mb-1" for="targeted_tpl_html">模板 HTML 代码</label>
                <textarea
                    name="targeted_tpl_html"
                    id="targeted_tpl_html"
                    rows="10"
                    class="w-full rounded-lg border border-gray-300 px-3 py-2 font-mono text-xs leading-relaxed focus:border-blue-500 focus:outline-none"
                    placeholder="<?= htmlspecialchars(defaultMailTargetedHtmlTemplate(), ENT_QUOTES, 'UTF-8') ?>"
                    required
                ></textarea>
            </div>
        </form>

        <div class="mt-6 border-t border-gray-100 pt-4">
            <p class="text-sm font-medium text-gray-900">模板列表</p>
            <?php if (empty($targetedTemplates)): ?>
            <p class="mt-2 text-sm text-gray-500">暂无模板。可先在上方新建并保存。</p>
            <?php else: ?>
            <div class="mt-3 overflow-auto">
                <table class="min-w-full text-sm">
                    <thead>
                    <tr class="text-left text-xs uppercase tracking-wide text-gray-500">
                        <th class="py-2 pr-3">名称</th>
                        <th class="py-2 pr-3">更新时间</th>
                        <th class="py-2 pr-3">操作</th>
                    </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-100">
                    <?php foreach ($targetedTemplates as $tpl): ?>
                    <tr>
                        <td class="py-2 pr-3">
                            <span class="font-medium text-gray-900"><?= htmlspecialchars($tpl['name'], ENT_QUOTES, 'UTF-8') ?></span>
                        </td>
                        <td class="py-2 pr-3 text-gray-500"><?= htmlspecialchars($tpl['updated_at'], ENT_QUOTES, 'UTF-8') ?></td>
                        <td class="py-2 pr-3">
                            <div class="flex flex-wrap gap-2">
                                <button
                                    type="button"
                                    class="rounded-lg border border-gray-300 bg-white px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50"
                                    data-action="edit-template"
                                    data-tpl-id="<?= htmlspecialchars($tpl['id'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-tpl-name="<?= htmlspecialchars($tpl['name'], ENT_QUOTES, 'UTF-8') ?>"
                                    data-tpl-html="<?= htmlspecialchars($tpl['html_template'], ENT_QUOTES, 'UTF-8') ?>"
                                >
                                    编辑
                                </button>
                                <form method="POST" onsubmit="return confirm('确定删除该模板吗？');">
                                    <input type="hidden" name="panel" value="targeted_template_delete">
                                    <input type="hidden" name="targeted_tpl_id" value="<?= htmlspecialchars($tpl['id'], ENT_QUOTES, 'UTF-8') ?>">
                                    <button type="submit"
                                            class="rounded-lg border border-red-300 bg-white px-3 py-1.5 text-xs text-red-600 hover:bg-red-50">
                                        删除
                                    </button>
                                </form>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
(() => {
    const apiUrl = '../api/mail_targeted_user_search.php';
    const queryInput = document.getElementById('targetedUserQuery');
    const searchBtn = document.getElementById('targetedUserSearchBtn');
    const clearBtn = document.getElementById('targetedUserClearBtn');
    const loadingEl = document.getElementById('targetedUserLoading');
    const emptyEl = document.getElementById('targetedUserEmpty');
    const resultsEl = document.getElementById('targetedUserResults');
    const selectedCountEl = document.getElementById('targetedSelectedCount');
    const selectedListEl = document.getElementById('targetedSelectedList');
    const hiddenIdsEl = document.getElementById('targeted_user_ids');
    const perPageEl = document.getElementById('targetedUserPerPage');
    const summaryEl = document.getElementById('targetedUserSummary');
    const pagerEl = document.getElementById('targetedUserPager');

    const tplForm = document.getElementById('targetedTemplateForm');
    const tplIdEl = document.getElementById('targeted_tpl_id');
    const tplNameEl = document.getElementById('targeted_tpl_name');
    const tplHtmlEl = document.getElementById('targeted_tpl_html');
    const tplResetBtn = document.getElementById('targetedTplResetBtn');

    /** @type {Map<number, {id:number, label:string, email:string}>} */
    const selected = new Map();
    let currentPage = 1;

    function updateSummary(meta) {
        if (!summaryEl) return;
        if (!meta || !meta.total) {
            summaryEl.textContent = meta && meta.total === 0 ? '共 0 条' : '';
            return;
        }
        let text = '共 ' + meta.total + ' 条';
        if (meta.pages > 1) {
            text += ' · 第 ' + meta.page + ' / ' + meta.pages + ' 页（' + meta.range_start + '–' + meta.range_end + '）';
        }
        summaryEl.textContent = text;
    }

    function renderPager(meta) {
        if (!pagerEl) return;
        pagerEl.innerHTML = '';
        if (!meta || meta.pages <= 1) {
            pagerEl.classList.add('hidden');
            return;
        }
        pagerEl.classList.remove('hidden');

        function addBtn(label, page, disabled, active) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.textContent = label;
            btn.disabled = !!disabled;
            btn.className = 'rounded-lg px-3 py-1.5 text-xs '
                + (active ? 'bg-gray-900 text-white' : 'border border-gray-200 text-gray-700 hover:bg-gray-50 disabled:opacity-45');
            if (!disabled && !active) {
                btn.addEventListener('click', () => loadUsers(page));
            }
            pagerEl.appendChild(btn);
        }

        addBtn('首页', 1, meta.page <= 1, false);
        addBtn('上一页', meta.page - 1, meta.page <= 1, false);
        const start = Math.max(1, meta.page - 2);
        const end = Math.min(meta.pages, meta.page + 2);
        for (let p = start; p <= end; p++) {
            addBtn(String(p), p, false, p === meta.page);
        }
        addBtn('下一页', meta.page + 1, meta.page >= meta.pages, false);
        addBtn('末页', meta.pages, meta.page >= meta.pages, false);
    }

    function updateSelectedUi() {
        const ids = Array.from(selected.keys()).sort((a, b) => a - b);
        hiddenIdsEl.value = ids.join(',');
        selectedCountEl.textContent = String(ids.length);

        selectedListEl.innerHTML = '';
        ids.slice(0, 80).forEach(id => {
            const u = selected.get(id);
            if (!u) return;
            const pill = document.createElement('span');
            pill.className = 'inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-xs text-blue-700 border border-blue-100';
            pill.innerHTML = `<span>${escapeHtml(u.label)}</span><button type="button" class="text-blue-700 hover:text-blue-900" data-remove-id="${id}" aria-label="移除">×</button>`;
            selectedListEl.appendChild(pill);
        });

        if (ids.length > 80) {
            const more = document.createElement('span');
            more.className = 'text-xs text-gray-500';
            more.textContent = `… 还有 ${ids.length - 80} 人未展示`;
            selectedListEl.appendChild(more);
        }
    }

    function escapeHtml(str) {
        return String(str)
            .replaceAll('&', '&amp;')
            .replaceAll('<', '&lt;')
            .replaceAll('>', '&gt;')
            .replaceAll('"', '&quot;')
            .replaceAll("'", '&#039;');
    }

    function setLoading(isLoading) {
        loadingEl.classList.toggle('hidden', !isLoading);
    }

    function setEmpty(isEmpty) {
        emptyEl.classList.toggle('hidden', !isEmpty);
    }

    function renderResults(users) {
        resultsEl.innerHTML = '';
        if (!users || users.length === 0) {
            setEmpty(true);
            return;
        }
        setEmpty(false);

        users.forEach(u => {
            const id = Number(u.id || 0);
            const username = (u.display_name && String(u.display_name).trim() !== '') ? u.display_name : u.username;
            const email = String(u.email || '');
            const status = String(u.status || '');

            const li = document.createElement('li');
            li.className = 'flex items-center justify-between gap-3 px-3 py-2';

            const left = document.createElement('div');
            left.className = 'min-w-0';
            left.innerHTML = `
                <p class="text-sm font-medium text-gray-900 truncate">${escapeHtml(username || '')} <span class="text-xs text-gray-400">#${id}</span></p>
                <p class="text-xs text-gray-500 truncate">${escapeHtml(email)}${status ? ' · ' + escapeHtml(status) : ''}</p>
            `;

            const right = document.createElement('div');
            right.className = 'shrink-0 flex items-center gap-2';
            const checked = selected.has(id);
            right.innerHTML = `
                <label class="inline-flex items-center gap-2 text-xs text-gray-700">
                    <input type="checkbox" class="rounded border-gray-300" data-user-id="${id}" ${checked ? 'checked' : ''}>
                    选择
                </label>
            `;

            li.appendChild(left);
            li.appendChild(right);
            resultsEl.appendChild(li);
        });
    }

    async function loadUsers(page) {
        const q = (queryInput.value || '').trim();
        const perPage = Number(perPageEl?.value || 10) || 10;
        currentPage = Math.max(1, Number(page || 1));
        setLoading(true);
        try {
            const params = new URLSearchParams({
                q: q,
                page: String(currentPage),
                per_page: String(perPage),
            });
            const res = await fetch(apiUrl + '?' + params.toString(), { credentials: 'same-origin' });
            const data = await res.json();
            if (!data || !data.ok) {
                renderResults([]);
                updateSummary(null);
                renderPager(null);
                return;
            }
            currentPage = Number(data.page || currentPage);
            renderResults(data.users || []);
            updateSummary(data);
            renderPager(data);
        } catch (e) {
            renderResults([]);
            updateSummary(null);
            renderPager(null);
        } finally {
            setLoading(false);
        }
    }

    function search() {
        loadUsers(1);
    }

    resultsEl.addEventListener('change', (e) => {
        const el = e.target;
        if (!(el instanceof HTMLInputElement)) return;
        if (el.type !== 'checkbox') return;
        const id = Number(el.dataset.userId || 0);
        if (!id) return;

        const row = el.closest('li');
        const nameEl = row ? row.querySelector('p') : null;
        const label = nameEl ? nameEl.textContent.replace(/\s+#\d+$/, '') : ('用户 #' + id);
        const emailEl = row ? row.querySelector('p:nth-child(2)') : null;
        const email = emailEl ? emailEl.textContent.split('·')[0].trim() : '';

        if (el.checked) {
            selected.set(id, { id, label, email });
        } else {
            selected.delete(id);
        }
        updateSelectedUi();
    });

    selectedListEl.addEventListener('click', (e) => {
        const el = e.target;
        if (!(el instanceof HTMLElement)) return;
        const removeId = Number(el.dataset.removeId || 0);
        if (!removeId) return;
        selected.delete(removeId);
        updateSelectedUi();
        const cb = resultsEl.querySelector(`input[type="checkbox"][data-user-id="${removeId}"]`);
        if (cb instanceof HTMLInputElement) cb.checked = false;
    });

    searchBtn.addEventListener('click', search);
    perPageEl?.addEventListener('change', () => loadUsers(1));
    queryInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
            e.preventDefault();
            search();
        }
    });

    clearBtn.addEventListener('click', () => {
        selected.clear();
        updateSelectedUi();
        resultsEl.querySelectorAll('input[type="checkbox"][data-user-id]').forEach(cb => {
            if (cb instanceof HTMLInputElement) cb.checked = false;
        });
    });

    document.querySelectorAll('[data-action="edit-template"]').forEach(btn => {
        btn.addEventListener('click', () => {
            const id = btn.getAttribute('data-tpl-id') || '';
            const name = btn.getAttribute('data-tpl-name') || '';
            const html = btn.getAttribute('data-tpl-html') || '';
            tplIdEl.value = id;
            tplNameEl.value = name;
            tplHtmlEl.value = html;
            tplNameEl.focus();
            tplForm.scrollIntoView({ behavior: 'smooth', block: 'start' });
        });
    });

    tplResetBtn.addEventListener('click', () => {
        tplIdEl.value = '';
        tplNameEl.value = '';
        tplHtmlEl.value = '';
        tplNameEl.focus();
    });

    // 默认加载全部用户（第 1 页）
    loadUsers(1);
})();
</script>

