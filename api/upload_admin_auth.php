<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/upload_config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'error' => '方法不允许']);
    exit;
}

$raw = file_get_contents('php://input');
$json = json_decode($raw ?: '', true);
$data = is_array($json) ? $json : $_POST;

$pdo = getDB();
$config = getUploadApiConfig($pdo);
$expectedToken = (string)$config['remote_api_token'];
$token = (string)($data['api_token'] ?? '');

if ($expectedToken === '' || !hash_equals($expectedToken, $token)) {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'API Token 无效']);
    exit;
}

$username = trim((string)($data['username'] ?? ''));
$password = (string)($data['password'] ?? '');
if ($username === '' || $password === '') {
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => '请输入用户名和密码']);
    exit;
}

$stmt = $pdo->prepare("SELECT id, username, password, email FROM users WHERE username = 'admin' AND username = ? LIMIT 1");
$stmt->execute([$username]);
$admin = $stmt->fetch();

if (!$admin || !password_verify($password, (string)$admin['password'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => '管理员账号或密码错误']);
    exit;
}

echo json_encode([
    'ok' => true,
    'admin' => [
        'id' => (int)$admin['id'],
        'username' => $admin['username'],
        'email' => $admin['email'],
    ],
]);
