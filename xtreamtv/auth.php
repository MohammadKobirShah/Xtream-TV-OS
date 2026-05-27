<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — Authentication Middleware
 *  Phase 1: Foundation — auth.php
 * ============================================================
 *  Developer        : Kobir Shah
 *  DEVELOPER_CREDIT : Powered by Kobir Shah
 *  Description      : Session-based auth + API token middleware.
 *                     Drop-in include for any protected page.
 * ============================================================
 */

declare(strict_types=1);

// ── Safety guard: never include without config ────────────
if (!defined('APP_NAME')) {
    require_once __DIR__ . '/config.php';
}

/**
 * Auth — Centralized Authentication Middleware
 *
 * Usage:
 *   Auth::requireLogin();           // Redirect to login if not authenticated
 *   Auth::requireAdmin();           // 403 if not admin
 *   Auth::user();                   // Returns current user array or null
 *   Auth::login($user);             // Establish session
 *   Auth::logout();                 // Destroy session
 *   Auth::attempt($user, $pass);    // Validate credentials → user array | null
 *   Auth::byToken($token);          // Xtream API token auth
 */
final class Auth
{
    // ── Public Guards ─────────────────────────────────────

    /**
     * Require an active session. Redirects to login page if not authenticated.
     */
    public static function requireLogin(): void
    {
        if (!self::check()) {
            // Store intended destination for post-login redirect
            $_SESSION['redirect_after_login'] = $_SERVER['REQUEST_URI'] ?? '/xtreamtv/index.php';
            self::redirectToLogin();
        }
    }

    /**
     * Require admin role. Returns 403 JSON if unauthorized.
     */
    public static function requireAdmin(): void
    {
        self::requireLogin();
        if (!self::isAdmin()) {
            http_response_code(403);
            header('Content-Type: application/json');
            echo json_encode([
                'error'   => 'Administrator access required',
                'code'    => 403,
                'credit'  => DEVELOPER_CREDIT,
                'product' => APP_NAME,
            ]);
            exit;
        }
    }

    // ── Session Auth ─────────────────────────────────────

    /**
     * Check if a valid session is active.
     */
    public static function check(): bool
    {
        return !empty($_SESSION['auth_user_id'])
            && !empty($_SESSION['auth_role'])
            && !empty($_SESSION['auth_token']);
    }

    /**
     * Check if current user is admin.
     */
    public static function isAdmin(): bool
    {
        return self::check() && (bool)$_SESSION['auth_is_admin'];
    }

    /**
     * Return current authenticated user data array, or null.
     *
     * @return array{id:int,username:string,is_admin:bool,api_token:string,max_streams:int}|null
     */
    public static function user(): ?array
    {
        if (!self::check()) return null;
        return [
            'id'          => (int)$_SESSION['auth_user_id'],
            'username'    => $_SESSION['auth_username'] ?? '',
            'is_admin'    => (bool)$_SESSION['auth_is_admin'],
            'api_token'   => $_SESSION['auth_token'] ?? '',
            'max_streams' => (int)($_SESSION['auth_max_streams'] ?? 1),
            'role'        => $_SESSION['auth_is_admin'] ? 'admin' : 'user',
        ];
    }

    /**
     * Return user ID shorthand.
     */
    public static function id(): int
    {
        return (int)($_SESSION['auth_user_id'] ?? 0);
    }

    /**
     * Establish a session for a given user row from the database.
     *
     * @param array $user Full user row from users table
     */
    public static function login(array $user): void
    {
        // Prevent session fixation
        session_regenerate_id(true);

        $_SESSION['auth_user_id']    = $user['id'];
        $_SESSION['auth_username']   = $user['username'];
        $_SESSION['auth_is_admin']   = (bool)$user['is_admin'];
        $_SESSION['auth_token']      = $user['api_token'] ?? '';
        $_SESSION['auth_max_streams']= $user['max_streams'] ?? 1;
        $_SESSION['auth_logged_at']  = time();
        $_SESSION['_initiated']      = true;

        // Update last login metadata
        Database::query(
            "UPDATE users SET last_login = strftime('%s','now'), last_ip = ? WHERE id = ?",
            [$_SERVER['REMOTE_ADDR'] ?? '', $user['id']]
        );

        // Log login event
        Database::query(
            "INSERT INTO access_log (user_id, action, ip, meta) VALUES (?, 'login', ?, ?)",
            [$user['id'], $_SERVER['REMOTE_ADDR'] ?? '', $user['username'] . ' logged in']
        );
    }

    /**
     * Destroy the current session and log the logout event.
     */
    public static function logout(): void
    {
        $userId = self::id();

        if ($userId) {
            Database::query(
                "INSERT INTO access_log (user_id, action, ip, meta) VALUES (?, 'logout', ?, 'Session ended')",
                [$userId, $_SERVER['REMOTE_ADDR'] ?? '']
            );
        }

        // Completely destroy session
        $_SESSION = [];
        if (ini_get('session.use_cookies')) {
            $p = session_get_cookie_params();
            setcookie(
                session_name(), '', time() - 86400,
                $p['path'], $p['domain'], $p['secure'], $p['httponly']
            );
        }
        session_destroy();
    }

    // ── Credential Verification ───────────────────────────

    /**
     * Attempt login by username + password.
     * Returns user array on success, null on failure.
     *
     * @return array|null
     */
    public static function attempt(string $username, string $password): ?array
    {
        if (empty($username) || empty($password)) return null;

        $user = Database::query(
            "SELECT * FROM users WHERE username = ? COLLATE NOCASE AND is_active = 1",
            [trim($username)]
        )->fetch();

        if (!$user) return null;

        // Argon2ID hash verification
        if (!password_verify($password, $user['password'])) {
            // Also support raw API token as password (for API-only clients)
            if (!hash_equals($user['api_token'] ?? '', $password)) {
                return null;
            }
        }

        // Check expiry
        if ($user['expires_at'] && (int)$user['expires_at'] < time()) {
            return null; // Expired account
        }

        // Rehash if needed (algorithm upgrade support)
        if (password_needs_rehash($user['password'], PASSWORD_ARGON2ID)) {
            $newHash = password_hash($password, PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]);
            Database::query("UPDATE users SET password = ? WHERE id = ?", [$newHash, $user['id']]);
        }

        return $user;
    }

    /**
     * Authenticate via API token string (for Xtream Codes API / proxy.php).
     * Returns user array or null.
     *
     * @return array|null
     */
    public static function byToken(string $token): ?array
    {
        if (empty($token) || strlen($token) < 8) return null;

        $user = Database::query(
            "SELECT * FROM users WHERE api_token = ? AND is_active = 1",
            [$token]
        )->fetch();

        if (!$user) return null;
        if ($user['expires_at'] && (int)$user['expires_at'] < time()) return null;

        return $user;
    }

    /**
     * Authenticate via username + raw token (Xtream-style GET params).
     */
    public static function byUsernameToken(string $username, string $token): ?array
    {
        if (empty($username) || empty($token)) return null;

        $user = Database::query(
            "SELECT * FROM users WHERE username = ? COLLATE NOCASE AND api_token = ? AND is_active = 1",
            [$username, $token]
        )->fetch();

        if (!$user) return null;
        if ($user['expires_at'] && (int)$user['expires_at'] < time()) return null;

        return $user;
    }

    // ── Rate Limiting ─────────────────────────────────────

    /**
     * Rate limit helper. Returns false if limit exceeded.
     * Uses session-based sliding window counter.
     */
    public static function rateLimit(string $key, int $maxAttempts = 5, int $windowSec = 300): bool
    {
        $sKey = 'rl_' . md5($key);
        $now  = time();
        $data = $_SESSION[$sKey] ?? ['hits' => [], 'blocked_until' => 0];

        // Still blocked?
        if ($data['blocked_until'] > $now) return false;

        // Remove expired hits
        $data['hits'] = array_filter($data['hits'], fn($t) => $t > $now - $windowSec);

        if (count($data['hits']) >= $maxAttempts) {
            $data['blocked_until'] = $now + $windowSec;
            $_SESSION[$sKey] = $data;
            return false;
        }

        $data['hits'][] = $now;
        $_SESSION[$sKey] = $data;
        return true;
    }

    // ── CSRF ─────────────────────────────────────────────

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
        }
        return $_SESSION['csrf_token'];
    }

    public static function verifyCsrf(string $token): bool
    {
        if (empty($_SESSION['csrf_token'])) return false;
        return hash_equals($_SESSION['csrf_token'], $token);
    }

    public static function csrfField(): string
    {
        return '<input type="hidden" name="csrf_token" value="' . self::csrfToken() . '">';
    }

    // ── Helpers ───────────────────────────────────────────

    private static function redirectToLogin(): never
    {
        header('Location: /xtreamtv/login.php');
        exit;
    }
}
