<?php
require_once __DIR__ . '/../includes/admin_json.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/player_video_token.php';

requireAdminJson();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();

if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    $items = [];
    foreach (listVideosForTokenSettings($pdo) as $row) {
        $items[] = [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
            'cover' => (string)($row['cover'] ?? ''),
            'server_group_name' => (string)($row['server_group_name'] ?? ''),
            'episode_count' => (int)($row['episode_count'] ?? 0),
            'skip_backend_proxy' => !empty($row['skip_backend_proxy']),
            'created_at' => (string)($row['created_at'] ?? ''),
        ];
    }

    echo json_encode([
        'ok' => true,
        'items' => $items,
        'proxy_enabled' => isPlayerProxyEnabled($pdo),
    ], JSON_UNESCAPED_UNICODE);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['ok' => false, 'message' => '请使用 GET 或 POST'], JSON_UNESCAPED_UNICODE);
    exit;
}

$raw = file_get_contents('php://input');
$input = json_decode((string)$raw, true);
if (!is_array($input)) {
    $input = $_POST;
}

$videoIds = $input['video_ids'] ?? [];
$skipIds = $input['skip_ids'] ?? [];

if (!is_array($videoIds)) {
    $videoIds = [];
}
if (!is_array($skipIds)) {
    $skipIds = [];
}

saveVideoSkipBackendProxySettings($pdo, $videoIds, $skipIds);

echo json_encode(['ok' => true, 'message' => '视频 Token 设置已保存'], JSON_UNESCAPED_UNICODE);
