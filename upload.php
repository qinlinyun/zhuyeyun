<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/upload_config.php';

requireLogin();
if (isAdmin()) {
    header('Location: admin/upload_manage.php?section=overview');
    exit;
}
$user = getCurrentUser();
$pdo = getDB();

if ($uploadReady = isUploadPhpReady($pdo)) {
    $forceDesktop = isset($_GET['desktop']) && (string)$_GET['desktop'] === '1';
    if (!$forceDesktop && isMobileUploadUserAgent() && resolveUploadMobilePageUrl($pdo) !== '') {
        header('Location: api/upload_mobile_go.php');
        exit;
    }
}

ensureUserVideoUploadsTable($pdo);
$uploadVideoConfig = getUploadVideoConfig($pdo);
$uploadFlashError = '';
if (!empty($_SESSION['upload_error_flash'])) {
    $uploadFlashError = (string)$_SESSION['upload_error_flash'];
    unset($_SESSION['upload_error_flash']);
}
$mobileDone = isset($_GET['mobile_done']) && (string)$_GET['mobile_done'] === '1';
$apiConfig = getUploadApiConfig($pdo);
$uploads = fetchUserVideoUploads($pdo, (int)$user['id']);
$statusLabels = uploadReviewStatusLabels();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>视频上传 - 竹叶云控平台</title>
<?php include __DIR__ . '/components/theme-head.php'; ?>
<?php include __DIR__ . '/components/theme-dynamic.php'; ?>
</head>
<body class="bg-gray-100 text-gray-900">
<nav class="bg-white/80 glass shadow-sm sticky top-0 z-50">
<div class="mx-auto max-w-screen-xl px-4 py-3 flex justify-between items-center">
<a href="index.php" class="text-sm text-gray-600 hover:text-gray-900">← 返回首页</a>
<?php include __DIR__ . '/components/theme-toggle.php'; ?>
</div>
</nav>

<main class="mx-auto max-w-screen-xl px-4 py-12">
<div class="mx-auto max-w-3xl rounded-lg bg-white p-8 shadow">
<div class="mb-6 text-center">
<h1 class="mb-2 text-lg font-semibold">视频上传</h1>
<p class="text-sm text-gray-500">下方区域为<strong>远程上传后端</strong>页面（内嵌），视频文件不经本网站服务器；主站仅保存标题与审核信息。</p>
</div>

<?php if (!$uploadReady): ?>
<div class="mb-4 rounded-lg bg-amber-50 px-4 py-3 text-sm text-amber-800">
    上传服务尚未配置，请联系管理员在「上传中心 → 转码后端」填写远程地址、上传域名与 API Token。
</div>
<?php endif; ?>

<?php if ($uploadFlashError !== ''): ?>
<div class="mb-4 rounded-lg bg-red-50 px-4 py-3 text-sm text-red-600"><?= htmlspecialchars($uploadFlashError, ENT_QUOTES, 'UTF-8') ?></div>
<?php endif; ?>
<div id="uploadSuccessMessage" class="hidden mb-4 rounded-lg bg-green-50 px-4 py-2 text-sm text-green-700"></div>
<div id="uploadErrorMessage" class="hidden mb-4 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-600"></div>

<form method="post" class="space-y-5" id="videoUploadForm"<?= $uploadReady ? '' : ' data-disabled="1"' ?>>
    <div>
        <label class="mb-2 block text-sm font-medium text-gray-700" for="video_title">视频名称</label>
        <input id="video_title" name="title" type="text" required class="w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2.5 text-sm outline-none focus:border-red-500 focus:ring-2 focus:ring-red-500/20" placeholder="请输入视频名称" <?= $uploadReady ? '' : 'disabled' ?>>
    </div>

    <div>
        <label class="mb-2 block text-sm font-medium text-gray-700" for="video_description">视频简介</label>
        <textarea id="video_description" name="description" rows="3" class="w-full rounded-lg border border-gray-300 bg-gray-50 px-4 py-2.5 text-sm outline-none focus:border-red-500 focus:ring-2 focus:ring-red-500/20" placeholder="请输入视频简介" <?= $uploadReady ? '' : 'disabled' ?>></textarea>
    </div>

    <?php if (!empty($uploadVideoConfig['traffic_enabled'])): ?>
    <div class="rounded-lg border border-amber-200 bg-amber-50 p-4">
        <label class="flex items-center gap-2 text-sm font-medium text-amber-900">
            <input type="checkbox" name="is_traffic" value="1" class="rounded border-amber-300" <?= $uploadReady ? '' : 'disabled' ?>>
            设为流量视频
        </label>
        <div class="mt-3">
            <label class="mb-1 block text-xs font-medium text-amber-900" for="traffic_cost">解锁消耗流量</label>
            <input id="traffic_cost" name="traffic_cost" type="number" min="0" value="0" class="w-full rounded border border-amber-200 bg-white px-3 py-2 text-sm" <?= $uploadReady ? '' : 'disabled' ?>>
        </div>
    </div>
    <?php endif; ?>

    <div>
        <div class="mb-2 flex items-center justify-between">
            <span class="text-sm font-medium text-gray-700">远程上传区域</span>
            <span class="text-xs text-gray-400">在下方选择 mp4 并上传</span>
        </div>
        <div class="overflow-hidden rounded-lg border border-gray-200 bg-gray-50">
            <iframe
                id="backendUploadFrame"
                title="远程视频上传"
                class="block w-full min-h-[320px] bg-white"
                style="height:380px;border:0;"
                src="about:blank"
                <?= $uploadReady ? '' : 'hidden' ?>
            ></iframe>
            <?php if (!$uploadReady): ?>
                <p class="px-4 py-8 text-center text-sm text-gray-400">上传区域未就绪</p>
            <?php endif; ?>
        </div>
    </div>

    <div id="uploadProgressWrap" class="hidden rounded-lg border border-gray-200 bg-gray-50 p-4">
        <div class="mb-2 flex items-center justify-between text-xs text-gray-500">
            <span id="uploadProgressText">正在准备...</span>
            <span id="uploadProgressPercent">0%</span>
        </div>
        <div class="h-2 overflow-hidden rounded-full bg-gray-200">
            <div id="uploadProgressBar" class="h-full w-0 rounded-full bg-red-600 transition-all duration-200"></div>
        </div>
    </div>

    <button type="button" id="videoUploadStart" class="w-full rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700 disabled:cursor-not-allowed disabled:opacity-60" <?= $uploadReady ? '' : 'disabled' ?>>
        打开上传区域
    </button>
    <p class="text-center text-xs text-gray-400">先填写名称与简介，再点击按钮加载远程上传页；在区域内完成上传后会自动提交审核</p>
    <p class="text-center text-xs text-gray-400 md:hidden"><a href="api/upload_mobile_go.php" class="text-red-600 underline">使用手机专用上传页</a></p>
</form>
</div>

<div class="mx-auto mt-6 max-w-3xl rounded-lg bg-white p-6 shadow">
    <h2 class="mb-4 text-base font-semibold">我的上传审核状态</h2>
    <?php if (empty($uploads)): ?>
        <p class="text-sm text-gray-500">暂无上传记录</p>
    <?php else: ?>
        <div class="space-y-3">
            <?php foreach ($uploads as $item): ?>
                <div class="rounded-lg border border-gray-200 p-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div class="font-medium"><?= htmlspecialchars($item['title'], ENT_QUOTES, 'UTF-8') ?></div>
                        <span class="rounded-full px-2 py-1 text-xs <?= uploadReviewStatusClass((string)$item['status']) ?>">
                            <?= htmlspecialchars($statusLabels[$item['status']] ?? '未知', ENT_QUOTES, 'UTF-8') ?>
                        </span>
                    </div>
                    <div class="mt-2 text-xs text-gray-500">
                        提交时间：<?= htmlspecialchars((string)$item['created_at'], ENT_QUOTES, 'UTF-8') ?>
                        <?php if (!empty($item['stored_filename'])): ?>
                            · 路径：<?= htmlspecialchars((string)$item['stored_filename'], ENT_QUOTES, 'UTF-8') ?>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>
</main>

<div id="uploadDoneModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4" role="dialog" aria-modal="true" aria-labelledby="uploadDoneModalTitle">
    <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl">
        <h3 id="uploadDoneModalTitle" class="mb-2 text-center text-lg font-semibold text-gray-900">提交成功</h3>
        <p class="mb-6 text-center text-sm leading-relaxed text-gray-600">视频已经上传，等待管理员审核后即可看见。</p>
        <button type="button" id="uploadDoneModalBtn" class="w-full rounded-lg bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700">我知道了</button>
    </div>
</div>

<script src="assets/js/embed-video-upload.js"></script>
<script>
(function () {
    function showUploadDoneModal(onClose) {
        const modal = document.getElementById('uploadDoneModal');
        const btn = document.getElementById('uploadDoneModalBtn');
        if (!modal || !btn) {
            if (typeof onClose === 'function') {
                onClose();
            }
            return;
        }
        modal.classList.remove('hidden');
        function closeModal() {
            modal.classList.add('hidden');
            btn.removeEventListener('click', closeModal);
            if (typeof onClose === 'function') {
                onClose();
            }
        }
        btn.addEventListener('click', closeModal);
    }

    window.showUploadDoneModal = showUploadDoneModal;

    <?php if ($mobileDone): ?>
    showUploadDoneModal();
    <?php endif; ?>
    const form = document.getElementById('videoUploadForm');
    if (!form || form.dataset.disabled === '1' || !window.EmbedVideoUpload) return;

    const startBtn = document.getElementById('videoUploadStart');
    const iframe = document.getElementById('backendUploadFrame');
    const progressWrap = document.getElementById('uploadProgressWrap');
    const progressBar = document.getElementById('uploadProgressBar');
    const progressText = document.getElementById('uploadProgressText');
    const progressPercent = document.getElementById('uploadProgressPercent');
    const successBox = document.getElementById('uploadSuccessMessage');
    const errorBox = document.getElementById('uploadErrorMessage');
    let waitingForRemote = false;

    function showBox(box, text) {
        if (!box) return;
        box.textContent = text || '';
        box.classList.toggle('hidden', !text);
    }

    function setProgress(percent, text) {
        const value = Math.max(0, Math.min(100, Math.round(percent)));
        if (progressWrap) progressWrap.classList.remove('hidden');
        if (progressBar) progressBar.style.width = value + '%';
        if (progressPercent) progressPercent.textContent = value + '%';
        if (progressText) progressText.textContent = text || '处理中...';
    }

    function readFormMeta() {
        const titleEl = form.querySelector('[name="title"]');
        if (!titleEl || !String(titleEl.value || '').trim()) {
            throw new Error('请填写视频名称');
        }
        return {
            title: titleEl.value || '',
            description: (form.querySelector('[name="description"]') || {}).value || '',
            is_traffic: !!(form.querySelector('[name="is_traffic"]') && form.querySelector('[name="is_traffic"]').checked),
            traffic_cost: (form.querySelector('[name="traffic_cost"]') || {}).value || '0',
        };
    }

    function isEmbedPostMessage(event) {
        try {
            if (iframe && iframe.contentWindow && event.source === iframe.contentWindow) {
                return true;
            }
            const src = iframe && iframe.src ? iframe.src : '';
            if (!src || src === 'about:blank') {
                return false;
            }
            const expected = new URL(src).origin;
            if (!event.origin || event.origin === 'null') {
                return true;
            }
            return event.origin === expected;
        } catch (e) {
            return false;
        }
    }

    startBtn.addEventListener('click', function () {
        showBox(successBox, '');
        showBox(errorBox, '');
        let meta;
        try {
            meta = readFormMeta();
        } catch (err) {
            showBox(errorBox, String(err.message || err));
            return;
        }

        startBtn.disabled = true;
        startBtn.textContent = '正在加载...';
        setProgress(0, '正在连接远程上传页...');
        waitingForRemote = true;

        EmbedVideoUpload.start({
            prepareUrl: 'api/upload_prepare.php',
            completeUrl: 'api/upload_complete.php',
            iframe: iframe,
            originalFilename: 'video.mp4',
            title: meta.title,
            description: meta.description,
            is_traffic: meta.is_traffic,
            traffic_cost: meta.traffic_cost,
            iframeTimeoutMs: 0,
            onProgress: function (percent, text) {
                if (waitingForRemote) {
                    setProgress(percent, text);
                }
            },
        }).then(function (data) {
            waitingForRemote = false;
            setProgress(100, '完成');
            showBox(successBox, data.message || '已提交审核');
            form.reset();
            if (iframe) iframe.src = 'about:blank';
            showUploadDoneModal(function () {
                window.location.reload();
            });
        }).catch(function (err) {
            waitingForRemote = false;
            setProgress(100, '失败');
            showBox(errorBox, String(err && err.message ? err.message : err));
        }).finally(function () {
            waitingForRemote = false;
            startBtn.disabled = false;
            startBtn.textContent = '打开上传区域';
        });
    });

    window.addEventListener('message', function (event) {
        if (!waitingForRemote || !event.data || event.data.type !== 'zhuyeyun-embed-upload') {
            return;
        }
        if (!isEmbedPostMessage(event)) {
            return;
        }
        if (typeof event.data.progress === 'number') {
            setProgress(event.data.progress, event.data.progress_text || '正在上传...');
            return;
        }
        if (event.data.ok === true) {
            startBtn.textContent = '远程上传完成，正在登记...';
            setProgress(85, '远程上传完成，正在登记审核...');
        } else if (event.data.ok === false) {
            showBox(errorBox, event.data.error || '远程上传失败');
        }
    });
})();
</script>
<?php include __DIR__ . '/components/theme-toggle-script.php'; ?>
</body>
</html>
