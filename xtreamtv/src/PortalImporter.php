<?php
declare(strict_types=1);

class PortalImporter
{
    private string $server;
    private string $mac;
    private string $token;
    private string $stbType;
    private string $serial;

    private const STALKER_API = '/stalker_portal/server/load.php';

    public function __construct(string $server, string $mac, string $serial = '')
    {
        $this->server = rtrim($server, '/');
        $this->mac    = strtoupper($mac);
        $this->serial = $serial ?: strtoupper(bin2hex(random_bytes(8)));
        $this->token  = '';
        $this->stbType = '';
    }

    /**
     * Perform STB handshake and authenticate with the portal.
     */
    public function authenticate(): void
    {
        // Step 1: Get STB info (token + stb_type)
        $info = $this->httpGet($this->server . self::STALKER_API . '?type=stb&action=get');
        if (!empty($info['token'])) {
            $this->token = $info['token'];
        }
        $this->stbType = $info['stb_type'] ?? 'MAG200';

        // Step 2: Handshake with MAC
        $this->httpPost($this->server . self::STALKER_API . '?type=stb&action=handshake', [
            'mac'      => $this->mac,
            'stb_type' => $this->stbType,
            'num_banks' => '1',
            'sn'       => $this->serial,
        ]);

        // Step 3: Verify auth with a simple call
        $this->httpGet($this->server . self::STALKER_API . '?type=stb&action=get_profile', [
            'mac' => $this->mac,
            'stb_type' => $this->stbType,
        ]);
    }

    /**
     * Fetch all ITV channels from the portal and import into a new playlist.
     * Returns the new playlist ID.
     */
    public function import(string $playlistName): int
    {
        $this->authenticate();

        // Get all channels
        $channels = $this->httpGet(
            $this->server . self::STALKER_API . '?type=itv&action=get_all_channels&force_ch_link_check=1&JsHttpRequest=1-xml'
        );

        // The response is wrapped in JS-like format: {js: {...}}
        $jsData = $channels['js'] ?? $channels;
        $items  = $jsData['data'] ?? $jsData;
        if (!is_array($items) || isset($items['total_items'])) {
            // Try alternate envelope
            $items = $channels['data'] ?? $channels;
        }
        // Remove count keys
        if (isset($items['total_items'])) unset($items['total_items']);
        if (isset($items['cur_page'])) unset($items['cur_page']);
        if (isset($items['selected_item'])) unset($items['selected_item']);

        // Convert to indexed array if it's an object
        $items = array_values($items);

        if (empty($items)) {
            throw new \RuntimeException('No channels returned from portal. Check MAC address.');
        }

        $config = json_encode([
            'server' => $this->server,
            'mac'    => $this->mac,
        ], JSON_UNESCAPED_SLASHES);

        Database::query(
            "INSERT INTO playlists (name, source_type, source_config, channel_count, last_synced)
             VALUES (?, 'portal', ?, 0, strftime('%s','now'))",
            [$playlistName, $config]
        );
        $playlistId = (int)Database::lastInsertId();

        $count = 0;
        $pdo   = Database::getInstance();
        $stmt  = $pdo->prepare(
            "INSERT INTO channels (playlist_id, name, stream_url, tvg_logo, group_title, tvg_id, stream_type, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, 'live', ?)"
        );

        $pdo->beginTransaction();
        foreach ($items as $i => $ch) {
            // Parse channel data
            $name  = $ch['name'] ?? $ch['number'] ?? 'Unknown';
            $logo  = $ch['logo'] ?? '';
            $cmd   = $ch['cmd'] ?? '';
            $genre = $ch['tv_genre_name'] ?? $ch['genre'] ?? 'Uncategorized';
            $num   = $ch['number'] ?? '';

            // Resolve stream URL from cmd
            $streamUrl = $this->resolveStreamUrl($cmd, $ch);
            if (empty($streamUrl)) continue;

            $tvgId = $num ? (string)$num : (string)$i;

            $stmt->execute([
                $playlistId,
                $name,
                $streamUrl,
                $this->resolveLogoUrl($logo),
                $genre,
                $tvgId,
                $i,
            ]);
            $count++;
        }
        $pdo->commit();

        Database::query(
            "UPDATE playlists SET channel_count = ?, last_synced = strftime('%s','now') WHERE id = ?",
            [$count, $playlistId]
        );

        return $playlistId;
    }

    private function resolveStreamUrl(string $cmd, array $ch): string
    {
        // cmd can be: "ffrt {url}", "http://...", "rtmp://...", etc.
        $cmd = trim($cmd);
        if ($cmd === '') return '';

        // Remove ffrt prefix (used by Stalker)
        if (str_starts_with($cmd, 'ffrt ')) {
            $cmd = trim(substr($cmd, 5));
        }

        // If it's already a URL, use it
        if (filter_var($cmd, FILTER_VALIDATE_URL)) {
            return $cmd;
        }

        // If it's a relative path, prepend server
        if (str_starts_with($cmd, '/')) {
            return $this->server . $cmd;
        }

        // Some portals put internal IDs — skip
        return '';
    }

    private function resolveLogoUrl(string $logo): string
    {
        if ($logo === '') return '';
        if (filter_var($logo, FILTER_VALIDATE_URL)) {
            return $logo;
        }
        if (str_starts_with($logo, '/')) {
            return $this->server . $logo;
        }
        // Could be a relative logo path
        if (!str_starts_with($logo, 'http')) {
            return $this->server . '/' . ltrim($logo, '/');
        }
        return $logo;
    }

    private function httpGet(string $url, array $extraHeaders = []): array
    {
        $ch = curl_init($url);
        $headers = [
            'Authorization: Bearer ' . $this->token,
            'User-Agent: Mozilla/5.0 (QtEmbedded; U; Linux; Android) XtreamTV/1.0',
        ];
        foreach ($extraHeaders as $k => $v) {
            $headers[] = "$k: $v";
        }

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 60,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_HTTPHEADER     => $headers,
        ]);
        $body = curl_exec($ch);
        $err  = curl_errno($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("cURL error #{$err} connecting to portal");
        }
        if ($code !== 200) {
            throw new \RuntimeException("HTTP {$code} from portal");
        }

        // Strip JS wrapper if present: // {...}
        $body = preg_replace('/^\/\/.*/', '', $body);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            // Try stripping stb callback
            if (preg_match('/\{.*\}/s', $body, $m)) {
                $data = json_decode($m[0], true);
            }
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON response from portal');
        }

        return $data;
    }

    private function httpPost(string $url, array $postData): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => http_build_query($postData),
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 30,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'Mozilla/5.0 (QtEmbedded; U; Linux; Android) XtreamTV/1.0',
            CURLOPT_HTTPHEADER     => [
                'Authorization: Bearer ' . $this->token,
                'Content-Type: application/x-www-form-urlencoded',
            ],
        ]);
        $body = curl_exec($ch);
        $err  = curl_errno($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("cURL error #{$err} during portal handshake");
        }
        if ($code !== 200) {
            throw new \RuntimeException("HTTP {$code} during portal handshake");
        }

        $body = preg_replace('/^\/\/.*/', '', $body);
        $data = json_decode($body, true);
        if (!is_array($data)) {
            if (preg_match('/\{.*\}/s', $body, $m)) {
                $data = json_decode($m[0], true);
            }
        }
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON response during portal handshake');
        }

        return $data;
    }
}
