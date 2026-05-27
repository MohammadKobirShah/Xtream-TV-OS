<?php
/**
 * ============================================================
 *  XtreamTV — Xtream Codes API Compatible Layer
 *  Supports TiviMate, IPTV Smarters, GSE, Perfect Player, etc.
 *  Developer: Kobir Shah
 * ============================================================
 */

declare(strict_types=1);

class XtreamAPI
{
    private array $user;

    public function __construct(array $user)
    {
        $this->user = $user;
    }

    /** Route API action */
    public function handle(string $action, array $params): void
    {
        header('Content-Type: application/json');
        header('X-Powered-By: XtreamTV/' . APP_VERSION . ' by Kobir Shah');

        match ($action) {
            'get_live_categories'  => $this->getLiveCategories(),
            'get_live_streams'     => $this->getLiveStreams($params),
            'get_vod_categories'   => $this->respondEmpty(),
            'get_vod_streams'      => $this->respondEmpty(),
            'get_series_categories'=> $this->respondEmpty(),
            'get_series'           => $this->respondEmpty(),
            default                => $this->userInfo(),
        };
    }

    /** /player_api.php?username=X&password=X — user info */
    public function userInfo(): void
    {
        $expires = $this->user['expires_at']
            ? date('Y-m-d H:i:s', (int)$this->user['expires_at'])
            : '2099-12-31 23:59:59';

        $activeSessions = (int)Database::query(
            "SELECT COUNT(*) FROM stream_sessions WHERE user_id = ? AND last_ping > ?",
            [$this->user['id'], time() - 30]
        )->fetchColumn();

        echo json_encode([
            'user_info' => [
                'username'         => $this->user['username'],
                'password'         => $this->user['api_token'],
                'message'          => 'Powered by XtreamTV — ' . APP_AUTHOR,
                'auth'             => 1,
                'status'           => 'Active',
                'exp_date'         => $expires,
                'is_trial'         => '0',
                'active_cons'      => (string)$activeSessions,
                'created_at'       => (string)$this->user['created_at'],
                'max_connections'  => (string)$this->user['max_streams'],
                'allowed_output_formats' => ['m3u8', 'ts'],
            ],
            'server_info' => [
                'url'            => parse_url(APP_URL, PHP_URL_HOST),
                'port'           => '80',
                'https_port'     => '443',
                'server_protocol'=> 'http',
                'rtmp_port'      => '1935',
                'timezone'       => 'UTC',
                'timestamp_now'  => time(),
                'time_now'       => date('Y-m-d H:i:s'),
                'process'        => 'XtreamTV',
                'credit'         => APP_AUTHOR,
            ],
        ]);
    }

    /** GET /player_api.php?action=get_live_categories */
    private function getLiveCategories(): void
    {
        $groups = Database::query(
            "SELECT DISTINCT c.group_title
             FROM channels c
             JOIN playlists p ON p.id = c.playlist_id
             WHERE p.user_id = ? AND c.is_active = 1
             ORDER BY c.group_title",
            [$this->user['id']]
        )->fetchAll(PDO::FETCH_COLUMN);

        $out = [];
        foreach ($groups as $i => $g) {
            $out[] = [
                'category_id'   => (string)($i + 1),
                'category_name' => $g,
                'parent_id'     => 0,
            ];
        }
        echo json_encode($out);
    }

    /** GET /player_api.php?action=get_live_streams[&category_id=X] */
    private function getLiveStreams(array $params): void
    {
        // Map category_id back to group name
        $categoryFilter = '';
        $binds = [$this->user['id']];

        if (!empty($params['category_id'])) {
            $groups = Database::query(
                "SELECT DISTINCT group_title FROM channels c
                 JOIN playlists p ON p.id = c.playlist_id
                 WHERE p.user_id = ? AND c.is_active = 1 ORDER BY group_title",
                [$this->user['id']]
            )->fetchAll(PDO::FETCH_COLUMN);

            $idx = (int)$params['category_id'] - 1;
            if (isset($groups[$idx])) {
                $categoryFilter = " AND c.group_title = ?";
                $binds[]        = $groups[$idx];
            }
        }

        $channels = Database::query(
            "SELECT c.* FROM channels c
             JOIN playlists p ON p.id = c.playlist_id
             WHERE p.user_id = ? AND c.is_active = 1{$categoryFilter}
             ORDER BY c.sort_order",
            $binds
        )->fetchAll();

        $out = [];
        foreach ($channels as $ch) {
            $streamUrl = APP_URL . '/xtreamtv/proxy.php?id=' . $ch['id'] . '&t=' . urlencode($this->user['api_token']);
            $out[] = [
                'num'             => $ch['id'],
                'name'            => $ch['name'],
                'stream_type'     => 'live',
                'stream_id'       => $ch['id'],
                'stream_icon'     => $ch['logo'] ?? '',
                'epg_channel_id'  => $ch['tvg_id'] ?? '',
                'added'           => (string)$ch['created_at'],
                'category_id'     => '1',
                'custom_sid'      => '',
                'tv_archive'      => 0,
                'direct_source'   => $streamUrl,
                'tv_archive_duration' => 0,
            ];
        }
        echo json_encode($out);
    }

    private function respondEmpty(): void
    {
        echo json_encode([]);
    }

    /** Handle /live/{username}/{password}/{id}.ts and /live/.../id.m3u8 */
    public static function handleDirectStream(string $username, string $password, int $streamId, string $ext): void
    {
        $user = Security::authenticateToken($username, $password);
        if (!$user) {
            http_response_code(401);
            echo json_encode(['error' => 'Unauthorized', 'credit' => APP_AUTHOR]);
            exit;
        }

        $channel = Database::query(
            "SELECT c.* FROM channels c
             JOIN playlists p ON p.id = c.playlist_id
             WHERE c.id = ? AND c.is_active = 1 AND p.user_id = ?",
            [$streamId, $user['id']]
        )->fetch();

        if (!$channel) {
            http_response_code(404);
            echo json_encode(['error' => 'Stream not found', 'credit' => APP_AUTHOR]);
            exit;
        }

        if (!Security::validateStreamUrl($channel['stream_url'])) {
            http_response_code(403);
            echo json_encode(['error' => 'Blocked', 'credit' => APP_AUTHOR]);
            exit;
        }

        StreamProxy::pipe($channel['stream_url'], (int)$user['id']);
    }
}
