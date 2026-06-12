<?php

require_once __DIR__ . '/analytics_schema.php';
require_once __DIR__ . '/analytics_config.php';

function recordUserLogin(array $user): void
{
    if (!isAnalyticsLoginEnabled()) {
        return;
    }

    $userId = (int)($user['id'] ?? 0);
    if ($userId <= 0) {
        return;
    }

    $username = trim((string)($user['username'] ?? ''));
    $email = trim((string)($user['email'] ?? ''));
    $pdo = analyticsDb();
    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare('SELECT user_id FROM analytics_user_logins WHERE user_id = ? LIMIT 1');
    $stmt->execute([$userId]);

    if ($stmt->fetch()) {
        $update = $pdo->prepare("
            UPDATE analytics_user_logins
            SET username = ?, email = ?, logins = logins + 1, last_login_at = ?
            WHERE user_id = ?
        ");
        $update->execute([$username, $email, $now, $userId]);
        return;
    }

    $insert = $pdo->prepare("
        INSERT INTO analytics_user_logins (user_id, username, email, logins, first_login_at, last_login_at)
        VALUES (?, ?, ?, 1, ?, ?)
    ");
    $insert->execute([$userId, $username, $email, $now, $now]);
}

function getUserLoginRanking(PDO $pdo): array
{
    analyticsDb($pdo);

    $limit = getAnalyticsRankingLimit($pdo);
    $stmt = $pdo->prepare("
        SELECT user_id, username, email, logins
        FROM analytics_user_logins
        ORDER BY logins DESC, last_login_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $items = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    if ($items === []) {
        return [];
    }

    $userIds = array_values(array_filter(array_map(static function ($row) {
        return (int)($row['user_id'] ?? 0);
    }, $items), static function ($id) {
        return $id > 0;
    }));

    $usersById = [];
    if ($userIds !== []) {
        $placeholders = implode(',', array_fill(0, count($userIds), '?'));
        $userStmt = $pdo->prepare("SELECT id, username, email FROM users WHERE id IN ($placeholders)");
        $userStmt->execute($userIds);
        foreach ($userStmt->fetchAll() as $row) {
            $usersById[(int)$row['id']] = $row;
        }
    }

    $ranking = [];
    $rank = 1;
    foreach ($items as $meta) {
        $id = (int)($meta['user_id'] ?? 0);
        if ($id <= 0) {
            continue;
        }

        $dbUser = $usersById[$id] ?? null;
        $username = $dbUser ? (string)$dbUser['username'] : (string)($meta['username'] ?? '（用户已删除）');
        $email = $dbUser ? (string)$dbUser['email'] : (string)($meta['email'] ?? '-');

        $ranking[] = [
            'rank' => $rank++,
            'user_id' => $id,
            'username' => $username,
            'email' => $email,
            'logins' => (int)($meta['logins'] ?? 0),
            'exists' => $dbUser !== null,
        ];
    }

    return $ranking;
}
