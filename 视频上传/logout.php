<?php
require_once __DIR__ . '/common.php';
unset($_SESSION['upload_backend_admin']);
unset($_SESSION['upload_backend_csrf']);
session_destroy();
header('Location: login.php');
exit;
