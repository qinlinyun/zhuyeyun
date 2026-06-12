<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/user_profile.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => '请先登录']);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '方法不允许']);
    exit;
}

$userId = (int)$_SESSION['user_id'];
$pdo = getDB();
ensureUserProfileSchema($pdo);

$user = fetchUserById($pdo, $userId);
if (!$user) {
    http_response_code(404);
    echo json_encode(['ok' => false, 'error' => '用户不存在']);
    exit;
}

$baseDir = dirname(__DIR__);
$uploadDir = $baseDir . '/uploads/avatars';
$result = saveUserAvatar($_FILES['avatar'] ?? [], $userId, $uploadDir, $user['avatar'] ?? null, $baseDir);

if ($result['error']) {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => $result['error']]);
    exit;
}

$pdo->prepare('UPDATE users SET avatar = ? WHERE id = ?')->execute([$result['path'], $userId]);

echo json_encode([
    'ok' => true,
    'avatar' => $result['path'],
    'avatar_url' => (string)$result['path'],
]);
