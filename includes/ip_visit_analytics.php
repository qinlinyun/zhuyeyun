<?php

require_once __DIR__ . '/analytics_schema.php';
require_once __DIR__ . '/analytics_config.php';

function getClientIp(): string
{
    $keys = ['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'HTTP_X_REAL_IP', 'REMOTE_ADDR'];
    foreach ($keys as $key) {
        if (empty($_SERVER[$key])) {
            continue;
        }
        $raw = (string)$_SERVER[$key];
        $ip = trim(explode(',', $raw)[0]);
        if (filter_var($ip, FILTER_VALIDATE_IP)) {
            return $ip;
        }
    }
    return '0.0.0.0';
}

function resolveIpLocation(string $ip): string
{
    if (!filter_var($ip, FILTER_VALIDATE_IP)) {
        return '未知';
    }

    if (!filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
        return '本地/内网';
    }

    $ctx = stream_context_create([
        'http' => [
            'timeout' => 2,
            'ignore_errors' => true,
            'header' => "User-Agent: Zhuyeyun-Analytics/1.0\r\n",
        ],
    ]);
    $url = 'http://ip-api.com/json/' . rawurlencode($ip) . '?lang=zh-CN&fields=status,country,regionName,city';
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false) {
        return '未知';
    }

    $data = json_decode($body, true);
    if (!is_array($data) || ($data['status'] ?? '') !== 'success') {
        return '未知';
    }

    $parts = array_filter([
        (string)($data['country'] ?? ''),
        (string)($data['regionName'] ?? ''),
        (string)($data['city'] ?? ''),
    ], static function ($part) {
        return $part !== '';
    });

    return empty($parts) ? '未知' : implode(' ', $parts);
}

function recordIpVisit(?string $username = null): void
{
    if (!isAnalyticsIpEnabled()) {
        return;
    }

    $ip = getClientIp();
    if ($ip === '0.0.0.0') {
        return;
    }

    $username = $username !== null ? trim($username) : null;
    if ($username === '') {
        $username = null;
    }

    $pdo = analyticsDb();
    maybeCleanupAnalyticsIpVisits($pdo);
    $now = date('Y-m-d H:i:s');

    $stmt = $pdo->prepare('SELECT id, location, visits FROM analytics_ip_visits WHERE ip = ? LIMIT 1');
    $stmt->execute([$ip]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($row) {
        $location = trim((string)($row['location'] ?? ''));
        if ($location === '' || $location === '未知') {
            $location = resolveIpLocation($ip);
        }

        $update = $pdo->prepare("
            UPDATE analytics_ip_visits
            SET visits = visits + 1,
                last_visit_at = ?,
                location = ?,
                username = COALESCE(?, username)
            WHERE ip = ?
        ");
        $update->execute([$now, $location, $username, $ip]);
        return;
    }

    $insert = $pdo->prepare("
        INSERT INTO analytics_ip_visits (ip, location, username, visits, first_visit_at, last_visit_at)
        VALUES (?, ?, ?, 1, ?, ?)
    ");
    $insert->execute([$ip, resolveIpLocation($ip), $username, $now, $now]);
}

function getIpVisitRanking(): array
{
    $pdo = analyticsDb();
    $limit = getAnalyticsRankingLimit($pdo);
    $stmt = $pdo->prepare("
        SELECT ip, location, username, visits
        FROM analytics_ip_visits
        ORDER BY visits DESC, last_visit_at DESC
        LIMIT ?
    ");
    $stmt->bindValue(1, $limit, PDO::PARAM_INT);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

    $ranking = [];
    $rank = 1;
    foreach ($rows as $meta) {
        $username = trim((string)($meta['username'] ?? ''));
        $ranking[] = [
            'rank' => $rank++,
            'ip' => (string)($meta['ip'] ?? ''),
            'location' => (string)($meta['location'] ?? '未知'),
            'visits' => (int)($meta['visits'] ?? 0),
            'username' => $username,
        ];
    }

    return $ranking;
}

function trackCurrentIpVisit(): void
{
    if (PHP_SAPI === 'cli' || defined('IP_VISIT_RECORDED')) {
        return;
    }

    if (!isAnalyticsIpEnabled() || !isAnalyticsKeyPageRequest()) {
        return;
    }

    if (!shouldRecordIpVisitThisRequest()) {
        return;
    }

    define('IP_VISIT_RECORDED', true);

    $username = null;
    if (isset($_SESSION['user_id'], $_SESSION['username']) && $_SESSION['username'] !== '') {
        $username = (string)$_SESSION['username'];
    }

    recordIpVisit($username);
}
