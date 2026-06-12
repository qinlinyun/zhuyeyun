<?php

/** 当前是否为后台管理区域（强制浅色，不读用户深色偏好） */
function themeIsAdminArea(): bool
{
    if (isset($GLOBALS['themeForceLight'])) {
        return (bool)$GLOBALS['themeForceLight'];
    }

    $script = str_replace('\\', '/', (string)($_SERVER['SCRIPT_NAME'] ?? $_SERVER['PHP_SELF'] ?? ''));

    return strpos($script, '/admin/') !== false;
}

/** 前台是否应启用深色（未显式选择浅色时默认深色） */
function themeShouldUseDark(): bool
{
    return !themeIsAdminArea();
}
