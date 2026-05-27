<?php
declare(strict_types=1);

class XtreamImporter
{
    private string $server;
    private string $username;
    private string $password;
    private string $apiUrl;

    public function __construct(string $server, string $username, string $password)
    {
        $this->server   = rtrim($server, '/');
        $this->username = $username;
        $this->password = $password;
        $this->apiUrl   = $this->server . '/player_api.php?username=' . urlencode($username)
                        . '&password=' . urlencode($password);
    }

    /**
     * Fetch live categories from remote Xtream server.
     * @return array<int, array{category_id: int, category_name: string, parent_id: int}>
     */
    public function getLiveCategories(): array
    {
        $data = $this->apiRequest('get_live_categories');
        return $data['categories'] ?? [];
    }

    /**
     * Fetch all live streams from remote Xtream server.
     * @return array<int, array{num: int, name: string, stream_type: string, stream_id: int, stream_icon: string, epg_channel_id: string, category_id: string, added: string}>
     */
    public function getLiveStreams(): array
    {
        $data = $this->apiRequest('get_live_streams');
        return $data['live_streams'] ?? [];
    }

    /**
     * Import all live streams into a new playlist.
     * Returns the new playlist ID.
     */
    public function import(string $playlistName): int
    {
        $categories = $this->getLiveCategories();
        $catMap = [];
        foreach ($categories as $cat) {
            $catMap[(string)$cat['category_id']] = $cat['category_name'] ?? 'Uncategorized';
        }

        $streams = $this->getLiveStreams();
        if (empty($streams)) {
            throw new \RuntimeException('No live streams returned from server. Check credentials.');
        }

        $config = json_encode([
            'server'   => $this->server,
            'username' => $this->username,
            'password' => $this->password,
        ], JSON_UNESCAPED_SLASHES);

        Database::query(
            "INSERT INTO playlists (name, source_type, source_config, url, channel_count, last_synced)
             VALUES (?, 'xtream', ?, ?, 0, strftime('%s','now'))",
            [$playlistName, $config, $this->apiUrl]
        );
        $playlistId = (int)Database::lastInsertId();

        $count = 0;
        $pdo   = Database::getInstance();
        $stmt  = $pdo->prepare(
            "INSERT INTO channels (playlist_id, name, stream_url, tvg_logo, group_title, tvg_id, stream_type, sort_order)
             VALUES (?, ?, ?, ?, ?, ?, 'live', ?)"
        );

        $pdo->beginTransaction();
        foreach ($streams as $i => $ch) {
            $streamUrl = $this->server . '/live/' . $this->username . '/' . $this->password . '/' . $ch['stream_id'] . '.ts';
            $group     = $catMap[(string)($ch['category_id'] ?? '')] ?? 'Uncategorized';

            $stmt->execute([
                $playlistId,
                $ch['name'] ?? 'Unknown',
                $streamUrl,
                $ch['stream_icon'] ?? '',
                $group,
                $ch['epg_channel_id'] ?? $ch['name'] ?? '',
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

    private function apiRequest(string $action): array
    {
        $url = $this->apiUrl . '&action=' . urlencode($action);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_TIMEOUT        => 120,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_USERAGENT      => 'XtreamTV/' . APP_VERSION . ' (Kobir Shah)',
        ]);
        $body = curl_exec($ch);
        $err  = curl_errno($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($err) {
            throw new \RuntimeException("cURL error #{$err} connecting to Xtream server");
        }
        if ($code !== 200) {
            throw new \RuntimeException("HTTP {$code} from Xtream server");
        }

        $data = json_decode($body, true);
        if (!is_array($data)) {
            throw new \RuntimeException('Invalid JSON response from Xtream server');
        }

        return $data;
    }
}
