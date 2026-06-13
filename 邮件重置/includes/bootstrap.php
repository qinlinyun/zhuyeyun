<?php

declare(strict_types=1);

require_once __DIR__ . '/../../includes/auth.php';

function emailChangeRequireLogin(): void
{
    if (!isLoggedIn()) {
        if (authWantsJsonResponse()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode([
                'ok' => false,
                'message' => '请先登录',
                'login_url' => 'login.php',
            ], JSON_UNESCAPED_UNICODE);
            exit;
        }
        header('Location: login.php');
        exit;
    }

    $user = getCurrentUser();
    enforceAccountStatusPageAccess($user);
}

function emailChangeUpdateUnreadCounts(PDO $pdo, int $userId): void
{
    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM notifications n
        LEFT JOIN notification_reads r
          ON r.notification_id = n.id AND r.user_id = ?
        WHERE (n.target_type = \'all\' OR n.target_user_id = ?)
          AND r.id IS NULL
    ');
    $stmt->execute([$userId, $userId]);
    $_SESSION['unread_notification_count'] = (int)$stmt->fetchColumn();

    $stmt = $pdo->prepare('
        SELECT COUNT(*)
        FROM feedback_replies r
        JOIN feedbacks f ON f.id = r.feedback_id
        LEFT JOIN feedback_reply_reads rr
          ON rr.reply_id = r.id AND rr.user_id = ?
        WHERE f.user_id = ?
          AND r.role = \'admin\'
          AND rr.id IS NULL
    ');
    $stmt->execute([$userId, $userId]);
    $_SESSION['unread_feedback_reply_count'] = (int)$stmt->fetchColumn();
}

/**
 * @return array{ok:bool,message?:string}
 */
function emailChangeAttemptLogin(PDO $pdo, string $username, string $password): array
{
    $username = trim($username);
    if ($username === '' || $password === '') {
        return ['ok' => false, 'message' => '请填写账号和密码'];
    }

    $stmt = $pdo->prepare('SELECT * FROM users WHERE username = ? OR email = ? LIMIT 1');
    $stmt->execute([$username, $username]);
    $user = $stmt->fetch();
    if (!$user || !password_verify($password, (string)$user['password'])) {
        return ['ok' => false, 'message' => '账号或密码错误'];
    }

    if (!canUseSiteFeatures($user)) {
        return ['ok' => false, 'message' => '账户当前不可用，请联系管理员'];
    }

    $isAdminUser = ((string)$user['username'] === 'admin');
    $_SESSION['user_id'] = (int)$user['id'];
    $_SESSION['username'] = (string)$user['username'];
    $_SESSION['is_admin'] = $isAdminUser;

    if (!$isAdminUser) {
        require_once __DIR__ . '/../../includes/user_login_analytics.php';
        recordUserLogin($user);
    }

    emailChangeUpdateUnreadCounts($pdo, (int)$user['id']);

    return ['ok' => true];
}
