<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/flash.php';

requireAdmin();
$pdo = getDB();

$message = '';
$error = '';
$success = $_GET['success'] ?? '';
if ($success === 'created') {
    $message = '通知已发布';
} elseif ($success === 'deleted') {
    $message = '通知已删除';
}
applyFlash($message, $error);

/**
 * mbstring 未启用时的降级：仅用于展示截断预览
 */
function notif_str_len(string $s): int
{
    if (function_exists('mb_strlen')) {
        return (int)mb_strlen($s, 'UTF-8');
    }
    return strlen($s);
}

function notif_str_sub(string $s, int $start, int $len): string
{
    if (function_exists('mb_substr')) {
        return (string)mb_substr($s, $start, $len, 'UTF-8');
    }
    return substr($s, $start, $len);
}

// 列表筛选（GET）
$q = trim((string)($_GET['q'] ?? ''));
$filterType = trim((string)($_GET['type'] ?? ''));
if (!in_array($filterType, ['', 'all', 'user'], true)) {
    $filterType = '';
}
$listLimit = (int)($_GET['limit'] ?? 200);
$listLimit = max(20, min(500, $listLimit));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'create';

    if ($action === 'delete') {
        $notificationId = (int)($_POST['notification_id'] ?? 0);
        if ($notificationId <= 0) {
            $error = '通知不存在';
        } else {
            $stmt = $pdo->prepare("DELETE FROM notification_reads WHERE notification_id = ?");
            $stmt->execute([$notificationId]);
            $stmt = $pdo->prepare("DELETE FROM notifications WHERE id = ?");
            $stmt->execute([$notificationId]);
            header('Location: notifications.php?success=deleted');
            exit;
        }
    } else {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $targetType = $_POST['target_type'] ?? 'all';
        $targetUserId = (int)($_POST['target_user_id'] ?? 0);

        if ($title === '' || $content === '') {
            $error = '标题和内容不能为空';
        } elseif ($targetType === 'user' && $targetUserId <= 0) {
            $error = '请选择要通知的用户';
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO notifications (title, content, target_type, target_user_id, created_by)
                VALUES (?, ?, ?, ?, ?)
            ");
            $stmt->execute([
                $title,
                $content,
                $targetType,
                $targetType === 'user' ? $targetUserId : null,
                $_SESSION['user_id']
            ]);
            header('Location: notifications.php?success=created');
            exit;
        }
    }

    if ($error) {
        flashSet('error', $error);
        header('Location: notifications.php');
        exit;
    }
}

$users = $pdo->query("SELECT id, username FROM users ORDER BY id DESC LIMIT 2000")->fetchAll();

$where = [];
$params = [];
if ($filterType !== '') {
    $where[] = 'n.target_type = ?';
    $params[] = $filterType;
}
if ($q !== '') {
    $like = '%' . $q . '%';
    $where[] = '(n.title LIKE ? OR n.content LIKE ? OR u.username LIKE ? OR tu.username LIKE ?)';
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
    $params[] = $like;
}

$sql = "
    SELECT n.*, u.username AS creator_name, tu.username AS target_name
    FROM notifications n
    LEFT JOIN users u ON n.created_by = u.id
    LEFT JOIN users tu ON n.target_user_id = tu.id
";
if ($where !== []) {
    $sql .= " WHERE " . implode(' AND ', $where);
}
$sql .= " ORDER BY n.created_at DESC LIMIT ?";

$stmt = $pdo->prepare($sql);
$bindIndex = 1;
foreach ($params as $p) {
    $stmt->bindValue($bindIndex++, $p, PDO::PARAM_STR);
}
$stmt->bindValue($bindIndex, $listLimit, PDO::PARAM_INT);
$stmt->execute();
$notifications = $stmt->fetchAll() ?: [];
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>站内通知管理</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php $themeAssetPrefix = '../'; include __DIR__ . '/../components/theme-head.php'; ?>

    <?php include __DIR__ . '/../components/theme-dynamic.php'; ?>
</head>
<body class="bg-gray-100 text-gray-900">
<?php $adminNavActive = 'notifications'; include __DIR__ . '/../components/admin-top-nav.php'; ?>

<main class="mx-auto max-w-screen-xl px-4 py-6">
    <?php
    $allCount = 0;
    $userCount = 0;
    foreach ($notifications as $n) {
        if (($n['target_type'] ?? '') === 'user') {
            $userCount++;
        } else {
            $allCount++;
        }
    }
    ?>

    <div class="mb-5 flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-lg font-semibold text-gray-900">站内通知</h1>
            <p class="mt-1 text-xs text-gray-500">发布全员或指定用户通知，并管理历史通知。</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-2 rounded-full bg-gray-900/90 px-3 py-1 text-white">
                <span class="h-1.5 w-1.5 rounded-full bg-white/70" aria-hidden="true"></span>
                当前显示 <?= (int)count($notifications) ?> 条
            </span>
            <span class="inline-flex items-center gap-2 rounded-full bg-green-50 px-3 py-1 text-green-700 ring-1 ring-green-100">
                <span class="h-1.5 w-1.5 rounded-full bg-green-500" aria-hidden="true"></span>
                全员 <?= (int)$allCount ?>
            </span>
            <span class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-blue-700 ring-1 ring-blue-100">
                <span class="h-1.5 w-1.5 rounded-full bg-blue-500" aria-hidden="true"></span>
                指定用户 <?= (int)$userCount ?>
            </span>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="mb-4 rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="mb-4 rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="grid gap-4 lg:grid-cols-3">
        <section class="lg:col-span-1">
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden lg:sticky lg:top-4">
                <div class="border-b border-gray-100 px-5 py-3">
                    <p class="text-sm font-semibold text-gray-900">发布通知</p>
                    <p class="mt-1 text-xs text-gray-500">支持全员或指定用户（单人）。</p>
                </div>
                <form method="POST" class="px-5 py-4 space-y-4" id="noticeCreateForm">
                    <input type="hidden" name="action" value="create">

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700" for="notice_title">标题</label>
                        <input id="notice_title" name="title" required
                               placeholder="例如：系统维护通知"
                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                    </div>

                    <div>
                        <label class="mb-1 block text-sm font-medium text-gray-700" for="notice_content">内容</label>
                        <textarea id="notice_content" name="content" rows="6" required
                                  placeholder="请输入通知内容（支持换行）"
                                  class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm leading-relaxed focus:border-blue-500 focus:outline-none"></textarea>
                    </div>

                    <div class="rounded-xl border border-gray-200 bg-gray-50 p-3">
                        <p class="text-xs font-semibold text-gray-600 uppercase tracking-wide">发送范围</p>
                        <div class="mt-2 flex flex-wrap items-center gap-4 text-sm">
                            <label class="inline-flex items-center gap-2">
                                <input type="radio" name="target_type" value="all" checked>
                                全员通知
                            </label>
                            <label class="inline-flex items-center gap-2">
                                <input type="radio" name="target_type" value="user">
                                指定用户
                            </label>
                        </div>
                        <div class="mt-3" id="targetUserBox">
                            <label class="mb-1 block text-xs text-gray-500" for="target_user_id">选择用户</label>
                            <select id="target_user_id" name="target_user_id"
                                    class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                                <option value="">请选择用户</option>
                                <?php foreach ($users as $u): ?>
                                    <option value="<?= (int)$u['id'] ?>"><?= htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8') ?> (#<?= (int)$u['id'] ?>)</option>
                                <?php endforeach; ?>
                            </select>
                            <p class="mt-1 text-[11px] text-gray-500">用户过多时建议按 ID 近似选择，后续可升级为搜索选择。</p>
                        </div>
                    </div>

                    <button class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
                        发布通知
                    </button>
                </form>
            </div>
        </section>

        <section class="lg:col-span-2">
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-3">
                    <div class="flex flex-wrap items-end justify-between gap-3">
                        <div>
                            <p class="text-sm font-semibold text-gray-900">通知列表</p>
                            <p class="mt-1 text-xs text-gray-500">按时间倒序，最多显示 <?= (int)$listLimit ?> 条。</p>
                        </div>
                        <form method="GET" class="flex flex-wrap items-end gap-2">
                            <div class="min-w-[220px]">
                                <label class="mb-1 block text-[11px] text-gray-500" for="q">搜索</label>
                                <input id="q" name="q" value="<?= htmlspecialchars($q, ENT_QUOTES, 'UTF-8') ?>"
                                       placeholder="标题 / 内容 / 发布者 / 目标用户"
                                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                            </div>
                            <div class="min-w-[120px]">
                                <label class="mb-1 block text-[11px] text-gray-500" for="type">类型</label>
                                <select id="type" name="type" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                                    <option value="" <?= $filterType === '' ? 'selected' : '' ?>>全部</option>
                                    <option value="all" <?= $filterType === 'all' ? 'selected' : '' ?>>全员</option>
                                    <option value="user" <?= $filterType === 'user' ? 'selected' : '' ?>>指定用户</option>
                                </select>
                            </div>
                            <div class="min-w-[110px]">
                                <label class="mb-1 block text-[11px] text-gray-500" for="limit">数量</label>
                                <select id="limit" name="limit" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                                    <?php foreach ([50, 100, 200, 500] as $opt): ?>
                                        <option value="<?= (int)$opt ?>" <?= $listLimit === (int)$opt ? 'selected' : '' ?>><?= (int)$opt ?></option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <button class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">
                                筛选
                            </button>
                            <a href="notifications.php"
                               class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">
                                重置
                            </a>
                        </form>
                    </div>
                </div>

                <?php if (empty($notifications)): ?>
                    <div class="px-5 py-10 text-center">
                        <div class="mx-auto inline-flex h-12 w-12 items-center justify-center rounded-2xl bg-gray-50 text-gray-400 border border-gray-200">
                            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" class="h-6 w-6" aria-hidden="true">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 6h16v12H4z"/>
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M4 7l8 6 8-6"/>
                            </svg>
                        </div>
                        <p class="mt-3 text-sm font-semibold text-gray-900">暂无通知</p>
                        <p class="mt-1 text-sm text-gray-500">你可以在左侧发布第一条通知。</p>
                    </div>
                <?php else: ?>
                    <div class="overflow-auto">
                        <table class="min-w-full text-sm">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                            <tr class="text-left">
                                <th class="px-5 py-3">标题 / 内容</th>
                                <th class="px-5 py-3">类型</th>
                                <th class="px-5 py-3">发布者</th>
                                <th class="px-5 py-3">时间</th>
                                <th class="px-5 py-3 text-right">操作</th>
                            </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                            <?php foreach ($notifications as $notice): ?>
                                <?php
                                $isUser = ($notice['target_type'] ?? '') === 'user';
                                $targetName = (string)($notice['target_name'] ?? '');
                                $creator = (string)($notice['creator_name'] ?? '系统');
                                $title = (string)($notice['title'] ?? '');
                                $content = (string)($notice['content'] ?? '');
                                $createdAt = (string)($notice['created_at'] ?? '');
                                $preview = notif_str_sub($content, 0, 120);
                                if (notif_str_len($content) > 120) {
                                    $preview .= '…';
                                }
                                ?>
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-5 py-4">
                                        <p class="font-semibold text-gray-900"><?= htmlspecialchars($title, ENT_QUOTES, 'UTF-8') ?></p>
                                        <details class="mt-1">
                                            <summary class="cursor-pointer select-none text-xs text-gray-500 hover:text-gray-700">
                                                <?= htmlspecialchars($preview, ENT_QUOTES, 'UTF-8') ?>
                                            </summary>
                                            <div class="mt-2 rounded-lg border border-gray-200 bg-white p-3 text-sm text-gray-700 whitespace-pre-line">
                                                <?= htmlspecialchars($content, ENT_QUOTES, 'UTF-8') ?>
                                            </div>
                                        </details>
                                    </td>
                                    <td class="px-5 py-4">
                                        <?php if ($isUser): ?>
                                            <span class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-xs font-medium text-blue-700 ring-1 ring-blue-100">
                                                指定用户
                                                <span class="text-blue-600/80">·</span>
                                                <?= htmlspecialchars($targetName !== '' ? $targetName : '未知', ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        <?php else: ?>
                                            <span class="inline-flex items-center gap-2 rounded-full bg-green-50 px-3 py-1 text-xs font-medium text-green-700 ring-1 ring-green-100">
                                                全员通知
                                            </span>
                                        <?php endif; ?>
                                    </td>
                                    <td class="px-5 py-4 text-gray-700">
                                        <?= htmlspecialchars($creator, ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-5 py-4 text-gray-500 whitespace-nowrap">
                                        <?= htmlspecialchars($createdAt, ENT_QUOTES, 'UTF-8') ?>
                                    </td>
                                    <td class="px-5 py-4 text-right">
                                        <form method="POST" onsubmit="return confirm('确定删除该通知吗？');" class="inline">
                                            <input type="hidden" name="action" value="delete">
                                            <input type="hidden" name="notification_id" value="<?= (int)$notice['id'] ?>">
                                            <button class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">
                                                删除
                                            </button>
                                        </form>
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
</main>
</body>
</html>

<script>
(() => {
    const form = document.getElementById('noticeCreateForm');
    if (!form) return;
    const radios = Array.from(form.querySelectorAll('input[name="target_type"]'));
    const box = document.getElementById('targetUserBox');
    const select = document.getElementById('target_user_id');

    function sync() {
        const v = (radios.find(r => r.checked) || {}).value || 'all';
        const isUser = v === 'user';
        if (box) box.style.display = isUser ? '' : 'none';
        if (select) {
            select.disabled = !isUser;
            if (!isUser) select.value = '';
        }
    }

    radios.forEach(r => r.addEventListener('change', sync));
    sync();
})();
</script>