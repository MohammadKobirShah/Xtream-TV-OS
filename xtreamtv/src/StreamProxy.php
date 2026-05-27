<?php
/**
 * ============================================================
 *  XtreamTV — Stream Proxy Engine
 *  cURL-based, chunked, memory-optimized IPTV stream forwarder
 *  Developer: Kobir Shah
 * ============================================================
 */

declare(strict_types=1);

class StreamProxy
{
    private const HLS_MIME   = 'application/vnd.apple.mpegurl';
    private const TS_MIME    = 'video/MP2T';
    private const OCTET_MIME = 'application/octet-stream';

    /**
     * Proxy a stream by channel ID
     * Validates token, enforces concurrent stream limits, then pipes bytes.
     */
    public static function stream(int $channelId, string $token): void
    {
        // ── Authenticate ──────────────────────────────────────
        $user = Database::query(
            "SELECT u.* FROM users u
             JOIN stream_sessions s ON s.user_id = u.id
             WHERE s.token = ? AND s.channel_id = ? AND u.is_active = 1",
            [$token, $channelId]
        )->fetch();

        if (!$user) {
            // Try direct token auth fallback
            $user = Database::query(
                "SELECT * FROM users WHERE api_token = ? AND is_active = 1",
                [$token]
            )->fetch();

            if (!$user) {
                self::deny(401, 'Unauthorized');
                return;
            }
        }

        // ── Expiry check ──────────────────────────────────────
        if ($user['expires_at'] && $user['expires_at'] < time()) {
            self::deny(403, 'Account expired');
            return;
        }

        // ── Concurrent stream limit ───────────────────────────
        $activeStreams = (int)Database::query(
            "SELECT COUNT(*) FROM stream_sessions
             WHERE user_id = ? AND last_ping > ?",
            [$user['id'], time() - 30]
        )->fetchColumn();

        if ($activeStreams >= (int)$user['max_streams']) {
            self::deny(429, 'Max concurrent streams reached');
            return;
        }

        // ── Fetch channel ─────────────────────────────────────
        $channel = Database::query(
            "SELECT * FROM channels WHERE id = ? AND is_active = 1",
            [$channelId]
        )->fetch();

        if (!$channel) {
            self::deny(404, 'Channel not found');
            return;
        }

        // ── SSRF check ────────────────────────────────────────
        if (!Security::validateStreamUrl($channel['stream_url'])) {
            self::deny(403, 'Stream URL blocked');
            return;
        }

        // ── Register / refresh session ────────────────────────
        $sessionToken = Security::generateStreamToken();
        Database::query(
            "INSERT OR REPLACE INTO stream_sessions
             (user_id, channel_id, token, ip, user_agent, started_at, last_ping)
             VALUES (?, ?, ?, ?, ?, strftime('%s','now'), strftime('%s','now'))",
            [
                $user['id'],
                $channelId,
                $sessionToken,
                $_SERVER['REMOTE_ADDR'] ?? 'unknown',
                substr($_SERVER['HTTP_USER_AGENT'] ?? '', 0, 255),
            ]
        );

        // Log access
        Database::query(
            "INSERT INTO access_log (user_id, channel_id, ip, action, meta) VALUES (?, ?, ?, 'stream_start', ?)",
            [$user['id'], $channelId, $_SERVER['REMOTE_ADDR'] ?? '', $channel['name']]
        );

        // ── Pipe the stream ───────────────────────────────────
        self::pipe($channel['stream_url'], $user['id']);
    }

    /**
     * Direct stream pipe — no auth (used internally by Xtream API endpoint)
     */
    public static function pipe(string $url, int $userId = 0): void
    {
        // Disable output buffering entirely for real-time streaming
        while (ob_get_level() > 0) ob_end_clean();
        set_time_limit(0);
        ignore_user_abort(false);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => PROXY_MAX_REDIRECT,
            CURLOPT_CONNECTTIMEOUT => PROXY_TIMEOUT,
            CURLOPT_TIMEOUT        => 0, // No timeout — live stream
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'XtreamTV/' . APP_VERSION . ' (Kobir Shah)',
            CURLOPT_HEADERFUNCTION => function ($curl, $header) {
                // Forward relevant headers only (strip CORS/auth from upstream)
                $lower = strtolower($header);
                $forward = ['content-type', 'content-length', 'transfer-encoding', 'cache-control'];
                foreach ($forward as $h) {
                    if (str_starts_with($lower, $h . ':')) {
                        header(rtrim($header));
                        break;
                    }
                }
                return strlen($header);
            },
            CURLOPT_WRITEFUNCTION  => function ($curl, $data) use ($userId) {
                if (connection_aborted()) return -1; // Stop proxy if client disconnected
                echo $data;
                flush();
                // Update bytes and ping
                static $bytes = 0;
                static $lastPing = 0;
                $bytes += strlen($data);
                if (time() - $lastPing > 10) {
                    Database::query(
                        "UPDATE stream_sessions SET last_ping = strftime('%s','now'), bytes_sent = bytes_sent + ?
                         WHERE user_id = ? ORDER BY id DESC LIMIT 1",
                        [$bytes, $userId]
                    );
                    $bytes    = 0;
                    $lastPing = time();
                }
                return strlen($data);
            },
        ]);

        // Pass client Range header for VOD seeking
        if (!empty($_SERVER['HTTP_RANGE'])) {
            curl_setopt($ch, CURLOPT_RANGE, substr($_SERVER['HTTP_RANGE'], 6));
        }

        // Security headers
        header('X-Powered-By: XtreamTV/' . APP_VERSION . ' by Kobir Shah');
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Allow-Origin: *');

        curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);

        if ($errno && !connection_aborted()) {
            error_log("[XtreamTV][Proxy] cURL error #{$errno} — Credit: Kobir Shah");
        }
    }

    private static function deny(int $code, string $msg): void
    {
        http_response_code($code);
        header('Content-Type: application/json');
        echo json_encode([
            'error'   => $msg,
            'code'    => $code,
            'product' => APP_NAME,
            'credit'  => APP_AUTHOR,
        ]);
        exit;
    }
}
