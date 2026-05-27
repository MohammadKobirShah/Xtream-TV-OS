<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — Configuration & Bootstrap
 * ============================================================
 *  Developer        : Kobir Shah
 *  DEVELOPER_CREDIT : Powered by Kobir Shah
 *  Version          : 2.0.0
 *  PHP              : 8.2+
 *
 *  BugFix: Added DEVELOPER_CREDIT constant (was missing).
 *  BugFix: Version bumped to 2.0.0 (was mismatched at 1.0.0).
 *  BugFix: Added FFMPEG_DEFAULT_MODE constant for admin control.
 * ============================================================
 */

declare(strict_types=1);

// ── Timezone ─────────────────────────────────────────────────
date_default_timezone_set('UTC');

// ── Application Constants ─────────────────────────────────────
define('APP_NAME',      'XtreamTV');
define('APP_VERSION',   '2.0.0');                          // FIX: was '1.0.0'
define('APP_AUTHOR',    'Kobir Shah');
define('DEVELOPER_CREDIT', 'Powered by Kobir Shah');       // FIX: was missing entirely
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

// ── Security ─────────────────────────────────────────────────
define('SESSION_NAME',       'xtv_session');
define('SESSION_LIFETIME',   86400 * 7);   // 7 days
define('CSRF_TOKEN_LENGTH',  64);

// ── Stream Proxy ──────────────────────────────────────────────
define('PROXY_MAX_CHUNK',    1048576);     // 1 MB
define('PROXY_TIMEOUT',      30);
define('PROXY_MAX_REDIRECT', 5);

// ── FFmpeg Mode — Admin-Controlled (Pro-Tip #2) ───────────────
// Default: OFF. Admin can toggle per-channel or globally via panel.
// Values: 'off' | 'on' | 'auto' (auto = on if stream fails passthru)
define('FFMPEG_DEFAULT_MODE', 'off');      // NEW: admin panel setting key

// ── Allowed Upstream URL Schemes (SSRF protection) ───────────
define('ALLOWED_SCHEMES', ['http', 'https', 'rtmp', 'rtsp']);

// ── Bootstrap ─────────────────────────────────────────────────
ini_set('display_errors', '0');
ini_set('log_errors', '1');
ini_set('error_log', LOG_PATH . '/php_error.log');
error_reporting(E_ALL);

// ── Ensure storage directories exist ──────────────────────────
foreach ([STORAGE_PATH, LOG_PATH, CACHE_PATH, EPG_PATH] as $dir) {
    if (!is_dir($dir)) {
        mkdir($dir, 0750, true);
    }
}

// ── Autoload (src/ classes) ───────────────────────────────────
spl_autoload_register(function (string $class): void {
    $file = BASE_PATH . '/src/' . str_replace('\\', '/', $class) . '.php';
    if (file_exists($file)) {
        require_once $file;
    }
});

// ── Start Session ─────────────────────────────────────────────
if (session_status() === PHP_SESSION_NONE) {
    session_name(SESSION_NAME);
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'secure'   => isset($_SERVER['HTTPS']),
        'httponly' => true,
        'samesite' => 'Strict',
    ]);
    session_start();
    if (!isset($_SESSION['_initiated'])) {
        session_regenerate_id(true);
        $_SESSION['_initiated'] = true;
    }
}
