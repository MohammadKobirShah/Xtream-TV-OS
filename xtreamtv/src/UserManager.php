<?php
/**
 * ============================================================
 *  XtreamTV — User Manager
 *  CRUD + token management
 *  Developer: Kobir Shah
 * ============================================================
 */

declare(strict_types=1);

class UserManager
{
    public static function getAll(): array
    {
        return Database::query(
            "SELECT id, username, role, max_streams, expires_at, is_active, created_at, last_login,
                    api_token,
                    (SELECT COUNT(*) FROM stream_sessions ss WHERE ss.user_id = users.id AND ss.last_ping > ?) AS active_streams
             FROM users ORDER BY created_at DESC",
            [time() - 30]
        )->fetchAll();
    }

    public static function getById(int $id): ?array
    {
        return Database::query("SELECT * FROM users WHERE id = ?", [$id])->fetch() ?: null;
    }

    public static function create(string $username, string $password, string $role = 'user', int $maxStreams = 1, ?int $expiresAt = null): int|false
    {
        if (empty($username) || strlen($username) < 3) return false;
        if (empty($password) || strlen($password) < 6)  return false;

        $hash  = password_hash($password, PASSWORD_ARGON2ID);
        $token = bin2hex(random_bytes(32));

        try {
            Database::query(
                "INSERT INTO users (username, password, role, api_token, max_streams, expires_at)
                 VALUES (?, ?, ?, ?, ?, ?)",
                [$username, $hash, $role, $token, $maxStreams, $expiresAt]
            );
            return (int)Database::lastInsertId();
        } catch (PDOException $e) {
            error_log('[XtreamTV][UserManager] ' . $e->getMessage());
            return false;
        }
    }

    public static function update(int $id, array $data): bool
    {
        $allowed = ['username', 'role', 'max_streams', 'expires_at', 'is_active'];
        $sets    = [];
        $vals    = [];

        foreach ($allowed as $field) {
            if (array_key_exists($field, $data)) {
                $sets[] = "{$field} = ?";
                $vals[] = $data[$field];
            }
        }

        if (!empty($data['password']) && strlen($data['password']) >= 6) {
            $sets[] = "password = ?";
            $vals[] = password_hash($data['password'], PASSWORD_ARGON2ID);
        }

        if (empty($sets)) return false;

        $vals[] = $id;
        Database::query("UPDATE users SET " . implode(', ', $sets) . " WHERE id = ?", $vals);
        return true;
    }

    public static function delete(int $id): void
    {
        Database::query("DELETE FROM users WHERE id = ?", [$id]);
    }

    public static function regenerateToken(int $id): string
    {
        $token = bin2hex(random_bytes(32));
        Database::query("UPDATE users SET api_token = ? WHERE id = ?", [$token, $id]);
        return $token;
    }

    public static function getStats(): array
    {
        $db = Database::getInstance();
        return [
            'total_users'    => (int)$db->query("SELECT COUNT(*) FROM users")->fetchColumn(),
            'active_users'   => (int)$db->query("SELECT COUNT(*) FROM users WHERE is_active=1")->fetchColumn(),
            'total_channels' => (int)$db->query("SELECT COUNT(*) FROM channels WHERE is_active=1")->fetchColumn(),
            'total_playlists'=> (int)$db->query("SELECT COUNT(*) FROM playlists")->fetchColumn(),
            'active_streams' => (int)$db->query("SELECT COUNT(*) FROM stream_sessions WHERE last_ping > " . (time()-30))->fetchColumn(),
            'total_logs'     => (int)$db->query("SELECT COUNT(*) FROM access_log")->fetchColumn(),
        ];
    }
}
