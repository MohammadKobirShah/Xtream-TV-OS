<?php
/**
 * ============================================================
 *  XtreamTV — Playlist Manager
 *  Developer: Kobir Shah
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/M3UParser.php';
require_once __DIR__ . '/src/View.php';

Security::requireLogin();

$userId  = (int)$_SESSION['user_id'];
$isAdmin = Security::isAdmin();
$flash   = '';

// ── Handle Actions ─────────────────────────────────────────

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        View::flash('error', 'Invalid CSRF token.');
        header('Location: /xtreamtv/playlists.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    // Add playlist from URL
    if ($action === 'add_url') {
        $name = trim($_POST['name'] ?? '');
        $url  = trim($_POST['source_url'] ?? '');

        if (empty($name) || empty($url)) {
            View::flash('error', 'Name and URL are required.');
        } elseif (!Security::validateStreamUrl($url)) {
            View::flash('error', 'URL blocked: SSRF protection rejected this address.');
        } else {
            Database::query(
                "INSERT INTO playlists (user_id, name, source_url, last_synced) VALUES (?, ?, ?, strftime('%s','now'))",
                [$userId, $name, $url]
            );
            $playlistId = (int)Database::lastInsertId();

            try {
                set_time_limit(300);
                $count = M3UParser::parseFromUrl($url, $playlistId);
                M3UParser::flushInserts();
                Database::query(
                    "UPDATE playlists SET channel_count = ?, last_synced = strftime('%s','now') WHERE id = ?",
                    [$count, $playlistId]
                );
                View::flash('success', "Playlist '{$name}' imported with {$count} channels.");
            } catch (\Throwable $e) {
                View::flash('error', 'Import failed: ' . $e->getMessage());
                Database::query("DELETE FROM playlists WHERE id = ?", [$playlistId]);
            }
        }
        header('Location: /xtreamtv/playlists.php');
        exit;
    }

    // Add playlist from file upload
    if ($action === 'add_file') {
        $name = trim($_POST['name'] ?? '');
        $file = $_FILES['m3u_file'] ?? null;

        if (empty($name) || !$file || $file['error'] !== UPLOAD_ERR_OK) {
            View::flash('error', 'Name and valid M3U file are required.');
        } else {
            // Validate file type
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['m3u', 'm3u8', 'txt'])) {
                View::flash('error', 'Only .m3u, .m3u8, and .txt files are allowed.');
            } elseif ($file['size'] > 104857600) { // 100MB
                View::flash('error', 'File too large (max 100MB).');
            } else {
                $savePath = STORAGE_PATH . '/playlist_' . time() . '_' . bin2hex(random_bytes(4)) . '.m3u';
                if (move_uploaded_file($file['tmp_name'], $savePath)) {
                    Database::query(
                        "INSERT INTO playlists (user_id, name, source_file) VALUES (?, ?, ?)",
                        [$userId, $name, $savePath]
                    );
                    $playlistId = (int)Database::lastInsertId();
                    $content = file_get_contents($savePath);
                    $channels = M3UParser::parse($content);
                    $count = count($channels);

                    $pdo = Database::getInstance();
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare(
                        "INSERT INTO channels (playlist_id, name, stream_url, logo, group_title, tvg_id, tvg_name, sort_order)
                         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    foreach ($channels as $i => $ch) {
                        $stmt->execute([$playlistId, $ch['name'], $ch['url'], $ch['logo'], $ch['group'], $ch['tvg_id'], $ch['tvg_name'], $i]);
                    }
                    $pdo->commit();

                    Database::query(
                        "UPDATE playlists SET channel_count = ?, last_synced = strftime('%s','now') WHERE id = ?",
                        [$count, $playlistId]
                    );
                    View::flash('success', "Playlist '{$name}' uploaded with {$count} channels.");
                } else {
                    View::flash('error', 'File upload failed.');
                }
            }
        }
        header('Location: /xtreamtv/playlists.php');
        exit;
    }

    // Delete playlist
    if ($action === 'delete') {
        $pid = (int)($_POST['playlist_id'] ?? 0);
        $pl  = Database::query("SELECT * FROM playlists WHERE id = ?", [$pid])->fetch();
        if ($pl && ($isAdmin || (int)$pl['user_id'] === $userId)) {
            // Remove stored file if any
            if (!empty($pl['source_file']) && file_exists($pl['source_file'])) {
                unlink($pl['source_file']);
            }
            Database::query("DELETE FROM playlists WHERE id = ?", [$pid]);
            View::flash('success', 'Playlist deleted.');
        } else {
            View::flash('error', 'Playlist not found or access denied.');
        }
        header('Location: /xtreamtv/playlists.php');
        exit;
    }

    // Re-sync playlist from URL
    if ($action === 'sync') {
        $pid = (int)($_POST['playlist_id'] ?? 0);
        $pl  = Database::query("SELECT * FROM playlists WHERE id = ?", [$pid])->fetch();
        if ($pl && !empty($pl['source_url']) && ($isAdmin || (int)$pl['user_id'] === $userId)) {
            try {
                set_time_limit(300);
                Database::query("DELETE FROM channels WHERE playlist_id = ?", [$pid]);
                $count = M3UParser::parseFromUrl($pl['source_url'], $pid);
                M3UParser::flushInserts();
                Database::query(
                    "UPDATE playlists SET channel_count = ?, last_synced = strftime('%s','now') WHERE id = ?",
                    [$count, $pid]
                );
                View::flash('success', "Synced: {$count} channels imported.");
            } catch (\Throwable $e) {
                View::flash('error', 'Sync failed: ' . $e->getMessage());
            }
        }
        header('Location: /xtreamtv/playlists.php');
        exit;
    }
}

// ── Fetch Playlists ─────────────────────────────────────────
$playlists = $isAdmin
    ? Database::query("SELECT p.*, u.username FROM playlists p JOIN users u ON u.id = p.user_id ORDER BY p.added_at DESC")->fetchAll()
    : Database::query("SELECT p.*, u.username FROM playlists p JOIN users u ON u.id = p.user_id WHERE p.user_id = ? ORDER BY p.added_at DESC", [$userId])->fetchAll();

$csrf = Security::csrfToken();

ob_start();
?>
<?= View::getFlash() ?>

<div class="flex items-center justify-between mb-6">
  <div>
    <h2 style="font-size:1.4rem;font-weight:800">📡 Playlists</h2>
    <div class="text-muted" style="font-size:0.82rem;margin-top:2px"><?= count($playlists) ?> playlist(s) total</div>
  </div>
  <div class="flex gap-2">
    <button onclick="openModal('modalUrl')" class="btn btn-primary">🔗 Add from URL</button>
    <button onclick="openModal('modalFile')" class="btn btn-purple">📂 Upload M3U</button>
  </div>
</div>

<!-- Playlists Table -->
<div class="glass">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Playlist Name</th>
          <?php if ($isAdmin): ?><th>Owner</th><?php endif; ?>
          <th>Channels</th>
          <th>Source</th>
          <th>Last Synced</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($playlists)): ?>
        <tr><td colspan="7" style="text-align:center;padding:48px;color:var(--text-muted)">
          <div style="font-size:2.5rem;margin-bottom:10px">📡</div>
          <div>No playlists yet. Add one to get started!</div>
        </td></tr>
      <?php else: ?>
        <?php foreach ($playlists as $i => $pl): ?>
        <tr>
          <td class="text-muted"><?= $i + 1 ?></td>
          <td>
            <div style="font-weight:600"><?= Security::e($pl['name']) ?></div>
            <?php if ($pl['source_url']): ?>
            <div class="mono text-muted" style="font-size:0.72rem;margin-top:2px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= Security::e($pl['source_url']) ?></div>
            <?php endif; ?>
          </td>
          <?php if ($isAdmin): ?><td><span class="neon-blue"><?= Security::e($pl['username']) ?></span></td><?php endif; ?>
          <td>
            <span style="font-weight:700;color:var(--neon-blue)"><?= number_format((int)$pl['channel_count']) ?></span>
            <span class="text-muted" style="font-size:0.75rem"> ch</span>
          </td>
          <td>
            <?php if ($pl['source_url']): ?>
              <span class="tag tag-active">🔗 URL</span>
            <?php elseif ($pl['source_file']): ?>
              <span class="tag tag-user">📂 File</span>
            <?php else: ?>
              <span class="tag">—</span>
            <?php endif; ?>
          </td>
          <td class="text-muted" style="font-size:0.8rem">
            <?= $pl['last_synced'] ? date('Y-m-d H:i', (int)$pl['last_synced']) : 'Never' ?>
          </td>
          <td>
            <div class="flex gap-2">
              <a href="/xtreamtv/channels.php?playlist_id=<?= $pl['id'] ?>" class="btn btn-ghost btn-sm">📺 Channels</a>
              <a href="/xtreamtv/proxy.php?action=m3u&playlist_id=<?= $pl['id'] ?>&t=<?= urlencode($_SESSION['token'] ?? '') ?>" class="btn btn-success btn-sm">⬇ M3U</a>
              <?php if ($pl['source_url']): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="sync">
                <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm" onclick="return confirm('Re-sync this playlist? Existing channels will be replaced.')">🔄 Sync</button>
              </form>
              <?php endif; ?>
              <?php if ($isAdmin || (int)$pl['user_id'] === $userId): ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this playlist and all channels?')">🗑</button>
              </form>
              <?php endif; ?>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ Modal: Add from URL ═══ -->
<div id="modalUrl" class="modal-overlay <?= ($_GET['action'] ?? '') === 'add' ? 'open' : '' ?>">
  <div class="modal">
    <div class="modal-head">
      <h3>🔗 Add Playlist from URL</h3>
      <button class="modal-close" onclick="closeModal('modalUrl')">✕</button>
    </div>
    <form method="POST" action="/xtreamtv/playlists.php">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="add_url">
        <div class="form-group">
          <label>Playlist Name</label>
          <input type="text" name="name" placeholder="My IPTV Playlist" required>
        </div>
        <div class="form-group">
          <label>M3U / M3U8 URL</label>
          <input type="url" name="source_url" placeholder="http://provider.com/playlist.m3u" required>
        </div>
        <div class="alert alert-info">⚡ Large playlists are parsed in streaming mode — no memory spikes.</div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="closeModal('modalUrl')" class="btn btn-ghost">Cancel</button>
        <button type="submit" class="btn btn-primary">⬇ Import Playlist</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ Modal: Upload File ═══ -->
<div id="modalFile" class="modal-overlay">
  <div class="modal">
    <div class="modal-head">
      <h3>📂 Upload M3U File</h3>
      <button class="modal-close" onclick="closeModal('modalFile')">✕</button>
    </div>
    <form method="POST" action="/xtreamtv/playlists.php" enctype="multipart/form-data">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="add_file">
        <div class="form-group">
          <label>Playlist Name</label>
          <input type="text" name="name" placeholder="Uploaded Playlist" required>
        </div>
        <div class="form-group">
          <label>M3U File (.m3u / .m3u8)</label>
          <input type="file" name="m3u_file" accept=".m3u,.m3u8,.txt" required>
        </div>
        <div class="alert alert-info">Max file size: 100MB. Channels are batch-inserted for performance.</div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="closeModal('modalFile')" class="btn btn-ghost">Cancel</button>
        <button type="submit" class="btn btn-purple">📂 Upload & Parse</button>
      </div>
    </form>
  </div>
</div>
<?php
$body = ob_get_clean();
echo View::layout('Playlists', $body, 'playlists');
