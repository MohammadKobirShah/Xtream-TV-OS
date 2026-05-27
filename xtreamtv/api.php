<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/epg.php';

error_log('[XtreamTV][API] Request: ' . ($_SERVER['REQUEST_URI'] ?? '') . ' — Kobir Shah');

header('Content-Type: application/json; charset=utf-8');
header('X-Developer: Kobir Shah');
header('X-Powered-By: XtreamTV/' . APP_VERSION);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Cache-Control: no-store, no-cache');

$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

match ($action) {
    'get_live_categories'  => getLiveCategories(),
    'get_live_streams'     => getLiveStreams(),
    'get_vod_categories'   => getVodCategories(),
    'get_vod_streams'      => getVodStreams(),
    'get_vod_info'         => getVodInfo(),
    'get_series_categories'=> (function() { echo json_encode([]); })(),
    'get_series'           => (function() { echo json_encode([]); })(),
    'get_short_epg'        => getShortEPG(),
    'get_epg_channels'     => getEPGChannels(),
    default                => userInfo(),
};

function userInfo(): void
{
    echo json_encode([
        'user_info' => [
            'username'        => 'xtreamtv',
            'password'        => APP_VERSION,
            'message'         => DEVELOPER_CREDIT . ' | XtreamTV v' . APP_VERSION,
            'auth'            => 1,
            'status'          => 'Active',
            'exp_date'        => '2099-12-31 23:59:59',
            'is_trial'        => '0',
            'active_cons'     => '0',
            'created_at'      => (string)time(),
            'max_connections' => '10',
            'allowed_output_formats' => ['m3u8', 'ts', 'rtmp'],
        ],
        'server_info' => serverInfo(),
        'credit'      => DEVELOPER_CREDIT,
        'developer'   => 'Kobir Shah',
    ]);
}

function getLiveCategories(): void
{
    $groups = Database::query(
        "SELECT DISTINCT c.group_title
         FROM channels c
         WHERE c.is_active = 1 AND c.stream_type = 'live'
         ORDER BY c.group_title"
    )->fetchAll(\PDO::FETCH_COLUMN);

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

function getLiveStreams(): void
{
    [$where, $params] = buildStreamFilter('live');
    $channels = Database::query(
        "SELECT c.*, p.name AS playlist_name
         FROM channels c
         JOIN playlists p ON p.id = c.playlist_id
         WHERE {$where}
         ORDER BY c.sort_order, c.name",
        $params
    )->fetchAll();

    $out = [];
    foreach ($channels as $ch) {
        $streamUrl = APP_URL . '/xtreamtv/proxy.php?id=' . $ch['id'];
        $out[] = [
            'num'              => $ch['id'],
            'name'             => $ch['name'],
            'stream_type'      => 'live',
            'stream_id'        => $ch['id'],
            'stream_icon'      => $ch['tvg_logo'] ?? '',
            'epg_channel_id'   => $ch['tvg_id']   ?? '',
            'added'            => (string)($ch['added_at'] ?? time()),
            'category_id'      => '1',
            'custom_sid'       => '',
            'tv_archive'       => 0,
            'direct_source'    => $streamUrl,
            'tv_archive_duration' => 0,
        ];
    }
    echo json_encode($out);
}

function getVodCategories(): void
{
    $groups = Database::query(
        "SELECT DISTINCT c.group_title
         FROM channels c
         WHERE c.is_active = 1 AND c.stream_type = 'vod'
         ORDER BY c.group_title"
    )->fetchAll(\PDO::FETCH_COLUMN);

    $out = [];
    foreach ($groups as $i => $g) {
        $out[] = ['category_id' => (string)($i + 100), 'category_name' => $g, 'parent_id' => 0];
    }
    echo json_encode($out);
}

function getVodStreams(): void
{
    [$where, $params] = buildStreamFilter('vod');
    $channels = Database::query(
        "SELECT c.* FROM channels c
         JOIN playlists p ON p.id = c.playlist_id
         WHERE {$where} ORDER BY c.name",
        $params
    )->fetchAll();

    $out = [];
    foreach ($channels as $ch) {
        $streamUrl = APP_URL . '/xtreamtv/proxy.php?id=' . $ch['id'];
        $out[] = [
            'num'           => $ch['id'],
            'name'          => $ch['name'],
            'stream_type'   => 'movie',
            'stream_id'     => $ch['id'],
            'stream_icon'   => $ch['tvg_logo'] ?? '',
            'category_id'   => '100',
            'container_extension' => 'mp4',
            'direct_source' => $streamUrl,
            'added'         => (string)($ch['added_at'] ?? time()),
        ];
    }
    echo json_encode($out);
}

function getVodInfo(): void
{
    $id = (int)($_GET['vod_id'] ?? 0);
    $ch = Database::query("SELECT * FROM channels WHERE id = ? AND is_active = 1", [$id])->fetch();
    if (!$ch) { echo json_encode([]); return; }

    $proxyUrl = APP_URL . '/xtreamtv/proxy.php?id=' . $ch['id'];
    echo json_encode([
        'info' => [
            'name'       => $ch['name'],
            'cover'      => $ch['tvg_logo'] ?? '',
            'plot'       => '',
            'cast'       => '',
            'director'   => '',
            'genre'      => $ch['group_title'] ?? '',
            'releasedate'=> '',
            'rating'     => '',
        ],
        'movie_data' => [
            'stream_id'           => $ch['id'],
            'name'                => $ch['name'],
            'direct_source'       => $proxyUrl,
            'container_extension' => 'mp4',
        ],
        'credit' => DEVELOPER_CREDIT,
    ]);
}

function getShortEPG(): void
{
    $streamId = (int)($_GET['stream_id'] ?? 0);
    $ch = Database::query("SELECT tvg_id FROM channels WHERE id = ?", [$streamId])->fetch();
    if (!$ch || !$ch['tvg_id']) { echo json_encode(['epg_listings' => [], 'credit' => DEVELOPER_CREDIT]); return; }

    $current = EPGEngine::getCurrentProgram($ch['tvg_id']);
    $next    = EPGEngine::getNextProgram($ch['tvg_id']);
    $listings = [];
    foreach (array_filter([$current, $next]) as $p) {
        $listings[] = [
            'id'          => $p['id'],
            'epg_id'      => $ch['tvg_id'],
            'title'       => base64_encode($p['title']),
            'lang'        => 'en',
            'start'       => date('Y-m-d H:i:s', (int)$p['start_time']),
            'end'         => date('Y-m-d H:i:s', (int)$p['stop_time']),
            'description' => base64_encode($p['description'] ?? ''),
            'channel_id'  => $ch['tvg_id'],
            'start_timestamp' => (string)$p['start_time'],
            'stop_timestamp'  => (string)$p['stop_time'],
        ];
    }
    echo json_encode(['epg_listings' => $listings, 'credit' => DEVELOPER_CREDIT]);
}

function getEPGChannels(): void
{
    $channels = Database::query(
        "SELECT DISTINCT c.tvg_id, c.name, c.tvg_logo FROM channels c
         WHERE c.is_active = 1 AND c.tvg_id != ''"
    )->fetchAll();

    $out = [];
    foreach ($channels as $ch) {
        $current = EPGEngine::getCurrentProgram($ch['tvg_id']);
        $out[] = [
            'id'           => $ch['tvg_id'],
            'name'         => $ch['name'],
            'icon'         => $ch['tvg_logo'] ?? '',
            'current'      => $current ? [
                'title' => $current['title'],
                'start' => date('H:i', (int)$current['start_time']),
                'stop'  => date('H:i', (int)$current['stop_time']),
            ] : null,
        ];
    }
    echo json_encode(['channels' => $out, 'credit' => DEVELOPER_CREDIT]);
}

function buildStreamFilter(string $streamType): array
{
    $where  = "c.is_active = 1 AND c.stream_type = ?";
    $params = [$streamType];

    if (!empty($_GET['category_id'])) {
        $idx = (int)$_GET['category_id'] - ($streamType === 'vod' ? 100 : 1);
        $groups = Database::query(
            "SELECT DISTINCT group_title FROM channels c
             WHERE c.stream_type = ?
             ORDER BY group_title",
            [$streamType]
        )->fetchAll(\PDO::FETCH_COLUMN);
        if (isset($groups[$idx])) {
            $where    .= " AND c.group_title = ?";
            $params[]  = $groups[$idx];
        }
    }
    return [$where, $params];
}

function serverInfo(): array
{
    $parsed = parse_url(APP_URL);
    return [
        'xui'              => true,
        'version'          => '1.5.12',
        'revision'         => APP_VERSION,
        'url'              => $parsed['host'] ?? 'localhost',
        'port'             => '80',
        'https_port'       => '443',
        'server_protocol'  => 'http',
        'rtmp_port'        => '1935',
        'timezone'         => 'UTC',
        'timestamp_now'    => time(),
        'time_now'         => date('Y-m-d H:i:s'),
        'process'          => 'XtreamTV',
        'credit'           => DEVELOPER_CREDIT,
        'developer'        => 'Kobir Shah',
    ];
}
