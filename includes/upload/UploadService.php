<?php

declare(strict_types=1);

final class UploadService
{
    public static function validateVideoFile(array $file): ?string
    {
        $errorCode = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
        if ($errorCode !== UPLOAD_ERR_OK) {
            return UploadSupport::fileErrorMessage($errorCode);
        }
        $tmpName = (string)($file['tmp_name'] ?? '');
        if ($tmpName === '' || !is_uploaded_file($tmpName)) {
            return '上传临时文件无效';
        }
        if (strtolower(pathinfo((string)($file['name'] ?? ''), PATHINFO_EXTENSION)) !== 'mp4') {
            return '当前仅支持上传 mp4 视频文件';
        }
        if ((int)($file['size'] ?? 0) <= 0) {
            return '视频文件为空';
        }

        return null;
    }

    public static function saveLocalFromFile(array $file, array $modeConfig, string $baseDir): array
    {
        if ($error = self::validateVideoFile($file)) {
            return ['ok' => false, 'error' => $error];
        }

        $localMp4Dir = UploadSupport::normalizeRelativePath((string)($modeConfig['local_mp4_dir'] ?? 'uploads/user_videos/mp4'));
        if ($localMp4Dir === '') {
            return ['ok' => false, 'error' => '本机 mp4 目录配置无效'];
        }

        $originalName = (string)$file['name'];
        $storedName = UploadSupport::storedFileName($originalName);
        $targetDir = rtrim($baseDir, '/\\') . DIRECTORY_SEPARATOR . $localMp4Dir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            return ['ok' => false, 'error' => '创建本机上传目录失败'];
        }

        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $localMp4Dir . '/' . $storedName);
        $target = $targetDir . DIRECTORY_SEPARATOR . $storedName;
        if (!move_uploaded_file((string)$file['tmp_name'], $target)) {
            return ['ok' => false, 'error' => '保存本机视频文件失败'];
        }

        return [
            'ok' => true,
            'stored_filename' => $relativePath,
            'backend_file_id' => $relativePath,
            'original_filename' => $originalName,
            'size_bytes' => filesize($target) ?: (int)$file['size'],
        ];
    }

    public static function mergeLocalSession(
        string $projectRoot,
        string $sessionId,
        int $userId,
        array $modeConfig
    ): array {
        $localMp4Dir = UploadSupport::normalizeRelativePath((string)($modeConfig['local_mp4_dir'] ?? 'uploads/user_videos/mp4'));
        if ($localMp4Dir === '') {
            return ['ok' => false, 'error' => '本机 mp4 目录配置无效'];
        }

        $meta = ChunkUpload::loadSession($projectRoot, $sessionId, $userId);
        if ($meta === null) {
            return ['ok' => false, 'error' => '上传会话无效'];
        }

        $storedName = UploadSupport::storedFileName((string)($meta['file_name'] ?? 'video.mp4'));
        $targetDir = rtrim($projectRoot, '/\\') . DIRECTORY_SEPARATOR . $localMp4Dir;
        if (!is_dir($targetDir) && !mkdir($targetDir, 0755, true)) {
            return ['ok' => false, 'error' => '创建本机上传目录失败'];
        }

        $target = $targetDir . DIRECTORY_SEPARATOR . $storedName;
        $merged = ChunkUpload::mergeToFile($projectRoot, $sessionId, $userId, $target);
        if (empty($merged['ok'])) {
            return $merged;
        }

        $relativePath = str_replace(DIRECTORY_SEPARATOR, '/', $localMp4Dir . '/' . $storedName);

        return [
            'ok' => true,
            'stored_filename' => $relativePath,
            'backend_file_id' => $relativePath,
            'original_filename' => (string)($merged['original_filename'] ?? ''),
            'size_bytes' => (int)($merged['size_bytes'] ?? 0),
        ];
    }

    public static function initUpload(PDO $pdo, int $userId, array $input): array
    {
        throw new RuntimeException('请使用上传页面提交 mp4 视频');
    }

    public static function buildUserStorageRelativePath(
        int $userId,
        string $storedName,
        array $phpConfig,
        ?string $videoFolder = null
    ): string {
        $subdir = trim((string)($phpConfig['user_subdir'] ?? 'users'), '/\\');
        if ($subdir === '') {
            $subdir = 'users';
        }
        $folder = $videoFolder ?? UploadSupport::generateVideoFolderId();
        $storedName = ltrim(str_replace('\\', '/', $storedName), '/');

        return $subdir . '/' . max(1, $userId) . '/' . $folder . '/' . $storedName;
    }

    public static function prepareUserUpload(PDO $pdo, int $userId, string $originalFilename): array
    {
        if (!UploadConfig::isPhpUploadReady($pdo)) {
            return [
                'ok' => false,
                'error' => '上传服务未就绪，请管理员在「上传中心 → 转码后端」配置远程地址与 API Token',
            ];
        }

        $apiConfig = UploadConfig::getApi($pdo);
        $secret = trim((string)($apiConfig['remote_api_token'] ?? ''));
        $embedPageUrl = UploadConfig::resolveEmbedUploadPageUrl($apiConfig);
        if ($embedPageUrl === '') {
            return ['ok' => false, 'error' => '未配置内嵌上传页面域名，请在主站「转码后端」填写上传域名，或在远程 视频上传/config.php 设置 UPLOAD_DOMAIN'];
        }

        $phpConfig = UploadConfig::getPhp($pdo);
        $videoFolder = UploadSupport::generateVideoFolderId();
        $storedName = UploadSupport::storedFileName($originalFilename !== '' ? $originalFilename : 'video.mp4');
        $relative = self::buildUserStorageRelativePath($userId, $storedName, $phpConfig, $videoFolder);
        $token = UploadTokenService::buildUserToken($userId, $secret);
        UploadTokenService::rememberNonce($pdo, $userId, $token['nonce'], $token['exp']);

        $parentOrigin = '';
        if (!empty($_SERVER['HTTP_HOST'])) {
            $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
            $parentOrigin = $scheme . '://' . (string)$_SERVER['HTTP_HOST'];
        }

        return [
            'ok' => true,
            'mode' => 'embed',
            'message' => '请在下方上传区域选择视频并上传',
            'upload_token' => $token['token'],
            'stored_filename' => $relative,
            'backend_file_id' => $relative,
            'video_folder' => $videoFolder,
            'original_filename' => $originalFilename,
            'embed_upload_page_url' => $embedPageUrl,
            'embed_upload_url' => UploadConfig::buildEmbedUploadUrl(
                $apiConfig,
                $token['token'],
                $relative,
                $parentOrigin
            ),
            'complete_url' => 'api/upload_complete.php',
            'max_upload_mb' => (int)$phpConfig['max_upload_mb'],
            'max_upload_bytes' => (int)$phpConfig['max_upload_mb'] * 1024 * 1024,
        ];
    }

    /**
     * @deprecated 主站中转已停用，请使用 prepareUserUpload + 浏览器直传后端
     * @param array<string, mixed> $meta
     */
    public static function submitUserVideo(PDO $pdo, int $userId, array $file, array $meta): array
    {
        if (!UploadConfig::isPhpUploadReady($pdo)) {
            return [
                'ok' => false,
                'error' => '上传服务未就绪，请管理员在「上传中心 → 转码后端」配置远程地址与 API Token',
            ];
        }

        if ($error = self::validateVideoFile($file)) {
            return ['ok' => false, 'error' => $error];
        }

        $phpConfig = UploadConfig::getPhp($pdo);
        $maxBytes = (int)$phpConfig['max_upload_mb'] * 1024 * 1024;
        if ($maxBytes > 0 && (int)($file['size'] ?? 0) > $maxBytes) {
            return ['ok' => false, 'error' => '视频超过限制（最大 ' . (int)$phpConfig['max_upload_mb'] . ' MB）'];
        }

        $apiConfig = UploadConfig::getApi($pdo);
        $secret = trim((string)($apiConfig['remote_api_token'] ?? ''));
        $originalName = (string)($file['name'] ?? 'video.mp4');
        $videoFolder = UploadSupport::generateVideoFolderId();
        $storedName = UploadSupport::storedFileName($originalName);
        $relative = self::buildUserStorageRelativePath($userId, $storedName, $phpConfig, $videoFolder);

        $token = UploadTokenService::buildUserToken($userId, $secret);
        UploadTokenService::rememberNonce($pdo, $userId, $token['nonce'], $token['exp']);

        $relay = self::relayVideoToBackend($pdo, $file, [
            'upload_token' => $token['token'],
            'stored_filename' => $relative,
            'title' => (string)($meta['title'] ?? ''),
            'description' => (string)($meta['description'] ?? ''),
        ]);
        if (empty($relay['ok'])) {
            return $relay;
        }

        if (!UploadTokenService::consumeNonce($pdo, $userId, $token['nonce'])) {
            return ['ok' => false, 'error' => '上传会话无效，请重试'];
        }

        try {
            $uploadId = self::createRecord($pdo, $userId, [
                'title' => (string)($meta['title'] ?? ''),
                'description' => (string)($meta['description'] ?? ''),
                'original_filename' => $originalName,
                'stored_filename' => (string)($relay['stored_filename'] ?? $relative),
                'backend_file_id' => (string)($relay['backend_file_id'] ?? $relative),
                'is_traffic' => !empty($meta['is_traffic']),
                'traffic_cost' => (string)($meta['traffic_cost'] ?? '0'),
            ]);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        }

        return [
            'ok' => true,
            'message' => '视频已上传至转码服务器并提交审核',
            'upload_id' => $uploadId,
            'stored_filename' => (string)($relay['stored_filename'] ?? $relative),
            'size_bytes' => (int)($relay['size_bytes'] ?? 0),
        ];
    }

    public static function testBackendConnection(PDO $pdo): array
    {
        if (!UploadConfig::isPhpUploadReady($pdo)) {
            return ['ok' => false, 'error' => '请先配置转码后端地址与 API Token'];
        }

        $apiConfig = UploadConfig::getApi($pdo);
        $endpoints = UploadConfig::resolveLegacyVideoEndpoints($apiConfig);
        $pingUrls = [];
        foreach ($endpoints as $endpoint) {
            $pingUrls[] = preg_replace('#/api/upload_video\.php$#i', '/api/ping.php', $endpoint) ?? $endpoint;
        }
        $pingUrls = array_values(array_unique(array_filter($pingUrls)));

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => '主站未启用 curl'];
        }

        $lastError = '无法连接远程后端';
        foreach ($pingUrls as $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 10,
                CURLOPT_TIMEOUT => 15,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($response === false) {
                $lastError = $url . ' — ' . $curlError;
                continue;
            }
            $data = json_decode((string)$response, true);
            if (is_array($data) && !empty($data['ok'])) {
                return ['ok' => true, 'message' => '远程后端可达：' . $url];
            }
            $lastError = $url . ' HTTP ' . $status;
        }

        return ['ok' => false, 'error' => $lastError];
    }

    /** @deprecated */
    public static function uploadVideoViaFtp(PDO $pdo, int $userId, array $file): array
    {
        if ($error = self::validateVideoFile($file)) {
            return ['ok' => false, 'error' => $error];
        }

        $ftpConfig = UploadConfig::getFtp($pdo);
        if (!UploadConfig::isFtpConfigured($ftpConfig)) {
            return ['ok' => false, 'error' => 'FTP 上传未配置，请联系管理员在「上传中心 → FTP 配置」中填写服务器信息'];
        }

        if (!function_exists('ftp_connect')) {
            return ['ok' => false, 'error' => '服务器未启用 PHP FTP 扩展，无法上传'];
        }

        $originalName = (string)($file['name'] ?? 'video.mp4');
        $videoFolder = UploadSupport::generateVideoFolderId();
        $storedName = UploadSupport::storedFileName($originalName);
        $client = new FtpClient($ftpConfig);
        $remoteRelative = $client->buildUserRemotePath($userId, $storedName, $videoFolder);

        @set_time_limit(0);
        @ignore_user_abort(true);

        try {
            $result = $client->uploadFile((string)$file['tmp_name'], $remoteRelative);
        } catch (Throwable $e) {
            return ['ok' => false, 'error' => $e->getMessage()];
        } finally {
            $client->disconnect();
        }

        if (empty($result['ok'])) {
            return $result;
        }

        $path = (string)($result['remote_path'] ?? $remoteRelative);

        return [
            'ok' => true,
            'stored_filename' => $path,
            'backend_file_id' => $path,
            'original_filename' => $originalName,
            'size_bytes' => (int)($result['size_bytes'] ?? 0),
        ];
    }

    public static function deleteFtpVideo(PDO $pdo, array $upload): void
    {
        $path = trim((string)($upload['stored_filename'] ?? $upload['backend_file_id'] ?? ''));
        if ($path === '') {
            return;
        }
        $ftpConfig = UploadConfig::getFtp($pdo);
        if (!UploadConfig::isFtpConfigured($ftpConfig)) {
            return;
        }
        try {
            (new FtpClient($ftpConfig))->deleteFile($path);
        } catch (Throwable) {
            // 忽略 FTP 删除失败，后端可能已清理
        }
    }

    public static function testFtpConnection(PDO $pdo): array
    {
        $ftpConfig = UploadConfig::getFtp($pdo);
        if (!UploadConfig::isFtpConfigured($ftpConfig)) {
            return ['ok' => false, 'error' => '请先填写 FTP 主机与用户名'];
        }
        if (!function_exists('ftp_connect')) {
            return ['ok' => false, 'error' => 'PHP 未启用 FTP 扩展'];
        }

        return (new FtpClient($ftpConfig))->testConnection();
    }

    public static function ensureTable(PDO $pdo): void
    {
        $pdo->exec("CREATE TABLE IF NOT EXISTS `user_video_uploads` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `user_id` int(11) NOT NULL,
        `title` varchar(255) NOT NULL,
        `description` text,
        `original_filename` varchar(255) DEFAULT NULL,
        `stored_filename` varchar(255) DEFAULT NULL,
        `backend_file_id` varchar(120) DEFAULT NULL,
        `is_traffic` tinyint(1) NOT NULL DEFAULT 0,
        `traffic_cost` int(11) NOT NULL DEFAULT 0,
        `status` enum('pending','approved','rejected') NOT NULL DEFAULT 'pending',
        `save_original` tinyint(1) NOT NULL DEFAULT 0,
        `review_note` varchar(255) DEFAULT NULL,
        `reviewed_by` int(11) DEFAULT NULL,
        `reviewed_at` datetime DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        `updated_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_user_id` (`user_id`),
        KEY `idx_status` (`status`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

        if (!(bool)$pdo->query("SHOW COLUMNS FROM user_video_uploads LIKE 'is_traffic'")->fetch()) {
            $pdo->exec('ALTER TABLE user_video_uploads ADD COLUMN is_traffic tinyint(1) NOT NULL DEFAULT 0 AFTER backend_file_id');
        }
        if (!(bool)$pdo->query("SHOW COLUMNS FROM user_video_uploads LIKE 'traffic_cost'")->fetch()) {
            $pdo->exec('ALTER TABLE user_video_uploads ADD COLUMN traffic_cost int(11) NOT NULL DEFAULT 0 AFTER is_traffic');
        }
    }

    public static function statusLabels(): array
    {
        return [
            'pending' => '待审核',
            'approved' => '审核通过',
            'rejected' => '审核失败',
        ];
    }

    public static function statusClass(string $status): string
    {
        return match ($status) {
            'approved' => 'bg-green-50 text-green-700',
            'rejected' => 'bg-red-50 text-red-600',
            default => 'bg-amber-50 text-amber-700',
        };
    }

    public static function createRecord(PDO $pdo, int $userId, array $data): int
    {
        self::ensureTable($pdo);

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            throw new InvalidArgumentException('请输入视频名称');
        }

        $videoConfig = UploadConfig::getVideo($pdo);
        $isTraffic = !empty($videoConfig['traffic_enabled']) && !empty($data['is_traffic']) ? 1 : 0;
        $trafficCost = $isTraffic ? max(0, (int)($data['traffic_cost'] ?? 0)) : 0;

        $stmt = $pdo->prepare('
            INSERT INTO user_video_uploads
                (user_id, title, description, original_filename, stored_filename, backend_file_id, is_traffic, traffic_cost, status)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, "pending")
        ');
        $stmt->execute([
            $userId,
            $title,
            trim((string)($data['description'] ?? '')),
            self::nullableString($data['original_filename'] ?? ''),
            self::nullableString($data['stored_filename'] ?? ''),
            self::nullableString($data['backend_file_id'] ?? ''),
            $isTraffic,
            $trafficCost,
        ]);

        return (int)$pdo->lastInsertId();
    }

    public static function fetchByUser(PDO $pdo, int $userId): array
    {
        self::ensureTable($pdo);
        $stmt = $pdo->prepare('SELECT * FROM user_video_uploads WHERE user_id = ? ORDER BY id DESC');
        $stmt->execute([$userId]);

        return $stmt->fetchAll() ?: [];
    }

    public static function fetchReviewList(PDO $pdo): array
    {
        self::ensureTable($pdo);

        return $pdo->query('
            SELECT vu.*, u.username
            FROM user_video_uploads vu
            LEFT JOIN users u ON u.id = vu.user_id
            ORDER BY vu.status = "pending" DESC, vu.id DESC
        ')->fetchAll() ?: [];
    }

    public static function notifyBackend(PDO $pdo, array $upload, string $action, array $extra = []): array
    {
        $apiConfig = UploadConfig::getApi($pdo);
        if (!UploadConfig::hasTranscodeBackend($apiConfig)) {
            return ['ok' => true, 'message' => '未配置转码后端，仅更新本站审核状态'];
        }

        $backendUrl = UploadConfig::resolveBackendRoot($apiConfig);
        $token = (string)$apiConfig['remote_api_token'];
        if ($backendUrl === '' || $token === '') {
            return ['ok' => false, 'error' => '远程后端地址或 API Token 未配置'];
        }

        $uploadPayload = [
            'id' => (int)$upload['id'],
            'title' => (string)$upload['title'],
            'description' => (string)($upload['description'] ?? ''),
            'uploader' => (string)($upload['username'] ?? ''),
            'user_id' => (int)($upload['user_id'] ?? 0),
            'backend_file_id' => (string)($upload['backend_file_id'] ?? ''),
            'stored_filename' => (string)($upload['stored_filename'] ?? ''),
            'original_filename' => (string)($upload['original_filename'] ?? ''),
            'created_at' => (string)($upload['created_at'] ?? ''),
            'storage_type' => 'php',
            'source' => self::buildStorageSourcePayload($pdo, $upload),
        ];

        $payloadData = [
            'api_token' => $token,
            'action' => $action,
            'upload' => $uploadPayload,
        ];
        foreach ($extra as $key => $value) {
            $payloadData[$key] = $value;
        }

        $payload = json_encode($payloadData, JSON_UNESCAPED_UNICODE);
        if ($payload === false) {
            return ['ok' => false, 'error' => '生成远程审核请求失败'];
        }

        return self::httpJsonPost($backendUrl . '/api/review_action.php', $payload);
    }

    /** @return array<string, string> */
    public static function buildStorageSourcePayload(PDO $pdo, array $upload): array
    {
        $php = UploadConfig::getPhp($pdo);
        $relative = trim(str_replace('\\', '/', (string)($upload['stored_filename'] ?? $upload['backend_file_id'] ?? '')), '/');

        return [
            'type' => 'php',
            'relative_path' => $relative,
            'user_subdir' => (string)($php['user_subdir'] ?? 'users'),
        ];
    }

    public static function applyPublishSettings(PDO $pdo, array $upload, array $backendResult): void
    {
        $videoId = isset($backendResult['video_id']) ? (int)$backendResult['video_id'] : 0;
        if ($videoId <= 0) {
            return;
        }

        require_once __DIR__ . '/../upload_domain_group.php';

        $sets = [];
        $vals = [];
        if ((bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'server_group_id'")->fetch()) {
            $uploadSg = getUploadDomainServerGroupId($pdo);
            if ($uploadSg !== null) {
                $sgCheck = $pdo->prepare('SELECT server_group_id FROM videos WHERE id = ? LIMIT 1');
                $sgCheck->execute([$videoId]);
                $currentSg = $sgCheck->fetchColumn();
                if ($currentSg === false || $currentSg === null || $currentSg === '') {
                    $sets[] = 'server_group_id = ?';
                    $vals[] = $uploadSg;
                }
            }
        }
        if ((bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'is_traffic'")->fetch()) {
            $sets[] = 'is_traffic = ?';
            $vals[] = !empty($upload['is_traffic']) ? 1 : 0;
        }
        if ((bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'traffic_cost'")->fetch()) {
            $sets[] = 'traffic_cost = ?';
            $vals[] = max(0, (int)($upload['traffic_cost'] ?? 0));
        }
        if ((bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'uploaded_by'")->fetch()) {
            $sets[] = 'uploaded_by = ?';
            $vals[] = (int)($upload['user_id'] ?? 0);
        }
        if ((bool)$pdo->query("SHOW COLUMNS FROM videos LIKE 'skip_backend_proxy'")->fetch()) {
            $sets[] = 'skip_backend_proxy = ?';
            $vals[] = 1;
        }
        if ($sets === []) {
            return;
        }

        $vals[] = $videoId;
        $pdo->prepare('UPDATE videos SET ' . implode(', ', $sets) . ' WHERE id = ?')->execute($vals);
    }

    public static function notifyUser(PDO $pdo, int $userId, string $title, string $content, int $adminId): void
    {
        if ($userId <= 0) {
            return;
        }
        $pdo->exec("CREATE TABLE IF NOT EXISTS `notifications` (
        `id` bigint unsigned NOT NULL AUTO_INCREMENT,
        `title` varchar(200) NOT NULL,
        `content` text NOT NULL,
        `target_type` enum('all','user') NOT NULL DEFAULT 'all',
        `target_user_id` int(11) DEFAULT NULL,
        `created_by` int(11) DEFAULT NULL,
        `created_at` datetime NOT NULL DEFAULT CURRENT_TIMESTAMP,
        PRIMARY KEY (`id`),
        KEY `idx_target` (`target_type`,`target_user_id`),
        KEY `idx_created_at` (`created_at`)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $stmt = $pdo->prepare('INSERT INTO notifications (title, content, target_type, target_user_id, created_by) VALUES (?, ?, "user", ?, ?)');
        $stmt->execute([$title, $content, $userId, $adminId > 0 ? $adminId : null]);
    }

    public static function videoRecordMap(PDO $pdo): array
    {
        $raw = getSetting($pdo, 'video_sync_record_map', '{}');
        $map = json_decode((string)$raw, true);

        return is_array($map) ? $map : [];
    }

    public static function saveVideoRecordMap(PDO $pdo, array $map): void
    {
        setSetting($pdo, 'video_sync_record_map', json_encode($map, JSON_UNESCAPED_UNICODE));
    }

    public static function fetchManaged(PDO $pdo): array
    {
        self::ensureTable($pdo);
        $uploads = $pdo->query('
            SELECT vu.*, u.username
            FROM user_video_uploads vu
            LEFT JOIN users u ON u.id = vu.user_id
            ORDER BY vu.id DESC
        ')->fetchAll() ?: [];

        $map = self::videoRecordMap($pdo);
        $videoIds = [];
        foreach ($uploads as $upload) {
            $recordId = 'upload_' . (int)$upload['id'];
            if (!empty($map[$recordId])) {
                $videoIds[] = (int)$map[$recordId];
            }
        }
        $videoIds = array_values(array_unique(array_filter($videoIds)));

        $videosById = [];
        $episodesByVideoId = [];
        if ($videoIds !== []) {
            $placeholders = implode(',', array_fill(0, count($videoIds), '?'));
            $stmt = $pdo->prepare("SELECT * FROM videos WHERE id IN ($placeholders)");
            $stmt->execute($videoIds);
            foreach ($stmt->fetchAll() ?: [] as $video) {
                $videosById[(int)$video['id']] = $video;
            }

            $stmt = $pdo->prepare("
                SELECT *
                FROM video_episodes
                WHERE video_id IN ($placeholders)
                ORDER BY episode_order ASC, id ASC
            ");
            $stmt->execute($videoIds);
            foreach ($stmt->fetchAll() ?: [] as $episode) {
                $vid = (int)$episode['video_id'];
                if (!isset($episodesByVideoId[$vid])) {
                    $episodesByVideoId[$vid] = $episode;
                }
            }
        }

        foreach ($uploads as &$upload) {
            $recordId = 'upload_' . (int)$upload['id'];
            $videoId = !empty($map[$recordId]) ? (int)$map[$recordId] : 0;
            $video = $videoId > 0 ? ($videosById[$videoId] ?? null) : null;
            $episode = $videoId > 0 ? ($episodesByVideoId[$videoId] ?? null) : null;
            $upload['record_id'] = $recordId;
            $upload['video_id'] = $videoId;
            $upload['published_title'] = $video['title'] ?? '';
            $upload['published_description'] = $video['description'] ?? '';
            $rawCover = (string)($video['cover'] ?? '');
            $upload['cover'] = $rawCover !== '' ? UploadSupport::resolveCoverUrl($pdo, $rawCover) : '';
            $upload['video_url'] = $episode['video_url'] ?? '';
            $upload['episode_id'] = $episode['id'] ?? 0;
        }
        unset($upload);

        return $uploads;
    }

    public static function getManaged(PDO $pdo, int $uploadId): ?array
    {
        foreach (self::fetchManaged($pdo) as $upload) {
            if ((int)$upload['id'] === $uploadId) {
                return $upload;
            }
        }

        return null;
    }

    public static function updateManagedStatus(PDO $pdo, int $uploadId, string $status, int $adminId): array
    {
        $labels = self::statusLabels();
        if (!isset($labels[$status])) {
            return ['ok' => false, 'error' => '状态无效'];
        }
        $upload = self::getManaged($pdo, $uploadId);
        if (!$upload) {
            return ['ok' => false, 'error' => '上传记录不存在'];
        }

        $note = $status === 'rejected' ? '管理员手动修改为审核失败' : null;
        $pdo->prepare('UPDATE user_video_uploads SET status = ?, reviewed_by = ?, reviewed_at = NOW(), review_note = ? WHERE id = ?')
            ->execute([$status, $adminId, $note, $uploadId]);
        self::notifyUser(
            $pdo,
            (int)$upload['user_id'],
            '上传视频状态已更新',
            '你上传的视频「' . (string)$upload['title'] . '」状态已更新为：' . $labels[$status],
            $adminId
        );

        return ['ok' => true, 'message' => '视频状态已更新'];
    }

    public static function editManaged(PDO $pdo, int $uploadId, array $data): array
    {
        $upload = self::getManaged($pdo, $uploadId);
        if (!$upload) {
            return ['ok' => false, 'error' => '上传记录不存在'];
        }

        $title = trim((string)($data['title'] ?? ''));
        if ($title === '') {
            return ['ok' => false, 'error' => '请输入视频名称'];
        }
        $description = trim((string)($data['description'] ?? ''));
        $cover = UploadSupport::resolveCoverUrl($pdo, (string)($data['cover'] ?? ''));
        $videoUrl = UploadSupport::normalizeMediaPath((string)($data['video_url'] ?? ''));

        $pdo->prepare('UPDATE user_video_uploads SET title = ?, description = ? WHERE id = ?')
            ->execute([$title, $description, $uploadId]);

        if ((int)$upload['video_id'] > 0) {
            $pdo->prepare('UPDATE videos SET title = ?, description = ?, cover = ? WHERE id = ?')
                ->execute([$title, $description, $cover !== '' ? $cover : null, (int)$upload['video_id']]);
            if ((int)$upload['episode_id'] > 0 && $videoUrl !== '') {
                $pdo->prepare('UPDATE video_episodes SET video_url = ? WHERE id = ?')
                    ->execute([$videoUrl, (int)$upload['episode_id']]);
            }
        }

        return ['ok' => true, 'message' => '视频信息已更新'];
    }

    public static function deleteManaged(PDO $pdo, int $uploadId, int $adminId): array
    {
        $upload = self::getManaged($pdo, $uploadId);
        if (!$upload) {
            return ['ok' => false, 'error' => '上传记录不存在'];
        }

        $mediaPaths = array_values(array_filter([
            (string)($upload['video_url'] ?? ''),
            (string)($upload['cover'] ?? ''),
        ]));
        $backendResult = self::notifyBackend($pdo, $upload, 'delete_video', ['media_paths' => $mediaPaths]);
        if (empty($backendResult['ok'])) {
            return $backendResult;
        }
        self::deleteFtpVideo($pdo, $upload);

        $pdo->beginTransaction();
        try {
            if ((int)$upload['video_id'] > 0) {
                $pdo->prepare('DELETE FROM video_episodes WHERE video_id = ?')->execute([(int)$upload['video_id']]);
                $pdo->prepare('DELETE FROM videos WHERE id = ?')->execute([(int)$upload['video_id']]);
            }
            $pdo->prepare('DELETE FROM user_video_uploads WHERE id = ?')->execute([$uploadId]);
            $map = self::videoRecordMap($pdo);
            unset($map[(string)$upload['record_id']]);
            self::saveVideoRecordMap($pdo, $map);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }

        self::notifyUser(
            $pdo,
            (int)$upload['user_id'],
            '上传视频已删除',
            '你上传的视频「' . (string)$upload['title'] . '」已被管理员删除，相关文件和数据已同步清理。',
            $adminId
        );

        return ['ok' => true, 'message' => '视频已删除并同步后端'];
    }

    public static function review(PDO $pdo, int $uploadId, string $action, int $adminId): array
    {
        self::ensureTable($pdo);

        $stmt = $pdo->prepare('
            SELECT vu.*, u.username
            FROM user_video_uploads vu
            LEFT JOIN users u ON u.id = vu.user_id
            WHERE vu.id = ?
            LIMIT 1
        ');
        $stmt->execute([$uploadId]);
        $upload = $stmt->fetch();
        if (!$upload) {
            return ['ok' => false, 'error' => '上传记录不存在'];
        }

        if ($action === 'approve') {
            $backendResult = self::notifyBackend($pdo, $upload, 'approve');
            if (empty($backendResult['ok'])) {
                return $backendResult;
            }
            self::applyPublishSettings($pdo, $upload, $backendResult);
            $pdo->prepare('UPDATE user_video_uploads SET status = "approved", reviewed_by = ?, reviewed_at = NOW(), review_note = NULL WHERE id = ?')
                ->execute([$adminId, $uploadId]);

            return ['ok' => true, 'message' => '已通过审核'];
        }

        if ($action === 'reject') {
            $backendResult = self::notifyBackend($pdo, $upload, 'reject');
            if (empty($backendResult['ok'])) {
                return $backendResult;
            }
            $pdo->prepare('UPDATE user_video_uploads SET status = "rejected", reviewed_by = ?, reviewed_at = NOW(), review_note = "审核失败，已通知远程后端删除视频文件" WHERE id = ?')
                ->execute([$adminId, $uploadId]);

            return ['ok' => true, 'message' => '已标记审核失败'];
        }

        if ($action === 'save_original') {
            $backendResult = self::notifyBackend($pdo, $upload, 'save_original');
            if (empty($backendResult['ok'])) {
                return $backendResult;
            }
            $pdo->prepare('UPDATE user_video_uploads SET save_original = 1 WHERE id = ?')->execute([$uploadId]);

            return ['ok' => true, 'message' => '已通知后端保存原始文件'];
        }

        return ['ok' => false, 'error' => '未知审核动作'];
    }

    public static function relayVideoToBackend(PDO $pdo, array $file, array $fields): array
    {
        if ($error = self::validateVideoFile($file)) {
            return ['ok' => false, 'error' => $error];
        }

        $apiConfig = UploadConfig::getApi($pdo);
        $endpoints = UploadConfig::resolveLegacyVideoEndpoints($apiConfig);
        if ($endpoints === []) {
            return ['ok' => false, 'error' => '未配置远程上传后端地址'];
        }

        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => '主站未启用 curl，无法转发到远程后端'];
        }

        $tmpName = (string)($file['tmp_name'] ?? '');
        $mime = (string)($file['type'] ?? 'video/mp4');
        $originalName = (string)($file['name'] ?? 'video.mp4');
        $post = [
            'upload_token' => (string)($fields['upload_token'] ?? ''),
            'stored_filename' => (string)($fields['stored_filename'] ?? ''),
            'title' => (string)($fields['title'] ?? ''),
            'description' => (string)($fields['description'] ?? ''),
            'video_file' => new CURLFile($tmpName, $mime !== '' ? $mime : 'video/mp4', $originalName),
        ];

        $lastError = '无法连接远程上传后端';
        foreach ($endpoints as $endpoint) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $post,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 20,
                CURLOPT_TIMEOUT => 0,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
            ]);
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);

            if ($response === false) {
                $lastError = '转发失败：' . $endpoint . ' — ' . $curlError;
                continue;
            }

            $data = json_decode((string)$response, true);
            if (is_array($data) && !empty($data['ok'])) {
                $data['relayed_via'] = 'main_site_bridge';

                return $data;
            }

            $lastError = is_array($data)
                ? (string)($data['error'] ?? $data['message'] ?? '远程返回失败')
                : '远程返回格式错误（HTTP ' . $status . '）';
            $lastError .= ' @ ' . $endpoint;
        }

        return ['ok' => false, 'error' => $lastError];
    }

    private static function nullableString(mixed $value): ?string
    {
        $value = trim((string)$value);

        return $value !== '' ? $value : null;
    }

    private static function httpJsonPost(string $endpoint, string $payload): array
    {
        $status = 0;
        if (function_exists('curl_init')) {
            $ch = curl_init($endpoint);
            curl_setopt_array($ch, [
                CURLOPT_POST => true,
                CURLOPT_POSTFIELDS => $payload,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_CONNECTTIMEOUT => 15,
                CURLOPT_TIMEOUT => 300,
                CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
            ]);
            $response = curl_exec($ch);
            $curlError = curl_error($ch);
            $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
            curl_close($ch);
            if ($response === false) {
                return ['ok' => false, 'error' => '无法连接远程上传后端：' . $curlError];
            }
        } else {
            $context = stream_context_create([
                'http' => [
                    'method' => 'POST',
                    'header' => "Content-Type: application/json\r\nAccept: application/json\r\n",
                    'content' => $payload,
                    'timeout' => 300,
                    'ignore_errors' => true,
                ],
            ]);
            $response = @file_get_contents($endpoint, false, $context);
            $statusLine = $http_response_header[0] ?? '';
            $status = preg_match('/\s(\d{3})\s/', $statusLine, $matches) ? (int)$matches[1] : 0;
            if ($response === false) {
                return ['ok' => false, 'error' => '无法连接远程上传后端'];
            }
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            $detail = UploadSupport::responseSnippet((string)$response);
            $statusText = $status > 0 ? 'HTTP ' . $status . '，' : '';

            return ['ok' => false, 'error' => '远程上传后端返回格式错误：' . $statusText . ($detail !== '' ? $detail : '空响应')];
        }
        if ($status >= 400 && empty($data['ok'])) {
            $message = (string)($data['error'] ?? $data['message'] ?? '远程请求失败');

            return ['ok' => false, 'error' => $message . '（HTTP ' . $status . '）'];
        }

        return $data;
    }
}
