<?php
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

            CREATE INDEX IF NOT EXISTS idx_channels_playlist  ON channels(playlist_id);
            CREATE INDEX IF NOT EXISTS idx_channels_group     ON channels(group_title);
            CREATE INDEX IF NOT EXISTS idx_channels_tvgid     ON channels(tvg_id);
            CREATE INDEX IF NOT EXISTS idx_epg_tvgid          ON epg_programs(channel_tvg_id);
            CREATE INDEX IF NOT EXISTS idx_epg_times          ON epg_programs(start_time, stop_time);
        ");

        try {
            $chCols = array_column(
                $pdo->query("PRAGMA table_info(channels)")->fetchAll(),
                'name'
            );
            if (!in_array('ffmpeg_mode', $chCols, true)) {
                $pdo->exec("ALTER TABLE channels ADD COLUMN ffmpeg_mode TEXT DEFAULT 'inherit'");
            }
            $plCols = array_column(
                $pdo->query("PRAGMA table_info(playlists)")->fetchAll(),
                'name'
            );
            foreach ([
                'source_type'   => "TEXT NOT NULL DEFAULT 'm3u_url'",
                'source_config' => 'TEXT',
                'url'           => 'TEXT',
                'epg_url'       => 'TEXT',
            ] as $col => $def) {
                if (!in_array($col, $plCols, true)) {
                    $pdo->exec("ALTER TABLE playlists ADD COLUMN {$col} {$def}");
                }
            }
        } catch (\Throwable $e) {}

        $defaults = [
            ['site_name',        'XtreamTV IPTV OS'],
            ['site_version',     APP_VERSION],
            ['developer',        'Kobir Shah'],
            ['developer_credit', DEVELOPER_CREDIT],
            ['proxy_useragent',  'Mozilla/5.0 (SmartTV; Tizen 6.0) AppleWebKit/537.36 XtreamTV/2.0'],
            ['max_cache_age',    '3600'],
            ['epg_cache_hours',  '12'],
            ['ffmpeg_mode',      'off'],
            ['ffmpeg_quality',   'passthru'],
            ['installed_at',     (string)time()],
        ];

        $stmt = $pdo->prepare(
            "INSERT OR IGNORE INTO settings (key, value) VALUES (?, ?)"
        );
        foreach ($defaults as [$k, $v]) {
            $stmt->execute([$k, $v]);
        }
    }

    public static function query(string $sql, array $params = []): \PDOStatement
    {
        $stmt = self::getInstance()->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }

    public static function setting(string $key, string $default = ''): string
    {
        $val = self::query("SELECT value FROM settings WHERE key = ?", [$key])->fetchColumn();
        return ($val !== false) ? (string)$val : $default;
    }

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
