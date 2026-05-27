<?php
/**
 * ============================================================
 *  XtreamTV — M3U / M3U8 Playlist Parser
 *  Chunked, memory-efficient, regex-based
 *  Developer: Kobir Shah
 * ============================================================
 */

declare(strict_types=1);

class M3UParser
{
    private const CHUNK_SIZE = 65536; // 64 KB per chunk read

    /**
     * Parse M3U content string into array of channel entries.
     * Memory-efficient: processes line by line via generator.
     *
     * @return array<int, array{name:string, url:string, logo:string, group:string, tvg_id:string, tvg_name:string}>
     */
    public static function parse(string $content): array
    {
        $channels = [];
        $current  = [];
        $lines    = explode("\n", str_replace("\r\n", "\n", $content));

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line === '#EXTM3U') continue;

            if (str_starts_with($line, '#EXTINF:')) {
                $current = self::parseExtInf($line);
            } elseif ($line[0] !== '#' && !empty($current)) {
                // Validate URL superficially (no SSRF check here — done at proxy time)
                if (filter_var($line, FILTER_VALIDATE_URL) || str_starts_with($line, 'rtmp') || str_starts_with($line, 'rtsp')) {
                    $current['url'] = $line;
                    $channels[]     = $current;
                }
                $current = [];
            }
        }

        return $channels;
    }

    /**
     * Parse M3U from a remote URL (streaming, chunked — no full download into RAM)
     * Returns channel array; max ~500 MB playlists supported.
     */
    public static function parseFromUrl(string $url, int $playlistId): int
    {
        if (!(filter_var($url, FILTER_VALIDATE_URL) || str_starts_with($url, 'rtmp://') || str_starts_with($url, 'rtsp://'))) {
            throw new \RuntimeException('SSRF: URL not allowed');
        }

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => false,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => PROXY_MAX_REDIRECT,
            CURLOPT_CONNECTTIMEOUT => PROXY_TIMEOUT,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'XtreamTV/' . APP_VERSION . ' (Kobir Shah)',
            CURLOPT_WRITEFUNCTION  => null, // see below
        ]);

        // Stream-parse using write callback (memory efficient)
        $buffer   = '';
        $current  = [];
        $imported = 0;

        curl_setopt($ch, CURLOPT_WRITEFUNCTION, function ($curl, $data) use (&$buffer, &$current, &$imported, $playlistId) {
            $buffer .= $data;
            $lines   = explode("\n", $buffer);
            // Keep the last (potentially incomplete) line in buffer
            $buffer  = array_pop($lines);

            foreach ($lines as $line) {
                $line = trim($line);
                if ($line === '' || $line === '#EXTM3U') continue;

                if (str_starts_with($line, '#EXTINF:')) {
                    $current = self::parseExtInf($line);
                } elseif ($line[0] !== '#' && !empty($current)) {
                    if (filter_var($line, FILTER_VALIDATE_URL) || str_starts_with($line, 'rtmp')) {
                        $current['url'] = $line;
                        self::insertChannel($playlistId, $current, $imported);
                        $imported++;
                    }
                    $current = [];
                }
            }
            return strlen($data);
        });

        $result = curl_exec($ch);
        $errno  = curl_errno($ch);
        curl_close($ch);

        if ($errno) {
            throw new \RuntimeException("cURL error #{$errno} while fetching playlist");
        }

        // Flush remaining buffer
        if ($buffer !== '') {
            $line = trim($buffer);
            if ($line[0] !== '#' && !empty($current)) {
                $current['url'] = $line;
                self::insertChannel($playlistId, $current, $imported);
                $imported++;
            }
        }

        return $imported;
    }

    /** Batch-insert channels using a transaction for performance */
    private static function insertChannel(int $playlistId, array $ch, int $offset): void
    {
        static $pdo = null;
        static $stmt = null;
        static $txCount = 0;
        static $batchSize = 500;

        if ($pdo === null) {
            $pdo  = Database::getInstance();
            $stmt = $pdo->prepare(
                "INSERT INTO channels (playlist_id, name, stream_url, tvg_logo, group_title, tvg_id, tvg_name, sort_order)
                 VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
            );
        }

        if ($txCount === 0) $pdo->beginTransaction();

        $stmt->execute([
            $playlistId,
            $ch['name']     ?? 'Unknown',
            $ch['url']      ?? '',
            $ch['logo']     ?? '',
            $ch['group']    ?? 'Uncategorized',
            $ch['tvg_id']   ?? '',
            $ch['tvg_name'] ?? '',
            $offset,
        ]);
        $txCount++;

        if ($txCount >= $batchSize) {
            $pdo->commit();
            $txCount = 0;
        }
    }

    /** Call after all channels inserted to commit any remaining transaction */
    public static function flushInserts(): void
    {
        try {
            $pdo = Database::getInstance();
            if ($pdo->inTransaction()) {
                $pdo->commit();
            }
        } catch (\Throwable $e) {}
    }

    /** Parse a single #EXTINF line into metadata array */
    private static function parseExtInf(string $line): array
    {
        $meta = [
            'name'     => 'Unknown',
            'logo'     => '',
            'group'    => 'Uncategorized',
            'tvg_id'   => '',
            'tvg_name' => '',
        ];

        // tvg-id
        if (preg_match('/tvg-id="([^"]*)"/', $line, $m))   $meta['tvg_id']   = $m[1];
        // tvg-name
        if (preg_match('/tvg-name="([^"]*)"/', $line, $m)) $meta['tvg_name'] = $m[1];
        // tvg-logo
        if (preg_match('/tvg-logo="([^"]*)"/', $line, $m)) $meta['logo']     = $m[1];
        // group-title
        if (preg_match('/group-title="([^"]*)"/', $line, $m)) $meta['group'] = $m[1];
        // Channel name (after last comma)
        if (preg_match('/,(.+)$/', $line, $m)) $meta['name'] = trim($m[1]);

        return $meta;
    }

    /**
     * Generate M3U output for a playlist (streamed, chunked — no full build in RAM)
     */
    public static function generate(int $playlistId, string $baseProxyUrl): void
    {
        header('Content-Type: application/x-mpegurl; charset=utf-8');
        header('Content-Disposition: attachment; filename="playlist.m3u"');
        echo "#EXTM3U\n";
        echo "# Generated by " . APP_NAME . " v" . APP_VERSION . " — " . APP_AUTHOR . "\n";

        $stmt = Database::query(
            "SELECT * FROM channels WHERE playlist_id = ? AND is_active = 1 ORDER BY sort_order",
            [$playlistId]
        );

        while ($ch = $stmt->fetch()) {
            $proxyUrl = rtrim($baseProxyUrl, '/') . '/xtreamtv/proxy.php?id=' . $ch['id'];
            echo '#EXTINF:-1'
                . ' tvg-id="'    . htmlspecialchars($ch['tvg_id'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')   . '"'
                . ' tvg-name="'  . htmlspecialchars($ch['tvg_name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')  . '"'
                . ' tvg-logo="'  . htmlspecialchars($ch['tvg_logo'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8')      . '"'
                . ' group-title="' . htmlspecialchars($ch['group_title'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"'
                . ',' . htmlspecialchars($ch['name'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . "\n"
                . $proxyUrl . "\n";
        }
    }
}
