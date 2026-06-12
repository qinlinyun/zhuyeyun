<?php
$authPath = __DIR__ . '/includes/auth.php';
$configPath = __DIR__ . '/config/database.php';
if (!file_exists($authPath)) {
    $authPath = dirname(__DIR__) . '/includes/auth.php';
}
if (!file_exists($configPath)) {
    $configPath = dirname(__DIR__) . '/config/database.php';
}
if (!file_exists($authPath) || !file_exists($configPath)) {
    die('找不到认证或数据库配置文件，请确认 includes/auth.php 与 config/database.php 是否存在。');
}
require_once $authPath;
require_once $configPath;
require_once __DIR__ . '/includes/flash.php';
require_once __DIR__ . '/includes/feedback_upload.php';

requireLogin();
$user = getCurrentUser();
$pdo = getDB();

$message = '';
$error = '';
$success = $_GET['success'] ?? '';
if ($success === 'created') {
    $message = '反馈已提交';
} elseif ($success === 'replied') {
    $message = '回复已发送';
}
applyFlash($message, $error);
$uploadDir = __DIR__ . '/uploads/feedback';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $title = trim($_POST['title'] ?? '');
        $content = trim($_POST['content'] ?? '');
        $imagePath = null;

        if ($content === '') {
            $error = '请输入反馈内容';
        }

        if (!$error) {
            $uploadResult = saveFeedbackImage($_FILES['image'] ?? [], (int)$user['id'], $uploadDir);
            if ($uploadResult['error']) {
                $error = $uploadResult['error'];
            } else {
                $imagePath = $uploadResult['path'];
            }
        }

        if (!$error) {
            try {
                $stmt = $pdo->prepare("INSERT INTO feedbacks (user_id, title, content, image_path) VALUES (?, ?, ?, ?)");
                $stmt->execute([$user['id'], $title ?: null, $content, $imagePath]);
                header('Location: feedback.php?success=created');
                exit;
            } catch (Throwable $e) {
                deleteFeedbackImage($imagePath, __DIR__);
                $error = '反馈提交失败，请稍后重试';
            }
        }
    } elseif ($action === 'reply') {
        $feedbackId = (int)($_POST['feedback_id'] ?? 0);
        $replyContent = trim($_POST['reply_content'] ?? '');

        if ($feedbackId <= 0 || $replyContent === '') {
            $error = '回复内容不能为空';
        } else {
            $stmt = $pdo->prepare("SELECT id FROM feedbacks WHERE id = ? AND user_id = ?");
            $stmt->execute([$feedbackId, $user['id']]);
            if (!$stmt->fetch()) {
                $error = '无效的反馈记录';
            } else {
                $stmt = $pdo->prepare("INSERT INTO feedback_replies (feedback_id, user_id, role, content) VALUES (?, ?, 'user', ?)");
                $stmt->execute([$feedbackId, $user['id'], $replyContent]);
                $pdo->prepare("UPDATE feedbacks SET status='open' WHERE id=?")->execute([$feedbackId]);
                header('Location: feedback.php?success=replied');
                exit;
            }
        }
    }

    if ($error) {
        flashSet('error', $error);
        header('Location: feedback.php');
        exit;
    }
}

$stmt = $pdo->prepare("SELECT * FROM feedbacks WHERE user_id = ? ORDER BY updated_at DESC");
$stmt->execute([$user['id']]);
$feedbacks = $stmt->fetchAll();

$feedbackIds = array_column($feedbacks, 'id');

// 先标记管理员回复为已读，再查询，避免未读数不一致
if (!empty($feedbackIds)) {
    $placeholders = implode(',', array_fill(0, count($feedbackIds), '?'));
    $markStmt = $pdo->prepare("
        INSERT IGNORE INTO feedback_reply_reads (reply_id, user_id, read_at)
        SELECT r.id, ?, NOW()
        FROM feedback_replies r
        WHERE r.feedback_id IN ($placeholders) AND r.role = 'admin'
    ");
    $markStmt->execute(array_merge([(int)$user['id']], $feedbackIds));
}

$repliesByFeedback = [];
if (!empty($feedbackIds)) {
    $placeholders = implode(',', array_fill(0, count($feedbackIds), '?'));
    $stmt = $pdo->prepare("
        SELECT r.*, u.username,
               IF(rr.id IS NULL, 0, 1) AS is_read
        FROM feedback_replies r
        LEFT JOIN users u ON r.user_id = u.id
        LEFT JOIN feedback_reply_reads rr
          ON rr.reply_id = r.id AND rr.user_id = ?
        WHERE r.feedback_id IN ($placeholders)
        ORDER BY r.created_at ASC
    ");
    $stmt->execute(array_merge([(int)$user['id']], $feedbackIds));
    foreach ($stmt->fetchAll() as $reply) {
        $repliesByFeedback[$reply['feedback_id']][] = $reply;
    }
}

$assetPrefix = '';
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
    <meta charset="UTF-8">
    <title>意见反馈</title>
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php include __DIR__ . '/components/theme-head.php'; ?>

    <?php include __DIR__ . '/components/theme-dynamic.php'; ?>
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/animate.css@4.1.1/animate.min.css">
    <style>
        .card-hover{transition:transform .2s ease, box-shadow .2s ease;}
        .card-hover:hover{transform:translateY(-4px);box-shadow:0 12px 30px rgba(15,23,42,.12);}
        .pill{display:inline-flex;align-items:center;gap:6px;padding:4px 10px;border-radius:999px;font-size:12px;}
    </style>
</head>
<body class="bg-gray-100 text-gray-900">
<nav class="bg-white shadow-sm">
    <div class="mx-auto max-w-screen-xl px-4 py-3 flex gap-2 items-center">

        <!-- 首页 -->
        <a href="index.php" class="group rounded-full p-2 hover:bg-gray-100">
            <svg class="w-5 h-5 text-gray-500 group-hover:text-gray-900 transition"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                      d="M3 10l9-7 9 7v10a2 2 0 01-2 2h-4v-6H9v6H5a2 2 0 01-2-2z"/>
            </svg>
        </a>

        <!-- 站内通知 -->
        <a href="notifications.php" class="group rounded-full p-2 hover:bg-gray-100">
            <svg class="w-5 h-5 text-gray-500 group-hover:text-gray-900 transition"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-width="1.8" stroke-linecap="round"
                      d="M15 17h5l-1.4-1.4A2 2 0 0118 14.2V11a6 6 0 10-12 0v3.2a2 2 0 01-.6 1.4L4 17h5"/>
                <path stroke-width="1.8" stroke-linecap="round" d="M9 17a3 3 0 006 0"/>
            </svg>
        </a>

        <!-- 当前页：意见反馈 -->
        <a href="feedback.php" class="rounded-full p-2 bg-gray-100">
            <svg class="w-5 h-5 text-gray-900"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <path stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round"
                      d="M7 8h10M7 12h6m-2 8l-4-4H5a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v8a2 2 0 01-2 2h-3l-4 4z"/>
            </svg>
        </a>

        <!-- 用户 -->
        <a href="profile.php" class="group rounded-full p-2 hover:bg-gray-100">
            <svg class="w-5 h-5 text-gray-500 group-hover:text-gray-900 transition"
                 fill="none" stroke="currentColor" viewBox="0 0 24 24">
                <circle cx="12" cy="8" r="4" stroke-width="1.8"/>
                <path stroke-width="1.8" stroke-linecap="round"
                      d="M4 20a8 8 0 0116 0"/>
            </svg>
        </a>

        <!-- 退出（保留文字更安全） -->
        <?php include __DIR__ . '/components/logout-nav-link.php'; ?>
        <?php include __DIR__ . '/components/theme-toggle.php'; ?>

    </div>
</nav>

<main class="mx-auto max-w-screen-xl px-4 py-6 space-y-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <h1 class="text-lg font-semibold animate__animated animate__fadeInDown">意见反馈</h1>
            <p class="text-xs text-gray-500">反馈问题与建议，管理员会尽快回复</p>
        </div>
        <?php
        $unreadReplies = 0;
        foreach ($repliesByFeedback as $replyList) {
            foreach ($replyList as $reply) {
                if ($reply['role'] === 'admin' && (int)$reply['is_read'] === 0) {
                    $unreadReplies++;
                }
            }
        }
        ?>
        <div class="flex gap-2 text-xs">
            <span class="pill bg-blue-50 text-blue-600">📮 反馈 <?= count($feedbacks) ?></span>
            <span class="pill bg-amber-50 text-amber-600">未读回复 <?= $unreadReplies ?></span>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-600">
            <?= htmlspecialchars($message) ?>
        </div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-600">
            <?= htmlspecialchars($error) ?>
        </div>
    <?php endif; ?>

    <div class="rounded-2xl bg-white p-5 shadow card-hover">
        <h2 class="mb-3 text-base font-semibold">提交反馈</h2>
        <form method="POST" enctype="multipart/form-data" class="space-y-3">
            <input type="hidden" name="action" value="create">
            <div>
                <label class="mb-1 block text-sm text-gray-600">标题（可选）</label>
                <input name="title" class="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-red-500 focus:outline-none">
            </div>
            <div>
                <label class="mb-1 block text-sm text-gray-600">内容</label>
                <textarea name="content" rows="4" required class="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-red-500 focus:outline-none"></textarea>
            </div>
            <div>
                <label class="mb-1 block text-sm text-gray-600">上传图片（jpg / jpeg / png，≤10MB）</label>
                <input type="file" name="image" accept="image/jpeg,image/png,.jpg,.jpeg,.png" class="text-sm">
            </div>
            <button class="rounded bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">提交反馈</button>
        </form>
    </div>

    <div class="space-y-4">
        <?php if (empty($feedbacks)): ?>
            <div class="rounded-lg bg-white p-6 text-sm text-gray-500 shadow">暂无反馈记录。</div>
        <?php endif; ?>
        <?php foreach ($feedbacks as $fb): ?>
            <div class="rounded-2xl bg-white p-5 shadow card-hover animate__animated animate__fadeInUp">
                <div class="flex flex-wrap items-center justify-between gap-2">
                    <div class="text-base font-semibold"><?= htmlspecialchars($fb['title'] ?: '未命名反馈') ?></div>
                    <span class="text-xs text-gray-500"><?= htmlspecialchars($fb['created_at']) ?></span>
                </div>
                <div class="mt-2 text-sm text-gray-700 whitespace-pre-line"><?= htmlspecialchars($fb['content']) ?></div>
                <?php if (!empty($fb['image_path'])): ?>
                    <div class="mt-3">
                        <img src="<?= htmlspecialchars($assetPrefix . $fb['image_path']) ?>" alt="反馈图片" class="max-h-64 rounded border">
                    </div>
                <?php endif; ?>
                <div class="mt-3 flex flex-wrap items-center gap-2 text-xs text-gray-500">
                    <span>状态：<?= htmlspecialchars($fb['status']) ?></span>
                    <?php
                    $hasUnread = false;
                    $replies = $repliesByFeedback[$fb['id']] ?? [];
                    foreach ($replies as $reply) {
                        if ($reply['role'] === 'admin' && (int)$reply['is_read'] === 0) {
                            $hasUnread = true;
                            break;
                        }
                    }
                    ?>
                    <?php if ($hasUnread): ?>
                        <span class="pill bg-amber-50 text-amber-600">未读回复</span>
                    <?php endif; ?>
                </div>

                <?php $replies = $repliesByFeedback[$fb['id']] ?? []; ?>
                <?php if (!empty($replies)): ?>
                    <div class="mt-4 space-y-3">
                        <?php foreach ($replies as $reply): ?>
                            <div class="rounded border border-gray-100 bg-gray-50 p-3 text-sm">
                                <div class="text-xs text-gray-500">
                                    <?= $reply['role'] === 'admin' ? '管理员' : htmlspecialchars($reply['username'] ?? '用户') ?>
                                    <span class="ml-2"><?= htmlspecialchars($reply['created_at']) ?></span>
                                    <?php if ($reply['role'] === 'admin' && (int)$reply['is_read'] === 0): ?>
                                        <span class="ml-2 rounded-full bg-amber-50 px-2 py-0.5 text-amber-600">未读</span>
                                    <?php endif; ?>
                                </div>
                                <div class="mt-1 text-gray-700 whitespace-pre-line"><?= htmlspecialchars($reply['content']) ?></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>

                <form method="POST" class="mt-4 space-y-2">
                    <input type="hidden" name="action" value="reply">
                    <input type="hidden" name="feedback_id" value="<?= $fb['id'] ?>">
                    <textarea name="reply_content" rows="2" required class="w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-red-500 focus:outline-none" placeholder="回复管理员..."></textarea>
                    <button class="rounded bg-gray-800 px-3 py-1.5 text-xs font-semibold text-white hover:bg-gray-900">发送回复</button>
                </form>
            </div>
        <?php endforeach; ?>
    </div>
</main>
<?php include __DIR__ . '/components/theme-toggle-script.php'; ?>
</body>
</html>

