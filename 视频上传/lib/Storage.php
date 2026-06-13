<?php

declare(strict_types=1);

final class BackendStorage
{
    public static function ensureDir(string $dir): bool
    {
        if (is_dir($dir)) {
            return true;
        }

        return @mkdir($dir, 0755, true) || is_dir($dir);
    }

    public static function absolutePath(string $relativePath): string
    {
        $relativePath = BackendSupport::normalizeRelativePath($relativePath);
        $root = BackendConfig::storageRoot();

        return $relativePath !== ''
            ? $root . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $relativePath)
            : $root;
    }

    public static function saveUploadedFile(array $file, array $meta = []): array
    {
        $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            return ['ok' => false, 'error' => BackendSupport::uploadErrorMessage($errorCode)];
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return ['ok' => false, 'error' => '上传临时文件无效'];
        }

        $originalName = (string)($file['name'] ?? 'video.mp4');
        $extension = strtolower(pathinfo($originalName, PATHINFO_EXTENSION));
        $extension = preg_replace('/[^a-z0-9]/', '', $extension);
        if ($extension === '' || !in_array($extension, BackendConfig::allowedExtensions(), true)) {
            return ['ok' => false, 'error' => '仅支持 ' . implode('、', BackendConfig::allowedExtensions())];
        }

        $size = (int)($file['size'] ?? 0);
        if ($size <= 0) {
            return ['ok' => false, 'error' => '视频文件为空'];
        }
        if ($size > BackendConfig::maxUploadBytes()) {
            return ['ok' => false, 'error' => '视频不能超过 ' . BackendSupport::formatBytes(BackendConfig::maxUploadBytes())];
        }

        $storedName = BackendSupport::storedFileName($originalName);
        $relativePath = self::resolveUploadRelativePath($meta, $storedName);
        if ($relativePath === '') {
            return ['ok' => false, 'error' => '目标保存路径无效'];
        }

        $target = self::absolutePathForUpload($relativePath);
        if (!self::ensureDir(dirname($target))) {
            return ['ok' => false, 'error' => '创建保存目录失败'];
        }
        if (!move_uploaded_file($tmpName, $target)) {
            return ['ok' => false, 'error' => '保存视频文件失败'];
        }

        return [
            'ok' => true,
            'message' => '视频已保存到远程后端',
            'backend_file_id' => $relativePath,
            'stored_filename' => $relativePath,
            'original_filename' => $originalName,
            'size_bytes' => filesize($target) ?: $size,
            'title' => (string)($meta['title'] ?? ''),
            'description' => (string)($meta['description'] ?? ''),
            'absolute_path' => $target,
        ];
    }

    /** @param array<string, mixed> $meta */
    private static function resolveUploadRelativePath(array $meta, string $fallbackStoredName): string
    {
        $target = BackendSupport::normalizeRelativePath((string)($meta['target_relative'] ?? ''));
        if ($target !== '') {
            return str_replace(DIRECTORY_SEPARATOR, '/', $target);
        }

        $userId = (int)($meta['user_id'] ?? 0);
        if ($userId > 0) {
            $userSubdir = trim((string)($meta['user_subdir'] ?? 'users'), '/\\');
            if ($userSubdir === '') {
                $userSubdir = 'users';
            }

            return str_replace(DIRECTORY_SEPARATOR, '/', $userSubdir . '/' . $userId . '/' . $fallbackStoredName);
        }

        $config = BackendConfig::get();
        $mp4Dir = BackendSupport::normalizeRelativePath((string)($config['MP4_DIR'] ?? 'mp4')) ?: 'mp4';

        return str_replace(DIRECTORY_SEPARATOR, '/', $mp4Dir . '/' . $fallbackStoredName);
    }

    public static function absolutePathForUpload(string $relativePath): string
    {
        $relativePath = BackendSupport::normalizeRelativePath($relativePath);
        $config = BackendConfig::get();
        $ftpRoot = trim(str_replace('\\', '/', (string)($config['FTP_SOURCE_ROOT'] ?? '')), '/');
        if ($ftpRoot !== '') {
            return $ftpRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relativePath);
        }

        return self::absolutePath($relativePath);
    }

    public static function findVideo(string $relativePath): ?array
    {
        return self::resolveVideoSource([], $relativePath);
    }

    public static function findVideoInStorage(string $relativePath): ?array
    {
        $relativePath = BackendSupport::normalizeRelativePath($relativePath);
        if ($relativePath === '') {
            return null;
        }

        $config = BackendConfig::get();
        $candidates = [$relativePath];
        $mp4Dir = trim((string)($config['MP4_DIR'] ?? 'mp4'), " \t\n\r\0\x0B/\\");
        if ($mp4Dir !== '' && !str_starts_with($relativePath, $mp4Dir . '/')) {
            $candidates[] = $mp4Dir . '/' . $relativePath;
        }

        foreach (array_unique($candidates) as $candidate) {
            $path = self::absolutePathForUpload($candidate);
            if (is_file($path)) {
                return [
                    'relative_path' => str_replace(DIRECTORY_SEPARATOR, '/', $candidate),
                    'path' => $path,
                    'external' => false,
                ];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $upload */
    public static function resolveVideoSource(array $upload, string $relativePath): ?array
    {
        $relativePath = BackendSupport::normalizeRelativePath($relativePath);
        if ($relativePath === '') {
            return null;
        }

        $sourceMeta = is_array($upload['source'] ?? null) ? $upload['source'] : [];
        $storageType = (string)($upload['storage_type'] ?? '');
        $sourceType = (string)($sourceMeta['type'] ?? '');
        if ($sourceType === 'php' || $storageType === 'php') {
            $phpRelative = BackendSupport::normalizeRelativePath((string)($sourceMeta['relative_path'] ?? $relativePath));
            if ($phpRelative !== '') {
                $direct = self::findVideoInStorage($phpRelative);
                if ($direct !== null) {
                    return $direct;
                }
            }
        }
        if ($sourceType === 'ftp' || $storageType === 'ftp') {
            $ftpSource = self::resolveFtpVideoSource($sourceMeta, $relativePath);
            if ($ftpSource !== null) {
                return $ftpSource;
            }
        }

        $config = BackendConfig::get();
        $candidates = [$relativePath];
        $mp4Dir = trim((string)($config['MP4_DIR'] ?? 'mp4'), " \t\n\r\0\x0B/\\");
        if ($mp4Dir !== '') {
            $candidates[] = $mp4Dir . DIRECTORY_SEPARATOR . $relativePath;
        }

        foreach (array_unique($candidates) as $candidate) {
            $candidate = BackendSupport::normalizeRelativePath($candidate);
            if ($candidate === '') {
                continue;
            }
            $path = self::absolutePath($candidate);
            if (is_file($path)) {
                return [
                    'relative_path' => str_replace(DIRECTORY_SEPARATOR, '/', $candidate),
                    'path' => $path,
                    'external' => false,
                ];
            }
        }

        return null;
    }

    /** @param array<string, mixed> $source */
    private static function resolveFtpVideoSource(array $source, string $relativePath): ?array
    {
        $relative = BackendSupport::normalizeRelativePath((string)($source['relative_path'] ?? $relativePath));
        if ($relative === '') {
            return null;
        }

        $explicitPath = trim(str_replace('\\', '/', (string)($source['file_path'] ?? '')));
        if ($explicitPath !== '' && is_file($explicitPath)) {
            return self::ftpSourceResult($relative, $explicitPath);
        }

        $root = trim(str_replace('\\', '/', (string)($source['filesystem_root'] ?? '')), '/');
        if ($root === '') {
            $config = BackendConfig::get();
            $root = trim(str_replace('\\', '/', (string)($config['FTP_SOURCE_ROOT'] ?? '')), '/');
        }
        if ($root === '') {
            return null;
        }

        $basePath = trim(str_replace('\\', '/', (string)($source['ftp_base_path'] ?? '')), '/');
        $candidates = [];
        if ($basePath !== '') {
            $candidates[] = $root . '/' . $basePath . '/' . $relative;
        }
        $candidates[] = $root . '/' . $relative;

        foreach ($candidates as $candidate) {
            $path = str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $candidate);
            if (is_file($path)) {
                return self::ftpSourceResult($relative, $path);
            }
        }

        return null;
    }

    /** @return array{relative_path: string, path: string, external: true} */
    private static function ftpSourceResult(string $relative, string $path): array
    {
        return [
            'relative_path' => str_replace(DIRECTORY_SEPARATOR, '/', $relative),
            'path' => $path,
            'external' => true,
        ];
    }

    /** @param array<string, mixed> $upload */
    public static function deleteFile(string $relativePath, array $upload = []): bool
    {
        $source = self::resolveVideoSource($upload, $relativePath);

        return $source !== null && @unlink($source['path']);
    }

    public static function deletePathTree(string $path): bool
    {
        if (is_file($path)) {
            return @unlink($path);
        }
        if (!is_dir($path)) {
            return false;
        }
        foreach (glob(rtrim($path, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . '*') ?: [] as $item) {
            self::deletePathTree($item);
        }

        return @rmdir($path);
    }
}
