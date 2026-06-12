<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/upload_config.php';

requireLogin();

if (isAdmin()) {
    header('Location: admin/upload_manage.php?section=content&item=review');
    exit;
}

$pdo = getDB();
$user = getCurrentUser();
$userId = (int)($user['id'] ?? 0);
if ($userId <= 0) {
    header('Location: login.php');
    exit;
}

ensureUserVideoUploadsTable($pdo);
$videoPolicy = getUploadVideoConfig($pdo);
$trafficEnabled = !empty($videoPolicy['traffic_enabled']);

$message = '';
$error = '';

/**
 * @return array{upload:array,video_id:int,episode_id:int,video_url:string,cover:string}
 */
function userUploadManagedInfo(PDO $pdo, array $upload, array $recordMap): array
{
    $uploadId = (int)($upload['id'] ?? 0);
    $recordId = 'upload_' . $uploadId;
    $videoId = isset($recordMap[$recordId]) ? (int)$recordMap[$recordId] : 0;
    $episodeId = 0;
    $videoUrl = '';
    $cover = '';

    if ($videoId > 0) {
        $st = $pdo->prepare('SELECT id, cover FROM videos WHERE id = ? LIMIT 1');
        $st->execute([$videoId]);
        $videoRow = $st->fetch();
        if (!$videoRow) {
            $videoId = 0;
        } else {
            $cover = (string)($videoRow['cover'] ?? '');
            $ep = $pdo->prepare('SELECT id, video_url FROM video_episodes WHERE video_id = ? ORDER BY id ASC LIMIT 1');
            $ep->execute([$videoId]);
            $epRow = $ep->fetch();
            if ($epRow) {
                $episodeId = (int)($epRow['id'] ?? 0);
                $videoUrl = (string)($epRow['video_url'] ?? '');
            }
        }
    }

    return [
        'upload' => $upload,
        'video_id' => $videoId,
        'episode_id' => $episodeId,
        'video_url' => $videoUrl,
        'cover' => $cover,
    ];
}

function userCanEditUpload(array $upload): bool
{
    // 允许用户随时改标题/简介/流量策略（已发布会同步 videos 表）。
    return (int)($upload['id'] ?? 0) > 0;
}

function userCanDeleteUpload(array $upload): bool
{
    return (int)($upload['id'] ?? 0) > 0;
}

function loadUserUpload(PDO $pdo, int $userId, int $uploadId): ?array
{
    $st = $pdo->prepare('SELECT * FROM user_video_uploads WHERE id = ? AND user_id = ? LIMIT 1');
    $st->execute([$uploadId, $userId]);
    $row = $st->fetch();
    return $row ?: null;
}

/**
 * @return array{ok:bool,message?:string,error?:string}
 */
function userUpdateUpload(
    PDO $pdo,
    int $userId,
    array $upload,
    array $managed,
    bool $trafficEnabled,
    array $post
): array {
    $title = trim((string)($post['title'] ?? ''));
    if ($title === '') {
        return ['ok' => false, 'error' => '请输入视频名称'];
    }
    $description = trim((string)($post['description'] ?? ''));
    $isTraffic = $trafficEnabled && !empty($post['is_traffic']) ? 1 : 0;
    $trafficCost = $isTraffic ? max(0, (int)($post['traffic_cost'] ?? 0)) : 0;

    $pdo->prepare('UPDATE user_video_uploads SET title = ?, description = ?, is_traffic = ?, traffic_cost = ? WHERE id = ? AND user_id = ?')
        ->execute([$title, $description, $isTraffic, $trafficCost, (int)$upload['id'], $userId]);

    $videoId = (int)($managed['video_id'] ?? 0);
    if ($videoId > 0) {
        // videos 表字段可能不存在（兼容老库）
        $hasTrafficCol = (bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'is_traffic'")->fetch();
        $hasCostCol = (bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'traffic_cost'")->fetch();
        $sets = ['title = ?', 'description = ?'];
        $vals = [$title, $description];
        if ($hasTrafficCol) {
            $sets[] = 'is_traffic = ?';
            $vals[] = $isTraffic;
        }
        if ($hasCostCol) {
            $sets[] = 'traffic_cost = ?';
            $vals[] = $trafficCost;
        }
        $vals[] = $videoId;
        $pdo->prepare('UPDATE videos SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }

    return ['ok' => true, 'message' => '已保存'];
}

/**
 * @return array{ok:bool,message?:string,error?:string}
 */
function userDeleteUpload(PDO $pdo, int $userId, array $upload, array $managed, array $recordMap): array
{
    // 删除必须走“主站 -> 远程上传后端”的同步删除，避免只删主站记录导致后端残留文件
    $apiCfg = getUploadApiConfig($pdo);
    if (!UploadConfig::hasTranscodeBackend($apiCfg)) {
        return ['ok' => false, 'error' => '未配置远程上传后端，无法同步删除后端视频文件，请联系管理员先在「上传中心 → 转码后端」完成配置'];
    }

    // 先通知远程后端清理文件
    $uploadWithUsername = $upload;
    $uploadWithUsername['username'] = $uploadWithUsername['username'] ?? '';
    $mediaPaths = array_values(array_filter([
        (string)($managed['video_url'] ?? ''),
        (string)($managed['cover'] ?? ''),
    ]));
    $backendResult = notifyUploadBackendAction($pdo, $uploadWithUsername, 'delete_video', ['media_paths' => $mediaPaths]);
    if (empty($backendResult['ok'])) {
        return ['ok' => false, 'error' => (string)($backendResult['error'] ?? $backendResult['message'] ?? '删除失败')];
    }

    $pdo->beginTransaction();
    try {
        $videoId = (int)($managed['video_id'] ?? 0);
        if ($videoId > 0) {
            $pdo->prepare('DELETE FROM video_episodes WHERE video_id = ?')->execute([$videoId]);
            $pdo->prepare('DELETE FROM videos WHERE id = ?')->execute([$videoId]);
        }

        $pdo->prepare('DELETE FROM user_video_uploads WHERE id = ? AND user_id = ?')->execute([(int)$upload['id'], $userId]);

        // 清理映射
        $recordId = 'upload_' . (int)$upload['id'];
        unset($recordMap[$recordId]);
        saveUploadVideoRecordMap($pdo, $recordMap);

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        throw $e;
    }

    return ['ok' => true, 'message' => '已删除'];
}

$recordMap = uploadVideoRecordMap($pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    $uploadId = (int)($_POST['upload_id'] ?? 0);
    $upload = $uploadId > 0 ? loadUserUpload($pdo, $userId, $uploadId) : null;
    if (!$upload) {
        $error = '记录不存在或无权限';
    } else {
        $managed = userUploadManagedInfo($pdo, $upload, $recordMap);
        if ($action === 'save' && userCanEditUpload($upload)) {
            $res = userUpdateUpload($pdo, $userId, $upload, $managed, $trafficEnabled, $_POST);
            if (!empty($res['ok'])) {
                $message = (string)($res['message'] ?? '已保存');
            } else {
                $error = (string)($res['error'] ?? '保存失败');
            }
        } elseif ($action === 'delete' && userCanDeleteUpload($upload)) {
            $res = userDeleteUpload($pdo, $userId, $upload, $managed, $recordMap);
            if (!empty($res['ok'])) {
                $message = (string)($res['message'] ?? '已删除');
                $recordMap = uploadVideoRecordMap($pdo);
            } else {
                $error = (string)($res['error'] ?? '删除失败');
            }
        } else {
            $error = '操作无效';
        }
    }
}

$uploads = fetchUserVideoUploads($pdo, $userId);
$labels = uploadReviewStatusLabels();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>视频管理 - 竹叶云控平台</title>
    <?php include __DIR__ . '/components/theme-head.php'; ?>
    <?php include __DIR__ . '/components/theme-dynamic.php'; ?>
</head>
<body class="bg-gray-100 text-gray-900">
<nav class="bg-white/80 glass shadow-sm sticky top-0 z-50">
    <div class="mx-auto max-w-screen-xl px-4 py-3 flex items-center justify-between gap-3 text-sm">
        <div class="flex items-center gap-2">
            <a href="user_home.php" class="rounded-full px-3 py-1 hover:bg-gray-100/50">← 返回个人主页</a>
            <a href="upload.php" class="rounded-full px-3 py-1 hover:bg-gray-100/50">去上传</a>
        </div>
        <div class="flex items-center gap-2">
            <?php include __DIR__ . '/components/logout-nav-link.php'; ?>
            <?php include __DIR__ . '/components/theme-toggle.php'; ?>
        </div>
    </div>
</nav>

<main class="mx-auto max-w-screen-xl px-4 py-6">
    <div class="rounded-xl border border-gray-200 bg-white overflow-hidden shadow-sm">
        <div class="border-b border-gray-100 px-5 py-4">
            <h1 class="text-base font-semibold text-gray-900">视频管理</h1>
            <p class="mt-1 text-sm text-gray-500">管理你上传的视频：编辑信息、流量设置、删除、查看审核状态。</p>
        </div>

        <?php if ($message !== ''): ?>
            <div class="mx-4 mt-4 rounded-lg bg-green-50 px-4 py-2 text-sm text-green-700"><?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>
        <?php if ($error !== ''): ?>
            <div class="mx-4 mt-4 rounded-lg bg-red-50 px-4 py-2 text-sm text-red-600"><?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
        <?php endif; ?>

        <div class="p-5">
            <?php if (!$trafficEnabled): ?>
                <div class="mb-4 rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-900">
                    当前未开启“流量视频”功能（管理员可在「上传管理 → 视频策略」中开启）。你仍可编辑信息、查看状态、删除视频。
                </div>
            <?php endif; ?>

            <?php if (empty($uploads)): ?>
                <div class="rounded-lg border border-dashed border-gray-300 p-10 text-center text-sm text-gray-500">
                    你还没有上传记录。
                    <div class="mt-3">
                        <a href="upload.php" class="inline-block rounded bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">去上传</a>
                    </div>
                </div>
            <?php else: ?>
                <div class="overflow-x-auto">
                    <table class="min-w-[980px] table-fixed divide-y divide-gray-200 text-sm">
                        <thead class="bg-gray-50 text-left text-xs uppercase tracking-wide text-gray-500">
                            <tr>
                                <th class="w-[220px] px-3 py-2">视频</th>
                                <th class="w-[110px] px-3 py-2">状态</th>
                                <th class="w-[280px] px-3 py-2">文件</th>
                                <th class="w-[150px] px-3 py-2">流量视频</th>
                                <th class="w-[120px] px-3 py-2">提交时间</th>
                                <th class="w-[220px] px-3 py-2 text-right">操作</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-100">
                        <?php foreach ($uploads as $u): ?>
                            <?php
                                $managed = userUploadManagedInfo($pdo, $u, $recordMap);
                                $status = (string)($u['status'] ?? 'pending');
                                $isTraffic = !empty($u['is_traffic']);
                            ?>
                            <tr>
                                <td class="px-3 py-3 align-top">
                                    <div class="max-w-[210px] truncate font-medium text-gray-900" title="<?= htmlspecialchars((string)$u['title'], ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars((string)$u['title'], ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                    <?php if (!empty($u['description'])): ?>
                                        <div class="mt-1 max-w-[210px] truncate text-xs text-gray-500" title="<?= htmlspecialchars((string)$u['description'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string)$u['description'], ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>
                                    <?php if (!empty($managed['video_id'])): ?>
                                        <div class="mt-1 text-[11px] text-gray-400">已发布 · 视频 #<?= (int)$managed['video_id'] ?></div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <span class="inline-flex rounded-full px-2 py-1 text-xs <?= uploadReviewStatusClass($status) ?>">
                                        <?= htmlspecialchars($labels[$status] ?? '未知', ENT_QUOTES, 'UTF-8') ?>
                                    </span>
                                    <?php if (!empty($u['review_note'])): ?>
                                        <div class="mt-1 text-[11px] text-gray-400" title="<?= htmlspecialchars((string)$u['review_note'], ENT_QUOTES, 'UTF-8') ?>">
                                            <?= htmlspecialchars((string)$u['review_note'], ENT_QUOTES, 'UTF-8') ?>
                                        </div>
                                    <?php endif; ?>
                                </td>
                                <td class="px-3 py-3 align-top text-xs text-gray-500">
                                    <?php $fname = (string)($u['original_filename'] ?? $u['stored_filename'] ?? $u['backend_file_id'] ?? '-'); ?>
                                    <div class="w-[280px] overflow-hidden text-ellipsis whitespace-nowrap" title="<?= htmlspecialchars($fname, ENT_QUOTES, 'UTF-8') ?>">
                                        <?= htmlspecialchars($fname !== '' ? $fname : '-', ENT_QUOTES, 'UTF-8') ?>
                                    </div>
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <div class="text-xs text-gray-600">
                                        <?= $trafficEnabled ? ($isTraffic ? '已开启' : '未开启') : '未启用功能' ?>
                                    </div>
                                    <div class="mt-1 text-xs text-gray-500">
                                        消耗：<?= $trafficEnabled && $isTraffic ? (int)($u['traffic_cost'] ?? 0) : 0 ?>
                                    </div>
                                </td>
                                <td class="px-3 py-3 align-top text-xs text-gray-500">
                                    <?= htmlspecialchars((string)($u['created_at'] ?? '-'), ENT_QUOTES, 'UTF-8') ?>
                                </td>
                                <td class="px-3 py-3 align-top">
                                    <div class="flex flex-wrap justify-end gap-2">
                                        <button
                                            type="button"
                                            class="whitespace-nowrap rounded border border-gray-300 px-3 py-1 text-xs font-semibold text-gray-700 hover:bg-gray-50"
                                            onclick="openEdit(<?= (int)$u['id'] ?>)"
                                        >编辑</button>
                                        <form method="post" onsubmit="return confirm('确定删除该视频记录吗？已发布的视频也会被同步删除。');">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="upload_id" value="<?= (int)$u['id'] ?>">
                                            <button class="whitespace-nowrap rounded bg-red-600 px-3 py-1 text-xs font-semibold text-white hover:bg-red-700">删除</button>
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
</main>

<!-- 编辑弹窗 -->
<div id="editModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/40 px-4">
    <form method="post" class="w-full max-w-lg rounded-xl bg-white p-6 shadow-xl">
        <input type="hidden" name="action" value="save">
        <input type="hidden" name="upload_id" id="editUploadId">
        <h3 class="mb-4 text-base font-semibold text-gray-900">编辑视频</h3>

        <div class="space-y-4">
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">视频名称</label>
                <input name="title" id="editTitle" required class="w-full rounded border border-gray-300 px-3 py-2 text-sm">
            </div>
            <div>
                <label class="mb-1 block text-sm font-medium text-gray-700">简介</label>
                <textarea name="description" id="editDescription" rows="4" class="w-full rounded border border-gray-300 px-3 py-2 text-sm"></textarea>
            </div>

            <div class="rounded-lg border border-gray-200 bg-gray-50 px-4 py-3 <?= $trafficEnabled ? '' : 'opacity-60' ?>">
                <label class="flex items-center gap-2 text-sm font-medium text-gray-800 <?= $trafficEnabled ? '' : 'pointer-events-none' ?>">
                    <input type="checkbox" name="is_traffic" id="editIsTraffic" value="1" class="rounded border-gray-300" <?= $trafficEnabled ? '' : 'disabled' ?>>
                    开启流量视频
                </label>
                <div class="mt-3">
                    <label class="mb-1 block text-xs font-medium text-gray-700">解锁消耗流量</label>
                    <input name="traffic_cost" id="editTrafficCost" type="number" min="0" value="0" class="w-full max-w-xs rounded border border-gray-300 bg-white px-3 py-2 text-sm" <?= $trafficEnabled ? '' : 'disabled' ?>>
                </div>
                <?php if (!$trafficEnabled): ?>
                    <p class="mt-2 text-xs text-amber-700">管理员未开启“流量视频”功能，此处不可设置。</p>
                <?php endif; ?>
            </div>
        </div>

        <div class="mt-5 flex justify-end gap-2">
            <button type="button" class="rounded border border-gray-300 px-4 py-2 text-sm text-gray-700 hover:bg-gray-50" onclick="closeEdit()">取消</button>
            <button class="rounded bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">保存</button>
        </div>
    </form>
</div>

<script>
(() => {
    const modal = document.getElementById('editModal');
    const uploads = <?=
        json_encode(
            array_map(static function ($u) {
                return [
                    'id' => (int)($u['id'] ?? 0),
                    'title' => (string)($u['title'] ?? ''),
                    'description' => (string)($u['description'] ?? ''),
                    'is_traffic' => !empty($u['is_traffic']),
                    'traffic_cost' => (int)($u['traffic_cost'] ?? 0),
                ];
            }, $uploads),
            JSON_UNESCAPED_UNICODE | JSON_HEX_APOS | JSON_HEX_QUOT
        );
    ?>;
    const map = {};
    uploads.forEach(u => { map[u.id] = u; });

    window.openEdit = function (id) {
        const u = map[id];
        if (!u || !modal) return;
        document.getElementById('editUploadId').value = String(u.id);
        document.getElementById('editTitle').value = u.title || '';
        document.getElementById('editDescription').value = u.description || '';
        document.getElementById('editIsTraffic').checked = !!u.is_traffic;
        document.getElementById('editTrafficCost').value = String(u.traffic_cost || 0);
        modal.classList.remove('hidden');
        modal.classList.add('flex');
    };

    window.closeEdit = function () {
        if (!modal) return;
        modal.classList.add('hidden');
        modal.classList.remove('flex');
    };

    if (modal) {
        modal.addEventListener('click', (e) => {
            if (e.target === modal) window.closeEdit();
        });
    }
})();
</script>
<?php include __DIR__ . '/components/theme-toggle-script.php'; ?>
</body>
</html>

