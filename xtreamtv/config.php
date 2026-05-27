<?php
declare(strict_types=1);

date_default_timezone_set('UTC');

define('APP_NAME',      'XtreamTV');
define('APP_VERSION',   '2.0.1');
define('APP_AUTHOR',    'Kobir Shah');
define('DEVELOPER_CREDIT', 'Powered by Kobir Shah');
define('APP_URL',       rtrim(
    (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost'), '/'
));
define('BASE_PATH',     __DIR__);
define('DB_PATH',       BASE_PATH . '/storage/database.sqlite');
define('STORAGE_PATH',  BASE_PATH . '/storage');
define('LOG_PATH',      BASE_PATH . '/storage/logs');
define('CACHE_PATH',    BASE_PATH . '/storage/cache');
define('EPG_PATH',      BASE_PATH . '/storage/epg');

define('PROXY_MAX_CHUNK',    1048576);
define('PROXY_TIMEOUT',      30);
define('PROXY_MAX_REDIRECT', 5);
define('FFMPEG_DEFAULT_MODE', 'off');
define('ALLOWED_SCHEMES', ['http', 'https', 'rtmp', 'rtsp']);

ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_PATH . '/php_error.log');
error_reporting(E_ALL);

foreach ([STORAGE_PATH, LOG_PATH, CACHE_PATH, EPG_PATH] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
}

spl_autoload_register(function (string $class): void {
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});
