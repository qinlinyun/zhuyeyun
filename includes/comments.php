<?php

require_once __DIR__ . '/user_profile.php';
require_once __DIR__ . '/datetime.php';
require_once __DIR__ . '/comment_filter.php';

const COMMENT_MAX_LENGTH = 1000;
const COMMENT_LIST_PAGE_SIZE = 20;

function ensureCommentsSchema(PDO $pdo): void
{
    static $ready = false;
    if ($ready) {
        return;
    }

    $pdo->exec("CREATE TABLE IF NOT EXISTS `video_comments` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `video_id` int(11) NOT NULL,
        `user_id` int(11) NOT NULL,
        `parent_id` bigint unsigned DEFAULT NULL,
        `content` varchar(1000) NOT NULL,
        `status` enum('visible','hidden') NOT NULL DEFAULT 'visible',
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_video_status` (`video_id`, `status`),
        KEY `idx_user` (`user_id`),
        KEY `idx_parent` (`parent_id`),
        KEY `idx_created` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

    $ready = true;
}

function commentNormalizeContent(string $content): string
{
    $content = trim(preg_replace('/\r\n?/', "\n", $content) ?? '');
    if (function_exists('mb_substr')) {
        return mb_substr($content, 0, COMMENT_MAX_LENGTH, 'UTF-8');
    }

    return substr($content, 0, COMMENT_MAX_LENGTH);
}

function commentStatusLabel(string $status): string
{
    return $status === 'hidden' ? '已隐藏' : '正常显示';
}

function commentFormatRow(array $row, int $currentUserId, bool $isAdmin): array
{
    $avatar = userAvatarUrl($row);
    $userId = (int)($row['user_id'] ?? 0);

    return [
        'id' => (int)$row['id'],
        'video_id' => (int)$row['video_id'],
        'parent_id' => isset($row['parent_id']) && $row['parent_id'] !== null ? (int)$row['parent_id'] : null,
        'content' => (string)$row['content'],
        'status' => (string)$row['status'],
        'created_at' => formatChinaDateTime((string)($row['created_at'] ?? '')),
        'user' => [
            'id' => $userId,
            'username' => (string)($row['username'] ?? ''),
            'display_name' => userDisplayName($row),
            'avatar' => $avatar,
        ],
        'can_delete' => $isAdmin || ($currentUserId > 0 && $currentUserId === $userId),
        'is_mine' => $currentUserId > 0 && $currentUserId === $userId,
    ];
}

/** @return array{items: list<array>, total: int, page: int, pages: int} */
function commentFetchForVideo(PDO $pdo, int $videoId, int $page, int $currentUserId, bool $isAdmin, bool $includeHidden = false): array
{
    ensureCommentsSchema($pdo);
    $page = max(1, $page);
    $limit = COMMENT_LIST_PAGE_SIZE;
    $offset = ($page - 1) * $limit;

    $where = 'c.video_id = ? AND c.parent_id IS NULL';
    $params = [$videoId];
    if (!$includeHidden && !$isAdmin) {
        $where .= " AND c.status = 'visible'";
    } elseif (!$includeHidden) {
        $where .= " AND c.status = 'visible'";
    }

    $countSt = $pdo->prepare("SELECT COUNT(*) FROM video_comments c WHERE {$where}");
    $countSt->execute($params);
    $total = (int)$countSt->fetchColumn();

    $sql = "
        SELECT c.*, u.username, u.display_name, u.avatar
        FROM video_comments c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE {$where}
        ORDER BY c.created_at DESC
        LIMIT {$limit} OFFSET {$offset}";
    $st = $pdo->prepare($sql);
    $st->execute($params);
    $roots = $st->fetchAll() ?: [];

    $rootIds = array_map(static fn(array $r): int => (int)$r['id'], $roots);
    $repliesByParent = [];
    if ($rootIds !== []) {
        $ph = implode(',', array_fill(0, count($rootIds), '?'));
        $replyWhere = "c.parent_id IN ({$ph})";
        $replyParams = $rootIds;
        if (!$includeHidden && !$isAdmin) {
            $replyWhere .= " AND c.status = 'visible'";
        } elseif (!$includeHidden) {
            $replyWhere .= " AND c.status = 'visible'";
        }
        $replySt = $pdo->prepare("
            SELECT c.*, u.username, u.display_name, u.avatar
            FROM video_comments c
            LEFT JOIN users u ON u.id = c.user_id
            WHERE {$replyWhere}
            ORDER BY c.created_at ASC");
        $replySt->execute($replyParams);
        foreach ($replySt->fetchAll() ?: [] as $reply) {
            $repliesByParent[(int)$reply['parent_id']][] = commentFormatRow($reply, $currentUserId, $isAdmin);
        }
    }

    $items = [];
    foreach ($roots as $root) {
        $item = commentFormatRow($root, $currentUserId, $isAdmin);
        $item['replies'] = $repliesByParent[(int)$root['id']] ?? [];
        $items[] = $item;
    }

    $pages = max(1, (int)ceil($total / $limit));

    return [
        'items' => $items,
        'total' => $total,
        'page' => $page,
        'pages' => $pages,
    ];
}

function commentCreate(PDO $pdo, int $videoId, int $userId, string $content, ?int $parentId = null): array
{
    ensureCommentsSchema($pdo);
    $content = commentNormalizeContent($content);
    if ($content === '') {
        return ['ok' => false, 'message' => '评论内容不能为空'];
    }
    $len = function_exists('mb_strlen') ? mb_strlen($content, 'UTF-8') : strlen($content);
    if ($len < 2) {
        return ['ok' => false, 'message' => '评论至少 2 个字'];
    }

    $filterResult = commentValidateContent($pdo, $content);
    if (!$filterResult['ok']) {
        return $filterResult;
    }

    $videoSt = $pdo->prepare('SELECT id FROM videos WHERE id = ? LIMIT 1');
    $videoSt->execute([$videoId]);
    if (!$videoSt->fetchColumn()) {
        return ['ok' => false, 'message' => '视频不存在'];
    }

    if ($parentId !== null && $parentId > 0) {
        $parentSt = $pdo->prepare("SELECT id, video_id, parent_id FROM video_comments WHERE id = ? AND status = 'visible' LIMIT 1");
        $parentSt->execute([$parentId]);
        $parent = $parentSt->fetch();
        if (!$parent) {
            return ['ok' => false, 'message' => '回复的评论不存在或已隐藏'];
        }
        if ((int)$parent['video_id'] !== $videoId) {
            return ['ok' => false, 'message' => '评论与视频不匹配'];
        }
        if ($parent['parent_id'] !== null) {
            return ['ok' => false, 'message' => '仅支持回复一级评论'];
        }
    } else {
        $parentId = null;
    }

    $recentSt = $pdo->prepare("SELECT COUNT(*) FROM video_comments WHERE user_id = ? AND created_at >= DATE_SUB(NOW(), INTERVAL 1 MINUTE)");
    $recentSt->execute([$userId]);
    if ((int)$recentSt->fetchColumn() >= 5) {
        return ['ok' => false, 'message' => '评论太频繁，请稍后再试'];
    }

    $pdo->prepare('INSERT INTO video_comments (video_id, user_id, parent_id, content) VALUES (?, ?, ?, ?)')
        ->execute([$videoId, $userId, $parentId, $content]);
    $id = (int)$pdo->lastInsertId();

    $st = $pdo->prepare('
        SELECT c.*, u.username, u.display_name, u.avatar
        FROM video_comments c
        LEFT JOIN users u ON u.id = c.user_id
        WHERE c.id = ? LIMIT 1');
    $st->execute([$id]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => '评论保存失败'];
    }

    $item = commentFormatRow($row, $userId, false);
    $item['replies'] = [];

    return ['ok' => true, 'comment' => $item];
}

function commentDelete(PDO $pdo, int $commentId, int $userId, bool $isAdmin): array
{
    ensureCommentsSchema($pdo);
    $st = $pdo->prepare('SELECT id, user_id, parent_id FROM video_comments WHERE id = ? LIMIT 1');
    $st->execute([$commentId]);
    $row = $st->fetch();
    if (!$row) {
        return ['ok' => false, 'message' => '评论不存在'];
    }
    if (!$isAdmin && (int)$row['user_id'] !== $userId) {
        return ['ok' => false, 'message' => '无权删除该评论'];
    }

    if ($row['parent_id'] === null) {
        $pdo->prepare('DELETE FROM video_comments WHERE parent_id = ?')->execute([$commentId]);
    }
    $pdo->prepare('DELETE FROM video_comments WHERE id = ?')->execute([$commentId]);

    return ['ok' => true, 'message' => '评论已删除'];
}

function commentSetStatus(PDO $pdo, int $commentId, string $status): array
{
    ensureCommentsSchema($pdo);
    if (!in_array($status, ['visible', 'hidden'], true)) {
        return ['ok' => false, 'message' => '无效状态'];
    }
    $st = $pdo->prepare('SELECT id FROM video_comments WHERE id = ? LIMIT 1');
    $st->execute([$commentId]);
    if (!$st->fetchColumn()) {
        return ['ok' => false, 'message' => '评论不存在'];
    }
    $pdo->prepare('UPDATE video_comments SET status = ? WHERE id = ?')->execute([$status, $commentId]);

    return ['ok' => true, 'message' => $status === 'hidden' ? '评论已隐藏' : '评论已恢复显示'];
}

/** @return list<array<string, mixed>> */
function commentAdminList(PDO $pdo, string $section, string $keyword, int $videoIdFilter, int $limit = 200): array
{
    ensureCommentsSchema($pdo);
    $where = ['1=1'];
    $params = [];

    if ($section === 'visible') {
        $where[] = "c.status = 'visible'";
    } elseif ($section === 'hidden') {
        $where[] = "c.status = 'hidden'";
    }

    if ($videoIdFilter > 0) {
        $where[] = 'c.video_id = ?';
        $params[] = $videoIdFilter;
    }

    if ($keyword !== '') {
        $where[] = '(c.content LIKE ? OR u.username LIKE ? OR v.title LIKE ?)';
        $like = '%' . $keyword . '%';
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
    }

    $sql = "
        SELECT c.*, u.username, u.display_name, u.avatar, v.title AS video_title
        FROM video_comments c
        LEFT JOIN users u ON u.id = c.user_id
        LEFT JOIN videos v ON v.id = c.video_id
        WHERE " . implode(' AND ', $where) . "
        ORDER BY c.created_at DESC
        LIMIT " . max(1, min(500, $limit));

    $st = $pdo->prepare($sql);
    $st->execute($params);

    return $st->fetchAll() ?: [];
}

function commentAdminStats(PDO $pdo): array
{
    ensureCommentsSchema($pdo);
    $total = (int)$pdo->query('SELECT COUNT(*) FROM video_comments')->fetchColumn();
    $visible = (int)$pdo->query("SELECT COUNT(*) FROM video_comments WHERE status = 'visible'")->fetchColumn();
    $hidden = (int)$pdo->query("SELECT COUNT(*) FROM video_comments WHERE status = 'hidden'")->fetchColumn();
    $today = (int)$pdo->query("SELECT COUNT(*) FROM video_comments WHERE DATE(created_at) = CURDATE()")->fetchColumn();

    return compact('total', 'visible', 'hidden', 'today');
}
