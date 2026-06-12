<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/flash.php';
require_once '../includes/upload_domain_group.php';

requireAdmin();

$pdo = getDB();
$message = '';
$error = '';
applyFlash($message, $error);

$videoSgFeature = (bool)$pdo->query("SHOW TABLES LIKE 'server_groups'")->fetch()
    && (bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'server_group_id'")->fetch();
$serverGroups = [];
if ($videoSgFeature) {
    $serverGroups = $pdo->query('SELECT id, name FROM server_groups ORDER BY id')->fetchAll();
}
$defaultVideoServerGroupId = $videoSgFeature ? getUploadDomainServerGroupId($pdo) : null;

// 是否启用流量视频功能
$videoTrafficFeature = (bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'is_traffic'")->fetch();

// 页面分区（左侧菜单）
$activeSection = trim((string)($_GET['section'] ?? ''));
if (!in_array($activeSection, ['overview', 'videos', 'episodes'], true)) {
    $activeSection = 'overview';
}
$activeVideoId = (int)($_GET['video_id'] ?? 0);

// 处理操作
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect = trim((string)($_POST['redirect'] ?? ''));
    if ($redirect === '' || strpos($redirect, 'videos.php') !== 0) {
        $redirect = 'videos.php?section=' . urlencode($activeSection);
    }
    
    if ($action === 'add_video') {
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $cover = trim($_POST['cover'] ?? '');
        $isTraffic = $videoTrafficFeature && !empty($_POST['is_traffic']) ? 1 : 0;
        $trafficCost = $videoTrafficFeature ? max(0, (int)($_POST['traffic_cost'] ?? 0)) : 0;
        // 这两个字段历史上 UI 未暴露，默认保持 0（若你未来要启用，再做开关/单独页面更合适）
        $unlockMinutes = $videoTrafficFeature ? max(0, (int)($_POST['unlock_validity_minutes'] ?? 0)) : 0;
        $refreshLimit = $videoTrafficFeature ? max(0, (int)($_POST['refresh_limit'] ?? 0)) : 0;
        
        if ($title) {
            $cols = ['title', 'description', 'cover'];
            $vals = [$title, $description, $cover];
            $place = ['?', '?', '?'];
            if ($videoSgFeature) {
                $sgRaw = $_POST['server_group_id'] ?? '';
                $sgId = ($sgRaw === '' || $sgRaw === null) ? null : (int)$sgRaw;
                if ($sgId === null && $defaultVideoServerGroupId !== null) {
                    $sgId = $defaultVideoServerGroupId;
                }
                $cols[] = 'server_group_id';
                $vals[] = $sgId;
                $place[] = '?';
            }
            if ($videoTrafficFeature) {
                $cols = array_merge($cols, ['is_traffic', 'traffic_cost', 'unlock_validity_minutes', 'refresh_limit']);
                $vals = array_merge($vals, [$isTraffic, $trafficCost, $unlockMinutes, $refreshLimit]);
                $place = array_merge($place, ['?','?','?','?']);
            }
            $sql = 'INSERT INTO videos (' . implode(',', $cols) . ') VALUES (' . implode(',', $place) . ')';
            $pdo->prepare($sql)->execute($vals);
            $message = '视频添加成功';
        } else {
            $error = '请输入视频标题';
        }
    } elseif ($action === 'edit_video') {
        $videoId = $_POST['video_id'] ?? 0;
        $title = trim($_POST['title'] ?? '');
        $description = trim($_POST['description'] ?? '');
        $cover = trim($_POST['cover'] ?? '');
        $isTraffic = $videoTrafficFeature && !empty($_POST['is_traffic']) ? 1 : 0;
        $trafficCost = $videoTrafficFeature ? max(0, (int)($_POST['traffic_cost'] ?? 0)) : 0;
        // 兼容旧 UI：没有提交就不覆盖，避免编辑一次被重置为 0
        $unlockMinutes = null;
        $refreshLimit = null;
        if ($videoTrafficFeature && array_key_exists('unlock_validity_minutes', $_POST)) {
            $unlockMinutes = max(0, (int)($_POST['unlock_validity_minutes'] ?? 0));
        }
        if ($videoTrafficFeature && array_key_exists('refresh_limit', $_POST)) {
            $refreshLimit = max(0, (int)($_POST['refresh_limit'] ?? 0));
        }
        
        if ($videoId && $title) {
            $sets = ['title = ?', 'description = ?', 'cover = ?'];
            $vals = [$title, $description, $cover];
            if ($videoSgFeature) {
                $sgRaw = $_POST['server_group_id'] ?? '';
                $sets[] = 'server_group_id = ?';
                $vals[] = ($sgRaw === '' || $sgRaw === null) ? null : (int)$sgRaw;
            }
            if ($videoTrafficFeature) {
                $sets[] = 'is_traffic = ?';
                $sets[] = 'traffic_cost = ?';
                $vals[] = $isTraffic;
                $vals[] = $trafficCost;
                if ($unlockMinutes !== null) {
                    $sets[] = 'unlock_validity_minutes = ?';
                    $vals[] = $unlockMinutes;
                }
                if ($refreshLimit !== null) {
                    $sets[] = 'refresh_limit = ?';
                    $vals[] = $refreshLimit;
                }
            }
            $vals[] = $videoId;
            $sql = 'UPDATE videos SET ' . implode(', ', $sets) . ' WHERE id = ?';
            $pdo->prepare($sql)->execute($vals);
            $message = '视频更新成功';
        }
    } elseif ($action === 'delete_video') {
        $videoId = $_POST['video_id'] ?? 0;
        if ($videoId) {
            $stmt = $pdo->prepare("DELETE FROM video_episodes WHERE video_id = ?");
            $stmt->execute([$videoId]);
            if ($pdo->query("SHOW TABLES LIKE 'video_unlocks'")->fetch()) {
                $pdo->prepare("DELETE FROM video_unlocks WHERE video_id = ?")->execute([$videoId]);
            }
            $stmt = $pdo->prepare("DELETE FROM videos WHERE id = ?");
            $stmt->execute([$videoId]);
            $message = '视频删除成功';
        }
    } elseif ($action === 'add_episode') {
        $videoId = $_POST['video_id'] ?? 0;
        $episodeName = trim($_POST['episode_name'] ?? '');
        $videoUrl = trim($_POST['video_url'] ?? '');
        
        // 去除域名和协议
        $videoUrl = preg_replace('/^https?:\/\//', '', $videoUrl);
        // 去除域名部分，只保留路径
        if (preg_match('/^[^\/]+(\/.+)$/', $videoUrl, $matches)) {
            $videoUrl = $matches[1];
        } elseif (!preg_match('/^\//', $videoUrl)) {
            $videoUrl = '/' . $videoUrl;
        }
        
        if ($videoId && $episodeName && $videoUrl) {
            // 获取当前最大集数
            $stmt = $pdo->prepare("SELECT MAX(episode_order) as max_order FROM video_episodes WHERE video_id = ?");
            $stmt->execute([$videoId]);
            $result = $stmt->fetch();
            $order = ($result['max_order'] ?? 0) + 1;
            
            $stmt = $pdo->prepare("INSERT INTO video_episodes (video_id, episode_name, video_url, episode_order) VALUES (?, ?, ?, ?)");
            $stmt->execute([$videoId, $episodeName, $videoUrl, $order]);
            $message = '集数添加成功';
        } else {
            $error = '请填写所有字段';
        }
    } elseif ($action === 'edit_episode') {
        $episodeId = $_POST['episode_id'] ?? 0;
        $episodeName = trim($_POST['episode_name'] ?? '');
        $videoUrl = trim($_POST['video_url'] ?? '');
        
        // 去除域名和协议
        $videoUrl = preg_replace('/^https?:\/\//', '', $videoUrl);
        // 去除域名部分，只保留路径
        if (preg_match('/^[^\/]+(\/.+)$/', $videoUrl, $matches)) {
            $videoUrl = $matches[1];
        } elseif (!preg_match('/^\//', $videoUrl)) {
            $videoUrl = '/' . $videoUrl;
        }
        
        if ($episodeId && $episodeName && $videoUrl) {
            $stmt = $pdo->prepare("UPDATE video_episodes SET episode_name = ?, video_url = ? WHERE id = ?");
            $stmt->execute([$episodeName, $videoUrl, $episodeId]);
            $message = '集数更新成功';
        }
    } elseif ($action === 'delete_episode') {
        $episodeId = $_POST['episode_id'] ?? 0;
        if ($episodeId) {
            $stmt = $pdo->prepare("DELETE FROM video_episodes WHERE id = ?");
            $stmt->execute([$episodeId]);
            $message = '集数删除成功';
        }
    }

    finishPostRequest($message ?: null, $error ?: null, $redirect);
}

// 获取视频列表
if ($videoSgFeature) {
    $stmt = $pdo->query("SELECT v.*, 
        (SELECT COUNT(*) FROM video_episodes e WHERE e.video_id = v.id) AS episode_count,
        sg.name AS server_group_name
        FROM videos v
        LEFT JOIN server_groups sg ON v.server_group_id = sg.id
        ORDER BY v.created_at DESC");
} else {
    $stmt = $pdo->query("SELECT v.*, COUNT(e.id) as episode_count FROM videos v 
                    LEFT JOIN video_episodes e ON v.id = e.video_id 
                    GROUP BY v.id 
                    ORDER BY v.created_at DESC");
}
$videos = $stmt->fetchAll();

$videoCount = is_array($videos) ? count($videos) : 0;
$episodeCount = 0;
try {
    $episodeCount = (int)$pdo->query('SELECT COUNT(*) FROM video_episodes')->fetchColumn();
} catch (Throwable $e) {
    $episodeCount = 0;
}

$episodesForPanel = [];
$activeVideoForPanel = null;
if ($activeSection === 'episodes') {
    if ($activeVideoId <= 0 && !empty($videos[0]['id'])) {
        $activeVideoId = (int)$videos[0]['id'];
    }
    foreach ($videos as $v) {
        if ((int)($v['id'] ?? 0) === $activeVideoId) {
            $activeVideoForPanel = $v;
            break;
        }
    }
    if ($activeVideoId > 0) {
        $stEp = $pdo->prepare("SELECT * FROM video_episodes WHERE video_id = ? ORDER BY episode_order");
        $stEp->execute([$activeVideoId]);
        $episodesForPanel = $stEp->fetchAll() ?: [];
    }
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php $themeAssetPrefix = '../'; include __DIR__ . '/../components/theme-head.php'; ?>
    <title>视频管理 - 影视系统</title>

    <?php include __DIR__ . '/../components/theme-dynamic.php'; ?>
</head>
<body class="bg-gray-100 text-gray-900">
<?php $adminNavActive = 'videos'; include __DIR__ . '/../components/admin-top-nav.php'; ?>
    
    <main class="mx-auto max-w-screen-xl px-4 py-6 space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-lg font-semibold text-gray-900">视频管理</h1>
                <p class="mt-1 text-xs text-gray-500">按模块管理：视频列表 / 集数管理。</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="inline-flex items-center gap-2 rounded-full bg-gray-900/90 px-3 py-1 text-white">
                    <span class="h-1.5 w-1.5 rounded-full bg-white/70" aria-hidden="true"></span>
                    视频 <?= (int)$videoCount ?>
                </span>
                <span class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-blue-700 ring-1 ring-blue-100">
                    <span class="h-1.5 w-1.5 rounded-full bg-blue-500" aria-hidden="true"></span>
                    集数 <?= (int)$episodeCount ?>
                </span>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="flex gap-4 items-start">
            <?php include __DIR__ . '/../components/admin-videos-sidebar.php'; ?>

            <section class="min-w-0 flex-1 space-y-6">
                <?php if ($activeSection === 'overview'): ?>
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                        <div class="border-b border-gray-100 px-5 py-3">
                            <p class="text-sm font-semibold text-gray-900">总览</p>
                            <p class="mt-1 text-xs text-gray-500">从左侧选择模块进入管理。</p>
                        </div>
                        <div class="px-5 py-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <a href="?section=videos" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                                <p class="text-sm font-semibold text-gray-900">视频列表</p>
                                <p class="mt-1 text-xs text-gray-500">新增 / 编辑 / 删除</p>
                            </a>
                            <a href="?section=episodes" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                                <p class="text-sm font-semibold text-gray-900">集数管理</p>
                                <p class="mt-1 text-xs text-gray-500">按视频管理集数与路径</p>
                            </a>
                        </div>
                    </div>

                <?php elseif ($activeSection === 'episodes'): ?>
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                        <div class="border-b border-gray-100 px-5 py-3 flex flex-wrap items-center justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-gray-900">集数管理</p>
                                <p class="mt-1 text-xs text-gray-500">视频链接会自动去除域名，仅保留路径（例如 `/path/to.m3u8`）。</p>
                            </div>
                            <?php if (!empty($videos)): ?>
                                <form method="GET" class="flex items-center gap-2">
                                    <input type="hidden" name="section" value="episodes">
                                    <input type="hidden" name="video_id" id="episodeVideoIdInput" value="<?= (int)$activeVideoId ?>">
                                    <select name="video_id_select" class="hidden" aria-hidden="true" tabindex="-1">
                                        <?php foreach ($videos as $v): ?>
                                            <?php $vid = (int)($v['id'] ?? 0); ?>
                                            <option value="<?= $vid ?>" <?= $vid === (int)$activeVideoId ? 'selected' : '' ?>>
                                                #<?= $vid ?> <?= htmlspecialchars((string)($v['title'] ?? ''), ENT_QUOTES, 'UTF-8') ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button"
                                            class="inline-flex items-center gap-2 rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm text-gray-800 shadow-sm hover:bg-gray-50"
                                            onclick="openVideoPickerModal()">
                                        <span class="inline-flex h-5 w-5 items-center justify-center rounded bg-blue-50 text-xs font-semibold text-blue-700">选</span>
                                        <span class="max-w-[12rem] truncate">
                                            <?= htmlspecialchars((string)($activeVideoForPanel['title'] ?? '选择视频'), ENT_QUOTES, 'UTF-8') ?>
                                        </span>
                                        <span class="text-xs text-gray-400">
                                            <?= (int)($activeVideoForPanel['episode_count'] ?? 0) ?> 集
                                        </span>
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>

                        <div class="px-5 py-5 space-y-5">
                            <?php if (empty($videos)): ?>
                                <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 px-4 py-6 text-center">
                                    <p class="text-sm font-semibold text-gray-800">还没有视频</p>
                                    <p class="mt-1 text-xs text-gray-500">请先到“视频列表”新增视频，然后再来添加集数。</p>
                                    <a class="mt-3 inline-flex rounded-lg bg-blue-600 px-3 py-2 text-xs font-semibold text-white hover:bg-blue-700" href="videos.php?section=videos">去新增视频</a>
                                </div>
                            <?php else: ?>
                                <form method="POST" class="grid gap-2 sm:grid-cols-3">
                                    <input type="hidden" name="action" value="add_episode">
                                    <input type="hidden" name="video_id" value="<?= (int)$activeVideoId ?>">
                                    <input type="hidden" name="redirect" value="videos.php?section=episodes&amp;video_id=<?= (int)$activeVideoId ?>">
                                    <input type="text" name="episode_name" placeholder="集数名称" required
                                           class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                                    <input type="text" name="video_url" placeholder="视频链接（会自动去除域名）" required
                                           class="rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none sm:col-span-2">
                                    <div class="sm:col-span-3 flex justify-end">
                                        <button type="submit" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">添加集数</button>
                                    </div>
                                </form>
                            <?php endif; ?>

                            <?php if (!empty($videos) && empty($episodesForPanel)): ?>
                                <div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 px-4 py-6 text-center">
                                    <p class="text-sm font-semibold text-gray-800">暂无集数</p>
                                    <p class="mt-1 text-xs text-gray-500">你可以在上方表单先添加第一集。</p>
                                </div>
                            <?php elseif (!empty($videos)): ?>
                                <div class="overflow-auto rounded-xl border border-gray-200">
                                    <table class="min-w-full text-left text-sm">
                                        <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                        <tr>
                                            <th class="px-4 py-3">顺序</th>
                                            <th class="px-4 py-3">名称</th>
                                            <th class="px-4 py-3">路径</th>
                                            <th class="px-4 py-3 text-right">操作</th>
                                        </tr>
                                        </thead>
                                        <tbody class="divide-y divide-gray-100 bg-white">
                                        <?php foreach ($episodesForPanel as $ep): ?>
                                            <tr class="hover:bg-gray-50/60">
                                                <td class="px-4 py-3 text-gray-700"><?= (int)($ep['episode_order'] ?? 0) ?></td>
                                                <td class="px-4 py-3 font-semibold text-gray-900"><?= htmlspecialchars((string)($ep['episode_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                                <td class="px-4 py-3">
                                                    <span class="block max-w-[520px] truncate text-xs text-gray-600"><?= htmlspecialchars((string)($ep['video_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                                </td>
                                                <td class="px-4 py-3">
                                                    <div class="flex justify-end gap-2">
                                                        <button type="button"
                                                                class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700 js-edit-episode"
                                                                data-episode-id="<?= (int)$ep['id'] ?>"
                                                                data-episode-name="<?= htmlspecialchars((string)($ep['episode_name'] ?? ''), ENT_QUOTES, 'UTF-8') ?>"
                                                                data-episode-url="<?= htmlspecialchars((string)($ep['video_url'] ?? ''), ENT_QUOTES, 'UTF-8') ?>">
                                                            编辑
                                                        </button>
                                                        <form method="POST" class="inline">
                                                            <input type="hidden" name="action" value="delete_episode">
                                                            <input type="hidden" name="episode_id" value="<?php echo (int)$ep['id']; ?>">
                                                            <input type="hidden" name="redirect" value="videos.php?section=episodes&amp;video_id=<?= (int)$activeVideoId ?>">
                                                            <button type="submit" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700" onclick="return confirm('确定删除？')">删除</button>
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

                <?php else: ?>
                    <div class="grid gap-4 lg:grid-cols-3">
                        <section class="lg:col-span-1">
                            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden lg:sticky lg:top-4">
                                <div class="border-b border-gray-100 px-5 py-3">
                                    <p class="text-sm font-semibold text-gray-900">添加视频</p>
                                    <p class="mt-1 text-xs text-gray-500">建议先创建视频，再去“集数管理”添加播放路径。</p>
                                </div>
                                <form method="POST" class="px-5 py-4 space-y-4">
                                    <input type="hidden" name="action" value="add_video">
                                    <input type="hidden" name="redirect" value="videos.php?section=videos">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700">视频标题</label>
                                        <input type="text" name="title" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700">视频描述</label>
                                        <textarea name="description" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm leading-relaxed focus:border-blue-500 focus:outline-none"></textarea>
                                    </div>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700">封面图片 URL</label>
                                        <input type="text" name="cover" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                                    </div>
                                    <?php if ($videoSgFeature): ?>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700">服务器分组</label>
                                        <select name="server_group_id" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                                            <option value="">不指定（全部线路可用）</option>
                                            <?php foreach ($serverGroups as $sg): ?>
                                            <option value="<?php echo (int)$sg['id']; ?>" <?= $defaultVideoServerGroupId === (int)$sg['id'] ? 'selected' : '' ?>><?php echo htmlspecialchars($sg['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                                            <?php endforeach; ?>
                                        </select>
                                        <p class="mt-1 text-xs text-gray-500">默认使用「域名管理 → 上传域名组」配置；指定后播放页将优先展示该组下的域名线路。</p>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($videoTrafficFeature): ?>
                                    <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 space-y-3">
                                        <label class="flex items-center gap-2 text-sm font-semibold text-amber-800">
                                            <input type="checkbox" name="is_traffic" id="add_is_traffic" value="1" onchange="toggleAddTraffic(this)">
                                            设置为流量解锁视频
                                        </label>
                                        <div id="add_traffic_panel" class="hidden space-y-2">
                                            <div>
                                                <label class="mb-1 block text-xs text-gray-700">解锁所需流量</label>
                                                <input type="number" name="traffic_cost" min="0" value="0" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                                            </div>
                                        </div>
                                        <p class="text-xs text-amber-800/90">解锁有效期跟随用户流量周期；流量被重置时解锁失效，需重新支付。</p>
                                    </div>
                                    <?php endif; ?>
                                    <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">添加视频</button>
                                </form>
                            </div>
                        </section>

                        <section class="lg:col-span-2">
                            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                                <div class="border-b border-gray-100 px-5 py-3">
                                    <p class="text-sm font-semibold text-gray-900">视频列表</p>
                                    <p class="mt-1 text-xs text-gray-500">共 <?= (int)$videoCount ?> 条。</p>
                                </div>
                                <?php if (empty($videos)): ?>
                                    <div class="px-5 py-10 text-center">
                                        <p class="text-sm font-semibold text-gray-900">还没有视频</p>
                                        <p class="mt-1 text-xs text-gray-500">先在左侧“添加视频”创建第一条。</p>
                                    </div>
                                <?php else: ?>
                                    <div class="overflow-auto">
                                        <table class="min-w-full text-left text-sm">
                                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                            <tr>
                                                <th class="px-4 py-3">标题</th>
                                                <th class="px-4 py-3">集数</th>
                                                <?php if ($videoSgFeature): ?><th class="px-4 py-3">服务器组</th><?php endif; ?>
                                                <?php if ($videoTrafficFeature): ?><th class="px-4 py-3">流量</th><?php endif; ?>
                                                <th class="px-4 py-3 text-right">操作</th>
                                            </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100 bg-white">
                                            <?php foreach ($videos as $video): ?>
                                                <tr class="hover:bg-gray-50/60">
                                                    <td class="px-4 py-3">
                                                        <p class="font-semibold text-gray-900"><?php echo htmlspecialchars((string)($video['title'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></p>
                                                        <?php if (!empty($video['cover'])): ?>
                                                            <a class="mt-1 inline-block text-xs text-blue-700 hover:underline" href="<?php echo htmlspecialchars((string)$video['cover'], ENT_QUOTES, 'UTF-8'); ?>" target="_blank" rel="noreferrer">封面</a>
                                                        <?php endif; ?>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-700"><?php echo (int)($video['episode_count'] ?? 0); ?></td>
                                                    <?php if ($videoSgFeature): ?>
                                                        <td class="px-4 py-3 text-gray-700"><?php echo htmlspecialchars((string)($video['server_group_name'] ?? '未指定'), ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <?php endif; ?>
                                                    <?php if ($videoTrafficFeature): ?>
                                                        <td class="px-4 py-3">
                                                            <?php if (!empty($video['is_traffic'])): ?>
                                                                <span class="inline-flex items-center rounded-full bg-amber-100 px-2 py-0.5 text-xs font-semibold text-amber-800">流量 <?= (int)($video['traffic_cost'] ?? 0) ?></span>
                                                            <?php else: ?>
                                                                <span class="text-xs text-gray-400">—</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endif; ?>
                                                    <td class="px-4 py-3">
                                                        <div class="flex justify-end gap-2">
                                                            <a class="rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-black"
                                                               href="videos.php?section=episodes&amp;video_id=<?php echo (int)$video['id']; ?>">集数</a>
                                                            <button type="button" class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700"
                                                                    onclick='showEditVideo(<?php echo json_encode([
                                                                        'id' => (int)$video['id'],
                                                                        'title' => $video['title'] ?? '',
                                                                        'description' => $video['description'] ?? '',
                                                                        'cover' => $video['cover'] ?? '',
                                                                        'server_group_id' => ($videoSgFeature && isset($video['server_group_id']) && $video['server_group_id'] !== null && $video['server_group_id'] !== '') ? (int)$video['server_group_id'] : null,
                                                                        'is_traffic' => $videoTrafficFeature ? (int)($video['is_traffic'] ?? 0) : 0,
                                                                        'traffic_cost' => $videoTrafficFeature ? (int)($video['traffic_cost'] ?? 0) : 0,
                                                                        'unlock_validity_minutes' => $videoTrafficFeature ? (int)($video['unlock_validity_minutes'] ?? 0) : 0,
                                                                        'refresh_limit' => $videoTrafficFeature ? (int)($video['refresh_limit'] ?? 0) : 0,
                                                                    ], JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>)'>编辑</button>
                                                            <form method="POST" class="inline">
                                                                <input type="hidden" name="action" value="delete_video">
                                                                <input type="hidden" name="video_id" value="<?php echo (int)$video['id']; ?>">
                                                                <input type="hidden" name="redirect" value="videos.php?section=videos">
                                                                <button type="submit" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700" onclick="return confirm('确定删除？')">删除</button>
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
                        </section>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <?php if ($activeSection === 'episodes' && !empty($videos)): ?>
    <!-- 选择视频弹窗 -->
    <div id="videoPickerModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50 px-4" style="display:none;">
        <div class="relative w-full max-w-2xl overflow-hidden rounded-2xl bg-white shadow-xl">
            <div class="flex items-center justify-between border-b border-gray-100 px-5 py-4">
                <div>
                    <h2 class="text-base font-semibold text-gray-900">选择视频</h2>
                    <p class="mt-1 text-xs text-gray-500">格式：视频缩略图 -- 视频名字 -- 已有集数</p>
                </div>
                <button class="rounded-full px-2 text-2xl leading-none text-gray-400 hover:bg-gray-100 hover:text-gray-700"
                        type="button"
                        onclick="closeVideoPickerModal()"
                        aria-label="关闭">&times;</button>
            </div>
            <div class="max-h-[70vh] overflow-y-auto p-4">
                <div class="grid gap-3">
                    <?php foreach ($videos as $v): ?>
                        <?php
                        $vid = (int)($v['id'] ?? 0);
                        $cover = trim((string)($v['cover'] ?? ''));
                        $title = (string)($v['title'] ?? '');
                        $count = (int)($v['episode_count'] ?? 0);
                        $isCurrent = $vid === (int)$activeVideoId;
                        ?>
                        <button type="button"
                                class="group flex w-full items-center gap-3 rounded-xl border px-3 py-3 text-left transition hover:border-blue-200 hover:bg-blue-50/60 <?= $isCurrent ? 'border-blue-200 bg-blue-50 ring-1 ring-blue-100' : 'border-gray-200 bg-white' ?>"
                                onclick="selectEpisodeVideo(<?= $vid ?>)">
                            <span class="h-16 w-24 shrink-0 overflow-hidden rounded-lg bg-gray-100 ring-1 ring-gray-200">
                                <?php if ($cover !== ''): ?>
                                    <img src="<?= htmlspecialchars($cover, ENT_QUOTES, 'UTF-8') ?>"
                                         alt=""
                                         class="h-full w-full object-cover"
                                         loading="lazy"
                                         onerror="this.closest('span').innerHTML='<span class=&quot;flex h-full w-full items-center justify-center text-xs text-gray-400&quot;>无封面</span>'">
                                <?php else: ?>
                                    <span class="flex h-full w-full items-center justify-center text-xs text-gray-400">无封面</span>
                                <?php endif; ?>
                            </span>
                            <span class="min-w-0 flex-1">
                                <span class="block truncate text-sm font-semibold text-gray-900">
                                    <?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?>
                                </span>
                                <span class="mt-1 block text-xs text-gray-500">#<?= $vid ?></span>
                            </span>
                            <span class="shrink-0 rounded-full bg-gray-100 px-3 py-1 text-xs font-semibold text-gray-700 group-hover:bg-white">
                                已有 <?= $count ?> 集
                            </span>
                        </button>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </div>
    <?php endif; ?>
    
    <!-- 编辑视频模态框 -->
    <div id="editVideoModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50" style="display:none;">
        <div class="relative w-full max-w-md rounded-2xl bg-white p-5 shadow">
            <button class="absolute right-3 top-2 text-xl text-gray-400 hover:text-gray-700" onclick="closeEditVideoModal()" aria-label="关闭">&times;</button>
            <form method="POST" id="editVideoForm" class="space-y-3">
                <input type="hidden" name="action" value="edit_video">
                <input type="hidden" name="video_id" id="edit_video_id">
                <input type="hidden" name="redirect" value="videos.php?section=videos">
                <h2 class="text-base font-semibold text-gray-900">编辑视频</h2>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">视频标题</label>
                    <input type="text" name="title" id="edit_video_title" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">视频描述</label>
                    <textarea name="description" id="edit_video_description" rows="3" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm leading-relaxed focus:border-blue-500 focus:outline-none"></textarea>
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">封面图片 URL</label>
                    <input type="text" name="cover" id="edit_video_cover" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                </div>
                <?php if ($videoSgFeature): ?>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">服务器分组</label>
                    <select name="server_group_id" id="edit_video_server_group_id" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                        <option value="">不指定（全部线路可用）</option>
                        <?php foreach ($serverGroups as $sg): ?>
                        <option value="<?php echo (int)$sg['id']; ?>"><?php echo htmlspecialchars($sg['name'], ENT_QUOTES, 'UTF-8'); ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <?php endif; ?>
                <?php if ($videoTrafficFeature): ?>
                <div class="rounded-xl border border-amber-200 bg-amber-50 p-3 space-y-3">
                    <label class="flex items-center gap-2 text-sm font-semibold text-amber-800">
                        <input type="checkbox" name="is_traffic" id="edit_is_traffic" value="1" onchange="toggleEditTraffic(this)">
                        设置为流量解锁视频
                    </label>
                    <div id="edit_traffic_panel" class="hidden space-y-2">
                        <div>
                            <label class="mb-1 block text-xs text-gray-700">解锁所需流量</label>
                            <input type="number" name="traffic_cost" id="edit_traffic_cost" min="0" value="0" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                        </div>
                    </div>
                    <p class="text-xs text-amber-800/90">解锁有效期跟随用户流量周期。</p>
                </div>
                <?php endif; ?>
                <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">保存</button>
            </form>
        </div>
    </div>
    
    <!-- 编辑集数模态框 -->
    <div id="editEpisodeModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50" style="display:none;">
        <div class="relative w-full max-w-md rounded-2xl bg-white p-5 shadow">
            <button class="absolute right-3 top-2 text-xl text-gray-400 hover:text-gray-700" onclick="closeEditEpisodeModal()" aria-label="关闭">&times;</button>
            <form method="POST" id="editEpisodeForm" class="space-y-3">
                <input type="hidden" name="action" value="edit_episode">
                <input type="hidden" name="episode_id" id="edit_episode_id">
                <input type="hidden" name="redirect" id="edit_episode_redirect" value="">
                <h2 class="text-base font-semibold text-gray-900">编辑集数</h2>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">集数名称</label>
                    <input type="text" name="episode_name" id="edit_episode_name" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">视频链接</label>
                    <input type="text" name="video_url" id="edit_episode_url" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                </div>
                <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">保存</button>
            </form>
        </div>
    </div>
    
    <script>
        var episodeRedirect = <?php echo json_encode('videos.php?section=episodes&video_id=' . (int)$activeVideoId, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
        var defaultVideoServerGroupId = <?php echo $defaultVideoServerGroupId !== null ? (int)$defaultVideoServerGroupId : 'null'; ?>;

        function showEditVideo(data) {
            document.getElementById('edit_video_id').value = data.id;
            document.getElementById('edit_video_title').value = data.title || '';
            document.getElementById('edit_video_description').value = data.description || '';
            document.getElementById('edit_video_cover').value = data.cover || '';
            var sg = document.getElementById('edit_video_server_group_id');
            if (sg) {
                var sgVal = data.server_group_id;
                if (sgVal === null || sgVal === undefined || sgVal === '') {
                    sgVal = defaultVideoServerGroupId !== null ? defaultVideoServerGroupId : '';
                }
                sg.value = sgVal === '' ? '' : String(sgVal);
            }
            var isT = document.getElementById('edit_is_traffic');
            if (isT) {
                isT.checked = !!data.is_traffic;
                document.getElementById('edit_traffic_cost').value = data.traffic_cost || 0;
                toggleEditTraffic(isT);
            }
            document.getElementById('editVideoModal').style.display = 'flex';
        }

        function toggleAddTraffic(el) {
            var p = document.getElementById('add_traffic_panel');
            if (!p) return;
            p.classList.toggle('hidden', !el.checked);
        }
        function toggleEditTraffic(el) {
            var p = document.getElementById('edit_traffic_panel');
            if (!p) return;
            p.classList.toggle('hidden', !el.checked);
        }

        function openVideoPickerModal() {
            var modal = document.getElementById('videoPickerModal');
            if (modal) modal.style.display = 'flex';
        }

        function closeVideoPickerModal() {
            var modal = document.getElementById('videoPickerModal');
            if (modal) modal.style.display = 'none';
        }

        function selectEpisodeVideo(videoId) {
            var input = document.getElementById('episodeVideoIdInput');
            if (input) input.value = String(videoId);
            window.location.href = 'videos.php?section=episodes&video_id=' + encodeURIComponent(videoId);
        }
        
        function closeEditVideoModal() {
            document.getElementById('editVideoModal').style.display = 'none';
        }
        
        function showEditEpisode(id, name, url) {
            document.getElementById('edit_episode_id').value = id;
            document.getElementById('edit_episode_name').value = name;
            document.getElementById('edit_episode_url').value = url;
            var red = document.getElementById('edit_episode_redirect');
            if (red) red.value = episodeRedirect;
            document.getElementById('editEpisodeModal').style.display = 'flex';
        }

        document.querySelectorAll('.js-edit-episode').forEach(function (btn) {
            btn.addEventListener('click', function () {
                showEditEpisode(
                    parseInt(btn.getAttribute('data-episode-id') || '0', 10),
                    btn.getAttribute('data-episode-name') || '',
                    btn.getAttribute('data-episode-url') || ''
                );
            });
        });
        
        function closeEditEpisodeModal() {
            document.getElementById('editEpisodeModal').style.display = 'none';
        }
        
        window.onclick = function(event) {
            const modals = ['videoPickerModal', 'editVideoModal', 'editEpisodeModal'];
            modals.forEach(modalId => {
                const modal = document.getElementById(modalId);
                if (event.target == modal) {
                    if (modalId === 'videoPickerModal') closeVideoPickerModal();
                    if (modalId === 'editVideoModal') closeEditVideoModal();
                    if (modalId === 'editEpisodeModal') closeEditEpisodeModal();
                }
            });
        }
    </script>
</body>
</html>

