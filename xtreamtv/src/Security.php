<?php
/**
 * ============================================================
 *  XtreamTV — Security Layer
 *  CSRF, XSS, SSRF Protection + Auth helpers
 *  Developer: Kobir Shah
 * ============================================================
 */

declare(strict_types=1);

class Security
{
    // ── CSRF ─────────────────────────────────────────────────

    public static function csrfToken(): string
    {
        if (empty($_SESSION['csrf_token'])) {
            $_SESSION['csrf_token'] = bin2hex(random_bytes(CSRF_TOKEN_LENGTH / 2));
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

    // ── XSS ──────────────────────────────────────────────────

    public static function e(mixed $val): string
    {
        return htmlspecialchars((string)$val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    // ── SSRF Protection ───────────────────────────────────────

    /**
     * Validate a URL is safe to proxy (no private IPs, blocked schemes)
     */
    public static function validateStreamUrl(string $url): bool
    {
        $parsed = parse_url($url);
        if (!$parsed || !isset($parsed['scheme'], $parsed['host'])) return false;

        // Scheme whitelist
        if (!in_array(strtolower($parsed['scheme']), ['http', 'https'], strict: true)) {
            // Allow rtmp/rtsp only in stream context (non-HTTP proxy)
            if (!in_array(strtolower($parsed['scheme']), ['rtmp', 'rtsp'], strict: true)) {
                return false;
            }
        }

        $host = strtolower($parsed['host']);

        // Block localhost / loopback
        $blocked_hosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0'];
        if (in_array($host, $blocked_hosts, strict: true)) return false;

        // Resolve hostname to IP and check for private ranges
        $ip = gethostbyname($host);
        if ($ip === $host && !filter_var($ip, FILTER_VALIDATE_IP)) {
            // Could not resolve — allow (stream servers may not resolve in proxy env)
            return true;
        }

        // Block private / reserved IP ranges
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        return true;
    }

    // ── Auth ─────────────────────────────────────────────────

    public static function isLoggedIn(): bool
    {
        return !empty($_SESSION['user_id']) && !empty($_SESSION['role']);
    }

    public static function isAdmin(): bool
    {
        return self::isLoggedIn() && $_SESSION['role'] === 'admin';
    }

    public static function requireLogin(): void
    {
        if (!self::isLoggedIn()) {
            header('Location: /xtreamtv/login.php');
            exit;
        }
    }

    public static function requireAdmin(): void
    {
        if (!self::isAdmin()) {
            header('HTTP/1.1 403 Forbidden');
            echo json_encode(['error' => 'Forbidden', 'credit' => APP_AUTHOR]);
            exit;
        }
    }

    public static function login(array $user): void
    {
        session_regenerate_id(true);
        $_SESSION['user_id']  = $user['id'];
        $_SESSION['username'] = $user['username'];
        $_SESSION['role']     = $user['role'];
        $_SESSION['token']    = $user['api_token'];

        // Update last login
        Database::query(
            "UPDATE users SET last_login = strftime('%s','now') WHERE id = ?",
            [$user['id']]
        );
    }

    public static function logout(): void
    {
        $_SESSION = [];
        session_destroy();
        header('Location: /xtreamtv/login.php');
        exit;
    }

    /** Authenticate via API token (for Xtream-compatible apps) */
    public static function authenticateToken(string $username, string $password): ?array
    {
        $user = Database::query(
            "SELECT * FROM users WHERE username = ? AND is_active = 1",
            [$username]
        )->fetch();

        if (!$user) return null;

        // Support both password login and raw token as password
        if (password_verify($password, $user['password']) || hash_equals($user['api_token'] ?? '', $password)) {
            return $user;
        }
        return null;
    }

    /** Generate a secure stream token */
    public static function generateStreamToken(): string
    {
        return bin2hex(random_bytes(24));
    }

    /** Rate limiting helper — returns false if rate exceeded */
    public static function rateLimit(string $key, int $maxAttempts = 5, int $windowSeconds = 300): bool
    {
        $rlKey  = 'rl_' . md5($key);
        $data   = $_SESSION[$rlKey] ?? ['count' => 0, 'reset' => time() + $windowSeconds];

        if (time() > $data['reset']) {
            $data = ['count' => 0, 'reset' => time() + $windowSeconds];
        }

        $data['count']++;
        $_SESSION[$rlKey] = $data;

        return $data['count'] <= $maxAttempts;
    }

    /** Strip any dangerous tags/attributes from HTML (light sanitize) */
    public static function sanitizeHtml(string $html): string
    {
        return strip_tags($html, '<b><i><em><strong><span><p><br>');
    }
}
