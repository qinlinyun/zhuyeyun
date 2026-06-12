<?php
/** 由安装向导或其它设置自动生成，请勿手动泄露密码 */
if (!defined('REDIS_ENABLED')) { define('REDIS_ENABLED', true); }
if (!defined('REDIS_HOST')) { define('REDIS_HOST', '127.0.0.1'); }
if (!defined('REDIS_PORT')) { define('REDIS_PORT', 6379); }
if (!defined('REDIS_PASSWORD')) { define('REDIS_PASSWORD', ''); }
if (!defined('REDIS_DATABASE')) { define('REDIS_DATABASE', 0); }
if (!defined('REDIS_PREFIX')) { define('REDIS_PREFIX', 'phpy:'); }
if (!defined('REDIS_PUBLISH_THROTTLE_SEC')) { define('REDIS_PUBLISH_THROTTLE_SEC', 15); }
