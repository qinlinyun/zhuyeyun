<?php

const APP_TIMEZONE = 'Asia/Shanghai';

function initAppTimezone(): void
{
    static $initialized = false;
    if ($initialized) {
        return;
    }

    date_default_timezone_set(APP_TIMEZONE);
    $initialized = true;
}

function chinaNow(string $format = 'Y-m-d H:i:s'): string
{
    initAppTimezone();

    return (new DateTimeImmutable('now', new DateTimeZone(APP_TIMEZONE)))->format($format);
}

function formatChinaDateTime(?string $value, string $format = 'Y-m-d H:i:s'): string
{
    if ($value === null || trim($value) === '') {
        return '-';
    }

    initAppTimezone();

    try {
        $dt = new DateTimeImmutable(trim($value), new DateTimeZone(APP_TIMEZONE));

        return $dt->format($format);
    } catch (Throwable $e) {
        return trim($value);
    }
}

function initDbTimezone(PDO $pdo): void
{
    initAppTimezone();
    $pdo->exec("SET time_zone = '+08:00'");
}
