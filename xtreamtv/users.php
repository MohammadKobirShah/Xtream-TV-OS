<?php
/**
 * ============================================================
 *  XtreamTV — User Management (Admin Only)
 *  Developer: Kobir Shah
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/UserManager.php';
require_once __DIR__ . '/src/View.php';

Security::requireLogin();
Security::requireAdmin();

$csrf = Security::csrfToken();

// ── Handle Actions ─────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        View::flash('error', 'Invalid CSRF token.');
        header('Location: /xtreamtv/users.php');
        exit;
    }

    $action = $_POST['action'] ?? '';

    if ($action === 'create') {
        $username  = trim($_POST['username'] ?? '');
        $password  = $_POST['password'] ?? '';
        $role      = in_array($_POST['role'] ?? '', ['admin', 'user']) ? $_POST['role'] : 'user';
        $maxS      = max(1, (int)($_POST['max_streams'] ?? 1));
        $expiresAt = !empty($_POST['expires_at']) ? strtotime($_POST['expires_at']) : null;

        $id = UserManager::create($username, $password, $role, $maxS, $expiresAt ?: null);
        if ($id) {
            View::flash('success', "User '{$username}' created successfully.");
        } else {
            View::flash('error', 'Failed to create user. Username may already exist or validation failed.');
        }
    }

    if ($action === 'edit') {
        $uid  = (int)($_POST['user_id'] ?? 0);
        $data = [
            'username'    => trim($_POST['username'] ?? ''),
            'role'        => in_array($_POST['role'] ?? '', ['admin','user']) ? $_POST['role'] : 'user',
            'max_streams' => max(1, (int)($_POST['max_streams'] ?? 1)),
            'is_active'   => (int)($_POST['is_active'] ?? 1),
            'expires_at'  => !empty($_POST['expires_at']) ? strtotime($_POST['expires_at']) : null,
            'password'    => $_POST['password'] ?? '',
        ];
        UserManager::update($uid, $data);
        View::flash('success', 'User updated.');
    }

    if ($action === 'delete') {
        $uid = (int)($_POST['user_id'] ?? 0);
        if ($uid === (int)$_SESSION['user_id']) {
            View::flash('error', 'Cannot delete yourself.');
        } else {
            UserManager::delete($uid);
            View::flash('success', 'User deleted.');
        }
    }

    if ($action === 'regen_token') {
        $uid   = (int)($_POST['user_id'] ?? 0);
        $token = UserManager::regenerateToken($uid);
        View::flash('success', 'API token regenerated: ' . substr($token, 0, 16) . '…');
    }

    header('Location: /xtreamtv/users.php');
    exit;
}

// ── Fetch Users ────────────────────────────────────────────
$users = UserManager::getAll();

// Edit user fetch
$editUser = null;
if (!empty($_GET['edit'])) {
    $editUser = UserManager::getById((int)$_GET['edit']);
}

ob_start();
?>
<?= View::getFlash() ?>

<div class="flex items-center justify-between mb-6">
  <div>
    <h2 style="font-size:1.4rem;font-weight:800">👥 Users</h2>
    <div class="text-muted" style="font-size:0.82rem;margin-top:2px"><?= count($users) ?> registered account(s)</div>
  </div>
  <button onclick="openModal('modalCreate')" class="btn btn-primary">➕ Add User</button>
</div>

<div class="glass">
  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>#</th>
          <th>Username</th>
          <th>Role</th>
          <th>Streams</th>
          <th>Active</th>
          <th>Expires</th>
          <th>API Token</th>
          <th>Last Login</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($users as $i => $u): ?>
      <tr>
        <td class="text-muted"><?= $i + 1 ?></td>
        <td>
          <div style="font-weight:600"><?= Security::e($u['username']) ?></div>
          <div style="font-size:0.72rem;color:var(--text-muted)">ID #<?= $u['id'] ?></div>
        </td>
        <td><span class="tag tag-<?= $u['role'] ?>"><?= $u['role'] ?></span></td>
        <td>
          <span class="neon-blue"><?= (int)$u['active_streams'] ?></span>
          <span class="text-muted"> / <?= $u['max_streams'] ?></span>
        </td>
        <td>
          <?php if ($u['is_active']): ?>
            <span class="tag tag-active">✓ Active</span>
          <?php else: ?>
            <span class="tag tag-inactive">✗ Disabled</span>
          <?php endif; ?>
        </td>
        <td class="text-muted" style="font-size:0.8rem">
          <?php if ($u['expires_at']): ?>
            <?php $expired = $u['expires_at'] < time(); ?>
            <span style="color:<?= $expired ? 'var(--neon-red)' : 'var(--neon-green)' ?>">
              <?= $expired ? '⚠ ' : '' ?><?= date('Y-m-d', (int)$u['expires_at']) ?>
            </span>
          <?php else: ?>
            <span class="neon-green">∞ Never</span>
          <?php endif; ?>
        </td>
        <td>
          <div style="display:flex;align-items:center;gap:6px;">
            <span class="mono text-muted" style="font-size:0.7rem"><?= Security::e(substr($u['api_token'] ?? '—', 0, 12)) ?>…</span>
            <button class="btn btn-ghost btn-sm"
              onclick="navigator.clipboard.writeText('<?= Security::e($u['api_token'] ?? '') ?>').then(()=>{this.textContent='✓';setTimeout(()=>this.textContent='📋',1500)})">📋</button>
          </div>
        </td>
        <td class="text-muted" style="font-size:0.78rem">
          <?= $u['last_login'] ? date('m/d H:i', (int)$u['last_login']) : 'Never' ?>
        </td>
        <td>
          <div class="flex gap-2">
            <button onclick="openEditModal(<?= htmlspecialchars(json_encode($u), ENT_QUOTES) ?>)" class="btn btn-ghost btn-sm">✏ Edit</button>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="regen_token">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <button type="submit" class="btn btn-ghost btn-sm" onclick="return confirm('Regenerate API token? Old token will stop working.')">🔑</button>
            </form>
            <?php if ((int)$u['id'] !== (int)$_SESSION['user_id']): ?>
            <form method="POST" style="display:inline">
              <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="user_id" value="<?= $u['id'] ?>">
              <button type="submit" class="btn btn-danger btn-sm" onclick="return confirm('Delete user &quot;<?= Security::e($u['username']) ?>&quot; and all their data?')">🗑</button>
            </form>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>
</div>

<!-- ═══ Modal: Create User ═══ -->
<div id="modalCreate" class="modal-overlay <?= ($_GET['action'] ?? '') === 'add' ? 'open' : '' ?>">
  <div class="modal">
    <div class="modal-head">
      <h3>➕ Create New User</h3>
      <button class="modal-close" onclick="closeModal('modalCreate')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="create">
        <div class="form-row">
          <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" placeholder="john_doe" required minlength="3">
          </div>
          <div class="form-group">
            <label>Password</label>
            <input type="password" name="password" placeholder="Min 6 characters" required minlength="6">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Role</label>
            <select name="role">
              <option value="user">User</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div class="form-group">
            <label>Max Concurrent Streams</label>
            <input type="number" name="max_streams" value="1" min="1" max="99">
          </div>
        </div>
        <div class="form-group">
          <label>Expiry Date (leave blank = never expires)</label>
          <input type="date" name="expires_at" min="<?= date('Y-m-d') ?>">
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="closeModal('modalCreate')" class="btn btn-ghost">Cancel</button>
        <button type="submit" class="btn btn-primary">✓ Create User</button>
      </div>
    </form>
  </div>
</div>

<!-- ═══ Modal: Edit User ═══ -->
<div id="modalEdit" class="modal-overlay">
  <div class="modal">
    <div class="modal-head">
      <h3>✏ Edit User</h3>
      <button class="modal-close" onclick="closeModal('modalEdit')">✕</button>
    </div>
    <form method="POST" id="editForm">
      <div class="modal-body">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="user_id" id="edit_user_id">
        <div class="form-row">
          <div class="form-group">
            <label>Username</label>
            <input type="text" name="username" id="edit_username" required minlength="3">
          </div>
          <div class="form-group">
            <label>New Password (blank = keep current)</label>
            <input type="password" name="password" placeholder="Leave blank to keep">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Role</label>
            <select name="role" id="edit_role">
              <option value="user">User</option>
              <option value="admin">Admin</option>
            </select>
          </div>
          <div class="form-group">
            <label>Max Streams</label>
            <input type="number" name="max_streams" id="edit_max_streams" min="1" max="99">
          </div>
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>Status</label>
            <select name="is_active" id="edit_is_active">
              <option value="1">Active</option>
              <option value="0">Disabled</option>
            </select>
          </div>
          <div class="form-group">
            <label>Expiry Date</label>
            <input type="date" name="expires_at" id="edit_expires_at">
          </div>
        </div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="closeModal('modalEdit')" class="btn btn-ghost">Cancel</button>
        <button type="submit" class="btn btn-purple">✓ Save Changes</button>
      </div>
    </form>
  </div>
</div>

<script>
function openEditModal(user) {
  document.getElementById('edit_user_id').value     = user.id;
  document.getElementById('edit_username').value    = user.username;
  document.getElementById('edit_role').value        = user.role;
  document.getElementById('edit_max_streams').value = user.max_streams;
  document.getElementById('edit_is_active').value   = user.is_active;
  document.getElementById('edit_expires_at').value  = user.expires_at
    ? new Date(user.expires_at * 1000).toISOString().split('T')[0] : '';
  openModal('modalEdit');
}
</script>
<?php
$body = ob_get_clean();
echo View::layout('Users', $body, 'users');
