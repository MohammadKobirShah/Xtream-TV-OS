<?php
/**
 * ============================================================
 *  XtreamTV — Xtream Codes API Compatibility Layer
 *  Compatible with: TiviMate, IPTV Smarters, GSE, Perfect Player
 *  Developer: Kobir Shah
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/XtreamAPI.php';

// Log console credit
error_log('[XtreamTV][API] Request from ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ' — Credit: Kobir Shah');

// ── Authenticate ──────────────────────────────────────────
$username = trim($_GET['username'] ?? $_POST['username'] ?? '');
$password = trim($_GET['password'] ?? $_POST['password'] ?? '');

if (empty($username) || empty($password)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'user_info'   => ['auth' => 0],
        'server_info' => ['credit' => APP_AUTHOR],
    ]);
    exit;
}

$user = Security::authenticateToken($username, $password);

if (!$user || !$user['is_active']) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode([
        'user_info'   => ['auth' => 0, 'message' => 'Invalid credentials'],
        'server_info' => ['credit' => APP_AUTHOR],
    ]);
    exit;
}

if ($user['expires_at'] && $user['expires_at'] < time()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode([
        'user_info'   => ['auth' => 0, 'message' => 'Account expired'],
        'server_info' => ['credit' => APP_AUTHOR],
    ]);
    exit;
}

// ── Route Action ──────────────────────────────────────────
$action = trim($_GET['action'] ?? '');
$api    = new XtreamAPI($user);
$api->handle($action, $_GET);
