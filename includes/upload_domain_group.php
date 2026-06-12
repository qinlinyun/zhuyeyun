<?php

declare(strict_types=1);

require_once __DIR__ . '/settings.php';

const UPLOAD_DOMAIN_SERVER_GROUP_SETTING = 'upload_domain_server_group_id';

function uploadDomainGroupFeatureEnabled(PDO $pdo): bool
{
    return (bool)$pdo->query("SHOW TABLES LIKE 'server_groups'")->fetch()
        && (bool)$pdo->query("SHOW COLUMNS FROM domains LIKE 'server_group_id'")->fetch();
}

function getUploadDomainServerGroupId(PDO $pdo): ?int
{
    if (!uploadDomainGroupFeatureEnabled($pdo)) {
        return null;
    }

    $raw = trim((string)(getSetting($pdo, UPLOAD_DOMAIN_SERVER_GROUP_SETTING, '') ?? ''));
    if ($raw === '' || !ctype_digit($raw)) {
        return null;
    }

    $id = (int)$raw;
    if ($id <= 0) {
        return null;
    }

    $stmt = $pdo->prepare('SELECT id FROM server_groups WHERE id = ? LIMIT 1');
    $stmt->execute([$id]);

    return $stmt->fetchColumn() ? $id : null;
}

function setUploadDomainServerGroupId(PDO $pdo, ?int $serverGroupId): void
{
    if ($serverGroupId === null || $serverGroupId <= 0) {
        setSetting($pdo, UPLOAD_DOMAIN_SERVER_GROUP_SETTING, '');

        return;
    }

    setSetting($pdo, UPLOAD_DOMAIN_SERVER_GROUP_SETTING, (string)$serverGroupId);
}

/** @return list<array<string, mixed>> */
function fetchUploadPoolDomains(PDO $pdo): array
{
    if (!(bool)$pdo->query("SHOW TABLES LIKE 'domains'")->fetch()) {
        return [];
    }

    $sgId = getUploadDomainServerGroupId($pdo);
    if ($sgId === null) {
        return [];
    }

    $stmt = $pdo->prepare('SELECT id, domain, display_name FROM domains WHERE server_group_id = ? ORDER BY id');
    $stmt->execute([$sgId]);

    return $stmt->fetchAll() ?: [];
}

function isDomainInUploadPool(PDO $pdo, int $domainId): bool
{
    if ($domainId <= 0) {
        return false;
    }

    $sgId = getUploadDomainServerGroupId($pdo);
    if ($sgId === null) {
        return false;
    }

    $stmt = $pdo->prepare('SELECT id FROM domains WHERE id = ? AND server_group_id = ? LIMIT 1');
    $stmt->execute([$domainId, $sgId]);

    return (bool)$stmt->fetchColumn();
}

/**
 * 上传审核同步（record_id 为 upload_*）优先使用上传域名组；否则回退到视频同步配置中的默认组。
 */
function resolveVideoDefaultServerGroupId(PDO $pdo, string $recordId, ?int $syncConfigServerGroupId = null): ?int
{
    $recordId = trim($recordId);
    if ($recordId !== '' && strncmp($recordId, 'upload_', 7) === 0) {
        $uploadSg = getUploadDomainServerGroupId($pdo);
        if ($uploadSg !== null) {
            return $uploadSg;
        }
    }

    if ($syncConfigServerGroupId !== null && $syncConfigServerGroupId > 0) {
        return $syncConfigServerGroupId;
    }

    return null;
}

/** @param list<int|string> $domainIds @return list<int> */
function filterUploadPoolDomainIds(PDO $pdo, array $domainIds): array
{
    $allowed = [];
    foreach ($domainIds as $domainId) {
        $domainId = (int)$domainId;
        if ($domainId > 0 && isDomainInUploadPool($pdo, $domainId)) {
            $allowed[] = $domainId;
        }
    }

    return array_values(array_unique($allowed));
}
