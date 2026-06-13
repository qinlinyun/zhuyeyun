<?php

function mailServerDataDir(): string
{
    return __DIR__ . '/../data';
}

function mailServerConfigPath(): string
{
    return mailServerDataDir() . '/config.json';
}

function mailServerDefaultConfig(): array
{
    return [
        'installed' => false,
        'api_key' => '',
        'site_url' => '',
        'smtp' => [
            'host' => '127.0.0.1',
            'port' => 587,
            'encryption' => 'tls',
            'username' => '',
            'password' => '',
            'from_email' => '',
            'from_name' => '竹叶云控邮局',
        ],
    ];
}

function mailServerIsInstalled(): bool
{
    if (!is_file(mailServerConfigPath())) {
        return false;
    }

    $cfg = mailServerLoadConfig();

    return !empty($cfg['installed']) && ($cfg['api_key'] ?? '') !== '';
}

function mailServerLoadConfig(): array
{
    $path = mailServerConfigPath();
    if (!is_file($path)) {
        return mailServerDefaultConfig();
    }

    $data = json_decode((string)file_get_contents($path), true);

    return is_array($data) ? mailServerNormalizeConfig($data) : mailServerDefaultConfig();
}

function mailServerNormalizeConfig(array $data): array
{
    $defaults = mailServerDefaultConfig();
    $smtpIn = is_array($data['smtp'] ?? null) ? $data['smtp'] : [];

    $encryption = strtolower(trim((string)($smtpIn['encryption'] ?? $defaults['smtp']['encryption'])));
    if (!in_array($encryption, ['none', 'ssl', 'tls'], true)) {
        $encryption = 'tls';
    }

    $port = (int)($smtpIn['port'] ?? $defaults['smtp']['port']);
    if ($port <= 0 || $port > 65535) {
        $port = $encryption === 'ssl' ? 465 : 587;
    }

    return [
        'installed' => !empty($data['installed']),
        'api_key' => trim((string)($data['api_key'] ?? '')),
        'site_url' => trim((string)($data['site_url'] ?? '')),
        'smtp' => [
            'host' => trim((string)($smtpIn['host'] ?? $defaults['smtp']['host'])),
            'port' => $port,
            'encryption' => $encryption,
            'username' => trim((string)($smtpIn['username'] ?? '')),
            'password' => (string)($smtpIn['password'] ?? ''),
            'from_email' => trim((string)($smtpIn['from_email'] ?? '')),
            'from_name' => trim((string)($smtpIn['from_name'] ?? $defaults['smtp']['from_name'])) ?: $defaults['smtp']['from_name'],
        ],
    ];
}

function mailServerSaveConfig(array $config): void
{
    $dir = mailServerDataDir();
    if (!is_dir($dir)) {
        mkdir($dir, 0755, true);
    }

    $normalized = mailServerNormalizeConfig($config);
    file_put_contents(
        mailServerConfigPath(),
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)
    );
}

function mailServerGenerateApiKey(): string
{
    return bin2hex(random_bytes(24));
}

function mailServerSmtpReady(array $config): bool
{
    $smtp = $config['smtp'] ?? [];

    return ($smtp['host'] ?? '') !== ''
        && ($smtp['username'] ?? '') !== ''
        && ($smtp['password'] ?? '') !== ''
        && ($smtp['from_email'] ?? '') !== ''
        && filter_var($smtp['from_email'], FILTER_VALIDATE_EMAIL);
}

function mailServerSmtpConfigForMailer(array $config): array
{
    $smtp = $config['smtp'] ?? [];

    return [
        'enabled' => true,
        'host' => $smtp['host'] ?? '',
        'port' => (int)($smtp['port'] ?? 587),
        'encryption' => $smtp['encryption'] ?? 'tls',
        'username' => $smtp['username'] ?? '',
        'password' => $smtp['password'] ?? '',
        'from_email' => $smtp['from_email'] ?? '',
        'from_name' => $smtp['from_name'] ?? '竹叶云控邮局',
    ];
}

function mailServerPublicEndpoints(): array
{
    $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
    $host = $_SERVER['HTTP_HOST'] ?? 'localhost';
    $scriptDir = str_replace('\\', '/', dirname($_SERVER['SCRIPT_NAME'] ?? ''));
    $base = rtrim($scheme . '://' . $host . $scriptDir, '/');
    if (substr($base, -8) === '/install') {
        $base = dirname($base);
    }

    return [
        'base' => $base,
        'ping' => $base . '/api/ping.php',
        'send' => $base . '/api/send.php',
        'test' => $base . '/api/test.php',
    ];
}
