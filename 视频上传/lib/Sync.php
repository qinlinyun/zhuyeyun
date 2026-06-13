<?php

declare(strict_types=1);

final class BackendSync
{
    public static function sign(string $secret, string $recordId, string $title, string $m3u8Url, string $coverUrl, int $exp): string
    {
        return hash_hmac('sha256', $recordId . '|' . $title . '|' . $m3u8Url . '|' . $coverUrl . '|' . $exp, $secret);
    }

    public static function pushToMainSite(array $record): array
    {
        $config = BackendConfig::get();
        $mainSite = rtrim((string)($config['MAIN_SITE_URL'] ?? ''), '/');
        $secret = trim((string)($config['VIDEO_SYNC_SECRET'] ?? ''));
        if ($mainSite === '') {
            return ['ok' => false, 'error' => '未配置 MAIN_SITE_URL'];
        }
        if ($secret === '') {
            return ['ok' => false, 'error' => '未配置 VIDEO_SYNC_SECRET'];
        }

        $exp = time() + 300;
        $payload = [
            'record_id' => (string)$record['record_id'],
            'title' => (string)$record['title'],
            'm3u8_url' => (string)$record['m3u8_url'],
            'cover_url' => (string)$record['cover_url'],
            'description' => (string)($record['description'] ?? ''),
            'uploader' => (string)($record['uploader'] ?? ''),
            'user_id' => (int)($record['user_id'] ?? 0),
            'episode_name' => '1',
            'exp' => $exp,
            'sign' => self::sign(
                $secret,
                (string)$record['record_id'],
                (string)$record['title'],
                (string)$record['m3u8_url'],
                (string)$record['cover_url'],
                $exp
            ),
        ];

        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return ['ok' => false, 'error' => '生成同步请求失败'];
        }

        $url = $mainSite . '/api/video_data_sync.php';
        if (!function_exists('curl_init')) {
            return ['ok' => false, 'error' => '服务器未启用 curl'];
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT => 300,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json', 'Accept: application/json'],
        ]);
        $response = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($response === false) {
            return ['ok' => false, 'error' => '无法连接主站：' . $error];
        }

        $data = json_decode((string)$response, true);
        if (!is_array($data)) {
            return ['ok' => false, 'error' => '主站返回格式错误（HTTP ' . $status . '）'];
        }
        if ($status >= 400 && empty($data['ok'])) {
            return ['ok' => false, 'error' => (string)($data['message'] ?? $data['error'] ?? '同步失败')];
        }

        return [
            'ok' => true,
            'message' => (string)($data['message'] ?? '同步成功'),
            'video_id' => isset($data['video_id']) ? (int)$data['video_id'] : 0,
            'episode_id' => isset($data['episode_id']) ? (int)$data['episode_id'] : 0,
        ];
    }
}
