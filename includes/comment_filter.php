<?php

require_once __DIR__ . '/settings.php';

const COMMENT_FILTER_SETTING_KEY = 'comment_filter_config';

function defaultCommentFilterConfig(): array
{
    return [
        'keywords_enabled' => false,
        'keywords' => [],
        'link_block_enabled' => false,
        'link_whitelist' => [],
    ];
}

/** @return list<string> */
function commentFilterParseLines(string $raw): array
{
    $lines = preg_split('/\r\n|\r|\n/', $raw) ?: [];
    $items = [];
    foreach ($lines as $line) {
        $line = trim($line);
        if ($line === '') {
            continue;
        }
        $items[] = $line;
    }

    return array_values(array_unique($items));
}

function normalizeCommentFilterConfig(array $data): array
{
    $defaults = defaultCommentFilterConfig();

    $keywords = $data['keywords'] ?? [];
    if (is_string($keywords)) {
        $keywords = commentFilterParseLines($keywords);
    } elseif (!is_array($keywords)) {
        $keywords = [];
    } else {
        $keywords = commentFilterParseLines(implode("\n", array_map('strval', $keywords)));
    }

    $whitelist = $data['link_whitelist'] ?? [];
    if (is_string($whitelist)) {
        $whitelist = commentFilterParseLines($whitelist);
    } elseif (!is_array($whitelist)) {
        $whitelist = [];
    } else {
        $whitelist = commentFilterParseLines(implode("\n", array_map('strval', $whitelist)));
    }

    return [
        'keywords_enabled' => !empty($data['keywords_enabled']),
        'keywords' => $keywords,
        'link_block_enabled' => !empty($data['link_block_enabled']),
        'link_whitelist' => $whitelist,
    ];
}

function getCommentFilterConfig(PDO $pdo): array
{
    $raw = getSetting($pdo, COMMENT_FILTER_SETTING_KEY, '');
    if ($raw === '') {
        return defaultCommentFilterConfig();
    }

    $data = json_decode($raw, true);

    return is_array($data) ? normalizeCommentFilterConfig($data) : defaultCommentFilterConfig();
}

function saveCommentFilterConfig(PDO $pdo, array $config): void
{
    $normalized = normalizeCommentFilterConfig($config);
    setSetting(
        $pdo,
        COMMENT_FILTER_SETTING_KEY,
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function parseCommentFilterConfigFromPost(array $post): array
{
    return normalizeCommentFilterConfig([
        'keywords_enabled' => isset($post['keywords_enabled']),
        'keywords' => (string)($post['blocked_keywords'] ?? ''),
        'link_block_enabled' => isset($post['link_block_enabled']),
        'link_whitelist' => (string)($post['link_whitelist'] ?? ''),
    ]);
}

function commentExtractLinkHost(string $input): string
{
    $input = trim($input);
    if ($input === '') {
        return '';
    }

    if (!preg_match('#^https?://#i', $input)) {
        $input = 'http://' . ltrim($input, '/');
    }

    $host = parse_url($input, PHP_URL_HOST);

    return is_string($host) ? strtolower($host) : '';
}

function commentLinkHostAllowed(string $host, array $whitelist): bool
{
    $host = strtolower(trim($host));
    if ($host === '') {
        return true;
    }

    foreach ($whitelist as $entry) {
        $allowedHost = commentExtractLinkHost((string)$entry);
        if ($allowedHost === '') {
            continue;
        }
        if ($host === $allowedHost || str_ends_with($host, '.' . $allowedHost)) {
            return true;
        }
    }

    return false;
}

/** @return list<string> */
function commentFindLinkHosts(string $content): array
{
    $hosts = [];

    if (preg_match_all('#(?:https?://|www\.)([^\s<>"\'\)\]]+)#iu', $content, $matches)) {
        foreach ($matches[0] as $url) {
            $host = commentExtractLinkHost($url);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }
    }

    if (preg_match_all(
        '#(?<![@\w./])(?:[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\.)+[a-z]{2,}(?:/[^\s<>"\'\)\]]*)?(?![@\w])#iu',
        $content,
        $domainMatches
    )) {
        foreach ($domainMatches[0] as $domain) {
            $host = commentExtractLinkHost($domain);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }
    }

    return array_values(array_unique($hosts));
}

/** @param list<string> $keywords */
function commentMatchBlockedKeyword(string $content, array $keywords): ?string
{
    foreach ($keywords as $keyword) {
        $keyword = trim($keyword);
        if ($keyword === '') {
            continue;
        }
        if (function_exists('mb_stripos')) {
            if (mb_stripos($content, $keyword, 0, 'UTF-8') !== false) {
                return $keyword;
            }
        } elseif (stripos($content, $keyword) !== false) {
            return $keyword;
        }
    }

    return null;
}

function commentValidateContent(PDO $pdo, string $content): array
{
    $cfg = getCommentFilterConfig($pdo);

    if ($cfg['keywords_enabled'] && $cfg['keywords'] !== []) {
        if (commentMatchBlockedKeyword($content, $cfg['keywords']) !== null) {
            return ['ok' => false, 'message' => '评论包含禁止的关键词'];
        }
    }

    if ($cfg['link_block_enabled']) {
        foreach (commentFindLinkHosts($content) as $host) {
            if (!commentLinkHostAllowed($host, $cfg['link_whitelist'])) {
                return ['ok' => false, 'message' => '评论不允许包含未在白名单中的链接'];
            }
        }
    }

    return ['ok' => true];
}

function commentFilterKeywordsText(array $config): string
{
    return implode("\n", $config['keywords'] ?? []);
}

function commentFilterWhitelistText(array $config): string
{
    return implode("\n", $config['link_whitelist'] ?? []);
}
