<?php
/**
 * ============================================================
 *  XtreamTV IPTV OS — Cinematic Player
 *  Phase 3: Premium Web UI — player.php
 * ============================================================
 *  Developer        : Kobir Shah
 *  DEVELOPER_CREDIT : Powered by Kobir Shah
 *
 *  Features:
 *    - HLS.js + Video.js full-screen cinematic player
 *    - Live EPG overlay (current & next program)
 *    - EPG progress bar
 *    - Channel sidebar with group filter + search
 *    - Keyboard shortcuts (space, f, m, seek)
 *    - PiP (Picture-in-Picture) support
 *    - Auto-reconnect on stream error
 *    - Glassmorphism EPG card under player
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/epg.php';

$channelId = (int)($_GET['id'] ?? 0);
$channel   = null;
$epgCurrent = null;
$epgNext    = null;

if ($channelId) {
    $channel = Database::query(
        "SELECT c.* FROM channels c
         WHERE c.id = ? AND c.is_active = 1",
        [$channelId]
    )->fetch();

    if ($channel && !empty($channel['tvg_id'])) {
        $epgCurrent = EPGEngine::getCurrentProgram($channel['tvg_id']);
        $epgNext    = EPGEngine::getNextProgram($channel['tvg_id']);
    }
}

$groups = Database::query(
    "SELECT DISTINCT c.group_title
     FROM channels c
     WHERE c.is_active = 1 AND c.stream_type = 'live'
     ORDER BY c.group_title"
)->fetchAll(\PDO::FETCH_COLUMN);

$selectedGroup = trim($_GET['group'] ?? '');
$searchQ       = trim($_GET['q']     ?? '');

$chWhere = ["c.is_active = 1", "c.stream_type = 'live'"];
$chParams = [];
if ($selectedGroup) { $chWhere[] = "c.group_title = ?"; $chParams[] = $selectedGroup; }
if ($searchQ)       { $chWhere[] = "c.name LIKE ?";     $chParams[] = "%{$searchQ}%"; }

$channels = Database::query(
    "SELECT c.id, c.name, c.tvg_logo, c.tvg_id, c.group_title, c.stream_url
     FROM channels c
     WHERE " . implode(' AND ', $chWhere) . "
     ORDER BY c.group_title, c.sort_order, c.name LIMIT 300",
    $chParams
)->fetchAll();

$streamUrl = $channel
    ? APP_URL . '/xtreamtv/proxy.php?id=' . $channel['id']
    : '';

$epgProgress = $epgCurrent ? EPGEngine::programProgress($epgCurrent) : 0;

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title><?= $channel ? $e($channel['name']) . ' — ' : '' ?>XtreamTV Player | Kobir Shah</title>
<meta name="author" content="Kobir Shah">
<style>
/* ══════════════════════════════════════════════════════════
   XTREAMTV CINEMATIC PLAYER — by Kobir Shah
╔═════════════════════════════════════════════════════════ */
*, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
:root {
  --bg:         #020205;
  --bg-panel:   rgba(6,6,16,0.97);
  --bg-card:    rgba(10,10,24,0.92);
  --blue:       #00b4ff;
  --purple:     #a855f7;
  --cyan:       #06b6d4;
  --green:      #10b981;
  --red:        #ef4444;
  --amber:      #f59e0b;
  --text:       #e2e8f0;
  --muted:      #64748b;
  --dim:        #94a3b8;
  --border:     rgba(99,102,241,0.14);
  --sidebar-w:  290px;
  --ctrl-h:     56px;
}
html, body { height: 100%; background: var(--bg); color: var(--text); font-family: 'Segoe UI', system-ui, sans-serif; overflow: hidden; }

.app {
  display: grid;
  grid-template-columns: 1fr var(--sidebar-w);
  grid-template-rows: auto 1fr auto;
  height: 100vh;
  grid-template-areas:
    "topbar  topbar"
    "theater sidebar"
    "footer  footer";
}
.topbar   { grid-area: topbar; }
.theater  { grid-area: theater; overflow: hidden; position: relative; background: #000; }
.sidebar  { grid-area: sidebar; }
footer    { grid-area: footer; }

.topbar {
  height: 48px; display: flex; align-items: center; justify-content: space-between;
  padding: 0 20px; background: rgba(2,2,5,0.98); border-bottom: 1px solid var(--border);
  z-index: 50;
}
.tb-logo { font-size: 1.1rem; font-weight: 800; background: linear-gradient(90deg, var(--blue), var(--purple)); -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; }
.tb-channel { font-size: 0.82rem; color: var(--dim); display: flex; align-items: center; gap: 8px; }
.live-dot { width: 7px; height: 7px; border-radius: 50%; background: var(--red); box-shadow: 0 0 8px var(--red); animation: blink 1.2s ease-in-out infinite; }
@keyframes blink { 0%,100%{opacity:1}50%{opacity:0.3} }
.tb-right { display: flex; align-items: center; gap: 10px; }
.btn-sm { padding: 5px 12px; border-radius: 8px; font-size: 0.75rem; font-weight: 600; cursor: pointer; border: 1px solid var(--border); background: rgba(255,255,255,0.04); color: var(--dim); text-decoration: none; transition: .2s; }
.btn-sm:hover { background: rgba(0,180,255,0.08); color: var(--text); border-color: rgba(0,180,255,0.2); }
.btn-primary-sm { background: linear-gradient(135deg, var(--blue), #0070aa); color: #fff !important; border-color: rgba(0,180,255,0.3) !important; }

.theater { display: flex; flex-direction: column; background: #000; }

.video-wrap {
  flex: 1; position: relative; background: #000; overflow: hidden;
  display: flex; align-items: center; justify-content: center;
}
video { width: 100%; height: 100%; object-fit: contain; background: #000; }

.controls-overlay {
  position: absolute; inset: 0; display: flex; flex-direction: column;
  justify-content: flex-end; pointer-events: none;
  background: linear-gradient(transparent 50%, rgba(0,0,0,0.8) 100%);
  opacity: 0; transition: opacity .35s;
}
.video-wrap:hover .controls-overlay,
.video-wrap.paused .controls-overlay { opacity: 1; pointer-events: all; }
.controls-bar {
  padding: 12px 20px; display: flex; align-items: center; gap: 14px;
}
.ctrl-btn {
  background: none; border: none; color: #fff; cursor: pointer; font-size: 1.2rem;
  padding: 6px 8px; border-radius: 8px; transition: .15s; line-height: 1;
}
.ctrl-btn:hover { background: rgba(255,255,255,0.1); }
.ctrl-time { font-size: 0.78rem; color: rgba(255,255,255,0.7); min-width: 80px; font-family: monospace; }
.ctrl-spacer { flex: 1; }
.vol-slider { -webkit-appearance: none; appearance: none; height: 4px; border-radius: 2px; background: rgba(255,255,255,0.3); cursor: pointer; width: 80px; }
.vol-slider::-webkit-slider-thumb { -webkit-appearance: none; width: 12px; height: 12px; border-radius: 50%; background: var(--blue); }
.seek-bar-wrap { width: 100%; padding: 0 20px 4px; }
.seek-bar { -webkit-appearance: none; appearance: none; width: 100%; height: 3px; border-radius: 2px; background: rgba(255,255,255,0.2); cursor: pointer; }
.seek-bar::-webkit-slider-thumb { -webkit-appearance: none; width: 12px; height: 12px; border-radius: 50%; background: var(--blue); }

.buffering {
  position: absolute; top: 50%; left: 50%; transform: translate(-50%,-50%);
  width: 50px; height: 50px; border: 3px solid rgba(0,180,255,0.2);
  border-top-color: var(--blue); border-radius: 50%;
  animation: spin .8s linear infinite; display: none; pointer-events: none;
}
@keyframes spin { to { transform: translate(-50%,-50%) rotate(360deg); } }
.video-wrap.loading .buffering { display: block; }

.no-channel {
  position: absolute; inset: 0; display: flex; flex-direction: column;
  align-items: center; justify-content: center; gap: 16px;
  background: radial-gradient(ellipse at center, rgba(0,180,255,0.04) 0%, transparent 70%);
}
.no-channel-icon { font-size: 4rem; opacity: .4; }
.no-channel-title { font-size: 1.2rem; font-weight: 700; color: var(--dim); }
.no-channel-sub { font-size: 0.82rem; color: var(--muted); }

.epg-panel {
  background: rgba(5,5,14,0.95); border-top: 1px solid var(--border);
  padding: 14px 20px; flex-shrink: 0; min-height: 80px;
}
.epg-row { display: flex; align-items: flex-start; gap: 20px; }
.epg-now-badge {
  font-size: 0.62rem; font-weight: 700; letter-spacing: 1px; text-transform: uppercase;
  padding: 3px 8px; border-radius: 20px; white-space: nowrap; flex-shrink: 0;
  background: rgba(239,68,68,0.15); color: var(--red); border: 1px solid rgba(239,68,68,0.3);
  margin-top: 2px;
}
.epg-now-badge.next { background: rgba(99,102,241,0.12); color: #818cf8; border-color: rgba(99,102,241,0.25); }
.epg-content { flex: 1; min-width: 0; }
.epg-title { font-size: 0.92rem; font-weight: 700; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.epg-time  { font-size: 0.72rem; color: var(--muted); margin-top: 2px; }
.epg-desc  { font-size: 0.75rem; color: var(--dim); margin-top: 4px; line-height: 1.5; display: -webkit-box; -webkit-line-clamp: 2; -webkit-box-orient: vertical; overflow: hidden; }
.epg-divider { width: 1px; background: var(--border); flex-shrink: 0; align-self: stretch; margin: 2px 0; }
.epg-progress-wrap { margin-top: 8px; }
.epg-progress-bar { height: 3px; border-radius: 2px; background: rgba(255,255,255,0.1); overflow: hidden; }
.epg-progress-fill { height: 100%; border-radius: 2px; background: linear-gradient(90deg, var(--blue), var(--purple)); transition: width .5s ease; }
.epg-empty { color: var(--muted); font-size: 0.8rem; padding: 8px 0; }

.sidebar {
  display: flex; flex-direction: column;
  background: var(--bg-panel); border-left: 1px solid var(--border);
  overflow: hidden;
}
.sidebar-head {
  padding: 14px 16px; border-bottom: 1px solid var(--border); flex-shrink: 0;
}
.sidebar-title { font-size: 0.85rem; font-weight: 700; margin-bottom: 10px; }
.search-box {
  width: 100%; padding: 7px 12px; border-radius: 8px; font-size: 0.8rem;
  background: rgba(255,255,255,0.04); border: 1px solid var(--border);
  color: var(--text); outline: none; transition: .2s; font-family: inherit;
  margin-bottom: 8px;
}
.search-box:focus { border-color: var(--blue); background: rgba(255,255,255,0.06); }
.group-pills { display: flex; flex-wrap: nowrap; gap: 6px; overflow-x: auto; padding-bottom: 4px; }
.group-pills::-webkit-scrollbar { height: 3px; }
.group-pills::-webkit-scrollbar-thumb { background: rgba(99,102,241,0.3); border-radius: 2px; }
.pill {
  padding: 3px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 600;
  white-space: nowrap; cursor: pointer; border: 1px solid var(--border);
  background: rgba(255,255,255,0.04); color: var(--muted); text-decoration: none; transition: .15s;
}
.pill:hover, .pill.active { background: rgba(0,180,255,0.12); color: var(--blue); border-color: rgba(0,180,255,0.25); }

.ch-list { flex: 1; overflow-y: auto; }
.ch-list::-webkit-scrollbar { width: 4px; }
.ch-list::-webkit-scrollbar-thumb { background: rgba(99,102,241,0.3); border-radius: 2px; }

.ch-item {
  display: flex; align-items: center; gap: 10px; padding: 9px 16px;
  border-bottom: 1px solid rgba(99,102,241,0.05); cursor: pointer; text-decoration: none;
  transition: .15s; position: relative;
}
.ch-item:hover { background: rgba(0,180,255,0.05); }
.ch-item.active {
  background: linear-gradient(90deg, rgba(0,180,255,0.12), rgba(168,85,247,0.05));
  border-left: 3px solid var(--blue);
}
.ch-logo { width: 32px; height: 22px; object-fit: contain; border-radius: 4px; background: rgba(99,102,241,0.08); flex-shrink: 0; }
.ch-logo-placeholder { width: 32px; height: 22px; border-radius: 4px; background: rgba(99,102,241,0.08); display: flex; align-items: center; justify-content: center; font-size: 0.55rem; color: var(--muted); flex-shrink: 0; }
.ch-info { flex: 1; min-width: 0; }
.ch-name { font-size: 0.82rem; font-weight: 600; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; }
.ch-grp  { font-size: 0.68rem; color: var(--purple); margin-top: 1px; }
.ch-now  { font-size: 0.65rem; color: var(--muted); white-space: nowrap; overflow: hidden; text-overflow: ellipsis; margin-top: 2px; }

footer {
  padding: 10px 20px; border-top: 1px solid var(--border);
  display: flex; align-items: center; justify-content: space-between;
  font-size: 0.7rem; color: var(--muted);
  background: rgba(2,2,5,0.98); flex-shrink: 0;
}
.credit {
  background: linear-gradient(90deg, var(--blue), var(--purple));
  -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text; font-weight: 700;
}
.kbd { padding: 2px 6px; border-radius: 4px; background: rgba(255,255,255,0.06); border: 1px solid rgba(255,255,255,0.1); font-family: monospace; font-size: 0.65rem; color: var(--dim); }

.error-banner {
  display: none; position: absolute; top: 60px; left: 50%; transform: translateX(-50%);
  background: rgba(239,68,68,0.15); border: 1px solid rgba(239,68,68,0.3);
  border-radius: 10px; padding: 10px 20px; font-size: 0.82rem; color: #fca5a5;
  backdrop-filter: blur(12px); z-index: 20; pointer-events: none; white-space: nowrap;
}

.pip-badge {
  position: absolute; top: 12px; right: 12px;
  background: rgba(0,0,0,0.7); padding: 4px 10px; border-radius: 20px;
  font-size: 0.7rem; color: var(--blue); border: 1px solid rgba(0,180,255,0.2);
  display: none;
}

@media (max-width: 768px) {
  .app { grid-template-columns: 1fr; grid-template-areas: "topbar" "theater" "footer"; }
  .sidebar { display: none; }
}
</style>
</head>
<body>
<div class="app">

  <header class="topbar">
    <div style="display:flex;align-items:center;gap:16px;">
      <div class="tb-logo">⚡ XtreamTV</div>
      <?php if ($channel): ?>
      <div class="tb-channel">
        <div class="live-dot"></div>
        <span><?= $e($channel['name']) ?></span>
        <span style="color:var(--muted);font-size:0.72rem">• <?= $e($channel['group_title']) ?></span>
      </div>
      <?php endif; ?>
    </div>
    <div class="tb-right">
      <span style="font-size:0.7rem;color:var(--muted)">
        <kbd class="kbd">Space</kbd> Play/Pause &nbsp;
        <kbd class="kbd">F</kbd> Fullscreen &nbsp;
        <kbd class="kbd">M</kbd> Mute
      </span>
      <a href="index.php" class="btn-sm">⚡ Dashboard</a>
      <a href="index.php?action=pip" id="btnPiP" class="btn-sm" title="Picture-in-Picture">PiP</a>
    </div>
  </header>

  <section class="theater">

    <div class="video-wrap <?= !$channel ? '' : 'loading' ?>" id="videoWrap">

      <?php if (!$channel): ?>
      <div class="no-channel">
        <div class="no-channel-icon">📺</div>
        <div class="no-channel-title">Select a channel to start watching</div>
        <div class="no-channel-sub">Browse the channel list on the right</div>
      </div>
      <?php endif; ?>

      <video
        id="mainVideo"
        playsinline
        <?= !$channel ? 'style="display:none"' : '' ?>
      ></video>

      <?php if ($channel): ?>
      <div class="controls-overlay" id="controlsOverlay">
        <div class="seek-bar-wrap">
          <input type="range" class="seek-bar" id="seekBar" value="0" min="0" max="100" step="0.1">
        </div>
        <div class="controls-bar">
          <button class="ctrl-btn" id="btnPlay" title="Play/Pause (Space)">⏸</button>
          <button class="ctrl-btn" id="btnStop" title="Stop">⏹</button>
          <span class="ctrl-time" id="timeDisplay">LIVE</span>
          <div class="ctrl-spacer"></div>
          <button class="ctrl-btn" id="btnMute" title="Mute (M)">🔊</button>
          <input type="range" class="vol-slider" id="volSlider" min="0" max="1" step="0.05" value="1">
          <button class="ctrl-btn" id="btnPip" title="Picture-in-Picture">PiP</button>
          <button class="ctrl-btn" id="btnFullscreen" title="Fullscreen (F)">Fullscreen</button>
        </div>
      </div>
      <?php endif; ?>

      <div class="buffering" id="buffering"></div>
      <div class="error-banner" id="errorBanner">Stream error — retrying...</div>
      <div class="pip-badge" id="pipBadge">Playing in PiP</div>
    </div>

    <div class="epg-panel" id="epgPanel">
      <?php if ($channel): ?>
        <?php if ($epgCurrent): ?>
        <div class="epg-row">
          <div>
            <div class="epg-now-badge">NOW</div>
          </div>
          <div class="epg-content">
            <div class="epg-title"><?= $e($epgCurrent['title']) ?></div>
            <div class="epg-time">
              <?= date('H:i', (int)$epgCurrent['start_time']) ?> – <?= date('H:i', (int)$epgCurrent['stop_time']) ?>
              &nbsp;·&nbsp; <?= $e(EPGEngine::formatDuration((int)$epgCurrent['start_time'], (int)$epgCurrent['stop_time'])) ?>
              <?php if ($epgCurrent['category']): ?>
              &nbsp;·&nbsp; <span style="color:var(--purple)"><?= $e($epgCurrent['category']) ?></span>
              <?php endif; ?>
            </div>
            <?php if ($epgCurrent['description']): ?>
            <div class="epg-desc"><?= $e($epgCurrent['description']) ?></div>
            <?php endif; ?>
            <div class="epg-progress-wrap">
              <div class="epg-progress-bar">
                <div class="epg-progress-fill" id="epgProgressFill" style="width:<?= round($epgProgress, 1) ?>%"></div>
              </div>
            </div>
          </div>
          <?php if ($epgNext): ?>
          <div class="epg-divider"></div>
          <div>
            <div class="epg-now-badge next">NEXT</div>
          </div>
          <div class="epg-content">
            <div class="epg-title"><?= $e($epgNext['title']) ?></div>
            <div class="epg-time">
              <?= date('H:i', (int)$epgNext['start_time']) ?>
              &nbsp;·&nbsp; <?= $e(EPGEngine::formatDuration((int)$epgNext['start_time'], (int)$epgNext['stop_time'])) ?>
            </div>
          </div>
          <?php endif; ?>
        </div>
        <?php else: ?>
        <div class="epg-empty">No EPG data available for this channel.</div>
        <?php endif; ?>
      <?php else: ?>
        <div class="epg-empty">Select a channel to see program information.</div>
      <?php endif; ?>
    </div>

  </section>

  <aside class="sidebar">
    <div class="sidebar-head">
      <div class="sidebar-title">📺 Channels</div>

      <form method="GET" action="player.php">
        <?php if ($channelId): ?><input type="hidden" name="id" value="<?= $channelId ?>"><?php endif; ?>
        <input type="text" class="search-box" name="q" placeholder="Search channels..." value="<?= $e($searchQ) ?>" id="channelSearch">
      </form>

      <div class="group-pills">
        <a href="player.php?<?= $channelId ? 'id='.$channelId.'&' : '' ?>q=<?= urlencode($searchQ) ?>"
           class="pill <?= !$selectedGroup ? 'active' : '' ?>">All</a>
        <?php foreach ($groups as $g): ?>
        <a href="player.php?<?= $channelId ? 'id='.$channelId.'&' : '' ?>group=<?= urlencode($g) ?>&q=<?= urlencode($searchQ) ?>"
           class="pill <?= $selectedGroup === $g ? 'active' : '' ?>"><?= $e($g) ?></a>
        <?php endforeach; ?>
      </div>
    </div>

    <div class="ch-list" id="chList">
      <?php if (empty($channels)): ?>
      <div style="padding:32px 16px;text-align:center;color:var(--muted);font-size:0.82rem">
        <div style="font-size:2rem;margin-bottom:8px">📡</div>
        <div>No channels found</div>
      </div>
      <?php else: ?>
      <?php foreach ($channels as $ch): ?>
      <?php
        $isActive = $channelId === (int)$ch['id'];
        $href = 'player.php?id=' . $ch['id'] . ($selectedGroup ? '&group=' . urlencode($selectedGroup) : '') . ($searchQ ? '&q=' . urlencode($searchQ) : '');
      ?>
      <a href="<?= $e($href) ?>" class="ch-item <?= $isActive ? 'active' : '' ?>"
         data-channel-id="<?= $ch['id'] ?>"
         data-stream-url="<?= $e(APP_URL . '/xtreamtv/proxy.php?id=' . $ch['id']) ?>"
         data-tvg-id="<?= $e($ch['tvg_id'] ?? '') ?>"
         data-name="<?= $e($ch['name']) ?>">

        <?php if ($ch['tvg_logo']): ?>
        <img src="<?= $e($ch['tvg_logo']) ?>" class="ch-logo" alt="<?= $e($ch['name']) ?>" loading="lazy"
             onerror="this.outerHTML='<div class=\'ch-logo-placeholder\'>TV</div>'">
        <?php else: ?>
        <div class="ch-logo-placeholder">TV</div>
        <?php endif; ?>

        <div class="ch-info">
          <div class="ch-name"><?= $e($ch['name']) ?></div>
          <div class="ch-grp"><?= $e($ch['group_title']) ?></div>
          <div class="ch-now" id="epg-<?= $ch['id'] ?>">Loading EPG...</div>
        </div>
      </a>
      <?php endforeach; ?>
      <?php endif; ?>
    </div>
  </aside>

  <footer>
    <span>© <?= date('Y') ?> <span class="credit">Kobir Shah</span> — XtreamTV v<?= APP_VERSION ?> | IPTV OS</span>
    <span style="color:var(--muted)"><?= DEVELOPER_CREDIT ?></span>
    <span style="font-size:0.65rem;color:var(--muted)">
      <?= count($channels) ?> channels loaded &nbsp;·&nbsp;
      <span class="credit">Designed &amp; Developed by Kobir Shah</span>
    </span>
  </footer>

</div>

<script>
console.log('%c⚡ XtreamTV Cinematic Player v<?= APP_VERSION ?>', 'color:#00b4ff;font-size:13px;font-weight:bold;');
console.log('%c✦ Developed by Kobir Shah ✦', 'color:#a855f7;font-size:11px;');

const state = {
  currentChannelId: <?= $channelId ?: 'null' ?>,
  currentStreamUrl: <?= json_encode($streamUrl) ?: 'null' ?>,
  hls: null,
  reconnectTimer: null,
  reconnectAttempts: 0,
  maxReconnectAttempts: 5,
  epgTvgId: <?= json_encode($channel['tvg_id'] ?? null) ?: 'null' ?>,
};

const video       = document.getElementById('mainVideo');
const videoWrap   = document.getElementById('videoWrap');
const buffering   = document.getElementById('buffering');
const errorBanner = document.getElementById('errorBanner');
const btnPlay     = document.getElementById('btnPlay');
const btnStop     = document.getElementById('btnStop');
const btnMute     = document.getElementById('btnMute');
const volSlider   = document.getElementById('volSlider');
const btnFullscreen = document.getElementById('btnFullscreen');
const btnPip      = document.getElementById('btnPip');
const seekBar     = document.getElementById('seekBar');
const timeDisplay = document.getElementById('timeDisplay');
const pipBadge    = document.getElementById('pipBadge');

function loadHLSjs(callback) {
  if (window.Hls) { callback(); return; }
  const script = document.createElement('script');
  script.src = 'https://cdn.jsdelivr.net/npm/hls.js@1.5.15/dist/hls.min.js';
  script.onload  = callback;
  script.onerror = () => { console.warn('HLS.js CDN failed, using native playback'); callback(); };
  document.head.appendChild(script);
}

function initPlayer(url) {
  if (!url || !video) return;

  if (state.hls) { state.hls.destroy(); state.hls = null; }
  clearTimeout(state.reconnectTimer);

  video.style.display = 'block';
  videoWrap.classList.add('loading');
  errorBanner.style.display = 'none';

  loadHLSjs(() => {
    if (window.Hls && Hls.isSupported()) {
      state.hls = new Hls({
        enableWorker: true,
        lowLatencyMode: true,
        backBufferLength: 30,
        maxBufferLength: 60,
      });
      state.hls.loadSource(url);
      state.hls.attachMedia(video);

      state.hls.on(Hls.Events.MANIFEST_PARSED, () => {
        video.play().catch(() => {});
        videoWrap.classList.remove('loading');
        state.reconnectAttempts = 0;
      });

      state.hls.on(Hls.Events.ERROR, (_, data) => {
        if (data.fatal) {
          showError();
          autoReconnect(url);
        }
      });
    } else if (video.canPlayType('application/vnd.apple.mpegurl')) {
      video.src = url;
      video.play().catch(() => {});
      videoWrap.classList.remove('loading');
    } else {
      video.src = url;
      video.play().catch(() => {});
      videoWrap.classList.remove('loading');
    }
  });
}

function autoReconnect(url) {
  if (state.reconnectAttempts >= state.maxReconnectAttempts) {
    showError('Stream unavailable after ' + state.maxReconnectAttempts + ' attempts.');
    return;
  }
  state.reconnectAttempts++;
  const delay = Math.min(5000 * state.reconnectAttempts, 30000);
  console.log('[XtreamTV] Reconnecting in ' + (delay/1000) + 's (attempt ' + state.reconnectAttempts + ')');
  state.reconnectTimer = setTimeout(() => initPlayer(url), delay);
}

function showError(msg) {
  errorBanner.textContent = (msg || 'Stream error — retrying...');
  errorBanner.style.display = 'block';
  setTimeout(() => { errorBanner.style.display = 'none'; }, 4000);
}

function switchChannel(channelId, streamUrl, tvgId, name) {
  const newUrl = new URL(window.location.href);
  newUrl.searchParams.set('id', channelId);
  history.pushState({channelId, streamUrl, tvgId, name}, name, newUrl.toString());

  document.querySelectorAll('.ch-item').forEach(el => {
    el.classList.toggle('active', parseInt(el.dataset.channelId) === channelId);
  });

  const tbChannel = document.querySelector('.tb-channel span');
  if (tbChannel) tbChannel.textContent = name;

  state.currentChannelId = channelId;
  state.currentStreamUrl = streamUrl;
  state.epgTvgId = tvgId;

  initPlayer(streamUrl);

  if (tvgId) fetchEPG(tvgId, true);
}

document.querySelectorAll('.ch-item').forEach(el => {
  el.addEventListener('click', e => {
    e.preventDefault();
    const id       = parseInt(el.dataset.channelId);
    const url      = el.dataset.streamUrl;
    const tvgId    = el.dataset.tvgId || '';
    const name     = el.dataset.name || '';
    switchChannel(id, url, tvgId, name);
  });
});

if (video) {
  function togglePlay() {
    if (video.paused) { video.play(); btnPlay.textContent = '⏸'; videoWrap.classList.remove('paused'); }
    else              { video.pause(); btnPlay.textContent = '▶'; videoWrap.classList.add('paused'); }
  }
  btnPlay?.addEventListener('click', togglePlay);
  video.addEventListener('click', togglePlay);

  btnStop?.addEventListener('click', () => {
    if (state.hls) state.hls.destroy();
    video.src = '';
    video.style.display = 'none';
    videoWrap.classList.remove('loading');
  });

  btnMute?.addEventListener('click', () => {
    video.muted = !video.muted;
    btnMute.textContent = video.muted ? '🔇' : '🔊';
  });

  volSlider?.addEventListener('input', () => {
    video.volume = parseFloat(volSlider.value);
    video.muted = (video.volume === 0);
    btnMute.textContent = video.muted ? '🔇' : '🔊';
  });

  seekBar?.addEventListener('input', () => {
    if (video.duration && isFinite(video.duration)) {
      video.currentTime = (parseFloat(seekBar.value) / 100) * video.duration;
    }
  });

  video.addEventListener('timeupdate', () => {
    if (video.duration && isFinite(video.duration)) {
      seekBar.value = (video.currentTime / video.duration) * 100;
      const cur = formatTime(video.currentTime);
      const dur = formatTime(video.duration);
      timeDisplay.textContent = cur + ' / ' + dur;
    } else {
      timeDisplay.textContent = 'LIVE';
    }
  });

  video.addEventListener('waiting',  () => videoWrap.classList.add('loading'));
  video.addEventListener('playing',  () => { videoWrap.classList.remove('loading'); videoWrap.classList.remove('paused'); });
  video.addEventListener('pause',    () => videoWrap.classList.add('paused'));

  btnFullscreen?.addEventListener('click', toggleFullscreen);
  function toggleFullscreen() {
    if (!document.fullscreenElement) {
      videoWrap.requestFullscreen?.() || videoWrap.webkitRequestFullscreen?.();
      btnFullscreen.textContent = '⛶';
    } else {
      document.exitFullscreen?.();
      btnFullscreen.textContent = '⛶';
    }
  }

  btnPip?.addEventListener('click', async () => {
    if (!video.src && !state.currentStreamUrl) return;
    try {
      if (document.pictureInPictureElement) {
        await document.exitPictureInPicture();
        pipBadge.style.display = 'none';
      } else {
        await video.requestPictureInPicture();
        pipBadge.style.display = 'block';
      }
    } catch(e) { console.warn('PiP not supported:', e); }
  });
  video.addEventListener('leavepictureinpicture', () => { pipBadge.style.display = 'none'; });
}

document.addEventListener('keydown', e => {
  if (e.target.tagName === 'INPUT') return;
  switch (e.code) {
    case 'Space':     e.preventDefault(); togglePlay?.(); break;
    case 'KeyF':      toggleFullscreen?.(); break;
    case 'KeyM':      if (video) video.muted = !video.muted; break;
    case 'ArrowRight': if (video && isFinite(video.duration)) video.currentTime += 10; break;
    case 'ArrowLeft':  if (video && isFinite(video.duration)) video.currentTime -= 10; break;
    case 'ArrowUp':    if (video) video.volume = Math.min(1, video.volume + 0.1); break;
    case 'ArrowDown':  if (video) video.volume = Math.max(0, video.volume - 0.1); break;
  }
});

function fetchEPG(tvgId, updatePanel = false) {
  if (!tvgId) return;
  fetch('epg_api.php?tvg_id=' + encodeURIComponent(tvgId))
    .then(r => r.json())
    .then(data => {
      if (updatePanel) updateEPGPanel(data);
      updateSidebarEPG(state.currentChannelId, data.current?.title);
    })
    .catch(() => {});
}

function updateEPGPanel(data) {
  const panel = document.getElementById('epgPanel');
  if (!panel || !data) return;
  if (!data.current) {
    panel.innerHTML = '<div class="epg-empty">No EPG data available for this channel.</div>';
    return;
  }
  const c = data.current, n = data.next;
  panel.innerHTML = `
    <div class="epg-row">
      <div><div class="epg-now-badge">NOW</div></div>
      <div class="epg-content">
        <div class="epg-title">${esc(c.title)}</div>
        <div class="epg-time">${c.start} – ${c.stop} · ${c.duration}</div>
        ${c.description ? '<div class="epg-desc">' + esc(c.description) + '</div>' : ''}
        <div class="epg-progress-wrap">
          <div class="epg-progress-bar">
            <div class="epg-progress-fill" style="width:' + c.progress + '%"></div>
          </div>
        </div>
      </div>
      ${n ? `
      <div class="epg-divider"></div>
      <div><div class="epg-now-badge next">NEXT</div></div>
      <div class="epg-content">
        <div class="epg-title">${esc(n.title)}</div>
        <div class="epg-time">${n.start} · ${n.duration}</div>
      </div>` : ''}
    </div>`;
}

function updateSidebarEPG(channelId, title) {
  const el = document.getElementById('epg-' + channelId);
  if (el && title) el.textContent = title;
}

function loadSidebarEPGs() {
  const items = document.querySelectorAll('.ch-item[data-tvg-id]');
  const tvgIds = [...new Set([...items].map(el => el.dataset.tvgId).filter(Boolean))].slice(0, 30);
  if (!tvgIds.length) return;

  fetch('epg_api.php?action=batch&tvg_ids=' + tvgIds.join(','))
    .then(r => r.json())
    .then(data => {
      if (!data.data) return;
      document.querySelectorAll('.ch-item').forEach(el => {
        const tvgId = el.dataset.tvgId;
        const channelId = el.dataset.channelId;
        if (tvgId && data.data[tvgId]?.current) {
          const el2 = document.getElementById('epg-' + channelId);
          if (el2) el2.textContent = data.data[tvgId].current.title;
        }
      });
    })
    .catch(() => {
      document.querySelectorAll('.ch-now').forEach(el => {
        if (el.textContent === 'Loading EPG...') el.textContent = '';
      });
    });
}

function formatTime(sec) {
  const h = Math.floor(sec / 3600);
  const m = Math.floor((sec % 3600) / 60);
  const s = Math.floor(sec % 60);
  return h > 0
    ? h + ':' + String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0')
    : String(m).padStart(2,'0') + ':' + String(s).padStart(2,'0');
}
function esc(str) {
  const div = document.createElement('div');
  div.appendChild(document.createTextNode(str || ''));
  return div.innerHTML;
}

document.getElementById('channelSearch')?.addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('.ch-item').forEach(el => {
    const name = (el.dataset.name || '').toLowerCase();
    el.style.display = name.includes(q) ? '' : 'none';
  });
});

if (state.currentStreamUrl && video) {
  initPlayer(state.currentStreamUrl);
}

setTimeout(loadSidebarEPGs, 1000);

setInterval(() => {
  if (state.epgTvgId) fetchEPG(state.epgTvgId, true);
}, 120000);

setInterval(() => {
  const fill = document.getElementById('epgProgressFill');
  if (fill && state.epgTvgId) fetchEPG(state.epgTvgId, false);
}, 30000);

console.log('%cXtreamTV Player initialized', 'color:#10b981;font-size:10px;');
</script>
</body>
</html>
