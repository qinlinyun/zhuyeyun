<?php
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../config/database.php';

requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'msg' => 'forbidden']);
    exit;
}

header('Content-Type: application/json; charset=utf-8');

$pdo = getDB();

$q = trim((string)($_GET['q'] ?? ''));         // 搜索：用户名/视频标题/集名
$limit = (int)($_GET['limit'] ?? 50);
if ($limit <= 0) $limit = 50;
if ($limit > 200) $limit = 200;

$page = (int)($_GET['page'] ?? 1);
if ($page <= 0) $page = 1;
$offset = ($page - 1) * $limit;

$where = [];
$params = [];

// ✅ 排除管理员：简单做法（按 username = 'admin' 不可靠）
// 我们用 isAdmin() 逻辑通常来自用户组/权限表；你没给权限字段，所以这里采用“排除 group_id=1?”也不可靠。
// 最稳：排除当前 isAdmin() 的账号列表 —— 但你没提供 admin 字段。
// ✅ 实用方案：排除“管理员账号集合”，建议你在 auth.php 的 isAdmin() 里基于 group_id 判断。
// 这里假设：管理员 group_id = 0 或 99 不确定，所以提供可配置数组：
$adminGroupIds = [/* 例如 99, 100 */];

// 若你明确管理员 group_id，比如 99，就写：$adminGroupIds = [99];
// 如果你不确定，保持空数组也可以（因为保存接口已经跳过管理员记录，数据库一般不会有管理员记录）
if (!empty($adminGroupIds)) {
    $in = implode(',', array_fill(0, count($adminGroupIds), '?'));
    $where[] = "u.group_id NOT IN ($in)";
    $params = array_merge($params, $adminGroupIds);
}

// 搜索
if ($q !== '') {
    $where[] = "(u.username LIKE ? OR v.title LIKE ? OR e.episode_name LIKE ?)";
    $kw = "%{$q}%";
    $params[] = $kw; $params[] = $kw; $params[] = $kw;
}

$since = trim((string)($_GET['since'] ?? ''));
if ($since !== '') {
    $where[] = 'p.updated_at > ?';
    $params[] = $since;
}

$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

// 总数
$stmt = $pdo->prepare("
    SELECT COUNT(*)
    FROM video_watch_progress p
    JOIN users u ON u.id = p.user_id
    JOIN videos v ON v.id = p.video_id
    JOIN video_episodes e ON e.id = p.episode_id
    $whereSql
");
$stmt->execute($params);
$total = (int)$stmt->fetchColumn();

// 列表
$stmt = $pdo->prepare("
    SELECT
      p.user_id, u.username,
      p.video_id, v.title,
      p.episode_id, e.episode_name,
      p.progress_seconds, p.duration_seconds,
      p.updated_at
    FROM video_watch_progress p
    JOIN users u ON u.id = p.user_id
    JOIN videos v ON v.id = p.video_id
    JOIN video_episodes e ON e.id = p.episode_id
    $whereSql
    ORDER BY p.updated_at DESC
    LIMIT $limit OFFSET $offset
");
$stmt->execute($params);
$list = $stmt->fetchAll();

echo json_encode([
    'ok' => true,
    'q' => $q,
    'page' => $page,
    'limit' => $limit,
    'total' => $total,
    'list' => $list
]);
