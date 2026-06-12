<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/flash.php';
require_once __DIR__ . '/../includes/register_submit.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['ok' => false, 'message' => '请使用 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$result = processRegisterSubmission($pdo, $_POST);

if (!empty($result['ok'])) {
    flashSet('success', '注册成功，请登录');
}

$response = [
    'ok' => !empty($result['ok']),
    'field_errors' => $result['field_errors'] ?? [],
];

if (!empty($result['message'])) {
    $response['message'] = $result['message'];
}
if (!empty($result['redirect'])) {
    $response['redirect'] = $result['redirect'];
}

echo json_encode($response, JSON_UNESCAPED_UNICODE);
