<?php
/**
 * 主题动态样式 — 须在页面基础样式（layout.css / 本地 <style>）之后引入，确保覆盖优先级。
 */
if (!function_exists('renderThemeDynamicStyles')) {
    require_once __DIR__ . '/../config/database.php';
    require_once __DIR__ . '/../includes/settings.php';
}
try {
    $themePdo = getDB();
    echo renderThemeDynamicStyles($themePdo);
    if (function_exists('renderFontDynamicOutput')) {
        echo renderFontDynamicOutput($themePdo);
    }
} catch (Throwable $e) {
    // 数据库未就绪时跳过动态主题样式
}
