<?php
require_once __DIR__ . '/common.php';

$config = uploadBackendConfig();
$admin = $_SESSION['upload_backend_admin'] ?? [];
$message = '';
$testResults = [];
$envResults = [];

function uploadBackendMaskSecret(string $value): string
{
    $value = trim($value);
    if ($value === '') {
        return '未配置';
    }
    if (function_exists('mb_strlen') && mb_strlen($value, 'UTF-8') <= 8) {
        return str_repeat('*', mb_strlen($value, 'UTF-8'));
    }
    if (!function_exists('mb_strlen') && strlen($value) <= 8) {
        return str_repeat('*', strlen($value));
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, 4, 'UTF-8') . '****' . mb_substr($value, -4, null, 'UTF-8');
    }

    return substr($value, 0, 4) . '****' . substr($value, -4);
}

function uploadBackendConfigValue(array $config, string $key): string
{
    return trim((string)($config[$key] ?? ''));
}

function uploadBackendCheckStatus(bool $ok): string
{
    return $ok ? '已配置' : '待配置';
}

function uploadBackendCurrentRootUrl(): string
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    $path = rtrim(dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')), '/\\');
    if ($host === '') {
        return '';
    }

    return $scheme . '://' . $host . ($path !== '' ? $path : '');
}

function uploadBackendResolvedUploadRoot(array $config): string
{
    $root = uploadBackendConfigValue($config, 'UPLOAD_DOMAIN');
    if ($root === '') {
        $root = uploadBackendCurrentRootUrl();
    }
    if ($root !== '' && !preg_match('#^https?://#i', $root)) {
        $root = 'https://' . ltrim($root, '/');
    }

    return rtrim($root, '/');
}

function uploadBackendRunEndpointCheck(string $name, string $url, array $payload, array $expectedMessages = []): array
{
    $result = uploadBackendPostJson($url, $payload);
    if (!is_array($result)) {
        return ['name' => $name, 'ok' => false, 'message' => '检测失败'];
    }

    $message = (string)($result['message'] ?? $result['error'] ?? '主站已返回 JSON');
    foreach ($expectedMessages as $expectedMessage) {
        if ($expectedMessage !== '' && str_contains($message, $expectedMessage)) {
            return [
                'name' => $name,
                'ok' => true,
                'message' => '接口可达，已返回 JSON（' . $message . '，属于检测请求的正常提示）',
            ];
        }
    }

    return [
        'name' => $name,
        'ok' => !str_contains($message, '返回格式错误') && !str_contains($message, '无法连接主站 API'),
        'message' => $message,
    ];
}

function uploadBackendProbeUploadApi(string $url): array
{
    if (!function_exists('curl_init')) {
        return ['ok' => false, 'message' => '未启用 curl 扩展，无法检测上传接口'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST => true,
        CURLOPT_POSTFIELDS => ['api_token' => 'invalid_token'],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 8,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_HTTPHEADER => ['Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $error = curl_error($ch);
    $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
    curl_close($ch);
    if ($body === false) {
        return ['ok' => false, 'message' => '上传接口不可达：' . $error];
    }
    $json = json_decode((string)$body, true);
    if (!is_array($json)) {
        return ['ok' => false, 'message' => '上传接口返回非 JSON（HTTP ' . $status . '）'];
    }
    $msg = (string)($json['error'] ?? $json['message'] ?? '已返回 JSON');
    $ok = in_array($status, [400, 403], true);

    return ['ok' => $ok, 'message' => 'HTTP ' . $status . '，' . $msg];
}

function uploadBackendEnvironmentChecks(array $config): array
{
    $checks = [];
    $maxBytes = uploadBackendMaxUploadBytes();
    $postMax = uploadBackendPostMaxBytes();
    $uploadMax = uploadBackendUploadMaxFilesizeBytes();
    $effective = min($maxBytes, $postMax > 0 ? $postMax : $maxBytes, $uploadMax > 0 ? $uploadMax : $maxBytes);
    $checks[] = [
        'name' => '上传大小限制',
        'ok' => $effective >= $maxBytes,
        'message' => '应用上限 ' . uploadBackendFormatBytes($maxBytes)
            . '，post_max_size ' . ((string)ini_get('post_max_size') ?: '未设置')
            . '，upload_max_filesize ' . ((string)ini_get('upload_max_filesize') ?: '未设置'),
    ];

    $storageRoot = uploadBackendStorageRoot();
    $storageOk = uploadBackendEnsureDir($storageRoot);
    $tmpFile = $storageRoot . DIRECTORY_SEPARATOR . '.healthcheck_' . bin2hex(random_bytes(4)) . '.tmp';
    $writeOk = $storageOk && @file_put_contents($tmpFile, 'ok') !== false;
    if ($writeOk) {
        @unlink($tmpFile);
    }
    $checks[] = ['name' => 'storage 目录写权限', 'ok' => $writeOk, 'message' => $writeOk ? '可写：' . $storageRoot : '不可写：' . $storageRoot];

    $free = @disk_free_space($storageRoot);
    $checks[] = ['name' => '磁盘可用空间', 'ok' => is_numeric($free) && $free > 1024 * 1024 * 1024, 'message' => is_numeric($free) ? uploadBackendFormatBytes((int)$free) : '无法读取磁盘空间'];

    $ffmpeg = uploadBackendConfigValue($config, 'FFMPEG_PATH');
    $ffmpegOk = uploadBackendBinaryAvailable($ffmpeg);
    $checks[] = ['name' => 'FFmpeg 可用性', 'ok' => $ffmpegOk, 'message' => $ffmpegOk ? '正常：' . $ffmpeg : '不可用：' . ($ffmpeg !== '' ? $ffmpeg : '未配置')];

    $ffprobe = uploadBackendConfigValue($config, 'FFPROBE_PATH');
    $ffprobeOk = uploadBackendBinaryAvailable($ffprobe);
    $checks[] = ['name' => 'FFprobe 可用性', 'ok' => $ffprobeOk, 'message' => $ffprobeOk ? '正常：' . $ffprobe : '不可用：' . ($ffprobe !== '' ? $ffprobe : '未配置')];

    $embedUrl = uploadBackendResolveEmbedPageUrl();
    $checks[] = [
        'name' => '内嵌上传页',
        'ok' => $embedUrl !== '',
        'message' => $embedUrl !== '' ? $embedUrl : '未配置 UPLOAD_DOMAIN',
    ];

    $uploadEndpoint = uploadBackendResolvedUploadRoot($config) . '/api/upload_video.php';
    $apiProbe = uploadBackendProbeUploadApi($uploadEndpoint);
    $apiProbe['name'] = '上传接口连通性';
    $checks[] = $apiProbe;

    return $checks;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'check_api') {
    $mainSite = uploadBackendConfigValue($config, 'MAIN_SITE_URL');
    $token = uploadBackendConfigValue($config, 'API_TOKEN');
    if (!uploadBackendVerifyCsrf($_POST['csrf_token'] ?? null)) {
        $message = '表单已过期，请刷新后重试';
    } elseif ($mainSite === '') {
        $message = '请先配置 MAIN_SITE_URL';
    } elseif ($token === '') {
        $message = '请先配置 API_TOKEN';
    } else {
        $testResults[] = uploadBackendRunEndpointCheck('管理员登录接口', uploadBackendUrl('api/upload_admin_auth.php'), [
            'api_token' => $token,
            'username' => '',
            'password' => '',
        ], ['请输入用户名和密码']);
        $testResults[] = uploadBackendRunEndpointCheck('视频数据同步接口', uploadBackendUrl('api/video_data_sync.php'), [
            'record_id' => '',
            'title' => '',
            'm3u8_url' => '',
            'cover_url' => '',
            'exp' => time() + 300,
            'sign' => '',
        ], ['缺少 record_id']);
        $envResults = uploadBackendEnvironmentChecks($config);
    }
}

$requiredItems = [
    ['key' => 'MAIN_SITE_URL', 'label' => '主站地址', 'value' => uploadBackendConfigValue($config, 'MAIN_SITE_URL'), 'hint' => '例如 https://www.example.com，不要填写到 /api/ 路径。'],
    ['key' => 'API_TOKEN', 'label' => '上传后端 Token', 'value' => uploadBackendConfigValue($config, 'API_TOKEN'), 'secret' => true, 'hint' => '填写主站「上传管理 -> 上传后端配置 -> API配置」生成的 Token。'],
    ['key' => 'VIDEO_SYNC_SECRET', 'label' => '视频同步密钥', 'value' => uploadBackendConfigValue($config, 'VIDEO_SYNC_SECRET'), 'secret' => true, 'hint' => '需与主站「视频数据 API 同步」中的 API 密钥保持一致。'],
];

$optionalItems = [
    ['key' => 'UPLOAD_DOMAIN', 'label' => '内嵌上传域名', 'value' => uploadBackendConfigValue($config, 'UPLOAD_DOMAIN'), 'hint' => '主站 upload.php 用 iframe 加载本目录 embed_upload.php，例 https://upload.example.com/视频上传'],
    ['key' => 'VIDEO_DOMAIN', 'label' => '视频域名', 'value' => uploadBackendConfigValue($config, 'VIDEO_DOMAIN')],
    ['key' => 'IMAGE_DOMAIN', 'label' => '图片域名', 'value' => uploadBackendConfigValue($config, 'IMAGE_DOMAIN')],
    ['key' => 'MP4_DIR', 'label' => 'mp4 目录', 'value' => uploadBackendConfigValue($config, 'MP4_DIR')],
    ['key' => 'M3U8_DIR', 'label' => 'm3u8 目录', 'value' => uploadBackendConfigValue($config, 'M3U8_DIR')],
    ['key' => 'ORIGINALS_DIR', 'label' => '原始文件目录', 'value' => uploadBackendConfigValue($config, 'ORIGINALS_DIR')],
    ['key' => 'FFMPEG_PATH', 'label' => 'FFmpeg 路径', 'value' => uploadBackendConfigValue($config, 'FFMPEG_PATH')],
    ['key' => 'FFPROBE_PATH', 'label' => 'FFprobe 路径', 'value' => uploadBackendConfigValue($config, 'FFPROBE_PATH')],
    ['key' => 'VIDEO_SYNC_PATH_PREFIX', 'label' => '同步路径前缀（遗留）', 'value' => uploadBackendConfigValue($config, 'VIDEO_SYNC_PATH_PREFIX'), 'hint' => '审核通过写入 storage/m3u8/用户ID/10位目录/index.m3u8 与 screenshot.jpg，主站 path_prefix 建议 storage/。'],
];
$navLinks = uploadBackendIsLoggedIn()
    ? [
        ['href' => 'dashboard.php', 'label' => '概览'],
        ['href' => 'config_guide.php', 'label' => '配置引导', 'active' => true],
    ]
    : [];

$card = uploadBackendTwCard();
$codeClass = uploadBackendCodeClass();

uploadBackendPageHead('配置引导 - 远程上传后端');
if (uploadBackendIsLoggedIn()) {
    uploadBackendAdminNav('远程上传后端', $navLinks, (string)($admin['username'] ?? 'admin'));
} else {
    uploadBackendGuestNav('login.php', '返回登录', '配置引导');
}
?>
<main class="mx-auto max-w-screen-xl px-4 py-6">
    <section class="<?= uploadBackendTwAlertClass('info') ?> mb-6">
        <h1 class="mb-2 text-lg font-bold text-blue-950">远程上传后端配置引导</h1>
        <p class="leading-relaxed">请先编辑本目录下的 <code class="<?= $codeClass ?>">config.php</code>，再回到本页面检查配置。主站 API 返回格式错误时，通常是主站地址填错、接口被重定向、PHP 报错输出为 HTML，或主站未正确部署对应 API。</p>
    </section>

    <?php if ($message): uploadBackendAlert('error', $message); endif; ?>

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="<?= $card ?> lg:col-span-2">
            <h2 class="mb-4 text-base font-semibold">必填配置</h2>
            <div class="space-y-3">
                <?php foreach ($requiredItems as $item): ?>
                    <?php $ok = $item['value'] !== ''; ?>
                    <div class="rounded border border-slate-200 p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <div>
                                <div class="font-medium"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></div>
                                <div class="mt-1 text-xs text-slate-500"><?= htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8') ?></div>
                            </div>
                            <span class="rounded-full px-2 py-1 text-xs <?= $ok ? 'bg-green-50 text-green-700' : 'bg-red-50 text-red-600' ?>"><?= uploadBackendCheckStatus($ok) ?></span>
                        </div>
                        <p class="mt-3 break-all text-sm text-slate-700"><?= htmlspecialchars(!empty($item['secret']) ? uploadBackendMaskSecret($item['value']) : ($item['value'] ?: '未配置'), ENT_QUOTES, 'UTF-8') ?></p>
                        <p class="mt-2 text-xs text-slate-500"><?= htmlspecialchars($item['hint'], ENT_QUOTES, 'UTF-8') ?></p>
                    </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="<?= $card ?>">
            <h2 class="mb-3 text-base font-semibold text-slate-900">开箱自检</h2>
            <p class="mb-4 text-sm text-slate-500">会检测主站 API、上传接口连通性、PHP 上传限制、FFmpeg 与存储权限。</p>
            <form method="post">
                <input type="hidden" name="action" value="check_api">
                <input type="hidden" name="csrf_token" value="<?= htmlspecialchars(uploadBackendCsrfToken(), ENT_QUOTES, 'UTF-8') ?>">
                <button type="submit" class="<?= uploadBackendBtnPrimary('') ?> w-full py-2.5">运行开箱自检</button>
            </form>

            <?php if ($testResults): ?>
                <div class="mt-4 space-y-3">
                    <?php foreach ($testResults as $result): ?>
                        <div class="rounded border <?= $result['ok'] ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' ?> p-3 text-sm">
                            <div class="font-medium <?= $result['ok'] ? 'text-green-800' : 'text-red-700' ?>"><?= htmlspecialchars($result['name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="mt-1 break-all <?= $result['ok'] ? 'text-green-700' : 'text-red-600' ?>"><?= htmlspecialchars($result['message'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>

            <?php if ($envResults): ?>
                <div class="mt-4 space-y-3">
                    <?php foreach ($envResults as $result): ?>
                        <div class="rounded border <?= !empty($result['ok']) ? 'border-green-200 bg-green-50' : 'border-amber-200 bg-amber-50' ?> p-3 text-sm">
                            <div class="font-medium <?= !empty($result['ok']) ? 'text-green-800' : 'text-amber-700' ?>"><?= htmlspecialchars((string)$result['name'], ENT_QUOTES, 'UTF-8') ?></div>
                            <div class="mt-1 break-all <?= !empty($result['ok']) ? 'text-green-700' : 'text-amber-700' ?>"><?= htmlspecialchars((string)$result['message'], ENT_QUOTES, 'UTF-8') ?></div>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <section class="<?= $card ?> mt-4">
        <h2 class="mb-4 text-base font-semibold text-slate-900">其他配置</h2>
        <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($optionalItems as $item): ?>
                <div class="rounded border border-slate-200 p-3 text-sm">
                    <div class="text-xs text-slate-500"><?= htmlspecialchars($item['key'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="mt-1 font-medium"><?= htmlspecialchars($item['label'], ENT_QUOTES, 'UTF-8') ?></div>
                    <div class="mt-2 break-all text-slate-700"><?= htmlspecialchars($item['value'] !== '' ? $item['value'] : '未配置', ENT_QUOTES, 'UTF-8') ?></div>
                    <?php if (!empty($item['hint'])): ?>
                        <div class="mt-2 text-xs text-slate-500"><?= htmlspecialchars($item['hint'], ENT_QUOTES, 'UTF-8') ?></div>
                    <?php endif; ?>
                </div>
            <?php endforeach; ?>
        </div>
    </section>

    <section class="<?= $card ?> mt-4">
        <h2 class="mb-3 text-base font-semibold text-slate-900">常见故障排查</h2>
        <ol class="list-decimal space-y-2 pl-5 text-sm leading-relaxed text-slate-600">
            <li>确认 <code class="<?= $codeClass ?>">MAIN_SITE_URL</code> 只填写主站根地址，例如 <code class="<?= $codeClass ?>">https://www.example.com</code>。</li>
            <li>打开主站 <code class="<?= $codeClass ?>">/api/upload_admin_auth.php</code> 和 <code class="<?= $codeClass ?>">/api/video_data_sync.php</code>，应返回 JSON 或「请使用 POST」，不应返回 HTML 页面。</li>
            <li>如果检测结果里出现 HTML、登录页、404、Fatal error，请优先修复主站路径、伪静态、PHP 报错或数据库连接。</li>
            <li>确认 <code class="<?= $codeClass ?>">API_TOKEN</code> 与主站上传后端配置一致，<code class="<?= $codeClass ?>">VIDEO_SYNC_SECRET</code> 与主站视频数据 API 同步密钥一致。</li>
            <li>确认 <code class="<?= $codeClass ?>">VIDEO_SYNC_PATH_PREFIX</code> 与主站视频数据同步路径前缀一致，否则审核通过时可能出现签名校验失败。</li>
            <li>若浏览器报网络错误，优先检查上传地址、证书、端口与反向代理上传大小限制（如 Nginx <code class="<?= $codeClass ?>">client_max_body_size</code>）。</li>
        </ol>
    </section>
</main>
<?php uploadBackendPageFoot(); ?>
