<?php
/**
 * ============================================================
 *  XtreamTV — Access Logs (Admin Only)
 *  Developer: Kobir Shah
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/View.php';

Security::requireLogin();
Security::requireAdmin();

$page    = max(1, (int)($_GET['page'] ?? 1));
$perPage = 50;
$offset  = ($page - 1) * $perPage;
$search  = trim($_GET['q'] ?? '');

$where  = [];
$params = [];
if ($search) {
    $where[]  = "(al.action LIKE ? OR al.meta LIKE ? OR u.username LIKE ? OR al.ip LIKE ?)";
    $params[] = "%{$search}%"; $params[] = "%{$search}%";
    $params[] = "%{$search}%"; $params[] = "%{$search}%";
}
$whereStr = $where ? 'WHERE ' . implode(' AND ', $where) : '';

$total      = (int)Database::query("SELECT COUNT(*) FROM access_log al LEFT JOIN users u ON u.id = al.user_id {$whereStr}", $params)->fetchColumn();
$totalPages = max(1, (int)ceil($total / $perPage));

$logs = Database::query(
    "SELECT al.*, u.username FROM access_log al
     LEFT JOIN users u ON u.id = al.user_id
     {$whereStr}
     ORDER BY al.created_at DESC
     LIMIT {$perPage} OFFSET {$offset}",
    $params
)->fetchAll();

// Clear logs action
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'clear_logs') {
    if (Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        Database::query("DELETE FROM access_log");
        View::flash('success', 'All logs cleared.');
        header('Location: /xtreamtv/logs.php');
        exit;
    }
}

$csrf = Security::csrfToken();

$actionColors = [
    'stream_start' => 'tag-active',
    'login'        => 'tag-user',
    'logout'       => 'tag-inactive',
    'playlist_add' => 'tag-admin',
];

ob_start();
?>
<?= View::getFlash() ?>

<div class="flex items-center justify-between mb-6">
  <div>
    <h2 style="font-size:1.4rem;font-weight:800">📋 Access Logs</h2>
    <div class="text-muted" style="font-size:0.82rem;margin-top:2px"><?= number_format($total) ?> log entries</div>
  </div>
  <form method="POST" style="display:inline">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="action" value="clear_logs">
    <button type="submit" class="btn btn-danger" onclick="return confirm('Clear ALL log entries? This cannot be undone.')">🗑 Clear Logs</button>
  </form>
</div>

<!-- Search -->
<div class="glass mb-4" style="padding:16px 20px;">
  <form method="GET" style="display:flex;gap:12px;align-items:flex-end;">
    <div style="flex:1">
      <label style="font-size:0.72rem;color:var(--text-muted);display:block;margin-bottom:4px;">Search Logs</label>
      <input type="text" name="q" placeholder="Search by user, action, IP, or details..." value="<?= Security::e($search) ?>">
    </div>
    <button type="submit" class="btn btn-ghost">🔍 Search</button>
    <?php if ($search): ?>
    <a href="/xtreamtv/logs.php" class="btn btn-danger">✕</a>
    <?php endif; ?>
  </form>
</div>

<div class="glass">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Time</th>
          <th>User</th>
          <th>Action</th>
          <th>IP Address</th>
          <th>Details</th>
        </tr>
      </thead>
      <tbody>
      <?php if (empty($logs)): ?>
        <tr><td colspan="6" style="text-align:center;padding:48px;color:var(--text-muted)">
          <div style="font-size:2.5rem;margin-bottom:10px">📋</div>
          <div>No log entries<?= $search ? " matching '{$search}'" : '' ?>.</div>
        </td></tr>
      <?php else: ?>
        <?php foreach ($logs as $i => $l): ?>
        <tr>
          <td class="text-muted"><?= $offset + $i + 1 ?></td>
          <td style="font-size:0.78rem;white-space:nowrap">
            <div><?= date('Y-m-d', (int)$l['created_at']) ?></div>
            <div class="text-muted"><?= date('H:i:s', (int)$l['created_at']) ?></div>
          </td>
          <td>
            <?php if ($l['username']): ?>
              <span class="neon-blue"><?= Security::e($l['username']) ?></span>
            <?php else: ?>
              <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
          <td><span class="tag <?= $actionColors[$l['action']] ?? 'tag-user' ?>"><?= Security::e($l['action']) ?></span></td>
          <td class="mono text-muted" style="font-size:0.78rem"><?= Security::e($l['ip'] ?? '—') ?></td>
          <td style="font-size:0.8rem;max-width:240px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap" title="<?= Security::e($l['meta'] ?? '') ?>"><?= Security::e($l['meta'] ?? '—') ?></td>
        </tr>
        <?php endforeach; ?>
      <?php endif; ?>
      </tbody>
    </table>
  </div>

  <!-- Pagination -->
  <?php if ($totalPages > 1): ?>
  <div style="padding:16px 24px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-size:0.82rem;">
    <span class="text-muted">Page <?= $page ?> of <?= $totalPages ?></span>
    <div class="flex gap-2">
      <?php if ($page > 1): ?>
        <a href="?q=<?= urlencode($search) ?>&page=<?= $page - 1 ?>" class="btn btn-ghost btn-sm">← Prev</a>
      <?php endif; ?>
      <?php if ($page < $totalPages): ?>
        <a href="?q=<?= urlencode($search) ?>&page=<?= $page + 1 ?>" class="btn btn-primary btn-sm">Next →</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>
<?php
$body = ob_get_clean();
echo View::layout('Access Logs', $body, 'logs');
