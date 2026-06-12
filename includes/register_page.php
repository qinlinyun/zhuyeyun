<?php

function defaultRegisterPageConfig(): array
{
    return [
        'username_restrict_enabled' => true,
        'username_min_length' => 5,
        'password_strong_enabled' => false,
    ];
}

function normalizeRegisterPageConfig(array $data): array
{
    $defaults = defaultRegisterPageConfig();

    return [
        'username_restrict_enabled' => !empty($data['username_restrict_enabled']),
        'username_min_length' => max(5, (int)($data['username_min_length'] ?? $defaults['username_min_length'])),
        'password_strong_enabled' => !empty($data['password_strong_enabled']),
    ];
}

function getRegisterPageConfig(PDO $pdo): array
{
    $raw = getSetting($pdo, 'register_page_config', '');
    if ($raw === '') {
        return defaultRegisterPageConfig();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return defaultRegisterPageConfig();
    }

    return normalizeRegisterPageConfig($data);
}

function saveRegisterPageConfig(PDO $pdo, array $config): void
{
    $normalized = normalizeRegisterPageConfig($config);
    setSetting(
        $pdo,
        'register_page_config',
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function parseRegisterPageConfigFromPost(array $post): array
{
    return normalizeRegisterPageConfig([
        'username_restrict_enabled' => isset($post['username_restrict_enabled']),
        'username_min_length' => $post['username_min_length'] ?? 5,
        'password_strong_enabled' => isset($post['password_strong_enabled']),
    ]);
}

function validateRegisterUsername(string $username, array $config): ?string
{
    if (empty($config['username_restrict_enabled'])) {
        return null;
    }

    $minLength = max(5, (int)($config['username_min_length'] ?? 5));
    if (strlen($username) < $minLength) {
        return '用户名至少 ' . $minLength . ' 个字符';
    }

    if (!preg_match('/^[A-Za-z0-9]+$/', $username)) {
        return '用户名仅支持大小写字母和数字';
    }

    return null;
}

function validateRegisterPassword(string $password, array $config): ?string
{
    if (empty($config['password_strong_enabled'])) {
        if (strlen($password) < 6) {
            return '密码至少 6 位';
        }
        return null;
    }

    if (strlen($password) < 8) {
        return '密码至少 8 位';
    }

    $categories = 0;
    if (preg_match('/[A-Z]/', $password)) {
        $categories++;
    }
    if (preg_match('/[a-z]/', $password)) {
        $categories++;
    }
    if (preg_match('/[0-9]/', $password)) {
        $categories++;
    }
    if (preg_match('/[^A-Za-z0-9]/', $password)) {
        $categories++;
    }

    if ($categories < 3) {
        return '密码须包含大写字母、小写字母、数字、特殊符号中的至少三项';
    }

    return null;
}

function registerPageUsernameHint(array $config): ?string
{
    if (empty($config['username_restrict_enabled'])) {
        return null;
    }

    $minLength = max(5, (int)($config['username_min_length'] ?? 5));
    return '仅支持大小写字母和数字，至少 ' . $minLength . ' 个字符';
}

function registerPagePasswordHint(array $config): ?string
{
    if (empty($config['password_strong_enabled'])) {
        return null;
    }

    return '至少 8 位，须包含大写字母、小写字母、数字、特殊符号中的至少三项';
}

/** @param array<string, string> $registerFieldErrors */
function registerFieldErrorClass(string $field, array $registerFieldErrors): string
{
    return isset($registerFieldErrors[$field]) && $registerFieldErrors[$field] !== ''
        ? 'border-red-500 dark:border-red-500'
        : 'border-gray-300 dark:border-gray-600';
}

/** @param array<string, string> $registerFieldErrors */
function registerFieldErrorHtml(string $field, array $registerFieldErrors): string
{
    $fieldEsc = htmlspecialchars($field, ENT_QUOTES, 'UTF-8');
    if (empty($registerFieldErrors[$field])) {
        return '<p id="registerError-' . $fieldEsc . '" class="mt-1 hidden text-xs text-red-500 dark:text-red-400" data-field-error="' . $fieldEsc . '"></p>';
    }

    return '<p id="registerError-' . $fieldEsc . '" class="mt-1 text-xs text-red-500 dark:text-red-400" data-field-error="' . $fieldEsc . '">'
        . htmlspecialchars($registerFieldErrors[$field], ENT_QUOTES, 'UTF-8')
        . '</p>';
}
