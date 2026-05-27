<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — EPG (XMLTV) Engine
 *  Phase 5: EPG Integration
 * ============================================================
 *  Developer        : Kobir Shah
 *  DEVELOPER_CREDIT : Powered by Kobir Shah
 *  Handles          : XMLTV download, parse, cache, mapping,
 *                     current/next program lookup
 * ============================================================
 */

declare(strict_types=1);

if (!defined('APP_NAME')) {
    require_once __DIR__ . '/config.php';
}

/**
 * EPGEngine — Downloads, parses, and queries XMLTV program guides.
 *
 * Architecture:
 *  - Downloads XMLTV XML file (gzip supported via cURL)
 *  - Parses using XMLReader for streaming (handles 100MB+ EPG files)
 *  - Stores parsed programmes in SQLite epg_programs table
 *  - Maps by tvg-id for channel correlation
 *  - Cache TTL controlled by settings.epg_cache_hours
 */
final class EPGEngine
{
    private const CREDIT = 'Kobir Shah';

    // ══════════════════════════════════════════════════════
    //  SECTION 1: DOWNLOAD & PARSE XMLTV
    // ══════════════════════════════════════════════════════

    /**
     * Download an XMLTV EPG file and parse it into the database.
     *
     * @param  string $epgUrl    Remote XMLTV URL (.xml or .xml.gz)
     * @param  int    $playlistId Associate programmes with this playlist
     * @return int    Number of programmes imported
     * @throws RuntimeException on fetch/parse failure
     */
    public static function importEPG(string $epgUrl, int $playlistId = 0): int
    {
        // ── Validate URL (SSRF guard) ────────────────────
        if (class_exists('M3UEngine')) {
            M3UEngine::assertSafeUrl($epgUrl);
        }

        // ── Determine local cache path ───────────────────
        $cacheKey  = md5($epgUrl . $playlistId);
        $localFile = STORAGE_PATH . '/epg/epg_' . $cacheKey . '.xml';
        $cacheHours = (int)(Database::query("SELECT value FROM settings WHERE key='epg_cache_hours'")->fetchColumn() ?: 12);

        // ── Download if cache expired ────────────────────
        if (!file_exists($localFile) || (time() - filemtime($localFile)) > ($cacheHours * 3600)) {
            $downloaded = self::downloadEPG($epgUrl, $localFile);
            if (!$downloaded) {
                throw new \RuntimeException("Failed to download EPG from: {$epgUrl}");
            }
            error_log("[XtreamTV][EPG] Downloaded EPG: {$epgUrl} — " . self::CREDIT);
        }

        // ── Parse the XML file ───────────────────────────
        $count = self::parseXMLTV($localFile, $playlistId);

        // ── Update playlist EPG sync time ────────────────
        if ($playlistId) {
            Database::query(
                "UPDATE playlists SET epg_url = ?, last_synced = strftime('%s','now') WHERE id = ?",
                [$epgUrl, $playlistId]
            );
        }

        error_log("[XtreamTV][EPG] Imported {$count} programmes — " . self::CREDIT);
        return $count;
    }

    /**
     * Download EPG file via cURL with gzip support.
     * Saves directly to disk to avoid loading into RAM.
     *
     * @param  string $url       Remote XMLTV URL
     * @param  string $savePath  Local file path to write to
     * @return bool   True on success
     */
    private static function downloadEPG(string $url, string $savePath): bool
    {
        $dir = dirname($savePath);
        if (!is_dir($dir)) mkdir($dir, 0750, true);

        $fp = fopen($savePath, 'wb');
        if (!$fp) return false;

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_FILE           => $fp,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 30,
            CURLOPT_TIMEOUT        => 300,   // 5 min for large EPG files
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'XtreamTV/' . APP_VERSION . ' (Kobir Shah)',
            CURLOPT_ENCODING       => 'gzip', // Auto-decompress .gz files
        ]);

        $ok    = curl_exec($ch);
        $errno = curl_errno($ch);
        curl_close($ch);
        fclose($fp);

        if ($errno || !$ok) {
            @unlink($savePath);
            return false;
        }

        // ── Decompress if still gzip (cURL may not auto-decompress) ──
        if (self::isGzipped($savePath)) {
            $decompressed = $savePath . '.xml';
            if (self::decompressGzip($savePath, $decompressed)) {
                rename($decompressed, $savePath);
            }
        }

        return file_exists($savePath) && filesize($savePath) > 100;
    }

    /**
     * Parse an XMLTV file using XMLReader (streaming — low memory).
     * Extracts <programme> elements and stores them in epg_programs table.
     *
     * XMLTV format reference:
     *   <programme start="20240101120000 +0000" stop="20240101130000 +0000" channel="cnn.us">
     *     <title lang="en">Breaking News</title>
     *     <desc>Live coverage...</desc>
     *     <category>News</category>
     *     <icon src="..."/>
     *   </programme>
     *
     * @param  string $xmlFile   Path to local XMLTV file
     * @param  int    $playlistId Playlist ID for association
     * @return int    Number of programmes parsed
     */
    private static function parseXMLTV(string $xmlFile, int $playlistId): int
    {
        if (!file_exists($xmlFile) || filesize($xmlFile) < 10) {
            throw new \RuntimeException("EPG file invalid or empty: {$xmlFile}");
        }

        // ── Clear old EPG data for this playlist ─────────
        if ($playlistId) {
            Database::query("DELETE FROM epg_programs WHERE playlist_id = ?", [$playlistId]);
        }

        // ── Stream-parse with XMLReader ──────────────────
        $reader = new \XMLReader();
        if (!$reader->open($xmlFile, 'UTF-8', LIBXML_NOERROR | LIBXML_NOWARNING)) {
            throw new \RuntimeException("Cannot open EPG XML file");
        }

        $pdo   = Database::getInstance();
        $stmt  = $pdo->prepare(
            "INSERT INTO epg_programs (channel_tvg_id, title, start_time, stop_time, description, category, icon, playlist_id)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );

        $count = 0;
        $batchSize = 500;
        $pdo->beginTransaction();

        while ($reader->read()) {
            if ($reader->nodeType !== \XMLReader::ELEMENT || $reader->localName !== 'programme') {
                continue;
            }

            // Parse <programme> attributes
            $channelId = $reader->getAttribute('channel') ?? '';
            $startRaw  = $reader->getAttribute('start')   ?? '';
            $stopRaw   = $reader->getAttribute('stop')    ?? '';

            if (!$channelId || !$startRaw) continue;

            $startTime = self::parseXMLTVDate($startRaw);
            $stopTime  = self::parseXMLTVDate($stopRaw);

            // Parse inner elements
            $title = ''; $desc = ''; $category = ''; $icon = '';
            $inner = new \XMLReader();
            $inner->XML($reader->readOuterXml());

            while ($inner->read()) {
                if ($inner->nodeType !== \XMLReader::ELEMENT) continue;
                match ($inner->localName) {
                    'title'    => $title    = trim($inner->readString()),
                    'desc'     => $desc     = trim($inner->readString()),
                    'category' => $category = trim($inner->readString()),
                    'icon'     => $icon     = $inner->getAttribute('src') ?? '',
                    default    => null,
                };
            }
            $inner->close();

            if (!$title) continue;

            $stmt->execute([$channelId, $title, $startTime, $stopTime, $desc, $category, $icon, $playlistId ?: null]);
            $count++;

            // Batch commit
            if ($count % $batchSize === 0) {
                $pdo->commit();
                $pdo->beginTransaction();
            }
        }

        $pdo->commit();
        $reader->close();

        return $count;
    }

    // ══════════════════════════════════════════════════════
    //  SECTION 2: QUERY API
    // ══════════════════════════════════════════════════════

    /**
     * Get the currently airing program for a channel.
     *
     * @param  string $tvgId  The tvg-id of the channel
     * @param  int    $atTime Unix timestamp (defaults to now)
     * @return array|null     Programme data or null
     */
    public static function getCurrentProgram(string $tvgId, int $atTime = 0): ?array
    {
        if (!$atTime) $atTime = time();
        return Database::query(
            "SELECT * FROM epg_programs
             WHERE channel_tvg_id = ?
               AND start_time <= ?
               AND stop_time  >  ?
             ORDER BY start_time DESC LIMIT 1",
            [$tvgId, $atTime, $atTime]
        )->fetch() ?: null;
    }

    /**
     * Get the next program after the current one.
     *
     * @param  string $tvgId  The tvg-id of the channel
     * @param  int    $atTime Unix timestamp (defaults to now)
     * @return array|null
     */
    public static function getNextProgram(string $tvgId, int $atTime = 0): ?array
    {
        if (!$atTime) $atTime = time();
        return Database::query(
            "SELECT * FROM epg_programs
             WHERE channel_tvg_id = ?
               AND start_time > ?
             ORDER BY start_time ASC LIMIT 1",
            [$tvgId, $atTime]
        )->fetch() ?: null;
    }

    /**
     * Get the schedule for a channel over the next N hours.
     *
     * @param  string $tvgId  Channel tvg-id
     * @param  int    $hours  How many hours ahead (default 6)
     * @return array  Array of programme rows
     */
    public static function getSchedule(string $tvgId, int $hours = 6): array
    {
        $now = time();
        $end = $now + ($hours * 3600);
        return Database::query(
            "SELECT * FROM epg_programs
             WHERE channel_tvg_id = ?
               AND stop_time > ?
               AND start_time < ?
             ORDER BY start_time ASC",
            [$tvgId, $now, $end]
        )->fetchAll();
    }

    /**
     * Get current + next programs for multiple channels at once.
     * Used by the API for batch EPG queries.
     *
     * @param  array $tvgIds  Array of tvg-id strings
     * @return array  Keyed by tvg-id → ['current'=>[...], 'next'=>[...]]
     */
    public static function getBatchEPG(array $tvgIds): array
    {
        $result = [];
        foreach ($tvgIds as $id) {
            $result[$id] = [
                'current' => self::getCurrentProgram($id),
                'next'    => self::getNextProgram($id),
            ];
        }
        return $result;
    }

    /**
     * Calculate percentage of current program elapsed (for progress bar).
     *
     * @param  array $program  Program row from epg_programs
     * @return float  0.0–100.0
     */
    public static function programProgress(array $program): float
    {
        $now      = time();
        $total    = (int)$program['stop_time'] - (int)$program['start_time'];
        $elapsed  = $now - (int)$program['start_time'];
        if ($total <= 0) return 0.0;
        return min(100.0, max(0.0, ($elapsed / $total) * 100));
    }

    // ══════════════════════════════════════════════════════
    //  SECTION 3: UTILITIES
    // ══════════════════════════════════════════════════════

    /**
     * Convert XMLTV date string to Unix timestamp.
     * Format: "20240101120000 +0000" or "20240101120000 +0530"
     */
    private static function parseXMLTVDate(string $dateStr): int
    {
        $dateStr = trim($dateStr);
        if (empty($dateStr)) return 0;

        // Normalize: "20240101120000 +0000" → "20240101120000+0000"
        $normalized = str_replace(' ', '', $dateStr);

        // Try DateTimeImmutable first (most accurate)
        try {
            // Insert colon into timezone offset: +0000 → +00:00
            if (preg_match('/^(\d{14})([\+\-]\d{4})$/', $normalized, $m)) {
                $tzFormatted = substr($m[2], 0, 3) . ':' . substr($m[2], 3);
                $dt = new \DateTimeImmutable($m[1] . $tzFormatted);
                return $dt->getTimestamp();
            }
        } catch (\Throwable) {}

        // Fallback: strtotime
        $ts = strtotime($dateStr);
        return ($ts !== false) ? $ts : 0;
    }

    /**
     * Check if a file is gzip-compressed.
     */
    private static function isGzipped(string $file): bool
    {
        $fh = fopen($file, 'rb');
        if (!$fh) return false;
        $bytes = fread($fh, 2);
        fclose($fh);
        return $bytes === "\x1f\x8b";
    }

    /**
     * Decompress a gzip file.
     */
    private static function decompressGzip(string $source, string $dest): bool
    {
        $gz = gzopen($source, 'rb');
        $fp = fopen($dest, 'wb');
        if (!$gz || !$fp) return false;
        while (!gzeof($gz)) {
            fwrite($fp, gzread($gz, 65536));
        }
        gzclose($gz);
        fclose($fp);
        return file_exists($dest);
    }

    /**
     * Format seconds duration as H:MM.
     */
    public static function formatDuration(int $start, int $stop): string
    {
        $seconds = $stop - $start;
        $h = (int)floor($seconds / 3600);
        $m = (int)floor(($seconds % 3600) / 60);
        return $h > 0 ? "{$h}h {$m}m" : "{$m}m";
    }
}
