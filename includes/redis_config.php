<?php

/**
 * Redis 连接配置（config/redis_config.php 由安装向导或其它设置写入）
 */

function redisConfigFilePath(): string
{
    return dirname(__DIR__) . '/config/redis_config.php';
}

function defaultRedisConfig(): array
{
    return [
        'enabled' => false,
        'host' => '127.0.0.1',
        'port' => 6379,
        'password' => '',
        'database' => 0,
        'prefix' => 'phpy:',
        'publish_throttle_sec' => 15,
    ];
}

function loadRedisConfigArray(): array
{
    if (isset($GLOBALS['_redis_cfg_cache']) && is_array($GLOBALS['_redis_cfg_cache'])) {
        return $GLOBALS['_redis_cfg_cache'];
    }

    $cfg = defaultRedisConfig();
    $file = redisConfigFilePath();
    if (!is_file($file)) {
        $GLOBALS['_redis_cfg_cache'] = $cfg;
        return $cfg;
    }

    require_once $file;

    $cfg['enabled'] = defined('REDIS_ENABLED') && REDIS_ENABLED;
    $cfg['host'] = defined('REDIS_HOST') ? (string)REDIS_HOST : $cfg['host'];
    $cfg['port'] = defined('REDIS_PORT') ? (int)REDIS_PORT : $cfg['port'];
    $cfg['password'] = defined('REDIS_PASSWORD') ? (string)REDIS_PASSWORD : $cfg['password'];
    $cfg['database'] = defined('REDIS_DATABASE') ? (int)REDIS_DATABASE : $cfg['database'];
    $cfg['prefix'] = defined('REDIS_PREFIX') ? (string)REDIS_PREFIX : $cfg['prefix'];
    if (defined('REDIS_PUBLISH_THROTTLE_SEC')) {
        $cfg['publish_throttle_sec'] = (int)REDIS_PUBLISH_THROTTLE_SEC;
    }

    $cfg['port'] = max(1, min(65535, (int)$cfg['port']));
    $cfg['database'] = max(0, min(15, (int)$cfg['database']));
    $cfg['publish_throttle_sec'] = max(5, min(300, (int)$cfg['publish_throttle_sec']));
    if ($cfg['prefix'] === '' || $cfg['prefix'] === ':') {
        $cfg['prefix'] = 'phpy:';
    }
    if (substr($cfg['prefix'], -1) !== ':') {
        $cfg['prefix'] .= ':';
    }

    $GLOBALS['_redis_cfg_cache'] = $cfg;
    return $cfg;
}

function clearRedisConfigCache(): void
{
    unset($GLOBALS['_redis_cfg_cache']);
}

function redisConfigDefineLine(string $name, string $phpValue): string
{
    return 'if (!defined(' . var_export($name, true) . ')) { define('
        . var_export($name, true) . ', ' . $phpValue . "); }\n";
}

function writeRedisConfigFile(array $input): void
{
    $cfg = defaultRedisConfig();
    $cfg['enabled'] = !empty($input['enabled']);
    $cfg['host'] = trim((string)($input['host'] ?? $cfg['host']));
    $cfg['port'] = max(1, min(65535, (int)($input['port'] ?? $cfg['port'])));
    $cfg['password'] = (string)($input['password'] ?? '');
    $cfg['database'] = max(0, min(15, (int)($input['database'] ?? $cfg['database'])));
    $prefix = trim((string)($input['prefix'] ?? $cfg['prefix']));
    if ($prefix === '') {
        $prefix = 'phpy:';
    }
    if (substr($prefix, -1) !== ':') {
        $prefix .= ':';
    }
    $cfg['prefix'] = $prefix;
    $cfg['publish_throttle_sec'] = max(5, min(300, (int)($input['publish_throttle_sec'] ?? 15)));

    if ($cfg['host'] === '') {
        throw new InvalidArgumentException('Redis 主机不能为空');
    }

    $dir = dirname(redisConfigFilePath());
    if (!is_dir($dir) && !mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('无法创建 config 目录');
    }

    $content = "<?php\n";
    $content .= "/** 由安装向导或其它设置自动生成，请勿手动泄露密码 */\n";
    $content .= redisConfigDefineLine('REDIS_ENABLED', $cfg['enabled'] ? 'true' : 'false');
    $content .= redisConfigDefineLine('REDIS_HOST', var_export($cfg['host'], true));
    $content .= redisConfigDefineLine('REDIS_PORT', (string)(int)$cfg['port']);
    $content .= redisConfigDefineLine('REDIS_PASSWORD', var_export($cfg['password'], true));
    $content .= redisConfigDefineLine('REDIS_DATABASE', (string)(int)$cfg['database']);
    $content .= redisConfigDefineLine('REDIS_PREFIX', var_export($cfg['prefix'], true));
    $content .= redisConfigDefineLine('REDIS_PUBLISH_THROTTLE_SEC', (string)(int)$cfg['publish_throttle_sec']);

    if (file_put_contents(redisConfigFilePath(), $content) === false) {
        throw new RuntimeException('无法写入 Redis 配置文件');
    }

    clearRedisConfigCache();
}

function parseRedisConfigFromPost(array $post): array
{
    return [
        'enabled' => isset($post['redis_enabled']),
        'host' => trim((string)($post['redis_host'] ?? '127.0.0.1')),
        'port' => (int)($post['redis_port'] ?? 6379),
        'password' => (string)($post['redis_password'] ?? ''),
        'database' => (int)($post['redis_database'] ?? 0),
        'prefix' => trim((string)($post['redis_prefix'] ?? 'phpy:')),
        'publish_throttle_sec' => (int)($post['redis_publish_throttle_sec'] ?? 15),
    ];
}

function isRedisExtensionLoaded(): bool
{
    return extension_loaded('redis') || class_exists('Redis', false);
}

function isRedisConfiguredEnabled(): bool
{
    $cfg = loadRedisConfigArray();
    return !empty($cfg['enabled']);
}

/**
 * 是否实际可用（已开启 + 扩展已安装）
 */
function isRedisWatchProgressAvailable(): bool
{
    return isRedisConfiguredEnabled() && isRedisExtensionLoaded();
}

function getRedisConnection(): Redis
{
    static $client = null;
    if ($client instanceof Redis) {
        return $client;
    }

    if (!isRedisExtensionLoaded()) {
        throw new RuntimeException('未安装 PHP Redis 扩展（phpredis），无法连接 Redis');
    }

    $cfg = loadRedisConfigArray();
    if (empty($cfg['enabled'])) {
        throw new RuntimeException('Redis 未启用');
    }

    $redis = new Redis();
    $connected = @$redis->connect($cfg['host'], $cfg['port'], 2.0);
    if (!$connected) {
        throw new RuntimeException('无法连接 Redis 服务器');
    }

    if ($cfg['password'] !== '') {
        if (!$redis->auth($cfg['password'])) {
            throw new RuntimeException('Redis 认证失败');
        }
    }

    if ($cfg['database'] > 0) {
        $redis->select($cfg['database']);
    }

    $client = $redis;
    return $client;
}

function getRedisPrefix(): string
{
    return loadRedisConfigArray()['prefix'];
}

function getRedisPublishThrottleSec(): int
{
    return (int)loadRedisConfigArray()['publish_throttle_sec'];
}

/**
 * @return array{ok:bool,message:string,detail?:string}
 */
function testRedisConnection(?array $override = null): array
{
    if (!isRedisExtensionLoaded()) {
        return [
            'ok' => false,
            'message' => '未安装 PHP Redis 扩展',
            'detail' => '请安装并启用 phpredis（pecl install redis 或对应系统包）',
        ];
    }

    $cfg = $override ?? loadRedisConfigArray();
    if (empty($override) && empty($cfg['enabled'])) {
        return ['ok' => false, 'message' => 'Redis 未启用', 'detail' => '可在配置中开启后再测试'];
    }

    try {
        $redis = new Redis();
        $host = $cfg['host'] ?? '127.0.0.1';
        $port = (int)($cfg['port'] ?? 6379);
        if (!@$redis->connect($host, $port, 2.0)) {
            return ['ok' => false, 'message' => '连接失败', 'detail' => "{$host}:{$port}"];
        }
        if (($cfg['password'] ?? '') !== '') {
            if (!$redis->auth($cfg['password'])) {
                return ['ok' => false, 'message' => '认证失败', 'detail' => '请检查密码'];
            }
        }
        $db = (int)($cfg['database'] ?? 0);
        if ($db > 0) {
            $redis->select($db);
        }
        $pong = $redis->ping();
        $info = $redis->info('server');
        $version = is_array($info) ? ($info['redis_version'] ?? '') : '';

        return [
            'ok' => true,
            'message' => '连接成功',
            'detail' => 'PING: ' . (is_string($pong) ? $pong : 'OK') . ($version !== '' ? " · Redis {$version}" : ''),
        ];
    } catch (Throwable $e) {
        return ['ok' => false, 'message' => '连接异常', 'detail' => $e->getMessage()];
    }
}
