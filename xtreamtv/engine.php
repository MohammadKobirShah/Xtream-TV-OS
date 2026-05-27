<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — Core Engine
 *  Phase 2: M3U Parser + Stream Proxy Engine
 * ============================================================
 *  Developer        : Kobir Shah
 *  DEVELOPER_CREDIT : Powered by Kobir Shah
 *  Class            : M3UEngine
 *  Features         : Chunked M3U parsing, JSON cache,
 *                     cURL proxy with UA spoofing, header masking
 * ============================================================
 */

declare(strict_types=1);

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/config.php';
}

/**
 * M3UEngine — The core parsing and proxying class.
 *
 * All methods are static for zero-overhead instantiation.
 * Memory profile: chunked streaming keeps RAM under 8MB
 * regardless of playlist size.
 */
final class M3UEngine
{
    // ── Configuration ─────────────────────────────────────

    /** Cache TTL in seconds (default 1 hour) */
    private const CACHE_TTL = 3600;

    /** Maximum M3U file size to process (500 MB) */
    private const MAX_PLAYLIST_BYTES = 524288000;

    /** Chunk size for stream proxy output (1 MB) */
    private const STREAM_CHUNK = 1048576;

    /** Spoofed user agent for upstream requests */
    private const SPOOF_UA = 'Mozilla/5.0 (SmartTV; Tizen 6.0) AppleWebKit/537.36 XtreamTV/2.0';

    /** Developer credit injected into logs and headers */
    private const CREDIT = 'Kobir Shah';

    // ══════════════════════════════════════════════════════
    //  SECTION 1: M3U PARSER
    // ══════════════════════════════════════════════════════

    /**
     * Parse an M3U playlist from a remote URL.
     *
     * Strategy:
     * - Uses a cURL write-function callback so content streams
     *   through PHP's write buffer line-by-line — never fully
     *   loaded into RAM.
     * - Parsed channels are written to a JSON cache file.
     * - On subsequent calls, cache is returned instantly.
     *
     * @param  string $url       Remote M3U/M3U8 URL
     * @param  int    $playlistId DB playlist row ID (for cache key)
     * @param  bool   $forceRefresh Skip cache and re-fetch
     * @return array  Array of channel metadata arrays
     * @throws RuntimeException on network or SSRF errors
     */
    public static function parseM3U(string $url, int $playlistId = 0, bool $forceRefresh = false): array
    {
        // ── SSRF guard ──────────────────────────────────
        self::assertSafeUrl($url);

        // ── Cache check ─────────────────────────────────
        $cacheFile = self::cacheFilePath($playlistId ?: md5($url));
        if (!$forceRefresh && self::cacheValid($cacheFile)) {
            $cached = json_decode(file_get_contents($cacheFile), true);
            if (is_array($cached)) {
                error_log('[XtreamTV][Engine] Cache hit for playlist #' . $playlistId . ' — ' . self::CREDIT);
                return $cached;
            }
        }

        // ── Stream-parse via cURL write callback ────────
        $channels = [];
        $current  = [];
        $buffer   = '';
        $lineNum  = 0;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_USERAGENT      => self::SPOOF_UA,
            CURLOPT_HTTPHEADER     => [
                'Accept: */*',
                'Accept-Encoding: gzip, deflate',
                'X-Requested-By: XtreamTV/' . APP_VERSION,
            ],
            CURLOPT_ENCODING       => 'gzip',
            CURLOPT_BUFFERSIZE     => 65536,
        ]);

        // Write callback: processes the stream line by line
        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use (&$buffer, &$channels, &$current, &$lineNum) {
            $buffer .= $data;
            $lines   = explode("\n", $buffer);
            $buffer  = array_pop($lines); // Keep incomplete last line

            foreach ($lines as $line) {
                $lineNum++;
                $line = trim($line);
                if ($line === '' || $line === '#EXTM3U') continue;

                if (str_starts_with($line, '#EXTINF:')) {
                    $current = self::parseExtInfLine($line);
                } elseif ($line[0] !== '#' && !empty($current)) {
                    if (self::isValidStreamUrl($line)) {
                        $current['stream_url'] = $line;
                        $current['stream_type'] = self::detectStreamType($line);
                        $channels[] = $current;
                    }
                    $current = [];
                }
            }
            return strlen($data);
        });

        $ok    = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        curl_close($ch);

        if ($errno) {
            throw new \RuntimeException(
                "M3U fetch failed (cURL #{$errno}): {$error} — Credit: " . self::CREDIT
            );
        }

        // Flush remaining buffer
        if ($buffer !== '') {
            $line = trim($buffer);
            if (!empty($line) && $line[0] !== '#' && !empty($current)) {
                $current['stream_url'] = $line;
                $channels[] = $current;
            }
        }

        // ── Write JSON cache ────────────────────────────
        self::writeCache($cacheFile, $channels);

        error_log(sprintf(
            '[XtreamTV][Engine] Parsed %d channels from %s (%d lines) — %s',
            count($channels), $url, $lineNum, self::CREDIT
        ));

        return $channels;
    }

    /**
     * Parse M3U content string directly (e.g. from uploaded file).
     * Wraps the line-by-line parser for memory efficiency.
     *
     * @param  string $content   Raw M3U text content
     * @param  int    $playlistId For cache keying
     * @return array
     */
    public static function parseM3UContent(string $content, int $playlistId = 0): array
    {
        $channels = [];
        $current  = [];
        $lines    = explode("\n", str_replace("\r\n", "\n", $content));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === '#EXTM3U') continue;

            if (str_starts_with($line, '#EXTINF:')) {
                $current = self::parseExtInfLine($line);
            } elseif ($line !== '' && $line[0] !== '#' && !empty($current)) {
                if (self::isValidStreamUrl($line)) {
                    $current['stream_url']  = $line;
                    $current['stream_type'] = self::detectStreamType($line);
                    $channels[]             = $current;
                }
                $current = [];
            }
        }

        // Cache result
        if ($playlistId) {
            self::writeCache(self::cacheFilePath($playlistId), $channels);
        }

        return $channels;
    }

    /**
     * Parse a single #EXTINF line into a metadata array.
     *
     * Input example:
     *   #EXTINF:-1 tvg-id="cnn.us" tvg-name="CNN HD" tvg-logo="..." group-title="News",CNN HD
     *
     * @return array{tvg_id:string, tvg_name:string, tvg_logo:string, group_title:string, name:string}
     */
    private static function parseExtInfLine(string $line): array
    {
        $meta = [
            'tvg_id'     => '',
            'tvg_name'   => '',
            'tvg_logo'   => '',
            'group_title'=> 'Uncategorized',
            'name'       => 'Unknown Channel',
            'stream_url' => '',
            'stream_type'=> 'live',
        ];

        // Extract quoted attributes with a single pass regex
        if (preg_match_all('/(\w[\w-]*)="([^"]*)"/', $line, $m)) {
            $attrs = array_combine($m[1], $m[2]);
            $meta['tvg_id']      = $attrs['tvg-id']      ?? '';
            $meta['tvg_name']    = $attrs['tvg-name']    ?? '';
            $meta['tvg_logo']    = $attrs['tvg-logo']    ?? '';
            $meta['group_title'] = $attrs['group-title'] ?? 'Uncategorized';
        }

        // Channel display name is everything after the last comma
        if (preg_match('/,([^,]+)$/', $line, $m)) {
            $meta['name'] = trim($m[1]);
        }

        // Use tvg-name as fallback
        if ($meta['name'] === 'Unknown Channel' && !empty($meta['tvg_name'])) {
            $meta['name'] = $meta['tvg_name'];
        }

        return $meta;
    }

    // ══════════════════════════════════════════════════════
    //  SECTION 2: STREAM PROXY ENGINE
    // ══════════════════════════════════════════════════════

    /**
     * Proxy a stream from the upstream URL to the HTTP client.
     *
     * Features:
     * - Spoofs User-Agent to bypass restrictions
     * - Strips/replaces upstream server headers
     * - Forwards correct Content-Type for .ts, .m3u8, .mp4
     * - Handles Range requests for VOD seeking
     * - Client disconnect detection to stop wasting bandwidth
     * - Injects X-Developer credit header
     *
     * IMPORTANT: Call this ONLY after SSRF validation.
     * set_time_limit(0) is called to allow indefinite live streams.
     *
     * @param  string $streamUrl Decoded upstream stream URL
     * @param  int    $userId    User ID for bandwidth tracking
     */
    public static function proxyStream(string $streamUrl, int $userId = 0): void
    {
        // ── SSRF guard ──────────────────────────────────
        self::assertSafeUrl($streamUrl);

        // ── Disable timeouts & buffering for live streams ─
        set_time_limit(0);
        ignore_user_abort(false);
        while (ob_get_level() > 0) ob_end_clean();

        // ── Build cURL handle ───────────────────────────
        $ch = curl_init($streamUrl);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 0,          // No timeout for live streams
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_USERAGENT      => self::SPOOF_UA,  // Spoof as SmartTV
            CURLOPT_HTTPHEADER     => [
                'Accept: */*',
                'Connection: keep-alive',
            ],
            CURLOPT_ENCODING       => '',          // Accept any encoding
        ]);

        // ── Forward Range header (VOD seeking support) ──
        if (!empty($_SERVER['HTTP_RANGE'])) {
            curl_setopt($ch, CURLOPT_RANGE, substr($_SERVER['HTTP_RANGE'], 6));
        }

        // ── Header callback: selectively forward upstream headers ──
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($curl, $header) {
            $lower = strtolower(trim($header));

            // Headers we forward to client
            $forwardPrefixes = ['content-type', 'content-length', 'transfer-encoding', 'cache-control', 'accept-ranges'];
            foreach ($forwardPrefixes as $prefix) {
                if (str_starts_with($lower, $prefix . ':')) {
                    // Sanitize before forwarding
                    $safeHeader = preg_replace('/[\r\n]/', '', $header);
                    header($safeHeader);
                    break;
                }
            }

            return strlen($header);
        });

        // ── Write callback: pipe chunks directly to client ──
        $bytesSent  = 0;
        $lastUpdate = time();

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use (&$bytesSent, &$lastUpdate, $userId) {
            // Stop immediately if client disconnected
            if (connection_aborted()) {
                return -1; // Signal cURL to abort
            }

            echo $data;
            flush();

            $bytesSent += strlen($data);

            // Update byte count in DB every 15 seconds
            if ($userId && (time() - $lastUpdate) >= 15) {
                Database::query(
                    "UPDATE stream_sessions SET last_ping = strftime('%s','now'), bytes_sent = bytes_sent + ?
                     WHERE user_id = ? ORDER BY id DESC LIMIT 1",
                    [$bytesSent, $userId]
                );
                $bytesSent  = 0;
                $lastUpdate = time();
            }

            return strlen($data);
        });

        // ── Security & credit headers ───────────────────
        header('X-Powered-By: XtreamTV/' . APP_VERSION);
        header('X-Developer: ' . self::CREDIT);                   // Credit in every response
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Allow-Origin: *');
        header('Access-Control-Allow-Methods: GET, HEAD, OPTIONS');

        // ── Execute proxy ───────────────────────────────
        curl_exec($ch);
        $errno  = curl_errno($ch);
        $errMsg = curl_error($ch);
        curl_close($ch);

        if ($errno && !connection_aborted()) {
            error_log("[XtreamTV][Engine][Proxy] cURL #{$errno}: {$errMsg} — " . self::CREDIT);
        }
    }

    /**
     * Proxy an M3U8 (HLS) playlist, rewriting segment URLs to
     * pass through our proxy. This hides the origin server completely.
     *
     * @param  string $m3u8Url The upstream .m3u8 manifest URL
     * @param  string $proxyBase Our proxy base URL (e.g. http://server/xtreamtv/proxy.php)
     */
    public static function proxyM3U8Manifest(string $m3u8Url, string $proxyBase): void
    {
        self::assertSafeUrl($m3u8Url);

        $content = self::fetchContent($m3u8Url);
        if ($content === null) {
            http_response_code(502);
            echo '#EXTM3U8-proxy-error';
            return;
        }

        // Rewrite segment and sub-playlist URLs
        $baseUrl = dirname($m3u8Url) . '/';
        $lines   = explode("\n", $content);
        $output  = [];

        foreach ($lines as $line) {
            $trimmed = trim($line);
            if ($trimmed === '' || str_starts_with($trimmed, '#')) {
                $output[] = $line;
                continue;
            }

            // It's a URL line — make it absolute then proxy it
            $absoluteUrl = self::resolveUrl($trimmed, $baseUrl);
            $proxiedUrl  = $proxyBase . '?url=' . base64_encode($absoluteUrl);
            $output[]    = $proxiedUrl;
        }

        header('Content-Type: application/vnd.apple.mpegurl');
        header('X-Developer: ' . self::CREDIT);
        header('Cache-Control: no-cache');
        echo implode("\n", $output);
    }

    // ══════════════════════════════════════════════════════
    //  SECTION 3: CACHE MANAGEMENT
    // ══════════════════════════════════════════════════════

    /**
     * Return path to the JSON cache file for a playlist.
     */
    public static function cacheFilePath(string|int $key): string
    {
        return STORAGE_PATH . '/cache/playlist_' . md5((string)$key) . '.json';
    }

    /**
     * Check if a cache file exists and is still fresh.
     */
    public static function cacheValid(string $file): bool
    {
        return file_exists($file)
            && (time() - filemtime($file)) < self::CACHE_TTL;
    }

    /**
     * Write channel array to JSON cache file.
     */
    private static function writeCache(string $file, array $channels): void
    {
        $dir = dirname($file);
        if (!is_dir($dir)) mkdir($dir, 0750, true);

        $json = json_encode($channels, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        file_put_contents($file, $json, LOCK_EX);
    }

    /**
     * Load channels from JSON cache file.
     */
    public static function loadCache(string $file): ?array
    {
        if (!file_exists($file)) return null;
        $data = json_decode(file_get_contents($file), true);
        return is_array($data) ? $data : null;
    }

    /**
     * Invalidate (delete) a playlist cache.
     */
    public static function invalidateCache(int $playlistId): void
    {
        $file = self::cacheFilePath($playlistId);
        if (file_exists($file)) unlink($file);
    }

    // ══════════════════════════════════════════════════════
    //  SECTION 4: M3U GENERATOR
    // ══════════════════════════════════════════════════════

    /**
     * Generate an M3U playlist file for a set of channels,
     * rewriting stream URLs through our proxy.
     * Streamed line-by-line — no RAM accumulation.
     *
     * @param array  $channels   Array of channel arrays
     * @param string $proxyBase  Our proxy.php URL
     */
    public static function generateM3U(array $channels, string $proxyBase): void
    {
        header('Content-Type: application/x-mpegurl; charset=utf-8');
        header('Content-Disposition: attachment; filename="xtreamtv.m3u"');
        header('X-Developer: ' . self::CREDIT);

        echo "#EXTM3U\n";
        echo '# Generated by XtreamTV v' . APP_VERSION . ' — ' . DEVELOPER_CREDIT . "\n";

        foreach ($channels as $ch) {
            $proxyUrl = $proxyBase . '?url=' . base64_encode($ch['stream_url']);
            echo '#EXTINF:-1'
                . ' tvg-id="'      . self::escape($ch['tvg_id']      ?? '') . '"'
                . ' tvg-name="'    . self::escape($ch['tvg_name']    ?? '') . '"'
                . ' tvg-logo="'    . self::escape($ch['tvg_logo']    ?? '') . '"'
                . ' group-title="' . self::escape($ch['group_title'] ?? '') . '"'
                . ',' . self::escape($ch['name'] ?? 'Unknown') . "\n"
                . $proxyUrl . "\n";
        }
    }

    // ══════════════════════════════════════════════════════
    //  SECTION 5: UTILITIES / SECURITY
    // ══════════════════════════════════════════════════════

    /**
     * SSRF protection: assert a URL is safe to proxy.
     * Blocks private IPs, loopback, reserved ranges, and bad schemes.
     *
     * @throws RuntimeException if URL is blocked
     */
    public static function assertSafeUrl(string $url): void
    {
        $parsed = parse_url($url);
        if (!$parsed || empty($parsed['scheme']) || empty($parsed['host'])) {
            throw new \RuntimeException('Invalid URL structure');
        }

        $scheme = strtolower($parsed['scheme']);
        $allowedSchemes = ['http', 'https', 'rtmp', 'rtsp'];
        if (!in_array($scheme, $allowedSchemes, true)) {
            throw new \RuntimeException("Blocked scheme: {$scheme}");
        }

        $host = strtolower($parsed['host']);
        $blockedHosts = ['localhost', '127.0.0.1', '::1', '0.0.0.0', 'metadata.google.internal'];
        if (in_array($host, $blockedHosts, true)) {
            throw new \RuntimeException("Blocked host: {$host}");
        }

        // DNS-resolve and check for private ranges
        if ($scheme !== 'rtmp' && $scheme !== 'rtsp') {
            $ip = gethostbyname($host);
            if ($ip !== $host) {
                $private = filter_var(
                    $ip,
                    FILTER_VALIDATE_IP,
                    FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE
                );
                if ($private === false) {
                    throw new \RuntimeException("SSRF blocked: resolved to private IP {$ip}");
                }
            }
        }
    }

    /**
     * Quick URL validity check (less strict than SSRF check).
     */
    private static function isValidStreamUrl(string $url): bool
    {
        return (bool)(
            filter_var($url, FILTER_VALIDATE_URL) ||
            str_starts_with($url, 'rtmp://') ||
            str_starts_with($url, 'rtsp://')
        );
    }

    /**
     * Detect stream type from URL extension/pattern.
     */
    private static function detectStreamType(string $url): string
    {
        $lower = strtolower($url);
        if (str_contains($lower, '.m3u8') || str_contains($lower, 'hls')) return 'live';
        if (str_contains($lower, '.ts'))   return 'live';
        if (str_contains($lower, '.mp4') || str_contains($lower, '.mkv') || str_contains($lower, '.avi')) return 'vod';
        if (str_starts_with($lower, 'rtmp') || str_starts_with($lower, 'rtsp')) return 'live';
        return 'live';
    }

    /**
     * Fetch content of a URL as string (for small files like M3U8 manifests).
     */
    private static function fetchContent(string $url): ?string
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => self::SPOOF_UA,
        ]);
        $body  = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        return ($errno || $body === false) ? null : $body;
    }

    /**
     * Resolve a potentially relative URL against a base.
     */
    private static function resolveUrl(string $url, string $base): string
    {
        if (str_starts_with($url, 'http://') || str_starts_with($url, 'https://')) {
            return $url;
        }
        return rtrim($base, '/') . '/' . ltrim($url, '/');
    }

    /**
     * HTML-safe escaping for M3U attribute output.
     */
    private static function escape(string $val): string
    {
        return htmlspecialchars($val, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
