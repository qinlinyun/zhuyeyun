<?php

require_once __DIR__ . '/config.php';

function mailServerRequireApiKey(): void
{
    if (!mailServerIsInstalled()) {
        mailServerJsonError('邮局尚未安装，请先访问 install.php 完成配置', 503);
    }

    $provided = mailServerExtractApiKey();
    $expected = mailServerLoadConfig()['api_key'] ?? '';

    if ($provided === '' || !hash_equals($expected, $provided)) {
        mailServerJsonError('API 密钥无效', 401);
    }
}

function mailServerExtractApiKey(): string
{
    $header = $_SERVER['HTTP_X_MAIL_API_KEY'] ?? $_SERVER['HTTP_X_API_KEY'] ?? '';
    if ($header !== '') {
        return trim($header);
    }

    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
    if (preg_match('/^Bearer\s+(\S+)/i', $auth, $m)) {
        return trim($m[1]);
    }

    return trim((string)($_GET['api_key'] ?? $_POST['api_key'] ?? ''));
}

function mailServerJsonError(string $message, int $status = 400): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(['ok' => false, 'message' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function mailServerJsonResponse(array $payload, int $status = 200): never
{
    http_response_code($status);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    exit;
}

function mailServerReadJsonInput(): array
{
    $raw = file_get_contents('php://input');
    if ($raw !== false && $raw !== '') {
        $json = json_decode($raw, true);
        if (is_array($json)) {
            return $json;
        }
    }

    return $_POST;
}
