<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/mail_targeted.php';

requireAdmin();

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => '请使用 GET 请求'], JSON_UNESCAPED_UNICODE);
    exit;
}

$q = trim((string)($_GET['q'] ?? ''));
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = normalizeMailTargetedUserPageSize((int)($_GET['per_page'] ?? 10));

$pdo = getDB();
$result = listMailTargetedUsers($pdo, $q, $page, $perPage);

echo json_encode([
    'ok' => true,
    'users' => array_map(static function (array $u): array {
        return [
            'id' => (int)($u['id'] ?? 0),
            'username' => (string)($u['username'] ?? ''),
            'display_name' => (string)($u['display_name'] ?? ''),
            'email' => (string)($u['email'] ?? ''),
            'status' => (string)($u['status'] ?? ''),
            'group_id' => (int)($u['group_id'] ?? 0),
        ];
    }, $result['items']),
    'total' => (int)$result['total'],
    'page' => (int)$result['page'],
    'pages' => (int)$result['pages'],
    'per_page' => (int)$result['per_page'],
    'range_start' => (int)$result['range_start'],
    'range_end' => (int)$result['range_end'],
], JSON_UNESCAPED_UNICODE);
