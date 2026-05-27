<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/epg.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Developer: Kobir Shah');
header('X-Powered-By: XtreamTV/' . APP_VERSION . ' by Kobir Shah');
header('Access-Control-Allow-Origin: *');
header('Cache-Control: no-store');

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

if ($action === 'import') {
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

if ($action === 'schedule') {
    $tvgId = trim($_GET['tvg_id'] ?? '');
    $hours = min(24, max(1, (int)($_GET['hours'] ?? 6)));

    if (!$tvgId) {
        http_response_code(400);
        echo json_encode(['error' => 'tvg_id required', 'credit' => DEVELOPER_CREDIT]);
        exit;
    }

    $programs  = EPGEngine::getSchedule($tvgId, $hours);
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
