<?php

declare(strict_types=1);

final class BackendChunkUpload
{
    /** 单片大小：25 MiB */
    public const CHUNK_SIZE_BYTES = 26_214_400;

    /** 超过此大小自动走分片上传 */
    public const CHUNK_THRESHOLD_BYTES = 26_214_400;

    public static function sessionsRoot(): string
    {
        return BackendConfig::storageRoot() . DIRECTORY_SEPARATOR . '.upload_sessions';
    }

    public static function shouldUseChunkedUpload(int $fileSize): bool
    {
        return $fileSize > self::CHUNK_THRESHOLD_BYTES;
    }

    public static function resolveChunkSize(int $requested = 0): int
    {
        if ($requested > 0) {
            return max(self::CHUNK_SIZE_BYTES, $requested);
        }

        return self::CHUNK_SIZE_BYTES;
    }

    public static function createSession(
        int $userId,
        string $fileName,
        int $fileSize,
        int $chunkSize,
        int $totalChunks,
        string $targetRelative = ''
    ): array {
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension !== 'mp4') {
            throw new InvalidArgumentException('当前仅支持 mp4 视频');
        }
        if ($fileSize <= 0 || $totalChunks <= 0) {
            throw new InvalidArgumentException('文件大小无效');
        }

        $sessionId = bin2hex(random_bytes(16));
        $dir = self::sessionDir($sessionId);
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            throw new RuntimeException('无法创建上传会话目录');
        }
        if (!is_dir($dir . DIRECTORY_SEPARATOR . 'chunks') && !mkdir($dir . DIRECTORY_SEPARATOR . 'chunks', 0750, true)) {
            throw new RuntimeException('无法创建分片目录');
        }

        $targetRelative = BackendSupport::normalizeRelativePath($targetRelative);
        $meta = [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'chunk_size' => self::resolveChunkSize($chunkSize),
            'total_chunks' => $totalChunks,
            'target_relative' => $targetRelative,
            'received' => [],
            'created_at' => time(),
            'updated_at' => time(),
        ];
        self::writeMeta($dir, $meta);

        return $meta;
    }

    public static function loadSession(string $sessionId, int $userId): ?array
    {
        $sessionId = preg_replace('/[^a-f0-9]/', '', strtolower($sessionId)) ?? '';
        if ($sessionId === '') {
            return null;
        }
        $dir = self::sessionDir($sessionId);
        if (!is_dir($dir)) {
            return null;
        }
        $meta = self::readMeta($dir);
        if (!is_array($meta) || (int)($meta['user_id'] ?? 0) !== $userId) {
            return null;
        }

        return $meta;
    }

    public static function saveChunk(string $sessionId, int $userId, int $chunkIndex, string $tmpPath): array
    {
        $meta = self::loadSession($sessionId, $userId);
        if ($meta === null) {
            return ['ok' => false, 'error' => '上传会话不存在或已过期'];
        }

        $totalChunks = (int)($meta['total_chunks'] ?? 0);
        if ($chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            return ['ok' => false, 'error' => '分片序号无效'];
        }

        $dir = self::sessionDir($sessionId);
        $chunkPath = $dir . DIRECTORY_SEPARATOR . 'chunks' . DIRECTORY_SEPARATOR . $chunkIndex . '.part';

        if (is_uploaded_file($tmpPath)) {
            if (!move_uploaded_file($tmpPath, $chunkPath)) {
                return ['ok' => false, 'error' => '保存分片失败'];
            }
        } elseif (!rename($tmpPath, $chunkPath)) {
            if (!copy($tmpPath, $chunkPath)) {
                return ['ok' => false, 'error' => '保存分片失败'];
            }
            @unlink($tmpPath);
        }

        $received = is_array($meta['received'] ?? null) ? $meta['received'] : [];
        $received[(string)$chunkIndex] = filesize($chunkPath) ?: 0;
        $meta['received'] = $received;
        $meta['updated_at'] = time();
        self::writeMeta($dir, $meta);

        return [
            'ok' => true,
            'chunk_index' => $chunkIndex,
            'received_chunks' => count($received),
            'total_chunks' => $totalChunks,
            'complete' => count($received) >= $totalChunks,
        ];
    }

    public static function mergeToMp4(string $sessionId, int $userId): array
    {
        $meta = self::loadSession($sessionId, $userId);
        if ($meta === null) {
            return ['ok' => false, 'error' => '上传会话不存在或已过期'];
        }

        $totalChunks = (int)($meta['total_chunks'] ?? 0);
        $received = is_array($meta['received'] ?? null) ? $meta['received'] : [];
        if (count($received) < $totalChunks) {
            return ['ok' => false, 'error' => '分片未传齐'];
        }

        $targetRelative = BackendSupport::normalizeRelativePath((string)($meta['target_relative'] ?? ''));
        if ($targetRelative === '') {
            $config = BackendConfig::get();
            $mp4Dir = BackendSupport::normalizeRelativePath((string)($config['MP4_DIR'] ?? 'mp4')) ?: 'mp4';
            $storedName = BackendSupport::storedFileName((string)($meta['file_name'] ?? 'video.mp4'));
            $targetRelative = str_replace(DIRECTORY_SEPARATOR, '/', $mp4Dir . '/' . $storedName);
        }
        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $targetRelative);
        $target = BackendStorage::absolutePathForUpload($relativePath);
        $targetDir = dirname($target);
        if (!BackendStorage::ensureDir($targetDir)) {
            return ['ok' => false, 'error' => '创建视频目录失败'];
        }

        $dir = self::sessionDir($sessionId);
        $out = fopen($target, 'wb');
        if ($out === false) {
            return ['ok' => false, 'error' => '无法创建合并文件'];
        }

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $dir . DIRECTORY_SEPARATOR . 'chunks' . DIRECTORY_SEPARATOR . $i . '.part';
                if (!is_file($chunkPath)) {
                    fclose($out);
                    @unlink($target);

                    return ['ok' => false, 'error' => '缺少分片 #' . $i];
                }
                $in = fopen($chunkPath, 'rb');
                if ($in === false) {
                    fclose($out);
                    @unlink($target);

                    return ['ok' => false, 'error' => '读取分片失败'];
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        $size = filesize($target) ?: 0;
        self::destroySession($sessionId);

        return [
            'ok' => true,
            'message' => '视频文件已保存',
            'backend_file_id' => $relativePath,
            'stored_filename' => $relativePath,
            'original_filename' => (string)($meta['file_name'] ?? ''),
            'size_bytes' => $size,
            'chunked' => true,
        ];
    }

    public static function destroySession(string $sessionId): void
    {
        $sessionId = preg_replace('/[^a-f0-9]/', '', strtolower($sessionId)) ?? '';
        $dir = self::sessionDir($sessionId);
        if (!is_dir($dir)) {
            return;
        }
        $items = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
        foreach ($items as $item) {
            if (is_dir($item)) {
                foreach (glob($item . DIRECTORY_SEPARATOR . '*') ?: [] as $part) {
                    if (is_file($part)) {
                        @unlink($part);
                    }
                }
                @rmdir($item);
            } elseif (is_file($item)) {
                @unlink($item);
            }
        }
        @rmdir($dir);
    }

    private static function sessionDir(string $sessionId): string
    {
        return self::sessionsRoot() . DIRECTORY_SEPARATOR . $sessionId;
    }

    private static function readMeta(string $dir): ?array
    {
        $path = $dir . DIRECTORY_SEPARATOR . 'meta.json';
        if (!is_file($path)) {
            return null;
        }
        $data = json_decode((string)file_get_contents($path), true);

        return is_array($data) ? $data : null;
    }

    private static function writeMeta(string $dir, array $meta): void
    {
        file_put_contents(
            $dir . DIRECTORY_SEPARATOR . 'meta.json',
            json_encode($meta, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }
}
