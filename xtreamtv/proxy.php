<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — Universal Proxy Endpoint
 * ============================================================
 *  Developer        : Kobir Shah
 *  DEVELOPER_CREDIT : Powered by Kobir Shah
 *
 *  BugFix: Added missing require_once for StreamPassthru.php
 *  BugFix: FFmpeg mode now reads from DB settings (admin panel)
 *  BugFix: Per-channel ffmpeg_mode override supported
 *  BugFix: mode=auto tries passthru first, falls back to FFmpeg
 *
 *  Endpoints:
 *    ?url=BASE64&t=TOKEN              → auto-mode (from settings)
 *    ?url=BASE64&t=TOKEN&mode=on      → force FFmpeg
 *    ?url=BASE64&t=TOKEN&mode=off     → force passthru
 *    ?id=CHANNEL_ID&t=TOKEN           → per-channel mode from DB
 *    ?action=m3u&t=TOKEN              → M3U download
 *    ?action=m3u8manifest&url=&t=     → HLS manifest rewrite
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/StreamPassthru.php';  // FIX: was missing
require_once __DIR__ . '/src/FFmpegProxy.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/auth.php';

error_log('[XtreamTV][Proxy] ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ' — ' . DEVELOPER_CREDIT);

// ── Auth via token ─────────────────────────────────────────────
$token = trim($_GET['t'] ?? $_GET['token'] ?? '');
$user  = Auth::byToken($token);

if (!$user) {
    if (Auth::check()) {
        $user = Auth::user();
    } else {
        http_response_code(401);
        header('Content-Type: application/json');
        header('X-Developer: Kobir Shah');
        echo json_encode(['error' => 'Unauthorized', 'credit' => DEVELOPER_CREDIT]);
        exit;
    }
}

$action    = trim($_GET['action'] ?? '');
$channelId = (int)($_GET['id']    ?? 0);

// ── ACTION: Download M3U playlist ─────────────────────────────
if ($action === 'm3u') {
    $playlistId = (int)($_GET['playlist_id'] ?? 0);
    $proxyBase  = APP_URL . '/xtreamtv/proxy.php';
    header('X-Developer: Kobir Shah');

    if ($playlistId) {
        $pl = Database::query(
            "SELECT * FROM playlists WHERE id = ? AND user_id = ?",
            [$playlistId, $user['id']]
        )->fetch();

        if (!$pl) {
            http_response_code(404);
            echo json_encode(['error' => 'Playlist not found', 'credit' => DEVELOPER_CREDIT]);
            exit;
        }

        $channels = Database::query(
            "SELECT * FROM channels WHERE playlist_id = ? AND is_active = 1 ORDER BY sort_order",
            [$playlistId]
        )->fetchAll();

        M3UEngine::generateM3U($channels, $proxyBase . '?t=' . urlencode($token));
    } else {
        header('Content-Type: application/x-mpegurl; charset=utf-8');
        header('Content-Disposition: attachment; filename="xtreamtv_all.m3u"');
        echo "#EXTM3U\n# XtreamTV v" . APP_VERSION . " — " . DEVELOPER_CREDIT . "\n";

        $stmt = Database::query(
            "SELECT c.* FROM channels c
             JOIN playlists p ON p.id = c.playlist_id
             WHERE p.user_id = ? AND c.is_active = 1
             ORDER BY c.group_title, c.sort_order",
            [$user['id']]
        );
        while ($ch = $stmt->fetch()) {
            $proxiedUrl = APP_URL . '/xtreamtv/proxy.php?url='
                . base64_encode($ch['stream_url']) . '&t=' . urlencode($token);
            echo '#EXTINF:-1'
                . ' tvg-id="'      . htmlspecialchars($ch['tvg_id']      ?? '', ENT_QUOTES, 'UTF-8') . '"'
                . ' tvg-name="'    . htmlspecialchars($ch['tvg_name']    ?? '', ENT_QUOTES, 'UTF-8') . '"'
                . ' tvg-logo="'    . htmlspecialchars($ch['tvg_logo']    ?? '', ENT_QUOTES, 'UTF-8') . '"'
                . ' group-title="' . htmlspecialchars($ch['group_title'] ?? '', ENT_QUOTES, 'UTF-8') . '"'
                . ',' . htmlspecialchars($ch['name'], ENT_QUOTES, 'UTF-8') . "\n"
                . $proxiedUrl . "\n";
        }
    }
    exit;
}

// ── ACTION: Rewrite HLS manifest ──────────────────────────────
if ($action === 'm3u8manifest') {
    $rawUrl    = trim($_GET['url'] ?? '');
    $proxyBase = APP_URL . '/xtreamtv/proxy.php?t=' . urlencode($token);

    if (!$rawUrl) {
        http_response_code(400);
        echo json_encode(['error' => 'url required', 'credit' => DEVELOPER_CREDIT]);
        exit;
    }

    $decodedUrl = base64_decode($rawUrl, strict: true);
    $streamUrl  = ($decodedUrl !== false && filter_var($decodedUrl, FILTER_VALIDATE_URL))
        ? $decodedUrl
        : (filter_var($rawUrl, FILTER_VALIDATE_URL) ? $rawUrl : null);

    if (!$streamUrl) {
        http_response_code(400);
        echo json_encode(['error' => 'Invalid URL', 'credit' => DEVELOPER_CREDIT]);
        exit;
    }

    try {
        M3UEngine::proxyM3U8Manifest($streamUrl, $proxyBase . '&action=m3u8manifest&url=');
    } catch (\Throwable $e) {
        http_response_code(502);
        echo '#EXTM3U8-error: ' . $e->getMessage();
    }
    exit;
}

// ── Resolve stream URL + per-channel FFmpeg mode ───────────────
$streamUrl         = null;
$channelFfmpegMode = 'inherit'; // will be resolved below

if ($channelId > 0) {
    $ch = Database::query(
        "SELECT c.stream_url, c.ffmpeg_mode FROM channels c
         JOIN playlists p ON p.id = c.playlist_id
         WHERE c.id = ? AND c.is_active = 1 AND p.user_id = ?",
        [$channelId, $user['id']]
    )->fetch();

    if (!$ch) {
        http_response_code(404);
        header('Content-Type: application/json');
        header('X-Developer: Kobir Shah');
        echo json_encode(['error' => 'Channel not found', 'credit' => DEVELOPER_CREDIT]);
        exit;
    }
    $streamUrl         = $ch['stream_url'];
    $channelFfmpegMode = $ch['ffmpeg_mode'] ?? 'inherit';

} elseif (!empty($_GET['url'])) {
    $rawUrl    = trim($_GET['url']);
    $decoded   = base64_decode($rawUrl, strict: true);
    $streamUrl = ($decoded !== false && (filter_var($decoded, FILTER_VALIDATE_URL) || str_starts_with($decoded, 'rtmp')))
        ? $decoded
        : (filter_var($rawUrl, FILTER_VALIDATE_URL) ? $rawUrl : null);

    if (!$streamUrl) {
        http_response_code(400);
        header('Content-Type: application/json');
        header('X-Developer: Kobir Shah');
        echo json_encode(['error' => 'Invalid stream URL', 'credit' => DEVELOPER_CREDIT]);
        exit;
    }
}

if (!$streamUrl) {
    http_response_code(400);
    header('Content-Type: application/json');
    header('X-Developer: Kobir Shah');
    echo json_encode([
        'error'     => 'Provide ?id=CHANNEL_ID or ?url=BASE64_URL with ?t=TOKEN',
        'product'   => APP_NAME . ' v' . APP_VERSION,
        'credit'    => DEVELOPER_CREDIT,
        'developer' => 'Kobir Shah',
    ]);
    exit;
}

// ── Expiry check ───────────────────────────────────────────────
if (!empty($user['expires_at']) && (int)$user['expires_at'] < time()) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Account expired', 'credit' => DEVELOPER_CREDIT]);
    exit;
}

// ── Concurrent stream limit ────────────────────────────────────
$activeCount = (int)Database::query(
    "SELECT COUNT(*) FROM stream_sessions WHERE user_id = ? AND last_ping > ?",
    [$user['id'], time() - 30]
)->fetchColumn();

$maxStreams = (int)($user['max_streams'] ?? 1);
if ($activeCount >= $maxStreams) {
    http_response_code(429);
    header('Content-Type: application/json');
    header('X-Developer: Kobir Shah');
    echo json_encode([
        'error'  => "Max concurrent streams ({$maxStreams}) reached",
        'credit' => DEVELOPER_CREDIT,
    ]);
    exit;
}

// ── SSRF guard ────────────────────────────────────────────────
try {
    M3UEngine::assertSafeUrl($streamUrl);
} catch (\Throwable $e) {
    http_response_code(403);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Blocked: ' . $e->getMessage(), 'credit' => DEVELOPER_CREDIT]);
    exit;
}

// ════════════════════════════════════════════════════════════════
//  DETERMINE FFmpeg MODE
//  Priority: URL param > per-channel DB setting > global setting
//
//  Global (admin panel):  settings.ffmpeg_mode = off|on|auto
//  Per-channel override:  channels.ffmpeg_mode = inherit|off|on|auto
//  URL override:          ?mode=off|on|auto  (for testing)
// ════════════════════════════════════════════════════════════════
$globalMode  = Database::setting('ffmpeg_mode', 'off');    // admin panel value
$quality     = Database::setting('ffmpeg_quality', 'passthru');

// Resolve per-channel override
$resolvedMode = ($channelFfmpegMode !== 'inherit')
    ? $channelFfmpegMode
    : $globalMode;

// URL param can always override (for testing/debugging)
$urlMode = trim($_GET['mode'] ?? '');
if (in_array($urlMode, ['off', 'on', 'auto'], strict: true)) {
    $resolvedMode = $urlMode;
}

// ── Route to correct proxy mode ───────────────────────────────
$userId = (int)$user['id'];

if ($resolvedMode === 'on') {
    // ── FFmpeg mode ON: always use FFmpeg ─────────────────────
    FFmpegProxy::stream($streamUrl, $userId, $quality);

} elseif ($resolvedMode === 'auto') {
    // ── FFmpeg mode AUTO: try passthru, fallback to FFmpeg ─────
    // We attempt passthru first; if cURL fails immediately,
    // FFmpegProxy takes over. Simple header probe determines viability.
    $probeOk = self_probeStream($streamUrl);
    if ($probeOk) {
        StreamPassthru::pipe($streamUrl, $userId, $token);
    } else {
        error_log('[XtreamTV][Proxy] Auto-mode: passthru probe failed, switching to FFmpeg — Kobir Shah');
        FFmpegProxy::stream($streamUrl, $userId, $quality);
    }

} else {
    // ── FFmpeg mode OFF (default): fpassthru passthru ─────────
    StreamPassthru::pipe($streamUrl, $userId, $token);
}

/**
 * Quick HEAD probe to check if the stream URL is directly reachable.
 * Used in 'auto' mode to decide passthru vs FFmpeg.
 */
function self_probeStream(string $url): bool
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_NOBODY         => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 8,
        CURLOPT_FOLLOWLOCATION => true,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_USERAGENT      => 'Mozilla/5.0 (SmartTV; Tizen 6.0) XtreamTV/2.0',
    ]);
    curl_exec($ch);
    $code  = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $errno = curl_errno($ch);
    curl_close($ch);
    return ($errno === 0 && $code >= 200 && $code < 400);
}
