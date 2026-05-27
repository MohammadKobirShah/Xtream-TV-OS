<?php
declare(strict_types=1);

class StreamProxy
{
    private const HLS_MIME   = 'application/vnd.apple.mpegurl';
    private const TS_MIME    = 'video/MP2T';
    private const OCTET_MIME = 'application/octet-stream';

    public static function stream(int $channelId): void
    {
        $channel = Database::query(
            "SELECT * FROM channels WHERE id = ? AND is_active = 1",
            [$channelId]
        )->fetch();

        if (!$channel) {
            self::deny(404, 'Channel not found');
            return;
        }

        self::pipe($channel['stream_url']);
    }

    public static function pipe(string $url): void
    {
        while (ob_get_level() > 0) ob_end_clean();
        set_time_limit(0);
        ignore_user_abort(false);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => PROXY_MAX_REDIRECT,
            CURLOPT_CONNECTTIMEOUT => PROXY_TIMEOUT,
            CURLOPT_TIMEOUT        => 0,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'XtreamTV/' . APP_VERSION . ' (Kobir Shah)',
            CURLOPT_HEADERFUNCTION => function ($curl, $header) {
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
            CURLOPT_WRITEFUNCTION  => function ($curl, $data) {
                if (connection_aborted()) return -1;
                echo $data;
                flush();
                return strlen($data);
            },
        ]);

        if (!empty($_SERVER['HTTP_RANGE'])) {
            curl_setopt($ch, CURLOPT_RANGE, substr($_SERVER['HTTP_RANGE'], 6));
        }

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
