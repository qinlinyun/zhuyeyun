<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/admin_users.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => '请求方法不允许'], JSON_UNESCAPED_UNICODE);
    exit;
}

requireAdmin();

$pdo = getDB();
$action = trim((string)($_POST['action'] ?? ''));
$userId = (int)($_POST['user_id'] ?? 0);
$keyword = trim((string)($_POST['keyword'] ?? ''));

$result = adminUsersPerformAction($pdo, $action, $userId, $_POST, $keyword);
echo json_encode($result, JSON_UNESCAPED_UNICODE);
