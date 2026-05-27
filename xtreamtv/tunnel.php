<?php
declare(strict_types=1);

$tunnelFile = '/tmp/tunnel-url.txt';
$pidFile    = '/tmp/cloudflared.pid';
$logFile    = '/tmp/cloudflared.log';

$tunnelUrl = null;
$pid       = null;
$running   = false;

if (file_exists($pidFile)) {
    $pid = (int) trim(file_get_contents($pidFile) ?: '0');
    $running = $pid > 0 && is_dir("/proc/$pid");
}

if (file_exists($tunnelFile)) {
    $contents = trim(file_get_contents($tunnelFile) ?: '');
    if ($contents !== '') {
        $tunnelUrl = $contents;
    }
}

if ($tunnelUrl === null && file_exists($logFile)) {
    $log = file_get_contents($logFile) ?: '';
    if (preg_match('/https?:\/\/[a-z0-9-]+\.trycloudflare\.com/', $log, $m)) {
        $tunnelUrl = $m[0];
    }
}

$platform = getenv('PORT') ? 'railway' : 'docker';
$port     = getenv('PORT') ?: '80';

if ($tunnelUrl) {
    $status = 'active';
} elseif ($running) {
    $status = 'starting';
} elseif (!getenv('PORT')) {
    $status = 'unavailable';
} else {
    $status = 'error';
}

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
echo json_encode([
    'tunnel_url' => $tunnelUrl,
    'status'     => $status,
    'platform'   => $platform,
    'port'       => $port,
    'pid'        => $pid,
    'running'    => $running,
    'developer'  => 'Kobir Shah',
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
