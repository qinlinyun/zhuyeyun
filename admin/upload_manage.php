<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/upload_config.php';
require_once '../includes/upload_menu_helper.php';
require_once '../includes/upload_domain_group.php';

requireAdmin();

$pdo = getDB();
$message = '';
$error = '';

$menu = uploadMenuItems();
$nav = uploadMenuResolve((string)($_GET['section'] ?? 'overview'), (string)($_GET['item'] ?? ''));
$activeSection = $nav['section'];
$activeItem = $nav['item'];
$pageTitle = $nav['title'];
$pageDescription = $nav['description'];
$pendingCount = uploadMenuPendingCount($pdo);
$overviewStats = uploadMenuOverviewStats($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $panel = $_POST['panel'] ?? '';
    try {
        if ($panel === 'upload_php' || $panel === 'upload_mode' || $panel === 'upload_ftp') {
            saveUploadPhpConfig($pdo, $_POST);
            $message = 'PHP 上传配置已保存';
            $activeSection = 'infrastructure';
            $activeItem = 'php';
            $pageTitle = 'PHP 上传';
        } elseif ($panel === 'upload_backend_test' || $panel === 'upload_ftp_test') {
            $result = testUploadBackendConnection($pdo);
            if (!empty($result['ok'])) {
                $message = (string)($result['message'] ?? '远程后端连接成功');
            } else {
                $error = (string)($result['error'] ?? '远程后端连接失败');
            }
            $activeSection = 'infrastructure';
            $activeItem = 'php';
            $pageTitle = 'PHP 上传';
        } elseif ($panel === 'upload_api') {
            $data = $_POST;
            if (isset($_POST['generate_token'])) {
                $data['remote_api_token'] = generateUploadApiToken();
            }
            saveUploadApiConfig($pdo, $data);
            $message = 'API配置已保存';
            $activeSection = 'infrastructure';
            $activeItem = 'api';
            $pageTitle = '转码后端';
        } elseif ($panel === 'upload_review') {
            $uploadId = (int)($_POST['upload_id'] ?? 0);
            $action = (string)($_POST['review_action'] ?? '');
            $result = reviewUserVideoUpload($pdo, $uploadId, $action, (int)$_SESSION['user_id']);
            if (!empty($result['ok'])) {
                $message = (string)($result['message'] ?? '审核操作已执行');
            } else {
                $error = (string)($result['error'] ?? '审核操作失败');
            }
            $activeSection = 'content';
            $activeItem = 'review';
            $pageTitle = '待审核';
        } elseif ($panel === 'upload_review_delete') {
            $uploadId = (int)($_POST['upload_id'] ?? 0);
            $result = deleteManagedUploadedVideo($pdo, $uploadId, (int)$_SESSION['user_id']);
            if (!empty($result['ok'])) {
                $message = (string)($result['message'] ?? '审核记录已删除');
            } else {
                $error = (string)($result['error'] ?? '审核记录删除失败');
            }
            $activeSection = 'content';
            $activeItem = 'review';
            $pageTitle = '待审核';
        } elseif ($panel === 'upload_domain_assign') {
            $groupId = (int)($_POST['group_id'] ?? 0);
            $domainIds = $_POST['domain_ids'] ?? [];
            if ($groupId <= 0) {
                throw new InvalidArgumentException('请选择用户组');
            }

            $pdo->prepare('DELETE FROM group_domains WHERE group_id = ?')->execute([$groupId]);
            $allowedDomainIds = filterUploadPoolDomainIds($pdo, is_array($domainIds) ? $domainIds : []);
            if ($allowedDomainIds !== []) {
                $ins = $pdo->prepare('INSERT IGNORE INTO group_domains (group_id, domain_id) VALUES (?, ?)');
                foreach ($allowedDomainIds as $domainId) {
                    $ins->execute([$groupId, $domainId]);
                }
            }

            $message = '用户组域名分配已保存';
            $activeSection = 'settings';
            $activeItem = 'domains';
            $pageTitle = '域名分配';
        } elseif ($panel === 'uploaded_video_status') {
            $uploadId = (int)($_POST['upload_id'] ?? 0);
            $status = (string)($_POST['status'] ?? '');
            $result = updateManagedUploadedVideoStatus($pdo, $uploadId, $status, (int)$_SESSION['user_id']);
            if (!empty($result['ok'])) {
                $message = (string)($result['message'] ?? '状态已更新');
            } else {
                $error = (string)($result['error'] ?? '状态更新失败');
            }
            $activeSection = 'content';
            $activeItem = 'published';
            $pageTitle = '已发布管理';
        } elseif ($panel === 'uploaded_video_edit') {
            $uploadId = (int)($_POST['upload_id'] ?? 0);
            $result = editManagedUploadedVideo($pdo, $uploadId, $_POST);
            if (!empty($result['ok'])) {
                $message = (string)($result['message'] ?? '视频信息已更新');
            } else {
                $error = (string)($result['error'] ?? '视频信息更新失败');
            }
            $activeSection = 'content';
            $activeItem = 'published';
            $pageTitle = '已发布管理';
        } elseif ($panel === 'uploaded_video_delete') {
            $uploadId = (int)($_POST['upload_id'] ?? 0);
            $result = deleteManagedUploadedVideo($pdo, $uploadId, (int)$_SESSION['user_id']);
            if (!empty($result['ok'])) {
                $message = (string)($result['message'] ?? '视频已删除');
            } else {
                $error = (string)($result['error'] ?? '视频删除失败');
            }
            $activeSection = 'content';
            $activeItem = 'published';
            $pageTitle = '已发布管理';
        } elseif ($panel === 'upload_video_config') {
            saveUploadVideoConfig($pdo, [
                'traffic_enabled' => isset($_POST['traffic_enabled']),
                'encryption_enabled' => isset($_POST['encryption_enabled']),
            ]);
            $message = '视频配置已保存';
            $activeSection = 'settings';
            $activeItem = 'video';
            $pageTitle = '视频策略';
        }
    } catch (Throwable $e) {
        $error = '保存失败：' . $e->getMessage();
    }
    $pendingCount = uploadMenuPendingCount($pdo);
    $overviewStats = uploadMenuOverviewStats($pdo);
}

$phpConfig = getUploadPhpConfig($pdo);
$apiConfig = getUploadApiConfig($pdo);
$uploadVideoConfig = getUploadVideoConfig($pdo);
$reviewUploads = fetchUploadReviewList($pdo);
$managedUploadedVideos = fetchManagedUploadedVideos($pdo);
$statusLabels = uploadReviewStatusLabels();
$domainAssignAvailable = (bool)$pdo->query("SHOW TABLES LIKE 'domains'")->fetch()
    && (bool)$pdo->query("SHOW TABLES LIKE 'group_domains'")->fetch()
    && (bool)$pdo->query("SHOW TABLES LIKE 'user_groups'")->fetch();
$uploadGroups = [];
$uploadDomains = [];
$uploadGroupDomains = [];
$uploadDomainGroupConfigured = uploadDomainGroupFeatureEnabled($pdo) && getUploadDomainServerGroupId($pdo) !== null;
if ($domainAssignAvailable) {
    $uploadGroups = $pdo->query('SELECT id, name FROM user_groups ORDER BY id')->fetchAll() ?: [];
    $uploadDomains = $uploadDomainGroupConfigured ? fetchUploadPoolDomains($pdo) : [];
    $stmt = $pdo->query('SELECT group_id, domain_id FROM group_domains');
    while ($row = $stmt->fetch()) {
        $gid = (int)$row['group_id'];
        if (!isset($uploadGroupDomains[$gid])) {
            $uploadGroupDomains[$gid] = [];
        }
        $uploadGroupDomains[$gid][] = (int)$row['domain_id'];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?> - 上传管理 - 竹叶云控平台</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $themeAssetPrefix = '../'; include __DIR__ . '/../components/theme-head.php'; ?>

<?php include __DIR__ . '/../components/theme-dynamic.php'; ?>
</head>

<body class="bg-gray-100 text-gray-900">
<?php $adminNavActive = 'upload'; $adminNavShowThemeToggle = true; include __DIR__ . '/../components/admin-top-nav.php'; ?>

<main class="mx-auto max-w-screen-xl px-4 py-6">
    <div class="flex gap-4 items-start">
        <?php
        include __DIR__ . '/../components/admin-upload-sidebar.php';
        $healthChecks = ($activeSection === 'infrastructure' && $activeItem === 'php')
            ? uploadMenuHealthChecks($pdo)
            : [];
        $installerCmd = '';
        $installerWarn = '';
        $installToken = '';
        $installerBaseUrl = ((!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http') . '://' . (string)($_SERVER['HTTP_HOST'] ?? '');
        if ($activeSection === 'infrastructure' && $activeItem === 'api') {
            try {
                if (!empty($apiConfig['remote_api_token'])) {
                    $built = buildUploadBackendInstallerToken((int)$_SESSION['user_id'], (string)$apiConfig['remote_api_token'], 900);
                    $installToken = (string)($built['token'] ?? '');
                    if ($installToken !== '') {
                        $installerCmd = 'curl -fsSL ' . escapeshellarg($installerBaseUrl . '/api/upload_backend_installer.php?token=' . rawurlencode($installToken))
                            . " | tr -d '\\r' | bash -s -- --dir \"/www/wwwroot/upload-backend\"";
                    }
                } else {
                    $installerWarn = '请先保存 API Token 后再生成安装命令。';
                }
            } catch (Throwable $e) {
                $installerWarn = '生成安装命令失败：' . $e->getMessage();
            }
        }
        ?>

        <section class="min-w-0 flex-1">
            <div class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
                <div class="border-b border-gray-100 px-5 py-3">
                    <h1 class="text-base font-semibold text-gray-900"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                    <?php if (!empty($pageDescription)): ?>
                        <p class="mt-0.5 text-sm text-gray-500"><?= htmlspecialchars($pageDescription, ENT_QUOTES, 'UTF-8') ?></p>
                    <?php endif; ?>
                </div>
                <?php if ($message): ?>
                    <div class="mx-4 mt-4 rounded-lg bg-green-50 px-4 py-2 text-sm text-green-700"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>
                <?php if ($error): ?>
                    <div class="mx-4 mt-4 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-600"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
                <?php endif; ?>

                <?php if ($activeSection === 'overview'): ?>
                    <div class="p-6 space-y-6">
                        <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                            <div class="rounded-xl border border-gray-200 bg-slate-50 p-4">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">当前模式</p>
                                <p class="mt-2 text-lg font-semibold text-gray-900"><?= htmlspecialchars($overviewStats['mode_label'], ENT_QUOTES, 'UTF-8') ?></p>
                                <a href="<?= htmlspecialchars(uploadMenuUrl('infrastructure', 'php'), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 inline-block text-xs text-blue-600 hover:underline">PHP 上传配置</a>
                            </div>
                            <div class="rounded-xl border border-amber-200 bg-amber-50 p-4">
                                <p class="text-xs font-medium uppercase tracking-wide text-amber-800">待审核</p>
                                <p class="mt-2 text-2xl font-semibold text-amber-900"><?= (int)$overviewStats['pending'] ?></p>
                                <a href="<?= htmlspecialchars(uploadMenuUrl('content', 'review'), ENT_QUOTES, 'UTF-8') ?>" class="mt-2 inline-block text-xs text-amber-800 hover:underline">去审核</a>
                            </div>
                            <div class="rounded-xl border border-gray-200 bg-white p-4">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">已通过</p>
                                <p class="mt-2 text-2xl font-semibold text-green-700"><?= (int)$overviewStats['approved'] ?></p>
                            </div>
                            <div class="rounded-xl border border-gray-200 bg-white p-4">
                                <p class="text-xs font-medium uppercase tracking-wide text-gray-500">总上传</p>
                                <p class="mt-2 text-2xl font-semibold text-gray-900"><?= (int)$overviewStats['total'] ?></p>
                            </div>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-5">
                            <h2 class="text-sm font-semibold text-gray-900">直传上传流程</h2>
                            <ol class="mt-3 space-y-2 text-sm text-gray-600 list-decimal list-inside">
                                <li>用户在网站选择 mp4，浏览器<strong>直传</strong>远程「视频上传」后端（<code class="rounded bg-gray-100 px-1 text-xs">api/upload_video.php</code>），<strong>不经主站</strong>传文件</li>
                                <li>远程保存为 <code class="rounded bg-gray-100 px-1 text-xs"><?= htmlspecialchars((string)$overviewStats['user_subdir'], ENT_QUOTES, 'UTF-8') ?>/用户ID/10位目录/文件.mp4</code>，审核通过后 <code class="rounded bg-gray-100 px-1 text-xs">storage/m3u8/用户ID/10位目录/index.m3u8</code></li>
                                <li>写入审核队列，管理员审核</li>
                                <?php if (!empty($overviewStats['transcode_backend'])): ?>
                                    <li>审核通过 → 转码后端读取 mp4 → FFmpeg 切片 → 同步主站</li>
                                <?php endif; ?>
                            </ol>
                        </div>

                        <div class="rounded-xl border border-gray-200 p-5">
                            <h2 class="text-sm font-semibold text-gray-900">配置状态</h2>
                            <ul class="mt-3 divide-y divide-gray-100 text-sm">
                                <li class="flex justify-between py-2">
                                    <span class="text-gray-600">PHP 上传</span>
                                    <span class="<?= !empty($overviewStats['php_ready']) ? 'text-green-600' : 'text-red-600' ?> font-medium"><?= !empty($overviewStats['php_ready']) ? '已就绪' : '未配置后端' ?></span>
                                </li>
                                <li class="flex justify-between py-2">
                                    <span class="text-gray-600">转码后端</span>
                                    <span class="<?= !empty($overviewStats['transcode_backend']) ? 'text-green-600' : 'text-amber-600' ?> font-medium"><?= !empty($overviewStats['transcode_backend']) ? '已配置' : '未配置（仅审核）' ?></span>
                                </li>
                            </ul>
                        </div>
                    </div>
                <?php elseif ($activeSection === 'infrastructure' && $activeItem === 'php'): ?>
                    <div class="p-6 space-y-8">
                        <form method="post" class="space-y-6">
                            <input type="hidden" name="panel" value="upload_php">
                            <div>
                                <h2 class="text-base font-semibold">PHP 上传</h2>
                                <p class="mt-1 text-sm text-gray-500">主站 <code class="rounded bg-gray-100 px-1 text-xs">upload.php</code> 内嵌远程 <code class="rounded bg-gray-100 px-1 text-xs">embed_upload.php</code>，视频不经主站。请配置转码后端地址、API Token；「上传域名」与远程 <code class="rounded bg-gray-100 px-1 text-xs">UPLOAD_DOMAIN</code> 一致。</p>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">用户子目录名</label>
                                    <input name="user_subdir" value="<?= htmlspecialchars((string)$phpConfig['user_subdir'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
                                    <p class="mt-1 text-xs text-gray-500">保存路径：<code class="rounded bg-gray-100 px-1">{子目录}/{用户ID}/时间戳.mp4</code></p>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">单文件大小上限（MB）</label>
                                    <input name="max_upload_mb" type="number" min="1" value="<?= (int)($phpConfig['max_upload_mb'] ?? 2048) ?>" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
                                </div>
                            </div>

                            <div class="flex flex-wrap gap-2">
                                <button class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">保存配置</button>
                                <button type="submit" name="panel" value="upload_backend_test" class="rounded border border-gray-300 px-4 py-2 text-sm font-semibold text-gray-700 hover:bg-gray-50">测试远程后端</button>
                            </div>
                        </form>

                        <?php if ($healthChecks !== []): ?>
                        <div>
                            <h3 class="text-sm font-semibold text-gray-900">连接自检</h3>
                            <ul class="mt-3 space-y-2">
                                <?php foreach ($healthChecks as $check): ?>
                                    <li class="flex items-start gap-2 rounded-lg border px-3 py-2 text-sm <?= !empty($check['ok']) ? 'border-green-200 bg-green-50' : 'border-red-200 bg-red-50' ?>">
                                        <span><?= !empty($check['ok']) ? '✓' : '!' ?></span>
                                        <span><strong><?= htmlspecialchars((string)$check['label'], ENT_QUOTES, 'UTF-8') ?></strong> — <?= htmlspecialchars((string)$check['detail'], ENT_QUOTES, 'UTF-8') ?></span>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($activeSection === 'infrastructure' && $activeItem === 'api'): ?>
                    <div class="p-6">
                        <form method="post" class="space-y-6">
                            <input type="hidden" name="panel" value="upload_api">
                            <div>
                                <h2 class="text-base font-semibold">转码后端（可选）</h2>
                                <p class="mt-1 text-sm text-gray-500">审核通过后，主站通知「视频上传」后端对 mp4 切片并同步主站。远程 <code class="rounded bg-gray-100 px-1 text-xs">config.php</code> 中 <code class="rounded bg-gray-100 px-1 text-xs">API_TOKEN</code> 须与下方 Token 一致。</p>
                            </div>

                            <div class="grid gap-4 md:grid-cols-2">
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">远程后端地址</label>
                                    <input name="remote_backend_url" value="<?= htmlspecialchars($apiConfig['remote_backend_url'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://upload.example.com/视频上传（不要填写 login.php）" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">API Token</label>
                                    <div class="flex gap-2">
                                        <input name="remote_api_token" value="<?= htmlspecialchars($apiConfig['remote_api_token'], ENT_QUOTES, 'UTF-8') ?>" class="min-w-0 flex-1 rounded border border-gray-300 px-3 py-2 text-sm">
                                        <button name="generate_token" value="1" class="shrink-0 rounded border border-gray-300 px-3 py-2 text-sm hover:bg-gray-50">生成</button>
                                    </div>
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">内嵌上传域名</label>
                                    <input name="upload_domain" value="<?= htmlspecialchars($apiConfig['upload_domain'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://upload.example.com/视频上传（与远程 UPLOAD_DOMAIN 一致）" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">视频域名</label>
                                    <input name="video_domain" value="<?= htmlspecialchars($apiConfig['video_domain'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://video.example.com" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">图片域名</label>
                                    <input name="image_domain" value="<?= htmlspecialchars($apiConfig['image_domain'], ENT_QUOTES, 'UTF-8') ?>" placeholder="https://img.example.com" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">m3u8目录</label>
                                    <input name="m3u8_dir" value="<?= htmlspecialchars($apiConfig['m3u8_dir'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
                                </div>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">mp4目录</label>
                                    <input name="mp4_dir" value="<?= htmlspecialchars($apiConfig['mp4_dir'], ENT_QUOTES, 'UTF-8') ?>" class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
                                </div>
                            </div>

                            <button class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">保存配置</button>
                        </form>

                        <div class="mt-8 rounded-lg border border-gray-200 bg-gray-50 p-5">
                            <h3 class="text-sm font-semibold text-gray-900">转码后端一键安装</h3>
                            <p class="mt-1 text-xs text-gray-500">在转码服务器执行，仅同步 <code class="rounded bg-gray-100 px-1">config.php</code>，不会下载或覆盖其它程序文件。请先将「视频上传」代码部署到命令中的 <code class="rounded bg-gray-100 px-1">--dir</code> 目录。</p>
                            <?php if ($installerWarn): ?>
                                <div class="mt-3 rounded bg-amber-50 px-3 py-2 text-sm text-amber-800"><?= htmlspecialchars($installerWarn, ENT_QUOTES, 'UTF-8') ?></div>
                            <?php else: ?>
                                <textarea id="uploadBackendInstallerCmd" rows="3" class="mt-3 w-full rounded border border-gray-300 bg-white px-3 py-2 font-mono text-xs" readonly><?= htmlspecialchars($installerCmd, ENT_QUOTES, 'UTF-8') ?></textarea>
                                <div class="mt-2 flex gap-2">
                                    <button type="button" class="rounded bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white" onclick="copyInstallerCmd()">复制</button>
                                    <a class="rounded border border-gray-300 px-3 py-1.5 text-xs" href="<?= htmlspecialchars($installerBaseUrl . '/api/upload_backend_installer.php?token=' . rawurlencode($installToken), ENT_QUOTES, 'UTF-8') ?>" target="_blank" rel="noreferrer">预览</a>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                <?php elseif ($activeSection === 'settings' && $activeItem === 'domains'): ?>
                    <div class="p-6">
                        <div class="mb-4">
                            <h2 class="text-base font-semibold">域名分配</h2>
                            <p class="mt-1 text-sm text-gray-500">远程后端只回传域名后面的路径，播放和封面展示会按这里给用户组分配的域名拼接完整地址。仅可分配「域名管理 → 上传域名组」所选服务器组下的域名。</p>
                        </div>

                        <?php if (!$domainAssignAvailable): ?>
                            <div class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">域名或用户组数据表尚未初始化</div>
                        <?php elseif (!$uploadDomainGroupConfigured): ?>
                            <div class="rounded-lg border border-dashed border-amber-200 bg-amber-50 p-8 text-center text-sm text-amber-900">
                                请先在 <a href="domains.php?section=upload_domain_group" class="font-medium text-blue-700 underline hover:text-blue-800">域名管理 → 上传域名组</a> 中配置默认服务器组，并将上传/封面线路域名归入该组。
                            </div>
                        <?php elseif (empty($uploadGroups)): ?>
                            <div class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">请先创建用户组后再分配</div>
                        <?php elseif (empty($uploadDomains)): ?>
                            <div class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">
                                上传域名组内暂无域名，请在 <a href="domains.php?section=domains" class="text-blue-700 underline hover:text-blue-800">域名管理</a> 中添加域名并选择对应服务器组。
                            </div>
                        <?php else: ?>
                            <div class="space-y-4">
                                <?php foreach ($uploadGroups as $group): ?>
                                    <?php $gid = (int)$group['id']; $selectedDomains = $uploadGroupDomains[$gid] ?? []; ?>
                                    <form method="post" class="rounded-lg border border-gray-200 p-4">
                                        <input type="hidden" name="panel" value="upload_domain_assign">
                                        <input type="hidden" name="group_id" value="<?= $gid ?>">
                                        <div class="mb-3 flex flex-wrap items-center justify-between gap-2">
                                            <div>
                                                <div class="font-medium text-gray-900"><?= htmlspecialchars((string)$group['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                                <div class="text-xs text-gray-500">选择该用户组可使用的播放/封面域名</div>
                                            </div>
                                            <button class="rounded bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700">保存分配</button>
                                        </div>
                                        <div class="grid gap-2 md:grid-cols-2">
                                            <?php foreach ($uploadDomains as $domain): ?>
                                                <?php $did = (int)$domain['id']; ?>
                                                <label class="flex items-center gap-2 rounded border border-gray-100 px-3 py-2 text-sm hover:bg-gray-50">
                                                    <input type="checkbox" name="domain_ids[]" value="<?= $did ?>" <?= in_array($did, $selectedDomains, true) ? 'checked' : '' ?>>
                                                    <span class="min-w-0">
                                                        <span class="block truncate text-gray-900"><?= htmlspecialchars((string)$domain['domain'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        <?php if (!empty($domain['display_name'])): ?>
                                                            <span class="block truncate text-xs text-gray-500"><?= htmlspecialchars((string)$domain['display_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                        <?php endif; ?>
                                                    </span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($activeSection === 'content' && $activeItem === 'review'): ?>
                    <div class="p-6">
                        <div class="mb-4">
                            <h2 class="text-base font-semibold">待审核队列</h2>
                            <p class="mt-1 text-sm text-gray-500">用户经分片上传合并后的 mp4 记录。通过审核将触发远程后端转码并同步主站（远程模式）。</p>
                        </div>

                        <?php if (empty($reviewUploads)): ?>
                            <div class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">暂无上传审核记录</div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-[920px] table-fixed divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th class="w-[190px] px-3 py-2">视频</th>
                                            <th class="w-[90px] px-3 py-2">上传用户</th>
                                            <th class="w-[80px] px-3 py-2">状态</th>
                                            <th class="w-[160px] px-3 py-2">原始文件</th>
                                            <th class="w-[120px] px-3 py-2">提交时间</th>
                                            <th class="w-[210px] px-3 py-2 text-right">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($reviewUploads as $item): ?>
                                            <tr>
                                                <td class="px-3 py-3">
                                                    <div class="max-w-[170px] truncate font-medium text-gray-900" title="<?= htmlspecialchars((string)$item['title'], ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars((string)$item['title'], ENT_QUOTES, 'UTF-8') ?>
                                                    </div>
                                                    <?php if (!empty($item['description'])): ?>
                                                        <div class="mt-1 max-w-xs truncate text-xs text-gray-500"><?= htmlspecialchars((string)$item['description'], ENT_QUOTES, 'UTF-8') ?></div>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-3"><?= htmlspecialchars((string)($item['username'] ?? '未知'), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="px-3 py-3">
                                                    <span class="rounded-full px-2 py-1 text-xs <?= uploadReviewStatusClass((string)$item['status']) ?>">
                                                        <?= htmlspecialchars($statusLabels[$item['status']] ?? '未知', ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                </td>
                                                <td class="px-3 py-3 align-top text-xs text-gray-500">
                                                    <?php $originalFilename = trim((string)($item['original_filename'] ?? '')); ?>
                                                    <?php if ($originalFilename !== ''): ?>
                                                        <button
                                                            type="button"
                                                            class="upload-expand-toggle whitespace-nowrap text-left text-blue-600 hover:text-blue-800 hover:underline"
                                                            data-expand-label="查看文件名"
                                                            aria-expanded="false"
                                                        >查看文件名</button>
                                                        <div class="upload-expand-panel mt-1 hidden max-w-[220px] break-all text-gray-600"><?= htmlspecialchars($originalFilename, ENT_QUOTES, 'UTF-8') ?></div>
                                                    <?php else: ?>
                                                        <span class="text-gray-400">-</span>
                                                    <?php endif; ?>
                                                    <?php if (!empty($item['save_original'])): ?>
                                                        <span class="mt-1 block whitespace-nowrap text-green-600">已要求保存</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-3 text-xs text-gray-500"><?= htmlspecialchars((string)$item['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="px-3 py-3 align-top">
                                                    <div class="flex flex-wrap justify-end gap-2">
                                                        <form method="post">
                                                            <input type="hidden" name="panel" value="upload_review">
                                                            <input type="hidden" name="upload_id" value="<?= (int)$item['id'] ?>">
                                                            <input type="hidden" name="review_action" value="approve">
                                                            <button class="whitespace-nowrap rounded bg-green-600 px-3 py-1 text-xs font-semibold text-white hover:bg-green-700">通过审核</button>
                                                        </form>
                                                        <form method="post">
                                                            <input type="hidden" name="panel" value="upload_review">
                                                            <input type="hidden" name="upload_id" value="<?= (int)$item['id'] ?>">
                                                            <input type="hidden" name="review_action" value="reject">
                                                            <button class="whitespace-nowrap rounded bg-red-600 px-3 py-1 text-xs font-semibold text-white hover:bg-red-700">审核失败</button>
                                                        </form>
                                                        <form method="post">
                                                            <input type="hidden" name="panel" value="upload_review">
                                                            <input type="hidden" name="upload_id" value="<?= (int)$item['id'] ?>">
                                                            <input type="hidden" name="review_action" value="save_original">
                                                            <button class="whitespace-nowrap rounded border border-gray-300 px-3 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50">保存原始文件</button>
                                                        </form>
                                                        <button
                                                            type="button"
                                                            class="whitespace-nowrap rounded border border-red-300 px-3 py-1 text-xs font-semibold text-red-600 hover:bg-red-50"
                                                            onclick="openReviewDeleteModal(<?= (int)$item['id'] ?>, '<?= htmlspecialchars((string)$item['title'], ENT_QUOTES, 'UTF-8') ?>')"
                                                        >
                                                            删除记录
                                                        </button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php elseif ($activeSection === 'settings' && $activeItem === 'video'): ?>
                    <div class="p-6">
                        <form method="post" class="max-w-3xl space-y-5">
                            <input type="hidden" name="panel" value="upload_video_config">
                            <div>
                                <h2 class="text-base font-semibold">视频策略</h2>
                                <p class="mt-1 text-sm text-gray-500">控制用户上传时的流量视频选项，以及播放加密策略。</p>
                            </div>

                            <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">流量视频</p>
                                        <p class="mt-1 text-sm text-gray-500">开启后，用户上传视频时可设置为流量视频，并自定义解锁消耗额度。其他用户支付后会进入发布者收益流量。</p>
                                    </div>
                                    <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                                        <input type="checkbox" name="traffic_enabled" value="1" class="peer sr-only" <?= !empty($uploadVideoConfig['traffic_enabled']) ? 'checked' : '' ?>>
                                        <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-300"></span>
                                        <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                                    </label>
                                </div>
                                <p class="mt-3 text-xs text-gray-500">当前状态：<span class="font-medium <?= !empty($uploadVideoConfig['traffic_enabled']) ? 'text-green-600' : 'text-red-600' ?>"><?= !empty($uploadVideoConfig['traffic_enabled']) ? '已开启' : '已关闭' ?></span></p>
                            </div>

                            <div class="rounded-lg border border-gray-200 bg-gray-50 px-5 py-4">
                                <div class="flex items-start justify-between gap-4">
                                    <div>
                                        <p class="text-sm font-medium text-gray-900">启动加密</p>
                                        <p class="mt-1 text-sm text-gray-500">开启后，仅对用户上传的视频启用本站自签播放 Token；文件在上传 CDN，不会向切片后端申请链接。管理员发布的视频仍走 CDN 直链或切片后端代理，不受此项影响。</p>
                                    </div>
                                    <label class="relative inline-flex shrink-0 cursor-pointer items-center">
                                        <input type="checkbox" name="encryption_enabled" value="1" class="peer sr-only" <?= !empty($uploadVideoConfig['encryption_enabled']) ? 'checked' : '' ?>>
                                        <span class="h-6 w-11 rounded-full bg-gray-300 transition peer-checked:bg-blue-600 peer-focus:ring-2 peer-focus:ring-blue-300"></span>
                                        <span class="absolute left-0.5 top-0.5 h-5 w-5 rounded-full bg-white shadow transition peer-checked:translate-x-5"></span>
                                    </label>
                                </div>
                                <p class="mt-3 text-xs text-gray-500">当前状态：<span class="font-medium <?= !empty($uploadVideoConfig['encryption_enabled']) ? 'text-green-600' : 'text-red-600' ?>"><?= !empty($uploadVideoConfig['encryption_enabled']) ? '已开启' : '已关闭' ?></span></p>
                                <p class="mt-2 text-xs text-gray-500">与“其它设置 / 播放器管理 / 开启关闭后端代理”相互独立；后端代理仅用于管理员同步到切片后端的视频。用户上传视频始终跳过切片后端，由本站自签 Token 或 CDN 直链播放。</p>
                            </div>

                            <button class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">保存视频配置</button>
                        </form>
                    </div>
                <?php elseif ($activeSection === 'content' && $activeItem === 'published'): ?>
                    <div class="p-6">
                        <div class="mb-4">
                            <h2 class="text-base font-semibold">已发布管理</h2>
                            <p class="mt-1 text-sm text-gray-500">已审核视频与主站 <code class="rounded bg-gray-100 px-1 text-xs">videos</code> 记录。删除将同步清理远程后端媒体文件。</p>
                        </div>

                        <?php if (empty($managedUploadedVideos)): ?>
                            <div class="rounded-lg border border-dashed border-gray-300 p-8 text-center text-sm text-gray-500">暂无用户上传视频</div>
                        <?php else: ?>
                            <div class="overflow-x-auto">
                                <table class="min-w-[920px] table-fixed divide-y divide-gray-200 text-sm">
                                    <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th class="w-[220px] px-3 py-2">视频</th>
                                            <th class="w-[90px] px-3 py-2">上传用户</th>
                                            <th class="w-[80px] px-3 py-2">状态</th>
                                            <th class="w-[160px] px-3 py-2">视频地址</th>
                                            <th class="w-[120px] px-3 py-2">提交时间</th>
                                            <th class="w-[210px] px-3 py-2 text-right">操作</th>
                                        </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100">
                                        <?php foreach ($managedUploadedVideos as $item): ?>
                                            <?php
                                            $editPayload = [
                                                'id' => (int)$item['id'],
                                                'title' => (string)($item['published_title'] ?: $item['title']),
                                                'description' => (string)($item['published_description'] ?: $item['description']),
                                                'cover' => (string)($item['cover'] ?? ''),
                                                'video_url' => (string)($item['video_url'] ?? ''),
                                            ];
                                            ?>
                                            <tr>
                                                <td class="px-3 py-3">
                                                    <div class="max-w-[200px] truncate font-medium text-gray-900" title="<?= htmlspecialchars((string)($item['published_title'] ?: $item['title']), ENT_QUOTES, 'UTF-8') ?>">
                                                        <?= htmlspecialchars((string)($item['published_title'] ?: $item['title']), ENT_QUOTES, 'UTF-8') ?>
                                                    </div>
                                                    <div class="mt-1 text-xs text-gray-500">上传记录 #<?= (int)$item['id'] ?><?= (int)$item['video_id'] > 0 ? ' · 视频 #' . (int)$item['video_id'] : ' · 未发布' ?></div>
                                                </td>
                                                <td class="px-3 py-3"><?= htmlspecialchars((string)($item['username'] ?? '未知'), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="px-3 py-3">
                                                    <span class="rounded-full px-2 py-1 text-xs <?= uploadReviewStatusClass((string)$item['status']) ?>">
                                                        <?= htmlspecialchars($statusLabels[$item['status']] ?? '未知', ENT_QUOTES, 'UTF-8') ?>
                                                    </span>
                                                </td>
                                                <td class="px-3 py-3 align-top text-xs text-gray-500">
                                                    <?php $videoUrl = trim((string)($item['video_url'] ?? '')); ?>
                                                    <?php if ($videoUrl !== ''): ?>
                                                        <button
                                                            type="button"
                                                            class="upload-expand-toggle whitespace-nowrap text-left text-blue-600 hover:text-blue-800 hover:underline"
                                                            data-expand-label="查看视频地址"
                                                            aria-expanded="false"
                                                        >查看视频地址</button>
                                                        <div class="upload-expand-panel mt-1 hidden max-w-[280px] break-all text-gray-600"><?= htmlspecialchars($videoUrl, ENT_QUOTES, 'UTF-8') ?></div>
                                                    <?php else: ?>
                                                        <span class="text-gray-400">-</span>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="px-3 py-3 text-xs text-gray-500"><?= htmlspecialchars((string)$item['created_at'], ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="px-3 py-3">
                                                    <div class="flex flex-wrap justify-end gap-2">
                                                        <button type="button" class="whitespace-nowrap rounded border border-gray-300 px-3 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50" onclick="openStatusModal(<?= (int)$item['id'] ?>, '<?= htmlspecialchars((string)$item['status'], ENT_QUOTES, 'UTF-8') ?>')">修改状态</button>
                                                        <button type="button" class="whitespace-nowrap rounded bg-blue-600 px-3 py-1 text-xs font-semibold text-white hover:bg-blue-700" onclick='openEditModal(<?= json_encode($editPayload, JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT) ?>)'>编辑信息</button>
                                                        <button type="button" class="whitespace-nowrap rounded bg-red-600 px-3 py-1 text-xs font-semibold text-white hover:bg-red-700" onclick="openDeleteModal(<?= (int)$item['id'] ?>, '<?= htmlspecialchars((string)($item['published_title'] ?: $item['title']), ENT_QUOTES, 'UTF-8') ?>')">删除视频</button>
                                                    </div>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="p-10 text-center">
                        <h1 class="mb-2 text-lg font-semibold"><?= htmlspecialchars($pageTitle, ENT_QUOTES, 'UTF-8') ?></h1>
                        <p class="text-sm text-gray-500">功能开发中，当前为空白占位页面。</p>
                    </div>
                <?php endif; ?>
            </div>
        </section>
    </div>
</main>

<div id="statusModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4">
    <form method="post" class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <input type="hidden" name="panel" value="uploaded_video_status">
        <input type="hidden" name="upload_id" id="statusUploadId">
        <h3 class="mb-4 text-base font-semibold">修改状态</h3>
        <label class="mb-1 block text-sm font-medium text-gray-700">审核状态</label>
        <select name="status" id="statusValue" class="mb-5 w-full rounded border border-gray-300 px-3 py-2 text-sm">
            <option value="pending">审核中</option>
            <option value="approved">审核通过</option>
            <option value="rejected">审核失败</option>
        </select>
        <div class="flex justify-end gap-2">
            <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50" onclick="closeUploadModal('statusModal')">取消</button>
            <button class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">保存</button>
        </div>
    </form>
</div>

<div id="editModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4">
    <form method="post" class="w-full max-w-xl rounded-lg bg-white p-6 shadow-xl">
        <input type="hidden" name="panel" value="uploaded_video_edit">
        <input type="hidden" name="upload_id" id="editUploadId">
        <h3 class="mb-4 text-base font-semibold">编辑信息</h3>
        <div class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">视频名称</label>
                <input name="title" id="editTitle" required class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">简介</label>
                <textarea name="description" id="editDescription" rows="4" class="w-full rounded border border-gray-300 px-3 py-2 text-sm"></textarea>
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">图片</label>
                <input name="cover" id="editCover" class="w-full rounded border border-gray-300 px-3 py-2 text-sm" placeholder="/m3u8/xxx/screenshot.jpg">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">视频地址</label>
                <input name="video_url" id="editVideoUrl" class="w-full rounded border border-gray-300 px-3 py-2 text-sm" placeholder="/m3u8/xxx/index.m3u8">
            </div>
        </div>
        <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50" onclick="closeUploadModal('editModal')">取消</button>
            <button class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">保存</button>
        </div>
    </form>
</div>

<div id="deleteModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4">
    <form method="post" class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <input type="hidden" name="panel" value="uploaded_video_delete">
        <input type="hidden" name="upload_id" id="deleteUploadId">
        <h3 class="mb-2 text-base font-semibold text-red-600">确认删除视频？</h3>
        <p class="mb-5 text-sm text-gray-600">确认后会删除网站视频数据，并通知远程后端同步删除相关文件。系统会通过站内通知告知对应用户。</p>
        <p id="deleteVideoTitle" class="mb-5 rounded bg-red-50 px-3 py-2 text-sm text-red-700"></p>
        <div class="flex justify-end gap-2">
            <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50" onclick="closeUploadModal('deleteModal')">取消</button>
            <button class="rounded bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">确认删除</button>
        </div>
    </form>
</div>

<div id="reviewDeleteModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4">
    <form method="post" class="w-full max-w-md rounded-lg bg-white p-6 shadow-xl">
        <input type="hidden" name="panel" value="upload_review_delete">
        <input type="hidden" name="upload_id" id="reviewDeleteUploadId">
        <h3 class="mb-2 text-base font-semibold text-red-600">确认删除审核记录？</h3>
        <p class="mb-5 text-sm text-gray-600">确认后会删除当前审核记录，并通知远程后端同步清理相关文件。该操作不可撤销。</p>
        <p id="reviewDeleteTitle" class="mb-5 rounded bg-red-50 px-3 py-2 text-sm text-red-700"></p>
        <div class="flex justify-end gap-2">
            <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm hover:bg-gray-50" onclick="closeUploadModal('reviewDeleteModal')">取消</button>
            <button class="rounded bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">确认删除</button>
        </div>
    </form>
</div>

<script>
function copyInstallerCmd() {
    const el = document.getElementById('uploadBackendInstallerCmd');
    if (!el) return;
    el.select();
    el.setSelectionRange(0, el.value.length);
    try {
        document.execCommand('copy');
    } catch (e) {}
}
function openUploadModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    }
}
function closeUploadModal(id) {
    const modal = document.getElementById(id);
    if (modal) {
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    }
}
function openStatusModal(uploadId, status) {
    document.getElementById('statusUploadId').value = uploadId;
    document.getElementById('statusValue').value = status || 'pending';
    openUploadModal('statusModal');
}
function openEditModal(data) {
    document.getElementById('editUploadId').value = data.id || '';
    document.getElementById('editTitle').value = data.title || '';
    document.getElementById('editDescription').value = data.description || '';
    document.getElementById('editCover').value = data.cover || '';
    document.getElementById('editVideoUrl').value = data.video_url || '';
    openUploadModal('editModal');
}
function openDeleteModal(uploadId, title) {
    document.getElementById('deleteUploadId').value = uploadId;
    document.getElementById('deleteVideoTitle').textContent = title || '该视频';
    openUploadModal('deleteModal');
}
function openReviewDeleteModal(uploadId, title) {
    document.getElementById('reviewDeleteUploadId').value = uploadId;
    document.getElementById('reviewDeleteTitle').textContent = title || '该审核记录';
    openUploadModal('reviewDeleteModal');
}
document.querySelectorAll('.upload-expand-toggle').forEach(function (btn) {
    btn.addEventListener('click', function () {
        var panel = btn.nextElementSibling;
        if (!panel || !panel.classList.contains('upload-expand-panel')) return;
        var open = panel.classList.toggle('hidden');
        btn.setAttribute('aria-expanded', open ? 'false' : 'true');
        var label = btn.getAttribute('data-expand-label') || '查看';
        btn.textContent = open ? label : '收起';
    });
});
</script>

<?php include __DIR__ . '/../components/theme-toggle-script.php'; ?>
</body>
</html>
