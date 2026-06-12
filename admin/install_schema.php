<?php
$configPath = __DIR__ . '/config/database.php';
if (!file_exists($configPath)) {
    $configPath = dirname(__DIR__) . '/config/database.php';
}
if (!file_exists($configPath)) {
    die('找不到数据库配置文件：请确认 config/database.php 是否存在。');
}
require_once $configPath;

$message = '';
$error = '';

function ensureLogTable(PDO $pdo): void {
    $pdo->exec("CREATE TABLE IF NOT EXISTS `schema_change_logs` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `applied_sql` longtext NOT NULL,
        `tables_json` text NOT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
}

function extractTableNames(string $sql): array {
    $pattern = '/CREATE\s+TABLE\s+(?:IF\s+NOT\s+EXISTS\s+)?(?:`?[a-zA-Z0-9_]+`?\.)?`?([a-zA-Z0-9_]+)`?/i';
    if (!preg_match_all($pattern, $sql, $matches)) {
        return [];
    }
    $names = [];
    foreach ($matches[1] as $name) {
        if ($name !== '' && preg_match('/^[a-zA-Z0-9_]+$/', $name)) {
            $names[] = $name;
        }
    }
    return array_values(array_unique($names));
}

function splitSqlStatements(string $sql): array {
    $statements = [];
    $buffer = '';
    $inSingle = false;
    $inDouble = false;
    $inBacktick = false;
    $length = strlen($sql);

    for ($i = 0; $i < $length; $i++) {
        $char = $sql[$i];
        $prev = $i > 0 ? $sql[$i - 1] : '';

        if ($char === "'" && !$inDouble && !$inBacktick && $prev !== '\\') {
            $inSingle = !$inSingle;
        } elseif ($char === '"' && !$inSingle && !$inBacktick && $prev !== '\\') {
            $inDouble = !$inDouble;
        } elseif ($char === '`' && !$inSingle && !$inDouble) {
            $inBacktick = !$inBacktick;
        }

        if ($char === ';' && !$inSingle && !$inDouble && !$inBacktick) {
            $trimmed = trim($buffer);
            if ($trimmed !== '') {
                $statements[] = $trimmed;
            }
            $buffer = '';
            continue;
        }

        $buffer .= $char;
    }

    $trimmed = trim($buffer);
    if ($trimmed !== '') {
        $statements[] = $trimmed;
    }

    return $statements;
}

try {
    $pdo = getDB();
    $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    ensureLogTable($pdo);

    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $action = $_POST['action'] ?? '';

        if ($action === 'apply') {
            $sql = trim($_POST['sql'] ?? '');
            if ($sql === '') {
                throw new RuntimeException('请输入要创建的表 SQL。');
            }

            $tables = extractTableNames($sql);
            if (empty($tables)) {
                throw new RuntimeException('未检测到 CREATE TABLE 语句，仅支持创建表的 SQL。');
            }

            $statements = splitSqlStatements($sql);
            if (empty($statements)) {
                throw new RuntimeException('未解析到可执行的 SQL 语句。');
            }

            foreach ($statements as $statement) {
                $pdo->exec($statement);
            }

            $stmt = $pdo->prepare("INSERT INTO `schema_change_logs` (`applied_sql`, `tables_json`) VALUES (?, ?)");
            $stmt->execute([$sql, json_encode($tables, JSON_UNESCAPED_UNICODE)]);

            $message = '写入成功，已记录此次变更，可回滚到上一个版本。';
        } elseif ($action === 'rollback_to') {
            $targetId = (int)($_POST['log_id'] ?? 0);
            if ($targetId <= 0) {
                throw new RuntimeException('无效的回滚目标。');
            }

            $stmt = $pdo->prepare("SELECT id, tables_json FROM `schema_change_logs` WHERE id > ? ORDER BY id DESC");
            $stmt->execute([$targetId]);
            $logsToRollback = $stmt->fetchAll();

            foreach ($logsToRollback as $log) {
                $tables = json_decode($log['tables_json'], true) ?: [];
                foreach ($tables as $tableName) {
                    if (preg_match('/^[a-zA-Z0-9_]+$/', $tableName)) {
                        $pdo->exec("DROP TABLE IF EXISTS `{$tableName}`");
                    }
                }
            }

            $stmt = $pdo->prepare("DELETE FROM `schema_change_logs` WHERE id > ?");
            $stmt->execute([$targetId]);
            $message = '回滚完成，目标版本之后的记录已自动清除。';
        }
    }

    $logs = $pdo->query("SELECT id, created_at, tables_json FROM `schema_change_logs` ORDER BY id DESC")->fetchAll();
} catch (Throwable $e) {
    if (isset($pdo) && $pdo->inTransaction()) {
        $pdo->rollBack();
    }
    $error = $e->getMessage();
    $logs = $logs ?? [];
}
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>数据库表写入与回滚</title>

    <style>
        .mono { font-family: ui-monospace, SFMono-Regular, Menlo, Monaco, Consolas, "Liberation Mono", "Courier New", monospace; }
    </style>
</head>
<body class="min-h-screen bg-gray-100 text-gray-900">
    <div class="mx-auto max-w-4xl px-4 py-10">
        <div class="rounded-lg bg-white p-6 shadow">
            <h1 class="mb-2 text-xl font-semibold">数据库表写入与回滚</h1>
            <p class="mb-6 text-sm text-gray-600">粘贴 CREATE TABLE SQL 并写入，同时自动记录版本。每次写入都可回滚到上一个版本。</p>

            <?php if ($message): ?>
                <div class="mb-4 rounded border border-green-200 bg-green-50 px-3 py-2 text-sm text-green-700"><?php echo htmlspecialchars($message); ?></div>
            <?php endif; ?>
            <?php if ($error): ?>
                <div class="mb-4 rounded border border-red-200 bg-red-50 px-3 py-2 text-sm text-red-700"><?php echo htmlspecialchars($error); ?></div>
            <?php endif; ?>

            <form method="POST" class="space-y-3">
                <input type="hidden" name="action" value="apply">
                <label class="block text-sm font-medium text-gray-700">要创建的表 SQL</label>
                <textarea name="sql" rows="8" class="mono w-full rounded border border-gray-300 px-3 py-2 text-sm focus:border-red-500 focus:outline-none" placeholder="例如：CREATE TABLE ...;"></textarea>
                <button type="submit" class="rounded bg-red-600 px-4 py-2 text-sm font-semibold text-white hover:bg-red-700">写入数据库</button>
            </form>
        </div>

        <div class="mt-8 rounded-lg bg-white p-6 shadow">
            <div class="mb-4 flex items-center justify-between">
                <h2 class="text-lg font-semibold">写入记录</h2>
                <span class="text-xs text-gray-500">点击回滚将恢复到该版本</span>
            </div>

            <?php if (empty($logs)): ?>
                <div class="rounded border border-dashed border-gray-200 p-4 text-sm text-gray-500">暂无写入记录。</div>
            <?php else: ?>
                <div class="space-y-3">
                    <?php foreach ($logs as $log): ?>
                        <?php $tables = json_decode($log['tables_json'], true) ?: []; ?>
                        <div class="flex flex-col gap-3 rounded border border-gray-200 p-4 sm:flex-row sm:items-center sm:justify-between">
                            <div>
                                <div class="text-sm font-medium">版本 #<?php echo (int)$log['id']; ?></div>
                                <div class="mt-1 text-xs text-gray-500">时间：<?php echo htmlspecialchars($log['created_at']); ?></div>
                                <div class="mt-2 text-xs text-gray-600">表：<span class="mono"><?php echo htmlspecialchars(implode(', ', $tables)); ?></span></div>
                            </div>
                            <form method="POST" class="shrink-0">
                                <input type="hidden" name="action" value="rollback_to">
                                <input type="hidden" name="log_id" value="<?php echo (int)$log['id']; ?>">
                                <button type="submit" class="rounded border border-red-600 px-3 py-2 text-xs font-semibold text-red-600 hover:bg-red-50">回滚到此版本</button>
                            </form>
                        </div>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>

