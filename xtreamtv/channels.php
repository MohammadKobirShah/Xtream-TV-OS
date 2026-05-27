<?php
declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/View.php';

$playlistId = (int)($_GET['playlist_id'] ?? 0);
$search     = trim($_GET['q'] ?? '');
$group      = trim($_GET['group'] ?? '');
$page       = max(1, (int)($_GET['page'] ?? 1));
$perPage    = 50;
$offset     = ($page - 1) * $perPage;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = $_POST['action'] ?? '';

    if ($action === 'toggle') {
        $cid = (int)($_POST['channel_id'] ?? 0);
        Database::query("UPDATE channels SET is_active = 1 - is_active WHERE id = ?", [$cid]);
        View::flash('success', 'Channel status toggled.');
    }

    if ($action === 'delete') {
        $cid = (int)($_POST['channel_id'] ?? 0);
        Database::query("DELETE FROM channels WHERE id = ?", [$cid]);
        View::flash('success', 'Channel deleted.');
    }

    if ($action === 'add') {
        $name = trim($_POST['name'] ?? '');
        $url  = trim($_POST['stream_url'] ?? '');
        $logo = trim($_POST['logo'] ?? '');
        $grp  = trim($_POST['group_title'] ?? 'Uncategorized');
        $pid  = (int)($_POST['playlist_id'] ?? $playlistId);

        if (empty($name) || empty($url)) {
            View::flash('error', 'Name and Stream URL are required.');
        } else {
            Database::query(
                "INSERT INTO channels (playlist_id, name, stream_url, tvg_logo, group_title) VALUES (?, ?, ?, ?, ?)",
                [$pid, $name, $url, $logo, $grp]
            );
            Database::query("UPDATE playlists SET channel_count = channel_count + 1 WHERE id = ?", [$pid]);
            View::flash('success', "Channel '{$name}' added.");
        }
    }

    header("Location: /xtreamtv/channels.php?playlist_id={$playlistId}&q=" . urlencode($search));
    exit;
}

$where  = [];
$params = [];

if ($playlistId) {
    $pl = Database::query("SELECT * FROM playlists WHERE id = ?", [$playlistId])->fetch();
    if (!$pl) {
        View::flash('error', 'Playlist not found.');
        header('Location: /xtreamtv/playlists.php');
        exit;
    }
    $where[]  = 'c.playlist_id = ?';
    $params[] = $playlistId;
}

if ($search) {
    $where[]  = '(c.name LIKE ? OR c.group_title LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}
if ($group) {
    $where[]  = 'c.group_title = ?';
    $params[] = $group;
}

$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';
$total    = (int)Database::query(
    "SELECT COUNT(*) FROM channels c {$whereStr}",
    $params
)->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$channels = Database::query(
    "SELECT c.*, p.name AS playlist_name FROM channels c
     JOIN playlists p ON p.id = c.playlist_id
     {$whereStr}
     ORDER BY c.sort_order, c.id
     LIMIT {$perPage} OFFSET {$offset}",
    $params
)->fetchAll();

$groups = $playlistId
    ? Database::query("SELECT DISTINCT group_title FROM channels WHERE playlist_id = ? ORDER BY group_title", [$playlistId])->fetchAll(PDO::FETCH_COLUMN)
    : [];

$allPlaylists = Database::query("SELECT id, name FROM playlists ORDER BY name")->fetchAll();

ob_start();
?>
<?= View::getFlash() ?>

<div class="flex items-center justify-between mb-6">
  <div>
    <h2 style="font-size:1.4rem;font-weight:800">📺 Channels</h2>
    <div class="text-muted" style="font-size:0.82rem;margin-top:2px">
      <?= number_format($total) ?> channel(s)
      <?= $playlistId ? ' in <strong>' . htmlspecialchars($pl['name'] ?? '') . '</strong>' : '' ?>
    </div>
  </div>
  <button onclick="openModal('modalAddChannel')" class="btn btn-primary">➕ Add Channel</button>
</div>

<div class="glass mb-4" style="padding:16px 20px;">
  <form method="GET" action="/xtreamtv/channels.php" style="display:flex;gap:12px;flex-wrap:wrap;align-items:flex-end;">
    <input type="hidden" name="playlist_id" value="<?= $playlistId ?>">
    <div style="flex:1;min-width:180px;">
      <label style="font-size:0.72rem;color:var(--text-muted);display:block;margin-bottom:4px;">Search</label>
      <input type="text" name="q" placeholder="Search channels..." value="<?= htmlspecialchars($search) ?>">
    </div>
    <?php if (!empty($groups)): ?>
    <div style="min-width:180px;">
      <label style="font-size:0.72rem;color:var(--text-muted);display:block;margin-bottom:4px;">Group</label>
      <select name="group">
        <option value="">All Groups</option>
        <?php foreach ($groups as $g): ?>
        <option value="<?= htmlspecialchars($g) ?>" <?= $group === $g ? 'selected' : '' ?>><?= htmlspecialchars($g) ?></option>
        <?php endforeach; ?>
      </select>
    </div>
    <?php endif; ?>
    <button type="submit" class="btn btn-ghost">🔍 Filter</button>
    <?php if ($search || $group): ?>
    <a href="/xtreamtv/channels.php?playlist_id=<?= $playlistId ?>" class="btn btn-danger">✕ Clear</a>
    <?php endif; ?>
  </form>
</div>

<div class="glass">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Channel</th>
          <th>Group</th>
          <th>Playlist</th>
          <th>Status</th>
          <th>Stream URL</th>
          <th>Proxy URL</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($channels)): ?>
        <tr><td colspan="8" style="text-align:center;padding:48px;color:var(--text-muted)">
          <div style="font-size:2.5rem;margin-bottom:10px">📺</div>
          <div>No channels found<?= $search ? " matching '{$search}'" : '' ?>.</div>
        </td></tr>
      <?php else: ?>
        <?php foreach ($channels as $i => $ch): ?>
        <?php
          $proxyUrl = APP_URL . '/xtreamtv/proxy.php?id=' . $ch['id'];
        ?>
        <tr>
          <td class="text-muted"><?= $offset + $i + 1 ?></td>
          <td>
            <div style="display:flex;align-items:center;gap:10px;">
              <?php if ($ch['tvg_logo']): ?>
              <img src="<?= htmlspecialchars($ch['tvg_logo']) ?>" alt="" style="width:32px;height:22px;object-fit:contain;border-radius:4px;background:#111;" onerror="this.style.display='none'">
              <?php else: ?>
              <div style="width:32px;height:22px;background:rgba(99,102,241,0.1);border-radius:4px;display:flex;align-items:center;justify-content:center;font-size:0.6rem;color:var(--text-muted)">TV</div>
              <?php endif; ?>
              <div style="font-weight:600;font-size:0.88rem"><?= htmlspecialchars($ch['name']) ?></div>
            </div>
          </td>
          <td><span style="font-size:0.78rem;color:var(--neon-purple)"><?= htmlspecialchars($ch['group_title']) ?></span></td>
          <td style="font-size:0.78rem;color:var(--text-muted)"><?= htmlspecialchars($ch['playlist_name']) ?></td>
          <td>
            <?php if ($ch['is_active']): ?>
              <span class="tag tag-active">Active</span>
            <?php else: ?>
              <span class="tag tag-inactive">Off</span>
            <?php endif; ?>
          </td>
          <td>
            <span class="mono text-muted" style="font-size:0.7rem;max-width:140px;display:block;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= htmlspecialchars($ch['stream_url']) ?>">
              <?= htmlspecialchars(substr($ch['stream_url'], 0, 30)) ?>…
            </span>
          </td>
          <td>
            <button class="btn btn-ghost btn-sm copy-btn" onclick="navigator.clipboard.writeText('<?= htmlspecialchars($proxyUrl) ?>').then(()=>{this.textContent='✓ Copied!';setTimeout(()=>this.textContent='📋 Copy',1500)})">📋 Copy</button>
          </td>
          <td>
            <div class="flex gap-2">
              <a href="<?= htmlspecialchars($proxyUrl) ?>" target="_blank" class="btn btn-ghost btn-sm" title="Test stream">▶</a>
              <form method="POST" style="display:inline">
                <input type="hidden" name="action" value="toggle">
                <input type="hidden" name="channel_id" value="<?= $ch['id'] ?>">
                <button type="submit" class="btn btn-ghost btn-sm"><?= $ch['is_active'] ? '⏸' : '▶' ?></button>
              </form>
              <form method="POST" style="display:inline" onsubmit="return confirm('Delete this channel?')">
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="channel_id" value="<?= $ch['id'] ?>">
                <button type="submit" class="btn btn-danger btn-sm">🗑</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <?php if ($totalPages > 1): ?>
  <div style="padding:16px 24px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-size:0.82rem;">
    <span class="text-muted">Page <?= $page ?> of <?= $totalPages ?> &nbsp;(<?= number_format($total) ?> total)</span>
    <div class="flex gap-2">
      <?php if ($page > 1): ?>
        <a href="?playlist_id=<?= $playlistId ?>&q=<?= urlencode($search) ?>&group=<?= urlencode($group) ?>&page=<?= $page - 1 ?>" class="btn btn-ghost btn-sm">← Prev</a>
      <?php endif; ?>
      <?php if ($page < $totalPages): ?>
        <a href="?playlist_id=<?= $playlistId ?>&q=<?= urlencode($search) ?>&group=<?= urlencode($group) ?>&page=<?= $page + 1 ?>" class="btn btn-primary btn-sm">Next →</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<div id="modalAddChannel" class="modal-overlay">
  <div class="modal">
    <div class="modal-head">
      <h3>➕ Add Channel</h3>
      <button class="modal-close" onclick="closeModal('modalAddChannel')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add">
        <div class="form-group">
          <label>Playlist</label>
          <select name="playlist_id" required>
            <?php foreach ($allPlaylists as $p): ?>
            <option value="<?= $p['id'] ?>" <?= $playlistId === (int)$p['id'] ? 'selected' : '' ?>><?= htmlspecialchars($p['name']) ?></option>
            <?php endforeach; ?>
          </select>
        </div>
        <div class="form-group">
          <label>Channel Name</label>
          <input type="text" name="name" placeholder="CNN HD" required>
        </div>
        <div class="form-group">
          <label>Stream URL</label>
          <input type="url" name="stream_url" placeholder="http://provider.com/stream/channel.m3u8" required>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Group / Category</label>
            <input type="text" name="group_title" placeholder="News" value="Uncategorized">
          </div>
          <div class="form-group">
            <label>Logo URL (optional)</label>
            <input type="url" name="logo" placeholder="https://...">
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="closeModal('modalAddChannel')" class="btn btn-ghost">Cancel</button>
        <button type="submit" class="btn btn-primary">➕ Add Channel</button>
      </div>
    </form>
  </div>
</div>
<?php
$body = ob_get_clean();
echo View::layout('Channels', $body, 'channels');
