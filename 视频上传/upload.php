<?php
require_once __DIR__ . '/common.php';
uploadBackendRequireLogin();
$config = uploadBackendConfig();
$message = '';
$error = '';
$saved = null;
$requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
$accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));
$isAjaxUpload = $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    set_time_limit(0);
    if (!uploadBackendVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $error = '表单已过期，请刷新后重试';
    } else {
        $file = $_FILES['video_file'] ?? null;
        if (!is_array($file)) {
            $error = '请选择要上传的视频文件';
        } else {
            $result = uploadBackendSaveUploadedVideo($file, [
                'title' => $_POST['title'] ?? '',
                'description' => $_POST['description'] ?? '',
            ]);
            if (!empty($result['ok'])) {
                $message = '视频已保存到远程后端，可用返回的文件路径创建审核记录或测试转码。';
                $saved = $result;
            } else {
                $error = (string)($result['error'] ?? '上传失败');
            }
        }
    }

    if ($isAjaxUpload) {
        uploadBackendJson([
            'ok' => $saved !== null,
            'message' => $saved !== null ? $message : $error,
            'stored_filename' => $saved['stored_filename'] ?? '',
            'size_bytes' => $saved['size_bytes'] ?? 0,
        ], $saved !== null ? 200 : 400);
        exit;
    }
}

$inputClass = uploadBackendInputClass();
$codeClass = uploadBackendCodeClass();
$card = uploadBackendTwCard('p-6');

uploadBackendPageHead('上传视频 - 远程上传后端');
uploadBackendAdminNav('远程上传后端', [
    ['href' => 'dashboard.php', 'label' => '概览'],
    ['href' => 'upload.php', 'label' => '上传', 'active' => true],
    ['href' => 'originals.php', 'label' => '原始文件'],
]);
?>
<main class="mx-auto max-w-2xl px-4 py-8">
    <section class="<?= $card ?>">
        <h1 class="text-xl font-bold text-slate-900">上传视频</h1>
        <p class="mb-6 mt-1 text-sm text-slate-500">管理员可在这里手动上传 mp4，用于检查远程存储、目录权限和后续转码流程。</p>

        <div id="uploadSuccess" class="<?= uploadBackendTwAlertClass('success') ?><?= $message ? '' : ' hidden' ?> mb-4" role="status"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <div id="uploadError" class="<?= uploadBackendTwAlertClass('error') ?><?= $error ? '' : ' hidden' ?> mb-4" role="alert"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>

        <?php if ($saved): ?>
            <div class="mb-5 rounded-lg border border-slate-200 bg-slate-50 p-4 text-sm text-slate-700">
                <div>保存路径：<code class="<?= $codeClass ?>"><?= htmlspecialchars((string)$saved['stored_filename'], ENT_QUOTES, 'UTF-8') ?></code></div>
                <div class="mt-1">文件大小：<?= htmlspecialchars(uploadBackendFormatBytes((int)$saved['size_bytes']), ENT_QUOTES, 'UTF-8') ?></div>
            </div>
        <?php endif; ?>

        <form method="post" enctype="multipart/form-data" class="space-y-5" id="backendVideoUploadForm">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(uploadBackendCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
            <div>
                <label for="title" class="mb-1 block text-sm font-medium text-slate-700">视频名称</label>
                <input id="title" name="title" class="<?= $inputClass ?>" placeholder="请输入视频名称">
            </div>
            <div>
                <label for="description" class="mb-1 block text-sm font-medium text-slate-700">视频简介</label>
                <textarea id="description" name="description" rows="4" class="<?= $inputClass ?>" placeholder="请输入视频简介"></textarea>
            </div>
            <div>
                <label for="video_file" class="mb-1 block text-sm font-medium text-slate-700">选择视频</label>
                <input id="video_file" name="video_file" type="file" accept=".mp4,video/mp4" required class="<?= uploadBackendFileInputClass() ?>">
                <p class="mt-2 text-xs leading-relaxed text-slate-500">
                    mp4：<?= htmlspecialchars((string)$config['MP4_DIR'], ENT_QUOTES, 'UTF-8') ?> ·
                    m3u8：<?= htmlspecialchars((string)$config['M3U8_DIR'], ENT_QUOTES, 'UTF-8') ?> ·
                    最大 <?= htmlspecialchars(uploadBackendFormatBytes(uploadBackendMaxUploadBytes()), ENT_QUOTES, 'UTF-8') ?> ·
                    超过 <?= (int)ceil(BackendChunkUpload::CHUNK_THRESHOLD_BYTES / 1024 / 1024) ?> MB 按 <?= (int)ceil(BackendChunkUpload::CHUNK_SIZE_BYTES / 1024 / 1024) ?> MB 分片
                </p>
            </div>
            <?php uploadBackendProgressBlock('upload'); ?>
            <button type="submit" id="backendVideoUploadSubmit" class="<?= uploadBackendBtnPrimary('w-full py-2.5') ?>">上传视频</button>
        </form>
    </section>
</main>
<script src="assets/js/upload-ui.js"></script>
<script src="assets/chunk-upload.js"></script>
<script>
(function () {
    const form = document.getElementById('backendVideoUploadForm');
    const submitBtn = document.getElementById('backendVideoUploadSubmit');
    const successBox = document.getElementById('uploadSuccess');
    const errorBox = document.getElementById('uploadError');
    const ui = window.BackendUploadUI;
    const chunkApi = {
        initUrl: <?= json_encode(uploadBackendApiUrl('init.php'), JSON_UNESCAPED_UNICODE) ?>,
        chunkUrl: <?= json_encode(uploadBackendApiUrl('chunk.php'), JSON_UNESCAPED_UNICODE) ?>,
        finishUrl: <?= json_encode(uploadBackendApiUrl('finish.php'), JSON_UNESCAPED_UNICODE) ?>,
    };
    if (!form || !submitBtn || !ui || !window.XMLHttpRequest) return;

    function uploadSingle() {
        return new Promise(function (resolve, reject) {
            const xhr = new XMLHttpRequest();
            xhr.open('POST', form.action || window.location.href, true);
            xhr.setRequestHeader('X-Requested-With', 'XMLHttpRequest');
            xhr.setRequestHeader('Accept', 'application/json');
            xhr.upload.onprogress = function (event) {
                if (!event.lengthComputable) {
                    ui.progress('upload', 0, '正在上传...');
                    return;
                }
                ui.progress('upload', (event.loaded / event.total) * 100, '正在上传视频...');
            };
            xhr.onload = function () {
                let data = null;
                try { data = JSON.parse(xhr.responseText || '{}'); } catch (e) {}
                if (xhr.status >= 200 && xhr.status < 300 && data && data.ok) {
                    resolve(data);
                    return;
                }
                if (xhr.status === 401) {
                    reject('登录状态已过期，请重新登录后再上传');
                    return;
                }
                reject((data && (data.message || data.error)) || ('上传失败 HTTP ' + xhr.status));
            };
            xhr.onerror = function () {
                reject('网络错误或服务器网关超时，请稍后重试');
            };
            xhr.send(new FormData(form));
        });
    }

    form.addEventListener('submit', function (event) {
        event.preventDefault();
        const fileInput = document.getElementById('video_file');
        const file = fileInput && fileInput.files && fileInput.files[0] ? fileInput.files[0] : null;
        if (!file) {
            ui.show(errorBox, '请选择要上传的视频文件');
            return;
        }

        ui.show(successBox, '');
        ui.show(errorBox, '');
        ui.progress('upload', 0, '准备上传...');
        submitBtn.disabled = true;
        submitBtn.textContent = '上传中...';

        const useChunk = window.BackendChunkUploadClient && window.BackendChunkUploadClient.shouldUseChunk(file);
        const uploadPromise = useChunk
            ? window.BackendChunkUploadClient.upload({
                file: file,
                uploadToken: '',
                storedFilename: '',
                initUrl: chunkApi.initUrl,
                chunkUrl: chunkApi.chunkUrl,
                finishUrl: chunkApi.finishUrl,
                onProgress: function (p, t) { ui.progress('upload', p, t); },
            })
            : uploadSingle();

        uploadPromise
            .then(function (data) {
                ui.progress('upload', 100, '上传完成');
                let msg = data.message || '视频已保存到远程后端';
                if (data.stored_filename) {
                    msg += '，保存路径：' + data.stored_filename;
                }
                ui.show(successBox, msg);
                form.reset();
            })
            .catch(function (err) {
                ui.progress('upload', 100, '上传失败');
                ui.show(errorBox, String(err && err.message ? err.message : err));
            })
            .finally(function () {
                submitBtn.disabled = false;
                submitBtn.textContent = '上传视频';
            });
    });
})();
</script>
<?php uploadBackendPageFoot(); ?>
