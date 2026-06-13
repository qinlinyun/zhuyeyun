<?php

declare(strict_types=1);

final class BackendConfig
{
    public static function get(): array
    {
        static $config = null;
        if ($config === null) {
            $defaults = [
                'MAIN_SITE_URL' => '',
                'API_TOKEN' => '',
                'UPLOAD_DOMAIN' => '',
                'VIDEO_DOMAIN' => '',
                'IMAGE_DOMAIN' => '',
                'M3U8_DIR' => 'm3u8',
                'MP4_DIR' => 'mp4',
                'FTP_SOURCE_ROOT' => '',
                'ORIGINALS_DIR' => 'originals',
                'FFMPEG_PATH' => '/usr/bin/ffmpeg',
                'FFPROBE_PATH' => '/usr/bin/ffprobe',
                'VIDEO_SYNC_SECRET' => '',
                'VIDEO_SYNC_PATH_PREFIX' => 'storage/',
                'MAX_UPLOAD_BYTES' => 21474836480,
                'ALLOWED_VIDEO_EXTENSIONS' => ['mp4'],
            ];
            $loaded = is_file(dirname(__DIR__) . '/config.php') ? require dirname(__DIR__) . '/config.php' : [];
            $config = array_replace($defaults, is_array($loaded) ? $loaded : []);
            if (!is_array($config['ALLOWED_VIDEO_EXTENSIONS'])) {
                $config['ALLOWED_VIDEO_EXTENSIONS'] = ['mp4'];
            }
        }

        return $config;
    }

    public static function storageRoot(): string
    {
        return dirname(__DIR__) . DIRECTORY_SEPARATOR . 'storage';
    }

    public static function maxUploadBytes(): int
    {
        $bytes = (int)(self::get()['MAX_UPLOAD_BYTES'] ?? 21474836480);

        return $bytes > 0 ? $bytes : 21474836480;
    }

    /** @return list<string> */
    public static function allowedExtensions(): array
    {
        $extensions = self::get()['ALLOWED_VIDEO_EXTENSIONS'] ?? ['mp4'];
        if (!is_array($extensions)) {
            return ['mp4'];
        }
        $normalized = [];
        foreach ($extensions as $extension) {
            $extension = strtolower(preg_replace('/[^a-z0-9]/', '', (string)$extension));
            if ($extension !== '') {
                $normalized[] = $extension;
            }
        }

        return $normalized !== [] ? array_values(array_unique($normalized)) : ['mp4'];
    }
}
