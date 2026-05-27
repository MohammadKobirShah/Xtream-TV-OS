<?php
declare(strict_types=1);

define('INSTALL_MODE', true);
define('DEVELOPER_CREDIT', 'Powered by Kobir Shah');
define('APP_VERSION_INSTALL', '2.0.1');

$isCli = in_array('--cli', $argv ?? [], true);

$dbPath      = __DIR__ . '/storage/database.sqlite';
$storagePath = __DIR__ . '/storage';
$cachePath   = __DIR__ . '/storage/cache';
$logPath     = __DIR__ . '/storage/logs';
$epgPath     = __DIR__ . '/storage/epg';

$steps   = [];
$success = true;

foreach ([$storagePath, $cachePath, $logPath, $epgPath] as $dir) {
    if (!is_dir($dir)) {
        if (mkdir($dir, 0750, true)) {
            $steps[] = ['ok', "Created directory: {$dir}"];
        } else {
            $steps[] = ['err', "Failed to create directory: {$dir}"];
            $success = false;
        }
    } else {
        $steps[] = ['skip', "Directory exists: {$dir}"];
    }
}

$htaccess = $storagePath . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Order allow,deny\nDeny from all\n");
    $steps[] = ['ok', 'Protected storage/.htaccess written'];
}

try {
    $pdo = new PDO("sqlite:{$dbPath}", options: [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    $pdo->exec("PRAGMA journal_mode=WAL");
    $pdo->exec("PRAGMA synchronous=NORMAL");
    $pdo->exec("PRAGMA cache_size=-32000");
    $pdo->exec("PRAGMA foreign_keys=ON");
    $steps[] = ['ok', "SQLite database created: {$dbPath}"];
} catch (PDOException $e) {
    $steps[] = ['err', "Database connection failed: " . $e->getMessage()];
    $success = false;
}

if ($success) {
    $schema = <<<SQL

    CREATE TABLE IF NOT EXISTS playlists (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        name          TEXT    NOT NULL,
        source_type   TEXT    NOT NULL DEFAULT 'm3u_url',
        source_config TEXT,
        url           TEXT,
        epg_url       TEXT,
        source_file   TEXT,
        cache_file    TEXT,
        channel_count INTEGER DEFAULT 0,
        is_active     INTEGER NOT NULL DEFAULT 1,
        last_synced   INTEGER,
        added_at      INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    );

    CREATE TABLE IF NOT EXISTS channels (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        playlist_id   INTEGER NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
        tvg_id        TEXT    DEFAULT '',
        tvg_name      TEXT    DEFAULT '',
        tvg_logo      TEXT    DEFAULT '',
        group_title   TEXT    DEFAULT 'Uncategorized',
        name          TEXT    NOT NULL,
        stream_url    TEXT    NOT NULL,
        stream_type   TEXT    DEFAULT 'live',
        ffmpeg_mode   TEXT    DEFAULT 'inherit',
        sort_order    INTEGER DEFAULT 0,
        is_active     INTEGER NOT NULL DEFAULT 1,
        added_at      INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    );

    CREATE TABLE IF NOT EXISTS settings (
        key           TEXT PRIMARY KEY,
        value         TEXT,
        updated_at    INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    );

    CREATE TABLE IF NOT EXISTS epg_programs (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        channel_tvg_id TEXT   NOT NULL,
        title         TEXT    NOT NULL,
        start_time    INTEGER NOT NULL,
        stop_time     INTEGER NOT NULL,
        description   TEXT    DEFAULT '',
        category      TEXT    DEFAULT '',
        icon          TEXT    DEFAULT '',
        playlist_id   INTEGER REFERENCES playlists(id) ON DELETE CASCADE
    );

    CREATE INDEX IF NOT EXISTS idx_channels_playlist  ON channels(playlist_id);
    CREATE INDEX IF NOT EXISTS idx_channels_group     ON channels(group_title);
    CREATE INDEX IF NOT EXISTS idx_channels_tvgid     ON channels(tvg_id);
    CREATE INDEX IF NOT EXISTS idx_epg_tvgid          ON epg_programs(channel_tvg_id);
    CREATE INDEX IF NOT EXISTS idx_epg_times          ON epg_programs(start_time, stop_time);

    SQL;

    try {
        $pdo->exec($schema);
        $steps[] = ['ok', 'All database tables created successfully'];
    } catch (PDOException $e) {
        $steps[] = ['err', 'Schema migration failed: ' . $e->getMessage()];
        $success = false;
    }
}

if ($success) {
    $defaults = [
        ['site_name',        'XtreamTV IPTV OS'],
        ['site_version',     APP_VERSION_INSTALL],
        ['developer',        'Kobir Shah'],
        ['developer_credit', DEVELOPER_CREDIT],
        ['proxy_useragent',  'Mozilla/5.0 (SmartTV; Linux) XtreamTV/2.0'],
        ['max_cache_age',    '3600'],
        ['epg_cache_hours',  '12'],
        ['installed_at',     (string)time()],
    ];

    $stmt = $pdo->prepare(
        "INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)"
    );
    foreach ($defaults as [$k, $v]) {
        $stmt->execute([$k, $v]);
    }
    $steps[] = ['ok', 'Default settings seeded (' . count($defaults) . ' entries)'];
}

if ($success) {
    file_put_contents($storagePath . '/installed.lock', json_encode([
        'installed_at' => date('Y-m-d H:i:s'),
        'version'      => APP_VERSION_INSTALL,
        'credit'       => DEVELOPER_CREDIT,
    ]));
    $steps[] = ['ok', 'Install lock written to storage/installed.lock'];
}

if ($isCli):
    echo "\n";
    echo "╔══════════════════════════════════════════════════════╗\n";
    echo "║   ⚡ XtreamTV IPTV OS — Installer v" . APP_VERSION_INSTALL . "            ║\n";
    echo "║   " . DEVELOPER_CREDIT . "                   ║\n";
    echo "╚══════════════════════════════════════════════════════╝\n";
    echo "\n";
    foreach ($steps as [$type, $msg]) {
        $icon = match($type) { 'ok' => '✓', 'err' => '✗', 'skip' => '→' };
        echo "  [$icon] $msg\n";
    }
    echo "\n";
    if ($success) {
        echo "  🎉 Installation Complete!\n";
    } else {
        echo "  ❌ Installation Failed — check storage/ permissions\n";
    }
    echo "  " . DEVELOPER_CREDIT . "\n";
    echo "\n";
else:
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>XtreamTV Installer — by Kobir Shah</title>
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: #050508; color: #e2e8f0; min-height: 100vh;
    display: flex; align-items: center; justify-content: center;
    background-image: linear-gradient(rgba(0,180,255,0.03) 1px, transparent 1px),
                      linear-gradient(90deg,rgba(0,180,255,0.03) 1px,transparent 1px);
    background-size: 40px 40px;
  }
  .card {
    background: rgba(10,10,22,0.95); border: 1px solid rgba(99,102,241,0.2);
    border-radius: 20px; padding: 40px; width: min(640px, 95vw);
    box-shadow: 0 24px 80px rgba(0,0,0,0.6);
  }
  .logo { font-size: 2rem; font-weight: 800; background: linear-gradient(135deg,#00b4ff,#a855f7); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
  .subtitle { font-size: 0.8rem; color: #64748b; letter-spacing: 2px; text-transform: uppercase; margin-top: 4px; }
  h2 { font-size: 1.1rem; margin: 24px 0 16px; color: #94a3b8; }
  .step { display: flex; align-items: flex-start; gap: 12px; padding: 10px 14px; border-radius: 10px; margin-bottom: 8px; font-size: 0.85rem; }
  .step.ok   { background: rgba(16,185,129,0.08); border: 1px solid rgba(16,185,129,0.2); color: #6ee7b7; }
  .step.err  { background: rgba(239,68,68,0.08);  border: 1px solid rgba(239,68,68,0.2);  color: #fca5a5; }
  .step.skip { background: rgba(99,102,241,0.06); border: 1px solid rgba(99,102,241,0.15); color: #94a3b8; }
  .icon { font-size: 1rem; flex-shrink: 0; margin-top: 1px; }
  .result {
    margin-top: 24px; padding: 20px; border-radius: 14px; text-align: center;
    background: <?= $success ? 'rgba(16,185,129,0.1)' : 'rgba(239,68,68,0.1)' ?>;
    border: 1px solid <?= $success ? 'rgba(16,185,129,0.3)' : 'rgba(239,68,68,0.3)' ?>;
  }
  .result-title { font-size: 1.3rem; font-weight: 800; color: <?= $success ? '#10b981' : '#ef4444' ?>; margin-bottom: 8px; }
  footer { margin-top: 28px; text-align: center; font-size: 0.72rem; color: #475569; }
  .credit { background: linear-gradient(90deg,#00b4ff,#a855f7); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; font-weight: 700; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">⚡ XtreamTV</div>
  <div class="subtitle">IPTV OS Installer v<?= APP_VERSION_INSTALL ?></div>

  <h2>📦 Installation Steps</h2>
  <?php foreach ($steps as [$type, $msg]): ?>
  <div class="step <?= $type ?>">
    <span class="icon"><?= match($type) { 'ok'=>'✅', 'err'=>'❌', 'skip'=>'⏭' } ?></span>
    <span><?= $msg ?></span>
  </div>
  <?php endforeach; ?>

  <div class="result">
    <div class="result-title"><?= $success ? '🎉 Installation Complete!' : '❌ Installation Failed' ?></div>
    <?php if ($success): ?>
    <div style="font-size:0.85rem;color:#94a3b8">Database ready. Add playlists from the dashboard.</div>
    <a href="index.php" class="btn" style="display:inline-block;margin-top:16px;padding:11px 24px;border-radius:10px;font-size:0.9rem;font-weight:700;text-decoration:none;background:linear-gradient(135deg,#00b4ff,#7c3aed);color:#fff;box-shadow:0 4px 20px rgba(0,180,255,0.25);">⚡ Go to Dashboard</a>
    <?php else: ?>
    <div style="font-size:0.85rem;color:#94a3b8">Check file permissions on <code>storage/</code> directory and try again.</div>
    <?php endif; ?>
  </div>

  <footer>
    <?= DEVELOPER_CREDIT ?> &nbsp;|&nbsp; XtreamTV v<?= APP_VERSION_INSTALL ?> &nbsp;|&nbsp;
    © <?= date('Y') ?> <span class="credit">Kobir Shah</span>
  </footer>
</div>
</body>
</html>
<?php endif; ?>
