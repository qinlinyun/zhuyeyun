<?php

declare(strict_types=1);

require_once __DIR__ . '/upload_config.php';

/** @return list<array<string, mixed>> */
function uploadMenuItems(): array
{
    static $menu = null;
    if ($menu === null) {
        $menu = require __DIR__ . '/upload_menu.php';
    }

    return $menu;
}

/**
 * @return array{section: string, item: string, group: ?array, child: ?array, title: string, description: string}
 */
function uploadMenuResolve(string $section, string $item): array
{
    $menu = uploadMenuItems();
    $section = trim($section);
    $item = trim($item);

    $legacyMap = [
        'backend_config' => 'infrastructure',
        'review' => 'content',
        'video_config' => 'settings',
        'uploaded_videos' => 'content',
    ];
    $legacyItemMap = [
        'mode' => 'php',
        'ftp' => 'php',
        'domain_assign' => 'domains',
        'health' => 'php',
        'install' => 'api',
    ];
    if (isset($legacyMap[$section])) {
        $section = $legacyMap[$section];
    }
    if (isset($legacyItemMap[$item])) {
        $item = $legacyItemMap[$item];
    }
    if ($section === 'content' && $item === '') {
        $item = 'review';
    }
    if ($section === 'settings' && $item === 'video_config') {
        $item = 'video';
    }
    if ($section === 'content' && $item === 'uploaded_videos') {
        $item = 'published';
    }
    if ($section === 'infrastructure' && ($item === 'mode' || $item === 'ftp')) {
        $item = 'php';
    }

    $group = null;
    $child = null;
    foreach ($menu as $entry) {
        if ((string)($entry['id'] ?? '') === $section) {
            $group = $entry;
            break;
        }
    }

    if ($group === null) {
        $section = 'overview';
        foreach ($menu as $entry) {
            if ((string)($entry['id'] ?? '') === $section) {
                $group = $entry;
                break;
            }
        }
    }

    $children = is_array($group['children'] ?? null) ? $group['children'] : [];
    if ($children !== []) {
        if ($item === '') {
            $child = $children[0];
            $item = (string)($child['id'] ?? '');
        } else {
            foreach ($children as $c) {
                if ((string)($c['id'] ?? '') === $item) {
                    $child = $c;
                    break;
                }
            }
            if ($child === null) {
                $child = $children[0];
                $item = (string)($child['id'] ?? '');
            }
        }
    }

    return [
        'section' => $section,
        'item' => $item,
        'group' => $group,
        'child' => $child,
        'title' => (string)($child['label'] ?? $group['label'] ?? '上传管理'),
        'description' => (string)($child['description'] ?? $group['description'] ?? ''),
    ];
}

/** @return list<string> */
function uploadMenuSectionIds(): array
{
    return array_values(array_map(static fn (array $g) => (string)$g['id'], uploadMenuItems()));
}

function uploadMenuUrl(string $section, string $item = ''): string
{
    $params = ['section' => $section];
    if ($item !== '') {
        $params['item'] = $item;
    }

    return '?' . http_build_query($params);
}

function uploadMenuPendingCount(PDO $pdo): int
{
    ensureUserVideoUploadsTable($pdo);
    $stmt = $pdo->query('SELECT COUNT(*) FROM user_video_uploads WHERE status = "pending"');

    return (int)$stmt->fetchColumn();
}

/** @return array<string, mixed> */
function uploadMenuOverviewStats(PDO $pdo): array
{
    ensureUserVideoUploadsTable($pdo);
    $php = getUploadPhpConfig($pdo);
    $api = getUploadApiConfig($pdo);

    $counts = $pdo->query('
        SELECT
            SUM(status = "pending") AS pending,
            SUM(status = "approved") AS approved,
            SUM(status = "rejected") AS rejected,
            COUNT(*) AS total
        FROM user_video_uploads
    ')->fetch() ?: [];

    return [
        'mode' => 'php',
        'mode_label' => 'PHP 上传',
        'pending' => (int)($counts['pending'] ?? 0),
        'approved' => (int)($counts['approved'] ?? 0),
        'rejected' => (int)($counts['rejected'] ?? 0),
        'total' => (int)($counts['total'] ?? 0),
        'php_ready' => isUploadPhpReady($pdo),
        'user_subdir' => (string)($php['user_subdir'] ?? 'users'),
        'max_upload_mb' => (int)($php['max_upload_mb'] ?? 2048),
        'transcode_backend' => UploadConfig::hasTranscodeBackend($api),
        'backend_url' => (string)($api['remote_backend_url'] ?? ''),
    ];
}

/** @return list<array{label: string, ok: bool, detail: string}> */
function uploadMenuHealthChecks(PDO $pdo): array
{
    $api = getUploadApiConfig($pdo);
    $php = getUploadPhpConfig($pdo);
    $checks = [];

    $checks[] = [
        'label' => '主站 curl（可选）',
        'ok' => function_exists('curl_init'),
        'detail' => function_exists('curl_init') ? '已启用（用于测试远程后端连通）' : '测试连通需要 curl',
    ];

    $checks[] = [
        'label' => '转码后端配置',
        'ok' => UploadConfig::hasTranscodeBackend($api),
        'detail' => UploadConfig::hasTranscodeBackend($api)
            ? (string)($api['remote_backend_url'] ?? '')
            : '请填写远程后端地址与 API Token',
    ];

    if (UploadConfig::hasTranscodeBackend($api)) {
        $test = testUploadBackendConnection($pdo);
        $checks[] = [
            'label' => '远程后端连通',
            'ok' => !empty($test['ok']),
            'detail' => !empty($test['ok'])
                ? (string)($test['message'] ?? '连接成功')
                : (string)($test['error'] ?? '连接失败'),
        ];
    }

    $checks[] = [
        'label' => '用户目录规则',
        'ok' => trim((string)($php['user_subdir'] ?? '')) !== '',
        'detail' => (string)($php['user_subdir'] ?? 'users') . '/{用户ID}/{10位目录}/文件名.mp4',
    ];

    return $checks;
}

function uploadMenuIconSvg(string $icon): string
{
    return match ($icon) {
        'overview' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zm10 0a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"/>',
        'server' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M5 12h14M5 12a2 2 0 01-2-2V6a2 2 0 012-2h14a2 2 0 012 2v4a2 2 0 01-2 2M5 12a2 2 0 00-2 2v4a2 2 0 002 2h14a2 2 0 002-2v-4a2 2 0 00-2-2m-7-4h.01M12 16h.01"/>',
        'content' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 10l4.553-2.276A1 1 0 0121 8.618v6.764a1 1 0 01-1.447.894L15 14M5 18h8a2 2 0 002-2V8a2 2 0 00-2-2H5a2 2 0 00-2 2v8a2 2 0 002 2z"/>',
        'settings' => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.066 2.573c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.573 1.066c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.066-2.573c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"/><path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"/>',
        default => '<path stroke-linecap="round" stroke-linejoin="round" stroke-width="1.75" d="M13 16h-1v-4h-1m1-4h.01M21 12a9 9 0 11-18 0 9 9 0 0118 0z"/>',
    };
}
