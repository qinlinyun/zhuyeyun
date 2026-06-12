<?php
/**
 * 事件表安装引导界面（用于 SSE 实时广播）
 * 文件名建议：install_watch_events.php
 * 用完建议删除或改名，避免被重复访问。
 */

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/config/database.php';

requireLogin();
if (!isAdmin()) {
    http_response_code(403);
    exit('403 Forbidden - 仅管理员可访问');
}

$pdo = null;
$error = '';
$resultLogs = [];
$installed = false;

function tableExists(PDO $pdo, string $table): bool {
    $stmt = $pdo->prepare("SHOW TABLES LIKE ?");
    $stmt->execute([$table]);
    return (bool)$stmt->fetchColumn();
}

try {
    $pdo = getDB();
} catch (Throwable $e) {
    $error = $e->getMessage();
}

// 检测是否已安装
if ($pdo) {
    $installed = tableExists($pdo, 'watch_progress_events');
}

// 执行安装
if ($pdo && isset($_POST['install']) && $_POST['install'] === '1') {
    try {
        $pdo->beginTransaction();

        $sql = "
CREATE TABLE IF NOT EXISTS `watch_progress_events` (
  `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  `actor_user_id` int(11) DEFAULT NULL,
  `target_user_id` int(11) DEFAULT NULL,
  `action` varchar(50) NOT NULL,
  `video_id` int(11) DEFAULT NULL,
  `episode_id` int(11) DEFAULT NULL,
  `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `idx_target_user` (`target_user_id`),
  KEY `idx_created` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
        ";
        $pdo->exec($sql);
        $resultLogs[] = "✅ 已执行创建表 SQL：watch_progress_events";

        // 二次确认
        $installed = tableExists($pdo, 'watch_progress_events');
        if ($installed) {
            $resultLogs[] = "✅ 检测通过：watch_progress_events 表已存在";
        } else {
            $resultLogs[] = "❌ 检测失败：表未创建成功（请检查数据库权限/配置）";
        }

        $pdo->commit();
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $resultLogs[] = "❌ 安装失败：" . $e->getMessage();
    }
}

// 获取数据库名称（可选展示）
$dbName = '';
try {
    if ($pdo) {
        $dbName = (string)$pdo->query("SELECT DATABASE()")->fetchColumn();
    }
} catch (Throwable $e) {
    // ignore
}

?>
<!DOCTYPE html>
<html lang="zh-CN" class="scroll-smooth">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>事件表安装引导（实时广播核心）</title>

<!-- 沿用你的静态资源，不改变原体系 -->

<?php include __DIR__ . '/components/theme-dynamic.php'; ?>

<style>
.card{background:#fff;border-radius:12px;box-shadow:0 1px 8px rgba(0,0,0,.06)}
.mono{font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace;}
.badge{display:inline-flex;align-items:center;gap:.4rem;padding:.25rem .6rem;border-radius:999px;font-size:.8rem}
.badge-ok{background:rgba(34,197,94,.12);color:#16a34a}
.badge-no{background:rgba(239,68,68,.12);color:#ef4444}
.badge-warn{background:rgba(234,179,8,.14);color:#a16207}
</style>
</head>

<body class="bg-gray-100 text-gray-900">
<nav class="bg-white shadow-sm sticky top-0 z-50">
  <div class="max-w-screen-lg mx-auto px-4 py-3 flex justify-between items-center">
    <div class="font-semibold">📣 事件表安装引导（实时广播核心）</div>
    <div class="text-sm">
      <a href="/" class="mr-3">返回首页</a>
      <a href="logout.php" class="text-red-500">退出</a>
    </div>
  </div>
</nav>

<main class="max-w-screen-lg mx-auto px-4 py-6 space-y-5">

  <div class="card p-5">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div>
        <div class="text-lg font-semibold">连接状态</div>
        <div class="text-sm text-gray-500 mt-1">
          此页面用于创建 <span class="mono">watch_progress_events</span>（SSE 实时推送事件表）。
        </div>
      </div>

      <?php if ($pdo && !$error): ?>
        <span class="badge badge-ok">✅ 数据库已连接</span>
      <?php else: ?>
        <span class="badge badge-no">❌ 连接失败</span>
      <?php endif; ?>
    </div>

    <?php if ($error): ?>
      <div class="mt-4 p-3 rounded bg-red-50 text-red-600 text-sm mono">
        <?=htmlspecialchars($error)?>
      </div>
    <?php else: ?>
      <div class="mt-4 grid grid-cols-1 md:grid-cols-2 gap-3 text-sm">
        <div class="p-3 rounded bg-gray-50">
          <div class="text-gray-500">当前数据库</div>
          <div class="mono mt-1"><?=htmlspecialchars($dbName ?: '（未返回）')?></div>
        </div>
        <div class="p-3 rounded bg-gray-50">
          <div class="text-gray-500">目标表</div>
          <div class="mono mt-1">watch_progress_events</div>
        </div>
      </div>
    <?php endif; ?>
  </div>

  <div class="card p-5">
    <div class="flex items-center justify-between gap-3 flex-wrap">
      <div class="text-lg font-semibold">安装状态</div>
      <?php if ($installed): ?>
        <span class="badge badge-ok">✅ 已安装</span>
      <?php else: ?>
        <span class="badge badge-warn">⚠️ 未安装</span>
      <?php endif; ?>
    </div>

    <div class="mt-3 text-sm text-gray-600 leading-7">
      <ul class="list-disc pl-5 space-y-1">
        <li>该表用于记录“保存进度 / 删除记录 / 清空记录”等事件。</li>
        <li>用户端与管理员端通过 SSE 订阅事件，实现“几乎无延迟”实时刷新。</li>
        <li>安装完成后建议删除本文件，避免被他人访问。</li>
      </ul>
    </div>

    <?php if ($pdo && !$installed): ?>
      <form method="post" class="mt-4">
        <input type="hidden" name="install" value="1">
        <button class="px-4 py-2 rounded bg-red-500 text-white text-sm hover:bg-red-600">
          🚀 一键创建 watch_progress_events 表
        </button>
      </form>
    <?php elseif ($installed): ?>
      <div class="mt-4 p-3 rounded bg-green-50 text-green-700 text-sm">
        ✅ 表已存在，无需重复安装。<br>
        建议：现在就把 <span class="mono">install_watch_events.php</span> 删除或改名，以免被他人访问。
      </div>
    <?php endif; ?>

    <?php if (!empty($resultLogs)): ?>
      <div class="mt-4 p-3 rounded bg-gray-50 text-sm mono space-y-1">
        <?php foreach ($resultLogs as $line): ?>
          <div><?=htmlspecialchars($line)?></div>
        <?php endforeach; ?>
      </div>
    <?php endif; ?>
  </div>

  <div class="card p-5">
    <div class="text-lg font-semibold">下一步</div>
    <div class="mt-2 text-sm text-gray-600 leading-7 space-y-2">
      <div>创建成功后，你需要确保：</div>
      <ul class="list-disc pl-5 space-y-1">
        <li><span class="mono">api/save_progress.php</span> / <span class="mono">api/delete_progress.php</span> / <span class="mono">api/clear_progress.php</span> 在执行后写入事件表</li>
        <li><span class="mono">api/progress_sse.php</span> 能够推送事件（建议 Nginx 关闭缓冲）</li>
      </ul>
      <div class="text-xs text-gray-500">
        小提示：如果你使用 Nginx/宝塔，SSE 需要禁用代理缓冲，否则会看起来“有延迟”。
      </div>
    </div>
  </div>

</main>
</body>
</html>
