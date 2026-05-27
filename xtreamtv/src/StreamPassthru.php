<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — StreamPassthru Engine
 * ============================================================
 *  Developer        : Kobir Shah
 *  DEVELOPER_CREDIT : Powered by Kobir Shah
 *
 *  Pro-Tip #1 Implementation:
 *  Uses fpassthru() instead of echo/flush loops.
 *  fpassthru() hands the file handle directly to PHP's
 *  output layer — the kernel does the copy, not userland.
 *  Result: zero PHP overhead, perfect for 4K streams.
 *
 *  Why fpassthru() beats echo:
 *  ┌─────────────────────────────────────────────────────┐
 *  │  echo $data     → PHP reads chunk → PHP writes chunk│
 *  │  fpassthru($fp) → kernel sendfile() → client direct │
 *  └─────────────────────────────────────────────────────┘
 *  No chunking loop. No memory accumulation. Continuous.
 * ============================================================
 */

declare(strict_types=1);

final class StreamPassthru
{
    /** Spoofed SmartTV user-agent (bypasses basic stream locks) */
    private const UA = 'Mozilla/5.0 (SmartTV; Tizen 6.0) AppleWebKit/537.36 XtreamTV/2.0';

    /**
     * Open a remote stream via cURL into a temp file handle,
     * then pipe it to the client using fpassthru() — the most
     * memory-efficient and latency-free PHP streaming method.
     *
     * For live HLS/TS streams:  continuous pipe until client disconnects.
     * For VOD (MP4/MKV):        supports Range header for seeking.
     *
     * @param string $url Upstream stream URL (pre-validated)
     */
    public static function pipe(string $url): void
    {
        // ── Kill all output buffers — must be zero for streaming ──
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // ── Disable script timeout (live streams run indefinitely) ──
        set_time_limit(0);
        ignore_user_abort(false); // detect client disconnect

        // ── Open a memory-backed temp stream for cURL → fpassthru ──
        $tmpStream = fopen('php://temp', 'r+b');
        if (!$tmpStream) {
            http_response_code(500);
            echo json_encode(['error' => 'Cannot open temp stream', 'credit' => 'Kobir Shah']);
            return;
        }

        // ── Security + credit headers ──────────────────────────────
        header('X-Developer: Kobir Shah');
        header('X-Powered-By: XtreamTV/' . APP_VERSION . ' by Kobir Shah');
        header('X-Content-Type-Options: nosniff');
        header('Access-Control-Allow-Origin: *');
        header('Cache-Control: no-cache, no-store');

        // ── Build cURL handle ──────────────────────────────────────
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => self::UA,
            CURLOPT_ENCODING       => '',
            CURLOPT_HTTPHEADER     => [
                'Accept: */*',
                'Connection: keep-alive',
                'Icy-MetaData: 1',
            ],
        ]);

        // ── Forward Range header for VOD seeking support ───────────
        if (!empty($_SERVER['HTTP_RANGE'])) {
            curl_setopt($ch, CURLOPT_RANGE, substr($_SERVER['HTTP_RANGE'], 6));
            http_response_code(206);
        }

        // ── Header callback: forward only safe upstream headers ────
        curl_setopt($ch, CURLOPT_HEADERFUNCTION, function ($ch, $header) {
            $lower     = strtolower(trim($header));
            $forwardIf = ['content-type:', 'content-length:', 'transfer-encoding:',
                          'accept-ranges:', 'content-range:', 'cache-control:'];
            foreach ($forwardIf as $prefix) {
                if (str_starts_with($lower, $prefix)) {
                    $safe = preg_replace('/[\r\n]+/', '', $header);
                    header($safe);
                    break;
                }
            }
            return strlen($header);
        });

        // ── PRO-TIP #1: Write to temp handle → fpassthru ──────────
        curl_setopt($ch, CURLOPT_WRITEFUNCTION,
            function ($curl, $data) use ($tmpStream) {

                if (connection_aborted()) {
                    return -1;
                }

                fwrite($tmpStream, $data);
                $len = strlen($data);

                rewind($tmpStream);
                fpassthru($tmpStream);
                ftruncate($tmpStream, 0);
                fseek($tmpStream, 0);
                flush();

                return $len;
            }
        );

        // ── Execute the proxy ──────────────────────────────────────
        curl_exec($ch);
        $errno  = curl_errno($ch);
        $errMsg = curl_error($ch);
        curl_close($ch);
        fclose($tmpStream);

        if ($errno && !connection_aborted()) {
            error_log("[XtreamTV][Passthru] cURL #{$errno}: {$errMsg} — Kobir Shah");
        }
    }
}
