<?php

declare(strict_types=1);

final class BackendSupport
{
    public const CHUNK_SIZE = 5_242_880;

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

    public static function normalizeRelativePath(string $relativePath): string
    {
        $relativePath = rawurldecode($relativePath);
        $relativePath = str_replace(["\0", '\\'], ['', '/'], $relativePath);
        $relativePath = trim(preg_replace('#/+#', '/', $relativePath) ?? '', '/');
        if ($relativePath === '') {
            return '';
        }
        foreach (explode('/', $relativePath) as $segment) {
            if ($segment === '' || $segment === '.' || $segment === '..') {
                return '';
            }
        }

        return str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
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

    public static function iniBytes(string $value): int
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

    public static function uploadErrorMessage(int $code): string
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

    public static function storedFileName(string $originalName): string
    {
        $ext = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $ext = preg_replace('/[^a-z0-9]/', '', $ext) ?: 'mp4';

        return date('YmdHis') . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
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

    public static function pathWithinOpenBasedir(string $path): bool
    {
        $openBasedir = ini_get('open_basedir');
        if (!is_string($openBasedir) || $openBasedir === '') {
            return true;
        }

        $pathNorm = str_replace('\\', '/', $path);
        foreach (array_filter(array_map('trim', explode(PATH_SEPARATOR, $openBasedir))) as $base) {
            $baseNorm = rtrim(str_replace('\\', '/', $base), '/');
            if ($baseNorm === '') {
                continue;
            }
            if ($pathNorm === $baseNorm || str_starts_with($pathNorm, $baseNorm . '/')) {
                return true;
            }
        }

        return false;
    }

    public static function pathExistsSafe(string $path): bool
    {
        if ($path === '' || !self::pathWithinOpenBasedir($path)) {
            return false;
        }

        return @is_file($path);
    }

    /** 检测 FFmpeg/FFprobe 等可执行文件；避开 open_basedir 对 is_file 的限制 */
    public static function binaryAvailable(string $path): bool
    {
        if ($path === '') {
            return false;
        }

        if (PHP_OS_FAMILY === 'Windows') {
            return self::pathExistsSafe($path);
        }

        if (!function_exists('exec')) {
            return self::pathExistsSafe($path);
        }

        $cmd = escapeshellarg($path) . ' -version 2>&1';
        exec($cmd, $output, $code);

        return $code === 0;
    }
}