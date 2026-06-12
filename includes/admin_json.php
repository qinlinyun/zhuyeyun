<?php

require_once __DIR__ . '/auth.php';

function requireAdminJson(): void
{
    if (!isLoggedIn()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(401);
        echo json_encode(['ok' => false, 'message' => '未登录'], JSON_UNESCAPED_UNICODE);
        exit;
    }
    if (!isAdmin()) {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code(403);
        echo json_encode(['ok' => false, 'message' => '权限不足'], JSON_UNESCAPED_UNICODE);
        exit;
    }
}
