<?php
require_once __DIR__ . '/../includes/admin_json.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/video_data_sync.php';

requireAdminJson();

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();
$videos = $pdo->query('SELECT id, title FROM videos ORDER BY id DESC LIMIT 500')->fetchAll();

echo json_encode([
    'ok' => true,
    'videos' => array_map(static function ($row) {
        return [
            'id' => (int)$row['id'],
            'title' => (string)$row['title'],
        ];
    }, $videos),
], JSON_UNESCAPED_UNICODE);
