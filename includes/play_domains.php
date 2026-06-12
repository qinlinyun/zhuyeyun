<?php

/**
 * 用户组可用线路：
 * - group_domains 中勾选的域名；
 * - 整服务器组分配（group_server_groups）：该组下全部域名（含后续新增），与手动勾选合并生效。
 */
function play_domains_for_user_group(
    PDO $pdo,
    int $groupId,
    bool $hasGsgTable,
    bool $domainHasServerGroup,
    bool $filterByVideoSg,
    $videoServerGroupId
): array {
    if ($hasGsgTable && $domainHasServerGroup) {
        $sql = "
            SELECT DISTINCT d.id, d.domain, d.display_name
            FROM domains d
            WHERE (
                EXISTS (SELECT 1 FROM group_domains gd WHERE gd.domain_id = d.id AND gd.group_id = ?)
                OR (
                    d.server_group_id IS NOT NULL
                    AND EXISTS (
                        SELECT 1 FROM group_server_groups gsg
                        WHERE gsg.group_id = ? AND gsg.server_group_id = d.server_group_id
                    )
                )
            )";
        $params = [$groupId, $groupId];
        if ($filterByVideoSg) {
            $sql .= ' AND d.server_group_id = ?';
            $params[] = (int)$videoServerGroupId;
        }
        $sql .= ' ORDER BY d.id';
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll();
    }

    $sql = '
        SELECT d.id, d.domain, d.display_name
        FROM domains d
        JOIN group_domains gd ON d.id = gd.domain_id
        WHERE gd.group_id = ?';
    $params = [$groupId];
    if ($filterByVideoSg && $domainHasServerGroup) {
        $sql .= ' AND d.server_group_id = ?';
        $params[] = (int)$videoServerGroupId;
    }
    $sql .= ' ORDER BY d.id';
    $st = $pdo->prepare($sql);
    $st->execute($params);
    return $st->fetchAll();
}

/** 某服务器分组下的全部线路（管理员在视频指定该组时使用） */
function play_domains_in_server_group(PDO $pdo, int $serverGroupId): array
{
    $st = $pdo->prepare(
        'SELECT d.id, d.domain, d.display_name
         FROM domains d
         WHERE d.server_group_id = ?
         ORDER BY d.id'
    );
    $st->execute([$serverGroupId]);

    return $st->fetchAll();
}

/**
 * @return array{domainHasServerGroup:bool,hasGsgTable:bool,videoServerGroupId:mixed,filterByVideoSg:bool}
 */
function play_video_server_group_context(PDO $pdo, array $video): array
{
    $domainHasServerGroup = (bool)$pdo->query("SHOW COLUMNS FROM domains LIKE 'server_group_id'")->fetch();
    $hasGsgTable = (bool)$pdo->query("SHOW TABLES LIKE 'group_server_groups'")->fetch();
    $videoServerGroupId = $video['server_group_id'] ?? null;
    $filterByVideoSg = $domainHasServerGroup && $videoServerGroupId !== null && $videoServerGroupId !== '';

    return compact('domainHasServerGroup', 'hasGsgTable', 'videoServerGroupId', 'filterByVideoSg');
}

/**
 * 播放页可用线路：普通用户按用户组/服务器组分配；管理员在视频指定服务器组时可用该组下全部线路。
 *
 * @return array<int,array<string,mixed>>
 */
function play_domains_for_playback(PDO $pdo, array $user, array $video, bool $asAdmin): array
{
    $ctx = play_video_server_group_context($pdo, $video);
    $groupId = (int)($user['group_id'] ?? 0);

    if ($asAdmin && $ctx['filterByVideoSg']) {
        return play_domains_in_server_group($pdo, (int)$ctx['videoServerGroupId']);
    }

    $userDomains = play_domains_for_user_group(
        $pdo,
        $groupId,
        $ctx['hasGsgTable'],
        $ctx['domainHasServerGroup'],
        false,
        null
    );

    if (!$asAdmin && $ctx['filterByVideoSg']) {
        $allowedIds = array_flip(array_map('intval', array_column($userDomains, 'id')));
        $sgDomains = play_domains_in_server_group($pdo, (int)$ctx['videoServerGroupId']);
        $domains = [];
        foreach ($sgDomains as $domain) {
            $domainId = (int)$domain['id'];
            if (isset($allowedIds[$domainId])) {
                $domains[] = $domain;
            }
        }
        if ($domains !== []) {
            return $domains;
        }
    }

    if ($ctx['filterByVideoSg']) {
        return play_domains_for_user_group(
            $pdo,
            $groupId,
            $ctx['hasGsgTable'],
            $ctx['domainHasServerGroup'],
            true,
            $ctx['videoServerGroupId']
        );
    }

    return $userDomains;
}

function play_domain_allowed_for_playback(PDO $pdo, array $user, array $video, int $domainId, bool $asAdmin): bool
{
    if ($domainId <= 0) {
        return false;
    }

    $allowedIds = array_map('intval', array_column(
        play_domains_for_playback($pdo, $user, $video, $asAdmin),
        'id'
    ));

    return in_array($domainId, $allowedIds, true);
}

function play_build_group_domain_url(PDO $pdo, array $user, array $video, string $path, bool $asAdmin): string
{
    $path = trim(str_replace('\\', '/', $path));
    if ($path === '') {
        return '';
    }
    if (preg_match('#^https?://#i', $path)) {
        return $path;
    }

    $relativePath = '/' . ltrim($path, '/');
    $domains = play_domains_for_playback($pdo, $user, $video, $asAdmin);
    $domain = trim((string)($domains[0]['domain'] ?? ''));
    if ($domain === '') {
        return $relativePath;
    }

    $domain = preg_replace('#^https?://#i', '', $domain);
    return 'https://' . rtrim((string)$domain, '/') . $relativePath;
}
