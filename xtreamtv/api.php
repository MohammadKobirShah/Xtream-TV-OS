<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — Xtream Codes API (Full Emulation)
 *  Phase 4: Xtream Codes API Compatibility
 * ============================================================
 *  Developer        : Kobir Shah
 *  DEVELOPER_CREDIT : Powered by Kobir Shah
 *
 *  Compatible with:
 *    TiviMate, IPTV Smarters Pro, GSE Smart IPTV,
 *    Perfect Player, Kodi (PVR IPTV Simple), VLC
 *
 *  Supported actions:
 *    (none)                  → user_info + server_info
 *    get_live_categories     → channel group list
 *    get_live_streams        → live channel list
 *    get_vod_categories      → VOD category list
 *    get_vod_streams         → VOD stream list
 *    get_vod_info            → VOD metadata
 *    get_series_categories   → Series categories
 *    get_series              → Series list
 *    get_short_epg           → Current + next EPG for stream
 *    get_epg_channels        → EPG for multiple channels
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/epg.php';
require_once __DIR__ . '/auth.php';

error_log('[XtreamTV][API] Request: ' . ($_SERVER['REQUEST_URI'] ?? '') . ' — Kobir Shah');

// ── Security Headers ──────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Developer: Kobir Shah');               // ← Credit in EVERY API response header
header('X-Powered-By: XtreamTV/' . APP_VERSION);
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST');
header('Cache-Control: no-store, no-cache');

// ── Authentication ────────────────────────────────────────
$username = trim($_GET['username'] ?? $_POST['username'] ?? '');
$password = trim($_GET['password'] ?? $_POST['password'] ?? '');

if (!$username || !$password) {
    echo json_encode([
        'user_info'   => ['auth' => 0, 'message' => 'Missing credentials'],
        'server_info' => serverInfo(),
        'credit'      => DEVELOPER_CREDIT,
    ]);
    exit;
}

$user = Auth::attempt($username, $password);

if (!$user) {
    // Try token-based auth (password IS the API token)
    $user = Auth::byUsernameToken($username, $password);
}

if (!$user) {
    http_response_code(401);
    echo json_encode([
        'user_info'   => ['auth' => 0, 'message' => 'Invalid credentials'],
        'server_info' => serverInfo(),
        'credit'      => DEVELOPER_CREDIT,
    ]);
    exit;
}

// ── Route to action ───────────────────────────────────────
$action = trim($_GET['action'] ?? $_POST['action'] ?? '');

match ($action) {
    'get_live_categories'  => getLiveCategories($user),
    'get_live_streams'     => getLiveStreams($user),
    'get_vod_categories'   => getVodCategories($user),
    'get_vod_streams'      => getVodStreams($user),
    'get_vod_info'         => getVodInfo($user),
    'get_series_categories'=> echo(json_encode([])), // Placeholder
    'get_series'           => echo(json_encode([])),
    'get_short_epg'        => getShortEPG($user),
    'get_epg_channels'     => getEPGChannels($user),
    default                => userInfo($user),        // No action = user/server info
};

// ════════════════════════════════════════════════════════════
//  ACTION HANDLERS
// ════════════════════════════════════════════════════════════

/** No action → User Info + Server Info */
function userInfo(array $user): void
{
    $active = (int)Database::query(
        "SELECT COUNT(*) FROM stream_sessions WHERE user_id = ? AND last_ping > ?",
        [$user['id'], time() - 30]
    )->fetchColumn();

    $expires = $user['expires_at']
        ? date('Y-m-d H:i:s', (int)$user['expires_at'])
        : '2099-12-31 23:59:59';

    echo json_encode([
        'user_info' => [
            'username'        => $user['username'],
            'password'        => $user['api_token'],
            'message'         => DEVELOPER_CREDIT . ' | XtreamTV v' . APP_VERSION,
            'auth'            => 1,
            'status'          => 'Active',
            'exp_date'        => $expires,
            'is_trial'        => '0',
            'active_cons'     => (string)$active,
            'created_at'      => (string)($user['created_at'] ?? time()),
            'max_connections' => (string)($user['max_streams'] ?? 1),
            'allowed_output_formats' => ['m3u8', 'ts', 'rtmp'],
        ],
        'server_info' => serverInfo(),
        'credit'      => DEVELOPER_CREDIT,   // ← Kobir Shah in every response body
        'developer'   => 'Kobir Shah',
    ]);
}

/** action=get_live_categories */
function getLiveCategories(array $user): void
{
    $groups = Database::query(
        "SELECT DISTINCT c.group_title
         FROM channels c
         JOIN playlists p ON p.id = c.playlist_id
         WHERE p.user_id = ? AND c.is_active = 1 AND c.stream_type = 'live'
         ORDER BY c.group_title",
        [$user['id']]
    )->fetchAll(\PDO::FETCH_COLUMN);

    $out = [];
    foreach ($groups as $i => $g) {
        $out[] = [
            'category_id'   => (string)($i + 1),
            'category_name' => $g,
            'parent_id'     => 0,
        ];
    }

    header('X-Developer: Kobir Shah'); // Repeated on every response
    echo json_encode($out);
}

/** action=get_live_streams[&category_id=N] */
function getLiveStreams(array $user): void
{
    [$where, $params] = buildStreamFilter($user, 'live');
    $channels = Database::query(
        "SELECT c.*, p.name AS playlist_name
         FROM channels c
         JOIN playlists p ON p.id = c.playlist_id
         WHERE {$where}
         ORDER BY c.sort_order, c.name",
        $params
    )->fetchAll();

    $proxyBase = APP_URL . '/xtreamtv/proxy.php';
    $out = [];
    foreach ($channels as $ch) {
        $streamUrl = $proxyBase . '?url=' . base64_encode($ch['stream_url']) . '&t=' . urlencode($user['api_token']);
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
            'direct_source'    => $streamUrl,      // Points to our proxy — hides origin
            'tv_archive_duration' => 0,
        ];
    }

    echo json_encode($out);
}

/** action=get_vod_categories */
function getVodCategories(array $user): void
{
    $groups = Database::query(
        "SELECT DISTINCT c.group_title
         FROM channels c
         JOIN playlists p ON p.id = c.playlist_id
         WHERE p.user_id = ? AND c.is_active = 1 AND c.stream_type = 'vod'
         ORDER BY c.group_title",
        [$user['id']]
    )->fetchAll(\PDO::FETCH_COLUMN);

    $out = [];
    foreach ($groups as $i => $g) {
        $out[] = ['category_id' => (string)($i + 100), 'category_name' => $g, 'parent_id' => 0];
    }
    echo json_encode($out);
}

/** action=get_vod_streams[&category_id=N] */
function getVodStreams(array $user): void
{
    [$where, $params] = buildStreamFilter($user, 'vod');
    $channels = Database::query(
        "SELECT c.* FROM channels c
         JOIN playlists p ON p.id = c.playlist_id
         WHERE {$where} ORDER BY c.name",
        $params
    )->fetchAll();

    $proxyBase = APP_URL . '/xtreamtv/proxy.php';
    $out = [];
    foreach ($channels as $ch) {
        $streamUrl = $proxyBase . '?url=' . base64_encode($ch['stream_url']) . '&t=' . urlencode($user['api_token']);
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

/** action=get_vod_info&vod_id=N */
function getVodInfo(array $user): void
{
    $id = (int)($_GET['vod_id'] ?? 0);
    $ch = Database::query("SELECT * FROM channels WHERE id = ? AND is_active = 1", [$id])->fetch();
    if (!$ch) { echo json_encode([]); return; }

    $proxyUrl = APP_URL . '/xtreamtv/proxy.php?url=' . base64_encode($ch['stream_url']) . '&t=' . urlencode($user['api_token']);
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

/** action=get_short_epg&stream_id=N[&limit=2] */
function getShortEPG(array $user): void
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

/** action=get_epg_channels */
function getEPGChannels(array $user): void
{
    $channels = Database::query(
        "SELECT DISTINCT c.tvg_id, c.name, c.tvg_logo FROM channels c
         JOIN playlists p ON p.id = c.playlist_id
         WHERE p.user_id = ? AND c.is_active = 1 AND c.tvg_id != ''",
        [$user['id']]
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

// ════════════════════════════════════════════════════════════
//  SHARED HELPERS
// ════════════════════════════════════════════════════════════

/** Build channel WHERE clause with optional category filter */
function buildStreamFilter(array $user, string $streamType): array
{
    $where  = "p.user_id = ? AND c.is_active = 1 AND c.stream_type = ?";
    $params = [$user['id'], $streamType];

    if (!empty($_GET['category_id'])) {
        // Map numeric category_id back to group_title
        $idx = (int)$_GET['category_id'] - ($streamType === 'vod' ? 100 : 1);
        $groups = Database::query(
            "SELECT DISTINCT group_title FROM channels c
             JOIN playlists p ON p.id = c.playlist_id
             WHERE p.user_id = ? AND c.stream_type = ?
             ORDER BY group_title",
            [$user['id'], $streamType]
        )->fetchAll(\PDO::FETCH_COLUMN);

        if (isset($groups[$idx])) {
            $where    .= " AND c.group_title = ?";
            $params[]  = $groups[$idx];
        }
    }
    return [$where, $params];
}

/** Build server_info block (injected in every response) */
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
        'credit'           => DEVELOPER_CREDIT,  // ← Kobir Shah in server_info block
        'developer'        => 'Kobir Shah',
    ];
}
