<?php

declare(strict_types=1);

final class BackendReview
{
    public static function approve(array $upload, string $relativePath): array
    {
        $source = BackendStorage::resolveVideoSource($upload, $relativePath);
        if ($source === null) {
            $hint = self::missingSourceHint($upload);

            return ['ok' => false, 'error' => '未找到待转码的 mp4 文件' . $hint];
        }

        $config = BackendConfig::get();
        $recordDirectory = BackendTranscode::buildRecordDirectory($upload, $source['relative_path']);
        $m3u8Dir = BackendSupport::normalizeRelativePath((string)($config['M3U8_DIR'] ?? 'm3u8')) ?: 'm3u8';
        $outputRelativeDir = $m3u8Dir . DIRECTORY_SEPARATOR . $recordDirectory;
        $outputDir = BackendStorage::absolutePath($outputRelativeDir);

        $duration = BackendTranscode::getDuration($source['path']);
        $screenshotTime = $duration !== null ? min(5.0, $duration) : 5.0;
        $slice = BackendTranscode::sliceToHls($source['path'], $outputDir, $screenshotTime);
        if (empty($slice['ok'])) {
            BackendStorage::deletePathTree($outputDir);

            return ['ok' => false, 'error' => (string)($slice['error'] ?? '切片失败')];
        }

        $syncRelatives = BackendTranscode::buildSyncMediaRelatives($m3u8Dir, $recordDirectory);
        $m3u8Url = BackendTranscode::buildSyncMediaPath($syncRelatives['m3u8_relative']);
        $coverUrl = is_file((string)$slice['screenshot_path'])
            ? BackendTranscode::buildSyncCoverUrl($syncRelatives['cover_relative'])
            : '';

        $recordId = 'upload_' . (string)($upload['id'] ?? uniqid('', true));
        $title = trim((string)($upload['title'] ?? ''));
        if ($title === '') {
            $title = pathinfo((string)($upload['original_filename'] ?? basename($source['relative_path'])), PATHINFO_FILENAME);
        }

        $sync = BackendSync::pushToMainSite([
            'record_id' => $recordId,
            'title' => $title,
            'm3u8_url' => $m3u8Url,
            'cover_url' => $coverUrl,
            'description' => (string)($upload['description'] ?? ''),
            'uploader' => (string)($upload['uploader'] ?? $upload['username'] ?? ''),
            'user_id' => (int)($upload['user_id'] ?? 0),
        ]);
        if (empty($sync['ok'])) {
            BackendStorage::deletePathTree($outputDir);

            return $sync;
        }

        if (empty($source['external'])) {
            @unlink($source['path']);
        }

        return [
            'ok' => true,
            'message' => '审核通过，已转为 m3u8 并同步主站',
            'record_id' => $recordId,
            'm3u8_url' => $m3u8Url,
            'cover_url' => $coverUrl,
            'video_id' => $sync['video_id'] ?? 0,
            'episode_id' => $sync['episode_id'] ?? 0,
        ];
    }

    public static function reject(string $relativePath): array
    {
        $deleted = BackendStorage::deleteFile($relativePath);

        return [
            'ok' => true,
            'message' => $deleted ? '审核失败，已删除视频文件' : '审核失败，未找到可删除文件',
            'deleted_original' => $deleted,
        ];
    }

    public static function saveOriginal(string $relativePath, array $upload = []): array
    {
        $source = BackendStorage::resolveVideoSource($upload, $relativePath);
        if ($source === null) {
            return ['ok' => false, 'error' => '未找到可保存的原始文件'];
        }

        $config = BackendConfig::get();
        $originalsDir = BackendSupport::normalizeRelativePath((string)($config['ORIGINALS_DIR'] ?? 'originals')) ?: 'originals';
        $targetDir = BackendStorage::absolutePath($originalsDir);
        if (!BackendStorage::ensureDir($targetDir)) {
            return ['ok' => false, 'error' => '创建原始文件目录失败'];
        }

        $targetName = date('YmdHis') . '_' . bin2hex(random_bytes(4)) . '_' . basename($source['relative_path']);
        $target = $targetDir . DIRECTORY_SEPARATOR . $targetName;
        if (!@copy($source['path'], $target)) {
            return ['ok' => false, 'error' => '复制原始文件失败'];
        }

        $records = self::readOriginalIndex();
        $record = [
            'id' => bin2hex(random_bytes(12)),
            'upload_id' => isset($upload['id']) ? (int)$upload['id'] : 0,
            'title' => (string)($upload['title'] ?? ''),
            'original_filename' => (string)($upload['original_filename'] ?? basename($source['relative_path'])),
            'uploader' => (string)($upload['uploader'] ?? $upload['username'] ?? ''),
            'size_bytes' => filesize($target) ?: 0,
            'uploaded_at' => (string)($upload['created_at'] ?? date('Y-m-d H:i:s')),
            'saved_at' => date('Y-m-d H:i:s'),
            'source_relative' => $source['relative_path'],
            'saved_relative' => str_replace(DIRECTORY_SEPARATOR, '/', $originalsDir . '/' . $targetName),
        ];
        array_unshift($records, $record);
        if (!self::writeOriginalIndex($records)) {
            @unlink($target);

            return ['ok' => false, 'error' => '写入索引失败'];
        }

        return ['ok' => true, 'record' => $record];
    }

    public static function deletePublishedMedia(array $mediaPaths): int
    {
        $deleted = 0;
        $seen = [];
        foreach ($mediaPaths as $mediaPath) {
            foreach (self::mediaCandidates((string)$mediaPath) as $relative) {
                $fullPath = BackendStorage::absolutePath($relative);
                $target = basename($relative) === 'index.m3u8' ? dirname($fullPath) : $fullPath;
                $key = strtolower(str_replace('\\', '/', $target));
                if (isset($seen[$key])) {
                    continue;
                }
                if ((is_file($target) || is_dir($target)) && BackendStorage::deletePathTree($target)) {
                    $seen[$key] = true;
                    $deleted++;
                }
            }
        }

        return $deleted;
    }

    /** @return list<array<string, mixed>> */
    public static function readOriginalIndex(): array
    {
        $config = BackendConfig::get();
        $dir = BackendSupport::normalizeRelativePath((string)($config['ORIGINALS_DIR'] ?? 'originals')) ?: 'originals';
        $path = BackendStorage::absolutePath($dir . '/originals.json');
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string)file_get_contents($path), true);

        return is_array($data) ? array_values(array_filter($data, 'is_array')) : [];
    }

    public static function deleteOriginalRecord(string $id): bool
    {
        $records = self::readOriginalIndex();
        $kept = [];
        $deleted = false;
        foreach ($records as $record) {
            if ((string)($record['id'] ?? '') !== $id) {
                $kept[] = $record;
                continue;
            }
            $relative = BackendSupport::normalizeRelativePath((string)($record['saved_relative'] ?? ''));
            if ($relative !== '') {
                $path = BackendStorage::absolutePath($relative);
                if (is_file($path)) {
                    @unlink($path);
                }
            }
            $deleted = true;
        }
        if ($deleted) {
            self::writeOriginalIndex($kept);
        }

        return $deleted;
    }

    /** @param array<string, mixed> $upload */
    private static function missingSourceHint(array $upload): string
    {
        $source = is_array($upload['source'] ?? null) ? $upload['source'] : [];
        $filePath = trim((string)($source['file_path'] ?? ''));
        if ($filePath !== '') {
            return '（已告知路径：' . $filePath . '，请确认转码服务器上该文件存在且 PHP 有读权限）';
        }
        $root = trim((string)($source['filesystem_root'] ?? ''));
        if ($root === '') {
            return '（请确认远程后端 mp4 目录存在该文件，路径格式 users/用户ID/文件名.mp4）';
        }

        return '（根目录：' . $root . '）';
    }

    /** @return list<string> */
    private static function mediaCandidates(string $mediaPath): array
    {
        $path = trim(str_replace('\\', '/', $mediaPath));
        if ($path === '') {
            return [];
        }
        if (preg_match('#^https?://#i', $path)) {
            $parts = parse_url($path);
            $path = (string)($parts['path'] ?? '');
        }
        if ($path !== '' && $path[0] !== '/' && preg_match('#^[^/]+\.[^/]+(/.+)$#', $path, $matches)) {
            $path = $matches[1];
        }

        $config = BackendConfig::get();
        $m3u8Dir = trim(str_replace('\\', '/', (string)($config['M3U8_DIR'] ?? 'm3u8')), '/');
        $raw = ltrim($path, '/');
        $candidates = [$raw];
        if ($m3u8Dir !== '') {
            if (!str_starts_with($raw, $m3u8Dir . '/')) {
                $candidates[] = $m3u8Dir . '/' . $raw;
            }
            if (str_starts_with($raw, $m3u8Dir . '/')) {
                $candidates[] = substr($raw, strlen($m3u8Dir) + 1);
            }
        }
        if (str_starts_with(strtolower($raw), 'storage/')) {
            $candidates[] = substr($raw, strlen('storage/'));
        }

        $normalized = [];
        foreach ($candidates as $candidate) {
            $relative = BackendSupport::normalizeRelativePath($candidate);
            if ($relative !== '') {
                $normalized[] = str_replace(DIRECTORY_SEPARATOR, '/', $relative);
            }
        }

        return array_values(array_unique($normalized));
    }

    private static function writeOriginalIndex(array $records): bool
    {
        $config = BackendConfig::get();
        $dir = BackendSupport::normalizeRelativePath((string)($config['ORIGINALS_DIR'] ?? 'originals')) ?: 'originals';
        $path = BackendStorage::absolutePath($dir . '/originals.json');
        BackendStorage::ensureDir(dirname($path));

        return file_put_contents($path, json_encode(array_values($records), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT), LOCK_EX) !== false;
    }
}
