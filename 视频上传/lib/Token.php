<?php

declare(strict_types=1);

final class BackendToken
{
    public static function validateApiToken(string $token): bool
    {
        $expected = (string)(BackendConfig::get()['API_TOKEN'] ?? '');

        return $expected !== '' && hash_equals($expected, $token);
    }

    public static function parseUserUploadToken(string $token): ?array
    {
        $secret = (string)(BackendConfig::get()['API_TOKEN'] ?? '');
        if ($token === '' || $secret === '' || !str_contains($token, '.')) {
            return null;
        }
        [$encoded, $sign] = explode('.', $token, 2);
        if ($encoded === '' || $sign === '') {
            return null;
        }
        $expected = hash_hmac('sha256', $encoded, $secret);
        if (!hash_equals($expected, $sign)) {
            return null;
        }
        $value = strtr($encoded, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $payload = json_decode((string)base64_decode($value, true), true);
        if (!is_array($payload) || (string)($payload['scope'] ?? '') !== 'upload_video') {
            return null;
        }
        $uid = (int)($payload['uid'] ?? 0);
        $exp = (int)($payload['exp'] ?? 0);
        $nonce = trim((string)($payload['nonce'] ?? ''));
        if ($uid <= 0 || $exp <= time() || $nonce === '' || strlen($nonce) < 8) {
            return null;
        }

        return [
            'uid' => $uid,
            'exp' => $exp,
            'nonce' => $nonce,
            'sid' => trim((string)($payload['sid'] ?? '')),
        ];
    }
}
