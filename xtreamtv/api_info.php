<?php
/**
 * ============================================================
 *  XtreamTV — API Information & Setup Guide
 *  Developer: Kobir Shah
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';
require_once __DIR__ . '/src/View.php';

Security::requireLogin();

$user     = Database::query("SELECT * FROM users WHERE id = ?", [(int)$_SESSION['user_id']])->fetch();
$username = Security::e($user['username'] ?? '');
$token    = Security::e($user['api_token'] ?? '');
$baseUrl  = Security::e(APP_URL . '/xtreamtv');
$apiUrl   = $baseUrl . '/player_api.php';
$m3uUrl   = $baseUrl . '/proxy.php?action=m3u&t=' . $token;

ob_start();
?>
<div class="mb-6">
  <h2 style="font-size:1.4rem;font-weight:800">🔌 API Connection Info</h2>
  <div class="text-muted" style="font-size:0.82rem;margin-top:2px">Connect TiviMate, IPTV Smarters, GSE, VLC, or any Xtream-compatible player.</div>
</div>

<!-- Credential Cards -->
<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(260px,1fr));gap:16px;margin-bottom:28px;">
  <?php
  $creds = [
    ['label'=>'Server URL',  'val'=>$baseUrl,   'icon'=>'🌐', 'color'=>'var(--neon-blue)'],
    ['label'=>'Username',    'val'=>$username,   'icon'=>'👤', 'color'=>'var(--neon-purple)'],
    ['label'=>'Password / Token', 'val'=>$token, 'icon'=>'🔑', 'color'=>'var(--neon-cyan)'],
    ['label'=>'M3U URL',     'val'=>$m3uUrl,    'icon'=>'📡', 'color'=>'var(--neon-green)'],
  ];
  foreach ($creds as $c): ?>
  <div class="glass" style="padding:20px;">
    <div style="font-size:0.72rem;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px;"><?= $c['icon'] ?> <?= $c['label'] ?></div>
    <div style="font-family:monospace;font-size:0.82rem;color:<?= $c['color'] ?>;word-break:break-all;margin-bottom:12px;line-height:1.5"><?= $c['val'] ?></div>
    <button class="btn btn-ghost btn-sm" style="width:100%"
      onclick="navigator.clipboard.writeText('<?= addslashes($c['val']) ?>').then(()=>{this.textContent='✓ Copied!';setTimeout(()=>this.textContent='📋 Copy to Clipboard',1500)})">
      📋 Copy to Clipboard
    </button>
  </div>
  <?php endforeach; ?>
</div>

<!-- App Setup Guides -->
<div class="grid-2 mb-6">
  <!-- Xtream Setup -->
  <div class="glass">
    <div class="glass-header"><div class="glass-title">📱 TiviMate / IPTV Smarters Setup</div></div>
    <div class="glass-body">
      <div style="font-size:0.85rem;line-height:1.8;">
        <div style="margin-bottom:12px;">Choose <strong style="color:var(--neon-blue)">Xtream Codes API</strong> as the connection type, then enter:</div>
        <div style="background:rgba(0,0,0,0.4);border-radius:10px;padding:16px;border:1px solid var(--border);font-family:monospace;font-size:0.82rem;">
          <div><span style="color:var(--text-muted)">Server:  </span><span style="color:var(--neon-blue)"><?= $baseUrl ?></span></div>
          <div style="margin-top:8px"><span style="color:var(--text-muted)">Username:</span> <span style="color:var(--neon-purple)"><?= $username ?></span></div>
          <div style="margin-top:8px"><span style="color:var(--text-muted)">Password:</span> <span style="color:var(--neon-cyan)"><?= $token ?></span></div>
        </div>
        <div class="alert alert-info" style="margin-top:16px;margin-bottom:0">
          ✅ Compatible with TiviMate, IPTV Smarters Pro, GSE Smart IPTV, Perfect Player, Kodi (PVR IPTV Simple).
        </div>
      </div>
    </div>
  </div>

  <!-- VLC / M3U Setup -->
  <div class="glass">
    <div class="glass-header"><div class="glass-title">🎬 VLC / M3U Player Setup</div></div>
    <div class="glass-body">
      <div style="font-size:0.85rem;line-height:1.8;">
        <div style="margin-bottom:12px;">Use the M3U URL directly in VLC, Kodi, or any player supporting playlist URLs:</div>
        <div style="background:rgba(0,0,0,0.4);border-radius:10px;padding:16px;border:1px solid var(--border);font-family:monospace;font-size:0.78rem;word-break:break-all;color:var(--neon-green)">
          <?= $m3uUrl ?>
        </div>
        <div style="margin-top:12px;font-size:0.8rem;color:var(--text-muted)">
          In VLC: <strong>Media → Open Network Stream → Paste URL</strong><br>
          In Kodi: <strong>PVR IPTV Simple → M3U URL → Paste</strong>
        </div>
        <button class="btn btn-success btn-sm" style="margin-top:12px"
          onclick="navigator.clipboard.writeText('<?= addslashes($m3uUrl) ?>').then(()=>{this.textContent='✓ Copied!';setTimeout(()=>this.textContent='📋 Copy M3U URL',1500)})">
          📋 Copy M3U URL
        </button>
      </div>
    </div>
  </div>
</div>

<!-- API Endpoints Reference -->
<div class="glass mb-6">
  <div class="glass-header"><div class="glass-title">📖 Xtream Codes API Endpoints</div></div>
  <div class="glass-body" style="padding:0">
    <div class="table-wrap">
      <table>
        <thead><tr><th>Endpoint</th><th>Method</th><th>Description</th><th>Example</th></tr></thead>
        <tbody>
        <?php
        $endpoints = [
          ['/player_api.php',   'GET', 'User info & server info',     "?username={$username}&password={$token}"],
          ['/player_api.php',   'GET', 'Get live categories',          "?username={$username}&password={$token}&action=get_live_categories"],
          ['/player_api.php',   'GET', 'Get live streams',             "?username={$username}&password={$token}&action=get_live_streams"],
          ['/proxy.php',        'GET', 'Download M3U playlist',        "?action=m3u&t={$token}"],
          ['/proxy.php',        'GET', 'Stream a channel',             "?id=CHANNEL_ID&t={$token}"],
          ['/live/{u}/{p}/{id}.ts', 'GET', 'Direct TS stream (Xtream format)', '—'],
        ];
        foreach ($endpoints as $e): ?>
        <tr>
          <td><code style="font-size:0.78rem;color:var(--neon-blue)"><?= Security::e($baseUrl . $e[0]) ?></code></td>
          <td><span class="tag tag-active"><?= $e[1] ?></span></td>
          <td style="font-size:0.82rem"><?= Security::e($e[2]) ?></td>
          <td>
            <?php if ($e[3] !== '—'): ?>
            <button class="btn btn-ghost btn-sm"
              onclick="navigator.clipboard.writeText('<?= Security::e($baseUrl . $e[0] . $e[3]) ?>').then(()=>{this.textContent='✓';setTimeout(()=>this.textContent='📋',1500)})">📋</button>
            <?php else: ?>
            <span class="text-muted">—</span>
            <?php endif; ?>
          </td>
        </tr>
        <?php endforeach; ?>
        </tbody>
      </table>
    </div>
  </div>
</div>

<!-- Test API -->
<div class="glass">
  <div class="glass-header"><div class="glass-title">🧪 Live API Test</div></div>
  <div class="glass-body">
    <div style="display:flex;gap:12px;flex-wrap:wrap;margin-bottom:16px;">
      <a href="<?= $baseUrl ?>/player_api.php?username=<?= $username ?>&password=<?= $token ?>" target="_blank" class="btn btn-primary">🔍 Test User Info</a>
      <a href="<?= $baseUrl ?>/player_api.php?username=<?= $username ?>&password=<?= $token ?>&action=get_live_categories" target="_blank" class="btn btn-ghost">📂 Test Categories</a>
      <a href="<?= $baseUrl ?>/player_api.php?username=<?= $username ?>&password=<?= $token ?>&action=get_live_streams" target="_blank" class="btn btn-ghost">📺 Test Streams List</a>
      <a href="<?= $m3uUrl ?>" target="_blank" class="btn btn-success">⬇ Download M3U</a>
    </div>
    <div class="alert alert-info">
      💡 These links open in a new tab. View the JSON response to verify your API is working correctly.<br>
      Developed by <strong>Kobir Shah</strong> — XtreamTV v<?= APP_VERSION ?>
    </div>
  </div>
</div>
<?php
$body = ob_get_clean();
echo View::layout('API Info', $body, 'api');
