<?php

declare(strict_types=1);

final class UploadSupport
{
    public const DEFAULT_CHUNK_SIZE = 5_242_880; // 5 MiB

    public static function json(array $payload, int $status = 200): void
    {
        while (ob_get_level() > 0) {
            $buffer = ob_get_status();
            if (empty($buffer['del'])) {
                break;
            }
            ob_end_clean();
        }
        http_response_code($status);
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    public static function base64UrlEncode(string $value): string
    {
        return rtrim(strtr(base64_encode($value), '+/', '-_'), '=');
    }

    public static function base64UrlDecode(string $value): string
    {
        $value = strtr($value, '-_', '+/');
        $padding = strlen($value) % 4;
        if ($padding > 0) {
            $value .= str_repeat('=', 4 - $padding);
        }
        $decoded = base64_decode($value, true);

        return is_string($decoded) ? $decoded : '';
    }

    public static function normalizeRelativePath(string $relative): string
    {
        $relative = trim(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relative), DIRECTORY_SEPARATOR);
        if ($relative === '' || str_contains($relative, '..')) {
            return '';
        }

        return $relative;
    }

    public static function normalizeRemoteBackendUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }

        $url = rtrim(str_replace('\\', '/', $url), '/');
        $url = preg_replace('#/api/(upload_video|upload/init|upload/chunk|upload/finish|review_action)\.php$#i', '', $url) ?? $url;
        $url = preg_replace('#/api/v1/(init|chunk|finish)\.php$#i', '', $url) ?? $url;
        $url = preg_replace('#/(login|dashboard|config_guide|upload|originals|logout|index)\.php$#i', '', $url) ?? $url;

        return rtrim($url, '/');
    }

    public static function normalizeBaseUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . ltrim($url, '/');
        }

        return self::normalizeRemoteBackendUrl($url);
    }

    public static function bytesFromIni(string $value): int
    {
        $value = trim($value);
        if ($value === '') {
            return 0;
        }

        $unit = strtolower($value[strlen($value) - 1]);
        $bytes = (float)$value;
        if ($unit === 'g') {
            $bytes *= 1024 ** 3;
        } elseif ($unit === 'm') {
            $bytes *= 1024 ** 2;
        } elseif ($unit === 'k') {
            $bytes *= 1024;
        }

        return (int)$bytes;
    }

    public static function formatBytes(int $bytes): string
    {
        if ($bytes <= 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $index = min((int)floor(log($bytes, 1024)), count($units) - 1);

        return round($bytes / (1024 ** $index), 2) . ' ' . $units[$index];
    }

    public static function fileErrorMessage(int $code): string
    {
        return match ($code) {
            UPLOAD_ERR_INI_SIZE, UPLOAD_ERR_FORM_SIZE => '视频文件超过服务器允许大小',
            UPLOAD_ERR_PARTIAL => '分片传输不完整，请重试',
            UPLOAD_ERR_NO_FILE => '请选择要上传的视频文件',
            UPLOAD_ERR_NO_TMP_DIR => '服务器缺少临时上传目录',
            UPLOAD_ERR_CANT_WRITE => '服务器写入上传文件失败',
            UPLOAD_ERR_EXTENSION => '服务器扩展阻止了文件上传',
            default => '视频上传失败',
        };
    }

    public static function responseSnippet(string $response): string
    {
        $snippet = trim(strip_tags($response));
        $snippet = preg_replace('/\s+/', ' ', (string)$snippet);
        if (function_exists('mb_substr')) {
            return mb_substr($snippet, 0, 180, 'UTF-8');
        }

        return substr($snippet, 0, 180);
    }

    public static function normalizeMediaPath(string $path): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            $parts = parse_url($path);
            $path = (string)($parts['path'] ?? '');
        }
        if ($path !== '' && $path[0] !== '/' && preg_match('#^[^/]+\.[^/]+(/.+)$#', $path, $matches)) {
            $path = $matches[1];
        }

        return '/' . ltrim($path, '/');
    }

    public static function coverImageDomain(PDO $pdo): string
    {
        require_once __DIR__ . '/Config.php';
        $api = UploadConfig::getApi($pdo);
        $image = trim((string)($api['image_domain'] ?? ''));
        if ($image !== '') {
            return $image;
        }

        return trim((string)($api['video_domain'] ?? ''));
    }

    public static function normalizeCoverUrl(string $path, string $imageDomain = ''): string
    {
        $path = trim(str_replace('\\', '/', $path));
        if ($path === '') {
            return '';
        }
        if (preg_match('#^https?://#i', $path)) {
            return $path;
        }

        $relative = ltrim($path, '/');
        $imageDomain = preg_replace('#^https?://#i', '', trim($imageDomain));
        $imageDomain = rtrim($imageDomain, '/');
        if ($imageDomain === '') {
            return $relative;
        }

        return 'https://' . $imageDomain . '/' . $relative;
    }

    public static function resolveCoverUrl(PDO $pdo, string $path): string
    {
        return self::normalizeCoverUrl($path, self::coverImageDomain($pdo));
    }

    public static function storedFileName(string $originalName): string
    {
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension) ?: 'mp4';

        return date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $extension;
    }

    /** 每个视频独立目录名（10 位小写字母与数字） */
    public static function generateVideoFolderId(): string
    {
        $alphabet = 'abcdefghijklmnopqrstuvwxyz0123456789';
        $id = '';
        for ($i = 0; $i < 10; $i++) {
            $id .= $alphabet[random_int(0, strlen($alphabet) - 1)];
        }

        return $id;
    }
}
