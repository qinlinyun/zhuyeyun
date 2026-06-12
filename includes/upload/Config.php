<?php

declare(strict_types=1);

require_once dirname(__DIR__) . '/settings.php';
require_once dirname(__DIR__) . '/site_play_token.php';

final class UploadConfig
{
    public static function getMode(PDO $pdo): array
    {
        return array_merge(self::getPhp($pdo), ['mode' => 'php']);
    }

    public static function getPhp(PDO $pdo): array
    {
        $legacyMode = getSetting($pdo, 'upload_mode', '') ?: '';
        if ($legacyMode !== 'php') {
            setSetting($pdo, 'upload_mode', 'php');
        }

        $userSubdir = getSetting($pdo, 'upload_php_user_subdir', '') ?: '';
        if ($userSubdir === '') {
            $userSubdir = getSetting($pdo, 'upload_ftp_user_subdir', 'users') ?: 'users';
        }

        return [
            'user_subdir' => trim((string)$userSubdir, '/\\') ?: 'users',
            'max_upload_mb' => max(1, (int)(getSetting($pdo, 'upload_php_max_mb', '2048') ?: 2048)),
        ];
    }

    public static function savePhp(PDO $pdo, array $data): void
    {
        setSetting($pdo, 'upload_mode', 'php');
        $userSubdir = trim((string)($data['user_subdir'] ?? 'users'), '/\\');
        setSetting($pdo, 'upload_php_user_subdir', $userSubdir !== '' ? $userSubdir : 'users');
        setSetting($pdo, 'upload_php_max_mb', (string)max(1, (int)($data['max_upload_mb'] ?? 2048)));
    }

    public static function isPhpUploadReady(PDO $pdo): bool
    {
        return self::hasTranscodeBackend(self::getApi($pdo));
    }

    /** @deprecated 已改为 PHP 上传 */
    public static function getFtp(PDO $pdo): array
    {
        $php = self::getPhp($pdo);

        return array_merge($php, [
            'host' => getSetting($pdo, 'upload_ftp_host', '') ?: '',
            'port' => max(1, (int)(getSetting($pdo, 'upload_ftp_port', '21') ?: 21)),
            'username' => getSetting($pdo, 'upload_ftp_username', '') ?: '',
            'password' => getSetting($pdo, 'upload_ftp_password', '') ?: '',
            'passive' => getSetting($pdo, 'upload_ftp_passive', '1') === '1',
            'ssl' => getSetting($pdo, 'upload_ftp_ssl', '0') === '1',
            'base_path' => trim(str_replace('\\', '/', (string)(getSetting($pdo, 'upload_ftp_base_path', '') ?: '')), '/'),
            'user_subdir' => $php['user_subdir'],
            'timeout' => max(10, (int)(getSetting($pdo, 'upload_ftp_timeout', '90') ?: 90)),
            'transcode_source_root' => trim(str_replace('\\', '/', (string)(getSetting($pdo, 'upload_ftp_transcode_source_root', '') ?: '')), '/'),
        ]);
    }

    /** @deprecated */
    public static function saveFtp(PDO $pdo, array $data): void
    {
        setSetting($pdo, 'upload_mode', 'ftp');
        setSetting($pdo, 'upload_ftp_host', trim((string)($data['host'] ?? '')));
        setSetting($pdo, 'upload_ftp_port', (string)max(1, min(65535, (int)($data['port'] ?? 21))));
        setSetting($pdo, 'upload_ftp_username', trim((string)($data['username'] ?? '')));
        if (trim((string)($data['password'] ?? '')) !== '') {
            setSetting($pdo, 'upload_ftp_password', (string)$data['password']);
        }
        setSetting($pdo, 'upload_ftp_passive', !empty($data['passive']) ? '1' : '0');
        setSetting($pdo, 'upload_ftp_ssl', !empty($data['ssl']) ? '1' : '0');
        $basePath = trim(str_replace('\\', '/', (string)($data['base_path'] ?? '')), '/');
        setSetting($pdo, 'upload_ftp_base_path', $basePath);
        $userSubdir = trim((string)($data['user_subdir'] ?? 'users'), '/\\');
        setSetting($pdo, 'upload_ftp_user_subdir', $userSubdir !== '' ? $userSubdir : 'users');
        setSetting($pdo, 'upload_ftp_timeout', (string)max(10, (int)($data['timeout'] ?? 90)));
        $transcodeRoot = trim(str_replace('\\', '/', (string)($data['transcode_source_root'] ?? '')), '/');
        setSetting($pdo, 'upload_ftp_transcode_source_root', $transcodeRoot);
    }

    public static function saveMode(PDO $pdo, array $data): void
    {
        self::savePhp($pdo, $data);
    }

    public static function isFtpConfigured(array $ftp): bool
    {
        return trim((string)($ftp['host'] ?? '')) !== ''
            && trim((string)($ftp['username'] ?? '')) !== '';
    }

    public static function hasTranscodeBackend(array $apiConfig): bool
    {
        return trim((string)($apiConfig['remote_backend_url'] ?? '')) !== ''
            && trim((string)($apiConfig['remote_api_token'] ?? '')) !== '';
    }

    /** @deprecated 本机目录已不再使用 */
    public static function ensureLocalDirectories(array $config, string $baseDir): array
    {
        return [];
    }

    public static function getApi(PDO $pdo): array
    {
        return [
            'remote_backend_url' => UploadSupport::normalizeRemoteBackendUrl(getSetting($pdo, 'upload_remote_backend_url', '') ?: ''),
            'remote_api_token' => getSetting($pdo, 'upload_remote_api_token', '') ?: '',
            'upload_domain' => UploadSupport::normalizeBaseUrl(getSetting($pdo, 'upload_domain', '') ?: ''),
            'video_domain' => getSetting($pdo, 'upload_video_domain', '') ?: '',
            'image_domain' => getSetting($pdo, 'upload_image_domain', '') ?: '',
            'm3u8_dir' => getSetting($pdo, 'upload_m3u8_dir', 'm3u8') ?: 'm3u8',
            'mp4_dir' => getSetting($pdo, 'upload_mp4_dir', 'mp4') ?: 'mp4',
        ];
    }

    public static function saveApi(PDO $pdo, array $data): void
    {
        $keys = [
            'remote_backend_url' => 'upload_remote_backend_url',
            'remote_api_token' => 'upload_remote_api_token',
            'upload_domain' => 'upload_domain',
            'video_domain' => 'upload_video_domain',
            'image_domain' => 'upload_image_domain',
            'm3u8_dir' => 'upload_m3u8_dir',
            'mp4_dir' => 'upload_mp4_dir',
        ];
        foreach ($keys as $inputKey => $settingKey) {
            $value = trim((string)($data[$inputKey] ?? ''));
            if ($inputKey === 'remote_backend_url') {
                $value = UploadSupport::normalizeRemoteBackendUrl($value);
            } elseif ($inputKey === 'upload_domain') {
                $value = UploadSupport::normalizeBaseUrl($value);
            }
            setSetting($pdo, $settingKey, $value);
        }
    }

    public static function resolveBackendRoot(array $apiConfig): string
    {
        $uploadDomain = UploadSupport::normalizeBaseUrl((string)($apiConfig['upload_domain'] ?? ''));
        $backendUrl = UploadSupport::normalizeBaseUrl((string)($apiConfig['remote_backend_url'] ?? ''));
        if ($uploadDomain === '') {
            return $backendUrl;
        }
        if ($backendUrl === '') {
            return $uploadDomain;
        }

        $uploadParts = parse_url($uploadDomain);
        $backendParts = parse_url($backendUrl);
        $uploadPath = trim((string)($uploadParts['path'] ?? ''), '/');
        $backendPath = trim((string)($backendParts['path'] ?? ''), '/');

        if ($uploadPath === '' && $backendPath !== '') {
            $scheme = (string)($uploadParts['scheme'] ?? 'https');
            $host = (string)($uploadParts['host'] ?? '');
            if ($host === '') {
                return $backendUrl;
            }
            $port = isset($uploadParts['port']) ? ':' . (int)$uploadParts['port'] : '';

            return $scheme . '://' . $host . $port . '/' . $backendPath;
        }

        return $uploadDomain;
    }

    /** @return list<string> */
    public static function resolveChunkEndpoints(array $apiConfig): array
    {
        return self::expandBackendPaths($apiConfig, '/api/upload/chunk.php');
    }

    /** @return list<string> */
    public static function resolveFinishEndpoints(array $apiConfig): array
    {
        return self::expandBackendPaths($apiConfig, '/api/upload/finish.php');
    }

    /** @return list<string> */
    public static function resolveLegacyVideoEndpoints(array $apiConfig): array
    {
        return self::sortVideoUploadEndpoints(
            self::expandBackendPaths($apiConfig, '/api/upload_video.php')
        );
    }

    /**
     * 主站内嵌上传页地址（优先 upload_domain，否则 remote_backend_url）。
     */
    public static function resolveEmbedUploadPageUrl(array $apiConfig): string
    {
        $base = UploadSupport::normalizeBaseUrl((string)($apiConfig['upload_domain'] ?? ''));
        if ($base === '') {
            $base = self::resolveBackendRoot($apiConfig);
        }
        if ($base === '') {
            return '';
        }

        return rtrim($base, '/') . '/embed_upload.php';
    }

    /**
     * 手机端独立上传页（运行在远程上传后端，无 iframe）
     */
    public static function resolveMobileUploadPageUrl(array $apiConfig): string
    {
        $base = UploadSupport::normalizeBaseUrl((string)($apiConfig['upload_domain'] ?? ''));
        if ($base === '') {
            $base = self::resolveBackendRoot($apiConfig);
        }
        if ($base === '') {
            return '';
        }

        return rtrim($base, '/') . '/mobile_upload.php';
    }

    /**
     * @param array<string, string> $extraQuery
     */
    public static function buildMobileUploadUrl(
        array $apiConfig,
        string $uploadToken,
        string $storedFilename,
        array $extraQuery = []
    ): string {
        $page = self::resolveMobileUploadPageUrl($apiConfig);
        if ($page === '' || $uploadToken === '' || $storedFilename === '') {
            return '';
        }

        $query = array_merge([
            'upload_token' => $uploadToken,
            'stored_filename' => $storedFilename,
        ], $extraQuery);

        return $page . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * @param array<string, mixed> $apiConfig
     */
    public static function buildEmbedUploadUrl(
        array $apiConfig,
        string $uploadToken,
        string $storedFilename,
        string $parentOrigin = ''
    ): string {
        $page = self::resolveEmbedUploadPageUrl($apiConfig);
        if ($page === '' || $uploadToken === '' || $storedFilename === '') {
            return '';
        }

        $query = [
            'upload_token' => $uploadToken,
            'stored_filename' => $storedFilename,
        ];
        if ($parentOrigin !== '') {
            $query['parent_origin'] = $parentOrigin;
        }

        return $page . '?' . http_build_query($query, '', '&', PHP_QUERY_RFC3986);
    }

    /**
     * 优先尝试含「视频上传」子目录的地址，避免先打到站点根 /api/upload_video.php 的 404。
     *
     * @param list<string> $urls
     * @return list<string>
     */
    public static function sortVideoUploadEndpoints(array $urls): array
    {
        $unique = array_values(array_unique(array_filter($urls)));
        usort($unique, static function (string $a, string $b): int {
            $score = static function (string $url): int {
                if (preg_match('#/视频上传/|/%E8%A7%86%E9%A2%91#iu', $url)) {
                    return 3;
                }
                if (preg_match('#/api/upload_video\.php$#i', $url)) {
                    return 1;
                }

                return 2;
            };

            return $score($b) <=> $score($a);
        });

        return $unique;
    }

    /** @return list<string> */
    private static function expandBackendPaths(array $apiConfig, string $suffix): array
    {
        $roots = [];
        foreach ([
            self::resolveBackendRoot($apiConfig),
            UploadSupport::normalizeBaseUrl((string)($apiConfig['upload_domain'] ?? '')),
            UploadSupport::normalizeBaseUrl((string)($apiConfig['remote_backend_url'] ?? '')),
        ] as $root) {
            $root = rtrim((string)$root, '/');
            if ($root !== '') {
                $roots[] = $root;
            }
        }

        $endpoints = [];
        foreach (array_values(array_unique($roots)) as $root) {
            $parts = parse_url($root);
            $path = trim((string)($parts['path'] ?? ''), '/');
            if ($path === '') {
                $scheme = (string)($parts['scheme'] ?? 'https');
                $host = (string)($parts['host'] ?? '');
                if ($host !== '') {
                    $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
                    $base = $scheme . '://' . $host . $port;
                    $endpoints[] = $base . '/视频上传' . $suffix;
                }
            }
            $endpoints[] = $root . $suffix;
            $encodedRoot = self::encodeUrlPath($root);
            if ($encodedRoot !== $root) {
                if ($path === '') {
                    $scheme = (string)($parts['scheme'] ?? 'https');
                    $host = (string)($parts['host'] ?? '');
                    if ($host !== '') {
                        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';
                        $endpoints[] = $scheme . '://' . $host . $port . '/' . rawurlencode('视频上传') . $suffix;
                    }
                }
                $endpoints[] = $encodedRoot . $suffix;
            }
        }

        return array_values(array_unique(array_filter($endpoints)));
    }

    private static function encodeUrlPath(string $url): string
    {
        $parts = parse_url($url);
        if ($parts === false || empty($parts['host'])) {
            return $url;
        }
        $path = (string)($parts['path'] ?? '');
        if ($path === '' || !preg_match('#[^\x00-\x7F]#', $path)) {
            return $url;
        }
        $segments = array_map(
            static fn (string $segment): string => rawurlencode(rawurldecode($segment)),
            explode('/', trim($path, '/'))
        );
        $encodedPath = '/' . implode('/', array_filter($segments, static fn (string $s): bool => $s !== ''));

        $scheme = (string)($parts['scheme'] ?? 'https');
        $host = (string)$parts['host'];
        $port = isset($parts['port']) ? ':' . (int)$parts['port'] : '';

        return $scheme . '://' . $host . $port . $encodedPath;
    }

    public static function getVideo(PDO $pdo): array
    {
        return [
            'traffic_enabled' => getSetting($pdo, 'upload_video_traffic_enabled', '0') === '1',
            'encryption_enabled' => isSitePlayTokenEnabled($pdo),
        ];
    }

    public static function saveVideo(PDO $pdo, array $data): void
    {
        setSetting($pdo, 'upload_video_traffic_enabled', !empty($data['traffic_enabled']) ? '1' : '0');
        saveSitePlayTokenEnabled($pdo, !empty($data['encryption_enabled']));
    }

    public static function generateApiToken(): string
    {
        return bin2hex(random_bytes(24));
    }
}
