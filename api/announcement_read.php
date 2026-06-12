<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/announcement.php';

requireLogin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => '请使用 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$announcementId = (int)($_POST['announcement_id'] ?? 0);
if ($announcementId <= 0) {
    echo json_encode(['ok' => false, 'message' => '无效的公告'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$row = getAnnouncementById($pdo, $announcementId);
if (!$row || ($row['status'] ?? '') !== 'published') {
    echo json_encode(['ok' => false, 'message' => '公告不存在或未发布'], JSON_UNESCAPED_UNICODE);
    exit;
}

$userId = (int)($_SESSION['user_id'] ?? 0);
if ($userId <= 0) {
    echo json_encode(['ok' => false, 'message' => '未登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

markAnnouncementRead($pdo, $userId, $announcementId);

$notificationId = (int)($row['notification_id'] ?? 0);
if ($notificationId > 0) {
    $stmt = $pdo->prepare('INSERT IGNORE INTO notification_reads (user_id, notification_id, read_at) VALUES (?, ?, NOW())');
    $stmt->execute([$userId, $notificationId]);
    if (!empty($_SESSION['unread_notification_count']) && (int)$_SESSION['unread_notification_count'] > 0) {
        $_SESSION['unread_notification_count'] = max(0, (int)$_SESSION['unread_notification_count'] - 1);
    }
}

echo json_encode(['ok' => true], JSON_UNESCAPED_UNICODE);
