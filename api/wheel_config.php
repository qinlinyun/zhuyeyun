<?php

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/wheel.php';

header('Content-Type: application/json; charset=utf-8');

if (!isLoggedIn()) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'message' => '请先登录', 'login_url' => 'login.php'], JSON_UNESCAPED_UNICODE);
    exit;
}

$user = getCurrentUser();
if (!$user || !canUseSiteFeatures($user)) {
    echo json_encode(['ok' => false, 'message' => '账户不可用'], JSON_UNESCAPED_UNICODE);
    exit;
}

$pdo = getDB();
$config = wheelLoadConfig($pdo);
$userId = (int)$user['id'];
$availability = wheelSpinAvailability($pdo, $userId, $config);
$spinsLeft = (int)$availability['spins_left'];

echo json_encode([
    'ok' => true,
    'enabled' => wheelIsActivityEnabled($config, $pdo),
    'daily_spins' => (int)$config['daily_spins'],
    'spin_cost_traffic' => (int)$config['spin_cost_traffic'],
    'spins_left' => $spinsLeft,
    'daily_left' => (int)$availability['daily_left'],
    'traffic_left' => (int)$availability['traffic_left'],
    'can_spin' => !empty($availability['can_spin']),
    'spin_message' => (string)$availability['message'],
    'prizes' => wheelPublicPrizes($config),
    'username' => (string)$user['username'],
], JSON_UNESCAPED_UNICODE);
