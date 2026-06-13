<?php
require_once __DIR__ . '/common.php';

header('Location: ' . (uploadBackendIsLoggedIn() ? 'dashboard.php' : 'login.php'));
exit;
