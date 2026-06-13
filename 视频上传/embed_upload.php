<?php

/**
 * 供主站 iframe 内嵌的用户上传页（令牌鉴权，无需登录后台）
 */
require_once __DIR__ . '/common.php';

uploadBackendSendEmbedFrameHeaders();

$uploadToken = trim((string)($_REQUEST['upload_token'] ?? ''));
$storedFilename = trim(str_replace('\\', '/', (string)($_REQUEST['stored_filename'] ?? '')), '/');
$parentOrigin = trim((string)($_REQUEST['parent_origin'] ?? ''));
$tokenPayload = $uploadToken !== '' ? uploadBackendParseUserUploadToken($uploadToken) : null;
$maxBytes = uploadBackendMaxUploadBytes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(0);
    header('Content-Type: application/json; charset=utf-8');

    if ($tokenPayload === null) {
        uploadBackendJson(['ok' => false, 'error' => '上传令牌无效或已过期'], 403);
        exit;
    }

    $postStored = trim(str_replace('\\', '/', (string)($_POST['stored_filename'] ?? '')), '/');
    if ($postStored === '' || uploadBackendNormalizeRelativePath($postStored) === '') {
        uploadBackendJson(['ok' => false, 'error' => '缺少或无效的目标路径'], 400);
        exit;
    }

    $file = $_FILES['video_file'] ?? null;
    if (!is_array($file)) {
        uploadBackendJson(['ok' => false, 'error' => '未收到视频文件'], 400);
        exit;
    }

    $result = uploadBackendSaveUploadedVideo($file, [
        'user_id' => (int)($tokenPayload['uid'] ?? 0),
        'target_relative' => $postStored,
    ]);

    if (!empty($result['ok'])) {
        $result['upload_token'] = $uploadToken;
        $result['parent_origin'] = $parentOrigin;
    }

    uploadBackendJson($result, empty($result['ok']) ? 400 : 200);
    exit;
}

$tokenError = '';
if ($uploadToken === '' || $storedFilename === '') {
    $tokenError = '缺少上传参数，请从主站上传页重新打开';
} elseif ($tokenPayload === null) {
    $tokenError = '上传令牌无效或已过期，请返回主站刷新后重试';
} elseif (uploadBackendNormalizeRelativePath($storedFilename) === '') {
    $tokenError = '目标保存路径无效';
}

$maxMb = $maxBytes > 0 ? (int)ceil($maxBytes / 1024 / 1024) : 0;
$chunkThresholdMb = (int)ceil(BackendChunkUpload::CHUNK_THRESHOLD_BYTES / 1024 / 1024);
$chunkSizeMb = (int)ceil(BackendChunkUpload::CHUNK_SIZE_BYTES / 1024 / 1024);
$chunkInitUrl = uploadBackendApiUrl('init.php');
$chunkUploadUrl = uploadBackendApiUrl('chunk.php');
$chunkFinishUrl = uploadBackendApiUrl('finish.php');
$card = uploadBackendTwCard('p-4');
$codeClass = uploadBackendCodeClass();

uploadBackendPageHead('上传视频', ['minimal' => true]);
?>
<main class="mx-auto max-w-xl px-4 py-5">
    <?php if ($tokenError !== ''): ?>
        <?php uploadBackendAlert('error', $tokenError); ?>
    <?php else: ?>
        <div id="embedSuccess" class="<?= uploadBackendTwAlertClass('success') ?> mb-3 hidden" role="status"></div>
        <div id="embedError" class="<?= uploadBackendTwAlertClass('error') ?> mb-3 hidden" role="alert"></div>
        <form id="embedUploadForm" class="<?= $card ?> space-y-4" enctype="multipart/form-data">
            <input type="hidden" name="upload_token" value="<?= htmlspecialchars($uploadToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="stored_filename" value="<?= htmlspecialchars($storedFilename, ENT_QUOTES, 'UTF-8') ?>">
            <?php if ($parentOrigin !== ''): ?>
            <input type="hidden" name="parent_origin" value="<?= htmlspecialchars($parentOrigin, ENT_QUOTES, 'UTF-8') ?>">
            <?php endif; ?>
            <div>
                <label for="video_file" class="mb-1 block text-sm font-medium text-slate-700">选择 mp4 视频</label>
                <input id="video_file" name="video_file" type="file" accept=".mp4,video/mp4" required class="<?= uploadBackendFileInputClass() ?>">
                <?php if ($maxMb > 0): ?>
                    <p class="mt-2 text-xs text-slate-500">单文件最大 <?= (int)$maxMb ?> MB；超过 <?= $chunkThresholdMb ?> MB 自动按 <?= $chunkSizeMb ?> MB 分片上传</p>
                <?php endif; ?>
            </div>
            <?php uploadBackendProgressBlock('embed'); ?>
            <button type="submit" id="embedSubmitBtn" class="<?= uploadBackendBtnPrimary('w-full py-2.5') ?>">上传到远程服务器</button>
        </form>
        <p class="mt-3 text-center text-xs text-slate-400">保存路径：<code class="<?= $codeClass ?>"><?= htmlspecialchars($storedFilename, ENT_QUOTES, 'UTF-8') ?></code></p>
    <?php endif; ?>
</main>
<?php if ($tokenError === ''): ?>
<script src="assets/js/upload-ui.js"></script>
<script src="assets/chunk-upload.js"></script>
<script>
(function () {
    const form = document.getElementById('embedUploadForm');
    const submitBtn = document.getElementById('embedSubmitBtn');
    const ui = window.BackendUploadUI;
    const parentOrigin = (function () {
        try {
            const fromQuery = new URLSearchParams(window.location.search).get('parent_origin') || '';
            if (fromQuery) {
                return fromQuery;
            }
        } catch (e) {}
        return <?= json_encode($parentOrigin, JSON_UNESCAPED_UNICODE) ?>;
    })();
    const uploadToken = <?= json_encode($uploadToken, JSON_UNESCAPED_UNICODE) ?>;
    const storedFilename = <?= json_encode($storedFilename, JSON_UNESCAPED_UNICODE) ?>;
    const chunkApi = {
        initUrl: <?= json_encode($chunkInitUrl, JSON_UNESCAPED_UNICODE) ?>,
        chunkUrl: <?= json_encode($chunkUploadUrl, JSON_UNESCAPED_UNICODE) ?>,
        finishUrl: <?= json_encode($chunkFinishUrl, JSON_UNESCAPED_UNICODE) ?>,
    };

    function postToParent(payload) {
        if (!window.parent || window.parent === window) {
            return;
        }
        const msg = Object.assign({ type: 'zhuyeyun-embed-upload' }, payload);
        try {
            window.parent.postMessage(msg, '*');
        } catch (e) {}
        if (parentOrigin) {
            try {
                window.parent.postMessage(msg, parentOrigin);
            } catch (e2) {}
        }
    }

    function isMobileDevice() {
        return /Android|webOS|iPhone|iPad|iPod|Mobile/i.test(navigator.userAgent || '');
    }

    function postSuccessToParent(data, fileName) {
        const body = {
            ok: true,
            upload_token: uploadToken,
            stored_filename: data.stored_filename || storedFilename,
            backend_file_id: data.backend_file_id || data.stored_filename || storedFilename,
            size_bytes: data.size_bytes || 0,
            original_filename: fileName || '',
            chunked: !!data.chunked,
        };
        postToParent(body);
        const delays = isMobileDevice() ? [300, 800, 1500, 2500, 4000] : [300];
        delays.forEach(function (ms) {
            setTimeout(function () {
                postToParent(body);
            }, ms);
        });
    }

    function show(el, text) {
        if (ui) {
            ui.show(el, text);
            return;
        }
        if (!el) return;
        el.textContent = text || '';
        el.classList.toggle('hidden', !text);
    }

    function setProgress(percent, text) {
        if (ui) {
            ui.progress('embed', percent, text);
        }
        postToParent({ pending: true, progress: Math.round(percent), progress_text: text || '' });
    }

    function notifySuccess(data, fileName) {
        setProgress(100, '上传完成');
        show(document.getElementById('embedSuccess'), isMobileDevice()
            ? '视频已上传到远程，请返回主站点击「提交审核」'
            : '视频已上传，正在通知主站…');
        postSuccessToParent(data, fileName);
    }

    function uploadSingle(file) {
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', window.location.href.split('#')[0], true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.onprogress = function (ev) {
                if (!ev.lengthComputable) return;
                setProgress(Math.round((ev.loaded / ev.total) * 100), '正在上传...');
            };
            xhr.onload = function () {
                let data = null;
                try { data = JSON.parse(xhr.responseText || '{}'); } catch (err) {}
                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                    resolve(data);
                    return;
                }
                reject((data && (data.error || data.message)) || ('上传失败 HTTP ' + xhr.status));
            };
            xhr.onerror = function () {
                reject('网络错误，请检查上传域名与服务器限制');
            };
            xhr.send(new FormData(form));
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const fileInput = document.getElementById('video_file');
        const file = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
        if (!file) {
            show(document.getElementById('embedError'), '请选择 mp4 视频');
            return;
        }

        show(document.getElementById('embedSuccess'), '');
        show(document.getElementById('embedError'), '');
        submitBtn.disabled = true;
        submitBtn.textContent = '上传中...';
        setProgress(0, '准备上传...');

        const useChunk = window.BackendChunkUploadClient
            && window.BackendChunkUploadClient.shouldUseChunk(file);

        const uploadPromise = useChunk
            ? window.BackendChunkUploadClient.upload({
                file: file,
                uploadToken: uploadToken,
                storedFilename: storedFilename,
                initUrl: chunkApi.initUrl,
                chunkUrl: chunkApi.chunkUrl,
                finishUrl: chunkApi.finishUrl,
                onProgress: setProgress,
            })
            : uploadSingle(file);

        uploadPromise
            .then(function (data) {
                notifySuccess(data, file.name);
            })
            .catch(function (err) {
                const errMsg = String(err && err.message ? err.message : err);
                setProgress(100, '上传失败');
                show(document.getElementById('embedError'), errMsg);
                postToParent({ ok: false, error: errMsg });
            })
            .finally(function () {
                submitBtn.disabled = false;
                submitBtn.textContent = '上传到远程服务器';
            });
    });
})();
</script>
<?php endif; ?>
<?php uploadBackendPageFoot(); ?>
