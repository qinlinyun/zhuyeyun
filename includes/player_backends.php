<?php

require_once __DIR__ . '/settings.php';

/**
 * @param array<int,array{name?:string,url?:string}> $entries
 * @return array<int,array{name:string,url:string}>
 */
function normalizePlayerBackendEntries(array $entries, bool $dropEmpty = true): array
{
    $out = [];
    $seen = [];

    foreach ($entries as $entry) {
        if (!is_array($entry)) {
            continue;
        }
        $name = trim((string)($entry['name'] ?? ''));
        $url = normalizePlayerBackendBaseUrl(trim((string)($entry['url'] ?? '')));
        if ($url === '') {
            if (!$dropEmpty) {
                $out[] = ['name' => $name, 'url' => ''];
            }
            continue;
        }
        if (isset($seen[$url])) {
            continue;
        }
        $seen[$url] = true;
        $out[] = ['name' => $name, 'url' => $url];
    }

    return $out;
}

/**
 * @return array<int,array{name:string,url:string}>
 */
function loadPlayerBackendEntries(PDO $pdo, string $jsonSettingKey, string $legacySettingKey): array
{
    $raw = trim((string)getSetting($pdo, $jsonSettingKey, ''));
    if ($raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) {
            $entries = normalizePlayerBackendEntries($decoded);
            if ($entries !== []) {
                return $entries;
            }
        }
    }

    $legacy = normalizePlayerBackendBaseUrl(trim((string)getSetting($pdo, $legacySettingKey, '')));
    if ($legacy === '') {
        return [];
    }

    return [['name' => '', 'url' => $legacy]];
}

function savePlayerBackendEntries(PDO $pdo, string $jsonSettingKey, string $legacySettingKey, array $entries): void
{
    $entries = normalizePlayerBackendEntries($entries);
    setSetting($pdo, $jsonSettingKey, json_encode($entries, JSON_UNESCAPED_UNICODE));
    setSetting($pdo, $legacySettingKey, $entries[0]['url'] ?? '');
}

/**
 * @return array<int,array{name:string,url:string}>
 */
function playerBackendEntriesFromPost($names, $urls): array
{
    $names = is_array($names) ? $names : [];
    $urls = is_array($urls) ? $urls : [];
    $count = max(count($names), count($urls));
    $entries = [];

    for ($i = 0; $i < $count; $i++) {
        $entries[] = [
            'name' => trim((string)($names[$i] ?? '')),
            'url' => trim((string)($urls[$i] ?? '')),
        ];
    }

    return $entries;
}

/**
 * @param array<int,array{name:string,url:string}> $entries
 * @return string[]
 */
function playerBackendUrlsOnly(array $entries): array
{
    return array_values(array_map(static function (array $entry): string {
        return $entry['url'];
    }, $entries));
}

function firstPlayerBackendUrl(array $entries): string
{
    return $entries[0]['url'] ?? '';
}

function playerBackendValidationError(array $entries, string $emptyMessage): ?string
{
    if ($entries === []) {
        return $emptyMessage;
    }
    foreach ($entries as $entry) {
        $url = $entry['url'];
        if (!preg_match('#^https?://#i', $url)) {
            $label = $entry['name'] !== '' ? '「' . $entry['name'] . '」' : '后端地址';
            return $label . '需以 http:// 或 https:// 开头';
        }
    }

    return null;
}
