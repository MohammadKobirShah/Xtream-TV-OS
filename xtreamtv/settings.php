<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — Settings Panel
 * ============================================================
 *  Developer        : Kobir Shah
 *  DEVELOPER_CREDIT : Powered by Kobir Shah
 *
 *  Includes:
 *    - Global FFmpeg mode toggle (off / on / auto)
 *    - FFmpeg quality preset selector
 *    - Per-channel FFmpeg override table
 *    - Site settings (name, user-agent, cache TTL)
 *    - FFmpeg availability status indicator
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/FFmpegProxy.php';
require_once __DIR__ . '/src/View.php';

$flash  = '';
$flashT = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'save_settings') {
        $allowed = [
            'ffmpeg_mode', 'ffmpeg_quality', 'site_name',
            'proxy_useragent', 'max_cache_age', 'epg_cache_hours', 'allow_register',
        ];
        foreach ($allowed as $key) {
            if (isset($_POST[$key])) {
                Database::setSetting($key, trim($_POST[$key]));
            }
        }
        $flash = 'Saved successfully.'; $flashT = 'success';
    }

    if ($act === 'set_channel_mode') {
        $cid  = (int)($_POST['channel_id'] ?? 0);
        $mode = $_POST['ffmpeg_mode'] ?? 'inherit';
        if (in_array($mode, ['inherit', 'off', 'on', 'auto'], strict: true) && $cid > 0) {
            Database::query(
                "UPDATE channels SET ffmpeg_mode = ? WHERE id = ?",
                [$mode, $cid]
            );
            $flash = "Channel #{$cid} FFmpeg mode set to '{$mode}'."; $flashT = 'success';
        }
    }

    if ($act === 'reset_channel_modes') {
        Database::query("UPDATE channels SET ffmpeg_mode = 'inherit'");
        $flash = 'All channel FFmpeg modes reset to inherit.'; $flashT = 'success';
    }

    header('Location: /xtreamtv/settings.php');
    exit;
}

$settings = Database::query("SELECT key, value FROM settings ORDER BY key")->fetchAll(\PDO::FETCH_KEY_PAIR);

$ffmpegMode    = $settings['ffmpeg_mode']    ?? 'off';
$ffmpegQuality = $settings['ffmpeg_quality'] ?? 'passthru';
$ffmpegAvail   = FFmpegProxy::available();
$ffmpegVersion = FFmpegProxy::version();

$customChannels = Database::query(
    "SELECT c.id, c.name, c.group_title, c.ffmpeg_mode, p.name AS playlist_name
     FROM channels c JOIN playlists p ON p.id = c.playlist_id
     WHERE c.ffmpeg_mode != 'inherit'
     ORDER BY c.group_title, c.name
     LIMIT 100"
)->fetchAll();

$page     = max(1, (int)($_GET['page'] ?? 1));
$perPage  = 30;
$offset   = ($page - 1) * $perPage;
$search   = trim($_GET['q'] ?? '');
$bind     = [];
$where    = '';
if ($search) { $where = "WHERE c.name LIKE ?"; $bind[] = "%{$search}%"; }

$total     = (int)Database::query("SELECT COUNT(*) FROM channels c {$where}", $bind)->fetchColumn();
$pages     = max(1, (int)ceil($total / $perPage));
$channels  = Database::query(
    "SELECT c.id, c.name, c.group_title, c.ffmpeg_mode, p.name AS playlist_name
     FROM channels c JOIN playlists p ON p.id = c.playlist_id
     {$where} ORDER BY c.group_title, c.name LIMIT {$perPage} OFFSET {$offset}",
    $bind
)->fetchAll();

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

$modeColors = [
    'inherit' => ['bg'=>'rgba(99,102,241,.12)', 'color'=>'#818cf8', 'label'=>'Inherit'],
    'off'     => ['bg'=>'rgba(16,185,129,.12)', 'color'=>'#10b981', 'label'=>'OFF (Passthru)'],
    'on'      => ['bg'=>'rgba(239,68,68,.12)',  'color'=>'#ef4444', 'label'=>'ON (FFmpeg)'],
    'auto'    => ['bg'=>'rgba(245,158,11,.12)', 'color'=>'#f59e0b', 'label'=>'AUTO'],
];

ob_start();
?>
<?php if ($flash): ?>
<div class="alert alert-<?= $e($flashT) ?>" style="margin-bottom:20px">
  <span><?= $e($flash) ?></span>
</div>
<?php endif; ?>

<div class="glass mb-6" style="padding:20px 24px;background:<?= $ffmpegAvail ? 'linear-gradient(90deg,rgba(16,185,129,.06),rgba(6,182,212,.04))' : 'rgba(239,68,68,.06)' ?>;border-color:<?= $ffmpegAvail ? 'rgba(16,185,129,.25)' : 'rgba(239,68,68,.25)' ?>">
  <div class="flex items-center justify-between">
    <div style="display:flex;align-items:center;gap:14px">
      <div style="font-size:2rem"><?= $ffmpegAvail ? '✅' : '❌' ?></div>
      <div>
        <div style="font-weight:700;font-size:.95rem">FFmpeg <?= $ffmpegAvail ? 'Detected' : 'Not Found' ?></div>
        <div style="font-size:.78rem;color:var(--text-muted);margin-top:2px">
          <?= $e($ffmpegVersion) ?><?= !$ffmpegAvail ? ' — Install FFmpeg in the Docker container or set mode to OFF.' : '' ?>
        </div>
      </div>
    </div>
    <div style="text-align:right;font-size:.8rem">
      <div style="color:var(--text-muted)">Global Mode</div>
      <div style="font-weight:800;font-size:1.1rem;color:<?= match($ffmpegMode){ 'off'=>'var(--neon-green)', 'on'=>'var(--neon-red)', 'auto'=>'var(--neon-amber)', default=>'var(--text-muted)' } ?>">
        <?= strtoupper($ffmpegMode) ?>
      </div>
    </div>
  </div>
</div>

<div class="grid-2 mb-6">

  <div class="glass">
    <div class="glass-header">
      <div class="glass-title">FFmpeg Global Mode</div>
    </div>
    <div class="glass-body">
      <form method="POST">
        <input type="hidden" name="action" value="save_settings">

        <div class="form-group">
          <label>FFmpeg Restream Mode</label>
          <div style="display:grid;grid-template-columns:1fr 1fr 1fr;gap:10px;margin-top:6px">
            <?php foreach (['off'=>['OFF — Passthru (Default)','Uses fpassthru() — fastest, zero CPU'],
                             'on' =>['ON — Always FFmpeg','All streams go through FFmpeg'],
                             'auto'=>['AUTO — Smart Fallback','Passthru first; FFmpeg if stream fails']] as $val=>[$lbl,$desc]): ?>
            <label style="cursor:pointer;display:block">
              <input type="radio" name="ffmpeg_mode" value="<?= $val ?>" <?= $ffmpegMode===$val?'checked':'' ?> style="display:none">
              <div class="mode-card <?= $ffmpegMode===$val?'mode-active':'' ?>">
                <div style="font-weight:700;font-size:.78rem"><?= $lbl ?></div>
                <div style="font-size:.68rem;color:var(--text-muted);margin-top:3px"><?= $desc ?></div>
              </div>
            </label>
            <?php endforeach; ?>
          </div>
        </div>

        <div class="form-group" style="margin-top:16px">
          <label>FFmpeg Quality Preset</label>
          <select name="ffmpeg_quality">
            <?php foreach (['passthru'=>'Passthru / 4K — Copy codecs, no re-encode (fastest)',
                             'hd'      =>'HD 720p — H264 + AAC (balanced)',
                             'sd'      =>'SD 480p — H264 + AAC (low bandwidth)'] as $val=>$lbl): ?>
            <option value="<?= $val ?>" <?= $ffmpegQuality===$val?'selected':'' ?>><?= $e($lbl) ?></option>
            <?php endforeach; ?>
          </select>
        </div>

        <div class="form-group">
          <label>Site Name</label>
          <input type="text" name="site_name" value="<?= $e($settings['site_name'] ?? 'XtreamTV IPTV OS') ?>">
        </div>
        <div class="form-group">
          <label>Proxy User-Agent</label>
          <input type="text" name="proxy_useragent" value="<?= $e($settings['proxy_useragent'] ?? '') ?>">
        </div>
        <div class="form-row">
          <div class="form-group">
            <label>M3U Cache TTL (seconds)</label>
            <input type="number" name="max_cache_age" value="<?= $e($settings['max_cache_age'] ?? '3600') ?>" min="60">
          </div>
          <div class="form-group">
            <label>EPG Cache (hours)</label>
            <input type="number" name="epg_cache_hours" value="<?= $e($settings['epg_cache_hours'] ?? '12') ?>" min="1">
          </div>
        </div>

        <button type="submit" class="btn btn-primary" style="width:100%;margin-top:4px">Save Settings</button>
      </form>
    </div>
  </div>

  <div class="glass">
    <div class="glass-header">
      <div class="glass-title">Mode Reference</div>
    </div>
    <div class="glass-body">
      <div style="display:flex;flex-direction:column;gap:10px;margin-bottom:20px">
        <?php foreach ([
          ['OFF — fpassthru()','Default. Best for all streams. Zero CPU overhead. Uses kernel sendfile.','var(--neon-green)'],
          ['ON — FFmpeg always','All streams re-mux through FFmpeg. Fixes broken streams, bypasses CDN blocks.','var(--neon-red)'],
          ['AUTO — Smart','Probes upstream first. Uses passthru if reachable, FFmpeg as fallback.','var(--neon-amber)'],
          ['INHERIT (per-channel)','Channel uses the global setting above.','var(--text-muted)'],
        ] as [$lbl,$desc,$col]): ?>
        <div style="display:flex;gap:12px;padding:10px 14px;border-radius:10px;background:rgba(255,255,255,.03);border:1px solid var(--border)">
          <div>
            <div style="font-weight:700;font-size:.82rem;color:<?= $col ?>"><?= $lbl ?></div>
            <div style="font-size:.74rem;color:var(--text-muted);margin-top:2px"><?= $desc ?></div>
          </div>
        </div>
        <?php endforeach; ?>
      </div>

      <?php if (!empty($customChannels)): ?>
      <div style="font-size:.72rem;font-weight:600;color:var(--text-muted);text-transform:uppercase;letter-spacing:1px;margin-bottom:8px">
        Channels with Custom Mode (<?= count($customChannels) ?>)
      </div>
      <div style="max-height:180px;overflow-y:auto">
        <?php foreach ($customChannels as $cc):
          $mc = $modeColors[$cc['ffmpeg_mode']] ?? $modeColors['inherit']; ?>
        <div style="display:flex;align-items:center;justify-content:space-between;padding:6px 10px;border-radius:8px;margin-bottom:4px;background:rgba(255,255,255,.03)">
          <div>
            <span style="font-size:.8rem;font-weight:600"><?= $e($cc['name']) ?></span>
            <span style="font-size:.7rem;color:var(--text-muted);margin-left:6px"><?= $e($cc['group_title']) ?></span>
          </div>
          <span style="font-size:.68rem;font-weight:700;padding:2px 8px;border-radius:20px;background:<?= $mc['bg'] ?>;color:<?= $mc['color'] ?>"><?= $mc['label'] ?></span>
        </div>
        <?php endforeach; ?>
      </div>
      <form method="POST" style="margin-top:12px">
        <input type="hidden" name="action" value="reset_channel_modes">
        <button type="submit" class="btn btn-ghost btn-sm" onclick="return confirm('Reset ALL channels to inherit global setting?')" style="width:100%">Reset All Channels to Inherit</button>
      </form>
      <?php else: ?>
      <div style="text-align:center;padding:24px 0;color:var(--text-muted);font-size:.82rem">
        <div style="font-size:2rem;margin-bottom:6px">All channels using global mode</div>
      </div>
      <?php endif; ?>
    </div>
  </div>

</div>

<div class="glass">
  <div class="glass-header">
    <div class="glass-title">Per-Channel FFmpeg Override</div>
    <span style="font-size:.75rem;color:var(--text-muted)"><?= number_format($total) ?> channels</span>
  </div>

  <div style="padding:14px 20px;border-bottom:1px solid var(--border)">
    <form method="GET" style="display:flex;gap:10px">
      <input type="text" name="q" placeholder="Search channels..." value="<?= $e($search) ?>"
             style="flex:1;padding:8px 12px;border-radius:8px;background:rgba(255,255,255,.04);border:1px solid var(--border);color:var(--text-primary);outline:none;font-size:.85rem">
      <button type="submit" class="btn btn-ghost btn-sm">Search</button>
      <?php if ($search): ?><a href="/xtreamtv/settings.php" class="btn btn-danger btn-sm">Clear</a><?php endif; ?>
    </form>
  </div>

  <div class="table-wrap">
    <table>
      <thead>
        <tr>
          <th>Channel</th><th>Group</th><th>Playlist</th><th>FFmpeg Mode</th><th>Set Override</th>
        </tr>
      </thead>
      <tbody>
      <?php foreach ($channels as $ch):
        $mc = $modeColors[$ch['ffmpeg_mode']] ?? $modeColors['inherit']; ?>
      <tr>
        <td style="font-weight:600;font-size:.85rem"><?= $e($ch['name']) ?></td>
        <td style="font-size:.78rem"><?= $e($ch['group_title']) ?></td>
        <td style="font-size:.75rem;color:var(--text-muted)"><?= $e($ch['playlist_name']) ?></td>
        <td>
          <span style="font-size:.7rem;font-weight:700;padding:2px 9px;border-radius:20px;background:<?= $mc['bg'] ?>;color:<?= $mc['color'] ?>">
            <?= $mc['label'] ?>
          </span>
        </td>
        <td>
          <form method="POST" style="display:flex;gap:6px;align-items:center">
            <input type="hidden" name="action" value="set_channel_mode">
            <input type="hidden" name="channel_id" value="<?= (int)$ch['id'] ?>">
            <select name="ffmpeg_mode" style="padding:4px 8px;border-radius:7px;background:rgba(255,255,255,.04);border:1px solid var(--border);color:var(--text-primary);font-size:.75rem;outline:none">
              <?php foreach (['inherit'=>'Inherit','off'=>'OFF','on'=>'ON','auto'=>'AUTO'] as $val=>$lbl): ?>
              <option value="<?= $val ?>" <?= $ch['ffmpeg_mode']===$val?'selected':'' ?>><?= $lbl ?></option>
              <?php endforeach; ?>
            </select>
            <button type="submit" class="btn btn-primary btn-sm">Set</button>
          </form>
        </td>
      </tr>
      <?php endforeach; ?>
      </tbody>
    </table>
  </div>

  <?php if ($pages > 1): ?>
  <div style="padding:14px 20px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-size:.82rem">
    <span style="color:var(--text-muted)">Page <?= $page ?> of <?= $pages ?></span>
    <div class="flex gap-2">
      <?php if ($page > 1): ?>
      <a href="?q=<?= urlencode($search) ?>&page=<?= $page-1 ?>" class="btn btn-ghost btn-sm">Prev</a>
      <?php endif; ?>
      <?php if ($page < $pages): ?>
      <a href="?q=<?= urlencode($search) ?>&page=<?= $page+1 ?>" class="btn btn-primary btn-sm">Next</a>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>
</div>

<style>
.mode-card {
  padding:14px 10px;border-radius:12px;text-align:center;cursor:pointer;
  border:2px solid var(--border);background:rgba(255,255,255,.03);transition:.2s;
}
.mode-card:hover { border-color:rgba(0,180,255,.3);background:rgba(0,180,255,.06); }
.mode-card.mode-active { border-color:var(--neon-blue);background:rgba(0,180,255,.1);box-shadow:0 0 20px rgba(0,180,255,.1); }
</style>
<?php
$body = ob_get_clean();
echo View::layout('Settings — FFmpeg & Proxy', $body, 'settings');
