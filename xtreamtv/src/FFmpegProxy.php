<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — FFmpeg Restream Proxy
 * ============================================================
 *  Developer        : Kobir Shah
 *  DEVELOPER_CREDIT : Powered by Kobir Shah
 *
 *  Pro-Tip #2 Implementation:
 *  PHP exec() wrapper around FFmpeg for elite stream proxying.
 *
 *  Why FFmpeg beats raw cURL proxying:
 *  ┌──────────────────────────────────────────────────────────┐
 *  │  cURL proxy  → dumps bytes as-is, no healing             │
 *  │  FFmpeg proxy → re-muxes, fixes timestamps, reconnects,  │
 *  │                 bypasses Geo/CDN locks via User-Agent,    │
 *  │                 stabilises 4K, converts formats on-fly    │
 *  └──────────────────────────────────────────────────────────┘
 *
 *  Modes:
 *    stream()        → pipe FFmpeg output direct to HTTP client
 *    remux()         → remux to TS container (fixes broken streams)
 *    transcode()     → H264 + AAC re-encode (max compatibility)
 *    probe()         → ffprobe JSON metadata (no stream output)
 *
 *  Activate via: proxy.php?mode=ffmpeg[&quality=4k|hd|sd]
 * ============================================================
 */

declare(strict_types=1);

final class FFmpegProxy
{
    /** Path to ffmpeg binary inside Docker container */
    private const FFMPEG_BIN  = '/usr/bin/ffmpeg';
    private const FFPROBE_BIN = '/usr/bin/ffprobe';

    /** Max seconds FFmpeg will attempt to reconnect to a dead stream */
    private const RECONNECT_DELAY = 5;
    private const MAX_RECONNECTS  = 10;

    /** Spoofed SmartTV UA passed to FFmpeg's HTTP client */
    private const SPOOF_UA = 'Mozilla/5.0 (SmartTV; Tizen 6.0) AppleWebKit/537.36 XtreamTV/2.0';

    /**
     * Stream a channel through FFmpeg and pipe stdout to the HTTP client.
     *
     * FFmpeg remuxes to MPEG-TS on the fly — perfectly compatible
     * with all IPTV players. Fixes broken timestamps, reconnects
     * on stream errors, and bypasses User-Agent CDN locks.
     *
     * @param string $url     Upstream stream URL (pre-validated, no SSRF)
     * @param int    $userId  For logging
     * @param string $quality 4k | hd | sd | passthru (default passthru)
     */
    public static function stream(string $url, int $userId = 0, string $quality = 'passthru'): void
    {
        // ── Verify FFmpeg is available ─────────────────────────────
        if (!self::available()) {
            error_log('[XtreamTV][FFmpeg] Binary not found — fallback to passthru — Kobir Shah');
            // Graceful fallback to fpassthru mode
            StreamPassthru::pipe($url, $userId);
            return;
        }

        // ── Disable timeouts + output buffering ────────────────────
        set_time_limit(0);
        ignore_user_abort(false);
        while (ob_get_level() > 0) ob_end_clean();

        // ── Security + credit response headers ─────────────────────
        header('Content-Type: video/MP2T');
        header('X-Developer: Kobir Shah');
        header('X-Powered-By: XtreamTV/' . APP_VERSION . ' FFmpeg by Kobir Shah');
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-cache, no-store');

        // ── Build FFmpeg command ───────────────────────────────────
        $cmd = self::buildCommand($url, $quality);

        error_log('[XtreamTV][FFmpeg] Starting: ' . $cmd . ' — Kobir Shah');

        // ── Open FFmpeg process with stdout pipe ───────────────────
        $descriptors = [
            0 => ['pipe', 'r'],           // stdin  (unused)
            1 => ['pipe', 'w'],           // stdout → we read this
            2 => ['file',
                  STORAGE_PATH . '/logs/ffmpeg_' . date('Ymd') . '.log',
                  'a'],                   // stderr → log file
        ];

        $process = proc_open($cmd, $descriptors, $pipes);

        if (!is_resource($process)) {
            http_response_code(502);
            echo json_encode(['error' => 'FFmpeg process failed to start', 'credit' => DEVELOPER_CREDIT]);
            return;
        }

        // ── Close unused stdin ─────────────────────────────────────
        fclose($pipes[0]);

        // ── Set stdout to non-blocking ─────────────────────────────
        stream_set_blocking($pipes[1], false);

        $stdout     = $pipes[1];
        $bytesSent  = 0;
        $lastPing   = time();
        $chunkSize  = 65536; // 64 KB chunks — optimal for TS streams

        // ── PRO-TIP #1 + #2 combined:
        //    Read FFmpeg stdout → fpassthru() to client ──────────────
        $tmpHandle = fopen('php://temp', 'r+b');

        while (!feof($stdout)) {
            // ── Client disconnect check ────────────────────────────
            if (connection_aborted()) {
                error_log('[XtreamTV][FFmpeg] Client disconnected — Kobir Shah');
                break;
            }

            $chunk = fread($stdout, $chunkSize);

            if ($chunk === false || $chunk === '') {
                // Non-blocking read returned empty — brief pause
                usleep(5000); // 5ms
                continue;
            }

            // ── Write chunk to temp, rewind, fpassthru() ──────────
            fwrite($tmpHandle, $chunk);
            rewind($tmpHandle);
            fpassthru($tmpHandle);      // ← zero-copy kernel pipe
            ftruncate($tmpHandle, 0);
            fseek($tmpHandle, 0);
            flush();

            $bytesSent += strlen($chunk);

            // Ping DB every 20s
            if ($userId && (time() - $lastPing) >= 20) {
                try {
                    Database::query(
                        "UPDATE stream_sessions SET last_ping = strftime('%s','now'), bytes_sent = bytes_sent + ?
                         WHERE user_id = ? ORDER BY id DESC LIMIT 1",
                        [$bytesSent, $userId]
                    );
                } catch (\Throwable) {}
                $bytesSent = 0;
                $lastPing  = time();
            }
        }

        // ── Cleanup ────────────────────────────────────────────────
        fclose($tmpHandle);
        fclose($stdout);

        $exitCode = proc_close($process);
        error_log(sprintf(
            '[XtreamTV][FFmpeg] Process ended (exit:%d, bytes:%d) — Kobir Shah',
            $exitCode, $bytesSent
        ));
    }

    /**
     * Build the FFmpeg command string for the given URL and quality.
     *
     * passthru  → remux only (no re-encode, ultra fast, no CPU cost)
     * hd        → scale to 1280x720, H264 CRF 23
     * sd        → scale to 854x480,  H264 CRF 28
     * 4k        → passthrough 4K with copy codecs (remux only)
     */
    private static function buildCommand(string $url, string $quality): string
    {
        // Sanitize URL for shell — no shell_exec with raw user input ever
        $safeUrl = escapeshellarg($url);

        // Common input flags: reconnect, timeout, spoofed UA
        $inputFlags = implode(' ', [
            '-hide_banner',
            '-loglevel warning',
            '-reconnect 1',
            '-reconnect_streamed 1',
            '-reconnect_delay_max ' . self::RECONNECT_DELAY,
            '-reconnect_at_eof 1',
            '-user_agent ' . escapeshellarg(self::SPOOF_UA),
            '-timeout 10000000',            // 10s connect timeout (µs)
            '-analyzeduration 2000000',     // 2s analysis
            '-probesize 5000000',           // 5MB probe
        ]);

        // Output flags: pipe to stdout as MPEG-TS
        $outputPipe = 'pipe:1';

        return match ($quality) {

            // ── Passthru / 4K: copy codecs, remux to TS only ──────
            'passthru', '4k' => sprintf(
                '%s %s -i %s -c:v copy -c:a copy -f mpegts %s 2>&1',
                escapeshellcmd(self::FFMPEG_BIN),
                $inputFlags,
                $safeUrl,
                $outputPipe
            ),

            // ── HD: scale + H264 + AAC ─────────────────────────────
            'hd' => sprintf(
                '%s %s -i %s -vf scale=1280:720 -c:v libx264 -preset veryfast -crf 23 -c:a aac -b:a 128k -f mpegts %s 2>&1',
                escapeshellcmd(self::FFMPEG_BIN),
                $inputFlags,
                $safeUrl,
                $outputPipe
            ),

            // ── SD: scale + H264 + AAC (lower bitrate) ─────────────
            'sd' => sprintf(
                '%s %s -i %s -vf scale=854:480 -c:v libx264 -preset veryfast -crf 28 -c:a aac -b:a 96k -f mpegts %s 2>&1',
                escapeshellcmd(self::FFMPEG_BIN),
                $inputFlags,
                $safeUrl,
                $outputPipe
            ),

            // ── Unknown quality → safe fallback to passthru ─────────
            default => sprintf(
                '%s %s -i %s -c copy -f mpegts %s 2>&1',
                escapeshellcmd(self::FFMPEG_BIN),
                $inputFlags,
                $safeUrl,
                $outputPipe
            ),
        };
    }

    /**
     * Probe a stream URL with ffprobe — returns metadata as array.
     * Used by the admin panel to inspect stream health.
     *
     * @return array{streams: array, format: array}|null
     */
    public static function probe(string $url): ?array
    {
        if (!self::available()) return null;

        $safeUrl = escapeshellarg($url);
        $cmd = sprintf(
            '%s -v quiet -print_format json -show_streams -show_format '
            . '-user_agent %s %s 2>/dev/null',
            escapeshellcmd(self::FFPROBE_BIN),
            escapeshellarg(self::SPOOF_UA),
            $safeUrl
        );

        $output = shell_exec($cmd);
        if (!$output) return null;

        $data = json_decode($output, true);
        return is_array($data) ? $data : null;
    }

    /**
     * Check if ffmpeg binary is available in the container.
     */
    public static function available(): bool
    {
        return file_exists(self::FFMPEG_BIN) && is_executable(self::FFMPEG_BIN);
    }

    /**
     * Return FFmpeg version string (for admin panel display).
     */
    public static function version(): string
    {
        if (!self::available()) return 'FFmpeg not installed';
        $out = shell_exec(escapeshellcmd(self::FFMPEG_BIN) . ' -version 2>&1 | head -1');
        return trim($out ?? 'unknown');
    }
}
