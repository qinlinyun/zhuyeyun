<?php
session_start();

require_once __DIR__ . '/includes/db.php';

$alreadyInstalled = isDatabaseInstalled();
$step = 1;
$errors = [];
$successMessage = '';
$testResult = null;

if ($alreadyInstalled && $_SERVER['REQUEST_METHOD'] !== 'POST') {
    $successMessage = '系统已安装完成，可直接登录使用。';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? 'install';

    if ($action === 'test_connection') {
        $testResult = testMysqlConnection([
            'host' => $_POST['db_host'] ?? '127.0.0.1',
            'port' => $_POST['db_port'] ?? 3306,
            'dbname' => $_POST['db_name'] ?? '',
            'username' => $_POST['db_user'] ?? '',
            'password' => $_POST['db_pass'] ?? '',
        ], !empty($_POST['create_database']));
    } elseif ($action === 'install') {
        $installMode = $_POST['install_mode'] ?? 'fresh';
        $dbHost = trim($_POST['db_host'] ?? '127.0.0.1');
        $dbPort = (int)($_POST['db_port'] ?? 3306);
        $dbName = trim($_POST['db_name'] ?? '');
        $dbUser = trim($_POST['db_user'] ?? '');
        $dbPass = $_POST['db_pass'] ?? '';
        $createDb = isset($_POST['create_database']);

        $adminUser = trim($_POST['admin_username'] ?? '');
        $adminPass = $_POST['admin_password'] ?? '';
        $adminPassConfirm = $_POST['admin_password_confirm'] ?? '';

        if ($dbName === '') {
            $errors[] = '请填写数据库名称';
        }
        if ($dbUser === '') {
            $errors[] = '请填写数据库用户名';
        }
        if ($adminUser === '') {
            $errors[] = '请填写管理员用户名';
        } elseif (!preg_match('/^[a-zA-Z0-9_]{3,32}$/', $adminUser)) {
            $errors[] = '管理员用户名仅支持 3-32 位字母、数字或下划线';
        }
        if (strlen($adminPass) < 6) {
            $errors[] = '管理员密码至少 6 位';
        }
        if ($adminPass !== $adminPassConfirm) {
            $errors[] = '两次输入的管理员密码不一致';
        }

        if (empty($errors)) {
            $connTest = testMysqlConnection([
                'host' => $dbHost,
                'port' => $dbPort,
                'dbname' => $dbName,
                'username' => $dbUser,
                'password' => $dbPass,
            ], $createDb);

            if (!$connTest['success']) {
                $errors[] = $connTest['message'];
            } elseif (!empty($connTest['has_structure']) && !in_array($installMode, ['fresh', 'migrate'], true)) {
                $errors[] = '检测到数据库已有表，请选择「覆盖安装」或「补充安装」。建议先测试连接。';
            } else {
                $dbConfig = [
                    'host' => $dbHost,
                    'port' => $dbPort,
                    'dbname' => $dbName,
                    'username' => $dbUser,
                    'password' => $dbPass,
                    'charset' => 'utf8mb4',
                ];

                if (!saveDatabaseConfig($dbConfig)) {
                    $errors[] = '无法写入配置文件，请检查 config 目录是否可写';
                } else {
                    $pdo = getDb();
                    if (!$pdo) {
                        $errors[] = '保存配置后无法连接数据库，请检查配置';
                        @unlink(getDatabaseConfigPath());
                    } else {
                        try {
                            if ($installMode === 'fresh' && !empty($connTest['has_structure'])) {
                                sliceInstallDropAllTables($pdo);
                            }

                            createDatabaseTables($pdo);

                            $stmt = $pdo->query('SELECT COUNT(*) AS cnt FROM users');
                            $userCount = (int)$stmt->fetch()['cnt'];
                            if ($userCount === 0) {
                                if (!createAdminUser($pdo, $adminUser, $adminPass)) {
                                    throw new RuntimeException('创建管理员账号失败');
                                }
                            }

                            if ($installMode === 'migrate') {
                                $jsonPath = __DIR__ . '/records.json';
                                $migrated = migrateRecordsFromJson($jsonPath, $pdo);
                                if ($migrated > 0 && file_exists($jsonPath)) {
                                    @rename($jsonPath, $jsonPath . '.bak.' . date('YmdHis'));
                                }
                                $successMessage = '补充安装完成：已保留原有数据，并补齐缺失的表。';
                            } elseif ($installMode === 'fresh' && !empty($connTest['has_structure'])) {
                                $successMessage = '覆盖安装完成！数据库已清空并重建。';
                            } else {
                                $successMessage = '安装成功！';
                            }

                            $successMessage .= ' 请使用管理员账号登录。';
                            $alreadyInstalled = true;
                            $step = 3;
                        } catch (Throwable $e) {
                            $errors[] = '安装失败: ' . $e->getMessage();
                            @unlink(getDatabaseConfigPath());
                        }
                    }
                }
            }
        }
    }
}

$formDefaults = [
    'db_host' => $_POST['db_host'] ?? '127.0.0.1',
    'db_port' => $_POST['db_port'] ?? '3306',
    'db_name' => $_POST['db_name'] ?? 'video_slice',
    'db_user' => $_POST['db_user'] ?? 'root',
    'admin_username' => $_POST['admin_username'] ?? 'admin',
];
$testOk = $testResult && !empty($testResult['success']);
$needsInstallChoice = $testOk && (
    !empty($testResult['has_structure']) || !empty($testResult['has_data'])
);
?>
<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>视频切片工具 - MySQL 安装引导</title>
    <link rel="stylesheet" href="./css/layout.css?v=1">
    <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet">
</head>
<body class="bg-gray-50 text-gray-800 font-sans min-h-screen">
    <header class="bg-dark text-white shadow-md">
        <div class="container mx-auto px-4 py-3">
            <div class="text-xl font-bold">
                <i class="fa fa-database mr-2"></i>MySQL 数据库安装引导
            </div>
        </div>
    </header>

    <main class="container mx-auto px-4 py-8 max-w-3xl">
        <?php if ($successMessage && $alreadyInstalled): ?>
            <div class="bg-green-50 border-l-4 border-green-400 text-green-800 p-4 mb-6 rounded-r">
                <p class="font-bold mb-1"><i class="fa fa-check-circle mr-1"></i><?php echo htmlspecialchars($successMessage); ?></p>
                <a href="login.php" class="inline-flex mt-3 items-center text-primary hover:underline">
                    <i class="fa fa-sign-in mr-1"></i>前往登录
                </a>
            </div>
        <?php endif; ?>

        <?php if (!empty($errors)): ?>
            <div class="bg-red-50 border-l-4 border-red-400 text-red-700 p-4 mb-6 rounded-r">
                <p class="font-bold mb-2">安装遇到问题</p>
                <ul class="list-disc list-inside text-sm space-y-1">
                    <?php foreach ($errors as $error): ?>
                        <li><?php echo htmlspecialchars($error); ?></li>
                    <?php endforeach; ?>
                </ul>
            </div>
        <?php endif; ?>

        <?php if ($testResult): ?>
            <div class="mb-6 p-4 rounded-lg border <?php echo $testResult['success'] ? 'bg-green-50 border-green-200 text-green-800' : 'bg-red-50 border-red-200 text-red-800'; ?>">
                <p class="font-medium"><i class="fa <?php echo $testResult['success'] ? 'fa-check' : 'fa-times'; ?> mr-1"></i><?php echo htmlspecialchars($testResult['message']); ?></p>
                <?php if (!empty($testResult['success']) && !empty($testResult['version'])): ?>
                    <p class="mt-1 text-xs">MySQL 版本：<?php echo htmlspecialchars((string)$testResult['version']); ?></p>
                <?php endif; ?>
                <?php if (!empty($testResult['missing_tables']) && (int)($testResult['missing_count'] ?? 0) > 0): ?>
                    <p class="mt-1 text-xs">缺失表：<?php echo htmlspecialchars(implode('、', $testResult['missing_tables'])); ?></p>
                <?php endif; ?>
            </div>
        <?php endif; ?>

        <section class="bg-white rounded-xl shadow-lg p-6 mb-6">
            <h2 class="text-lg font-bold text-dark mb-4"><i class="fa fa-info-circle text-primary mr-2"></i>安装前准备</h2>
            <ol class="list-decimal list-inside space-y-2 text-sm text-gray-600">
                <li>确保服务器已安装 <strong>PHP 7.4+</strong> 且启用 <strong>PDO MySQL</strong> 扩展</li>
                <li>确保已安装并启动 <strong>MySQL 5.7+</strong> 或 <strong>MariaDB 10.3+</strong></li>
                <li>准备一个具有建库权限的数据库账号（或使用 root）</li>
                <li>确保 <code class="bg-gray-100 px-1 rounded">config/</code> 目录对 Web 服务器可写</li>
            </ol>
        </section>

        <?php if (!$alreadyInstalled || $_SERVER['REQUEST_METHOD'] === 'POST'): ?>
        <form method="post" class="space-y-6">
            <section class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-lg font-bold text-dark mb-4 border-b pb-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-primary text-white text-sm mr-2">1</span>
                    数据库连接
                </h2>
                <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">主机地址</label>
                        <input type="text" name="db_host" value="<?php echo htmlspecialchars($formDefaults['db_host']); ?>"
                            class="w-full px-3 py-2 border rounded-md focus:ring-primary focus:border-primary" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">端口</label>
                        <input type="number" name="db_port" value="<?php echo htmlspecialchars($formDefaults['db_port']); ?>"
                            class="w-full px-3 py-2 border rounded-md focus:ring-primary focus:border-primary" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">数据库名</label>
                        <input type="text" name="db_name" value="<?php echo htmlspecialchars($formDefaults['db_name']); ?>"
                            class="w-full px-3 py-2 border rounded-md focus:ring-primary focus:border-primary" required>
                    </div>
                    <div>
                        <label class="block text-sm font-medium mb-1">数据库用户名</label>
                        <input type="text" name="db_user" value="<?php echo htmlspecialchars($formDefaults['db_user']); ?>"
                            class="w-full px-3 py-2 border rounded-md focus:ring-primary focus:border-primary" required>
                    </div>
                    <div class="md:col-span-2">
                        <label class="block text-sm font-medium mb-1">数据库密码</label>
                        <input type="password" name="db_pass" placeholder="无密码可留空"
                            class="w-full px-3 py-2 border rounded-md focus:ring-primary focus:border-primary">
                    </div>
                </div>
                <label class="flex items-center mt-4 text-sm">
                    <input type="checkbox" name="create_database" value="1" class="mr-2 h-4 w-4 text-primary" checked>
                    若数据库不存在则自动创建
                </label>
                <button type="submit" name="action" value="test_connection"
                    class="mt-4 inline-flex items-center px-4 py-2 bg-gray-100 border rounded-md hover:bg-gray-200 text-sm">
                    <i class="fa fa-plug mr-2"></i>测试连接
                </button>
            </section>

            <section class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-lg font-bold text-dark mb-4 border-b pb-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-primary text-white text-sm mr-2">2</span>
                    管理员账号
                </h2>
                <p class="text-sm text-gray-500 mb-4">管理员用于登录后台，密码将加密存储在 MySQL 中。</p>
                <div class="space-y-4">
                    <div>
                        <label class="block text-sm font-medium mb-1">管理员用户名</label>
                        <input type="text" name="admin_username" value="<?php echo htmlspecialchars($formDefaults['admin_username']); ?>"
                            class="w-full px-3 py-2 border rounded-md focus:ring-primary focus:border-primary" required>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div>
                            <label class="block text-sm font-medium mb-1">管理员密码</label>
                            <input type="password" name="admin_password" minlength="6"
                                class="w-full px-3 py-2 border rounded-md focus:ring-primary focus:border-primary" required>
                        </div>
                        <div>
                            <label class="block text-sm font-medium mb-1">确认密码</label>
                            <input type="password" name="admin_password_confirm" minlength="6"
                                class="w-full px-3 py-2 border rounded-md focus:ring-primary focus:border-primary" required>
                        </div>
                    </div>
                </div>
            </section>

            <section class="bg-white rounded-xl shadow-lg p-6">
                <h2 class="text-lg font-bold text-dark mb-4 border-b pb-2">
                    <span class="inline-flex items-center justify-center w-7 h-7 rounded-full bg-primary text-white text-sm mr-2">3</span>
                    选择安装方式
                </h2>
                <?php if (!$testOk): ?>
                    <p class="text-sm text-amber-700 mb-4">请先完成上方「测试连接」，再根据检测结果选择安装方式。</p>
                <?php elseif (!$needsInstallChoice): ?>
                    <p class="text-sm text-gray-600 mb-4">数据库为空，可直接全新安装。</p>
                    <input type="hidden" name="install_mode" value="fresh">
                    <button type="submit" name="action" value="install"
                        class="w-full inline-flex justify-center items-center px-6 py-3 bg-primary text-white rounded-md hover:bg-primary/90 font-medium">
                        <i class="fa fa-magic mr-2"></i>开始全新安装
                    </button>
                <?php else: ?>
                    <p class="text-sm text-gray-600 mb-4">检测到数据库已有内容，请选择：</p>
                    <input type="hidden" name="install_mode" id="install_mode" value="">
                    <div class="space-y-3">
                        <button type="submit" name="action" value="install"
                            onclick="document.getElementById('install_mode').value='migrate'"
                            class="w-full text-left rounded-lg border-2 border-green-500 bg-green-50 p-4 hover:bg-green-100">
                            <div class="font-semibold text-green-800">补充安装（推荐）</div>
                            <div class="mt-1 text-xs text-green-700">不删除现有数据，仅补齐缺失的表（users、video_records、app_settings）。</div>
                        </button>
                        <button type="submit" name="action" value="install"
                            onclick="if(!confirm('覆盖安装将删除所有系统表及数据，确认继续？')){event.preventDefault();return false;} document.getElementById('install_mode').value='fresh'"
                            class="w-full text-left rounded-lg border-2 border-red-300 bg-white p-4 hover:border-red-500 hover:bg-red-50">
                            <div class="font-semibold text-red-700">覆盖安装</div>
                            <div class="mt-1 text-xs text-red-600">清除系统表内所有数据并重新安装。</div>
                        </button>
                    </div>
                <?php endif; ?>
            </section>
        </form>
        <?php endif; ?>

        <section class="mt-6 bg-blue-50 border border-blue-100 rounded-lg p-4 text-sm text-blue-900">
            <p class="font-medium mb-2"><i class="fa fa-shield mr-1"></i>安全提示</p>
            <ul class="list-disc list-inside space-y-1 text-blue-800">
                <li>安装完成后请妥善保管 <code class="bg-white/60 px-1 rounded">config/database.php</code></li>
                <li>未配置数据库时，访问登录页和主页会自动跳转到本页面</li>
            </ul>
        </section>
    </main>
</body>
</html>
