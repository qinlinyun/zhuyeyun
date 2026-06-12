<?php
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';
require_once __DIR__ . '/includes/traffic.php';

requireLogin();
$user = getCurrentUser();
$pdo = getDB();

$enabled = trafficFeatureEnabled($pdo);
$traffic = ['total'=>0,'used'=>0,'left'=>0,'expires_at'=>null,'expired'=>false];
$groupInfo = null;
if ($enabled) {
    $traffic = getUserTraffic($pdo, (int)$user['id']);
    $st = $pdo->prepare("SELECT name, default_traffic, traffic_validity_days FROM user_groups WHERE id = ?");
    $st->execute([(int)$user['group_id']]);
    $groupInfo = $st->fetch();
}

// 获取该用户已解锁的视频
$unlocks = [];
if ($enabled) {
    $st = $pdo->prepare("SELECT vu.*, v.title, v.cover, v.is_traffic, v.traffic_cost
        FROM video_unlocks vu
        LEFT JOIN videos v ON v.id = vu.video_id
        WHERE vu.user_id = ?
        ORDER BY vu.id DESC");
    $st->execute([(int)$user['id']]);
    $unlocks = $st->fetchAll();
}

// 流量变更记录（用户视角）
$logs = [];
$earningLogs = [];
if ($enabled) {
    $st = $pdo->prepare("SELECT * FROM traffic_logs WHERE user_id = ? ORDER BY id DESC LIMIT 50");
    $st->execute([(int)$user['id']]);
    $logs = $st->fetchAll();
    $earningLogs = fetchUserEarningLogs($pdo, (int)$user['id'], 50);
}

$total = (int)$traffic['total'];
$used = (int)$traffic['used'];
$left = (int)$traffic['left'];
$baseTotal = (int)($traffic['base_total'] ?? 0);
$baseUsed = (int)($traffic['base_used'] ?? 0);
$baseLeft = (int)($traffic['base_left'] ?? 0);
$earningAvailable = (int)($traffic['earning_available'] ?? 0);
$earningFrozen = (int)($traffic['earning_frozen'] ?? 0);
$pct = $total > 0 ? min(100, round($used / $total * 100)) : 0;
?>
<!DOCTYPE html>
<html lang="zh-CN" class="scroll-smooth">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>流量详情 - 竹叶云控平台</title>
<link rel="icon" href="https://css.qinlinyun.cn/ico/ico.png" type="image/png">
<?php include __DIR__ . '/components/theme-head.php'; ?>

<?php include __DIR__ . '/components/theme-dynamic.php'; ?>
<style>
.fade-up{opacity:0;transform:translateY(20px);transition:.5s}
.fade-up.show{opacity:1;transform:none}
.bar{transition: width .8s ease}
</style>
</head>
<body class="bg-gray-100 text-gray-900">

<nav class="bg-white shadow-sm sticky top-0 z-50">
<div class="mx-auto max-w-screen-xl px-4 py-3 flex items-center gap-4 text-sm">
    <a class="rounded-full px-3 py-1 hover:bg-gray-100" href="index.php">首页</a>
    <a class="rounded-full px-3 py-1 hover:bg-gray-100" href="profile.php">个人中心</a>
    <a class="rounded-full bg-gray-100 px-3 py-1" href="traffic.php">流量详情</a>
    <?php $linkExtraClass = 'ml-auto'; include __DIR__ . '/components/logout-nav-link.php'; ?>
    <?php include __DIR__ . '/components/theme-toggle.php'; ?>
</div>
</nav>

<main class="mx-auto max-w-screen-xl px-4 py-6 space-y-6">
<?php if (!$enabled): ?>
<div class="rounded-lg bg-white p-6 shadow text-sm text-gray-600">系统未启用流量功能。</div>
<?php else: ?>
    <?php if (isAdmin()): ?>
    <div class="rounded-lg bg-white p-4 shadow text-sm text-amber-700 fade-up">
        管理员账户不受流量限制，可观看任何流量视频。
    </div>
    <?php endif; ?>

    <h2 class="text-base font-semibold fade-up">我的流量</h2>
    <p class="text-xs text-gray-500 fade-up -mt-4">总流量与剩余流量 = 基础流量 + 可用收益流量（冻结收益不计入可用）</p>

    <section class="grid grid-cols-1 gap-4 sm:grid-cols-3">
        <div class="rounded-lg bg-white p-5 shadow fade-up">
            <div class="text-xs text-gray-500">总流量（基础 + 收益）</div>
            <div class="mt-2 text-2xl font-semibold"><?= $total ?></div>
            <div class="mt-1 text-xs text-gray-400">基础 <?= $baseTotal ?> + 收益 <?= $earningAvailable ?></div>
        </div>
        <div class="rounded-lg bg-white p-5 shadow fade-up">
            <div class="text-xs text-gray-500">已使用（仅基础）</div>
            <div class="mt-2 text-2xl font-semibold text-orange-500"><?= $used ?></div>
            <div class="mt-1 text-xs text-gray-400">基础已用 <?= $baseUsed ?> / <?= $baseTotal ?></div>
        </div>
        <div class="rounded-lg bg-white p-5 shadow fade-up">
            <div class="text-xs text-gray-500">剩余可用</div>
            <div class="mt-2 text-2xl font-semibold <?= $left > 0 ? 'text-green-600' : 'text-red-500' ?>">
                <?= $left ?>
            </div>
            <div class="mt-1 text-xs text-gray-400">基础 <?= $baseLeft ?> + 收益 <?= $earningAvailable ?></div>
        </div>
    </section>

    <?php if ($earningFrozen > 0): ?>
    <div class="rounded-lg border border-amber-200 bg-amber-50 px-4 py-3 text-sm text-amber-800 fade-up">
        冻结收益流量：<span class="font-semibold"><?= $earningFrozen ?></span>（不可用于解锁，待管理员处理）
    </div>
    <?php endif; ?>

    <section class="rounded-lg bg-white p-5 shadow fade-up">
        <h2 class="mb-3 text-base font-semibold">收益明细</h2>
        <?php if (empty($earningLogs)): ?>
            <div class="text-sm text-gray-500">暂无收益记录</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-gray-700">
                    <tr>
                        <th class="px-3 py-2">支付用户</th>
                        <th class="px-3 py-2">视频</th>
                        <th class="px-3 py-2">额度</th>
                        <th class="px-3 py-2">支付时间</th>
                        <th class="px-3 py-2">状态</th>
                        <th class="px-3 py-2">原因</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($earningLogs as $r): ?>
                    <tr>
                        <td class="px-3 py-2"><?= htmlspecialchars((string)($r['payer_username'] ?? '未知')) ?></td>
                        <td class="px-3 py-2"><?= htmlspecialchars((string)($r['video_title'] ?? '已删除视频')) ?></td>
                        <td class="px-3 py-2 text-green-600"><?= (int)$r['amount'] ?></td>
                        <td class="px-3 py-2 text-xs text-gray-500"><?= htmlspecialchars((string)$r['paid_at']) ?></td>
                        <td class="px-3 py-2 text-xs"><?= htmlspecialchars(trafficEarningStatusLabel((string)$r['status'])) ?></td>
                        <td class="px-3 py-2 text-xs text-gray-500"><?= htmlspecialchars((string)($r['reason'] ?? '')) ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>

    <!-- 进度条 + 详情 -->
    <section class="rounded-lg bg-white p-5 shadow fade-up">
        <div class="mb-2 flex items-center justify-between text-sm">
            <span>使用情况</span>
            <span class="text-gray-500">基础已用 <?= $used ?> / 合计 <?= $total ?> （<?= $pct ?>%）</span>
        </div>
        <div class="h-3 w-full overflow-hidden rounded-full bg-gray-200">
            <div class="bar h-full rounded-full <?= $pct >= 90 ? 'bg-red-500' : ($pct >= 60 ? 'bg-amber-500' : 'bg-green-500') ?>"
                 style="width:<?= $pct ?>%"></div>
        </div>
        <div class="mt-4 grid grid-cols-1 gap-3 text-sm md:grid-cols-2">
            <div class="rounded border border-gray-200 p-3">
                <div class="text-xs text-gray-500">所属分组</div>
                <div class="mt-1 font-medium"><?= htmlspecialchars($groupInfo['name'] ?? '-') ?></div>
            </div>
            <div class="rounded border border-gray-200 p-3">
                <div class="text-xs text-gray-500">分组默认流量</div>
                <div class="mt-1 font-medium"><?= (int)($groupInfo['default_traffic'] ?? 0) ?>
                    <?php $d = (int)($groupInfo['traffic_validity_days'] ?? 0); ?>
                    <span class="ml-2 text-xs text-gray-500">有效期：<?= $d > 0 ? ($d.' 天') : '永久' ?></span>
                </div>
            </div>
            <div class="rounded border border-gray-200 p-3 md:col-span-2">
                <div class="text-xs text-gray-500">流量到期时间</div>
                <div class="mt-1 font-medium <?= $traffic['expired'] ? 'text-red-500' : '' ?>">
                    <?= $traffic['expires_at'] ? htmlspecialchars($traffic['expires_at']) : '永久有效' ?>
                    <?php if ($traffic['expired']): ?><span class="ml-2 text-xs text-red-500">（已过期，请联系管理员充值）</span><?php endif; ?>
                </div>
            </div>
        </div>
    </section>

    <!-- 已解锁视频 -->
    <section class="rounded-lg bg-white p-5 shadow fade-up">
        <h2 class="mb-1 text-base font-semibold">我的解锁视频</h2>
        <p class="mb-3 text-xs text-gray-500">解锁有效期跟随流量周期：流量被自动/手动重置时，已解锁视频需重新解锁。</p>
        <?php if (empty($unlocks)): ?>
            <div class="text-sm text-gray-500">暂无解锁记录</div>
        <?php else: ?>
        <div class="grid grid-cols-1 gap-3 sm:grid-cols-2 lg:grid-cols-3">
            <?php foreach ($unlocks as $r): ?>
            <a href="play.php?id=<?= (int)$r['video_id'] ?>" class="rounded border border-gray-200 p-3 hover:border-red-400 transition">
                <div class="text-sm font-semibold line-clamp-1"><?= htmlspecialchars($r['title'] ?? '已删除视频') ?></div>
                <div class="mt-1 text-xs text-gray-500">花费：<?= (int)$r['cost'] ?></div>
                <div class="mt-1 text-xs <?= $traffic['expired'] ? 'text-red-500' : 'text-green-600' ?>">
                    <?php if ($traffic['expired']): ?>
                        ⚠ 流量已过期，需重新解锁
                    <?php elseif (!empty($traffic['next_reset_at'])): ?>
                        有效至下次重置：<?= htmlspecialchars($traffic['next_reset_at']) ?>
                    <?php elseif (!empty($traffic['expires_at'])): ?>
                        有效至流量到期：<?= htmlspecialchars($traffic['expires_at']) ?>
                    <?php else: ?>
                        长期有效（流量未重置时）
                    <?php endif; ?>
                </div>
                <div class="mt-1 text-[11px] text-gray-400">支付：<?= htmlspecialchars($r['paid_at']) ?></div>
            </a>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </section>

    <!-- 流量变更记录 -->
    <section class="rounded-lg bg-white p-5 shadow fade-up">
        <h2 class="mb-3 text-base font-semibold">流量变更记录</h2>
        <?php if (empty($logs)): ?>
            <div class="text-sm text-gray-500">暂无记录</div>
        <?php else: ?>
        <div class="overflow-x-auto">
            <table class="w-full text-left text-sm">
                <thead class="bg-gray-50 text-gray-700">
                    <tr>
                        <th class="px-3 py-2">时间</th>
                        <th class="px-3 py-2">动作</th>
                        <th class="px-3 py-2">变化</th>
                        <th class="px-3 py-2">变更前(总/已用)</th>
                        <th class="px-3 py-2">变更后(总/已用)</th>
                        <th class="px-3 py-2">备注</th>
                    </tr>
                </thead>
                <tbody class="divide-y">
                    <?php foreach ($logs as $r): ?>
                    <tr>
                        <td class="px-3 py-2 text-xs text-gray-500"><?= htmlspecialchars($r['created_at']) ?></td>
                        <td class="px-3 py-2 text-xs"><?= htmlspecialchars($r['action']) ?></td>
                        <td class="px-3 py-2 <?= (int)$r['change_amount'] >= 0 ? 'text-green-600' : 'text-red-500' ?>"><?= (int)$r['change_amount'] ?></td>
                        <td class="px-3 py-2"><?= (int)$r['before_total'] ?> / <?= (int)$r['before_used'] ?></td>
                        <td class="px-3 py-2"><?= (int)$r['after_total'] ?> / <?= (int)$r['after_used'] ?></td>
                        <td class="px-3 py-2 text-xs"><?= htmlspecialchars($r['remark'] ?? '') ?></td>
                    </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <?php endif; ?>
    </section>
<?php endif; ?>
</main>

<script>
const ob = new IntersectionObserver(es => es.forEach(e => e.isIntersecting && e.target.classList.add('show')), {threshold: .15});
document.querySelectorAll('.fade-up').forEach(el => ob.observe(el));
</script>
<?php include __DIR__ . '/components/theme-toggle-script.php'; ?>
</body>
</html>
