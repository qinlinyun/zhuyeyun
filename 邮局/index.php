<?php
require_once __DIR__ . '/includes/bootstrap.php';

if (mailServerIsInstalled()) {
    require_once __DIR__ . '/includes/session_auth.php';
    if (mailServerIsLoggedIn()) {
        header('Location: install.php');
    } else {
        header('Location: login.php');
    }
    exit;
}

header('Location: install.php');
exit;
