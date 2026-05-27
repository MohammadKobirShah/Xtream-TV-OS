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

    /** /player_api.php — user info (no auth required) */
    public function userInfo(): void
    {
        echo json_encode([
            'user_info' => [
                'username'         => 'xtreamtv',
                'password'         => '',
                'message'          => 'Powered by XtreamTV — ' . APP_AUTHOR,
                'auth'             => 1,
                'status'           => 'Active',
                'exp_date'         => '2099-12-31 23:59:59',
                'is_trial'         => '0',
                'active_cons'      => '0',
                'created_at'       => (string)time(),
                'max_connections'  => '999',
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
             WHERE c.is_active = 1
             ORDER BY c.group_title"
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
        $categoryFilter = '';
        $binds = [];

        if (!empty($params['category_id'])) {
            $groups = Database::query(
                "SELECT DISTINCT group_title FROM channels c
                 WHERE c.is_active = 1 ORDER BY group_title"
            )->fetchAll(PDO::FETCH_COLUMN);

            $idx = (int)$params['category_id'] - 1;
            if (isset($groups[$idx])) {
                $categoryFilter = " AND c.group_title = ?";
                $binds[]        = $groups[$idx];
            }
        }

        $channels = Database::query(
            "SELECT c.* FROM channels c
             WHERE c.is_active = 1{$categoryFilter}
             ORDER BY c.sort_order",
            $binds
        )->fetchAll();

        $out = [];
        foreach ($channels as $ch) {
            $streamUrl = APP_URL . '/xtreamtv/proxy.php?id=' . $ch['id'];
            $out[] = [
                'num'             => $ch['id'],
                'name'            => $ch['name'],
                'stream_type'     => 'live',
                'stream_id'       => $ch['id'],
                'stream_icon'     => $ch['tvg_logo'] ?? '',
                'epg_channel_id'  => $ch['tvg_id'] ?? '',
                'added'           => (string)($ch['added_at'] ?? time()),
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

    /** Handle /live/{id}.ts and /live/{id}.m3u8 (no auth required) */
    public static function handleDirectStream(int $streamId, string $ext = 'ts'): void
    {
        $channel = Database::query(
            "SELECT c.* FROM channels c
             WHERE c.id = ? AND c.is_active = 1",
            [$streamId]
        )->fetch();

        if (!$channel) {
            http_response_code(404);
            echo json_encode(['error' => 'Stream not found', 'credit' => APP_AUTHOR]);
            exit;
        }

        if (!(filter_var($channel['stream_url'], FILTER_VALIDATE_URL) || str_starts_with($channel['stream_url'], 'rtmp://') || str_starts_with($channel['stream_url'], 'rtsp://'))) {
            http_response_code(403);
            echo json_encode(['error' => 'Blocked', 'credit' => APP_AUTHOR]);
            exit;
        }

        StreamProxy::pipe($channel['stream_url']);
    }
}
