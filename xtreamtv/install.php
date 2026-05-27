<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — Installer / Database Bootstrap
 *  Phase 1: Foundation & Database Setup
 * ============================================================
 *  Developer  : Kobir Shah
 *  Version    : 2.0.1
 *  PHP        : 8.2+
 *  Run once   : http://yourserver/xtreamtv/install.php
 *  Delete after installation for security!
 * ============================================================
 */

declare(strict_types=1);

// ── Constants (pre-config bootstrap) ────────────────────────
define('INSTALL_MODE', true);
define('DEVELOPER_CREDIT', 'Powered by Kobir Shah');
define('APP_VERSION_INSTALL', '2.0.1');

// ── CLI mode ───────────────────────────────────────────────
// When --cli is passed, output plain text instead of HTML
$isCli = in_array('--cli', $argv ?? [], true);

$dbPath      = __DIR__ . '/storage/database.sqlite';
$storagePath = __DIR__ . '/storage';
$cachePath   = __DIR__ . '/storage/cache';
$logPath     = __DIR__ . '/storage/logs';
$epgPath     = __DIR__ . '/storage/epg';

$steps   = [];
$success = true;

// ── Step 1: Create required directories ────────────────────
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

// Protect storage directory from direct HTTP access
$htaccess = $storagePath . '/.htaccess';
if (!file_exists($htaccess)) {
    file_put_contents($htaccess, "Order allow,deny\nDeny from all\n");
    $steps[] = ['ok', 'Protected storage/.htaccess written'];
}

// ── Step 2: Create & connect to SQLite ─────────────────────
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

// ── Step 3: Create all tables ──────────────────────────────
if ($success) {
    $schema = <<<SQL

    -- ── Users table ─────────────────────────────────────────
    CREATE TABLE IF NOT EXISTS users (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        username      TEXT    NOT NULL UNIQUE COLLATE NOCASE,
        password      TEXT    NOT NULL,                         -- Argon2ID hash
        is_admin      INTEGER NOT NULL DEFAULT 0,               -- 1 = admin
        api_token     TEXT    UNIQUE,                           -- Xtream API token
        max_streams   INTEGER NOT NULL DEFAULT 1,
        expires_at    INTEGER,                                  -- Unix timestamp or NULL
        is_active     INTEGER NOT NULL DEFAULT 1,
        notes         TEXT,
        created_at    INTEGER NOT NULL DEFAULT (strftime('%s','now')),
        last_login    INTEGER,
        last_ip       TEXT
    );

    -- ── Playlists table ──────────────────────────────────────
    CREATE TABLE IF NOT EXISTS playlists (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id       INTEGER REFERENCES users(id) ON DELETE CASCADE,
        name          TEXT    NOT NULL,
        url           TEXT,                                     -- Remote M3U URL
        epg_url       TEXT,                                     -- XMLTV EPG URL
        source_file   TEXT,                                     -- Local file path (if uploaded)
        cache_file    TEXT,                                     -- Parsed JSON cache path
        channel_count INTEGER DEFAULT 0,
        is_active     INTEGER NOT NULL DEFAULT 1,
        last_synced   INTEGER,
        added_at      INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    );

    -- ── Channels table (parsed from M3U) ─────────────────────
    CREATE TABLE IF NOT EXISTS channels (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        playlist_id   INTEGER NOT NULL REFERENCES playlists(id) ON DELETE CASCADE,
        tvg_id        TEXT    DEFAULT '',
        tvg_name      TEXT    DEFAULT '',
        tvg_logo      TEXT    DEFAULT '',
        group_title   TEXT    DEFAULT 'Uncategorized',
        name          TEXT    NOT NULL,
        stream_url    TEXT    NOT NULL,
        stream_type   TEXT    DEFAULT 'live',                   -- live | vod | series
        ffmpeg_mode   TEXT    DEFAULT 'inherit',
        sort_order    INTEGER DEFAULT 0,
        is_active     INTEGER NOT NULL DEFAULT 1,
        added_at      INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    );

    -- ── Settings table (key-value store) ─────────────────────
    CREATE TABLE IF NOT EXISTS settings (
        key           TEXT PRIMARY KEY,
        value         TEXT,
        updated_at    INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    );

    -- ── EPG cache table (parsed XMLTV programs) ───────────────
    CREATE TABLE IF NOT EXISTS epg_programs (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        channel_tvg_id TEXT   NOT NULL,
        title         TEXT    NOT NULL,
        start_time    INTEGER NOT NULL,                         -- Unix timestamp
        stop_time     INTEGER NOT NULL,
        description   TEXT    DEFAULT '',
        category      TEXT    DEFAULT '',
        icon          TEXT    DEFAULT '',
        playlist_id   INTEGER REFERENCES playlists(id) ON DELETE CASCADE
    );

    -- ── Stream sessions ───────────────────────────────────────
    CREATE TABLE IF NOT EXISTS stream_sessions (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
        channel_id    INTEGER REFERENCES channels(id) ON DELETE CASCADE,
        token         TEXT    NOT NULL UNIQUE,
        ip            TEXT,
        user_agent    TEXT,
        started_at    INTEGER NOT NULL DEFAULT (strftime('%s','now')),
        last_ping     INTEGER NOT NULL DEFAULT (strftime('%s','now')),
        bytes_sent    INTEGER DEFAULT 0
    );

    -- ── Access log ────────────────────────────────────────────
    CREATE TABLE IF NOT EXISTS access_log (
        id            INTEGER PRIMARY KEY AUTOINCREMENT,
        user_id       INTEGER,
        channel_id    INTEGER,
        action        TEXT    NOT NULL,
        ip            TEXT,
        meta          TEXT,
        created_at    INTEGER NOT NULL DEFAULT (strftime('%s','now'))
    );

    -- ── Indexes ───────────────────────────────────────────────
    CREATE INDEX IF NOT EXISTS idx_channels_playlist   ON channels(playlist_id);
    CREATE INDEX IF NOT EXISTS idx_channels_group      ON channels(group_title);
    CREATE INDEX IF NOT EXISTS idx_channels_tvgid      ON channels(tvg_id);
    CREATE INDEX IF NOT EXISTS idx_epg_tvgid           ON epg_programs(channel_tvg_id);
    CREATE INDEX IF NOT EXISTS idx_epg_times           ON epg_programs(start_time, stop_time);
    CREATE INDEX IF NOT EXISTS idx_sessions_token      ON stream_sessions(token);
    CREATE INDEX IF NOT EXISTS idx_log_created         ON access_log(created_at);

    SQL;

    try {
        $pdo->exec($schema);
        $steps[] = ['ok', 'All database tables created successfully'];
    } catch (PDOException $e) {
        $steps[] = ['err', 'Schema migration failed: ' . $e->getMessage()];
        $success = false;
    }
}

// ── Step 4: Default settings ───────────────────────────────
if ($success) {
    $defaults = [
        ['site_name',       'XtreamTV IPTV OS'],
        ['site_version',    APP_VERSION_INSTALL],
        ['developer',       'Kobir Shah'],
        ['developer_credit', DEVELOPER_CREDIT],
        ['proxy_useragent', 'Mozilla/5.0 (SmartTV; Linux) XtreamTV/2.0'],
        ['max_cache_age',   '3600'],
        ['epg_cache_hours', '12'],
        ['allow_register',  '0'],
        ['installed_at',    (string)time()],
    ];

    $stmt = $pdo->prepare(
        "INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)"
    );
    foreach ($defaults as [$k, $v]) {
        $stmt->execute([$k, $v]);
    }
    $steps[] = ['ok', 'Default settings seeded (' . count($defaults) . ' entries)'];
}

// ── Step 5: Seed default admin ─────────────────────────────
if ($success) {
    $adminCount = (int)$pdo->query("SELECT COUNT(*) FROM users WHERE is_admin = 1")->fetchColumn();
    if ($adminCount === 0) {
        $hash  = password_hash('admin123', PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]);
        $token = bin2hex(random_bytes(32));
        $pdo->prepare(
            "INSERT INTO users (username, password, is_admin, api_token, max_streams)
             VALUES ('admin', ?, 1, ?, 99)"
        )->execute([$hash, $token]);
        $steps[] = ['ok', "Admin user seeded → username: <strong>admin</strong> / password: <strong>admin123</strong>"];
    } else {
        $steps[] = ['skip', 'Admin user already exists'];
    }
}

// ── Step 6: Write install lock file ───────────────────────
if ($success) {
    file_put_contents($storagePath . '/installed.lock', json_encode([
        'installed_at' => date('Y-m-d H:i:s'),
        'version'      => APP_VERSION_INSTALL,
        'credit'       => DEVELOPER_CREDIT,
    ]));
    $steps[] = ['ok', 'Install lock written to storage/installed.lock'];
}

// ── Render ─────────────────────────────────────────────────
if ($isCli):
    // ── CLI output ─────────────────────────────────────────
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
        echo "  🔐 Admin: admin / admin123\n";
    } else {
        echo "  ❌ Installation Failed — check storage/ permissions\n";
    }
    echo "  " . DEVELOPER_CREDIT . "\n";
    echo "\n";
else:
    // ── HTML output ─────────────────────────────────────────
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
  .btn { display: inline-block; margin-top: 16px; padding: 11px 24px; border-radius: 10px; font-size: 0.9rem; font-weight: 700; text-decoration: none; background: linear-gradient(135deg,#00b4ff,#7c3aed); color: #fff; box-shadow: 0 4px 20px rgba(0,180,255,0.25); }
  .warn { margin-top: 16px; padding: 12px 16px; border-radius: 10px; font-size: 0.8rem; background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.25); color: #fcd34d; }
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
    <div style="font-size:0.85rem;color:#94a3b8">Database ready. Admin account created. All systems initialized.</div>
    <a href="login.php" class="btn">⚡ Go to Login Panel</a>
    <div class="warn">⚠️ <strong>Security:</strong> Delete or rename <code>install.php</code> immediately after installation!</div>
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
