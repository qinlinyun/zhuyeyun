<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/flash.php';

requireAdmin();
$pdo = getDB();
$message = '';
$error = '';
$success = $_GET['success'] ?? '';
if ($success === 'replied') {
    $message = '回复已发送';
}
applyFlash($message, $error);

// 页面分区（左侧菜单）
$activeSection = trim((string)($_GET['section'] ?? ''));
if (!in_array($activeSection, ['overview', 'pending', 'done', 'logs'], true)) {
    $activeSection = 'overview';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $feedbackId = (int)($_POST['feedback_id'] ?? 0);
    $content = trim($_POST['reply_content'] ?? '');
    $redirect = trim((string)($_POST['redirect'] ?? ''));
    if ($redirect === '' || strpos($redirect, 'feedback.php') !== 0) {
        $redirect = 'feedback.php?section=' . urlencode($activeSection);
    }

    if ($feedbackId <= 0 || $content === '') {
        $error = '回复内容不能为空';
    } else {
        $stmt = $pdo->prepare("SELECT id FROM feedbacks WHERE id = ?");
        $stmt->execute([$feedbackId]);
        if (!$stmt->fetch()) {
            $error = '反馈记录不存在';
        } else {
            $stmt = $pdo->prepare("INSERT INTO feedback_replies (feedback_id, user_id, role, content) VALUES (?, ?, 'admin', ?)");
            $stmt->execute([$feedbackId, $_SESSION['user_id'], $content]);
            $pdo->prepare("UPDATE feedbacks SET status='replied' WHERE id=?")->execute([$feedbackId]);
            finishPostRequest('回复已发送', null, $redirect);
        }
    }

    if ($error) {
        finishPostRequest(null, $error, $redirect);
    }
}

$openCount = 0;
$repliedCount = 0;
try {
    $rows = $pdo->query("SELECT status, COUNT(*) AS c FROM feedbacks GROUP BY status")->fetchAll();
    foreach ($rows as $r) {
        if (($r['status'] ?? '') === 'open') $openCount = (int)$r['c'];
        if (($r['status'] ?? '') === 'replied') $repliedCount = (int)$r['c'];
    }
} catch (Throwable $e) {
    $openCount = 0;
    $repliedCount = 0;
}

$where = '';
$params = [];
if ($activeSection === 'pending') {
    $where = "WHERE f.status = 'open'";
} elseif ($activeSection === 'done') {
    $where = "WHERE f.status = 'replied'";
}

$feedbacks = $pdo->prepare("
    SELECT f.*, u.username
    FROM feedbacks f
    LEFT JOIN users u ON f.user_id = u.id
    {$where}
    ORDER BY f.updated_at DESC
    LIMIT 200
");
$feedbacks->execute($params);
$feedbacks = $feedbacks->fetchAll();

$adminReplyLogs = [];
if ($activeSection === 'logs') {
    try {
        $adminReplyLogs = $pdo->query("
            SELECT r.*, au.username AS admin_username, fu.username AS feedback_username, f.title AS feedback_title, f.status AS feedback_status
            FROM feedback_replies r
            LEFT JOIN users au ON r.user_id = au.id
            LEFT JOIN feedbacks f ON r.feedback_id = f.id
            LEFT JOIN users fu ON f.user_id = fu.id
            WHERE r.role = 'admin'
            ORDER BY r.created_at DESC
            LIMIT 200
        ")->fetchAll();
    } catch (Throwable $e) {
        $adminReplyLogs = [];
    }
}

$feedbackIds = array_column($feedbacks, 'id');
$repliesByFeedback = [];
if (!empty($feedbackIds)) {
    $placeholders = implode(',', array_fill(0, count($feedbackIds), '?'));
    $stmt = $pdo->prepare("
        SELECT r.*, u.username
        FROM feedback_replies r
        LEFT JOIN users u ON r.user_id = u.id
        WHERE r.feedback_id IN ($placeholders)
        ORDER BY r.created_at ASC
    ");
    $stmt->execute($feedbackIds);
    foreach ($stmt->fetchAll() as $reply) {
        $repliesByFeedback[$reply['feedback_id']][] = $reply;
    }
}

$assetPrefix = '../';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <title>意见反馈管理</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $themeAssetPrefix = '../'; include __DIR__ . '/../components/theme-head.php'; ?>
    <?php include __DIR__ . '/../components/theme-dynamic.php'; ?>
</head>
<body class="bg-gray-100 text-gray-900">
<?php $adminNavActive = 'feedback'; include __DIR__ . '/../components/admin-top-nav.php'; ?>

<main class="mx-auto max-w-screen-xl px-4 py-6 space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-lg font-semibold text-gray-900">意见反馈</h1>
            <p class="mt-1 text-xs text-gray-500">按模块处理：待处理 / 已处理 / 处理记录。</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-2 rounded-full bg-amber-50 px-3 py-1 text-amber-700 ring-1 ring-amber-100">
                <span class="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                待处理 <?= (int)$openCount ?>
            </span>
            <span class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-blue-700 ring-1 ring-blue-100">
                <span class="h-1.5 w-1.5 rounded-full bg-blue-500" aria-hidden="true"></span>
                已处理 <?= (int)$repliedCount ?>
            </span>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
            <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
            <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
        </div>
    <?php endif; ?>

    <div class="flex gap-4 items-start">
        <?php include __DIR__ . '/../components/admin-feedback-sidebar.php'; ?>

        <section class="min-w-0 flex-1 space-y-6">
            <?php if ($activeSection === 'overview'): ?>
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <p class="text-sm font-semibold text-gray-900">总览</p>
                        <p class="mt-1 text-xs text-gray-500">从左侧选择模块进入处理。</p>
                    </div>
                    <div class="px-5 py-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <a href="?section=pending" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                            <p class="text-sm font-semibold text-gray-900">待处理</p>
                            <p class="mt-1 text-xs text-gray-500">open 反馈（<?= (int)$openCount ?>）</p>
                        </a>
                        <a href="?section=done" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                            <p class="text-sm font-semibold text-gray-900">已处理</p>
                            <p class="mt-1 text-xs text-gray-500">已回复（<?= (int)$repliedCount ?>）</p>
                        </a>
                        <a href="?section=logs" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                            <p class="text-sm font-semibold text-gray-900">处理记录</p>
                            <p class="mt-1 text-xs text-gray-500">管理员回复流水</p>
                        </a>
                    </div>
                </div>

            <?php elseif ($activeSection === 'logs'): ?>
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <p class="text-sm font-semibold text-gray-900">处理记录</p>
                        <p class="mt-1 text-xs text-gray-500">最近 200 条管理员回复。</p>
                    </div>
                    <?php if (empty($adminReplyLogs)): ?>
                        <div class="px-5 py-10 text-center">
                            <p class="text-sm font-semibold text-gray-900">暂无记录</p>
                            <p class="mt-1 text-xs text-gray-500">当管理员回复反馈后，这里会显示处理流水。</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-4 py-3">时间</th>
                                    <th class="px-4 py-3">反馈</th>
                                    <th class="px-4 py-3">用户</th>
                                    <th class="px-4 py-3">管理员</th>
                                    <th class="px-4 py-3">内容</th>
                                </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                <?php foreach ($adminReplyLogs as $log): ?>
                                    <tr class="hover:bg-gray-50/60">
                                        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars((string)($log['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3">
                                            <a class="text-xs font-semibold text-blue-700 hover:underline" href="feedback.php?section=done#fb-<?= (int)($log['feedback_id'] ?? 0) ?>">
                                                #<?= (int)($log['feedback_id'] ?? 0) ?> <?= htmlspecialchars((string)($log['feedback_title'] ?? '未命名'), ENT_QUOTES, 'UTF-8') ?>
                                            </a>
                                            <div class="mt-1 text-xs text-gray-500">状态：<?= htmlspecialchars((string)($log['feedback_status'] ?? ''), ENT_QUOTES, 'UTF-8') ?></div>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($log['feedback_username'] ?? '未知'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($log['admin_username'] ?? 'admin'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3">
                                            <span class="block max-w-[520px] truncate text-xs text-gray-600"><?= htmlspecialchars((string)($log['content'] ?? ''), ENT_QUOTES, 'UTF-8') ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>

            <?php else: ?>
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <p class="text-sm font-semibold text-gray-900"><?= $activeSection === 'pending' ? '待处理' : '已处理' ?></p>
                        <p class="mt-1 text-xs text-gray-500">点击“查看/回复”在弹窗中处理，页面保持清爽。</p>
                    </div>
                    <?php if (empty($feedbacks)): ?>
                        <div class="px-5 py-10 text-center">
                            <p class="text-sm font-semibold text-gray-900">暂无反馈</p>
                            <p class="mt-1 text-xs text-gray-500">当前模块下没有记录。</p>
                        </div>
                    <?php else: ?>
                        <div class="overflow-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-4 py-3">反馈</th>
                                    <th class="px-4 py-3">用户</th>
                                    <th class="px-4 py-3">时间</th>
                                    <th class="px-4 py-3">状态</th>
                                    <th class="px-4 py-3 text-right">操作</th>
                                </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white">
                                <?php foreach ($feedbacks as $fb): ?>
                                    <?php
                                    $st = (string)($fb['status'] ?? '');
                                    $stBadge = $st === 'open'
                                        ? 'bg-amber-100 text-amber-800'
                                        : ($st === 'replied' ? 'bg-blue-100 text-blue-700' : 'bg-gray-100 text-gray-600');
                                    $replies = $repliesByFeedback[$fb['id']] ?? [];
                                    ?>
                                    <tr id="fb-<?= (int)$fb['id'] ?>" class="hover:bg-gray-50/60">
                                        <td class="px-4 py-3">
                                            <p class="font-semibold text-gray-900"><?= htmlspecialchars((string)($fb['title'] ?: '未命名反馈'), ENT_QUOTES, 'UTF-8') ?></p>
                                            <p class="mt-1 text-xs text-gray-500 line-clamp-2"><?= htmlspecialchars((string)($fb['content'] ?? ''), ENT_QUOTES, 'UTF-8') ?></p>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)($fb['username'] ?? '未知'), ENT_QUOTES, 'UTF-8') ?> (#<?= (int)($fb['user_id'] ?? 0) ?>)</td>
                                        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars((string)($fb['created_at'] ?? ''), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3">
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold <?= $stBadge ?>">
                                                <?= htmlspecialchars($st, ENT_QUOTES, 'UTF-8') ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3">
                                            <div class="flex justify-end">
                                                <button
                                                    type="button"
                                                    class="rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-black"
                                                    onclick='openFeedbackModal(<?php echo json_encode([
                                                        "id" => (int)$fb["id"],
                                                        "title" => (string)($fb["title"] ?? ""),
                                                        "content" => (string)($fb["content"] ?? ""),
                                                        "status" => (string)($fb["status"] ?? ""),
                                                        "username" => (string)($fb["username"] ?? ""),
                                                        "user_id" => (int)($fb["user_id"] ?? 0),
                                                        "created_at" => (string)($fb["created_at"] ?? ""),
                                                        "image_path" => (string)($fb["image_path"] ?? ""),
                                                        "replies" => $replies,
                                                    ], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?>)'>
                                                    查看 / 回复
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
            <?php endif; ?>
        </section>
    </div>
</main>

<!-- 回复弹窗 -->
<div id="fbModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50">
    <div class="relative w-full max-w-2xl rounded-2xl bg-white p-5 shadow">
        <button class="absolute right-3 top-2 text-xl text-gray-400 hover:text-gray-700" onclick="closeFeedbackModal()" aria-label="关闭">&times;</button>
        <div id="fbModalBody"></div>
    </div>
</div>

<script>
var feedbackRedirect = <?php echo json_encode('feedback.php?section=' . $activeSection, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?>;

function escapeHtml(str){
    return String(str ?? '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function closeFeedbackModal() {
    document.getElementById('fbModal').style.display = 'none';
}

function openFeedbackModal(fb) {
    const modal = document.getElementById('fbModal');
    const body = document.getElementById('fbModalBody');
    const id = Number(fb?.id || 0);
    const title = escapeHtml(fb?.title || '未命名反馈');
    const content = escapeHtml(fb?.content || '');
    const username = escapeHtml(fb?.username || '未知');
    const createdAt = escapeHtml(fb?.created_at || '');
    const status = escapeHtml(fb?.status || '');
    const userId = Number(fb?.user_id || 0);
    const imagePath = String(fb?.image_path || '');
    const replies = Array.isArray(fb?.replies) ? fb.replies : [];

    let repliesHtml = '';
    if (replies.length) {
        repliesHtml = replies.map(r => {
            const who = (r.role === 'admin') ? '管理员' : escapeHtml(r.username || '用户');
            const when = escapeHtml(r.created_at || '');
            const msg = escapeHtml(r.content || '').replace(/\n/g, '<br/>');
            return `
                <div class="rounded-xl border border-gray-100 bg-gray-50 p-3 text-sm">
                    <div class="text-xs text-gray-500">${who}<span class="ml-2">${when}</span></div>
                    <div class="mt-1 text-gray-700">${msg}</div>
                </div>
            `;
        }).join('');
    } else {
        repliesHtml = `<div class="rounded-xl border border-dashed border-gray-200 bg-gray-50 px-4 py-5 text-center text-xs text-gray-500">暂无对话记录</div>`;
    }

    let imgHtml = '';
    if (imagePath) {
        const src = <?php echo json_encode($assetPrefix, JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?> + imagePath;
        imgHtml = `
            <div class="mt-3">
                <img src="${escapeHtml(src)}" alt="反馈图片" class="max-h-64 rounded-xl border border-gray-200">
            </div>
        `;
    }

    body.innerHTML = `
        <div class="space-y-4">
            <div>
                <h2 class="text-base font-semibold text-gray-900">${title}</h2>
                <p class="mt-1 text-xs text-gray-500">${username} (#${userId}) · ${createdAt} · 状态：${status}</p>
                <div class="mt-3 rounded-xl border border-gray-200 bg-white px-4 py-3 text-sm text-gray-700 whitespace-pre-line">${content.replace(/\n/g,'<br/>')}</div>
                ${imgHtml}
            </div>

            <div class="space-y-2">
                <p class="text-sm font-semibold text-gray-900">对话</p>
                <div class="space-y-2">${repliesHtml}</div>
            </div>

            <form method="POST" class="pt-2 border-t border-gray-100 space-y-2">
                <input type="hidden" name="feedback_id" value="${id}">
                <input type="hidden" name="redirect" value="${escapeHtml(feedbackRedirect)}">
                <textarea name="reply_content" rows="3" required class="w-full rounded-xl border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none" placeholder="回复用户..."></textarea>
                <button type="submit" class="w-full rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-black">发送回复</button>
            </form>
        </div>
    `;

    modal.style.display = 'flex';
}

window.addEventListener('click', function(e){
    const m = document.getElementById('fbModal');
    if (e.target === m) closeFeedbackModal();
});
</script>
</body>
</html>