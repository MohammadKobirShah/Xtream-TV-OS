<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — Main Dashboard (Phase 3)
 *  Premium SaaS-tier dark glassmorphism UI
 * ============================================================
 *  Developer        : Kobir Shah
 *  DEVELOPER_CREDIT : Powered by Kobir Shah
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/engine.php';
require_once __DIR__ . '/epg.php';

$flashMsg  = '';
$flashType = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = $_POST['action'] ?? '';

    if ($act === 'add_m3u_url') {
        $name   = trim($_POST['name']    ?? '');
        $url    = trim($_POST['m3u_url'] ?? '');
        $epgUrl = trim($_POST['epg_url'] ?? '');

        if (!$name || !$url) {
            $flashMsg = 'Name and M3U URL are required.'; $flashType = 'error';
        } elseif (!filter_var($url, FILTER_VALIDATE_URL)) {
            $flashMsg = 'Invalid M3U URL.'; $flashType = 'error';
        } else {
            try {
                M3UEngine::assertSafeUrl($url);
                Database::query(
                    "INSERT INTO playlists (name, url, epg_url) VALUES (?, ?, ?)",
                    [$name, $url, $epgUrl ?: null]
                );
                $playlistId = (int)Database::lastInsertId();

                set_time_limit(300);
                $channels = M3UEngine::parseM3U($url, $playlistId);
                $count    = count($channels);

                $pdo  = Database::getInstance();
                $stmt = $pdo->prepare(
                    "INSERT INTO channels (playlist_id, tvg_id, tvg_name, tvg_logo, group_title, name, stream_url, stream_type, sort_order)
                     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)"
                );
                $pdo->beginTransaction();
                try {
                    foreach ($channels as $i => $ch) {
                        $stmt->execute([
                            $playlistId, $ch['tvg_id']??'', $ch['tvg_name']??'',
                            $ch['tvg_logo']??'', $ch['group_title']??'Uncategorized',
                            $ch['name']??'Unknown', $ch['stream_url']??'',
                            $ch['stream_type']??'live', $i,
                        ]);
                    }
                    $pdo->commit();
                } catch (\Throwable $e) {
                    $pdo->rollBack();
                    throw $e;
                }

                Database::query(
                    "UPDATE playlists SET channel_count = ?, last_synced = strftime('%s','now') WHERE id = ?",
                    [$count, $playlistId]
                );

                $epgImported = 0;
                if ($epgUrl) {
                    try {
                        M3UEngine::assertSafeUrl($epgUrl);
                        $epgImported = EPGEngine::importEPG($epgUrl, $playlistId);
                    } catch (\Throwable $e) {}
                }

                $flashMsg  = "✅ Imported '{$name}' with {$count} channels" . ($epgImported ? " + {$epgImported} EPG entries." : ".");
                $flashType = 'success';

            } catch (\Throwable $e) {
                $flashMsg  = 'Import failed: ' . $e->getMessage();
                $flashType = 'error';
            }
        }
    }

    if ($act === 'delete_playlist') {
        $pid = (int)($_POST['playlist_id'] ?? 0);
        $pl  = Database::query("SELECT * FROM playlists WHERE id = ?", [$pid])->fetch();
        if ($pl) {
            if ($pl['source_file'] && file_exists($pl['source_file'])) unlink($pl['source_file']);
            M3UEngine::invalidateCache($pid);
            Database::query("DELETE FROM playlists WHERE id = ?", [$pid]);
            $flashMsg = 'Playlist deleted.'; $flashType = 'success';
        }
    }
}

$db = Database::getInstance();

$q = function(string $sql, array $params = []) use ($db): int {
    $s = $db->prepare($sql); $s->execute($params); return (int)$s->fetchColumn();
};

$stats = [
    'playlists'    => $q("SELECT COUNT(*) FROM playlists"),
    'channels'     => $q("SELECT COUNT(*) FROM channels WHERE is_active=1"),
    'live'         => $q("SELECT COUNT(*) FROM channels WHERE stream_type='live'"),
    'vod'          => $q("SELECT COUNT(*) FROM channels WHERE stream_type='vod'"),
    'epg_programs' => (int)$db->query("SELECT COUNT(*) FROM epg_programs")->fetchColumn(),
    'uptime'       => preg_replace('/\s+/', ' ', shell_exec('uptime 2>/dev/null') ?: 'N/A'),
];

$playlists = Database::query("SELECT * FROM playlists ORDER BY added_at DESC")->fetchAll();

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Dashboard — XtreamTV IPTV OS | Kobir Shah</title>
<meta name="author" content="Kobir Shah">
<style>
/* ══════════════════════════════════════════════════════════
   XTREAMTV IPTV OS — DASHBOARD
   Developer: Kobir Shah | DEVELOPER_CREDIT: Powered by Kobir Shah
══════════════════════════════════════════════════════════ */
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
:root{
  --bg:#050508;--bg-surface:#0a0a14;--bg-card:rgba(10,10,22,0.9);
  --blue:#00b4ff;--purple:#a855f7;--cyan:#06b6d4;--green:#10b981;
  --red:#ef4444;--amber:#f59e0b;--text:#e2e8f0;--muted:#64748b;--dim:#94a3b8;
  --border:rgba(99,102,241,0.14);--glow:rgba(0,180,255,0.09);
  --nav-w:230px;--radius:14px;--radius-lg:18px;
}
html,body{height:100%;background:var(--bg);color:var(--text);font-family:'Segoe UI',system-ui,sans-serif;overflow-x:hidden;}
body::before{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;background-image:linear-gradient(rgba(0,180,255,0.025) 1px,transparent 1px),linear-gradient(90deg,rgba(0,180,255,0.025) 1px,transparent 1px);background-size:36px 36px;}
body::after{content:'';position:fixed;inset:0;pointer-events:none;z-index:0;background:radial-gradient(ellipse 65% 55% at 15% 10%,rgba(168,85,247,0.07) 0%,transparent 55%),radial-gradient(ellipse 55% 65% at 85% 85%,rgba(0,180,255,0.06) 0%,transparent 55%);}

/* ── SIDEBAR ── */
.sidebar{position:fixed;top:0;left:0;bottom:0;width:var(--nav-w);background:rgba(5,5,14,0.97);border-right:1px solid var(--border);display:flex;flex-direction:column;z-index:100;box-shadow:4px 0 40px rgba(0,0,0,0.5);}
.sb-logo{padding:22px 18px 18px;border-bottom:1px solid var(--border);}
.logo-txt{font-size:1.5rem;font-weight:800;letter-spacing:-.5px;background:linear-gradient(135deg,var(--blue),var(--purple));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;}
.logo-sub{font-size:.62rem;color:var(--muted);letter-spacing:2.5px;text-transform:uppercase;margin-top:2px;}
.nav{padding:14px 10px;flex:1;overflow-y:auto;}
.nav-sect{font-size:.6rem;color:var(--muted);letter-spacing:2px;text-transform:uppercase;padding:8px 8px 4px;margin-top:8px;}
.nav-item{display:flex;align-items:center;gap:10px;padding:9px 12px;border-radius:10px;margin-bottom:2px;color:var(--dim);font-size:.83rem;font-weight:500;cursor:pointer;transition:.2s;text-decoration:none;position:relative;}
.nav-item:hover{background:rgba(0,180,255,0.07);color:var(--text);transform:translateX(2px);}
.nav-item.active{background:linear-gradient(90deg,rgba(0,180,255,0.18),rgba(168,85,247,0.06));color:var(--blue);border-left:3px solid var(--blue);box-shadow:inset 0 0 20px rgba(0,180,255,0.05);}
.nav-icon{font-size:1rem;width:18px;text-align:center;}

/* ── MAIN ── */
.main{margin-left:var(--nav-w);min-height:100vh;display:flex;flex-direction:column;position:relative;z-index:1;}
.topbar{position:sticky;top:0;z-index:50;background:rgba(5,5,8,.92);backdrop-filter:blur(20px);border-bottom:1px solid var(--border);padding:0 28px;height:56px;display:flex;align-items:center;justify-content:space-between;}
.tb-title{font-size:1rem;font-weight:700;}
.tb-right{display:flex;align-items:center;gap:12px;}
.content{padding:26px 28px;flex:1;}

/* ── STAT GRID ── */
.stats{display:grid;grid-template-columns:repeat(auto-fit,minmax(170px,1fr));gap:14px;margin-bottom:24px;}
.stat{padding:18px 20px;border-radius:var(--radius-lg);border:1px solid var(--border);background:var(--bg-card);backdrop-filter:blur(16px);position:relative;overflow:hidden;transition:.2s;}
.stat::before{content:'';position:absolute;top:0;left:0;right:0;height:2px;background:var(--c,var(--blue));}
.stat:hover{transform:translateY(-2px);border-color:rgba(0,180,255,.2);}
.stat-icon{font-size:1.5rem;margin-bottom:8px;}
.stat-val{font-size:1.8rem;font-weight:800;color:var(--c,var(--blue));line-height:1;}
.stat-lbl{font-size:.67rem;color:var(--muted);margin-top:3px;text-transform:uppercase;letter-spacing:1px;}

/* ── GLASS CARD ── */
.glass{background:var(--bg-card);backdrop-filter:blur(16px);border:1px solid var(--border);border-radius:var(--radius-lg);box-shadow:0 8px 32px rgba(0,0,0,.4);transition:.2s;}
.glass:hover{border-color:rgba(0,180,255,.18);}
.glass-head{padding:16px 20px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-weight:700;font-size:.9rem;}
.glass-body{padding:20px;}

/* ── FORM ── */
.form-group{margin-bottom:16px;}
label{display:block;font-size:.72rem;font-weight:600;color:var(--dim);margin-bottom:5px;text-transform:uppercase;letter-spacing:.5px;}
input[type=text],input[type=url],input[type=number],input[type=password],input[type=date],select,textarea{width:100%;padding:10px 14px;border-radius:10px;font-size:.88rem;background:rgba(255,255,255,.04);border:1px solid var(--border);color:var(--text);outline:none;transition:.2s;font-family:inherit;}
input:focus,select:focus,textarea:focus{border-color:var(--blue);box-shadow:0 0 0 3px rgba(0,180,255,.1);background:rgba(255,255,255,.06);}
.form-row{display:grid;grid-template-columns:1fr 1fr;gap:14px;}
.grid-2{display:grid;grid-template-columns:1fr 1fr;gap:18px;margin-bottom:20px;}

/* ── BUTTONS ── */
.btn{display:inline-flex;align-items:center;gap:6px;padding:9px 18px;border-radius:10px;font-size:.83rem;font-weight:600;cursor:pointer;border:1px solid transparent;text-decoration:none;transition:.2s;white-space:nowrap;}
.btn-p{background:linear-gradient(135deg,var(--blue),#0070aa);color:#fff;border-color:rgba(0,180,255,.35);box-shadow:0 4px 18px rgba(0,180,255,.18);}
.btn-p:hover{transform:translateY(-1px);box-shadow:0 6px 26px rgba(0,180,255,.3);}
.btn-v{background:linear-gradient(135deg,var(--purple),#7c3aed);color:#fff;border-color:rgba(168,85,247,.35);box-shadow:0 4px 18px rgba(168,85,247,.18);}
.btn-g{background:rgba(255,255,255,.04);color:var(--dim);border-color:var(--border);}
.btn-g:hover{background:rgba(255,255,255,.08);color:var(--text);}
.btn-d{background:rgba(239,68,68,.1);color:var(--red);border-color:rgba(239,68,68,.25);}
.btn-s{background:rgba(16,185,129,.1);color:var(--green);border-color:rgba(16,185,129,.25);}
.btn-sm{padding:5px 12px;font-size:.75rem;border-radius:8px;}

/* ── TABLE ── */
.tbl-wrap{overflow-x:auto;}
table{width:100%;border-collapse:collapse;font-size:.82rem;}
th{padding:10px 16px;text-align:left;font-size:.66rem;font-weight:600;color:var(--muted);text-transform:uppercase;letter-spacing:1px;border-bottom:1px solid var(--border);}
td{padding:10px 16px;border-bottom:1px solid rgba(99,102,241,.05);vertical-align:middle;}
tr:last-child td{border:none;}
tbody tr{transition:.15s;}
tbody tr:hover{background:rgba(0,180,255,.04);}

/* ── TAGS ── */
.tag{display:inline-block;padding:2px 8px;border-radius:20px;font-size:.67rem;font-weight:600;}
.t-live{background:rgba(16,185,129,.15);color:var(--green);}
.t-vod{background:rgba(168,85,247,.12);color:var(--purple);}
.t-off{background:rgba(239,68,68,.1);color:var(--red);}
.pulse{display:inline-block;width:7px;height:7px;border-radius:50%;background:var(--green);box-shadow:0 0 6px var(--green);animation:p 2s infinite;margin-left:5px;}
@keyframes p{0%,100%{opacity:1}50%{opacity:.4}}

/* ── ALERTS ── */
.alert{padding:12px 16px;border-radius:10px;font-size:.85rem;margin-bottom:18px;display:flex;align-items:center;gap:10px;border:1px solid;}
.alert-success{background:rgba(16,185,129,.1);color:#6ee7b7;border-color:rgba(16,185,129,.25);}
.alert-error{background:rgba(239,68,68,.1);color:#fca5a5;border-color:rgba(239,68,68,.25);}
.alert-info{background:rgba(0,180,255,.07);color:#93c5fd;border-color:rgba(0,180,255,.2);}

/* ── MODAL ── */
.modal-overlay{display:none;position:fixed;inset:0;z-index:200;background:rgba(0,0,0,.75);backdrop-filter:blur(6px);align-items:center;justify-content:center;}
.modal-overlay.open{display:flex;}
.modal{background:#0c0c1e;border:1px solid var(--border);border-radius:var(--radius-lg);width:min(560px,95vw);box-shadow:0 24px 80px rgba(0,0,0,.8),0 0 60px rgba(0,180,255,.05);overflow:hidden;}
.modal-head{padding:18px 22px;border-bottom:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;background:linear-gradient(90deg,rgba(0,180,255,.05),rgba(168,85,247,.05));}
.modal-head h3{font-size:.95rem;font-weight:700;}
.modal-close{background:none;border:none;color:var(--muted);cursor:pointer;font-size:1.3rem;padding:2px 8px;border-radius:6px;}
.modal-close:hover{background:rgba(255,255,255,.08);color:var(--text);}
.modal-body{padding:22px;max-height:70vh;overflow-y:auto;}
.modal-foot{padding:14px 22px;border-top:1px solid var(--border);display:flex;justify-content:flex-end;gap:10px;}

/* ── MISC ── */
.mono{font-family:'Courier New',monospace;font-size:.78rem;}
.neon-b{color:var(--blue);}
.neon-p{color:var(--purple);}
.neon-g{color:var(--green);}
.text-muted{color:var(--muted);}
.flex{display:flex;}.items-center{align-items:center;}.gap-2{gap:8px;}.gap-3{gap:12px;}.justify-between{justify-content:space-between;}
.mb-4{margin-bottom:16px;}.mb-6{margin-bottom:24px;}.mt-4{margin-top:16px;}

/* ── FOOTER ── */
footer{padding:14px 28px;border-top:1px solid var(--border);display:flex;align-items:center;justify-content:space-between;font-size:.72rem;color:var(--muted);background:rgba(5,5,8,.9);}
.credit{background:linear-gradient(90deg,var(--blue),var(--purple));-webkit-background-clip:text;-webkit-text-fill-color:transparent;background-clip:text;font-weight:700;}

/* scrollbar */
::-webkit-scrollbar{width:5px;height:5px;}
::-webkit-scrollbar-track{background:var(--bg);}
::-webkit-scrollbar-thumb{background:rgba(99,102,241,.35);border-radius:3px;}

@media(max-width:768px){
  .sidebar{width:58px;}
  .sb-logo .logo-sub,.nav-item span:last-child{display:none;}
  .logo-txt{font-size:.9rem;}
  .main{margin-left:58px;}
  .content{padding:14px;}
  .form-row,.grid-2{grid-template-columns:1fr;}
  .stats{grid-template-columns:1fr 1fr;}
}
</style>
</head>
<body>

<!-- ══ SIDEBAR ══ -->
<aside class="sidebar">
  <div class="sb-logo">
    <div class="logo-txt">⚡ XtreamTV</div>
    <div class="logo-sub">IPTV OS v<?= APP_VERSION ?></div>
  </div>
  <nav class="nav">
    <div class="nav-sect">Core</div>
    <a href="index.php"    class="nav-item active"><span class="nav-icon">⚡</span><span>Dashboard</span></a>
    <a href="player.php"   class="nav-item"><span class="nav-icon">📺</span><span>Live TV</span></a>
    <a href="playlists.php" class="nav-item"><span class="nav-icon">📡</span><span>Playlists</span></a>
    <a href="channels.php" class="nav-item"><span class="nav-icon">☰</span><span>Channels</span></a>
    <div class="nav-sect">Tools</div>
    <a href="settings.php" class="nav-item"><span class="nav-icon">⚙️</span><span>Settings</span></a>
  </nav>
</aside>

<!-- ══ MAIN ══ -->
<main class="main">
  <div class="topbar">
    <div class="tb-title">⚡ Dashboard</div>
    <div class="tb-right">
      <a href="player.php" class="btn btn-v btn-sm">📺 Open Player</a>
      <span style="font-size:.7rem;color:var(--muted)"><?= APP_NAME ?> v<?= APP_VERSION ?></span>
    </div>
  </div>

  <div class="content">

    <!-- ── Flash ── -->
    <?php if ($flashMsg): ?>
    <div class="alert alert-<?= $e($flashType) ?>"><span><?= $e($flashMsg) ?></span></div>
    <?php endif; ?>

    <!-- ── Stats ── -->
    <div class="stats">
      <div class="stat" style="--c:var(--blue)">
        <div class="stat-icon">📡</div>
        <div class="stat-val"><?= $stats['playlists'] ?></div>
        <div class="stat-lbl">Playlists</div>
      </div>
      <div class="stat" style="--c:var(--purple)">
        <div class="stat-icon">📺</div>
        <div class="stat-val"><?= number_format($stats['channels']) ?></div>
        <div class="stat-lbl">Channels</div>
      </div>
      <div class="stat" style="--c:var(--green)">
        <div class="stat-icon">🔴</div>
        <div class="stat-val"><?= number_format($stats['live']) ?></div>
        <div class="stat-lbl">Live Channels</div>
      </div>
      <div class="stat" style="--c:var(--cyan)">
        <div class="stat-icon">🎬</div>
        <div class="stat-val"><?= number_format($stats['vod']) ?></div>
        <div class="stat-lbl">VOD</div>
      </div>
      <div class="stat" style="--c:var(--amber)">
        <div class="stat-icon">📅</div>
        <div class="stat-val"><?= number_format($stats['epg_programs']) ?></div>
        <div class="stat-lbl">EPG Programs</div>
      </div>
      <div class="stat" style="--c:var(--dim)">
        <div class="stat-icon">🖥</div>
        <div class="stat-val" style="font-size:1.1rem"><?= $stats['uptime'] ?></div>
        <div class="stat-lbl">Server Uptime</div>
      </div>
    </div>

    <!-- ── Add M3U / Quick Actions ── -->
    <div class="grid-2">

      <!-- Add M3U URL Form -->
      <div class="glass">
        <div class="glass-head">
          ➕ Add M3U Playlist
          <button onclick="openModal('modalFile')" class="btn btn-g btn-sm">📂 Upload File</button>
        </div>
        <div class="glass-body">
          <form method="POST">
            <input type="hidden" name="action" value="add_m3u_url">
            <div class="form-row">
              <div class="form-group">
                <label>Playlist Name</label>
                <input type="text" name="name" placeholder="My Sports Pack" required>
              </div>
              <div class="form-group">
                <label>M3U / M3U8 URL</label>
                <input type="url" name="m3u_url" placeholder="http://provider.com/list.m3u" required>
              </div>
            </div>
            <div class="form-group">
              <label>EPG / XMLTV URL <span style="color:var(--muted);font-weight:400">(optional)</span></label>
              <input type="url" name="epg_url" placeholder="http://epg-provider.com/guide.xml">
            </div>
            <div class="alert alert-info" style="margin-bottom:14px">
              ⚡ Playlists are parsed via streaming chunked read — supports unlimited file sizes without RAM spikes.
            </div>
            <button type="submit" class="btn btn-p" style="width:100%">⬇ Import Playlist</button>
          </form>
        </div>
      </div>

      <!-- Quick Actions -->
      <div class="glass">
        <div class="glass-head">⚡ Quick Actions</div>
        <div class="glass-body">
          <div style="display:flex;flex-wrap:wrap;gap:10px">
            <a href="player.php" class="btn btn-v">📺 Live TV Player</a>
            <button onclick="openModal('modalAddUrl')" class="btn btn-p">➕ Add Playlist</button>
            <a href="playlists.php" class="btn btn-g">📡 Manage Playlists</a>
            <a href="proxy.php?action=m3u" class="btn btn-s" target="_blank">⬇ Download All M3U</a>
            <a href="settings.php" class="btn btn-g">⚙️ Settings</a>
          </div>
        </div>
      </div>
    </div>

    <!-- ── Playlists Table ── -->
    <div class="glass mb-6">
      <div class="glass-head">
        📡 Playlists
        <div class="flex gap-2">
          <span style="font-size:.72rem;color:var(--muted)"><?= count($playlists) ?> total</span>
          <button onclick="openModal('modalAddUrl')" class="btn btn-p btn-sm">+ Add</button>
        </div>
      </div>
      <div class="tbl-wrap">
        <table>
          <thead>
            <tr>
              <th>#</th><th>Name</th>
              <th>Channels</th><th>EPG</th><th>Last Sync</th><th>Actions</th>
            </tr>
          </thead>
          <tbody>
          <?php if (empty($playlists)): ?>
          <tr><td colspan="6" style="text-align:center;padding:48px;color:var(--muted)">
            <div style="font-size:2.5rem;margin-bottom:10px">📡</div>
            <div>No playlists yet. Add your first M3U above!</div>
          </td></tr>
          <?php else: ?>
          <?php foreach ($playlists as $i => $pl): ?>
          <tr>
            <td class="text-muted"><?= $i+1 ?></td>
            <td>
              <div style="font-weight:600"><?= $e($pl['name']) ?></div>
              <?php if ($pl['url']): ?>
              <div class="mono text-muted" style="font-size:.68rem;max-width:260px;overflow:hidden;text-overflow:ellipsis;white-space:nowrap"><?= $e($pl['url']) ?></div>
              <?php endif; ?>
            </td>
            <td><span style="font-weight:700;color:var(--blue)"><?= number_format((int)$pl['channel_count']) ?></span></td>
            <td><?= $pl['epg_url'] ? '<span class="tag t-live">✓ EPG</span>' : '<span style="color:var(--muted)">—</span>' ?></td>
            <td class="text-muted" style="font-size:.76rem"><?= $pl['last_synced'] ? date('m/d H:i', (int)$pl['last_synced']) : 'Never' ?></td>
            <td>
              <div class="flex gap-2">
                <a href="player.php?playlist_id=<?= $pl['id'] ?>" class="btn btn-g btn-sm">📺 Watch</a>
                <a href="channels.php?playlist_id=<?= $pl['id'] ?>" class="btn btn-g btn-sm">☰ Channels</a>
                <a href="proxy.php?action=m3u&playlist_id=<?= $pl['id'] ?>" class="btn btn-s btn-sm">⬇ M3U</a>
                <?php if ($pl['epg_url']): ?>
                <a href="epg_api.php?action=import&epg_url=<?= urlencode($pl['epg_url']) ?>&playlist_id=<?= $pl['id'] ?>" class="btn btn-g btn-sm" target="_blank">📅 Sync EPG</a>
                <?php endif; ?>
                <form method="POST" style="display:inline">
                  <input type="hidden" name="action" value="delete_playlist">
                  <input type="hidden" name="playlist_id" value="<?= $pl['id'] ?>">
                  <button type="submit" class="btn btn-d btn-sm" onclick="return confirm('Delete this playlist and all channels?')">🗑</button>
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

  </div><!-- /content -->

  <!-- ══ FOOTER ══ -->
  <footer>
    <span>© <?= date('Y') ?> IPTV OS | Designed &amp; Developed by <span class="credit">Kobir Shah</span></span>
    <span><?= APP_NAME ?> <strong>v<?= APP_VERSION ?></strong> &nbsp;|&nbsp; <?= DEVELOPER_CREDIT ?></span>
    <span style="font-size:.68rem;color:var(--muted)">Built by <span class="credit">Kobir Shah</span></span>
  </footer>
</main>

<!-- ══ MODAL: Add URL ══ -->
<div id="modalAddUrl" class="modal-overlay">
  <div class="modal">
    <div class="modal-head">
      <h3>🔗 Add Playlist from URL</h3>
      <button class="modal-close" onclick="closeModal('modalAddUrl')">✕</button>
    </div>
    <form method="POST">
      <div class="modal-body">
        <input type="hidden" name="action" value="add_m3u_url">
        <div class="form-group"><label>Playlist Name</label><input type="text" name="name" placeholder="My IPTV" required></div>
        <div class="form-group"><label>M3U URL</label><input type="url" name="m3u_url" placeholder="http://provider.com/list.m3u" required></div>
        <div class="form-group"><label>EPG URL (optional)</label><input type="url" name="epg_url" placeholder="http://epg-server.com/guide.xml"></div>
      </div>
      <div class="modal-foot">
        <button type="button" onclick="closeModal('modalAddUrl')" class="btn btn-g">Cancel</button>
        <button type="submit" class="btn btn-p">⬇ Import</button>
      </div>
    </form>
  </div>
</div>

<script>
console.log('%c⚡ XtreamTV IPTV OS v<?= APP_VERSION ?>', 'color:#00b4ff;font-size:14px;font-weight:bold;');
console.log('%c✦ Powered by Kobir Shah ✦ All Rights Reserved', 'color:#a855f7;font-size:11px;font-weight:600;');

function openModal(id)  { document.getElementById(id)?.classList.add('open'); }
function closeModal(id) { document.getElementById(id)?.classList.remove('open'); }

document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});

document.querySelectorAll('.alert').forEach(a => {
  setTimeout(() => { a.style.opacity='0'; a.style.transition='.5s'; setTimeout(() => a.remove(), 500); }, 5000);
});
</script>
</body>
</html>
