<?php
require_once __DIR__ . '/includes/session_auth.php';

mailServerLogout();
header('Location: login.php');
exit;
