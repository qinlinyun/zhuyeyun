<?php

declare(strict_types=1);

function emailResetCdnBase(): string
{
    return 'https://css.qinlinyun.cn';
}

function emailResetStylesheetUrl(bool $useCdn): string
{
    $version = '1';

    if ($useCdn) {
        $path = ltrim('assets/css/email-reset.css', '/');
        $url = rtrim(emailResetCdnBase(), '/') . '/' . $path;

        return $url . '?v=' . rawurlencode($version);
    }

    return 'assets/css/email-reset.css?v=' . rawurlencode($version);
}
