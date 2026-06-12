<?php

declare(strict_types=1);

final class UploadTokenService
{
    public static function buildSigned(array $payload, string $secret): array
    {
        if ($secret === '') {
            throw new InvalidArgumentException('密钥不能为空');
        }
        $payloadJson = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($payloadJson === false) {
            throw new RuntimeException('生成令牌失败');
        }
        $encoded = UploadSupport::base64UrlEncode($payloadJson);
        $sign = hash_hmac('sha256', $encoded, $secret);

        return ['token' => $encoded . '.' . $sign, 'payload' => $payload];
    }

    public static function parseSigned(string $token, string $secret): ?array
    {
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
        $payload = json_decode(UploadSupport::base64UrlDecode($encoded), true);

        return is_array($payload) ? $payload : null;
    }

    public static function buildUserToken(int $userId, string $secret, int $ttlSeconds = 3600, ?string $sessionId = null): array
    {
        if ($userId <= 0 || $secret === '') {
            throw new InvalidArgumentException('上传令牌参数无效');
        }
        $exp = time() + max(300, $ttlSeconds);
        $nonce = bin2hex(random_bytes(12));
        $payload = [
            'scope' => 'upload_video',
            'uid' => $userId,
            'exp' => $exp,
            'nonce' => $nonce,
        ];
        if ($sessionId !== null && $sessionId !== '') {
            $payload['sid'] = $sessionId;
        }
        $built = self::buildSigned($payload, $secret);

        return [
            'token' => $built['token'],
            'exp' => $exp,
            'nonce' => $nonce,
            'session_id' => $sessionId,
        ];
    }

    public static function parseUserToken(string $token, string $secret): ?array
    {
        $payload = self::parseSigned($token, $secret);
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

    public static function buildInstallerToken(int $adminId, string $secret, int $ttlSeconds = 900): array
    {
        $exp = time() + max(120, $ttlSeconds);
        $nonce = bin2hex(random_bytes(12));
        $built = self::buildSigned([
            'scope' => 'upload_backend_install',
            'aid' => $adminId,
            'exp' => $exp,
            'nonce' => $nonce,
        ], $secret);

        return ['token' => $built['token'], 'exp' => $exp, 'nonce' => $nonce];
    }

    public static function ensureNonceTable(PDO $pdo): void
    {
        $pdo->exec('
            CREATE TABLE IF NOT EXISTS upload_token_nonces (
                nonce VARCHAR(64) NOT NULL PRIMARY KEY,
                user_id INT UNSIGNED NOT NULL,
                exp INT UNSIGNED NOT NULL,
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                KEY idx_upload_nonce_exp (exp),
                KEY idx_upload_nonce_user (user_id)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4
        ');
    }

    public static function rememberNonce(PDO $pdo, int $userId, string $nonce, int $exp): void
    {
        if ($userId <= 0 || $nonce === '') {
            return;
        }
        self::ensureNonceTable($pdo);
        $now = time();
        $pdo->prepare('DELETE FROM upload_token_nonces WHERE exp <= ?')->execute([$now]);
        $stmt = $pdo->prepare('
            INSERT INTO upload_token_nonces (nonce, user_id, exp)
            VALUES (?, ?, ?)
            ON DUPLICATE KEY UPDATE user_id = VALUES(user_id), exp = VALUES(exp)
        ');
        $stmt->execute([$nonce, $userId, $exp]);
    }

    public static function consumeNonce(PDO $pdo, int $userId, string $nonce): bool
    {
        if ($userId <= 0 || $nonce === '') {
            return false;
        }
        self::ensureNonceTable($pdo);
        $stmt = $pdo->prepare('
            DELETE FROM upload_token_nonces
            WHERE nonce = ? AND user_id = ? AND exp > ?
        ');
        $stmt->execute([$nonce, $userId, time()]);

        return $stmt->rowCount() > 0;
    }
}
