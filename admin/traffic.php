<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/traffic.php';
require_once '../includes/flash.php';

requireAdmin();

$pdo = getDB();
$message = '';
$error = '';
applyFlash($message, $error);

$hasTrafficCols = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'traffic_total'")->fetch();
if (!$hasTrafficCols) {
    die('请先执行 update_db.php 升级数据库以启用流量功能');
}
ensureTrafficEarningsSchema($pdo);

$adminId = (int)$_SESSION['user_id'];

function trafficUsersPageSizeOptions(): array
{
    return [10, 20, 30, 50];
}

function normalizeTrafficUsersPageSize(int $size): int
{
    return in_array($size, trafficUsersPageSizeOptions(), true) ? $size : 10;
}

function normalizeTrafficUsersPage(int $page): int
{
    return max(1, $page);
}

function trafficUsersListUrl(string $section, string $keyword, int $page, int $perPage): string
{
    $query = [
        'section' => $section,
        'page' => normalizeTrafficUsersPage($page),
        'per_page' => normalizeTrafficUsersPageSize($perPage),
    ];
    if ($keyword !== '') {
        $query['q'] = $keyword;
    }

    return 'traffic.php?' . http_build_query($query);
}

$activeSection = trim((string)($_GET['section'] ?? ''));
if (!in_array($activeSection, ['overview', 'groups', 'users', 'unlocks', 'logs'], true)) {
    $activeSection = 'users';
}

$keyword = trim($_GET['q'] ?? '');
$userPage = normalizeTrafficUsersPage((int)($_GET['page'] ?? 1));
$userPerPage = normalizeTrafficUsersPageSize((int)($_GET['per_page'] ?? 10));

function logTraffic(PDO $pdo, int $userId, string $action, int $change, int $beforeTotal, int $beforeUsed, int $afterTotal, int $afterUsed, string $remark, int $opId): void {
    $pdo->prepare("INSERT INTO traffic_logs (user_id, action, change_amount, before_total, before_used, after_total, after_used, remark, operator_id)
        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)")
        ->execute([$userId, $action, $change, $beforeTotal, $beforeUsed, $afterTotal, $afterUsed, $remark, $opId]);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $userId = (int)($_POST['user_id'] ?? 0);

    if ($action === 'group_default') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $defaultTraffic = max(0, (int)($_POST['default_traffic'] ?? 0));
        $validityDays = max(0, (int)($_POST['traffic_validity_days'] ?? 0));
        $autoResetDays = max(0, (int)($_POST['auto_reset_days'] ?? 0));
        if ($groupId) {
            $hasAuto = (bool)$pdo->query("SHOW COLUMNS FROM user_groups LIKE 'auto_reset_days'")->fetch();
            if ($hasAuto) {
                $pdo->prepare("UPDATE user_groups SET default_traffic = ?, traffic_validity_days = ?, auto_reset_days = ? WHERE id = ?")
                    ->execute([$defaultTraffic, $validityDays, $autoResetDays, $groupId]);
                if ($autoResetDays > 0) {
                    $pdo->prepare("UPDATE users SET traffic_last_reset_at = COALESCE(traffic_last_reset_at, NOW()) WHERE group_id = ?")
                        ->execute([$groupId]);
                }
            } else {
                $pdo->prepare("UPDATE user_groups SET default_traffic = ?, traffic_validity_days = ? WHERE id = ?")
                    ->execute([$defaultTraffic, $validityDays, $groupId]);
            }
            $message = '用户组默认流量已更新';
        }
    } elseif ($action === 'group_bulk_reset') {
        $groupId = (int)($_POST['group_id'] ?? 0);
        if ($groupId) {
            $count = resetAllUsersInGroup($pdo, $groupId, $adminId);
            $message = "已重置该用户组下 {$count} 名用户的流量与解锁记录";
        }
    } elseif ($action === 'user_auto_reset' && $userId) {
        $days = max(0, (int)($_POST['auto_reset_days'] ?? 0));
        if ($days > 0) {
            $pdo->prepare("UPDATE users SET auto_reset_days = ?, traffic_last_reset_at = COALESCE(traffic_last_reset_at, NOW()) WHERE id = ?")
                ->execute([$days, $userId]);
        } else {
            $pdo->prepare("UPDATE users SET auto_reset_days = ? WHERE id = ?")->execute([$days, $userId]);
        }
        $message = '用户自动重置周期已更新';
    } elseif ($action === 'manual_reset' && $userId) {
        $ok = manualResetUserTraffic($pdo, $userId, $adminId, 'manual_reset', '管理员手动重置');
        $message = $ok ? '用户流量已重置（含解锁清除）' : '操作失败';
    } elseif ($action === 'set_total' && $userId) {
        $newTotal = max(0, (int)($_POST['traffic_total'] ?? 0));
        $u = $pdo->prepare("SELECT traffic_total, traffic_used FROM users WHERE id = ?");
        $u->execute([$userId]);
        $row = $u->fetch();
        if ($row) {
            $pdo->prepare("UPDATE users SET traffic_total = ? WHERE id = ?")->execute([$newTotal, $userId]);
            logTraffic($pdo, $userId, 'set_total', $newTotal - (int)$row['traffic_total'], (int)$row['traffic_total'], (int)$row['traffic_used'], $newTotal, (int)$row['traffic_used'], '修改总流量', $adminId);
            $message = '用户总流量已更新';
        }
    } elseif ($action === 'add_total' && $userId) {
        $delta = (int)($_POST['delta'] ?? 0);
        $u = $pdo->prepare("SELECT traffic_total, traffic_used FROM users WHERE id = ?");
        $u->execute([$userId]);
        $row = $u->fetch();
        if ($row) {
            $newTotal = max(0, (int)$row['traffic_total'] + $delta);
            $pdo->prepare("UPDATE users SET traffic_total = ? WHERE id = ?")->execute([$newTotal, $userId]);
            logTraffic($pdo, $userId, 'add_total', $delta, (int)$row['traffic_total'], (int)$row['traffic_used'], $newTotal, (int)$row['traffic_used'], '增/减流量', $adminId);
            $message = '用户流量已调整';
        }
    } elseif ($action === 'reset' && $userId) {
        $ok = manualResetUserTraffic($pdo, $userId, $adminId, 'reset', '重置为分组默认值');
        $message = $ok ? '用户流量已重置（按所在分组默认值，含清除解锁）' : '操作失败';
    } elseif ($action === 'set_expires' && $userId) {
        $expiresInput = trim($_POST['traffic_expires_at'] ?? '');
        $expires = $expiresInput !== '' ? date('Y-m-d H:i:s', strtotime($expiresInput)) : null;
        $pdo->prepare("UPDATE users SET traffic_expires_at = ? WHERE id = ?")->execute([$expires, $userId]);
        $message = '到期时间已更新';
    } elseif ($action === 'clear_unlocks' && $userId) {
        $pdo->prepare("DELETE FROM video_unlocks WHERE user_id = ?")->execute([$userId]);
        $message = '已清除该用户的全部解锁记录';
    }

    finishPostRequest($message ?: null, $error ?: null, trafficUsersListUrl($activeSection, $keyword, $userPage, $userPerPage));
}

// 后台进入流量管理时，批量执行已到期的自动重置
runDueAutoResets($pdo);

$hasAutoCol = (bool)$pdo->query("SHOW COLUMNS FROM users LIKE 'auto_reset_days'")->fetch();
$autoSelect = $hasAutoCol ? ', u.auto_reset_days, u.traffic_last_reset_at, g.auto_reset_days AS group_auto_reset_days' : '';

$where = '';
$params = [];
if ($keyword !== '') {
    $where = 'WHERE u.username LIKE ? OR u.email LIKE ?';
    $params[] = "%{$keyword}%";
    $params[] = "%{$keyword}%";
}

$totalUserCount = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();

$listUserCount = 0;
$userTotalPages = 1;
$userRangeStart = 0;
$userRangeEnd = 0;
$userOffset = 0;
$users = [];

if ($activeSection === 'users') {
    $countSql = 'SELECT COUNT(*) FROM users u ' . $where;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($params);
    $listUserCount = (int)$countStmt->fetchColumn();

    $userTotalPages = max(1, (int)ceil(max(0, $listUserCount) / $userPerPage));
    if ($userPage > $userTotalPages) {
        $userPage = $userTotalPages;
    }
    $userOffset = ($userPage - 1) * $userPerPage;

    $sql = "SELECT u.id, u.username, u.email, u.group_id, u.status, u.traffic_total, u.traffic_used, u.traffic_expires_at,
            u.traffic_earnings_total, u.traffic_earnings_frozen,
            g.name AS group_name, g.default_traffic, g.traffic_validity_days{$autoSelect}
            FROM users u LEFT JOIN user_groups g ON g.id = u.group_id
            $where
            ORDER BY u.id DESC
            LIMIT ? OFFSET ?";
    $stmt = $pdo->prepare($sql);
    $bindIndex = 1;
    foreach ($params as $param) {
        $stmt->bindValue($bindIndex++, $param);
    }
    $stmt->bindValue($bindIndex++, $userPerPage, PDO::PARAM_INT);
    $stmt->bindValue($bindIndex, $userOffset, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll();

    $userRangeStart = $listUserCount > 0 ? $userOffset + 1 : 0;
    $userRangeEnd = min($listUserCount, $userOffset + count($users));
}

$groups = $pdo->query("SELECT * FROM user_groups ORDER BY id")->fetchAll();

// 视频解锁列表
$unlocks = $pdo->query("SELECT vu.*, u.username, v.title, v.traffic_cost
    FROM video_unlocks vu
    LEFT JOIN users u ON u.id = vu.user_id
    LEFT JOIN videos v ON v.id = vu.video_id
    ORDER BY vu.id DESC LIMIT 50")->fetchAll();

// 流量日志
$logs = $pdo->query("SELECT tl.*, u.username FROM traffic_logs tl
    LEFT JOIN users u ON u.id = tl.user_id
    ORDER BY tl.id DESC LIMIT 50")->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $themeAssetPrefix = '../'; include __DIR__ . '/../components/theme-head.php'; ?>
<title>流量管理 - 竹叶云控平台</title>

<?php include __DIR__ . '/../components/theme-dynamic.php'; ?>
</head>
<body class="bg-gray-100 text-gray-900">
<?php $adminNavActive = 'traffic'; include __DIR__ . '/../components/admin-top-nav.php'; ?>

<main class="mx-auto max-w-screen-xl px-4 py-6 space-y-6">
    <?php
    $userCount = $totalUserCount;
    $unlockCount = is_array($unlocks) ? count($unlocks) : 0;
    $logCount = is_array($logs) ? count($logs) : 0;
    $groupCount = is_array($groups) ? count($groups) : 0;
    ?>

    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-lg font-semibold text-gray-900">流量管理</h1>
            <p class="mt-1 text-xs text-gray-500">配置用户组默认流量、管理用户流量与到期、查看解锁记录与变更日志。</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-2 rounded-full bg-gray-900/90 px-3 py-1 text-white">
                <span class="h-1.5 w-1.5 rounded-full bg-white/70" aria-hidden="true"></span>
                用户 <?= (int)$userCount ?>
            </span>
            <span class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-blue-700 ring-1 ring-blue-100">
                <span class="h-1.5 w-1.5 rounded-full bg-blue-500" aria-hidden="true"></span>
                解锁 <?= (int)$unlockCount ?>
            </span>
            <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-emerald-700 ring-1 ring-emerald-100">
                <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
                日志 <?= (int)$logCount ?>
            </span>
        </div>
    </div>

    <?php if ($message): ?>
        <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
    <?php endif; ?>

    <div class="flex gap-4 items-start">
        <?php
        include __DIR__ . '/../components/admin-traffic-sidebar.php';
        ?>

        <section class="min-w-0 flex-1 space-y-6">
            <?php if ($activeSection === 'overview'): ?>
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <p class="text-sm font-semibold text-gray-900">总览</p>
                        <p class="mt-1 text-xs text-gray-500">从左侧选择功能模块进入管理。</p>
                    </div>
                    <div class="px-5 py-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <a href="?section=groups" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                            <p class="text-sm font-semibold text-gray-900">用户组配置</p>
                            <p class="mt-1 text-xs text-gray-500">默认流量 / 有效期 / 自动重置</p>
                        </a>
                        <a href="?section=users" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                            <p class="text-sm font-semibold text-gray-900">用户流量</p>
                            <p class="mt-1 text-xs text-gray-500">搜索用户并集中管理</p>
                        </a>
                        <a href="?section=unlocks" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                            <p class="text-sm font-semibold text-gray-900">解锁记录</p>
                            <p class="mt-1 text-xs text-gray-500">最近 50 条</p>
                        </a>
                        <a href="?section=logs" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                            <p class="text-sm font-semibold text-gray-900">变更日志</p>
                            <p class="mt-1 text-xs text-gray-500">最近 50 条</p>
                        </a>
                    </div>
                </div>
            <?php elseif ($activeSection === 'groups'): ?>
                <section class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <p class="text-sm font-semibold text-gray-900">用户组默认流量配置</p>
                        <p class="mt-1 text-xs text-gray-500">设置分组默认流量、有效期与自动重置周期（如启用）。</p>
                    </div>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">分组</th>
                                    <th class="px-3 py-2">默认流量</th>
                                    <th class="px-3 py-2">有效期(天，0=永久)</th>
                                    <?php if ($hasAutoCol): ?>
                                    <th class="px-3 py-2">自动重置周期(天，0=关闭)</th>
                                    <?php endif; ?>
                                    <th class="px-3 py-2">操作</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($groups as $g): ?>
                                <?php $gid = (int)$g['id']; $formId = 'gform_' . $gid; $bulkId = 'gbulk_' . $gid; ?>
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-3 py-2">
                                        <div class="font-medium text-gray-900"><?= htmlspecialchars((string)$g['name'], ENT_QUOTES, 'UTF-8') ?></div>
                                        <form id="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>" method="POST"></form>
                                        <input form="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>" type="hidden" name="action" value="group_default">
                                        <input form="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>" type="hidden" name="group_id" value="<?= $gid ?>">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input form="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>" type="number" name="default_traffic" min="0"
                                               value="<?= (int)($g['default_traffic'] ?? 0) ?>"
                                               class="w-28 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:outline-none">
                                    </td>
                                    <td class="px-3 py-2">
                                        <input form="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>" type="number" name="traffic_validity_days" min="0"
                                               value="<?= (int)($g['traffic_validity_days'] ?? 0) ?>"
                                               class="w-28 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:outline-none">
                                    </td>
                                    <?php if ($hasAutoCol): ?>
                                    <td class="px-3 py-2">
                                        <div class="flex flex-wrap gap-1.5 mb-1.5">
                                            <?php foreach ([0,1,7,14,30] as $preset): ?>
                                                <button type="button" data-input="auto_reset_days_<?= $gid ?>" data-val="<?= (int)$preset ?>"
                                                        class="reset-quick rounded-lg border border-gray-200 bg-white px-2 py-0.5 text-[11px] text-gray-700 hover:border-blue-300 hover:bg-blue-50">
                                                    <?= $preset === 0 ? '关闭' : ($preset . '天') ?>
                                                </button>
                                            <?php endforeach; ?>
                                        </div>
                                        <input form="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>" type="number" name="auto_reset_days"
                                               id="auto_reset_days_<?= $gid ?>" min="0"
                                               value="<?= (int)($g['auto_reset_days'] ?? 0) ?>"
                                               class="w-28 rounded-lg border border-gray-300 px-2.5 py-1.5 text-sm focus:border-blue-500 focus:outline-none">
                                    </td>
                                    <?php endif; ?>
                                    <td class="px-3 py-2">
                                        <div class="flex flex-wrap gap-2">
                                            <button form="<?= htmlspecialchars($formId, ENT_QUOTES, 'UTF-8') ?>" type="submit"
                                                    class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-blue-700">
                                                保存
                                            </button>

                                            <?php if ($hasAutoCol): ?>
                                                <form id="<?= htmlspecialchars($bulkId, ENT_QUOTES, 'UTF-8') ?>" method="POST"></form>
                                                <input form="<?= htmlspecialchars($bulkId, ENT_QUOTES, 'UTF-8') ?>" type="hidden" name="action" value="group_bulk_reset">
                                                <input form="<?= htmlspecialchars($bulkId, ENT_QUOTES, 'UTF-8') ?>" type="hidden" name="group_id" value="<?= $gid ?>">
                                                <button form="<?= htmlspecialchars($bulkId, ENT_QUOTES, 'UTF-8') ?>" type="submit"
                                                        class="rounded-lg border border-amber-200 bg-amber-50 px-3 py-1.5 text-xs font-medium text-amber-800 hover:bg-amber-100"
                                                        onclick="return confirm('确定一键重置该用户组下全部用户的流量？\n• 流量按分组默认值发放\n• 已用流量清零\n• 清除该组用户全部解锁记录\n\n此操作不可撤销！');">
                                                    一键重置本组
                                                </button>
                                            <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                </section>
            <?php elseif ($activeSection === 'users'): ?>
                <section class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-gray-900">用户流量</p>
                                <p class="mt-1 text-xs text-gray-500">
                                    支持按用户名/邮箱搜索，点击「管理」集中操作。
                                    <?php if ($keyword !== ''): ?>
                                        搜索「<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>」共 <?= (int)$listUserCount ?> 条。
                                    <?php else: ?>
                                        共 <?= (int)$listUserCount ?> 条。
                                    <?php endif; ?>
                                    <?php if ($listUserCount > 0): ?>
                                        当前第 <?= (int)$userPage ?> / <?= (int)$userTotalPages ?> 页（<?= (int)$userRangeStart ?>–<?= (int)$userRangeEnd ?>）。
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="flex flex-wrap items-end gap-2">
                                <form method="GET" class="flex flex-wrap items-end gap-2">
                                    <input type="hidden" name="section" value="users">
                                    <input type="hidden" name="page" value="1">
                                    <?php if ($keyword !== ''): ?>
                                        <input type="hidden" name="q" value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>">
                                    <?php endif; ?>
                                    <div>
                                        <label class="mb-1 block text-[11px] text-gray-500" for="per_page">每页</label>
                                        <select id="per_page" name="per_page"
                                                class="rounded-lg border border-gray-300 px-2 py-2 text-sm focus:border-blue-500 focus:outline-none"
                                                onchange="this.form.submit()">
                                            <?php foreach (trafficUsersPageSizeOptions() as $size): ?>
                                                <option value="<?= $size ?>" <?= $userPerPage === $size ? 'selected' : '' ?>><?= $size ?> 条</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>
                                <form method="GET" class="flex flex-wrap items-end gap-2">
                                    <input type="hidden" name="section" value="users">
                                    <input type="hidden" name="per_page" value="<?= (int)$userPerPage ?>">
                                    <div class="min-w-[220px]">
                                        <label class="mb-1 block text-[11px] text-gray-500" for="q">搜索</label>
                                        <input id="q" type="text" name="q" value="<?php echo htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8'); ?>" placeholder="用户名 / 邮箱"
                                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                                    </div>
                                    <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">搜索</button>
                                    <?php if ($keyword !== ''): ?>
                                        <a href="<?= htmlspecialchars(trafficUsersListUrl('users', '', 1, $userPerPage), ENT_QUOTES, 'UTF-8') ?>" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">重置</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php if (empty($users)): ?>
                        <div class="px-5 py-10 text-center">
                            <p class="text-sm font-semibold text-gray-900"><?= $keyword !== '' ? '未找到匹配用户' : '暂无用户' ?></p>
                            <p class="mt-1 text-xs text-gray-500"><?= $keyword !== '' ? '请尝试其他关键词。' : '当前没有可管理的用户记录。' ?></p>
                        </div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">ID</th>
                                    <th class="px-3 py-2">用户</th>
                                    <th class="px-3 py-2">分组</th>
                                    <th class="px-3 py-2">基础流量</th>
                                    <th class="px-3 py-2">收益流量</th>
                                    <th class="px-3 py-2">到期时间</th>
                                    <?php if ($hasAutoCol): ?>
                                    <th class="px-3 py-2">自动重置</th>
                                    <?php endif; ?>
                                    <th class="px-3 py-2">操作</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($users as $u): ?>
                                    <?php
                                    $total = (int)$u['traffic_total'];
                                    $used  = (int)$u['traffic_used'];
                                    $left  = max(0, $total - $used);
                                    $expires = $u['traffic_expires_at'];
                                    $expired = $expires && strtotime($expires) <= time();
                                    $userAuto  = $hasAutoCol ? (int)($u['auto_reset_days'] ?? 0) : 0;
                                    $groupAuto = $hasAutoCol ? (int)($u['group_auto_reset_days'] ?? 0) : 0;
                                    $effectiveAuto = $userAuto > 0 ? $userAuto : $groupAuto;
                                    $lastReset = $hasAutoCol ? ($u['traffic_last_reset_at'] ?? null) : null;
                                    $nextResetAt = ($effectiveAuto > 0)
                                        ? computeNextAutoResetAt($lastReset, $effectiveAuto, $lastReset ?: date('Y-m-d H:i:s'))
                                        : null;
                                    ?>
                                    <tr class="hover:bg-gray-50/60">
                                        <td class="px-3 py-2"><?php echo (int)$u['id']; ?></td>
                                        <td class="px-3 py-2 font-medium">
                                            <?php echo htmlspecialchars((string)$u['username'], ENT_QUOTES, 'UTF-8'); ?>
                                            <div class="text-xs text-gray-500"><?php echo htmlspecialchars((string)$u['email'], ENT_QUOTES, 'UTF-8'); ?></div>
                                        </td>
                                        <td class="px-3 py-2"><?php echo htmlspecialchars((string)($u['group_name'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                        <td class="px-3 py-2">
                                            <div class="text-xs text-gray-500">总/已用</div>
                                            <div><?php echo $total; ?> / <?php echo $used; ?></div>
                                            <div class="mt-1 inline-flex items-center rounded-full px-2.5 py-1 text-xs font-semibold <?php echo $left > 0 && !$expired ? 'bg-emerald-50 text-emerald-700 ring-1 ring-emerald-100' : 'bg-red-50 text-red-700 ring-1 ring-red-100'; ?>">
                                                剩余 <?php echo $left; ?>
                                            </div>
                                        </td>
                                        <td class="px-3 py-2">
                                            <div class="text-emerald-700">可用 <?php echo (int)($u['traffic_earnings_total'] ?? 0); ?></div>
                                            <div class="text-xs text-amber-700">冻结 <?php echo (int)($u['traffic_earnings_frozen'] ?? 0); ?></div>
                                        </td>
                                        <td class="px-3 py-2 text-xs">
                                            <?php if (!$expires): ?>
                                                <span class="text-gray-500">永久</span>
                                            <?php else: ?>
                                                <span class="<?php echo $expired ? 'text-red-500' : 'text-gray-700'; ?>"><?php echo htmlspecialchars((string)$expires, ENT_QUOTES, 'UTF-8'); ?></span>
                                                <?php if ($expired): ?><span class="ml-1 rounded-full bg-red-50 px-2 py-0.5 text-red-700 ring-1 ring-red-100">已过期</span><?php endif; ?>
                                            <?php endif; ?>
                                        </td>
                                        <?php if ($hasAutoCol): ?>
                                        <td class="px-3 py-2 text-xs">
                                            <?php if ($effectiveAuto > 0): ?>
                                                <span class="rounded-full bg-blue-100 px-2 py-0.5 text-blue-700">每 <?= (int)$effectiveAuto ?> 天</span>
                                                <span class="ml-1 text-gray-400"><?= $userAuto > 0 ? '(自定义)' : '(分组)' ?></span>
                                                <?php if (!$lastReset): ?>
                                                    <div class="mt-1 text-amber-600">未开始计时</div>
                                                <?php elseif ($nextResetAt): ?>
                                                    <?php $nextResetDue = strtotime($nextResetAt) <= time(); ?>
                                                    <div class="mt-1 <?= $nextResetDue ? 'text-red-600 font-medium' : 'text-gray-500' ?>">
                                                        下次：<?= htmlspecialchars(formatChinaDateTime($nextResetAt, 'Y-m-d H:i'), ENT_QUOTES, 'UTF-8') ?>
                                                        <?= $nextResetDue ? '（已到期，待重置）' : '' ?>
                                                    </div>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                <span class="text-gray-400">关闭</span>
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td class="px-3 py-2">
                                            <button type="button"
                                                    class="rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-black"
                                                    onclick='openModal("manage_user", <?php echo json_encode([
                                                        "id" => (int)$u["id"],
                                                        "username" => (string)$u["username"],
                                                        "total" => $total,
                                                        "used" => $used,
                                                        "expires" => $expires,
                                                        "user_auto" => $hasAutoCol ? $userAuto : 0,
                                                        "group_auto" => $hasAutoCol ? $groupAuto : 0,
                                                        "has_auto" => $hasAutoCol ? 1 : 0,
                                                    ]); ?>)'>
                                                管理
                                            </button>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php if ($userTotalPages > 1): ?>
                        <div class="flex flex-wrap items-center justify-center gap-2 border-t border-gray-100 px-5 py-4">
                            <?php if ($userPage > 1): ?>
                                <a href="<?= htmlspecialchars(trafficUsersListUrl('users', $keyword, 1, $userPerPage), ENT_QUOTES, 'UTF-8') ?>"
                                   class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">首页</a>
                                <a href="<?= htmlspecialchars(trafficUsersListUrl('users', $keyword, $userPage - 1, $userPerPage), ENT_QUOTES, 'UTF-8') ?>"
                                   class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">上一页</a>
                            <?php endif; ?>

                            <?php
                            $pageWindowStart = max(1, $userPage - 2);
                            $pageWindowEnd = min($userTotalPages, $userPage + 2);
                            for ($p = $pageWindowStart; $p <= $pageWindowEnd; $p++):
                            ?>
                                <a href="<?= htmlspecialchars(trafficUsersListUrl('users', $keyword, $p, $userPerPage), ENT_QUOTES, 'UTF-8') ?>"
                                   class="rounded-lg px-3 py-1.5 text-xs <?= $p === $userPage ? 'bg-gray-900 text-white' : 'border border-gray-200 text-gray-700 hover:bg-gray-50' ?>">
                                    <?= $p ?>
                                </a>
                            <?php endfor; ?>

                            <?php if ($userPage < $userTotalPages): ?>
                                <a href="<?= htmlspecialchars(trafficUsersListUrl('users', $keyword, $userPage + 1, $userPerPage), ENT_QUOTES, 'UTF-8') ?>"
                                   class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">下一页</a>
                                <a href="<?= htmlspecialchars(trafficUsersListUrl('users', $keyword, $userTotalPages, $userPerPage), ENT_QUOTES, 'UTF-8') ?>"
                                   class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">末页</a>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                    <?php endif; ?>
                </section>
            <?php elseif ($activeSection === 'unlocks'): ?>
                <section class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <p class="text-sm font-semibold text-gray-900">最近 50 条视频解锁记录</p>
                        <p class="mt-1 text-xs text-gray-500">解锁有效期跟随用户流量周期；流量自动/手动重置时该记录会被清除。</p>
                    </div>
                    <?php if (empty($unlocks)): ?>
                        <div class="px-5 py-6 text-sm text-gray-500">暂无解锁记录</div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">用户</th>
                                    <th class="px-3 py-2">视频</th>
                                    <th class="px-3 py-2">花费</th>
                                    <th class="px-3 py-2">有效期</th>
                                    <th class="px-3 py-2">支付时间</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($unlocks as $r): ?>
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-3 py-2"><?php echo htmlspecialchars((string)($r['username'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="px-3 py-2"><?php echo htmlspecialchars((string)($r['title'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="px-3 py-2"><?php echo (int)($r['cost'] ?? 0); ?></td>
                                    <td class="px-3 py-2 text-xs text-gray-700">跟随流量周期</td>
                                    <td class="px-3 py-2 text-xs text-gray-500"><?php echo htmlspecialchars((string)($r['paid_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </section>
            <?php else: ?>
                <section class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <p class="text-sm font-semibold text-gray-900">最近 50 条流量变更日志</p>
                        <p class="mt-1 text-xs text-gray-500">展示管理员调整与系统自动重置等行为的记录。</p>
                    </div>
                    <?php if (empty($logs)): ?>
                        <div class="px-5 py-6 text-sm text-gray-500">暂无变更记录</div>
                    <?php else: ?>
                    <div class="overflow-x-auto">
                        <table class="min-w-full text-left text-sm">
                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-3 py-2">用户</th>
                                    <th class="px-3 py-2">动作</th>
                                    <th class="px-3 py-2">变化</th>
                                    <th class="px-3 py-2">变更前(总/已用)</th>
                                    <th class="px-3 py-2">变更后(总/已用)</th>
                                    <th class="px-3 py-2">备注</th>
                                    <th class="px-3 py-2">时间</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y divide-gray-100">
                                <?php foreach ($logs as $r): ?>
                                <tr class="hover:bg-gray-50/60">
                                    <td class="px-3 py-2"><?php echo htmlspecialchars((string)($r['username'] ?? '-'), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="px-3 py-2 text-xs"><?php echo htmlspecialchars((string)($r['action'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="px-3 py-2 <?php echo (int)($r['change_amount'] ?? 0) >= 0 ? 'text-green-600' : 'text-red-500'; ?>">
                                        <?php echo (int)($r['change_amount'] ?? 0); ?>
                                    </td>
                                    <td class="px-3 py-2"><?php echo (int)($r['before_total'] ?? 0) . ' / ' . (int)($r['before_used'] ?? 0); ?></td>
                                    <td class="px-3 py-2"><?php echo (int)($r['after_total'] ?? 0) . ' / ' . (int)($r['after_used'] ?? 0); ?></td>
                                    <td class="px-3 py-2 text-xs"><?php echo htmlspecialchars((string)($r['remark'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                    <td class="px-3 py-2 text-xs text-gray-500"><?php echo htmlspecialchars((string)($r['created_at'] ?? ''), ENT_QUOTES, 'UTF-8'); ?></td>
                                </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    <?php endif; ?>
                </section>
            <?php endif; ?>
        </section>
    </div>
</main>

<!-- 通用模态 -->
<div id="trafficModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50" style="display:none;">
    <div class="relative w-full max-w-md rounded-2xl border border-gray-200 bg-white p-5 shadow-xl">
        <button class="absolute right-3 top-2 text-2xl leading-none text-gray-400 hover:text-gray-700" type="button" onclick="closeModal()">&times;</button>
        <div id="modalBody"></div>
    </div>
</div>

<script>
let trafficModalData = null;

function openModal(action, data) {
    if (data != null) {
        trafficModalData = data;
    } else {
        data = trafficModalData;
    }
    if (!data) return;

    const body = document.getElementById('modalBody');
    let html = '';
    if (action === 'manage_user') {
        const curExpires = data.expires ? data.expires : '';
        const baseLeft = Math.max(0, Number(data.total || 0) - Number(data.used || 0));
        html = `
            <div class="flex items-start justify-between gap-3">
                <div class="min-w-0">
                    <h2 class="text-base font-semibold text-gray-900">用户管理 - ${escapeHtml(data.username)}</h2>
                    <p class="mt-1 text-xs text-gray-500">把常用操作集中到一个入口，减少表格凌乱。</p>
                </div>
                <span class="shrink-0 rounded-full bg-gray-50 px-3 py-1 text-xs text-gray-700 ring-1 ring-gray-200">#${data.id}</span>
            </div>

            <div class="mt-4 grid gap-2 sm:grid-cols-2">
                <div class="rounded-xl border border-gray-200 bg-white p-3">
                    <p class="text-xs text-gray-500">基础流量</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900">总 ${Number(data.total || 0)} / 已用 ${Number(data.used || 0)}</p>
                    <p class="mt-1 text-xs ${baseLeft > 0 ? 'text-emerald-700' : 'text-red-700'}">剩余 ${baseLeft}</p>
                </div>
                <div class="rounded-xl border border-gray-200 bg-white p-3">
                    <p class="text-xs text-gray-500">到期时间</p>
                    <p class="mt-1 text-sm font-semibold text-gray-900">${curExpires ? escapeHtml(curExpires) : '永久'}</p>
                    <p class="mt-1 text-xs text-gray-500">可在下方操作里修改</p>
                </div>
            </div>

            <div class="mt-4 grid gap-2 sm:grid-cols-2">
                <button type="button" class="rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700" onclick="openModal('set_total')">修改总流量</button>
                <button type="button" class="rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700" onclick="openModal('add_total')">增/减流量</button>
                <button type="button" class="rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-amber-600" onclick="openModal('set_expires')">设置到期时间</button>
                ${data.has_auto ? `<button type="button" class="rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700" onclick="openModal('user_auto_reset', {id:${Number(data.id)}, username:${JSON.stringify(String(data.username || ''))}, days:${Number(data.user_auto || 0)}, group_days:${Number(data.group_auto || 0)}})">自动重置</button>` : `<div class="rounded-xl border border-gray-200 bg-gray-50 px-4 py-2.5 text-sm text-gray-500">未启用自动重置字段</div>`}
            </div>

            <div class="mt-4 rounded-xl border border-red-200 bg-red-50 p-3">
                <p class="text-sm font-semibold text-red-800">危险操作</p>
                <p class="mt-1 text-xs text-red-700">这些操作会影响用户观看权益或清除解锁记录。</p>
                <div class="mt-3 flex flex-wrap gap-2">
                    <form method="POST" class="inline" onsubmit="return confirm('确定立即重置该用户流量？\\n• 总流量按分组默认值发放\\n• 已用归零、清除全部解锁记录\\n• 重置周期重新计时');">
                        <input type="hidden" name="action" value="manual_reset">
                        <input type="hidden" name="user_id" value="${data.id}">
                        <button type="submit" class="rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-medium text-white hover:bg-black">立即重置</button>
                    </form>
                    <form method="POST" class="inline" onsubmit="return confirm('确定清除该用户全部解锁记录？');">
                        <input type="hidden" name="action" value="clear_unlocks">
                        <input type="hidden" name="user_id" value="${data.id}">
                        <button type="submit" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-medium text-white hover:bg-red-700">清除解锁</button>
                    </form>
                </div>
            </div>
        `;
    } else if (action === 'set_total') {
        html = `<h2 class="mb-3 text-base font-semibold text-gray-900">修改总流量 - ${escapeHtml(data.username)}</h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="set_total">
            <input type="hidden" name="user_id" value="${data.id}">
            <label class="block text-sm font-medium text-gray-700">新的总流量</label>
            <input type="number" name="traffic_total" min="0" value="${data.total}" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
            <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">保存</button>
        </form>`;
    } else if (action === 'add_total') {
        html = `<h2 class="mb-3 text-base font-semibold text-gray-900">增减流量 - ${escapeHtml(data.username)}</h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="add_total">
            <input type="hidden" name="user_id" value="${data.id}">
            <label class="block text-sm font-medium text-gray-700">变化值（可为负数，例如 -100 表示扣除 100）</label>
            <input type="number" name="delta" value="0" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
            <button type="submit" class="w-full rounded-xl bg-emerald-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-emerald-700">提交</button>
        </form>`;
    } else if (action === 'set_expires') {
        const cur = data.expires ? data.expires.replace(' ', 'T').slice(0,16) : '';
        html = `<h2 class="mb-3 text-base font-semibold text-gray-900">设置流量到期时间 - ${escapeHtml(data.username)}</h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="set_expires">
            <input type="hidden" name="user_id" value="${data.id}">
            <label class="block text-sm font-medium text-gray-700">到期时间（留空表示永久）</label>
            <input type="datetime-local" name="traffic_expires_at" value="${cur}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
            <button type="submit" class="w-full rounded-xl bg-amber-500 px-4 py-2.5 text-sm font-semibold text-white hover:bg-amber-600">保存</button>
        </form>`;
    } else if (action === 'user_auto_reset') {
        const presets = [0,1,7,14,30];
        const presetBtns = presets.map(d => `
            <button type="button" data-val="${d}"
                class="ar-preset rounded-lg border border-gray-200 bg-white px-3 py-1 text-xs text-gray-700 hover:border-blue-300 hover:bg-blue-50 ${String(d) === String(data.days) ? 'bg-blue-600 text-white border-blue-600 hover:bg-blue-700' : ''}">
                ${d === 0 ? '关闭(跟随分组)' : (d + ' 天')}
            </button>`).join('');
        html = `<h2 class="mb-3 text-base font-semibold text-gray-900">单独设置自动重置 - ${escapeHtml(data.username)}</h2>
        <form method="POST" class="space-y-3">
            <input type="hidden" name="action" value="user_auto_reset">
            <input type="hidden" name="user_id" value="${data.id}">
            <p class="text-xs text-gray-500">分组默认周期：${data.group_days > 0 ? '每 ' + data.group_days + ' 天' : '关闭'}</p>
            <div class="flex flex-wrap gap-1.5">${presetBtns}</div>
            <label class="block text-sm font-medium text-gray-700">自定义天数（0=跟随分组配置）</label>
            <input type="number" name="auto_reset_days" id="ar_days" min="0" value="${data.days}" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
            <p class="text-xs text-gray-500">⚠ 自动重置时会重新发放总流量、清空已用流量并清除该用户全部解锁记录。</p>
            <button type="submit" class="w-full rounded-xl bg-indigo-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-indigo-700">保存</button>
        </form>`;
    }
    body.innerHTML = html;
    document.getElementById('trafficModal').style.display = 'flex';

    // 自动重置预设按钮交互
    body.querySelectorAll('.ar-preset').forEach(btn => {
        btn.addEventListener('click', () => {
            body.querySelectorAll('.ar-preset').forEach(b => b.classList.remove('bg-blue-600','text-white','border-blue-600','hover:bg-blue-700'));
            btn.classList.add('bg-blue-600','text-white','border-blue-600','hover:bg-blue-700');
            const inp = document.getElementById('ar_days');
            if (inp) inp.value = btn.dataset.val;
        });
    });
}

// 分组表里的快捷预设按钮
document.querySelectorAll('.reset-quick').forEach(btn => {
    btn.addEventListener('click', () => {
        const inp = document.getElementById(btn.dataset.input);
        if (inp) inp.value = btn.dataset.val;
    });
});
function closeModal() { document.getElementById('trafficModal').style.display = 'none'; }
function escapeHtml(s) {
    return String(s ?? '').replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}
window.addEventListener('click', e => {
    const m = document.getElementById('trafficModal');
    if (e.target === m) closeModal();
});
</script>
</body>
</html>
