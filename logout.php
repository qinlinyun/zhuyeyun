<?php
session_start();
session_destroy();
// 判断当前是否在admin目录
$basePath = (strpos($_SERVER['PHP_SELF'], '/admin/') !== false) ? '../' : '';
header('Location: ' . $basePath . 'login.php');
exit;
?>

