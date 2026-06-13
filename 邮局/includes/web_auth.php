<?php

require_once __DIR__ . '/config.php';

function mailServerSiteBaseUrl(): string
{
    $cfg = mailServerLoadConfig();
    $url = trim((string)($cfg['site_url'] ?? ''));
    $url = rtrim($url, '/');
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    return $url;
}

function mailServerSiteApiUrl(string $script): string
{
    $base = mailServerSiteBaseUrl();
    if ($base === '') {
        return '';
    }

    if (preg_match('#/api/[a-z_]+\.php$#i', $base)) {
        return $base;
    }

    return $base . '/api/' . ltrim($script, '/');
}

/**
 * @return array{ok:bool,message?:string,data?:array}
 */
function mailServerSiteRequest(string $script, array $payload = [], string $method = 'POST'): array
{
    $url = mailServerSiteApiUrl($script);
    $apiKey = trim((string)(mailServerLoadConfig()['api_key'] ?? ''));

    if ($url === '') {
        return ['ok' => false, 'message' => '未配置关联网站地址'];
    }
    if ($apiKey === '') {
        return ['ok' => false, 'message' => '邮局 API 密钥未配置'];
    }

    $method = strtoupper($method);
    $headers = [
        'Content-Type: application/json',
        'Accept: application/json',
        'X-Mail-Api-Key: ' . $apiKey,
    ];
    $body = $method === 'GET' ? null : json_encode($payload, JSON_UNESCAPED_UNICODE);

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 8,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'message' => '无法连接网站：' . ($error ?: '网络错误')];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => '网站返回无效（HTTP ' . $status . '）'];
        }
        if (empty($data['ok'])) {
            return ['ok' => false, 'message' => $data['message'] ?? ('网站 API 错误（HTTP ' . $status . '）')];
        }

        return ['ok' => true, 'message' => $data['message'] ?? 'OK', 'data' => $data];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => 20,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return ['ok' => false, 'message' => '无法连接网站，请确认网站地址可达'];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => '网站返回无效'];
    }
    if (empty($data['ok'])) {
        return ['ok' => false, 'message' => $data['message'] ?? '网站 API 错误'];
    }

    return ['ok' => true, 'message' => $data['message'] ?? 'OK', 'data' => $data];
}

/**
 * @return array{ok:bool,message?:string,username?:string}
 */
function mailServerFetchSiteAdminInfo(): array
{
    $result = mailServerSiteRequest('mail_admin_info.php', [], 'GET');
    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => $result['message'] ?? '获取管理员信息失败'];
    }

    $data = $result['data'] ?? [];

    return [
        'ok' => true,
        'username' => (string)($data['username'] ?? 'admin'),
        'email' => (string)($data['email'] ?? ''),
    ];
}

/**
 * @return array{ok:bool,message?:string,username?:string}
 */
function mailServerVerifyAdminViaSite(string $username, string $password): array
{
    $result = mailServerSiteRequest('mail_admin_verify.php', [
        'username' => $username,
        'password' => $password,
    ]);

    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => $result['message'] ?? '登录失败'];
    }

    $data = $result['data'] ?? [];

    return [
        'ok' => true,
        'message' => $result['message'] ?? '登录成功',
        'username' => (string)($data['username'] ?? $username),
    ];
}
