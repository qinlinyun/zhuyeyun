<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/wheel.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn() || !isAdmin()) {
    http_response_code(isLoggedIn() ? 403 : 401);
    echo json_encode(['ok' => false, 'message' => isLoggedIn() ? '权限不足' : '请先登录'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$page = max(1, (int)($_GET['page'] ?? 1));
$perPage = max(1, min(50, (int)($_GET['per_page'] ?? 20)));
$keyword = trim((string)($_GET['q'] ?? ''));
$result = wheelListRecords($pdo, $page, $perPage, null, $keyword);

echo json_encode([
    'ok' => true,
    'records' => $result,
], JSON_UNESCAPED_UNICODE);
