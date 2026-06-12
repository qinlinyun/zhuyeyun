<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/watch_progress_service.php';
require_once __DIR__ . '/../includes/play_domains.php';

requireLogin();
header('Content-Type: application/json; charset=utf-8');

$userId = (int)($_SESSION['user_id'] ?? 0);
$since = trim((string)($_GET['since'] ?? ''));
$flush = !isset($_GET['since']);

$pdo = getDB();

if ($flush && isRedisWatchProgressAvailable()) {
    watchProgressFlushUserToMysql($pdo, $userId);
}

$sql = '
  SELECT
    p.video_id,
    p.episode_id,
    p.progress_seconds,
    p.duration_seconds,
    p.updated_at,
    v.title,
    v.cover,
    v.server_group_id,
    e.episode_name
  FROM video_watch_progress p
  JOIN videos v ON v.id = p.video_id
  JOIN video_episodes e ON e.id = p.episode_id
  WHERE p.user_id = ?
';
$params = [$userId];

if ($since !== '') {
    $sql .= ' AND p.updated_at > ?';
    $params[] = $since;
}

$sql .= ' ORDER BY p.updated_at DESC LIMIT 500';

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
$user = getCurrentUser();
$asAdmin = isAdmin();

/**
 * 观看记录列表可能很多条；按 server_group_id 缓存一次线路选择，避免每条记录都重复查 domains。
 * @return string 完整 URL（含 https:// 域名）或以 / 开头的相对路径
 */
function coverUrlForRow(PDO $pdo, array $user, bool $asAdmin, array $row, string $coverPath, array &$cache): string
{
    $coverPath = trim($coverPath);
    if ($coverPath === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $coverPath)) {
        return $coverPath;
    }
    $sgKey = (string)($row['server_group_id'] ?? '');
    if (!array_key_exists($sgKey, $cache)) {
        $video = [
            'id' => (int)($row['video_id'] ?? 0),
            'server_group_id' => $row['server_group_id'] ?? null,
        ];
        $domains = play_domains_for_playback($pdo, $user, $video, $asAdmin);
        $domain = trim((string)($domains[0]['domain'] ?? ''));
        if ($domain !== '') {
            $domain = preg_replace('#^https?://#i', '', $domain);
            $cache[$sgKey] = 'https://' . rtrim((string)$domain, '/');
        } else {
            $cache[$sgKey] = '';
        }
    }
    $prefix = (string)$cache[$sgKey];
    $relative = '/' . ltrim(str_replace('\\', '/', $coverPath), '/');
    return $prefix !== '' ? ($prefix . $relative) : $relative;
}

$coverPrefixCache = [];
foreach ($rows as &$row) {
    $coverPath = trim((string)($row['cover'] ?? ''));
    $row['cover_url'] = coverUrlForRow($pdo, $user, $asAdmin, $row, $coverPath, $coverPrefixCache);
}
unset($row);

echo json_encode([
    'ok' => true,
    'list' => $rows,
    'redis' => isRedisWatchProgressAvailable(),
]);
