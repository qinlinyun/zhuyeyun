<?php
session_start();
require_once __DIR__ . '/../config/database.php';

function authAccountActivationLoaded(): bool
{
    static $loaded = false;
    if (!$loaded) {
        require_once __DIR__ . '/account_activation.php';
        $loaded = true;
    }
    return true;
}

function userIsPendingActivationBan(array $user): bool
{
    if (($user['status'] ?? '') !== 'banned' || !empty($user['ban_until'])) {
        return false;
    }
    authAccountActivationLoaded();
    $pdo = getDB();

    return userHasPendingAccountActivation($pdo, (int)$user['id']);
}

require_once __DIR__ . '/account_status_popup.php';

// 检查登录状态
function isLoggedIn() {
    return isset($_SESSION['user_id']);
}

// 检查是否为管理员
function isAdmin() {
    return isset($_SESSION['is_admin']) && $_SESSION['is_admin'] === true;
}

// 获取当前用户信息
function getCurrentUser() {
    if (!isLoggedIn()) {
        return null;
    }
    
    $pdo = getDB();
    $stmt = $pdo->prepare("SELECT u.*, g.name as group_name FROM users u 
                          LEFT JOIN user_groups g ON u.group_id = g.id 
                          WHERE u.id = ?");
    $stmt->execute([$_SESSION['user_id']]);
    return $stmt->fetch();
}

// 检查用户状态（封禁/冻结等）
function checkUserStatus($user) {
    if (!$user) return false;
    
    // 检查是否被封禁
    if ($user['status'] === 'banned') {
        if ($user['ban_until'] && strtotime($user['ban_until']) > time()) {
            return false;
        } elseif ($user['ban_until'] && strtotime($user['ban_until']) <= time()) {
            $pdo = getDB();
            $stmt = $pdo->prepare("UPDATE users SET status = 'active', ban_until = NULL WHERE id = ?");
            $stmt->execute([$user['id']]);
            return true;
        } elseif (userIsPendingActivationBan($user)) {
            return false;
        }
    }
    
    // 检查是否被冻结
    if ($user['status'] === 'frozen') {
        return false;
    }
    
    return $user['status'] === 'active';
}

/** 管理员不受封禁/冻结等账户状态限制 */
function canUseSiteFeatures($user): bool
{
    if (!$user) {
        return false;
    }
    if (isAdmin()) {
        return true;
    }

    return checkUserStatus($user);
}

// 需要登录
function authWantsJsonResponse(): bool
{
    $requestedWith = strtolower((string)($_SERVER['HTTP_X_REQUESTED_WITH'] ?? ''));
    $accept = strtolower((string)($_SERVER['HTTP_ACCEPT'] ?? ''));

    return $requestedWith === 'xmlhttprequest' || str_contains($accept, 'application/json');
}

function authRelativeBasePath(): string
{
    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));
    $dir = trim(dirname($script), '/');
    if ($dir === '' || $dir === '.') {
        return '';
    }
    $segments = array_filter(explode('/', $dir), static fn (string $part): bool => $part !== '');

    return str_repeat('../', count($segments));
}

function requireLogin() {
    if (!isLoggedIn()) {
        if (authWantsJsonResponse()) {
            http_response_code(401);
            header('Content-Type: application/json; charset=utf-8');
            echo json_encode(['ok' => false, 'message' => '登录状态已过期，请重新登录后再上传'], JSON_UNESCAPED_UNICODE);
            exit;
        }
        $basePath = authRelativeBasePath();
        header('Location: ' . $basePath . 'login.php');
        exit;
    }

    $user = getCurrentUser();
    enforceAccountStatusPageAccess($user);

    if ($user && !isAdmin()) {
        require_once __DIR__ . '/traffic.php';
        touchAutoResetForUser(getDB(), (int)$user['id']);
    }
}

// 需要管理员权限
function requireAdmin() {
    requireLogin();
    if (!isAdmin()) {
        header('Location: ../index.php?error=权限不足');
        exit;
    }

    require_once __DIR__ . '/traffic.php';
    maybeRunScheduledAutoResets(getDB());
}

require_once __DIR__ . '/ip_visit_analytics.php';
trackCurrentIpVisit();
// IP 统计：仅关键页面 + 会话限频，见 analytics_config.php
?>

