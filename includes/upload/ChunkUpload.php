<?php

declare(strict_types=1);

final class ChunkUpload
{
    public static function sessionsRoot(string $projectRoot): string
    {
        return rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . '.upload_sessions';
    }

    public static function createSession(
        string $projectRoot,
        int $userId,
        string $fileName,
        int $fileSize,
        int $chunkSize,
        int $totalChunks
    ): array {
        if ($userId <= 0) {
            throw new InvalidArgumentException('用户无效');
        }
        $fileName = trim($fileName);
        if ($fileName === '') {
            throw new InvalidArgumentException('文件名无效');
        }
        $extension = strtolower(pathinfo($fileName, PATHINFO_EXTENSION));
        if ($extension !== 'mp4') {
            throw new InvalidArgumentException('当前仅支持 mp4 视频');
        }
        if ($fileSize <= 0 || $totalChunks <= 0) {
            throw new InvalidArgumentException('文件大小无效');
        }

        $sessionId = bin2hex(random_bytes(16));
        $dir = self::sessionDir($projectRoot, $sessionId);
        if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
            throw new RuntimeException('无法创建上传会话目录');
        }
        if (!is_dir($dir . DIRECTORY_SEPARATOR . 'chunks') && !mkdir($dir . DIRECTORY_SEPARATOR . 'chunks', 0750, true)) {
            throw new RuntimeException('无法创建分片目录');
        }

        $meta = [
            'session_id' => $sessionId,
            'user_id' => $userId,
            'file_name' => $fileName,
            'file_size' => $fileSize,
            'chunk_size' => max(1_048_576, $chunkSize),
            'total_chunks' => $totalChunks,
            'received' => [],
            'created_at' => time(),
            'updated_at' => time(),
        ];
        self::writeMeta($dir, $meta);

        return $meta;
    }

    public static function loadSession(string $projectRoot, string $sessionId, int $userId): ?array
    {
        $sessionId = preg_replace('/[^a-f0-9]/', '', strtolower($sessionId)) ?? '';
        if ($sessionId === '') {
            return null;
        }
        $dir = self::sessionDir($projectRoot, $sessionId);
        if (!is_dir($dir)) {
            return null;
        }
        $meta = self::readMeta($dir);
        if (!is_array($meta) || (int)($meta['user_id'] ?? 0) !== $userId) {
            return null;
        }

        return $meta;
    }

    public static function saveChunk(string $projectRoot, string $sessionId, int $userId, int $chunkIndex, string $tmpPath): array
    {
        $meta = self::loadSession($projectRoot, $sessionId, $userId);
        if ($meta === null) {
            return ['ok' => false, 'error' => '上传会话不存在或已过期'];
        }

        $totalChunks = (int)($meta['total_chunks'] ?? 0);
        if ($chunkIndex < 0 || $chunkIndex >= $totalChunks) {
            return ['ok' => false, 'error' => '分片序号无效'];
        }

        $dir = self::sessionDir($projectRoot, $sessionId);
        $chunkPath = $dir . DIRECTORY_SEPARATOR . 'chunks' . DIRECTORY_SEPARATOR . $chunkIndex . '.part';
        if (!is_uploaded_file($tmpPath) && !is_file($tmpPath)) {
            return ['ok' => false, 'error' => '分片数据无效'];
        }

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

    public static function mergeToFile(string $projectRoot, string $sessionId, int $userId, string $targetAbsolutePath): array
    {
        $meta = self::loadSession($projectRoot, $sessionId, $userId);
        if ($meta === null) {
            return ['ok' => false, 'error' => '上传会话不存在或已过期'];
        }

        $totalChunks = (int)($meta['total_chunks'] ?? 0);
        $received = is_array($meta['received'] ?? null) ? $meta['received'] : [];
        if (count($received) < $totalChunks) {
            return ['ok' => false, 'error' => '分片未传齐，当前 ' . count($received) . '/' . $totalChunks];
        }

        $dir = self::sessionDir($projectRoot, $sessionId);
        $targetDir = dirname($targetAbsolutePath);
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            return ['ok' => false, 'error' => '创建目标目录失败'];
        }

        $out = fopen($targetAbsolutePath, 'wb');
        if ($out === false) {
            return ['ok' => false, 'error' => '无法创建合并文件'];
        }

        try {
            for ($i = 0; $i < $totalChunks; $i++) {
                $chunkPath = $dir . DIRECTORY_SEPARATOR . 'chunks' . DIRECTORY_SEPARATOR . $i . '.part';
                if (!is_file($chunkPath)) {
                    fclose($out);
                    @unlink($targetAbsolutePath);

                    return ['ok' => false, 'error' => '缺少分片 #' . $i];
                }
                $in = fopen($chunkPath, 'rb');
                if ($in === false) {
                    fclose($out);
                    @unlink($targetAbsolutePath);

                    return ['ok' => false, 'error' => '读取分片失败 #' . $i];
                }
                stream_copy_to_stream($in, $out);
                fclose($in);
            }
        } finally {
            fclose($out);
        }

        $size = filesize($targetAbsolutePath) ?: 0;
        $expected = (int)($meta['file_size'] ?? 0);
        if ($expected > 0 && abs($size - $expected) > 1024) {
            @unlink($targetAbsolutePath);

            return ['ok' => false, 'error' => '合并后文件大小校验失败'];
        }

        self::destroySession($projectRoot, $sessionId);

        return [
            'ok' => true,
            'size_bytes' => $size,
            'original_filename' => (string)($meta['file_name'] ?? ''),
        ];
    }

    public static function destroySession(string $projectRoot, string $sessionId): void
    {
        $dir = self::sessionDir($projectRoot, preg_replace('/[^a-f0-9]/', '', strtolower($sessionId)) ?? '');
        if (!is_dir($dir)) {
            return;
        }
        $items = glob($dir . DIRECTORY_SEPARATOR . '*') ?: [];
        foreach ($items as $item) {
            if (is_dir($item)) {
                $parts = glob($item . DIRECTORY_SEPARATOR . '*') ?: [];
                foreach ($parts as $part) {
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

    private static function sessionDir(string $projectRoot, string $sessionId): string
    {
        return self::sessionsRoot($projectRoot) . DIRECTORY_SEPARATOR . $sessionId;
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
