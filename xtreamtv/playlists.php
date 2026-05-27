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
require_once __DIR__ . '/src/M3UParser.php';
require_once __DIR__ . '/src/XtreamImporter.php';
require_once __DIR__ . '/src/PortalImporter.php';
require_once __DIR__ . '/src/View.php';

$flash  = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'add_url') {
        $name = trim($_POST['name'] ?? '');
        $url  = trim($_POST['source_url'] ?? '');

        if (empty($name) || empty($url)) {
            View::flash('error', 'Name and URL are required.');
        } else {
            Database::query(
                "INSERT INTO playlists (name, source_type, url, last_synced) VALUES (?, 'm3u_url', ?, strftime('%s','now'))",
                [$name, $url]
            );
            $playlistId = (int)Database::lastInsertId();

            try {
                set_time_limit(300);
                if (!defined('M3UEngine::class')) require_once __DIR__ . '/engine.php';
                M3UEngine::assertSafeUrl($url);
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

    if ($action === 'add_file') {
        $name = trim($_POST['name'] ?? '');
        $file = $_FILES['m3u_file'] ?? null;

        if (empty($name) || !$file || $file['error'] !== UPLOAD_ERR_OK) {
            View::flash('error', 'Name and valid M3U file are required.');
        } else {
            $ext = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['m3u', 'm3u8', 'txt'])) {
                View::flash('error', 'Only .m3u, .m3u8, and .txt files are allowed.');
            } elseif ($file['size'] > 104857600) {
                View::flash('error', 'File too large (max 100MB).');
            } else {
                $savePath = STORAGE_PATH . '/playlist_' . time() . '_' . bin2hex(random_bytes(4)) . '.m3u';
                if (move_uploaded_file($file['tmp_name'], $savePath)) {
                    Database::query(
                        "INSERT INTO playlists (name, source_type, source_file) VALUES (?, 'm3u_file', ?)",
                        [$name, $savePath]
                    );
                    $playlistId = (int)Database::lastInsertId();
                    $content = file_get_contents($savePath);
                    $channels = M3UParser::parse($content);
                    $count = count($channels);

                    $pdo = Database::getInstance();
                    $pdo->beginTransaction();
                    $stmt = $pdo->prepare(
                    "INSERT INTO channels (playlist_id, name, stream_url, tvg_logo, group_title, tvg_id, tvg_name, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
                    );
                    foreach ($channels as $i => $ch) {
                        $stmt->execute([$playlistId, $ch['name'], $ch['url'], $ch['tvg_logo'] ?? $ch['logo'] ?? '', $ch['group'], $ch['tvg_id'], $ch['tvg_name'], $i]);
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

    if ($action === 'delete') {
        $pid = (int)($_POST['playlist_id'] ?? 0);
        $pl  = Database::query("SELECT * FROM playlists WHERE id = ?", [$pid])->fetch();
        if ($pl) {
            if (!empty($pl['source_file']) && file_exists($pl['source_file'])) {
                unlink($pl['source_file']);
            }
            Database::query("DELETE FROM playlists WHERE id = ?", [$pid]);
            View::flash('success', 'Playlist deleted.');
        } else {
            View::flash('error', 'Playlist not found.');
        }
        header('Location: /xtreamtv/playlists.php');
        exit;
    }

    if ($action === 'sync') {
        $pid = (int)($_POST['playlist_id'] ?? 0);
        $pl  = Database::query("SELECT * FROM playlists WHERE id = ?", [$pid])->fetch();
        if ($pl && !empty($pl['url'])) {
            try {
                set_time_limit(300);
                if (!defined('M3UEngine::class')) require_once __DIR__ . '/engine.php';
                M3UEngine::assertSafeUrl($pl['url']);
                Database::query("DELETE FROM channels WHERE playlist_id = ?", [$pid]);
                $count = M3UParser::parseFromUrl($pl['url'], $pid);
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

    if ($action === 'import_xtream') {
        $name     = trim($_POST['name'] ?? '');
        $server   = rtrim(trim($_POST['server'] ?? ''), '/');
        $username = trim($_POST['username'] ?? '');
        $password = trim($_POST['password'] ?? '');

        if (empty($name) || empty($server) || empty($username) || empty($password)) {
            View::flash('error', 'All fields are required.');
        } else {
            try {
                set_time_limit(300);
                $importer = new XtreamImporter($server, $username, $password);
                $pid      = $importer->import($name);
                View::flash('success', "Xtream playlist '{$name}' imported successfully.");
            } catch (\Throwable $e) {
                View::flash('error', 'Xtream import failed: ' . $e->getMessage());
            }
        }
        header('Location: /xtreamtv/playlists.php');
        exit;
    }

    if ($action === 'import_portal') {
        $name   = trim($_POST['name'] ?? '');
        $server = rtrim(trim($_POST['server'] ?? ''), '/');
        $mac    = strtoupper(trim($_POST['mac'] ?? ''));
        $serial = trim($_POST['serial'] ?? '');

        if (empty($name) || empty($server) || empty($mac)) {
            View::flash('error', 'Name, Server URL, and MAC address are required.');
        } elseif (!preg_match('/^[0-9A-F:]{17}$/', $mac)) {
            View::flash('error', 'Invalid MAC address format (use XX:XX:XX:XX:XX:XX).');
        } else {
            try {
                set_time_limit(300);
                $importer = new PortalImporter($server, $mac, $serial);
                $pid      = $importer->import($name);
                View::flash('success', "Portal playlist '{$name}' imported successfully.");
            } catch (\Throwable $e) {
                View::flash('error', 'Portal import failed: ' . $e->getMessage());
            }
        }
        header('Location: /xtreamtv/playlists.php');
        exit;
    }
}

$playlists = Database::query("SELECT * FROM playlists ORDER BY added_at DESC")->fetchAll();

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

ob_start();
?>
<?= View::getFlash() ?>

<div class="flex items-center justify-between mb-6">
  <div>
    <h2 style="font-size:1.4rem;font-weight:800">📡 Playlists</h2>
    <div class="text-muted" style="font-size:0.82rem;margin-top:2px"><?= count($playlists) ?> playlist(s) total</div>
  </div>
  <div style="display:flex;gap:8px;flex-wrap:wrap;">
    <button onclick="openModal('modalUrl')" class="btn btn-primary">🔗 M3U URL</button>
    <button onclick="openModal('modalFile')" class="btn btn-purple">📂 M3U File</button>
    <button onclick="openModal('modalXtream')" class="btn btn-success">☁ Xtream</button>
    <button onclick="openModal('modalPortal')" class="btn btn-warning">📡 Portal</button>
  </div>
</div>

<div class="glass">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Playlist Name</th>
          <th>Channels</th>
          <th>Source</th>
          <th>Last Synced</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($playlists)): ?>
        <tr><td colspan="6" style="text-align:center;padding:48px;color:var(--text-muted)">
          <div style="font-size:2.5rem;margin-bottom:10px">📡</div>
          <div>No playlists yet. Add one to get started!</div>
        </td></tr>
      <?php else: ?>
        <?php foreach ($playlists as $i => $pl): ?>
        <tr>
          <td class="text-muted"><?= $i + 1 ?></td>
          <td>
            <div style="font-weight:600"><?= $e($pl['name']) ?></div>
              <?php if ($pl['url']): ?>
             <div class="mono text-muted" style="font-size:0.72rem;margin-top:2px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $e($pl['url']) ?></div>
             <?php elseif ($pl['source_config']): ?>
             <div class="mono text-muted" style="font-size:0.72rem;margin-top:2px;max-width:280px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap">Config: <?= $e(substr($pl['source_config'], 0, 60)) ?>…</div>
            <?php endif; ?>
          </td>
          <td>
            <span style="font-weight:700;color:var(--neon-blue)"><?= number_format((int)$pl['channel_count']) ?></span>
            <span class="text-muted" style="font-size:0.75rem"> ch</span>
          </td>
          <td>
            <?php
              $srcType = $pl['source_type'] ?? '';
              $srcTags = [
                'm3u_url'  => ['🔗 URL', 'tag-active'],
                'm3u_file' => ['📂 File', 'tag-user'],
                'xtream'   => ['☁ Xtream', 'tag-xtream'],
                'portal'   => ['📡 Portal', 'tag-xtream'],
              ];
              if (isset($srcTags[$srcType])):
                [$label, $cls] = $srcTags[$srcType];
              elseif ($pl['url']):
                [$label, $cls] = ['🔗 URL', 'tag-active'];
              elseif ($pl['source_file']):
                [$label, $cls] = ['📂 File', 'tag-user'];
              else:
                [$label, $cls] = ['—', ''];
              endif;
            ?>
            <span class="tag <?= $cls ?>"><?= $label ?></span>
          </td>
          <td class="text-muted" style="font-size:0.8rem">
            <?= $pl['last_synced'] ? date('Y-m-d H:i', (int)$pl['last_synced']) : 'Never' ?>
          </td>
          <td>
            <div class="flex gap-2">
              <a href="/xtreamtv/channels.php?playlist_id=<?= $pl['id'] ?>" class="btn btn-ghost btn-sm">📺 Channels</a>
              <a href="/xtreamtv/proxy.php?action=m3u&playlist_id=<?= $pl['id'] ?>" class="btn btn-success btn-sm">⬇ M3U</a>
              <?php if ($pl['url'] && ($pl['source_type'] ?? '') === 'm3u_url'): ?>
               <form method="POST" style="display:inline">
                 <input type="hidden" name="action" value="sync">
                 <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
                 <button type="submit" class="btn btn-ghost btn-sm" onclick="return confirm('Re-sync this playlist? Existing channels will be replaced.')">🔄 Sync</button>
               </form>
               <?php endif; ?>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete this playlist and all channels?')">🗑</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>
</div>

<div id="modalUrl" class="modal-overlay <?= ($_GET['action'] ?? '') === 'add' ? 'open' : '' ?>">
  <div class="modal">
    <div class="modal-head">
      <h3>🔗 Add Playlist from URL</h3>
      <button class="modal-close" onclick="closeModal('modalUrl')">✕</button>
    </div>
    <form method="POST" action="/xtreamtv/playlists.php">
      <div class="modal-body">
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

<div id="modalFile" class="modal-overlay">
  <div class="modal">
    <div class="modal-head">
      <h3>📂 Upload M3U File</h3>
      <button class="modal-close" onclick="closeModal('modalFile')">✕</button>
    </div>
    <form method="POST" action="/xtreamtv/playlists.php" enctype="multipart/form-data">
      <div class="modal-body">
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
<div id="modalXtream" class="modal-overlay">
  <div class="modal">
    <div class="modal-head">
      <h3>☁ Import from Xtream Codes</h3>
      <button class="modal-close" onclick="closeModal('modalXtream')">✕</button>
    </div>
    <form method="POST" action="/xtreamtv/playlists.php">
      <div class="modal-body">
        <input type="hidden" name="action" value="import_xtream">
        <div class="form-group">
          <label>Playlist Name</label>
          <input type="text" name="name" placeholder="My Xtream Provider" required>
        </div>
        <div class="form-group">
          <label>Server URL</label>
          <input type="url" name="server" placeholder="http://provider.com:8080" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" placeholder="Xtream username" required>
          </div>
          <div class="form-group">
            <label>Password</label>
            <input type="text" name="password" placeholder="Xtream password" required>
          </div>
        </div>
        <div class="alert alert-info">Connects via player_api.php to fetch live streams, categories, and EPG data.</div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="closeModal('modalXtream')" class="btn btn-ghost">Cancel</button>
        <button type="submit" class="btn btn-success">☁ Import Xtream</button>
      </div>
    </form>
  </div>
</div>

<div id="modalPortal" class="modal-overlay">
  <div class="modal">
    <div class="modal-head">
      <h3>📡 Import from Stalker/MAC Portal</h3>
      <button class="modal-close" onclick="closeModal('modalPortal')">✕</button>
    </div>
    <form method="POST" action="/xtreamtv/playlists.php">
      <div class="modal-body">
        <input type="hidden" name="action" value="import_portal">
        <div class="form-group">
          <label>Playlist Name</label>
          <input type="text" name="name" placeholder="My Portal" required>
        </div>
        <div class="form-group">
          <label>Portal Server URL</label>
          <input type="url" name="server" placeholder="http://portal.com" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>MAC Address</label>
            <input type="text" name="mac" placeholder="00:1A:2B:3C:4D:5E" required pattern="[0-9A-Fa-f:]{17}" title="MAC address format: XX:XX:XX:XX:XX:XX">
          </div>
          <div class="form-group">
            <label>Serial (optional)</label>
            <input type="text" name="serial" placeholder="Auto-generated if empty">
          </div>
        </div>
        <div class="alert alert-info">Performs STB handshake (token exchange + MAC auth), then fetches all ITV channels.</div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="closeModal('modalPortal')" class="btn btn-ghost">Cancel</button>
        <button type="submit" class="btn btn-warning">📡 Import Portal</button>
      </div>
    </form>
  </div>
</div>
<?php
$body = ob_get_clean();
echo View::layout('Playlists', $body, 'playlists');
