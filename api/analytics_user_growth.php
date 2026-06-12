<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/user_growth_analytics.php';

requireAdmin();

header('Content-Type: application/json; charset=utf-8');

$range = normalizeUserGrowthRange((string)($_GET['range'] ?? '24h'));
echo json_encode(getUserGrowthTrend($range), JSON_UNESCAPED_UNICODE);
