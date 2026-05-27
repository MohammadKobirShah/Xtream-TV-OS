<?php
/**
 * XtreamTV — Logout
 * Developer: Kobir Shah
 */
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';

if (Security::isLoggedIn()) {
    Database::query(
        "INSERT INTO access_log (user_id, ip, action, meta) VALUES (?, ?, 'logout', 'User logged out')",
        [(int)$_SESSION['user_id'], $_SERVER['REMOTE_ADDR'] ?? '']
    );
}
Security::logout();
