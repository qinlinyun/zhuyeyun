<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/ip_visit_analytics.php';

requireAdmin();

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'ok' => true,
    'items' => getIpVisitRanking(),
], JSON_UNESCAPED_UNICODE);
