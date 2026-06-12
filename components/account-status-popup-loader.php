<?php
if (!function_exists('isLoggedIn') || !isLoggedIn() || (function_exists('isAdmin') && isAdmin())) {
    return;
}

require_once dirname(__DIR__) . '/includes/account_status_popup.php';

$accountStatusPopup = getAccountStatusPopupForSession();
if (!empty($accountStatusPopup)) {
    include __DIR__ . '/account-status-popup.php';
}
