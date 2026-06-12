<?php

function userHasCustomAvatar(?array $user): bool
{
    return !empty($user['avatar']);
}

/** 已上传头像 URL；无自定义头像时返回 null（请用 components/user-avatar.php 渲染默认 SVG） */
function userAvatarUrl(?array $user): ?string
{
    if (!empty($user['avatar'])) {
        return (string)$user['avatar'];
    }
    return null;
}

function ensureUserProfileSchema(PDO $pdo): void
{
    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'display_name'");
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE users ADD COLUMN display_name varchar(80) DEFAULT NULL AFTER username');
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM users LIKE 'avatar'");
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE users ADD COLUMN avatar varchar(255) DEFAULT NULL AFTER register_device');
    }

    $stmt = $pdo->query("SHOW COLUMNS FROM videos LIKE 'uploaded_by'");
    if (!$stmt->fetch()) {
        $pdo->exec('ALTER TABLE videos ADD COLUMN uploaded_by int(11) DEFAULT NULL AFTER server_group_id, ADD KEY `idx_videos_uploaded_by` (`uploaded_by`)');
    }
}

function userDisplayName(?array $user): string
{
    $displayName = trim((string)($user['display_name'] ?? ''));
    if ($displayName !== '') {
        return $displayName;
    }

    return (string)($user['username'] ?? '');
}

function normalizeUserDisplayName(string $name): string
{
    $name = trim($name);
    $name = preg_replace('/\s+/u', ' ', $name);
    if (function_exists('mb_substr')) {
        return mb_substr((string)$name, 0, 40, 'UTF-8');
    }
    return substr((string)$name, 0, 80);
}

function updateUserDisplayName(PDO $pdo, int $userId, string $displayName): void
{
    ensureUserProfileSchema($pdo);
    $displayName = normalizeUserDisplayName($displayName);
    $pdo->prepare('UPDATE users SET display_name = ? WHERE id = ?')
        ->execute([$displayName !== '' ? $displayName : null, $userId]);
}

function fetchUserById(PDO $pdo, int $userId): ?array
{
    $stmt = $pdo->prepare('SELECT u.*, g.name AS group_name FROM users u
        LEFT JOIN user_groups g ON g.id = u.group_id WHERE u.id = ? LIMIT 1');
    $stmt->execute([$userId]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function fetchUserUploadedVideos(PDO $pdo, int $userId): array
{
    $stmt = $pdo->prepare('SELECT * FROM videos WHERE uploaded_by = ? ORDER BY created_at DESC');
    $stmt->execute([$userId]);
    return $stmt->fetchAll() ?: [];
}

function userUploadedVideoIds(array $videos): array
{
    return array_values(array_filter(array_map(static fn ($video) => (int)($video['id'] ?? 0), $videos)));
}

function countUserUploadedVideoClicks(PDO $pdo, array $videos): int
{
    $videoIds = userUploadedVideoIds($videos);
    if ($videoIds === []) {
        return 0;
    }
    require_once __DIR__ . '/analytics_schema.php';
    ensureAnalyticsTables($pdo);
    $placeholders = implode(',', array_fill(0, count($videoIds), '?'));
    $stmt = $pdo->prepare("SELECT COALESCE(SUM(clicks), 0) FROM analytics_video_clicks WHERE video_id IN ($placeholders)");
    $stmt->execute($videoIds);
    return (int)$stmt->fetchColumn();
}

function userVideoEarningSummary(PDO $pdo, int $userId): array
{
    require_once __DIR__ . '/traffic.php';
    ensureTrafficEarningsSchema($pdo);
    $stmt = $pdo->prepare('
        SELECT
            COALESCE(SUM(amount), 0) AS total_amount,
            COALESCE(SUM(CASE WHEN status = "settled" THEN amount ELSE 0 END), 0) AS settled_amount,
            COALESCE(SUM(CASE WHEN status = "frozen" THEN amount ELSE 0 END), 0) AS frozen_amount,
            COALESCE(SUM(CASE WHEN status = "reclaimed" THEN amount ELSE 0 END), 0) AS reclaimed_amount
        FROM traffic_earning_logs
        WHERE publisher_user_id = ?
    ');
    $stmt->execute([$userId]);
    $row = $stmt->fetch() ?: [];
    $wallet = getUserEarningTraffic($pdo, $userId);

    return [
        'total_amount' => (int)($row['total_amount'] ?? 0),
        'settled_amount' => (int)($row['settled_amount'] ?? 0),
        'frozen_amount' => (int)($row['frozen_amount'] ?? 0),
        'reclaimed_amount' => (int)($row['reclaimed_amount'] ?? 0),
        'available' => (int)$wallet['available'],
        'frozen' => (int)$wallet['frozen'],
    ];
}

function fetchUserVideoEarningBills(PDO $pdo, int $userId, int $limit = 5): array
{
    require_once __DIR__ . '/traffic.php';
    return fetchUserEarningLogs($pdo, $userId, $limit);
}

function deleteUserAvatarFile(?string $relativePath, string $baseDir): void
{
    if (!$relativePath || strpos($relativePath, 'uploads/avatars/') !== 0) {
        return;
    }
    $fullPath = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath);
    if (is_file($fullPath)) {
        @unlink($fullPath);
    }
}

function saveUserAvatar(array $file, int $userId, string $uploadDir, ?string $oldAvatar, string $baseDir): array
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) {
        return ['path' => null, 'error' => '请选择头像图片'];
    }

    if ($file['error'] !== UPLOAD_ERR_OK) {
        return ['path' => null, 'error' => '头像上传失败，请重试'];
    }

    if (($file['size'] ?? 0) > 2 * 1024 * 1024) {
        return ['path' => null, 'error' => '头像大小不能超过 2MB'];
    }

    if (empty($file['tmp_name']) || !is_uploaded_file($file['tmp_name'])) {
        return ['path' => null, 'error' => '无效的上传文件'];
    }

    $imgInfo = @getimagesize($file['tmp_name']);
    if (!$imgInfo) {
        return ['path' => null, 'error' => '无法识别图片格式'];
    }

    $extMap = [
        IMAGETYPE_JPEG => 'jpg',
        IMAGETYPE_PNG => 'png',
        IMAGETYPE_WEBP => 'webp',
    ];
    if (!isset($extMap[$imgInfo[2]])) {
        return ['path' => null, 'error' => '仅支持 jpg / png / webp 格式'];
    }
    $ext = $extMap[$imgInfo[2]];

    if (!is_dir($uploadDir) && !mkdir($uploadDir, 0755, true) && !is_dir($uploadDir)) {
        return ['path' => null, 'error' => '无法创建上传目录'];
    }

    $fileName = 'av_' . $userId . '_' . date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '.' . $ext;
    $target = rtrim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $uploadDir), DIRECTORY_SEPARATOR)
        . DIRECTORY_SEPARATOR . $fileName;

    if (!move_uploaded_file($file['tmp_name'], $target)) {
        return ['path' => null, 'error' => '头像保存失败，请检查 uploads/avatars 目录权限'];
    }

    $relative = 'uploads/avatars/' . $fileName;
    deleteUserAvatarFile($oldAvatar, $baseDir);

    return ['path' => $relative, 'error' => null];
}
