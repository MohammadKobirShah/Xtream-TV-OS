<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/epg.php';

error_log('[XtreamTV][API] Request from ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ' — Credit: Kobir Shah');

$action = trim($_GET['action'] ?? '');

header('Content-Type: application/json');
header('X-Powered-By: XtreamTV/' . APP_VERSION . ' by Kobir Shah');

match ($action) {
    'get_live_categories'  => getLiveCategories(),
    'get_live_streams'     => getLiveStreams(),
    'get_vod_categories'   => respondEmpty(),
    'get_vod_streams'      => respondEmpty(),
    'get_series_categories'=> respondEmpty(),
    'get_series'           => respondEmpty(),
    default                => userInfo(),
};

function userInfo(): void
{
    echo json_encode([
        'user_info' => [
            'username'         => 'xtreamtv',
            'password'         => APP_VERSION,
            'message'          => 'Powered by XtreamTV — ' . APP_AUTHOR,
            'auth'             => 1,
            'status'           => 'Active',
            'exp_date'         => '2099-12-31 23:59:59',
            'is_trial'         => '0',
            'active_cons'      => '0',
            'created_at'       => (string)time(),
            'max_connections'  => '10',
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

function getLiveCategories(): void
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

function getLiveStreams(): void
{
    $categoryFilter = '';
    $binds = [];

    if (!empty($_GET['category_id'])) {
        $groups = Database::query(
            "SELECT DISTINCT group_title FROM channels c
             WHERE c.is_active = 1 ORDER BY group_title"
        )->fetchAll(PDO::FETCH_COLUMN);

        $idx = (int)$_GET['category_id'] - 1;
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
            'added'           => (string)$ch['added_at'],
            'category_id'     => '1',
            'custom_sid'      => '',
            'tv_archive'      => 0,
            'direct_source'   => $streamUrl,
            'tv_archive_duration' => 0,
        ];
    }
    echo json_encode($out);
}

function respondEmpty(): void
{
    echo json_encode([]);
}
