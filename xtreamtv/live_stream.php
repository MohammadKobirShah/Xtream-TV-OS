<?php
/**
 * ============================================================
 *  XtreamTV — Direct Stream Route Handler
 *  Handles: /live/{id}.ts
 *           /live/{id}.m3u8
 *  Developer: Kobir Shah
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/StreamProxy.php';
require_once __DIR__ . '/src/XtreamAPI.php';

$id  = (int)($_GET['id']  ?? 0);
$ext = strtolower(trim($_GET['ext'] ?? 'ts'));

if (!$id) {
    http_response_code(400);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Bad request', 'credit' => APP_AUTHOR]);
    exit;
}

XtreamAPI::handleDirectStream($id, $ext);
