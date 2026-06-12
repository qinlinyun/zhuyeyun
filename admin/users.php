<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/flash.php';
require_once '../includes/ban_notice.php';
require_once '../includes/admin_users.php';

requireAdmin();

$pdo = getDB();
$message = '';
$error = '';
applyFlash($message, $error);

// 页面分区（左侧菜单）
$activeSection = trim((string)($_GET['section'] ?? ''));
if (!in_array($activeSection, adminUsersSections(), true)) {
    $activeSection = 'overview';
}

function adminUsersPageSizeOptions(): array
{
    return [10, 20, 30, 50];
}

function normalizeAdminUsersPageSize(int $size): int
{
    return in_array($size, adminUsersPageSizeOptions(), true) ? $size : 10;
}

function normalizeAdminUsersPage(int $page): int
{
    return max(1, $page);
}

function adminUsersListUrl(string $section, string $keyword, int $page, int $perPage): string
{
    $query = [
        'section' => $section,
        'page' => normalizeAdminUsersPage($page),
        'per_page' => normalizeAdminUsersPageSize($perPage),
    ];
    if ($keyword !== '') {
        $query['q'] = $keyword;
    }

    return 'users.php?' . http_build_query($query);
}

$keyword = trim((string)($_GET['q'] ?? ''));
$userPage = normalizeAdminUsersPage((int)($_GET['page'] ?? 1));
$userPerPage = normalizeAdminUsersPageSize((int)($_GET['per_page'] ?? 10));

$globalStats = adminUsersCountStats($pdo, '');
$sidebarUserCount = (int)$globalStats['total'];
$sidebarBannedCount = (int)$globalStats['banned'];
$sidebarFrozenCount = (int)$globalStats['frozen'];
$sidebarTimedBanCount = (int)$globalStats['timed_ban'];

$userStats = adminUsersCountStats($pdo, $keyword);
$activeCount = (int)$userStats['active'];
$bannedCount = (int)$userStats['banned'];
$frozenCount = (int)$userStats['frozen'];

[$userWhereSql, $userListParams] = adminUsersBuildListWhere(
    in_array($activeSection, adminUsersListSections(), true) ? $activeSection : 'users',
    $keyword
);

$userListTitle = adminUsersSectionTitle($activeSection);
$isListSection = in_array($activeSection, adminUsersListSections(), true);

if ($isListSection) {
    $countSql = 'SELECT COUNT(*) FROM users u' . $userWhereSql;
    $countStmt = $pdo->prepare($countSql);
    $countStmt->execute($userListParams);
    $userCount = (int)$countStmt->fetchColumn();
} else {
    $userCount = (int)$userStats['total'];
}

$userTotalPages = max(1, (int)ceil(max(0, $userCount) / $userPerPage));
if ($userPage > $userTotalPages) {
    $userPage = $userTotalPages;
}
$userOffset = ($userPage - 1) * $userPerPage;

$users = [];
if ($isListSection) {
    $userListSql = "
        SELECT u.*, g.name AS group_name
        FROM users u
        LEFT JOIN user_groups g ON u.group_id = g.id
        {$userWhereSql}
        ORDER BY u.created_at DESC
        LIMIT ? OFFSET ?";

    $stmt = $pdo->prepare($userListSql);
    $bindIndex = 1;
    foreach ($userListParams as $param) {
        $stmt->bindValue($bindIndex++, $param);
    }
    $stmt->bindValue($bindIndex++, $userPerPage, PDO::PARAM_INT);
    $stmt->bindValue($bindIndex, $userOffset, PDO::PARAM_INT);
    $stmt->execute();
    $users = $stmt->fetchAll();
}

$userRangeStart = $userCount > 0 ? $userOffset + 1 : 0;
$userRangeEnd = min($userCount, $userOffset + count($users));

$groups = $pdo->query("SELECT * FROM user_groups ORDER BY id")->fetchAll();
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>用户管理 - 竹叶云控平台</title>
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<?php $themeAssetPrefix = '../'; include __DIR__ . '/../components/theme-head.php'; ?>
<?php include __DIR__ . '/../components/theme-dynamic.php'; ?>
</head>

<body class="bg-gray-100 text-gray-900">

<?php $adminNavActive = 'users'; include __DIR__ . '/../components/admin-top-nav.php'; ?>

<main class="mx-auto max-w-screen-xl px-4 py-6 space-y-5">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="min-w-0">
            <h1 class="text-lg font-semibold text-gray-900">用户管理</h1>
            <p class="mt-1 text-xs text-gray-500">封禁、冻结、删除、重置密码与更改分组（操作已集中到“管理”弹窗）。</p>
        </div>
        <div class="flex flex-wrap items-center gap-2 text-xs">
            <span class="inline-flex items-center gap-2 rounded-full bg-gray-900/90 px-3 py-1 text-white" data-stat-total-wrap>
                <span class="h-1.5 w-1.5 rounded-full bg-white/70" aria-hidden="true"></span>
                总数 <span data-stat-total><?= (int)$userStats['total'] ?></span>
            </span>
            <span class="inline-flex items-center gap-2 rounded-full bg-green-50 px-3 py-1 text-green-700 ring-1 ring-green-100">
                <span class="h-1.5 w-1.5 rounded-full bg-green-500" aria-hidden="true"></span>
                正常 <span data-stat-active><?= (int)$activeCount ?></span>
            </span>
            <span class="inline-flex items-center gap-2 rounded-full bg-red-50 px-3 py-1 text-red-700 ring-1 ring-red-100" data-stat-banned-wrap <?= $bannedCount > 0 ? '' : 'hidden' ?>>
                <span class="h-1.5 w-1.5 rounded-full bg-red-500" aria-hidden="true"></span>
                封禁 <span data-stat-banned><?= (int)$bannedCount ?></span>
            </span>
            <span class="inline-flex items-center gap-2 rounded-full bg-amber-50 px-3 py-1 text-amber-800 ring-1 ring-amber-100" data-stat-frozen-wrap <?= $frozenCount > 0 ? '' : 'hidden' ?>>
                <span class="h-1.5 w-1.5 rounded-full bg-amber-500" aria-hidden="true"></span>
                冻结 <span data-stat-frozen><?= (int)$frozenCount ?></span>
            </span>
        </div>
    </div>

<?php if ($message): ?>
    <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700">
        <?= htmlspecialchars($message, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700">
        <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?>
    </div>
<?php endif; ?>

    <div class="flex gap-4 items-start">
        <?php
        $userCount = $sidebarUserCount;
        $bannedCount = $sidebarBannedCount;
        $frozenCount = $sidebarFrozenCount;
        $timedBanCount = $sidebarTimedBanCount;
        include __DIR__ . '/../components/admin-users-sidebar.php';
        ?>

        <section class="min-w-0 flex-1 space-y-6">
            <?php if ($activeSection === 'overview'): ?>
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <p class="text-sm font-semibold text-gray-900">总览</p>
                        <p class="mt-1 text-xs text-gray-500">从左侧选择模块进入管理。</p>
                    </div>
                    <div class="px-5 py-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                        <a href="?section=users" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                            <p class="text-sm font-semibold text-gray-900">用户列表</p>
                            <p class="mt-1 text-xs text-gray-500">全部用户 · <?= (int)$sidebarUserCount ?> 人</p>
                        </a>
                        <a href="?section=banned" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                            <p class="text-sm font-semibold text-gray-900">封禁用户</p>
                            <p class="mt-1 text-xs text-gray-500">已封禁 · <?= (int)$sidebarBannedCount ?> 人</p>
                        </a>
                        <a href="?section=frozen" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                            <p class="text-sm font-semibold text-gray-900">冻结用户</p>
                            <p class="mt-1 text-xs text-gray-500">已冻结 · <?= (int)$sidebarFrozenCount ?> 人</p>
                        </a>
                        <a href="?section=timed_ban" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                            <p class="text-sm font-semibold text-gray-900">定时封禁</p>
                            <p class="mt-1 text-xs text-gray-500">限时封禁 · <?= (int)$sidebarTimedBanCount ?> 人</p>
                        </a>
                    </div>
                </div>
            <?php else: ?>
                <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                    <div class="border-b border-gray-100 px-5 py-3">
                        <div class="flex flex-wrap items-end justify-between gap-3">
                            <div>
                                <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($userListTitle, ENT_QUOTES, 'UTF-8') ?></p>
                                <p class="mt-1 text-xs text-gray-500" id="usersListSummary" data-keyword="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>" data-page="<?= (int)$userPage ?>" data-total-pages="<?= (int)$userTotalPages ?>" data-range-start="<?= (int)$userRangeStart ?>" data-range-end="<?= (int)$userRangeEnd ?>" data-total="<?= (int)$userCount ?>">
                                    <?php if ($keyword !== ''): ?>
                                        搜索「<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>」共 <?= (int)$userCount ?> 条。每行操作已收纳到「管理」。
                                    <?php else: ?>
                                        共 <?= (int)$userCount ?> 条。每行操作已收纳到「管理」。
                                    <?php endif; ?>
                                    <?php if ($userCount > 0): ?>
                                        当前第 <?= (int)$userPage ?> / <?= (int)$userTotalPages ?> 页（<?= (int)$userRangeStart ?>–<?= (int)$userRangeEnd ?>）。
                                    <?php endif; ?>
                                </p>
                            </div>
                            <div class="flex flex-wrap items-end gap-2">
                                <form method="GET" class="flex flex-wrap items-end gap-2">
                                    <input type="hidden" name="section" value="<?= htmlspecialchars($activeSection, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="page" value="1">
                                    <?php if ($keyword !== ''): ?>
                                        <input type="hidden" name="q" value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>">
                                    <?php endif; ?>
                                    <div>
                                        <label class="mb-1 block text-[11px] text-gray-500" for="per_page">每页</label>
                                        <select id="per_page" name="per_page"
                                                class="rounded-lg border border-gray-300 px-2 py-2 text-sm focus:border-blue-500 focus:outline-none"
                                                onchange="this.form.submit()">
                                            <?php foreach (adminUsersPageSizeOptions() as $size): ?>
                                                <option value="<?= $size ?>" <?= $userPerPage === $size ? 'selected' : '' ?>><?= $size ?> 条</option>
                                            <?php endforeach; ?>
                                        </select>
                                    </div>
                                </form>
                                <form method="GET" class="flex flex-wrap items-end gap-2">
                                    <input type="hidden" name="section" value="<?= htmlspecialchars($activeSection, ENT_QUOTES, 'UTF-8') ?>">
                                    <input type="hidden" name="per_page" value="<?= (int)$userPerPage ?>">
                                    <div class="min-w-[220px]">
                                        <label class="mb-1 block text-[11px] text-gray-500" for="q">搜索</label>
                                        <input id="q" type="text" name="q" value="<?= htmlspecialchars($keyword, ENT_QUOTES, 'UTF-8') ?>" placeholder="账户名 / 邮箱"
                                               class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                                    </div>
                                    <button type="submit" class="rounded-lg bg-gray-900 px-4 py-2 text-sm font-medium text-white hover:bg-black">搜索</button>
                                    <?php if ($keyword !== ''): ?>
                                        <a href="<?= htmlspecialchars(adminUsersListUrl($activeSection, '', 1, $userPerPage), ENT_QUOTES, 'UTF-8') ?>" class="rounded-lg border border-gray-200 bg-white px-3 py-2 text-sm text-gray-700 hover:bg-gray-50">重置</a>
                                    <?php endif; ?>
                                </form>
                            </div>
                        </div>
                    </div>
                    <?php if (empty($users)): ?>
                        <div class="px-5 py-10 text-center">
                            <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars(adminUsersSectionEmptyText($activeSection, $keyword !== ''), ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mt-1 text-xs text-gray-500">
                                <?= $keyword !== '' ? '请尝试其他关键词。' : '当前没有可管理的用户记录。' ?>
                            </p>
                            <?php if ($keyword !== ''): ?>
                                <a href="<?= htmlspecialchars(adminUsersListUrl($activeSection, '', 1, $userPerPage), ENT_QUOTES, 'UTF-8') ?>" class="mt-3 inline-block text-xs text-blue-600 hover:underline">清除搜索</a>
                            <?php endif; ?>
                        </div>
                    <?php else: ?>
                        <div class="overflow-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-4 py-3">用户</th>
                                    <th class="px-4 py-3">邮箱</th>
                                    <th class="px-4 py-3">用户组</th>
                                    <th class="px-4 py-3">状态</th>
                                    <th class="px-4 py-3">注册时间</th>
                                    <th class="px-4 py-3 text-right">操作</th>
                                </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100 bg-white" id="usersTableBody">
                                <?php foreach ($users as $user): ?>
                                    <?php $userPayload = adminUsersFormatRowForApi($user); ?>
                                    <tr class="hover:bg-gray-50/60" data-user-id="<?= (int)$user['id'] ?>">
                                        <td class="px-4 py-3">
                                            <p class="font-semibold text-gray-900"><?= htmlspecialchars((string)$user['username'], ENT_QUOTES, 'UTF-8') ?></p>
                                            <p class="mt-1 text-xs text-gray-500">#<?= (int)$user['id'] ?></p>
                                        </td>
                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars((string)$user['email'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3 text-gray-700" data-user-group><?= htmlspecialchars((string)($user['group_name'] ?? '未分组'), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3" data-user-status>
                                            <?php
                                            $badge = [
                                                'active' => 'bg-green-100 text-green-700',
                                                'banned' => 'bg-red-100 text-red-700',
                                                'frozen' => 'bg-amber-100 text-amber-800',
                                            ][$user['status']] ?? 'bg-gray-100 text-gray-600';
                                            ?>
                                            <span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold <?= $badge ?>" data-user-status-badge>
                                                <?= htmlspecialchars((string)$user['status'], ENT_QUOTES, 'UTF-8') ?>
                                                <?= $user['ban_until'] ? '（至 ' . htmlspecialchars(formatChinaDateTime($user['ban_until']), ENT_QUOTES, 'UTF-8') . '）' : '' ?>
                                            </span>
                                        </td>
                                        <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars(formatChinaDateTime($user['created_at']), ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3">
                                            <div class="flex justify-end">
                                                <button
                                                    type="button"
                                                    class="rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-black"
                                                    data-manage-user='<?= htmlspecialchars(json_encode($userPayload, JSON_UNESCAPED_UNICODE), ENT_QUOTES, 'UTF-8') ?>'>
                                                    管理
                                                </button>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <?php if ($userTotalPages > 1): ?>
                            <div class="flex flex-wrap items-center justify-center gap-2 border-t border-gray-100 px-5 py-4">
                                <?php if ($userPage > 1): ?>
                                    <a href="<?= htmlspecialchars(adminUsersListUrl($activeSection, $keyword, 1, $userPerPage), ENT_QUOTES, 'UTF-8') ?>"
                                       class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">首页</a>
                                    <a href="<?= htmlspecialchars(adminUsersListUrl($activeSection, $keyword, $userPage - 1, $userPerPage), ENT_QUOTES, 'UTF-8') ?>"
                                       class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">上一页</a>
                                <?php endif; ?>

                                <?php
                                $pageWindowStart = max(1, $userPage - 2);
                                $pageWindowEnd = min($userTotalPages, $userPage + 2);
                                for ($p = $pageWindowStart; $p <= $pageWindowEnd; $p++):
                                ?>
                                    <a href="<?= htmlspecialchars(adminUsersListUrl($activeSection, $keyword, $p, $userPerPage), ENT_QUOTES, 'UTF-8') ?>"
                                       class="rounded-lg px-3 py-1.5 text-xs <?= $p === $userPage ? 'bg-gray-900 text-white' : 'border border-gray-200 text-gray-700 hover:bg-gray-50' ?>">
                                        <?= $p ?>
                                    </a>
                                <?php endfor; ?>

                                <?php if ($userPage < $userTotalPages): ?>
                                    <a href="<?= htmlspecialchars(adminUsersListUrl($activeSection, $keyword, $userPage + 1, $userPerPage), ENT_QUOTES, 'UTF-8') ?>"
                                       class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">下一页</a>
                                    <a href="<?= htmlspecialchars(adminUsersListUrl($activeSection, $keyword, $userTotalPages, $userPerPage), ENT_QUOTES, 'UTF-8') ?>"
                                       class="rounded-lg border border-gray-200 px-3 py-1.5 text-xs text-gray-700 hover:bg-gray-50">末页</a>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>
</main>

<!-- 模态框 -->
<div id="modal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50">
    <div class="relative w-full max-w-md rounded-2xl bg-white p-5 shadow">
        <button class="absolute right-3 top-2 text-xl text-gray-400 hover:text-gray-700" onclick="closeModal()" aria-label="关闭">&times;</button>
        <div id="modal-body"></div>
    </div>
</div>

<div id="usersAdminToast" class="fixed left-1/2 bottom-6 z-[70] -translate-x-1/2 rounded-full bg-gray-900 px-4 py-2 text-sm text-white shadow-lg hidden" aria-live="polite"></div>

<?php
$groupsForJs = array_map(static fn(array $g): array => [
    'id' => (string)$g['id'],
    'name' => (string)$g['name'],
], $groups);
?>
<script>
const usersAdminConfig = {
    apiUrl: '../api/admin_users_action.php',
    keyword: <?php echo json_encode($keyword, JSON_UNESCAPED_UNICODE); ?>,
    section: <?php echo json_encode($activeSection, JSON_UNESCAPED_UNICODE); ?>,
    groups: <?php echo json_encode($groupsForJs, JSON_UNESCAPED_UNICODE); ?>,
};

function userMatchesCurrentSection(user) {
    const section = usersAdminConfig.section || 'users';
    if (section === 'banned') return user.status === 'banned';
    if (section === 'frozen') return user.status === 'frozen';
    if (section === 'timed_ban') return user.status === 'banned' && !!user.ban_until;
    return true;
}

const statusBadgeClass = {
    active: 'bg-green-100 text-green-700',
    banned: 'bg-red-100 text-red-700',
    frozen: 'bg-amber-100 text-amber-800',
};

function escapeHtml(str){
    return String(str ?? '')
        .replace(/&/g,'&amp;')
        .replace(/</g,'&lt;')
        .replace(/>/g,'&gt;')
        .replace(/"/g,'&quot;')
        .replace(/'/g,'&#039;');
}

function showUsersToast(msg, isError) {
    const el = document.getElementById('usersAdminToast');
    if (!el) return;
    el.textContent = msg;
    el.className = 'fixed left-1/2 bottom-6 z-[70] -translate-x-1/2 rounded-full px-4 py-2 text-sm text-white shadow-lg'
        + (isError ? ' bg-red-600' : ' bg-gray-900');
    el.hidden = false;
    el.classList.remove('hidden');
    clearTimeout(showUsersToast._t);
    showUsersToast._t = setTimeout(function () {
        el.hidden = true;
        el.classList.add('hidden');
    }, 2800);
}

function setButtonLoading(btn, loading) {
    if (!btn) return;
    btn.disabled = loading;
    if (loading) {
        btn.dataset.originalText = btn.textContent;
        btn.textContent = '处理中...';
    } else if (btn.dataset.originalText) {
        btn.textContent = btn.dataset.originalText;
        delete btn.dataset.originalText;
    }
}

async function runUserAction(formData, triggerBtn) {
    formData.append('keyword', usersAdminConfig.keyword || '');
    setButtonLoading(triggerBtn, true);
    try {
        const res = await fetch(usersAdminConfig.apiUrl, {
            method: 'POST',
            body: formData,
            credentials: 'same-origin',
        });
        const data = await res.json();
        if (!data.ok) throw new Error(data.message || '操作失败');
        showUsersToast(data.message || '操作成功');
        if (data.stats) updateUserStats(data.stats);
        if (data.deleted) {
            removeUserRow(data.user_id);
        } else if (data.user) {
            if (!userMatchesCurrentSection(data.user)) {
                removeUserRow(data.user.id);
            } else {
                updateUserRow(data.user);
            }
        }
        closeModal();
        return data;
    } catch (err) {
        showUsersToast(err.message || '操作失败', true);
        throw err;
    } finally {
        setButtonLoading(triggerBtn, false);
    }
}

function updateUserStats(stats) {
    const totalEl = document.querySelector('[data-stat-total]');
    const activeEl = document.querySelector('[data-stat-active]');
    const bannedEl = document.querySelector('[data-stat-banned]');
    const frozenEl = document.querySelector('[data-stat-frozen]');
    const bannedWrap = document.querySelector('[data-stat-banned-wrap]');
    const frozenWrap = document.querySelector('[data-stat-frozen-wrap]');
    if (totalEl) totalEl.textContent = String(stats.total ?? 0);
    if (activeEl) activeEl.textContent = String(stats.active ?? 0);
    if (bannedEl) bannedEl.textContent = String(stats.banned ?? 0);
    if (frozenEl) frozenEl.textContent = String(stats.frozen ?? 0);
    if (bannedWrap) bannedWrap.hidden = !(stats.banned > 0);
    if (frozenWrap) frozenWrap.hidden = !(stats.frozen > 0);

    const summary = document.getElementById('usersListSummary');
    if (summary) {
        summary.dataset.total = String(stats.total ?? 0);
        updateListSummaryText(summary);
    }
}

function updateListSummaryText(summary) {
    const keyword = summary.dataset.keyword || '';
    const total = Number(summary.dataset.total || 0);
    const page = Number(summary.dataset.page || 1);
    const totalPages = Number(summary.dataset.totalPages || 1);
    const rangeStart = Number(summary.dataset.rangeStart || 0);
    const rangeEnd = Number(summary.dataset.rangeEnd || 0);
    let text = keyword !== ''
        ? '搜索「' + keyword + '」共 ' + total + ' 条。每行操作已收纳到「管理」。'
        : '共 ' + total + ' 条。每行操作已收纳到「管理」。';
    if (total > 0) {
        text += ' 当前第 ' + page + ' / ' + totalPages + ' 页（' + rangeStart + '–' + rangeEnd + '）。';
    }
    summary.textContent = text;
}

function renderStatusBadge(user) {
    const cls = statusBadgeClass[user.status] || 'bg-gray-100 text-gray-600';
    let text = escapeHtml(user.status || '');
    if (user.ban_until_fmt) {
        text += '（至 ' + escapeHtml(user.ban_until_fmt) + '）';
    }
    return '<span class="inline-flex rounded-full px-2 py-0.5 text-xs font-semibold ' + cls + '" data-user-status-badge>' + text + '</span>';
}

function updateUserRow(user) {
    const row = document.querySelector('tr[data-user-id="' + user.id + '"]');
    if (!row) return;
    const groupCell = row.querySelector('[data-user-group]');
    const statusCell = row.querySelector('[data-user-status]');
    const manageBtn = row.querySelector('[data-manage-user]');
    if (groupCell) groupCell.textContent = user.group_name || '未分组';
    if (statusCell) statusCell.innerHTML = renderStatusBadge(user);
    if (manageBtn) manageBtn.dataset.manageUser = JSON.stringify(user);
}

function removeUserRow(userId) {
    const row = document.querySelector('tr[data-user-id="' + userId + '"]');
    if (row) row.remove();

    const summary = document.getElementById('usersListSummary');
    const tbody = document.getElementById('usersTableBody');
    if (summary && tbody) {
        const total = Math.max(0, Number(summary.dataset.total || 0) - 1);
        const rowCount = tbody.querySelectorAll('tr').length;
        const rangeStart = rowCount > 0 ? Number(summary.dataset.rangeStart || 1) : 0;
        const rangeEnd = rowCount > 0 ? rangeStart + rowCount - 1 : 0;
        summary.dataset.total = String(total);
        summary.dataset.rangeEnd = String(rangeEnd);
        if (rowCount === 0) {
            summary.dataset.rangeStart = '0';
        }
        updateListSummaryText(summary);
    }
    if (tbody && !tbody.querySelector('tr')) {
        location.reload();
    }
}

function openManageUser(u) {
    const modal = document.getElementById('modal');
    const body = document.getElementById('modal-body');
    const userId = Number(u?.id || 0);
    const status = String(u?.status || '');
    const currentGroupId = String(u?.group_id || '');
    const username = escapeHtml(u?.username || '');
    const email = escapeHtml(u?.email || '');

    let statusHint = '';
    if (status === 'banned') statusHint = '当前已封禁';
    else if (status === 'frozen') statusHint = '当前已冻结';
    else if (status === 'active') statusHint = '当前正常';
    else statusHint = '当前状态：' + escapeHtml(status || '未知');

    let primaryActions = '';
    if (status === 'banned') {
        primaryActions += '<button type="button" class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700" data-user-action="unban" data-user-id="' + userId + '">解封</button>';
    } else {
        primaryActions += '<button type="button" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700" data-user-action="ban" data-user-id="' + userId + '">封禁</button>';
        primaryActions += '<button type="button" class="rounded-lg bg-orange-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-orange-600" data-user-action="ban_custom" data-user-id="' + userId + '">定时封禁</button>';
    }
    if (status === 'frozen') {
        primaryActions += '<button type="button" class="rounded-lg bg-green-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-green-700" data-user-action="unfreeze" data-user-id="' + userId + '">解冻</button>';
    } else {
        primaryActions += '<button type="button" class="rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-600" data-user-action="freeze" data-user-id="' + userId + '">冻结</button>';
    }

    body.innerHTML = `
        <div class="space-y-4">
            <div>
                <h2 class="text-base font-semibold text-gray-900">管理用户</h2>
                <p class="mt-1 text-xs text-gray-500">${username} · ${email}</p>
                <p class="mt-2 text-xs text-gray-500">${statusHint}</p>
            </div>
            <div class="flex flex-wrap gap-2">${primaryActions}</div>
            <div class="grid gap-3 sm:grid-cols-2">
                <button type="button" class="rounded-xl bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700" data-user-action="reset_password" data-user-id="${userId}">重置密码</button>
                <button type="button" class="rounded-xl bg-gray-900 px-4 py-2 text-sm font-semibold text-white hover:bg-black" data-user-action="change_group" data-user-id="${userId}" data-group-id="${escapeHtml(currentGroupId)}">更改分组</button>
            </div>
            <div class="pt-2 border-t border-gray-100">
                <button type="button" class="w-full rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700"
                        data-user-action="delete" data-user-id="${userId}">
                    删除用户
                </button>
                <p class="mt-2 text-xs text-gray-500">注意：系统会阻止删除用户名为 admin 的用户。</p>
            </div>
        </div>
    `;
    modal.style.display = 'flex';
}

function showModal(action, userId, currentGroupId) {
    const modal = document.getElementById('modal');
    const body = document.getElementById('modal-body');
    let html = '';

    if (action === 'ban') {
        html = `<h2 class="mb-3 text-base font-semibold">封禁用户</h2>
        <form class="space-y-3" data-user-form="ban">
            <p class="text-sm text-gray-600">确定要封禁此用户吗？</p>
            <button type="submit" class="w-full rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700">确认封禁</button>
        </form>`;
    } else if (action === 'ban_custom') {
        html = `<h2 class="mb-3 text-base font-semibold">定时封禁用户</h2>
        <form class="space-y-3" data-user-form="ban_custom">
            <div>
                <label class="block text-sm text-gray-600 mb-1">封禁至（日期时间）</label>
                <input type="datetime-local" name="ban_until" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
            </div>
            <button type="submit" class="w-full rounded-xl bg-red-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-red-700">确认封禁</button>
        </form>`;
    } else if (action === 'reset_password') {
        html = `<h2 class="mb-3 text-base font-semibold">重置用户密码</h2>
        <form class="space-y-3" data-user-form="reset_password">
            <div>
                <label class="mb-1 block text-sm text-gray-600">新密码</label>
                <input type="password" name="new_password" required minlength="6" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
            </div>
            <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">确认重置</button>
        </form>`;
    } else if (action === 'change_group') {
        const options = usersAdminConfig.groups.map(function (g) {
            const selected = String(g.id) === String(currentGroupId || '') ? ' selected' : '';
            return '<option value="' + escapeHtml(g.id) + '"' + selected + '>' + escapeHtml(g.name) + '</option>';
        }).join('');
        html = `<h2 class="mb-3 text-base font-semibold">更改用户分组</h2>
        <form class="space-y-3" data-user-form="change_group">
            <div>
                <label class="mb-1 block text-sm text-gray-600">选择用户组</label>
                <select name="group_id" required class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">${options}</select>
            </div>
            <button type="submit" class="w-full rounded-xl bg-gray-900 px-4 py-2.5 text-sm font-semibold text-white hover:bg-black">确认更改</button>
        </form>`;
    }

    body.innerHTML = html;
    body.dataset.actionUserId = String(userId);
    modal.style.display = 'flex';
}

function closeModal() {
    const modal = document.getElementById('modal');
    modal.style.display = 'none';
    document.getElementById('modal-body').innerHTML = '';
}

async function postAction(action, userId, extra, triggerBtn) {
    const fd = new FormData();
    fd.append('action', action);
    fd.append('user_id', String(userId));
    Object.keys(extra || {}).forEach(function (key) {
        fd.append(key, extra[key]);
    });
    return runUserAction(fd, triggerBtn);
}

document.addEventListener('click', function (e) {
    const manageBtn = e.target.closest('[data-manage-user]');
    if (manageBtn) {
        try {
            openManageUser(JSON.parse(manageBtn.dataset.manageUser || '{}'));
        } catch (err) {
            showUsersToast('无法打开用户管理', true);
        }
        return;
    }

    const actionBtn = e.target.closest('[data-user-action]');
    if (!actionBtn) return;

    const action = actionBtn.dataset.userAction;
    const userId = Number(actionBtn.dataset.userId || 0);
    if (!userId) return;

    if (action === 'delete') {
        if (!confirm('确定删除该用户？\n\n该操作不可撤销！')) return;
        postAction('delete', userId, {}, actionBtn);
        return;
    }

    if (action === 'ban' || action === 'ban_custom' || action === 'reset_password' || action === 'change_group') {
        showModal(action, userId, actionBtn.dataset.groupId || '');
        return;
    }

    postAction(action, userId, {}, actionBtn);
});

document.getElementById('modal-body').addEventListener('submit', function (e) {
    const form = e.target.closest('[data-user-form]');
    if (!form) return;
    e.preventDefault();
    const action = form.dataset.userForm;
    const userId = Number(document.getElementById('modal-body').dataset.actionUserId || 0);
    const fd = new FormData(form);
    fd.append('action', action);
    fd.append('user_id', String(userId));
    const submitBtn = form.querySelector('[type="submit"]');
    runUserAction(fd, submitBtn);
});

window.onclick = function (e) {
    if (e.target === document.getElementById('modal')) closeModal();
};
</script>

</body>
</html>
