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

$hasTrafficCols = (bool)$pdo->query("SHOW COLUMNS FROM user_groups LIKE 'default_traffic'")->fetch();
$hasAutoReset = (bool)$pdo->query("SHOW COLUMNS FROM user_groups LIKE 'auto_reset_days'")->fetch();

// 页面分区（左侧菜单）
$activeSection = trim((string)($_GET['section'] ?? ''));
if (!in_array($activeSection, ['overview', 'groups', 'traffic'], true)) {
    $activeSection = 'overview';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect = trim((string)($_POST['redirect'] ?? ''));
    if ($redirect === '' || strpos($redirect, 'groups.php') !== 0) {
        $redirect = 'groups.php?section=' . urlencode($activeSection);
    }
    
    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $defaultTraffic = max(0, (int)($_POST['default_traffic'] ?? 0));
        $validityDays = max(0, (int)($_POST['traffic_validity_days'] ?? 0));
        $autoResetDays = max(0, (int)($_POST['auto_reset_days'] ?? 0));
        if ($name) {
            try {
                if ($hasTrafficCols && $hasAutoReset) {
                    $stmt = $pdo->prepare("INSERT INTO user_groups (name, default_traffic, traffic_validity_days, auto_reset_days) VALUES (?, ?, ?, ?)");
                    $stmt->execute([$name, $defaultTraffic, $validityDays, $autoResetDays]);
                } elseif ($hasTrafficCols) {
                    $stmt = $pdo->prepare("INSERT INTO user_groups (name, default_traffic, traffic_validity_days) VALUES (?, ?, ?)");
                    $stmt->execute([$name, $defaultTraffic, $validityDays]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO user_groups (name) VALUES (?)");
                    $stmt->execute([$name]);
                }
                $message = '分组添加成功';
            } catch(PDOException $e) {
                $error = '分组名称已存在';
            }
        } else {
            $error = '请输入分组名称';
        }
    } elseif ($action === 'edit_traffic' && $hasTrafficCols) {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $defaultTraffic = max(0, (int)($_POST['default_traffic'] ?? 0));
        $validityDays = max(0, (int)($_POST['traffic_validity_days'] ?? 0));
        $autoResetDays = max(0, (int)($_POST['auto_reset_days'] ?? 0));
        $applyToExisting = !empty($_POST['apply_to_existing']);
        if ($groupId) {
            if ($hasAutoReset) {
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

            if ($applyToExisting) {
                $expiresExpr = $validityDays > 0 ? "DATE_ADD(NOW(), INTERVAL {$validityDays} DAY)" : "NULL";
                $extraSet = $hasAutoReset ? ', traffic_last_reset_at = NOW()' : '';
                $pdo->prepare("UPDATE users SET traffic_total = ?, traffic_used = 0, traffic_expires_at = {$expiresExpr} {$extraSet} WHERE group_id = ?")
                    ->execute([$defaultTraffic, $groupId]);

                $logStmt = $pdo->prepare("INSERT INTO traffic_logs (user_id, action, change_amount, before_total, before_used, after_total, after_used, remark, operator_id)
                    SELECT id, 'group_sync', ?, traffic_total, traffic_used, ?, 0, ?, ? FROM users WHERE group_id = ?");
                $logStmt->execute([$defaultTraffic, $defaultTraffic, '分组流量同步', (int)$_SESSION['user_id'], $groupId]);

                // 清除该组用户全部解锁记录
                if ($pdo->query("SHOW TABLES LIKE 'video_unlocks'")->fetch()) {
                    $pdo->prepare("DELETE FROM video_unlocks WHERE user_id IN (SELECT id FROM users WHERE group_id = ?)")
                        ->execute([$groupId]);
                }
            }
            $message = '用户组流量配置已更新' . ($applyToExisting ? '，并同步到该组所有用户（含清除解锁）' : '');
        }
    } elseif ($action === 'bulk_reset_group' && $hasAutoReset) {
        $groupId = (int)($_POST['group_id'] ?? 0);
        if ($groupId) {
            $count = resetAllUsersInGroup($pdo, $groupId, (int)$_SESSION['user_id']);
            $message = "已重置本组 {$count} 名用户的流量与解锁记录";
        }
    } elseif ($action === 'delete') {
        $groupId = $_POST['group_id'] ?? 0;
        if ($groupId) {
            $stmt = $pdo->prepare("SELECT name FROM user_groups WHERE id = ?");
            $stmt->execute([$groupId]);
            $group = $stmt->fetch();
            
            if ($group && $group['name'] === '注册用户组') {
                $error = '不能删除默认分组';
            } else {
                $stmt = $pdo->prepare("SELECT id FROM user_groups WHERE name = '注册用户组'");
                $stmt->execute();
                $defaultGroup = $stmt->fetch();
                
                if ($defaultGroup) {
                    $stmt = $pdo->prepare("UPDATE users SET group_id = ? WHERE group_id = ?");
                    $stmt->execute([$defaultGroup['id'], $groupId]);
                }

                $stmt = $pdo->prepare("DELETE FROM group_domains WHERE group_id = ?");
                $stmt->execute([$groupId]);
                if ($pdo->query("SHOW TABLES LIKE 'group_server_groups'")->fetch()) {
                    $pdo->prepare("DELETE FROM group_server_groups WHERE group_id = ?")->execute([$groupId]);
                }

                $stmt = $pdo->prepare("DELETE FROM user_groups WHERE id = ?");
                $stmt->execute([$groupId]);
                $message = '分组删除成功';
            }
        }
    }

    finishPostRequest($message ?: null, $error ?: null, $redirect);
}

$stmt = $pdo->query("SELECT g.*, COUNT(u.id) as user_count FROM user_groups g
                    LEFT JOIN users u ON g.id = u.group_id 
                    GROUP BY g.id 
                    ORDER BY g.id");
$groups = $stmt->fetchAll();

$groupCount = is_array($groups) ? count($groups) : 0;
$userTotal = 0;
try {
    $userTotal = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
} catch (Throwable $e) {
    $userTotal = 0;
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <?php $themeAssetPrefix = '../'; include __DIR__ . '/../components/theme-head.php'; ?>
    <title>分组管理 - 竹叶云控平台</title>

    <?php include __DIR__ . '/../components/theme-dynamic.php'; ?>
</head>
<body class="bg-gray-100 text-gray-900">
<?php $adminNavActive = 'groups'; include __DIR__ . '/../components/admin-top-nav.php'; ?>
    
    <main class="mx-auto max-w-screen-xl px-4 py-6 space-y-5">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div class="min-w-0">
                <h1 class="text-lg font-semibold text-gray-900">分组管理</h1>
                <p class="mt-1 text-xs text-gray-500">管理用户组、默认流量规则与批量重置。</p>
            </div>
            <div class="flex flex-wrap items-center gap-2 text-xs">
                <span class="inline-flex items-center gap-2 rounded-full bg-gray-900/90 px-3 py-1 text-white">
                    <span class="h-1.5 w-1.5 rounded-full bg-white/70" aria-hidden="true"></span>
                    分组 <?= (int)$groupCount ?>
                </span>
                <?php if ($userTotal > 0): ?>
                    <span class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-blue-700 ring-1 ring-blue-100">
                        <span class="h-1.5 w-1.5 rounded-full bg-blue-500" aria-hidden="true"></span>
                        用户 <?= (int)$userTotal ?>
                    </span>
                <?php endif; ?>
            </div>
        </div>

        <?php if ($message): ?>
            <div class="rounded-xl border border-green-200 bg-green-50 px-4 py-3 text-sm text-green-700"><?php echo htmlspecialchars($message, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>
        <?php if ($error): ?>
            <div class="rounded-xl border border-red-200 bg-red-50 px-4 py-3 text-sm text-red-700"><?php echo htmlspecialchars($error, ENT_QUOTES, 'UTF-8'); ?></div>
        <?php endif; ?>

        <div class="flex gap-4 items-start">
            <?php include __DIR__ . '/../components/admin-groups-sidebar.php'; ?>

            <section class="min-w-0 flex-1 space-y-6">
                <?php if ($activeSection === 'overview'): ?>
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                        <div class="border-b border-gray-100 px-5 py-3">
                            <p class="text-sm font-semibold text-gray-900">总览</p>
                            <p class="mt-1 text-xs text-gray-500">从左侧选择模块进入管理。</p>
                        </div>
                        <div class="px-5 py-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                            <a href="?section=groups" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                                <p class="text-sm font-semibold text-gray-900">分组列表</p>
                                <p class="mt-1 text-xs text-gray-500">新增 / 删除 / 用户数</p>
                            </a>
                            <a href="?section=traffic" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                                <p class="text-sm font-semibold text-gray-900">流量配置</p>
                                <p class="mt-1 text-xs text-gray-500">默认流量 / 有效期 / 同步</p>
                            </a>
                        </div>
                    </div>

                <?php elseif ($activeSection === 'traffic'): ?>
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                        <div class="border-b border-gray-100 px-5 py-3">
                            <p class="text-sm font-semibold text-gray-900">流量配置</p>
                            <p class="mt-1 text-xs text-gray-500">在这里快速进入各分组的流量配置与批量操作。</p>
                        </div>
                        <?php if (!$hasTrafficCols): ?>
                            <div class="px-5 py-10 text-center">
                                <p class="text-sm font-semibold text-gray-900">当前未启用分组流量字段</p>
                                <p class="mt-1 text-xs text-gray-500">数据库 `user_groups` 表缺少 `default_traffic` 等字段。</p>
                            </div>
                        <?php else: ?>
                            <div class="overflow-auto">
                                <table class="min-w-full text-left text-sm">
                                    <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                    <tr>
                                        <th class="px-4 py-3">分组</th>
                                        <th class="px-4 py-3">用户</th>
                                        <th class="px-4 py-3">默认流量</th>
                                        <th class="px-4 py-3">有效期</th>
                                        <?php if ($hasAutoReset): ?><th class="px-4 py-3">自动重置</th><?php endif; ?>
                                        <th class="px-4 py-3 text-right">操作</th>
                                    </tr>
                                    </thead>
                                    <tbody class="divide-y divide-gray-100 bg-white">
                                    <?php foreach ($groups as $group): ?>
                                        <tr class="hover:bg-gray-50/60">
                                            <td class="px-4 py-3">
                                                <p class="font-semibold text-gray-900"><?php echo htmlspecialchars((string)$group['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                                                <p class="mt-1 text-xs text-gray-500">#<?php echo (int)$group['id']; ?></p>
                                            </td>
                                            <td class="px-4 py-3 text-gray-700"><?php echo (int)$group['user_count']; ?></td>
                                            <td class="px-4 py-3 text-gray-700"><?php echo (int)($group['default_traffic'] ?? 0); ?></td>
                                            <td class="px-4 py-3 text-gray-700"><?php $d = (int)($group['traffic_validity_days'] ?? 0); echo $d > 0 ? ($d . ' 天') : '永久'; ?></td>
                                            <?php if ($hasAutoReset): ?>
                                                <td class="px-4 py-3">
                                                    <?php $r = (int)($group['auto_reset_days'] ?? 0); ?>
                                                    <?php if ($r > 0): ?>
                                                        <span class="inline-flex rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700">每 <?= $r ?> 天</span>
                                                    <?php else: ?>
                                                        <span class="text-xs text-gray-400">关闭</span>
                                                    <?php endif; ?>
                                                </td>
                                            <?php endif; ?>
                                            <td class="px-4 py-3">
                                                <div class="flex justify-end gap-2">
                                                    <button type="button" class="rounded-lg bg-blue-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-blue-700"
                                                            onclick='showEditTraffic(<?php echo json_encode([
                                                                "id" => (int)$group["id"],
                                                                "name" => $group["name"],
                                                                "default_traffic" => (int)($group["default_traffic"] ?? 0),
                                                                "traffic_validity_days" => (int)($group["traffic_validity_days"] ?? 0),
                                                                "auto_reset_days" => (int)($group["auto_reset_days"] ?? 0),
                                                            ], JSON_HEX_APOS|JSON_HEX_QUOT|JSON_UNESCAPED_UNICODE); ?>)'>配置</button>
                                                    <?php if ($hasAutoReset && (int)$group['user_count'] > 0): ?>
                                                        <form method="POST" class="inline" onsubmit="return confirm('确定一键重置该分组下全部用户流量？\n\n将执行：\n1. 用户流量按分组默认值重置\n2. 已用流量清零\n3. 清除该组用户全部解锁记录\n\n此操作不可撤销！');">
                                                            <input type="hidden" name="action" value="bulk_reset_group">
                                                            <input type="hidden" name="group_id" value="<?php echo (int)$group['id']; ?>">
                                                            <input type="hidden" name="redirect" value="groups.php?section=traffic">
                                                            <button type="submit" class="rounded-lg bg-amber-500 px-3 py-1.5 text-xs font-semibold text-white hover:bg-amber-600">重置</button>
                                                        </form>
                                                    <?php endif; ?>
                                                </div>
                                            </td>
                                        </tr>
                                    <?php endforeach; ?>
                                    </tbody>
                                </table>
                            </div>
                        <?php endif; ?>
                    </div>

                <?php else: ?>
                    <div class="grid gap-4 lg:grid-cols-3">
                        <section class="lg:col-span-1">
                            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden lg:sticky lg:top-4">
                                <div class="border-b border-gray-100 px-5 py-3">
                                    <p class="text-sm font-semibold text-gray-900">新增分组</p>
                                    <p class="mt-1 text-xs text-gray-500">建议：先创建分组，再去域名/流量模块进行进一步配置。</p>
                                </div>
                                <form method="POST" class="px-5 py-4 space-y-4">
                                    <input type="hidden" name="action" value="add">
                                    <input type="hidden" name="redirect" value="groups.php?section=groups">
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700">分组名称</label>
                                        <input type="text" name="name" placeholder="分组名称" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                                    </div>
                                    <?php if ($hasTrafficCols): ?>
                                    <div class="grid gap-3 sm:grid-cols-2">
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-gray-700">默认流量</label>
                                            <input type="number" name="default_traffic" min="0" value="0" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                                        </div>
                                        <div>
                                            <label class="mb-1 block text-sm font-medium text-gray-700">有效期（天）</label>
                                            <input type="number" name="traffic_validity_days" min="0" value="0" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                                            <p class="mt-1 text-xs text-gray-500">0 = 永久</p>
                                        </div>
                                    </div>
                                    <?php endif; ?>
                                    <?php if ($hasAutoReset): ?>
                                    <div>
                                        <label class="mb-1 block text-sm font-medium text-gray-700">自动重置周期（天）</label>
                                        <input type="number" name="auto_reset_days" min="0" value="0" placeholder="0=不自动重置" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                                    </div>
                                    <?php endif; ?>
                                    <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">添加分组</button>
                                </form>
                            </div>
                        </section>

                        <section class="lg:col-span-2">
                            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                                <div class="border-b border-gray-100 px-5 py-3">
                                    <p class="text-sm font-semibold text-gray-900">分组列表</p>
                                    <p class="mt-1 text-xs text-gray-500">共 <?= (int)$groupCount ?> 条。</p>
                                </div>
                                <?php if (empty($groups)): ?>
                                    <div class="px-5 py-10 text-center">
                                        <p class="text-sm font-semibold text-gray-900">暂无分组</p>
                                        <p class="mt-1 text-xs text-gray-500">先在左侧创建一个分组。</p>
                                    </div>
                                <?php else: ?>
                                    <div class="overflow-auto">
                                        <table class="min-w-full text-left text-sm">
                                            <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                            <tr>
                                                <th class="px-4 py-3">ID</th>
                                                <th class="px-4 py-3">分组名称</th>
                                                <th class="px-4 py-3">用户</th>
                                                <?php if ($hasTrafficCols): ?>
                                                    <th class="px-4 py-3">默认流量</th>
                                                    <th class="px-4 py-3">有效期</th>
                                                <?php endif; ?>
                                                <?php if ($hasAutoReset): ?><th class="px-4 py-3">自动重置</th><?php endif; ?>
                                                <th class="px-4 py-3">创建时间</th>
                                                <th class="px-4 py-3 text-right">操作</th>
                                            </tr>
                                            </thead>
                                            <tbody class="divide-y divide-gray-100 bg-white">
                                            <?php foreach ($groups as $group): ?>
                                                <tr class="hover:bg-gray-50/60">
                                                    <td class="px-4 py-3 text-gray-700"><?php echo (int)$group['id']; ?></td>
                                                    <td class="px-4 py-3">
                                                        <p class="font-semibold text-gray-900"><?php echo htmlspecialchars((string)$group['name'], ENT_QUOTES, 'UTF-8'); ?></p>
                                                    </td>
                                                    <td class="px-4 py-3 text-gray-700"><?php echo (int)$group['user_count']; ?></td>
                                                    <?php if ($hasTrafficCols): ?>
                                                        <td class="px-4 py-3 text-gray-700"><?php echo (int)($group['default_traffic'] ?? 0); ?></td>
                                                        <td class="px-4 py-3 text-gray-700"><?php $d = (int)($group['traffic_validity_days'] ?? 0); echo $d > 0 ? ($d . ' 天') : '永久'; ?></td>
                                                    <?php endif; ?>
                                                    <?php if ($hasAutoReset): ?>
                                                        <td class="px-4 py-3">
                                                            <?php $r = (int)($group['auto_reset_days'] ?? 0); ?>
                                                            <?php if ($r > 0): ?>
                                                                <span class="inline-flex rounded-full bg-blue-100 px-2 py-0.5 text-xs font-semibold text-blue-700">每 <?= $r ?> 天</span>
                                                            <?php else: ?>
                                                                <span class="text-xs text-gray-400">关闭</span>
                                                            <?php endif; ?>
                                                        </td>
                                                    <?php endif; ?>
                                                    <td class="px-4 py-3 text-gray-600"><?php echo htmlspecialchars((string)$group['created_at'], ENT_QUOTES, 'UTF-8'); ?></td>
                                                    <td class="px-4 py-3">
                                                        <div class="flex justify-end gap-2">
                                                            <?php if ($hasTrafficCols): ?>
                                                                <a class="rounded-lg bg-gray-900 px-3 py-1.5 text-xs font-semibold text-white hover:bg-black" href="groups.php?section=traffic">流量</a>
                                                            <?php endif; ?>
                                                            <?php if ($group['name'] !== '注册用户组'): ?>
                                                                <form method="POST" class="inline">
                                                                    <input type="hidden" name="action" value="delete">
                                                                    <input type="hidden" name="group_id" value="<?php echo (int)$group['id']; ?>">
                                                                    <input type="hidden" name="redirect" value="groups.php?section=groups">
                                                                    <button type="submit" class="rounded-lg bg-red-600 px-3 py-1.5 text-xs font-semibold text-white hover:bg-red-700" onclick="return confirm('确定删除此分组？该分组下的用户将移回默认分组')">删除</button>
                                                                </form>
                                                            <?php else: ?>
                                                                <span class="text-xs text-gray-500">默认</span>
                                                            <?php endif; ?>
                                                        </div>
                                                    </td>
                                                </tr>
                                            <?php endforeach; ?>
                                            </tbody>
                                        </table>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </section>
                    </div>
                <?php endif; ?>
            </section>
        </div>
    </main>

    <?php if ($hasTrafficCols): ?>
    <div id="trafficModal" class="fixed inset-0 z-50 hidden items-center justify-center bg-black/50" style="display:none;">
        <div class="relative w-full max-w-md rounded-2xl bg-white p-5 shadow">
            <button class="absolute right-3 top-2 text-xl text-gray-400 hover:text-gray-700" type="button" onclick="closeTrafficModal()" aria-label="关闭">&times;</button>
            <h2 class="mb-3 text-base font-semibold text-gray-900">分组流量配置 - <span id="tg_name"></span></h2>
            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="edit_traffic">
                <input type="hidden" name="group_id" id="tg_id">
                <input type="hidden" name="redirect" id="tg_redirect" value="groups.php?section=traffic">
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">默认流量</label>
                    <input type="number" name="default_traffic" id="tg_default_traffic" min="0" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                </div>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">流量有效期（天，0=永久）</label>
                    <input type="number" name="traffic_validity_days" id="tg_validity" min="0" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                </div>
                <?php if ($hasAutoReset): ?>
                <div>
                    <label class="mb-1 block text-sm font-medium text-gray-700">自动重置周期</label>
                    <div class="flex flex-wrap gap-1.5 mb-2" id="tg_reset_presets">
                        <button type="button" data-val="0" class="reset-preset rounded border border-gray-300 px-3 py-1 text-xs hover:border-red-500">关闭</button>
                        <button type="button" data-val="1" class="reset-preset rounded border border-gray-300 px-3 py-1 text-xs hover:border-red-500">1 天</button>
                        <button type="button" data-val="7" class="reset-preset rounded border border-gray-300 px-3 py-1 text-xs hover:border-red-500">7 天</button>
                        <button type="button" data-val="14" class="reset-preset rounded border border-gray-300 px-3 py-1 text-xs hover:border-red-500">14 天</button>
                        <button type="button" data-val="30" class="reset-preset rounded border border-gray-300 px-3 py-1 text-xs hover:border-red-500">30 天</button>
                    </div>
                    <input type="number" name="auto_reset_days" id="tg_reset_days" min="0" placeholder="自定义天数；0=不自动重置" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                    <p class="mt-1 text-xs text-gray-500">到期后系统自动按"默认流量"重新发放，并清除该用户全部解锁记录。</p>
                </div>
                <?php endif; ?>
                <label class="flex items-center gap-2 text-sm text-gray-700">
                    <input type="checkbox" name="apply_to_existing" value="1">
                    立即同步到本组所有用户（重置已用流量、到期时间，并清除解锁记录）
                </label>
                <button type="submit" class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">保存</button>
            </form>
        </div>
    </div>
    <script>
    function showEditTraffic(data) {
        document.getElementById('tg_id').value = data.id;
        document.getElementById('tg_default_traffic').value = data.default_traffic;
        document.getElementById('tg_validity').value = data.traffic_validity_days;
        var rd = document.getElementById('tg_reset_days');
        if (rd) rd.value = data.auto_reset_days || 0;
        document.getElementById('tg_name').textContent = data.name;
        highlightResetPreset(data.auto_reset_days || 0);
        document.getElementById('trafficModal').style.display = 'flex';
    }
    function closeTrafficModal() {
        document.getElementById('trafficModal').style.display = 'none';
    }
    function highlightResetPreset(val) {
        document.querySelectorAll('#tg_reset_presets .reset-preset').forEach(btn => {
            const active = String(btn.dataset.val) === String(val);
            btn.classList.toggle('bg-red-600', active);
            btn.classList.toggle('text-white', active);
            btn.classList.toggle('border-red-600', active);
        });
    }
    document.querySelectorAll('#tg_reset_presets .reset-preset').forEach(btn => {
        btn.addEventListener('click', () => {
            const v = btn.dataset.val;
            document.getElementById('tg_reset_days').value = v;
            highlightResetPreset(v);
        });
    });
    var rdInput = document.getElementById('tg_reset_days');
    if (rdInput) rdInput.addEventListener('input', () => highlightResetPreset(rdInput.value));
    window.addEventListener('click', e => {
        const m = document.getElementById('trafficModal');
        if (e.target === m) closeTrafficModal();
    });
    </script>
    <?php endif; ?>
</body>
</html>
