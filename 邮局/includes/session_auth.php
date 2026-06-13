<?php

if (session_status() !== PHP_SESSION_ACTIVE) {
    session_start();
}

function mailServerIsLoggedIn(): bool
{
    return !empty($_SESSION['mail_server_admin']) && !empty($_SESSION['mail_server_username']);
}

function mailServerCurrentUsername(): string
{
    return (string)($_SESSION['mail_server_username'] ?? '');
}

function mailServerLogin(string $username): void
{
    $_SESSION['mail_server_admin'] = true;
    $_SESSION['mail_server_username'] = $username;
    $_SESSION['mail_server_login_at'] = time();
}

function mailServerLogout(): void
{
    unset($_SESSION['mail_server_admin'], $_SESSION['mail_server_username'], $_SESSION['mail_server_login_at']);
}

function mailServerRequireLogin(): void
{
    if (mailServerIsLoggedIn()) {
        return;
    }

    $redirect = 'login.php';
    if (!empty($_SERVER['REQUEST_URI'])) {
        $redirect .= '?redirect=' . rawurlencode($_SERVER['REQUEST_URI']);
    }

    header('Location: ' . $redirect);
    exit;
}

function mailServerRedirectAfterLogin(): void
{
    $target = trim((string)($_GET['redirect'] ?? $_POST['redirect'] ?? ''));
    if ($target !== '' && str_starts_with($target, '/') && !str_starts_with($target, '//')) {
        header('Location: ' . $target);
        exit;
    }

    header('Location: install.php');
    exit;
}
