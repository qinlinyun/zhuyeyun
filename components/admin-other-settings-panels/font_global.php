<?php
/** @var array $fontConfig */
/** @var string $message */
/** @var string $error */
$fontConfig = $fontConfig ?? defaultFontConfig();
$mode = $fontConfig['mode'];
?>
<div class="px-4 py-3 overflow-y-auto max-h-[calc(100vh-12rem)]">
    <?php if ($message): ?>
    <div class="mb-2 rounded-md border border-green-200 bg-green-50 px-3 py-1.5 text-sm leading-snug text-green-600">
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <?php if ($error): ?>
    <div class="mb-2 rounded-md border border-red-200 bg-red-50 px-3 py-1.5 text-sm leading-snug text-red-600">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
    <?php endif; ?>

    <p class="mb-4 text-sm text-gray-500">
        设置全站默认字体。可使用字体服务 CSS 链接（如 Google Fonts）、直链字体文件 URL，或上传本地字体文件。字体 URL 与上传只能启用一种。
    </p>

    <form method="POST" enctype="multipart/form-data" class="max-w-xl space-y-4" id="fontGlobalForm">
        <input type="hidden" name="panel" value="font_global">
        <input type="hidden" name="font_file" id="font_file_hidden" value="<?= htmlspecialchars($fontConfig['font_file'], ENT_QUOTES, 'UTF-8') ?>">

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4 space-y-3">
            <p class="text-sm font-medium text-gray-900">字体来源</p>
            <div class="flex flex-wrap gap-4 text-sm">
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="font_mode" value="default" class="text-blue-600" <?= $mode === 'default' ? 'checked' : '' ?>>
                    <span>系统默认</span>
                </label>
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="font_mode" value="url" class="text-blue-600" <?= $mode === 'url' ? 'checked' : '' ?>>
                    <span>字体 URL</span>
                </label>
                <label class="inline-flex items-center gap-2 cursor-pointer">
                    <input type="radio" name="font_mode" value="upload" class="text-blue-600" <?= $mode === 'upload' ? 'checked' : '' ?>>
                    <span>上传字体</span>
                </label>
            </div>
        </div>

        <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
            <label for="family_name" class="block text-sm font-medium text-gray-900">字体名称</label>
            <p class="mt-1 text-xs text-gray-500">填写 CSS <code class="text-xs bg-white px-1 rounded">font-family</code> 使用的名称，如 <span class="font-mono">Noto Sans SC</span></p>
            <input
                type="text"
                id="family_name"
                name="family_name"
                value="<?= htmlspecialchars($fontConfig['family_name'], ENT_QUOTES, 'UTF-8') ?>"
                class="mt-2 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm"
                placeholder="Noto Sans SC"
            >
        </div>

        <div id="fontUrlPanel" class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4 space-y-2 <?= $mode !== 'url' ? 'hidden' : '' ?>">
            <label for="font_url" class="block text-sm font-medium text-gray-900">字体 URL</label>
            <p class="text-xs text-gray-500">
                支持：Google Fonts 等 CSS 链接（<span class="font-mono text-[11px]">fonts.googleapis.com/...</span>），或直链字体文件（<span class="font-mono">.woff2</span> / <span class="font-mono">.woff</span> / <span class="font-mono">.ttf</span> / <span class="font-mono">.otf</span>）
            </p>
            <input
                type="url"
                id="font_url"
                name="font_url"
                value="<?= htmlspecialchars($fontConfig['font_url'], ENT_QUOTES, 'UTF-8') ?>"
                class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-mono"
                placeholder="https://fonts.googleapis.com/css2?family=..."
            >
        </div>

        <div id="fontUploadPanel" class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4 space-y-3 <?= $mode !== 'upload' ? 'hidden' : '' ?>">
            <p class="text-sm font-medium text-gray-900">上传字体文件</p>
            <p class="text-xs text-gray-500">支持 woff2、woff、ttf、otf，最大 15MB。重新上传将替换当前字体。</p>
            <input type="file" name="font_file_upload" accept=".woff2,.woff,.ttf,.otf,font/woff2,font/woff,font/ttf,font/otf" class="block w-full text-sm text-gray-600">
            <?php if ($fontConfig['font_file'] !== ''): ?>
            <p class="text-xs text-gray-500">
                当前文件：<span class="font-mono break-all"><?= htmlspecialchars($fontConfig['font_file'], ENT_QUOTES, 'UTF-8') ?></span>
                （<?= htmlspecialchars($fontConfig['font_format'], ENT_QUOTES, 'UTF-8') ?>）
            </p>
            <?php endif; ?>
        </div>

        <div id="fontExtraPanel" class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4 <?= $mode === 'default' ? 'hidden' : '' ?>">
            <label for="fallback" class="block text-sm font-medium text-gray-900">备用字体栈（可选）</label>
            <p class="mt-1 text-xs text-gray-500">自定义字体加载失败时的回退字体，逗号分隔</p>
            <input
                type="text"
                id="fallback"
                name="fallback"
                value="<?= htmlspecialchars($fontConfig['fallback'], ENT_QUOTES, 'UTF-8') ?>"
                class="mt-2 w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm font-mono"
            >
        </div>

        <div>
            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-medium text-white hover:bg-blue-700">
                保存字体设置
            </button>
        </div>
    </form>
</div>

<script>
(() => {
    const form = document.getElementById('fontGlobalForm');
    const urlPanel = document.getElementById('fontUrlPanel');
    const uploadPanel = document.getElementById('fontUploadPanel');
    const extraPanel = document.getElementById('fontExtraPanel');
    const fileHidden = document.getElementById('font_file_hidden');
    const STORAGE_KEY = 'phpy_font_global_form_v1';
    const savedOk = <?= !empty($message) ? 'true' : 'false' ?>;

    function readState() {
        const mode = form.querySelector('input[name="font_mode"]:checked')?.value || 'default';
        return {
            font_mode: mode,
            family_name: form.family_name?.value ?? '',
            font_url: form.font_url?.value ?? '',
            fallback: form.fallback?.value ?? '',
            font_file: fileHidden?.value ?? '',
        };
    }

    function applyState(state) {
        if (!state || typeof state !== 'object') return;
        if (state.font_mode) {
            const radio = form.querySelector('input[name="font_mode"][value="' + state.font_mode + '"]');
            if (radio) radio.checked = true;
        }
        if (form.family_name && state.family_name != null) form.family_name.value = state.family_name;
        if (form.font_url && state.font_url != null) form.font_url.value = state.font_url;
        if (form.fallback && state.fallback != null) form.fallback.value = state.fallback;
        if (fileHidden && state.font_file != null) fileHidden.value = state.font_file;
        syncPanels();
    }

    function persistDraft() {
        try {
            sessionStorage.setItem(STORAGE_KEY, JSON.stringify(readState()));
        } catch (e) { /* ignore */ }
    }

    function restoreDraft() {
        try {
            const raw = sessionStorage.getItem(STORAGE_KEY);
            if (!raw) return;
            applyState(JSON.parse(raw));
        } catch (e) { /* ignore */ }
    }

    function syncPanels() {
        const mode = form.querySelector('input[name="font_mode"]:checked')?.value || 'default';
        urlPanel.classList.toggle('hidden', mode !== 'url');
        uploadPanel.classList.toggle('hidden', mode !== 'upload');
        extraPanel.classList.toggle('hidden', mode === 'default');
    }

    if (savedOk) {
        try { sessionStorage.removeItem(STORAGE_KEY); } catch (e) { /* ignore */ }
    } else {
        restoreDraft();
    }

    syncPanels();

    form.querySelectorAll('input[name="font_mode"]').forEach(function (r) {
        r.addEventListener('change', function () {
            syncPanels();
            persistDraft();
        });
    });

    form.addEventListener('input', persistDraft);
    form.addEventListener('change', persistDraft);
    form.addEventListener('submit', persistDraft);
})();
</script>
