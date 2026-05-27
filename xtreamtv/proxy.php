<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — Universal Proxy Endpoint
 * ============================================================
 *  Developer        : Kobir Shah
 *  DEVELOPER_CREDIT : Powered by Kobir Shah
 *
 *  Endpoints:
 *    ?url=BASE64               → auto-mode (from settings)
 *    ?url=BASE64&mode=on       → force FFmpeg
 *    ?url=BASE64&mode=off      → force passthru
 *    ?id=CHANNEL_ID            → per-channel mode from DB
 *    ?action=m3u               → M3U download
 *    ?action=m3u8manifest&url= → HLS manifest rewrite
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/StreamPassthru.php';
require_once __DIR__ . '/src/FFmpegProxy.php';
require_once __DIR__ . '/engine.php';

error_log('[XtreamTV][Proxy] ' . ($_SERVER['REMOTE_ADDR'] ?? '?') . ' — ' . DEVELOPER_CREDIT);

$action    = trim($_GET['action'] ?? '');
$channelId = (int)($_GET['id']    ?? 0);

// ── ACTION: Download M3U playlist ─────────────────────────────
if ($action === 'm3u') {
    $playlistId = (int)($_GET['playlist_id'] ?? 0);
    $proxyBase  = APP_URL . '/xtreamtv/proxy.php';
    header('X-Developer: Kobir Shah');

    if ($playlistId) {
        $pl = Database::query(
            "SELECT * FROM playlists WHERE id = ?",
            [$playlistId]
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

        M3UEngine::generateM3U($channels, $proxyBase);
    } else {
        header('Content-Type: application/x-mpegurl; charset=utf-8');
        header('Content-Disposition: attachment; filename="xtreamtv_all.m3u"');
        echo "#EXTM3U\n# XtreamTV v" . APP_VERSION . " — " . DEVELOPER_CREDIT . "\n";

        $stmt = Database::query(
            "SELECT c.* FROM channels c
             JOIN playlists p ON p.id = c.playlist_id
             WHERE c.is_active = 1
             ORDER BY c.group_title, c.sort_order"
        );
        while ($ch = $stmt->fetch()) {
            $proxiedUrl = APP_URL . '/xtreamtv/proxy.php?url='
                . base64_encode($ch['stream_url']);
            $m3uEscape = fn($v) => str_replace(['\\', '"'], ['\\\\', '\\"'], $v ?? '');
            echo '#EXTINF:-1'
                . ' tvg-id="'      . $m3uEscape($ch['tvg_id'])      . '"'
                . ' tvg-name="'    . $m3uEscape($ch['tvg_name'])    . '"'
                . ' tvg-logo="'    . $m3uEscape($ch['tvg_logo'])    . '"'
                . ' group-title="' . $m3uEscape($ch['group_title']) . '"'
                . ',' . $m3uEscape($ch['name']) . "\n"
                . $proxiedUrl . "\n";
        }
    }
    exit;
}

// ── ACTION: Rewrite HLS manifest ──────────────────────────────
if ($action === 'm3u8manifest') {
    $rawUrl    = trim($_GET['url'] ?? '');
    $proxyBase = APP_URL . '/xtreamtv/proxy.php';

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
        M3UEngine::proxyM3U8Manifest($streamUrl, $proxyBase . '?action=m3u8manifest&url=');
    } catch (\Throwable $e) {
        http_response_code(502);
        echo '#EXTM3U8-error: ' . $e->getMessage();
    }
    exit;
}

// ── Resolve stream URL + per-channel FFmpeg mode ───────────────
$streamUrl         = null;
$channelFfmpegMode = 'inherit';

if ($channelId > 0) {
    $ch = Database::query(
        "SELECT c.stream_url, c.ffmpeg_mode FROM channels c
         WHERE c.id = ? AND c.is_active = 1",
        [$channelId]
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
        'error'     => 'Provide ?id=CHANNEL_ID or ?url=BASE64_URL',
        'product'   => APP_NAME . ' v' . APP_VERSION,
        'credit'    => DEVELOPER_CREDIT,
        'developer' => 'Kobir Shah',
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
$globalMode  = Database::setting('ffmpeg_mode', 'off');
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
if ($resolvedMode === 'on') {
    FFmpegProxy::stream($streamUrl, $quality);

} elseif ($resolvedMode === 'auto') {
    $probeOk = self_probeStream($streamUrl);
    if ($probeOk) {
        StreamPassthru::pipe($streamUrl);
    } else {
        error_log('[XtreamTV][Proxy] Auto-mode: passthru probe failed, switching to FFmpeg — Kobir Shah');
        FFmpegProxy::stream($streamUrl, $quality);
    }

} else {
    StreamPassthru::pipe($streamUrl);
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
