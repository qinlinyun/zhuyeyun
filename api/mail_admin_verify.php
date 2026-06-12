<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/mail_server_api.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => '请使用 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
mailServerRequireApiKey($pdo);

$input = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($input)) {
    $input = $_POST;
}

$result = verifySiteAdminCredentials(
    $pdo,
    (string)($input['username'] ?? ''),
    (string)($input['password'] ?? '')
);

if (empty($result['ok'])) {
    http_response_code(401);
}

echo json_encode($result, JSON_UNESCAPED_UNICODE);
