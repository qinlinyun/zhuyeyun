<?php
session_start();

require_once __DIR__ . '/includes/bootstrap.php';

$config = require __DIR__ . '/includes/config.php';
requireDatabaseInstalled();

// 检查用户是否已登录
if (isset($_SESSION['logged_in']) && $_SESSION['logged_in'] === true) {
    header('Location: ' . $config['index_page']);
    exit;
}

$login_error = '';

// 处理登录请求
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = trim($_POST['password'] ?? '');
    
    if (empty($username) || empty($password)) {
        $login_error = '请输入用户名和密码';
    } else {
        $user = authenticateUser($username, $password);
        if (!$user) {
            $login_error = '用户名或密码不正确';
        } else {
            $_SESSION['logged_in'] = true;
            $_SESSION['user_id'] = $user['id'];
            $_SESSION['username'] = $user['username'];
            $_SESSION['last_activity'] = time();

            header('Location: ' . $config['index_page']);
            exit;
        }
    }
}
?>

<!DOCTYPE html>
<html lang="zh-CN">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>视频切片工具 - 登录</title>
    <link rel="stylesheet" href="./css/layout.css?v=1">
    <!-- 引入Font Awesome -->
    <link href="https://cdn.bootcdn.net/ajax/libs/font-awesome/4.7.0/css/font-awesome.min.css" rel="stylesheet">
    
    <style>
        @layer utilities {
            .content-auto {
                content-visibility: auto;
            }
            .transition-custom {
                transition: all 0.3s ease;
            }
            .shadow-custom {
                box-shadow: 0 4px 20px rgba(0, 0, 0, 0.08);
            }
            .login-container {
                min-height: calc(100vh - 64px);
            }
        }
    </style>
</head>
<body class="bg-gray-50 text-gray-800 font-sans">
    <!-- 顶部导航 -->
    <header class="bg-dark text-white shadow-md">
        <div class="container mx-auto px-4 py-3">
            <nav class="flex justify-between items-center">
                <div class="text-xl font-bold">
                    <i class="fa fa-video-camera mr-2"></i>视频切片工具
                </div>
                <div class="text-sm">请登录后使用系统</div>
            </nav>
        </div>
    </header>
    
    <!-- 主内容区 - 登录表单 -->
    <main class="login-container flex items-center justify-center py-8">
        <section class="w-full max-w-md bg-white rounded-xl shadow-custom p-8">
            <div class="text-center mb-8">
                <h2 class="text-2xl font-bold text-dark mb-2">用户登录</h2>
                <p class="text-gray-500">请输入您的账号信息登录系统</p>
            </div>
            
            <?php if ($login_error): ?>
                <div class="bg-red-50 border-l-4 border-red-400 text-red-700 p-4 mb-6">
                    <p><?php echo htmlspecialchars($login_error); ?></p>
                </div>
            <?php endif; ?>
            
            <form action="login.php" method="post" class="space-y-6">
                <div>
                    <label for="username" class="block text-sm font-medium text-gray-700 mb-1">用户名</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fa fa-user text-gray-400"></i>
                        </div>
                        <input type="text" name="username" id="username" 
                            class="pl-10 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary transition-custom"
                            placeholder="请输入用户名" required>
                    </div>
                </div>
                
                <div>
                    <label for="password" class="block text-sm font-medium text-gray-700 mb-1">密码</label>
                    <div class="relative">
                        <div class="absolute inset-y-0 left-0 pl-3 flex items-center pointer-events-none">
                            <i class="fa fa-lock text-gray-400"></i>
                        </div>
                        <input type="password" name="password" id="password" 
                            class="pl-10 block w-full px-3 py-2 border border-gray-300 rounded-md shadow-sm focus:outline-none focus:ring-primary focus:border-primary transition-custom"
                            placeholder="请输入密码" required>
                    </div>
                </div>
                
                <div class="flex items-center justify-between">
                    <div class="flex items-center">
                        <input id="remember-me" name="remember-me" type="checkbox" class="h-4 w-4 text-primary focus:ring-primary border-gray-300 rounded">
                        <label for="remember-me" class="ml-2 block text-sm text-gray-700">
                            记住我
                        </label>
                    </div>
                </div>
                
                <div>
                    <button type="submit" class="w-full flex justify-center py-2 px-4 border border-transparent shadow-sm text-sm font-medium rounded-md text-white bg-primary hover:bg-primary/90 focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-primary transition-custom">
                        <i class="fa fa-sign-in mr-2"></i>登录
                    </button>
                </div>
            </form>
            
            <div class="mt-6 pt-6 border-t border-gray-200 text-center">
                <p class="text-sm text-gray-500">
                    系统仅对授权用户开放<br>
                    如有登录问题，请联系管理员
                </p>
            </div>
        </section>
    </main>
    
    <!-- 页脚 -->
    <footer class="bg-dark text-white py-4">
        <div class="container mx-auto px-4 text-center text-sm">
            <p>© 2023 视频切片工具 | 版权所有</p>
        </div>
    </footer>

    <script>
        document.addEventListener('DOMContentLoaded', function() {
            // 自动聚焦到用户名输入框
            document.getElementById('username').focus();
            
            // 简单的表单验证
            const form = document.querySelector('form');
            form.addEventListener('submit', function(e) {
                const username = document.getElementById('username').value.trim();
                const password = document.getElementById('password').value.trim();
                
                if (!username) {
                    alert('请输入用户名');
                    e.preventDefault();
                    return;
                }
                
                if (!password) {
                    alert('请输入密码');
                    e.preventDefault();
                    return;
                }
            });
        });
    </script>
</body>
</html>