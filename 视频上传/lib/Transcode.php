<?php

declare(strict_types=1);

final class BackendTranscode
{
    private static function isBinaryAvailable(string $path): bool
    {
        if (method_exists(BackendSupport::class, 'binaryAvailable')) {
            return BackendSupport::binaryAvailable($path);
        }

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

    private static function pathExistsSafe(string $path): bool
    {
        if (method_exists(BackendSupport::class, 'pathExistsSafe')) {
            return BackendSupport::pathExistsSafe($path);
        }

        return $path !== '' && @is_file($path);
    }

    public static function getDuration(string $file): ?float
    {
        $ffprobe = (string)(BackendConfig::get()['FFPROBE_PATH'] ?? '/usr/bin/ffprobe');
        if ($ffprobe === '' || !self::isBinaryAvailable($ffprobe)) {
            return null;
        }
        $cmd = escapeshellarg($ffprobe)
            . ' -v error -show_entries format=duration -of default=noprint_wrappers=1:nokey=1 '
            . escapeshellarg($file);
        exec($cmd, $output, $code);
        if ($code !== 0 || empty($output[0]) || !is_numeric($output[0])) {
            return null;
        }

        return (float)$output[0];
    }

    public static function sliceToHls(string $inputFile, string $outputDir, ?float $screenshotTime = null): array
    {
        $ffmpeg = (string)(BackendConfig::get()['FFMPEG_PATH'] ?? '/usr/bin/ffmpeg');
        if ($ffmpeg === '' || !self::isBinaryAvailable($ffmpeg)) {
            return ['ok' => false, 'error' => 'FFmpeg 不存在，请检查 FFMPEG_PATH'];
        }
        if (!self::pathExistsSafe($inputFile)) {
            return ['ok' => false, 'error' => '待切片视频不存在'];
        }
        if (!BackendStorage::ensureDir($outputDir)) {
            return ['ok' => false, 'error' => '创建切片目录失败'];
        }

        $m3u8Path = $outputDir . DIRECTORY_SEPARATOR . 'index.m3u8';
        $cmd = escapeshellarg($ffmpeg)
            . ' -y -i ' . escapeshellarg($inputFile)
            . ' -c:v copy -c:a copy -bsf:v h264_mp4toannexb'
            . ' -hls_time 10 -hls_list_size 0 -hls_flags independent_segments -start_number 0 '
            . escapeshellarg($m3u8Path)
            . ' 2>&1';
        exec($cmd, $output, $code);
        if ($code !== 0) {
            return ['ok' => false, 'error' => implode("\n", $output)];
        }
        if (!self::pathExistsSafe($m3u8Path)) {
            return ['ok' => false, 'error' => '未生成 index.m3u8'];
        }

        $screenshotPath = $outputDir . DIRECTORY_SEPARATOR . 'screenshot.jpg';
        if ($screenshotTime !== null) {
            $ssCmd = escapeshellarg($ffmpeg)
                . ' -y -i ' . escapeshellarg($inputFile)
                . ' -ss ' . escapeshellarg((string)$screenshotTime)
                . ' -vframes 1 -q:v 2 '
                . escapeshellarg($screenshotPath)
                . ' 2>&1';
            exec($ssCmd, $ssOutput, $ssCode);
            if ($ssCode !== 0) {
                return ['ok' => false, 'error' => '生成封面失败: ' . implode("\n", $ssOutput)];
            }
        }

        return [
            'ok' => true,
            'm3u8_path' => $m3u8Path,
            'screenshot_path' => $screenshotPath,
        ];
    }

    public static function sanitizeSegment(string $value, string $fallback): string
    {
        $value = strtolower(trim($value));
        $value = preg_replace('/[^a-z0-9_-]+/', '-', $value);
        $value = trim((string)$value, '-_');

        return $value !== '' ? $value : $fallback;
    }

    public static function buildRecordDirectory(array $upload, string $sourcePath): string
    {
        $userId = (int)($upload['user_id'] ?? 0);
        $folder = self::resolveVideoFolderFromUpload($upload, $sourcePath);
        if ($userId > 0) {
            return $userId . '/' . $folder;
        }

        $id = (string)($upload['id'] ?? '');
        if ($id !== '') {
            return self::sanitizeSegment('upload-' . $id, 'upload-' . date('YmdHis')) . '/' . $folder;
        }

        return self::sanitizeSegment(pathinfo($sourcePath, PATHINFO_FILENAME) . '-' . date('YmdHis'), 'upload-' . date('YmdHis'))
            . '/' . $folder;
    }

    /**
     * 从 stored_filename 解析视频目录（users/12/abc1234567/file.mp4 → abc1234567）
     */
    public static function resolveVideoFolderFromUpload(array $upload, string $sourcePath): string
    {
        $relative = trim(str_replace('\\', '/', (string)($upload['stored_filename'] ?? $sourcePath)), '/');
        if ($relative === '') {
            return BackendSupport::generateVideoFolderId();
        }

        $parts = array_values(array_filter(explode('/', $relative), static fn (string $p): bool => $p !== ''));
        if (count($parts) >= 2) {
            $candidate = (string)$parts[count($parts) - 2];
            if (preg_match('/^[a-z0-9]{10}$/i', $candidate)) {
                return strtolower($candidate);
            }
        }

        return BackendSupport::generateVideoFolderId();
    }

    /**
     * 生成同步主站的 m3u8 / 封面相对路径（含 M3U8_DIR，例如 m3u8/2/abc1234567/index.m3u8）
     *
     * @return array{m3u8_relative: string, cover_relative: string}
     */
    public static function buildSyncMediaRelatives(string $m3u8Dir, string $recordDirectory): array
    {
        $m3u8DirSlash = trim(str_replace('\\', '/', $m3u8Dir), '/');
        if ($m3u8DirSlash === '') {
            $m3u8DirSlash = 'm3u8';
        }
        $recordDirectory = trim(str_replace('\\', '/', $recordDirectory), '/');
        $base = $recordDirectory !== '' ? ($m3u8DirSlash . '/' . $recordDirectory) : $m3u8DirSlash;

        return [
            'm3u8_relative' => $base . '/index.m3u8',
            'cover_relative' => $base . '/screenshot.jpg',
        ];
    }

    /**
     * 同步主站用的完整相对路径，例如 storage/m3u8/2/abc1234567/index.m3u8
     */
    public static function buildSyncMediaPath(string $relativePath): string
    {
        $relativePath = ltrim(str_replace('\\', '/', $relativePath), '/');
        if ($relativePath === '') {
            return '';
        }
        if (str_starts_with(strtolower($relativePath), 'storage/')) {
            return $relativePath;
        }

        return 'storage/' . $relativePath;
    }

    /**
     * 同步主站用的封面完整 URL（优先使用 IMAGE_DOMAIN）
     */
    public static function buildSyncCoverUrl(string $relativePath): string
    {
        return self::buildStoragePublicUrl($relativePath, true);
    }

    /**
     * storage 下文件的公网直链（原始 mp4 等，不做签名/代理）
     *
     * @param bool $preferImageDomain 封面优先 IMAGE_DOMAIN，否则优先 VIDEO_DOMAIN
     */
    public static function buildStoragePublicUrl(string $relativePath, bool $preferImageDomain = false): string
    {
        $syncPath = self::buildSyncMediaPath($relativePath);
        if ($syncPath === '') {
            return '';
        }

        $config = BackendConfig::get();
        $domain = '';
        if ($preferImageDomain) {
            $domain = trim((string)($config['IMAGE_DOMAIN'] ?? ''));
        }
        if ($domain === '') {
            $domain = trim((string)($config['VIDEO_DOMAIN'] ?? ''));
        }
        if ($domain === '') {
            $domain = trim((string)($config['UPLOAD_DOMAIN'] ?? ''));
        }
        if ($domain === '') {
            return '/' . ltrim($syncPath, '/');
        }

        if (preg_match('#^https?://#i', $domain)) {
            return rtrim($domain, '/') . '/' . ltrim($syncPath, '/');
        }

        return 'https://' . rtrim($domain, '/') . '/' . ltrim($syncPath, '/');
    }

    /** @deprecated 使用 buildSyncMediaPath */
    public static function normalizeSyncM3u8Path(string $relativePath): string
    {
        return self::buildSyncMediaPath($relativePath);
    }
}
