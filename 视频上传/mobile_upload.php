<?php

/**
 * 手机端独立上传页：主站携带 upload_token 跳转至此，填写信息后一次提交上传并登记审核
 */
require_once __DIR__ . '/common.php';

$uploadToken = trim((string)($_REQUEST['upload_token'] ?? ''));
$storedFilename = trim(str_replace('\\', '/', (string)($_REQUEST['stored_filename'] ?? '')), '/');
$returnUrl = trim((string)($_REQUEST['return_url'] ?? ''));
$userLabel = trim((string)($_REQUEST['user_label'] ?? ''));
$trafficEnabled = trim((string)($_REQUEST['traffic_enabled'] ?? '')) === '1';
$tokenPayload = $uploadToken !== '' ? uploadBackendParseUserUploadToken($uploadToken) : null;
$maxBytes = uploadBackendMaxUploadBytes();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(0);
    header('Content-Type: application/json; charset=utf-8');

    if ($tokenPayload === null) {
        uploadBackendJson(['ok' => false, 'error' => '上传令牌无效或已过期，请返回主站重新进入'], 403);
        exit;
    }

    $action = trim((string)($_REQUEST['action'] ?? $_POST['action'] ?? ''));

    if ($action === 'complete') {
        $raw = file_get_contents('php://input');
        $json = json_decode($raw ?: '', true);
        $data = is_array($json) ? $json : $_POST;

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            uploadBackendJson(['ok' => false, 'error' => '请填写视频名称'], 400);
            exit;
        }

        $postStored = trim(str_replace('\\', '/', (string)($data['stored_filename'] ?? $storedFilename)), '/');
        if ($postStored === '' || uploadBackendNormalizeRelativePath($postStored) === '') {
            uploadBackendJson(['ok' => false, 'error' => '缺少或无效的目标路径'], 400);
            exit;
        }

        $absolute = uploadBackendStoragePath($postStored);
        if (!is_file($absolute)) {
            uploadBackendJson(['ok' => false, 'error' => '未找到已上传的视频文件，请先完成上传'], 400);
            exit;
        }

        $register = uploadBackendRegisterReviewOnMainSite([
            'upload_token' => $uploadToken,
            'title' => $title,
            'description' => (string)($data['description'] ?? ''),
            'original_filename' => trim((string)($data['original_filename'] ?? 'video.mp4')),
            'stored_filename' => $postStored,
            'backend_file_id' => $postStored,
            'size_bytes' => (int)(@filesize($absolute) ?: 0),
            'is_traffic' => !empty($data['is_traffic']),
            'traffic_cost' => (string)($data['traffic_cost'] ?? '0'),
        ]);

        if (empty($register['ok'])) {
            uploadBackendJson([
                'ok' => false,
                'error' => (string)($register['error'] ?? $register['message'] ?? '提交审核失败'),
            ], 400);
            exit;
        }

        uploadBackendJson([
            'ok' => true,
            'message' => (string)($register['message'] ?? '视频已提交，等待管理员审核'),
            'return_url' => $returnUrl,
            'upload_id' => (int)($register['upload_id'] ?? 0),
        ]);
        exit;
    }

    $postStored = trim(str_replace('\\', '/', (string)($_POST['stored_filename'] ?? $storedFilename)), '/');
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
        $result['stored_filename'] = $postStored;
    }
    uploadBackendJson($result, empty($result['ok']) ? 400 : 200);
    exit;
}

$tokenError = '';
if ($uploadToken === '' || $storedFilename === '') {
    $tokenError = '缺少上传参数，请从主站「视频上传」页重新进入';
} elseif ($tokenPayload === null) {
    $tokenError = '上传令牌无效或已过期，请返回主站后重新打开上传页';
} elseif (uploadBackendNormalizeRelativePath($storedFilename) === '') {
    $tokenError = '目标保存路径无效';
}

$maxMb = $maxBytes > 0 ? (int)ceil($maxBytes / 1024 / 1024) : 0;
$chunkThresholdMb = (int)ceil(BackendChunkUpload::CHUNK_THRESHOLD_BYTES / 1024 / 1024);
$chunkSizeMb = (int)ceil(BackendChunkUpload::CHUNK_SIZE_BYTES / 1024 / 1024);
$chunkInitUrl = uploadBackendApiUrl('init.php');
$chunkUploadUrl = uploadBackendApiUrl('chunk.php');
$chunkFinishUrl = uploadBackendApiUrl('finish.php');
$mainSiteUrl = rtrim((string)(BackendConfig::get()['MAIN_SITE_URL'] ?? ''), '/');
$inputClass = uploadBackendInputClass();
$card = uploadBackendTwCard('p-4');
$codeClass = uploadBackendCodeClass();

uploadBackendPageHead('手机上传视频', [
    'minimal' => true,
    'viewport' => 'width=device-width, initial-scale=1.0, viewport-fit=cover',
    'body_class' => 'min-h-screen bg-gradient-to-b from-slate-50 to-slate-100 pb-6 text-slate-900 antialiased',
]);
?>
<header class="sticky top-0 z-10 border-b border-slate-200/80 bg-white/95 shadow-sm backdrop-blur-md">
    <div class="mx-auto flex max-w-lg items-center justify-between gap-2 px-4 py-3">
        <h1 class="text-base font-bold text-slate-900">上传视频</h1>
        <?php if ($returnUrl !== ''): ?>
        <a href="<?= htmlspecialchars($returnUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-xs font-medium text-blue-600 hover:text-blue-800">返回主站</a>
        <?php elseif ($mainSiteUrl !== ''): ?>
        <a href="<?= htmlspecialchars($mainSiteUrl, ENT_QUOTES, 'UTF-8') ?>" class="text-xs font-medium text-blue-600 hover:text-blue-800">返回主站</a>
        <?php endif; ?>
    </div>
    <?php if ($userLabel !== ''): ?>
    <p class="mx-auto max-w-lg px-4 pb-2 text-xs text-slate-500">当前账号：<?= htmlspecialchars($userLabel, ENT_QUOTES, 'UTF-8') ?></p>
    <?php endif; ?>
</header>

<main class="mx-auto max-w-lg px-4 py-5">
    <?php if ($tokenError !== ''): ?>
        <?php uploadBackendAlert('error', $tokenError); ?>
    <?php else: ?>
        <div id="mobileSuccess" class="<?= uploadBackendTwAlertClass('success') ?> mb-3 hidden" role="status"></div>
        <div id="mobileError" class="<?= uploadBackendTwAlertClass('error') ?> mb-3 hidden" role="alert"></div>

        <form id="mobileUploadForm" class="<?= $card ?> space-y-4">
            <input type="hidden" name="upload_token" value="<?= htmlspecialchars($uploadToken, ENT_QUOTES, 'UTF-8') ?>">
            <input type="hidden" name="stored_filename" value="<?= htmlspecialchars($storedFilename, ENT_QUOTES, 'UTF-8') ?>">

            <div>
                <label for="video_title" class="mb-1 block text-sm font-medium text-slate-700">视频名称 <span class="text-red-500">*</span></label>
                <input id="video_title" name="title" type="text" required maxlength="200" class="<?= $inputClass ?> py-2.5" placeholder="请输入视频名称">
            </div>

            <div>
                <label for="video_description" class="mb-1 block text-sm font-medium text-slate-700">视频简介</label>
                <textarea id="video_description" name="description" rows="3" class="<?= $inputClass ?> py-2.5" placeholder="选填"></textarea>
            </div>

            <?php if ($trafficEnabled): ?>
            <div class="rounded-lg border border-amber-200 bg-amber-50 p-3 ring-1 ring-amber-100">
                <label class="flex items-center gap-2 text-sm font-medium text-amber-900">
                    <input type="checkbox" name="is_traffic" value="1" class="h-4 w-4 rounded border-amber-300 text-amber-600 focus:ring-amber-500">
                    设为流量视频
                </label>
                <div class="mt-2">
                    <label for="traffic_cost" class="mb-1 block text-xs font-medium text-amber-900">解锁消耗流量</label>
                    <input id="traffic_cost" name="traffic_cost" type="number" min="0" value="0" class="<?= uploadBackendInputClass('border-amber-200') ?>">
                </div>
            </div>
            <?php endif; ?>

            <div>
                <label for="video_file" class="mb-1 block text-sm font-medium text-slate-700">选择 mp4 视频 <span class="text-red-500">*</span></label>
                <input id="video_file" name="video_file" type="file" accept=".mp4,video/mp4" required class="<?= uploadBackendFileInputClass() ?>">
                <?php if ($maxMb > 0): ?>
                    <p class="mt-2 text-xs text-slate-500">最大 <?= (int)$maxMb ?> MB；超过 <?= $chunkThresholdMb ?> MB 自动分片</p>
                <?php endif; ?>
            </div>

            <?php uploadBackendProgressBlock('mobile'); ?>

            <button type="submit" id="mobileSubmitBtn" class="<?= uploadBackendBtnPrimary('w-full py-3') ?>">
                上传并提交审核
            </button>
        </form>

        <p class="mt-3 text-center text-xs text-slate-400">
            保存路径：<code class="<?= $codeClass ?>"><?= htmlspecialchars($storedFilename, ENT_QUOTES, 'UTF-8') ?></code>
        </p>
    <?php endif; ?>
</main>

<div id="uploadDoneModal" class="hidden fixed inset-0 z-50 flex items-center justify-center bg-black/50 px-4" role="dialog" aria-modal="true" aria-labelledby="uploadDoneModalTitle">
    <div class="w-full max-w-sm rounded-xl bg-white p-6 shadow-xl ring-1 ring-slate-200/60">
        <h3 id="uploadDoneModalTitle" class="mb-2 text-center text-lg font-semibold text-slate-900">提交成功</h3>
        <p class="mb-6 text-center text-sm leading-relaxed text-slate-600">视频已经上传，等待管理员审核后即可看见。</p>
        <button type="button" id="uploadDoneModalBtn" class="<?= uploadBackendBtnPrimary('w-full py-2.5') ?>">我知道了</button>
    </div>
</div>

<?php if ($tokenError === ''): ?>
<script src="assets/js/upload-ui.js"></script>
<script src="assets/chunk-upload.js"></script>
<script>
(function () {
    const form = document.getElementById('mobileUploadForm');
    const submitBtn = document.getElementById('mobileSubmitBtn');
    const ui = window.BackendUploadUI;
    const uploadToken = <?= json_encode($uploadToken, JSON_UNESCAPED_UNICODE) ?>;
    const storedFilename = <?= json_encode($storedFilename, JSON_UNESCAPED_UNICODE) ?>;
    const returnUrl = <?= json_encode($returnUrl, JSON_UNESCAPED_UNICODE) ?>;
    const chunkApi = {
        initUrl: <?= json_encode($chunkInitUrl, JSON_UNESCAPED_UNICODE) ?>,
        chunkUrl: <?= json_encode($chunkUploadUrl, JSON_UNESCAPED_UNICODE) ?>,
        finishUrl: <?= json_encode($chunkFinishUrl, JSON_UNESCAPED_UNICODE) ?>,
    };

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
            ui.progress('mobile', percent, text);
        }
    }

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

    function uploadSingle(file) {
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            const fd = new FormData(form);
            xhr.open('POST', window.location.href.split('#')[0], true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.onprogress = function (ev) {
                if (!ev.lengthComputable) return;
                setProgress(Math.round((ev.loaded / ev.total) * 90), '正在上传视频...');
            };
            xhr.onload = function () {
                let data = null;
                try { data = JSON.parse(xhr.responseText || '{}'); } catch (e) {}
                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                    resolve(data);
                    return;
                }
                reject((data && (data.error || data.message)) || ('上传失败 HTTP ' + xhr.status));
            };
            xhr.onerror = function () { reject('网络错误'); };
            xhr.send(fd);
        });
    }

    function registerReview(fileName, sizeBytes) {
        const titleEl = document.getElementById('video_title');
        const descEl = document.getElementById('video_description');
        const trafficEl = form.querySelector('[name="is_traffic"]');
        const costEl = document.getElementById('traffic_cost');
        const completeUrl = (function () {
            const base = window.location.href.split('#')[0];
            return base + (base.indexOf('?') >= 0 ? '&' : '?') + 'action=complete';
        })();
        return fetch(completeUrl, {
            method: 'POST',
            headers: {
                'Content-Type': 'application/json',
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest',
            },
            body: JSON.stringify({
                upload_token: uploadToken,
                stored_filename: storedFilename,
                title: titleEl ? titleEl.value : '',
                description: descEl ? descEl.value : '',
                original_filename: fileName || 'video.mp4',
                size_bytes: sizeBytes || 0,
                is_traffic: !!(trafficEl && trafficEl.checked),
                traffic_cost: costEl ? costEl.value : '0',
            }),
        }).then(function (res) {
            return res.json().then(function (data) {
                if (res.ok && data && data.ok) {
                    return data;
                }
                throw (data && (data.error || data.message)) || ('提交失败 HTTP ' + res.status);
            });
        });
    }

    form.addEventListener('submit', function (e) {
        e.preventDefault();
        const fileInput = document.getElementById('video_file');
        const file = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
        const titleEl = document.getElementById('video_title');
        if (!titleEl || !String(titleEl.value || '').trim()) {
            show(document.getElementById('mobileError'), '请填写视频名称');
            return;
        }
        if (!file) {
            show(document.getElementById('mobileError'), '请选择 mp4 视频');
            return;
        }

        show(document.getElementById('mobileSuccess'), '');
        show(document.getElementById('mobileError'), '');
        submitBtn.disabled = true;
        submitBtn.textContent = '处理中...';
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
                onProgress: function (p, t) {
                    setProgress(Math.min(90, p), t || '正在上传...');
                },
            })
            : uploadSingle(file);

        uploadPromise
            .then(function (data) {
                setProgress(95, '正在提交审核...');
                submitBtn.textContent = '正在提交审核...';
                return registerReview(
                    file.name,
                    data.size_bytes || file.size || 0
                );
            })
            .then(function (data) {
                setProgress(100, '完成');
                show(document.getElementById('mobileSuccess'), data.message || '视频已提交，等待管理员审核');
                submitBtn.textContent = '提交成功';
                const back = data.return_url || returnUrl;
                showUploadDoneModal(function () {
                    if (back) {
                        window.location.href = back;
                    }
                });
            })
            .catch(function (err) {
                setProgress(100, '失败');
                show(document.getElementById('mobileError'), String(err && err.message ? err.message : err));
                submitBtn.disabled = false;
                submitBtn.textContent = '上传并提交审核';
            });
    });
})();
</script>
<?php endif; ?>
<?php uploadBackendPageFoot(); ?>
