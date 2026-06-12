<?php

require_once __DIR__ . '/settings.php';
require_once __DIR__ . '/player_proxy.php';

const PLAYER_SETTING_ENGINE = 'player_engine';
const PLAYER_SETTING_TOKEN_AUTO_DURATION = 'player_token_auto_duration';
const PLAYER_SETTING_ANTI_DOWNLOAD = 'player_anti_download';

function defaultPlayerConfig(): array
{
    return [
        'engine' => 'videojs',
        'token_auto_duration' => false,
        'anti_download' => false,
    ];
}

function getPlayerConfig(PDO $pdo): array
{
    $cfg = defaultPlayerConfig();
    $engine = trim((string)getSetting($pdo, PLAYER_SETTING_ENGINE, 'videojs'));
    $cfg['engine'] = in_array($engine, ['videojs', 'dplayer', 'xgplayer'], true) ? $engine : 'videojs';
    $cfg['token_auto_duration'] = getSetting($pdo, PLAYER_SETTING_TOKEN_AUTO_DURATION, '0') === '1';
    $cfg['anti_download'] = getSetting($pdo, PLAYER_SETTING_ANTI_DOWNLOAD, '0') === '1';

    return $cfg;
}

function savePlayerConfig(PDO $pdo, array $config): void
{
    $engine = trim((string)($config['engine'] ?? 'videojs'));
    if (!in_array($engine, ['videojs', 'dplayer', 'xgplayer'], true)) {
        $engine = 'videojs';
    }
    setSetting($pdo, PLAYER_SETTING_ENGINE, $engine);
    setSetting($pdo, PLAYER_SETTING_TOKEN_AUTO_DURATION, !empty($config['token_auto_duration']) ? '1' : '0');
    setSetting($pdo, PLAYER_SETTING_ANTI_DOWNLOAD, !empty($config['anti_download']) ? '1' : '0');
}

function playerConfigValidationError(PDO $pdo, array $config): ?string
{
    $needsProxy = !empty($config['token_auto_duration']) || !empty($config['anti_download']);
    if ($needsProxy && !isPlayerProxyEnabled($pdo)) {
        return 'Token 时效（2×时长）与防下载功能需先开启「后端代理」并完成配置';
    }

    return null;
}

/**
 * 将播放策略同步到视频切片后端
 *
 * @return array{ok:bool,message?:string}
 */
function pushPlayerPolicyToSliceBackend(PDO $pdo): array
{
    $proxyCfg = getPlayerProxyConfig($pdo);
    if ($proxyCfg['backend_urls'] === [] || $proxyCfg['api_secret'] === '') {
        return ['ok' => false, 'message' => '未配置视频后端地址或密钥'];
    }

    $playerCfg = getPlayerConfig($pdo);
    $exp = time() + 300;
    $payload = [
        'proxy_enabled' => isPlayerProxyEnabled($pdo) ? '1' : '0',
        'anti_download' => $playerCfg['anti_download'] ? '1' : '0',
        'token_auto_duration' => $playerCfg['token_auto_duration'] ? '1' : '0',
        'exp' => $exp,
    ];
    $payload['sign'] = hash_hmac(
        'sha256',
        $payload['proxy_enabled'] . '|' . $payload['anti_download'] . '|' . $payload['token_auto_duration'] . '|' . $exp,
        $proxyCfg['api_secret']
    );

    $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
    $result = postJsonToPlayerBackends($proxyCfg['backend_urls'], (string)$body);
    if (!$result['ok']) {
        return ['ok' => false, 'message' => $result['message']];
    }

    return ['ok' => true, 'message' => $result['message']];
}

function resolvePlayTokenTtl(PDO $pdo, int $fixedTtl): array
{
    $cfg = getPlayerConfig($pdo);
    if ($cfg['token_auto_duration'] && isPlayerProxyEnabled($pdo)) {
        return ['auto_ttl' => true, 'ttl' => $fixedTtl];
    }

    return ['auto_ttl' => false, 'ttl' => $fixedTtl];
}
