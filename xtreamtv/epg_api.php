<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — EPG API Endpoint
 * ============================================================
 *  Developer        : Kobir Shah
 *  DEVELOPER_CREDIT : Powered by Kobir Shah
 *
 *  BugFix: self::formatProgram() called in procedural file
 *          (no class context) → changed to plain formatProgram()
 *  BugFix: array_map('formatProgram', ...) called before
 *          function definition — hoisted above call site.
 *  BugFix: Missing require_once for StreamPassthru / engine.
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/epg.php';
require_once __DIR__ . '/auth.php';

// ── Headers ───────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Developer: Kobir Shah');
header('X-Powered-By: XtreamTV/' . APP_VERSION . ' by Kobir Shah');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

// ── Auth: token or session ─────────────────────────────────────
$user  = null;
$token = trim($_GET['t'] ?? $_GET['token'] ?? '');
if ($token) {
    $user = Auth::byToken($token);
}
if (!$user && Auth::check()) {
    $user = Auth::user();
}
if (!$user) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized', 'credit' => DEVELOPER_CREDIT]);
    exit;
}

// ── Helper: format a programme row for API output ──────────────
// FIX: defined BEFORE any call site to avoid "undefined function" fatal
function formatProgram(array $p): array
{
    return [
        'id'          => (int)$p['id'],
        'channel'     => $p['channel_tvg_id'],
        'title'       => $p['title'],
        'description' => $p['description'] ?? '',
        'category'    => $p['category']    ?? '',
        'icon'        => $p['icon']        ?? '',
        'start'       => date('Y-m-d H:i:s', (int)$p['start_time']),
        'stop'        => date('Y-m-d H:i:s', (int)$p['stop_time']),
        'start_ts'    => (int)$p['start_time'],
        'stop_ts'     => (int)$p['stop_time'],
        'duration'    => EPGEngine::formatDuration((int)$p['start_time'], (int)$p['stop_time']),
        'progress'    => round(EPGEngine::programProgress($p), 1),
    ];
}

$action = trim($_GET['action'] ?? 'current_next');

// ── ACTION: import (admin only) ────────────────────────────────
if ($action === 'import') {
    if (!$user['is_admin']) {
        http_response_code(403);
        echo json_encode(['error' => 'Admin required', 'credit' => DEVELOPER_CREDIT]);
        exit;
    }

    $epgUrl     = trim($_GET['epg_url'] ?? '');
    $playlistId = (int)($_GET['playlist_id'] ?? 0);

    if (!$epgUrl) {
        http_response_code(400);
        echo json_encode(['error' => 'epg_url required', 'credit' => DEVELOPER_CREDIT]);
        exit;
    }

    try {
        set_time_limit(600);
        $count = EPGEngine::importEPG($epgUrl, $playlistId);
        echo json_encode([
            'success'     => true,
            'imported'    => $count,
            'message'     => "Imported {$count} EPG programmes",
            'epg_url'     => $epgUrl,
            'playlist_id' => $playlistId,
            'credit'      => DEVELOPER_CREDIT,
            'generated'   => date('Y-m-d H:i:s'),
        ]);
    } catch (\Throwable $e) {
        http_response_code(500);
        echo json_encode(['error' => $e->getMessage(), 'credit' => DEVELOPER_CREDIT]);
    }
    exit;
}

// ── ACTION: batch (multiple channels) ─────────────────────────
if ($action === 'batch') {
    $rawIds = trim($_GET['tvg_ids'] ?? '');
    if (!$rawIds) {
        http_response_code(400);
        echo json_encode(['error' => 'tvg_ids required (comma-separated)', 'credit' => DEVELOPER_CREDIT]);
        exit;
    }

    $tvgIds = array_filter(array_map('trim', explode(',', $rawIds)));
    if (count($tvgIds) > 50) {
        http_response_code(400);
        echo json_encode(['error' => 'Max 50 channels per batch request', 'credit' => DEVELOPER_CREDIT]);
        exit;
    }

    $data   = EPGEngine::getBatchEPG($tvgIds);
    $result = [];

    foreach ($data as $id => $programs) {
        // FIX: was self::formatProgram() — no class context here
        $result[$id] = [
            'current' => $programs['current'] ? formatProgram($programs['current']) : null,
            'next'    => $programs['next']    ? formatProgram($programs['next'])    : null,
        ];
    }

    echo json_encode([
        'data'      => $result,
        'count'     => count($result),
        'timestamp' => time(),
        'credit'    => DEVELOPER_CREDIT,
    ]);
    exit;
}

// ── ACTION: schedule (N-hour schedule) ────────────────────────
if ($action === 'schedule') {
    $tvgId = trim($_GET['tvg_id'] ?? '');
    $hours = min(24, max(1, (int)($_GET['hours'] ?? 6)));

    if (!$tvgId) {
        http_response_code(400);
        echo json_encode(['error' => 'tvg_id required', 'credit' => DEVELOPER_CREDIT]);
        exit;
    }

    $programs  = EPGEngine::getSchedule($tvgId, $hours);
    // FIX: was array_map('formatProgram', ...) before function definition
    $formatted = array_map('formatProgram', $programs);

    echo json_encode([
        'tvg_id'        => $tvgId,
        'hours'         => $hours,
        'program_count' => count($formatted),
        'schedule'      => $formatted,
        'credit'        => DEVELOPER_CREDIT,
        'generated'     => date('Y-m-d H:i:s'),
    ]);
    exit;
}

// ── DEFAULT: current + next for single channel ─────────────────
$tvgId = trim($_GET['tvg_id'] ?? '');
if (!$tvgId) {
    http_response_code(400);
    echo json_encode(['error' => 'tvg_id parameter required', 'credit' => DEVELOPER_CREDIT]);
    exit;
}

$current = EPGEngine::getCurrentProgram($tvgId);
$next    = EPGEngine::getNextProgram($tvgId);

echo json_encode([
    'tvg_id'    => $tvgId,
    'current'   => $current ? formatProgram($current) : null,
    'next'      => $next    ? formatProgram($next)    : null,
    'timestamp' => time(),
    'credit'    => DEVELOPER_CREDIT,
    'developer' => 'Kobir Shah',
]);
