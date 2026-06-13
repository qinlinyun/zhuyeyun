<?php

function getDatabaseConfigPath(): string
{
    return __DIR__ . '/../config/database.php';
}

function loadDatabaseConfig(): ?array
{
    $path = getDatabaseConfigPath();
    if (!file_exists($path)) {
        return null;
    }
    $config = require $path;
    return is_array($config) ? $config : null;
}

function getDbConnection(bool $withoutDatabase = false): ?PDO
{
    $config = loadDatabaseConfig();
    if (!$config) {
        return null;
    }

    $host = $config['host'] ?? '127.0.0.1';
    $port = (int)($config['port'] ?? 3306);
    $dbname = $config['dbname'] ?? '';
    $charset = $config['charset'] ?? 'utf8mb4';

    if ($withoutDatabase || $dbname === '') {
        $dsn = "mysql:host={$host};port={$port};charset={$charset}";
    } else {
        $dsn = "mysql:host={$host};port={$port};dbname={$dbname};charset={$charset}";
    }

    try {
        return new PDO($dsn, $config['username'] ?? '', $config['password'] ?? '', [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);
    } catch (PDOException $e) {
        return null;
    }
}

function getDb(): ?PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $pdo = getDbConnection();
    return $pdo;
}

function databaseTablesExist(PDO $pdo): bool
{
    try {
        $stmt = $pdo->query("SHOW TABLES LIKE 'users'");
        if ($stmt->rowCount() === 0) {
            return false;
        }
        $stmt = $pdo->query("SHOW TABLES LIKE 'video_records'");
        return $stmt->rowCount() > 0;
    } catch (PDOException $e) {
        return false;
    }
}

function isDatabaseInstalled(): bool
{
    $pdo = getDb();
    if (!$pdo) {
        return false;
    }
    return databaseTablesExist($pdo);
}

function getInstallSchemaSql(): string
{
    return <<<'SQL'
CREATE TABLE IF NOT EXISTS `users` (
    `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username` VARCHAR(64) NOT NULL,
    `password` VARCHAR(255) NOT NULL,
    `created_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uk_username` (`username`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `video_records` (
    `id` VARCHAR(32) NOT NULL,
    `title` VARCHAR(255) NOT NULL DEFAULT '',
    `directory` VARCHAR(64) NOT NULL,
    `created_at` DATETIME NOT NULL,
    `screenshot` TINYINT(1) NOT NULL DEFAULT 0,
    `disguised` TINYINT(1) NOT NULL DEFAULT 0,
    `source` VARCHAR(16) NOT NULL DEFAULT 'upload',
    PRIMARY KEY (`id`),
    KEY `idx_created_at` (`created_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `app_settings` (
    `setting_key` VARCHAR(64) NOT NULL,
    `setting_value` VARCHAR(512) NOT NULL DEFAULT '',
    `updated_at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`setting_key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
SQL;
}

function createDatabaseTables(PDO $pdo): void
{
    $statements = array_filter(array_map('trim', explode(';', getInstallSchemaSql())));
    foreach ($statements as $sql) {
        if ($sql !== '') {
            $pdo->exec($sql);
        }
    }
}

function saveDatabaseConfig(array $config): bool
{
    $dir = dirname(getDatabaseConfigPath());
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        return false;
    }

    $export = var_export([
        'host' => $config['host'],
        'port' => (int)$config['port'],
        'dbname' => $config['dbname'],
        'username' => $config['username'],
        'password' => $config['password'],
        'charset' => $config['charset'] ?? 'utf8mb4',
    ], true);

    $content = "<?php\nreturn {$export};\n";
    return file_put_contents(getDatabaseConfigPath(), $content, LOCK_EX) !== false;
}

function createAdminUser(PDO $pdo, string $username, string $password): bool
{
    $hash = password_hash($password, PASSWORD_DEFAULT);
    $stmt = $pdo->prepare('INSERT INTO users (username, password) VALUES (?, ?)');
    return $stmt->execute([$username, $hash]);
}

function authenticateUser(string $username, string $password): ?array
{
    $pdo = getDb();
    if (!$pdo) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id, username, password FROM users WHERE username = ? LIMIT 1');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if (!$user || !password_verify($password, $user['password'])) {
        return null;
    }

    return [
        'id' => (int)$user['id'],
        'username' => $user['username'],
    ];
}

function readRecords(): array
{
    $pdo = getDb();
    if (!$pdo) {
        return [];
    }

    $stmt = $pdo->query(
        'SELECT id, title, directory, created_at, screenshot, disguised, source
         FROM video_records ORDER BY created_at DESC'
    );
    $rows = $stmt->fetchAll();

    return array_map(static function (array $row) {
        return [
            'id' => $row['id'],
            'title' => $row['title'],
            'directory' => $row['directory'],
            'created_at' => $row['created_at'],
            'screenshot' => (bool)$row['screenshot'],
            'disguised' => (bool)$row['disguised'],
            'source' => $row['source'],
        ];
    }, $rows);
}

function addRecord(array $record): bool
{
    $pdo = getDb();
    if (!$pdo) {
        return false;
    }

    $stmt = $pdo->prepare(
        'INSERT INTO video_records (id, title, directory, created_at, screenshot, disguised, source)
         VALUES (?, ?, ?, ?, ?, ?, ?)'
    );

    return $stmt->execute([
        $record['id'],
        $record['title'],
        $record['directory'],
        $record['created_at'],
        $record['screenshot'] ? 1 : 0,
        $record['disguised'] ? 1 : 0,
        $record['source'] ?? 'upload',
    ]);
}

function deleteRecordById(string $recordId): ?array
{
    $pdo = getDb();
    if (!$pdo) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT * FROM video_records WHERE id = ? LIMIT 1');
    $stmt->execute([$recordId]);
    $record = $stmt->fetch();
    if (!$record) {
        return null;
    }

    $del = $pdo->prepare('DELETE FROM video_records WHERE id = ?');
    $del->execute([$recordId]);

    return $record;
}

function migrateRecordsFromJson(string $jsonPath, PDO $pdo): int
{
    if (!file_exists($jsonPath)) {
        return 0;
    }

    $content = file_get_contents($jsonPath);
    $records = json_decode($content, true);
    if (!is_array($records) || empty($records)) {
        return 0;
    }

    $count = 0;
    $check = $pdo->prepare('SELECT id FROM video_records WHERE id = ? LIMIT 1');
    foreach ($records as $record) {
        if (empty($record['id'])) {
            continue;
        }
        $check->execute([$record['id']]);
        if ($check->fetch()) {
            continue;
        }

        addRecord([
            'id' => $record['id'],
            'title' => $record['title'] ?? '',
            'directory' => $record['directory'] ?? '',
            'created_at' => $record['created_at'] ?? date('Y-m-d H:i:s'),
            'screenshot' => !empty($record['screenshot']),
            'disguised' => !empty($record['disguised']),
            'source' => $record['source'] ?? 'upload',
        ]);
        $count++;
    }

    return $count;
}

function sliceInstallExpectedTables(): array
{
    return ['users', 'video_records', 'app_settings'];
}

function sliceInstallDetect(PDO $pdo): array
{
    $existing = $pdo->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
    $map = array_fill_keys($existing, true);
    $expected = sliceInstallExpectedTables();
    $missing = array_values(array_filter($expected, static fn($t) => !isset($map[$t])));

    $dataSummary = [];
    foreach (['users', 'video_records'] as $table) {
        if (isset($map[$table])) {
            $dataSummary[$table] = (int)$pdo->query("SELECT COUNT(*) FROM `{$table}`")->fetchColumn();
        }
    }

    $hasData = false;
    foreach ($dataSummary as $cnt) {
        if ($cnt > 0) {
            $hasData = true;
            break;
        }
    }

    return [
        'table_count' => count($existing),
        'missing_tables' => $missing,
        'missing_count' => count($missing),
        'data_summary' => $dataSummary,
        'has_structure' => count($existing) > 0,
        'has_data' => $hasData,
        'tables_sample' => array_slice($existing, 0, 10),
    ];
}

function sliceInstallDropAllTables(PDO $pdo): void
{
    $pdo->exec('SET FOREIGN_KEY_CHECKS=0');
    foreach (sliceInstallExpectedTables() as $table) {
        $pdo->exec('DROP TABLE IF EXISTS `' . str_replace('`', '``', $table) . '`');
    }
    $pdo->exec('SET FOREIGN_KEY_CHECKS=1');
}

function testMysqlConnection(array $config, bool $createDatabase = false): array
{
    $host = trim($config['host'] ?? '127.0.0.1');
    $port = (int)($config['port'] ?? 3306);
    $dbname = trim($config['dbname'] ?? '');
    $username = $config['username'] ?? '';
    $password = $config['password'] ?? '';
    $charset = $config['charset'] ?? 'utf8mb4';

    try {
        $dsn = "mysql:host={$host};port={$port};charset={$charset}";
        $pdo = new PDO($dsn, $username, $password, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        ]);

        $version = $pdo->query('SELECT VERSION()')->fetchColumn();
        $dbs = $pdo->query('SHOW DATABASES')->fetchAll(PDO::FETCH_COLUMN);
        $dbExists = $dbname !== '' && in_array($dbname, $dbs, true);

        if ($createDatabase && $dbname !== '') {
            $safeName = str_replace('`', '``', $dbname);
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$safeName}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $dbExists = true;
        }

        $report = [
            'success' => true,
            'message' => '数据库连接成功',
            'version' => (string)$version,
            'db_exists' => $dbExists,
            'table_count' => 0,
            'missing_count' => count(sliceInstallExpectedTables()),
            'missing_tables' => sliceInstallExpectedTables(),
            'data_summary' => [],
            'has_structure' => false,
            'has_data' => false,
            'tables_sample' => [],
        ];

        if ($dbExists && $dbname !== '') {
            $safeName = str_replace('`', '``', $dbname);
            $pdo->exec("USE `{$safeName}`");
            $info = sliceInstallDetect($pdo);
            $report = array_merge($report, $info);
            $msg = '数据库连接成功';
            if ($info['has_data']) {
                $parts = [];
                if (isset($info['data_summary']['users'])) {
                    $parts[] = '用户 ' . (int)$info['data_summary']['users'] . ' 条';
                }
                if (isset($info['data_summary']['video_records'])) {
                    $parts[] = '切片记录 ' . (int)$info['data_summary']['video_records'] . ' 条';
                }
                $msg .= '；已有数据：' . implode('、', $parts);
            } elseif ($info['has_structure']) {
                $msg .= '；已有 ' . (int)$info['table_count'] . ' 张表';
            } else {
                $msg .= '；数据库为空，可全新安装';
            }
            if ($info['missing_count'] > 0) {
                $msg .= '；缺失系统表 ' . (int)$info['missing_count'] . ' 张';
            }
            $report['message'] = $msg;
        } elseif ($dbname !== '') {
            $report['message'] = '连接成功，数据库「' . $dbname . '」尚不存在' . ($createDatabase ? '（安装时将自动创建）' : '');
        }

        return $report;
    } catch (PDOException $e) {
        return ['success' => false, 'message' => '连接失败: ' . $e->getMessage()];
    }
}
