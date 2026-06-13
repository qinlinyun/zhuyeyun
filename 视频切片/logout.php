<?php
session_start();

// 销毁会话（退出登录不依赖数据库）
session_unset();
session_destroy();
// 重定向到登录页面
header('Location: login.php');
exit;
?>