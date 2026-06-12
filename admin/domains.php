<?php
require_once '../includes/auth.php';
require_once '../config/database.php';
require_once '../includes/flash.php';
require_once '../includes/upload_domain_group.php';

requireAdmin();

$pdo = getDB();
$message = '';
$error = '';
applyFlash($message, $error);

$sgFeature = (bool)$pdo->query("SHOW TABLES LIKE 'server_groups'")->fetch()
    && (bool)$pdo->query("SHOW COLUMNS FROM domains LIKE 'server_group_id'")->fetch();
$gsgFeature = (bool)$pdo->query("SHOW TABLES LIKE 'group_server_groups'")->fetch();
$bundleSgFeature = $sgFeature && $gsgFeature;

// 页面分区（菜单）
$activeSection = trim((string)($_GET['section'] ?? ''));
$allowedSections = ['overview', 'domains', 'assign'];
if ($sgFeature) {
    $allowedSections[] = 'server_groups';
    $allowedSections[] = 'upload_domain_group';
}
if (!in_array($activeSection, $allowedSections, true)) {
    $activeSection = 'overview';
}

// ================== 处理操作 ==================
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';
    $redirect = trim((string)($_POST['redirect'] ?? ''));
    if ($redirect === '' || strpos($redirect, 'domains.php') !== 0) {
        $redirect = 'domains.php?section=' . urlencode($activeSection);
    }

    if ($action === 'add') {
        $domain = trim($_POST['domain'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');
        $domain = preg_replace('/^https?:\/\//', '', $domain);
        $domain = rtrim($domain, '/');

        if ($domain) {
            try {
                if ($sgFeature) {
                    $sgRaw = $_POST['server_group_id'] ?? '';
                    $serverGroupId = ($sgRaw === '' || $sgRaw === null) ? null : (int)$sgRaw;
                    $stmt = $pdo->prepare("INSERT INTO domains (domain, display_name, server_group_id) VALUES (?, ?, ?)");
                    $stmt->execute([$domain, $displayName ?: null, $serverGroupId]);
                } else {
                    $stmt = $pdo->prepare("INSERT INTO domains (domain, display_name) VALUES (?, ?)");
                    $stmt->execute([$domain, $displayName ?: null]);
                }
                $message = '域名添加成功';
            } catch (PDOException $e) {
                $error = '域名已存在';
            }
        } else {
            $error = '请输入域名';
        }
    }

    if ($action === 'edit') {
        $domainId = $_POST['domain_id'] ?? 0;
        $domain = trim($_POST['domain'] ?? '');
        $displayName = trim($_POST['display_name'] ?? '');
        $domain = preg_replace('/^https?:\/\//', '', $domain);
        $domain = rtrim($domain, '/');

        if ($domainId && $domain) {
            if ($sgFeature) {
                $sgRaw = $_POST['server_group_id'] ?? '';
                $serverGroupId = ($sgRaw === '' || $sgRaw === null) ? null : (int)$sgRaw;
                $stmt = $pdo->prepare("UPDATE domains SET domain = ?, display_name = ?, server_group_id = ? WHERE id = ?");
                $stmt->execute([$domain, $displayName ?: null, $serverGroupId, $domainId]);
            } else {
                $stmt = $pdo->prepare("UPDATE domains SET domain = ?, display_name = ? WHERE id = ?");
                $stmt->execute([$domain, $displayName ?: null, $domainId]);
            }
            $message = '域名更新成功';
        }
    }

    if ($action === 'delete') {
        $domainId = $_POST['domain_id'] ?? 0;
        if ($domainId) {
            $pdo->prepare("DELETE FROM domains WHERE id = ?")->execute([$domainId]);
            $pdo->prepare("DELETE FROM group_domains WHERE domain_id = ?")->execute([$domainId]);
            $message = '域名删除成功';
        }
    }

    if ($action === 'assign_group_domains' && $sgFeature) {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $domainIds = $_POST['domain_ids'] ?? [];

        if ($groupId) {
            $pdo->prepare('DELETE FROM group_domains WHERE group_id = ?')->execute([$groupId]);
            if (!empty($domainIds)) {
                $ins = $pdo->prepare('INSERT IGNORE INTO group_domains (group_id, domain_id) VALUES (?, ?)');
                foreach ($domainIds as $did) {
                    $did = (int)$did;
                    if ($did > 0) {
                        $ins->execute([$groupId, $did]);
                    }
                }
            }
            $message = '域名分配已保存（可同时勾选多个服务器组下的域名）';
        }
    }

    if ($action === 'add_sg' && $sgFeature) {
        $sgName = trim($_POST['sg_name'] ?? '');
        if ($sgName !== '') {
            try {
                $pdo->prepare("INSERT INTO server_groups (name) VALUES (?)")->execute([$sgName]);
                $message = '服务器组已添加';
            } catch (PDOException $e) {
                $error = '服务器组名称已存在';
            }
        } else {
            $error = '请输入服务器组名称';
        }
    }

    if ($action === 'delete_sg' && $sgFeature) {
        $sgid = (int)($_POST['server_group_id'] ?? 0);
        if ($sgid) {
            if ($gsgFeature) {
                $pdo->prepare('DELETE FROM group_server_groups WHERE server_group_id = ?')->execute([$sgid]);
            }
            $pdo->prepare("UPDATE domains SET server_group_id = NULL WHERE server_group_id = ?")->execute([$sgid]);
            $pdo->prepare("DELETE FROM server_groups WHERE id = ?")->execute([$sgid]);
            $message = '服务器组已删除，其下域名已归入未分组';
        }
    }

    if ($action === 'save_upload_domain_group' && $sgFeature) {
        $sgRaw = $_POST['upload_server_group_id'] ?? '';
        if ($sgRaw === '' || $sgRaw === null) {
            setUploadDomainServerGroupId($pdo, null);
            $message = '已清除上传域名组配置';
        } else {
            $sgId = (int)$sgRaw;
            $stmt = $pdo->prepare('SELECT id FROM server_groups WHERE id = ? LIMIT 1');
            $stmt->execute([$sgId]);
            if (!$stmt->fetchColumn()) {
                $error = '所选服务器组不存在';
            } else {
                setUploadDomainServerGroupId($pdo, $sgId);
                $message = '上传域名组已保存';
            }
        }
    }

    if ($action === 'assign_group_server_groups' && $bundleSgFeature) {
        $groupId = (int)($_POST['group_id'] ?? 0);
        $sgIds = $_POST['server_group_ids'] ?? [];
        if ($groupId) {
            $pdo->prepare('DELETE FROM group_server_groups WHERE group_id = ?')->execute([$groupId]);
            if (!empty($sgIds)) {
                $ins = $pdo->prepare('INSERT IGNORE INTO group_server_groups (group_id, server_group_id) VALUES (?, ?)');
                foreach ($sgIds as $sgid) {
                    $sgid = (int)$sgid;
                    if ($sgid > 0) {
                        $ins->execute([$groupId, $sgid]);
                    }
                }
            }
            $message = '用户组与服务器组的关联已保存';
        }
    }

    finishPostRequest($message ?: null, $error ?: null, $redirect);
}

// ================== 数据 ==================
if ($sgFeature) {
    $domains = $pdo->query("
        SELECT d.*, sg.name AS server_group_name
        FROM domains d
        LEFT JOIN server_groups sg ON d.server_group_id = sg.id
        ORDER BY d.id
    ")->fetchAll();
    $serverGroups = $pdo->query("SELECT * FROM server_groups ORDER BY id")->fetchAll();
} else {
    $domains = $pdo->query("SELECT * FROM domains ORDER BY id")->fetchAll();
    $serverGroups = [];
}
$groups  = $pdo->query("SELECT * FROM user_groups ORDER BY id")->fetchAll();

$groupDomains = [];
$stmt = $pdo->query("SELECT group_id, domain_id FROM group_domains");
while ($r = $stmt->fetch()) {
    $gid = (int)$r['group_id'];
    if (!isset($groupDomains[$gid])) {
        $groupDomains[$gid] = [];
    }
    $groupDomains[$gid][] = (int)$r['domain_id'];
}

$groupServerGroups = [];
if ($gsgFeature) {
    $stmt = $pdo->query('SELECT group_id, server_group_id FROM group_server_groups');
    while ($r = $stmt->fetch()) {
        $gid = (int)$r['group_id'];
        if (!isset($groupServerGroups[$gid])) {
            $groupServerGroups[$gid] = [];
        }
        $groupServerGroups[$gid][] = (int)$r['server_group_id'];
    }
}

$domainsBySgKey = [];
$sgPanelOrder = [];
if ($sgFeature) {
    $domainsBySgKey = ['ungrouped' => []];
    foreach ($serverGroups as $sg) {
        $domainsBySgKey[(string)(int)$sg['id']] = [];
    }
    foreach ($domains as $d) {
        $sid = $d['server_group_id'] ?? null;
        if ($sid === null || $sid === '') {
            $domainsBySgKey['ungrouped'][] = $d;
        } else {
            $k = (string)(int)$sid;
            if (!isset($domainsBySgKey[$k])) {
                $domainsBySgKey[$k] = [];
            }
            $domainsBySgKey[$k][] = $d;
        }
    }
    $sgPanelOrder = array_merge(['ungrouped'], array_map(static function ($s) {
        return (string)(int)$s['id'];
    }, $serverGroups));
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
<meta charset="UTF-8">
<title>域名管理</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<?php $themeAssetPrefix = '../'; include __DIR__ . '/../components/theme-head.php'; ?>


<?php include __DIR__ . '/../components/theme-dynamic.php'; ?>
</head>
<body class="bg-gray-100 text-gray-900">

<?php $adminNavActive = 'domains'; include __DIR__ . '/../components/admin-top-nav.php'; ?>

<main class="mx-auto max-w-screen-xl px-4 py-6 space-y-5">
<?php
$domainCount = is_array($domains) ? count($domains) : 0;
$serverGroupCount = is_array($serverGroups) ? count($serverGroups) : 0;
$groupCount = is_array($groups) ? count($groups) : 0;
$uploadDomainServerGroupId = $sgFeature ? getUploadDomainServerGroupId($pdo) : null;
$uploadPoolDomains = $sgFeature ? fetchUploadPoolDomains($pdo) : [];
$uploadPoolCount = count($uploadPoolDomains);
?>

<div class="flex flex-wrap items-end justify-between gap-3">
    <div class="min-w-0">
        <h1 class="text-lg font-semibold text-gray-900">域名管理</h1>
        <p class="mt-1 text-xs text-gray-500">管理线路域名、服务器组，以及为用户组分配可用线路。</p>
    </div>
    <div class="flex flex-wrap items-center gap-2 text-xs">
        <span class="inline-flex items-center gap-2 rounded-full bg-gray-900/90 px-3 py-1 text-white">
            <span class="h-1.5 w-1.5 rounded-full bg-white/70" aria-hidden="true"></span>
            域名 <?= (int)$domainCount ?>
        </span>
        <?php if ($sgFeature): ?>
        <span class="inline-flex items-center gap-2 rounded-full bg-blue-50 px-3 py-1 text-blue-700 ring-1 ring-blue-100">
            <span class="h-1.5 w-1.5 rounded-full bg-blue-500" aria-hidden="true"></span>
            服务器组 <?= (int)$serverGroupCount ?>
        </span>
        <?php endif; ?>
        <span class="inline-flex items-center gap-2 rounded-full bg-emerald-50 px-3 py-1 text-emerald-700 ring-1 ring-emerald-100">
            <span class="h-1.5 w-1.5 rounded-full bg-emerald-500" aria-hidden="true"></span>
            用户组 <?= (int)$groupCount ?>
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
    <?php include __DIR__ . '/../components/admin-domains-sidebar.php'; ?>

    <section class="min-w-0 flex-1 space-y-6">
        <?php if ($activeSection === 'overview'): ?>
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-3">
                    <p class="text-sm font-semibold text-gray-900">总览</p>
                    <p class="mt-1 text-xs text-gray-500">从左侧选择功能模块进入管理。</p>
                </div>
                <div class="px-5 py-5 grid gap-3 sm:grid-cols-2 lg:grid-cols-3">
                    <?php if ($sgFeature): ?>
                        <a href="?section=server_groups" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                            <p class="text-sm font-semibold text-gray-900">服务器组</p>
                            <p class="mt-1 text-xs text-gray-500">新增/删除服务器组</p>
                        </a>
                        <a href="?section=upload_domain_group" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                            <p class="text-sm font-semibold text-gray-900">上传域名组</p>
                            <p class="mt-1 text-xs text-gray-500">指定上传/封面域名池所属服务器组</p>
                        </a>
                    <?php endif; ?>
                    <a href="?section=domains" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                        <p class="text-sm font-semibold text-gray-900">域名管理</p>
                        <p class="mt-1 text-xs text-gray-500">新增 / 编辑 / 删除域名</p>
                    </a>
                    <a href="?section=assign" class="rounded-xl border border-gray-200 bg-gradient-to-b from-white to-gray-50 p-4 shadow-sm hover:shadow transition">
                        <p class="text-sm font-semibold text-gray-900">分配线路</p>
                        <p class="mt-1 text-xs text-gray-500">分配给用户组（支持多选）</p>
                    </a>
                </div>
            </div>

        <?php elseif ($activeSection === 'server_groups' && $sgFeature): ?>
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-3">
                    <p class="text-sm font-semibold text-gray-900">服务器组</p>
                    <p class="mt-1 text-xs text-gray-500">将域名归入服务器组后，分配线路时可按组筛选勾选。删除组不会删除域名，仅将域名移回「未分组」。</p>
                </div>
                <div class="px-5 py-4">
                    <form method="POST" class="flex flex-wrap items-end gap-2">
                        <input type="hidden" name="action" value="add_sg">
                        <input type="hidden" name="redirect" value="domains.php?section=server_groups">
                        <div class="min-w-[220px] flex-1">
                            <label class="mb-1 block text-[11px] text-gray-500">新服务器组名称</label>
                            <input type="text" name="sg_name" required placeholder="例如：国内线路"
                                   class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                        </div>
                        <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">
                            添加组
                        </button>
                    </form>

                    <?php if (empty($serverGroups)): ?>
                        <div class="mt-4 rounded-xl border border-gray-200 bg-gray-50 px-4 py-3 text-sm text-gray-600">
                            暂无服务器组，可先添加组，再在「域名管理」中为域名选择归属。
                        </div>
                    <?php else: ?>
                        <div class="mt-4 overflow-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-4 py-3">名称</th>
                                    <th class="px-4 py-3 text-right">操作</th>
                                </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                <?php foreach ($serverGroups as $sg): ?>
                                    <tr class="hover:bg-gray-50/60">
                                        <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($sg['name'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3 text-right">
                                            <form method="POST" class="inline" onsubmit="return confirm('确定删除该服务器组？');">
                                                <input type="hidden" name="action" value="delete_sg">
                                                <input type="hidden" name="redirect" value="domains.php?section=server_groups">
                                                <input type="hidden" name="server_group_id" value="<?= (int)$sg['id'] ?>">
                                                <button type="submit" class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">
                                                    删除
                                                </button>
                                            </form>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($activeSection === 'upload_domain_group' && $sgFeature): ?>
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-3">
                    <p class="text-sm font-semibold text-gray-900">上传域名组</p>
                    <p class="mt-1 text-xs text-gray-500">选择默认服务器组后，该组下的域名将作为「上传管理 → 域名分配」的可选范围。请在「域名管理」中将上传/封面线路域名归入对应组。</p>
                </div>
                <div class="px-5 py-4 space-y-4">
                    <form method="POST" class="max-w-md space-y-3">
                        <input type="hidden" name="action" value="save_upload_domain_group">
                        <input type="hidden" name="redirect" value="domains.php?section=upload_domain_group">
                        <div>
                            <label class="mb-1 block text-sm font-medium text-gray-700">默认服务器组</label>
                            <?php if (empty($serverGroups)): ?>
                                <p class="text-sm text-gray-500">请先在「服务器组」中创建分组。</p>
                            <?php else: ?>
                                <select name="upload_server_group_id" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                                    <option value="">未配置（上传域名分配不可用）</option>
                                    <?php foreach ($serverGroups as $sg): ?>
                                        <option value="<?= (int)$sg['id'] ?>" <?= $uploadDomainServerGroupId === (int)$sg['id'] ? 'selected' : '' ?>>
                                            <?= htmlspecialchars($sg['name'], ENT_QUOTES, 'UTF-8') ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            <?php endif; ?>
                        </div>
                        <?php if (!empty($serverGroups)): ?>
                            <button type="submit" class="rounded-lg bg-blue-600 px-4 py-2 text-sm font-semibold text-white hover:bg-blue-700">保存配置</button>
                        <?php endif; ?>
                    </form>

                    <?php if ($uploadDomainServerGroupId !== null): ?>
                        <?php
                        $uploadSgName = '服务器组 #' . $uploadDomainServerGroupId;
                        foreach ($serverGroups as $sg) {
                            if ((int)$sg['id'] === $uploadDomainServerGroupId) {
                                $uploadSgName = (string)$sg['name'];
                                break;
                            }
                        }
                        ?>
                        <div class="rounded-xl border border-violet-100 bg-violet-50/60 px-4 py-3">
                            <p class="text-sm font-medium text-violet-900">当前组：<?= htmlspecialchars($uploadSgName, ENT_QUOTES, 'UTF-8') ?></p>
                            <p class="mt-1 text-xs text-violet-800/80">组内域名 <?= (int)$uploadPoolCount ?> 条，将出现在上传管理的域名分配列表中。</p>
                        </div>
                        <?php if ($uploadPoolCount === 0): ?>
                            <p class="text-sm text-gray-500">该组下暂无域名，请前往「域名管理」添加域名并选择此服务器组。</p>
                        <?php else: ?>
                            <ul class="divide-y divide-gray-100 rounded-xl border border-gray-200 bg-white text-sm">
                                <?php foreach ($uploadPoolDomains as $d): ?>
                                    <li class="flex items-center justify-between gap-2 px-4 py-2.5">
                                        <span class="font-medium text-gray-900"><?= htmlspecialchars((string)$d['domain'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php if (!empty($d['display_name'])): ?>
                                            <span class="truncate text-xs text-gray-500"><?= htmlspecialchars((string)$d['display_name'], ENT_QUOTES, 'UTF-8') ?></span>
                                        <?php endif; ?>
                                    </li>
                                <?php endforeach; ?>
                            </ul>
                        <?php endif; ?>
                    <?php else: ?>
                        <p class="text-sm text-gray-500">尚未配置上传域名组，上传管理中的「域名分配」将无法勾选域名。</p>
                    <?php endif; ?>
                </div>
            </div>

        <?php elseif ($activeSection === 'domains'): ?>
            <div class="grid gap-4 lg:grid-cols-3">
                <section class="lg:col-span-1">
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden lg:sticky lg:top-4">
                        <div class="border-b border-gray-100 px-5 py-3">
                            <p class="text-sm font-semibold text-gray-900">添加域名</p>
                            <p class="mt-1 text-xs text-gray-500">支持设置显示名称与所属服务器组（如启用）。</p>
                        </div>
                        <form method="POST" class="px-5 py-4 space-y-4">
                            <input type="hidden" name="action" value="add">
                            <input type="hidden" name="redirect" value="domains.php?section=domains">
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">域名</label>
                                <input type="text" name="domain" required placeholder="example.com"
                                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                            </div>
                            <div>
                                <label class="mb-1 block text-sm font-medium text-gray-700">显示名称（可选）</label>
                                <input type="text" name="display_name" placeholder="例如：国内优化线路"
                                       class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
                            </div>
                            <?php if ($sgFeature): ?>
                                <div>
                                    <label class="mb-1 block text-sm font-medium text-gray-700">所属服务器组（可选）</label>
                                    <select name="server_group_id" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
                                        <option value="">未分组</option>
                                        <?php foreach ($serverGroups as $sg): ?>
                                            <option value="<?= (int)$sg['id'] ?>"><?= htmlspecialchars($sg['name'], ENT_QUOTES, 'UTF-8') ?></option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            <?php endif; ?>
                            <button class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">
                                添加
                            </button>
                        </form>
                    </div>
                </section>

                <section class="lg:col-span-2">
                    <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                        <div class="border-b border-gray-100 px-5 py-3">
                            <p class="text-sm font-semibold text-gray-900">域名列表</p>
                            <p class="mt-1 text-xs text-gray-500">当前共 <?= (int)$domainCount ?> 条。</p>
                        </div>
                        <div class="overflow-auto">
                            <table class="min-w-full text-left text-sm">
                                <thead class="bg-gray-50 text-xs uppercase tracking-wide text-gray-500">
                                <tr>
                                    <th class="px-4 py-3">ID</th>
                                    <th class="px-4 py-3">域名</th>
                                    <th class="px-4 py-3">显示名</th>
                                    <?php if ($sgFeature): ?><th class="px-4 py-3">服务器组</th><?php endif; ?>
                                    <th class="px-4 py-3 text-right">操作</th>
                                </tr>
                                </thead>
                                <tbody class="divide-y divide-gray-100">
                                <?php foreach ($domains as $d): ?>
                                    <?php
                                    $editSgJs = 'null';
                                    if ($sgFeature && isset($d['server_group_id']) && $d['server_group_id'] !== null && $d['server_group_id'] !== '') {
                                        $editSgJs = (int)$d['server_group_id'];
                                    }
                                    ?>
                                    <tr class="hover:bg-gray-50/60">
                                        <td class="px-4 py-3 text-gray-500"><?= (int)$d['id'] ?></td>
                                        <td class="px-4 py-3 font-medium text-gray-900"><?= htmlspecialchars($d['domain'], ENT_QUOTES, 'UTF-8') ?></td>
                                        <td class="px-4 py-3 text-gray-700"><?= htmlspecialchars($d['display_name'] ?? '未设置', ENT_QUOTES, 'UTF-8') ?></td>
                                        <?php if ($sgFeature): ?>
                                            <td class="px-4 py-3 text-gray-600"><?= htmlspecialchars($d['server_group_name'] ?? '未分组', ENT_QUOTES, 'UTF-8') ?></td>
                                        <?php endif; ?>
                                        <td class="px-4 py-3 text-right">
                                            <div class="inline-flex items-center gap-2">
                                                <button type="button"
                                                        onclick="showEditDomain(<?= (int)$d['id'] ?>,'<?= htmlspecialchars($d['domain'],ENT_QUOTES) ?>','<?= htmlspecialchars($d['display_name'] ?? '',ENT_QUOTES) ?>',<?= $editSgJs ?>)"
                                                        class="rounded-lg border border-gray-200 bg-white px-3 py-1.5 text-xs font-medium text-gray-700 hover:bg-gray-50">
                                                    编辑
                                                </button>
                                                <form method="POST" class="inline" onsubmit="return confirm('确定删除该域名吗？');">
                                                    <input type="hidden" name="action" value="delete">
                                                    <input type="hidden" name="redirect" value="domains.php?section=domains">
                                                    <input type="hidden" name="domain_id" value="<?= (int)$d['id'] ?>">
                                                    <button class="rounded-lg border border-red-200 bg-white px-3 py-1.5 text-xs font-medium text-red-600 hover:bg-red-50">
                                                        删除
                                                    </button>
                                                </form>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </section>
            </div>

        <?php else: ?>
            <div class="rounded-2xl border border-gray-200 bg-white shadow-sm overflow-hidden">
                <div class="border-b border-gray-100 px-5 py-3">
                    <p class="text-sm font-semibold text-gray-900">分配域名给用户组</p>
                    <p class="mt-1 text-xs text-gray-500">按用户组保存可用线路。支持整服务器组分配与手动勾选合并（如启用）。</p>
                </div>
                <div class="px-5 py-5 space-y-4">
                    <?php foreach ($groups as $g): ?>
                        <div class="rounded-2xl border border-gray-200 bg-white p-4">
                            <div class="flex flex-wrap items-center justify-between gap-2">
                                <p class="text-sm font-semibold text-gray-900"><?= htmlspecialchars($g['name'], ENT_QUOTES, 'UTF-8') ?></p>
                                <span class="text-xs text-gray-400">#<?= (int)$g['id'] ?></span>
                            </div>

<?php if ($bundleSgFeature): ?>
                            <div class="mt-3 rounded-xl border border-sky-100 bg-sky-50/70 p-3">
                                <p class="mb-2 text-xs text-sky-900/90"><strong>整服务器组分配</strong>：可多选。勾选后，该用户组将自动拥有所选服务器组下<strong>全部域名</strong>的播放线路，与下方<strong>手动勾选</strong>的线路<strong>合并</strong>生效。</p>
                                <form method="POST" class="space-y-2">
                                    <input type="hidden" name="action" value="assign_group_server_groups">
                                    <input type="hidden" name="redirect" value="domains.php?section=assign">
                                    <input type="hidden" name="group_id" value="<?= (int)$g['id'] ?>">
                                    <?php if (empty($serverGroups)): ?>
                                        <p class="text-xs text-gray-500">请先在「服务器组」中添加服务器组。</p>
                                    <?php else: ?>
                                        <div class="flex flex-wrap gap-3">
                                            <?php foreach ($serverGroups as $sg): ?>
                                                <label class="flex items-center gap-2 text-sm">
                                                    <input type="checkbox" name="server_group_ids[]" value="<?= (int)$sg['id'] ?>" <?= in_array((int)$sg['id'], $groupServerGroups[(int)$g['id']] ?? [], true) ? 'checked' : '' ?>>
                                                    <span><?= htmlspecialchars($sg['name'], ENT_QUOTES, 'UTF-8') ?></span>
                                                </label>
                                            <?php endforeach; ?>
                                        </div>
                                        <button type="submit" class="rounded-lg bg-sky-700 px-4 py-2 text-xs font-medium text-white hover:bg-sky-800">保存服务器组分配</button>
                                    <?php endif; ?>
                                </form>
                            </div>
<?php endif; ?>

<?php if ($sgFeature): ?>
<?php $gid = (int)$g['id']; ?>
                            <div class="mt-3 rounded-xl border border-amber-100 bg-amber-50/60 p-3">
                                <p class="mb-2 text-xs text-amber-900/80"><strong>按服务器组勾选线路</strong>：按「未分组」与各服务器组分块列出，可同时勾选任意多个组下的域名；保存后将以本次勾选为准覆盖该用户组的手动线路分配（不影响上方的整服务器组分配）。</p>
                                <form method="POST" class="space-y-4">
                                    <input type="hidden" name="action" value="assign_group_domains">
                                    <input type="hidden" name="redirect" value="domains.php?section=assign">
                                    <input type="hidden" name="group_id" value="<?= $gid ?>">
<?php foreach ($sgPanelOrder as $sk): ?>
<?php
$list = $domainsBySgKey[$sk] ?? [];
if ($sk === 'ungrouped') {
    $sectionTitle = '未分组';
} else {
    $sectionTitle = '服务器组 #' . (int)$sk;
    foreach ($serverGroups as $sg) {
        if ((int)$sg['id'] === (int)$sk) {
            $sectionTitle = $sg['name'];
            break;
        }
    }
}
?>
                                    <div class="rounded border border-amber-200/80 bg-white p-3">
                                        <div class="mb-2 text-xs font-semibold text-amber-900"><?= htmlspecialchars($sectionTitle, ENT_QUOTES, 'UTF-8') ?></div>
<?php if (empty($list)): ?>
                                        <p class="text-xs text-gray-400">该分组下暂无域名。</p>
<?php else: ?>
                                        <div class="flex flex-wrap gap-3">
<?php foreach ($list as $d): ?>
                                            <label class="flex items-center gap-2 text-sm">
                                                <input type="checkbox" name="domain_ids[]" value="<?= (int)$d['id'] ?>"
                                                    <?= in_array((int)$d['id'], $groupDomains[$gid] ?? [], true) ? 'checked' : '' ?>>
                                                <span><?= htmlspecialchars($d['domain'], ENT_QUOTES, 'UTF-8') ?></span>
                                            </label>
<?php endforeach; ?>
                                        </div>
<?php endif; ?>
                                    </div>
<?php endforeach; ?>
                                    <button type="submit" class="rounded-lg bg-amber-700 px-4 py-2 text-xs font-medium text-white hover:bg-amber-800">保存域名分配</button>
                                </form>
                            </div>
<?php endif; ?>

<?php if (!$sgFeature): ?>
                            <p class="mt-3 text-xs text-gray-500">线路分配需数据库支持服务器组，请先执行项目中的 <code class="rounded bg-gray-100 px-1">update_db.php</code> 完成升级。</p>
<?php endif; ?>
                        </div>
<?php endforeach; ?>
                </div>
            </div>
        <?php endif; ?>
    </section>
</div>
</main>

<!-- 编辑域名弹窗 -->
<div id="editDomainModal" class="fixed inset-0 hidden items-center justify-center bg-black/50" style="display:none">
<div class="relative w-full max-w-md rounded-2xl border border-gray-200 bg-white p-5 shadow-xl">
<button class="absolute right-3 top-2 text-2xl leading-none text-gray-400 hover:text-gray-700" type="button" onclick="closeEdit()">&times;</button>
<form method="POST" class="space-y-3">
<input type="hidden" name="action" value="edit">
<input type="hidden" name="redirect" value="domains.php?section=domains">
<input type="hidden" name="domain_id" id="edit_domain_id">
<div>
    <label class="mb-1 block text-sm font-medium text-gray-700">域名</label>
    <input type="text" name="domain" id="edit_domain" required class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
</div>
<div>
    <label class="mb-1 block text-sm font-medium text-gray-700">显示名称</label>
    <input type="text" name="display_name" id="edit_display_name" class="w-full rounded-lg border border-gray-300 px-3 py-2 text-sm focus:border-blue-500 focus:outline-none">
</div>
<?php if ($sgFeature): ?>
<div>
    <label class="mb-1 block text-sm font-medium text-gray-700">所属服务器组</label>
    <select name="server_group_id" id="edit_server_group_id" class="w-full rounded-lg border border-gray-300 bg-white px-3 py-2 text-sm">
<option value="">未分组</option>
<?php foreach ($serverGroups as $sg): ?>
<option value="<?= (int)$sg['id'] ?>"><?= htmlspecialchars($sg['name']) ?></option>
<?php endforeach; ?>
</select>
</div>
<?php endif; ?>
<button class="w-full rounded-xl bg-blue-600 px-4 py-2.5 text-sm font-semibold text-white hover:bg-blue-700">保存</button>
</form>
</div>
</div>

<script>
function showEditDomain(id, domain, name, serverGroupId){
edit_domain_id.value=id;
edit_domain.value=domain;
edit_display_name.value=name;
var sgSel=document.getElementById('edit_server_group_id');
if(sgSel){
sgSel.value=(serverGroupId===null||serverGroupId===undefined||serverGroupId==='')?'':String(serverGroupId);
}
document.getElementById('editDomainModal').style.display='flex';
}
function closeEdit(){document.getElementById('editDomainModal').style.display='none';}
</script>
</body>
</html>
