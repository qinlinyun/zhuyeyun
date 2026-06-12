<?php

function flashSet(string $type, string $message): void
{
    $_SESSION['_flash'] = ['type' => $type, 'message' => $message];
}

function flashGet(): ?array
{
    if (empty($_SESSION['_flash'])) {
        return null;
    }
    $flash = $_SESSION['_flash'];
    unset($_SESSION['_flash']);
    return $flash;
}

function finishPostRequest(?string $message = null, ?string $error = null, string $url = ''): never
{
    if ($message) {
        flashSet('success', $message);
    }
    if ($error) {
        flashSet('error', $error);
    }
    if ($url === '') {
        $url = basename($_SERVER['PHP_SELF']);
        $query = $_SERVER['QUERY_STRING'] ?? '';
        if ($query !== '') {
            $url .= '?' . $query;
        }
    }
    header('Location: ' . $url);
    exit;
}

function applyFlash(?string &$message, ?string &$error): void
{
    $flash = flashGet();
    if (!$flash) {
        return;
    }
    if ($flash['type'] === 'success') {
        $message = $flash['message'];
    } else {
        $error = $flash['message'];
    }
}
