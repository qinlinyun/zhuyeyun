<?php

require_once __DIR__ . '/mail_config.php';

function mailApiNormalizeBaseUrl(string $url): string
{
    $url = trim($url);
    $url = rtrim($url, '/');
    if ($url !== '' && !preg_match('#^https?://#i', $url)) {
        $url = 'https://' . $url;
    }

    return $url;
}

function mailApiEndpointUrl(array $cfg, string $script): string
{
    $base = mailApiNormalizeBaseUrl((string)($cfg['api_url'] ?? ''));
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
function mailApiRequest(array $cfg, string $script, array $payload = [], string $method = 'POST'): array
{
    $url = mailApiEndpointUrl($cfg, $script);
    $apiKey = trim((string)($cfg['api_key'] ?? ''));

    if ($url === '') {
        return ['ok' => false, 'message' => '未配置邮局 API 地址'];
    }
    if ($apiKey === '') {
        return ['ok' => false, 'message' => '未配置邮局 API 密钥'];
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
            CURLOPT_TIMEOUT => 45,
            CURLOPT_CONNECTTIMEOUT => 10,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $response = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'message' => 'API 请求失败：' . ($error ?: (string)$errno)];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'message' => 'API 返回无效（HTTP ' . $status . '）'];
        }
        if (empty($data['ok'])) {
            return ['ok' => false, 'message' => $data['message'] ?? ('API 错误（HTTP ' . $status . '）')];
        }

        return ['ok' => true, 'message' => $data['message'] ?? 'OK', 'data' => $data];
    }

    $context = stream_context_create([
        'http' => [
            'method' => $method,
            'header' => implode("\r\n", $headers),
            'content' => $body ?? '',
            'timeout' => 45,
            'ignore_errors' => true,
        ],
    ]);

    $response = @file_get_contents($url, false, $context);
    if ($response === false) {
        return ['ok' => false, 'message' => 'API 请求失败，请确认邮局 API 地址可达'];
    }

    $data = json_decode($response, true);
    if (!is_array($data)) {
        return ['ok' => false, 'message' => 'API 返回无效'];
    }
    if (empty($data['ok'])) {
        return ['ok' => false, 'message' => $data['message'] ?? 'API 错误'];
    }

    return ['ok' => true, 'message' => $data['message'] ?? 'OK', 'data' => $data];
}

/**
 * @return array{ok:bool,message?:string}
 */
function sendSiteMailViaApi(array $cfg, string $to, string $subject, string $body, bool $isHtml = false): array
{
    $result = mailApiRequest($cfg, 'send.php', [
        'to' => $to,
        'subject' => $subject,
        'body' => $body,
        'is_html' => $isHtml,
    ]);

    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => $result['message'] ?? '邮局 API 发送失败'];
    }

    return ['ok' => true, 'message' => $result['message'] ?? '邮件已发送'];
}

/**
 * @return array{ok:bool,message?:string}
 */
function pingSiteMailApi(array $cfg): array
{
    $result = mailApiRequest($cfg, 'ping.php', [], 'GET');

    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => $result['message'] ?? 'API 连接失败'];
    }

    return ['ok' => true, 'message' => $result['message'] ?? 'API 连接正常'];
}

/**
 * @return array{ok:bool,message?:string}
 */
function sendSiteMailTestViaApi(array $cfg, string $to): array
{
    $result = mailApiRequest($cfg, 'test.php', ['to' => $to]);

    if (empty($result['ok'])) {
        return ['ok' => false, 'message' => $result['message'] ?? '测试发送失败'];
    }

    return ['ok' => true, 'message' => $result['message'] ?? '测试邮件已发送'];
}

function isMailApiMode(array $cfg): bool
{
    return ($cfg['send_mode'] ?? 'smtp') === 'api';
}

function isMailApiConfigured(array $cfg): bool
{
    return mailApiNormalizeBaseUrl((string)($cfg['api_url'] ?? '')) !== ''
        && trim((string)($cfg['api_key'] ?? '')) !== '';
}
