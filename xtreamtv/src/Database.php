<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — Database Layer (SQLite / PDO)
 * ============================================================
 *  Developer        : Kobir Shah
 *  DEVELOPER_CREDIT : Powered by Kobir Shah
 *
 *  BugFix: Added ffmpeg_mode to settings seed.
 *  BugFix: Added proxy_mode setting for admin panel control.
 *  BugFix: migrate() now uses IF NOT EXISTS on every table.
 *  BugFix: sessions table had wrong column reference (tvg_name).
 * ============================================================
 */

declare(strict_types=1);

class Database
{
    private static ?PDO $instance = null;

    public static function getInstance(): PDO
    {
        if (self::$instance === null) {
            try {
                $pdo = new PDO('sqlite:' . DB_PATH, options: [
                    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES   => false,
                ]);
                $pdo->exec("PRAGMA journal_mode=WAL");
                $pdo->exec("PRAGMA synchronous=NORMAL");
                $pdo->exec("PRAGMA cache_size=-64000");
                $pdo->exec("PRAGMA foreign_keys=ON");
                $pdo->exec("PRAGMA temp_store=MEMORY");
                self::$instance = $pdo;
                self::migrate($pdo);
            } catch (PDOException $e) {
                error_log('[XtreamTV][DB] ' . $e->getMessage() . ' — Kobir Shah');
                http_response_code(500);
                die(json_encode([
                    'error'  => 'Database unavailable',
                    'credit' => 'Kobir Shah',
                ]));
            }
        }
        return self::$instance;
    }

    private static function migrate(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS users (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                username      TEXT    NOT NULL UNIQUE COLLATE NOCASE,
                password      TEXT    NOT NULL,
                is_admin      INTEGER NOT NULL DEFAULT 0,
                api_token     TEXT    UNIQUE,
                max_streams   INTEGER NOT NULL DEFAULT 1,
                expires_at    INTEGER,
                is_active     INTEGER NOT NULL DEFAULT 1,
                notes         TEXT,
                created_at    INTEGER NOT NULL DEFAULT (strftime('%s','now')),
                last_login    INTEGER,
                last_ip       TEXT
            );

            CREATE TABLE IF NOT EXISTS playlists (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       INTEGER REFERENCES users(id) ON DELETE CASCADE,
                name          TEXT    NOT NULL,
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
                key           TEXT    PRIMARY KEY,
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

            CREATE TABLE IF NOT EXISTS stream_sessions (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       INTEGER REFERENCES users(id) ON DELETE SET NULL,
                channel_id    INTEGER REFERENCES channels(id) ON DELETE SET NULL,
                token         TEXT    NOT NULL UNIQUE,
                ip            TEXT,
                user_agent    TEXT,
                started_at    INTEGER NOT NULL DEFAULT (strftime('%s','now')),
                last_ping     INTEGER NOT NULL DEFAULT (strftime('%s','now')),
                bytes_sent    INTEGER DEFAULT 0
            );

            CREATE TABLE IF NOT EXISTS access_log (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                user_id       INTEGER,
                channel_id    INTEGER,
                action        TEXT    NOT NULL,
                ip            TEXT,
                meta          TEXT,
                created_at    INTEGER NOT NULL DEFAULT (strftime('%s','now'))
            );

            CREATE INDEX IF NOT EXISTS idx_channels_playlist  ON channels(playlist_id);
            CREATE INDEX IF NOT EXISTS idx_channels_group     ON channels(group_title);
            CREATE INDEX IF NOT EXISTS idx_channels_tvgid     ON channels(tvg_id);
            CREATE INDEX IF NOT EXISTS idx_epg_tvgid          ON epg_programs(channel_tvg_id);
            CREATE INDEX IF NOT EXISTS idx_epg_times          ON epg_programs(start_time, stop_time);
            CREATE INDEX IF NOT EXISTS idx_sessions_token     ON stream_sessions(token);
            CREATE INDEX IF NOT EXISTS idx_sessions_user      ON stream_sessions(user_id);
            CREATE INDEX IF NOT EXISTS idx_log_created        ON access_log(created_at);
        ");

        // ── Migration: add ffmpeg_mode column if upgrading from v1 ──
        try {
            $cols = array_column(
                $pdo->query("PRAGMA table_info(channels)")->fetchAll(),
                'name'
            );
            if (!in_array('ffmpeg_mode', $cols, true)) {
                $pdo->exec("ALTER TABLE channels ADD COLUMN ffmpeg_mode TEXT DEFAULT 'inherit'");
            }
        } catch (\Throwable) {}

        // ── Seed default settings ─────────────────────────────────
        $defaults = [
            ['site_name',        'XtreamTV IPTV OS'],
            ['site_version',     APP_VERSION],
            ['developer',        'Kobir Shah'],
            ['developer_credit', DEVELOPER_CREDIT],
            ['proxy_useragent',  'Mozilla/5.0 (SmartTV; Tizen 6.0) AppleWebKit/537.36 XtreamTV/2.0'],
            ['max_cache_age',    '3600'],
            ['epg_cache_hours',  '12'],
            ['allow_register',   '0'],
            // FFmpeg Pro-Tip #2 — default OFF, admin toggles from panel
            ['ffmpeg_mode',      'off'],    // 'off' | 'on' | 'auto'
            ['ffmpeg_quality',   'passthru'], // 'passthru' | 'hd' | 'sd' | '4k'
            ['installed_at',     (string)time()],
        ];

        $stmt = $pdo->prepare(
            "INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)"
        );
        foreach ($defaults as [$k, $v]) {
            $stmt->execute([$k, $v]);
        }

        // ── Seed default admin if empty ───────────────────────────
        $count = (int)$pdo->query("SELECT COUNT(*) FROM users")->fetchColumn();
        if ($count === 0) {
            $hash  = password_hash('admin123', PASSWORD_ARGON2ID, ['memory_cost' => 65536, 'time_cost' => 4]);
            $token = bin2hex(random_bytes(32));
            $pdo->prepare(
                "INSERT INTO users (username, password, is_admin, api_token, max_streams) VALUES (?, ?, 1, ?, 99)"
            )->execute(['admin', $hash, $token]);
            error_log('[XtreamTV] Admin seeded — user:admin pass:admin123 — ' . DEVELOPER_CREDIT);
        }
    }

    /** Prepared query helper */
    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    /** Get a single setting value */
    public static function setting(string $key, string $default = ''): string
    {
        $val = self::query("SELECT value FROM settings WHERE key = ?", [$key])->fetchColumn();
        return ($val !== false) ? (string)$val : $default;
    }

    /** Update a setting value */
    public static function setSetting(string $key, string $value): void
    {
        self::query(
            "INSERT INTO settings (key, value, updated_at) VALUES (?, ?, strftime('%s','now'))
             ON CONFLICT(key) DO UPDATE SET value = excluded.value, updated_at = excluded.updated_at",
            [$key, $value]
        );
    }

    public static function lastInsertId(): string
    {
        return self::getInstance()->lastInsertId();
    }
}
