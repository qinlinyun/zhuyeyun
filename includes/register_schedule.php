<?php

const REGISTER_SCHEDULE_TZ = 'Asia/Shanghai';

function scheduleTimezone(): DateTimeZone
{
    return new DateTimeZone(REGISTER_SCHEDULE_TZ);
}

function defaultRegisterScheduleConfig(): array
{
    $tz = scheduleTimezone();
    $close = (new DateTimeImmutable('tomorrow', $tz))->setTime(22, 0);
    $open = $close->modify('+1 day')->setTime(8, 0);

    return [
        'enabled' => false,
        'close_at' => $close->format('Y-m-d H:i'),
        'open_at' => $open->format('Y-m-d H:i'),
        'auto_closed' => false,
    ];
}

function parseScheduleDatetime(string $value): ?DateTimeImmutable
{
    $value = trim($value);
    if ($value === '') {
        return null;
    }

    $value = str_replace('T', ' ', $value);
    $tz = scheduleTimezone();

    foreach (['Y-m-d H:i:s', 'Y-m-d H:i'] as $format) {
        $dt = DateTimeImmutable::createFromFormat($format, $value, $tz);
        if ($dt instanceof DateTimeImmutable) {
            $errors = DateTimeImmutable::getLastErrors();
            if (empty($errors['warning_count']) && empty($errors['error_count'])) {
                return $dt;
            }
        }
    }

    if (preg_match('/^(\d{1,2}):(\d{2})$/', $value, $matches)) {
        $hour = (int)$matches[1];
        $minute = (int)$matches[2];
        if ($hour > 23 || $minute > 59) {
            return null;
        }

        $now = scheduleNow();
        $today = $now->setTime($hour, $minute, 0);
        if ($today <= $now) {
            $today = $today->modify('+1 day');
        }

        return $today;
    }

    return null;
}

function normalizeScheduleDatetime(string $value, string $fallback): string
{
    $parsed = parseScheduleDatetime($value);
    if ($parsed === null) {
        $parsed = parseScheduleDatetime($fallback);
    }
    if ($parsed === null) {
        $parsed = parseScheduleDatetime(defaultRegisterScheduleConfig()['close_at']);
    }

    return $parsed->format('Y-m-d H:i');
}

function normalizeRegisterScheduleConfig(array $data): array
{
    $defaults = defaultRegisterScheduleConfig();

    $closeRaw = (string)($data['close_at'] ?? $data['close_time'] ?? '');
    $openRaw = (string)($data['open_at'] ?? $data['open_time'] ?? '');

    return [
        'enabled' => !empty($data['enabled']),
        'close_at' => normalizeScheduleDatetime($closeRaw, $defaults['close_at']),
        'open_at' => normalizeScheduleDatetime($openRaw, $defaults['open_at']),
        'auto_closed' => !empty($data['auto_closed']),
    ];
}

function getRegisterScheduleConfig(PDO $pdo): array
{
    $raw = getSetting($pdo, 'register_schedule_config', '');
    if ($raw === '') {
        return defaultRegisterScheduleConfig();
    }

    $data = json_decode($raw, true);
    if (!is_array($data)) {
        return defaultRegisterScheduleConfig();
    }

    return normalizeRegisterScheduleConfig($data);
}

function saveRegisterScheduleConfig(PDO $pdo, array $config): void
{
    $normalized = normalizeRegisterScheduleConfig($config);
    setSetting(
        $pdo,
        'register_schedule_config',
        json_encode($normalized, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)
    );
}

function parseRegisterScheduleConfigFromPost(array $post, ?PDO $pdo = null): array
{
    $defaults = defaultRegisterScheduleConfig();
    $existing = $pdo ? getRegisterScheduleConfig($pdo) : [];

    return normalizeRegisterScheduleConfig([
        'enabled' => isset($post['schedule_enabled']),
        'close_at' => $post['close_at'] ?? $defaults['close_at'],
        'open_at' => $post['open_at'] ?? $defaults['open_at'],
        'auto_closed' => $existing['auto_closed'] ?? false,
    ]);
}

function disableRegisterSchedule(PDO $pdo, bool $clearAutoClosed = true): void
{
    $config = getRegisterScheduleConfig($pdo);
    if (empty($config['enabled']) && (!$clearAutoClosed || empty($config['auto_closed']))) {
        return;
    }

    $config['enabled'] = false;
    if ($clearAutoClosed) {
        $config['auto_closed'] = false;
    }
    saveRegisterScheduleConfig($pdo, $config);
}

function registerScheduleValidationError(array $config): ?string
{
    if (empty($config['enabled'])) {
        return null;
    }

    $closeAt = parseScheduleDatetime($config['close_at']);
    $openAt = parseScheduleDatetime($config['open_at']);

    if ($closeAt === null || $openAt === null) {
        return '请填写有效的关闭时间与开启时间（年月日时分，UTC+8）';
    }

    if ($openAt <= $closeAt) {
        return '开启时间必须晚于关闭时间';
    }

    return null;
}

function isRegisterScheduleActive(array $config): bool
{
    if (empty($config['enabled'])) {
        return false;
    }

    return registerScheduleValidationError($config) === null;
}

function scheduleNow(?DateTimeInterface $now = null): DateTimeImmutable
{
    if ($now instanceof DateTimeImmutable) {
        return $now->setTimezone(scheduleTimezone());
    }

    if ($now instanceof DateTimeInterface) {
        return DateTimeImmutable::createFromInterface($now)->setTimezone(scheduleTimezone());
    }

    return new DateTimeImmutable('now', scheduleTimezone());
}

function getRegisterScheduleCloseDatetime(array $config): ?DateTimeImmutable
{
    return parseScheduleDatetime($config['close_at'] ?? '');
}

function getRegisterScheduleOpenDatetime(array $config): ?DateTimeImmutable
{
    return parseScheduleDatetime($config['open_at'] ?? '');
}

function isRegisterScheduleClosed(array $config, ?DateTimeInterface $now = null): bool
{
    if (!isRegisterScheduleActive($config)) {
        return false;
    }

    $closeAt = getRegisterScheduleCloseDatetime($config);
    $openAt = getRegisterScheduleOpenDatetime($config);
    if ($closeAt === null || $openAt === null) {
        return false;
    }

    $now = scheduleNow($now);

    return $now >= $closeAt && $now < $openAt;
}

function getRegisterScheduleReopenTimestamp(array $config, ?DateTimeInterface $now = null): ?int
{
    if (!isRegisterScheduleClosed($config, $now)) {
        return null;
    }

    $openAt = getRegisterScheduleOpenDatetime($config);
    if ($openAt === null) {
        return null;
    }

    return $openAt->getTimestamp();
}

function applyRegisterSchedule(PDO $pdo): void
{
    static $applying = false;
    if ($applying) {
        return;
    }
    $applying = true;

    $config = getRegisterScheduleConfig($pdo);
    if (!isRegisterScheduleActive($config)) {
        $applying = false;
        return;
    }

    $closeAt = getRegisterScheduleCloseDatetime($config);
    $openAt = getRegisterScheduleOpenDatetime($config);
    if ($closeAt === null || $openAt === null) {
        $applying = false;
        return;
    }

    $now = scheduleNow();
    $registerOn = getSetting($pdo, 'register_enabled', '1') === '1';
    $changed = false;

    if ($now >= $closeAt && $now < $openAt) {
        if ($registerOn) {
            setSetting($pdo, 'register_enabled', '0');
            $config['auto_closed'] = true;
            $changed = true;
        }
    } elseif ($now >= $openAt) {
        if (!empty($config['auto_closed']) && !$registerOn) {
            setSetting($pdo, 'register_enabled', '1');
            $registerOn = true;
        }
        if (!empty($config['auto_closed'])) {
            $config['auto_closed'] = false;
            $changed = true;
        }
    } elseif ($now < $closeAt && !empty($config['auto_closed'])) {
        $config['auto_closed'] = false;
        $changed = true;
    }

    if ($changed) {
        saveRegisterScheduleConfig($pdo, $config);
    }

    $applying = false;
}

function formatScheduleCountdown(int $seconds): string
{
    $seconds = max(0, $seconds);
    $days = intdiv($seconds, 86400);
    $seconds %= 86400;
    $hours = intdiv($seconds, 3600);
    $seconds %= 3600;
    $minutes = intdiv($seconds, 60);
    $secs = $seconds % 60;

    $parts = [];
    if ($days > 0) {
        $parts[] = $days . '天';
    }
    if ($hours > 0 || $days > 0) {
        $parts[] = $hours . '小时';
    }
    if ($minutes > 0 || $hours > 0 || $days > 0) {
        $parts[] = $minutes . '分钟';
    }
    $parts[] = $secs . '秒';

    return implode('', $parts);
}

function formatScheduleDatetimeDisplay(string $value): string
{
    $dt = parseScheduleDatetime($value);
    if ($dt === null) {
        return $value;
    }

    return $dt->format('Y-m-d H:i') . ' (UTC+8)';
}

function scheduleDatetimeToInput(string $value): string
{
    $dt = parseScheduleDatetime($value);
    if ($dt === null) {
        return '';
    }

    return $dt->format('Y-m-d\TH:i');
}

function registerScheduleStatusText(array $config, ?DateTimeInterface $now = null): string
{
    if (!isRegisterScheduleActive($config)) {
        return '未启用';
    }

    $closeAt = getRegisterScheduleCloseDatetime($config);
    $openAt = getRegisterScheduleOpenDatetime($config);
    $now = scheduleNow($now);

    if ($closeAt === null || $openAt === null) {
        return '配置无效';
    }

    if (isRegisterScheduleClosed($config, $now)) {
        $reopenAt = getRegisterScheduleReopenTimestamp($config, $now);
        if ($reopenAt === null) {
            return '当前处于关闭时段，注册开关已自动关闭';
        }

        $remaining = max(0, $reopenAt - $now->getTimestamp());
        return '当前处于关闭时段，注册开关已自动关闭，距离开放还有 ' . formatScheduleCountdown($remaining);
    }

    if ($now < $closeAt) {
        $remaining = max(0, $closeAt->getTimestamp() - $now->getTimestamp());
        return '当前开放，将于 ' . formatScheduleDatetimeDisplay($config['close_at']) . ' 自动关闭注册开关（还有 ' . formatScheduleCountdown($remaining) . '）';
    }

    return '定时关闭已结束，当前开放';
}

function registerSchedulePeriodLabel(array $config): string
{
    $closeAt = getRegisterScheduleCloseDatetime($config);
    $openAt = getRegisterScheduleOpenDatetime($config);

    if ($closeAt === null || $openAt === null) {
        return '';
    }

    return formatScheduleDatetimeDisplay($config['close_at']) . ' 至 ' . formatScheduleDatetimeDisplay($config['open_at']) . ' 暂停注册';
}
