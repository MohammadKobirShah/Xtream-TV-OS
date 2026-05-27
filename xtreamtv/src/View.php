<?php
/**
 * ============================================================
 *  XtreamTV — View / Layout Engine
 *  Developer: Kobir Shah
 * ============================================================
 */

declare(strict_types=1);

class View
{
    /** Render the full page shell with glassmorphism dark UI */
    public static function layout(string $title, string $body, string $activeNav = ''): string
    {
        $csrf     = Security::csrfToken();
        $username = Security::e($_SESSION['username'] ?? 'Guest');
        $role     = Security::e($_SESSION['role'] ?? '');
        $appName  = APP_NAME;
        $version  = APP_VERSION;
        $author   = APP_AUTHOR;
        $year     = date('Y');

        $navItems = [
            ['href' => '/xtreamtv/index.php',      'icon' => '⚡', 'label' => 'Dashboard', 'key' => 'dashboard'],
            ['href' => '/xtreamtv/playlists.php',   'icon' => '📡', 'label' => 'Playlists',  'key' => 'playlists'],
            ['href' => '/xtreamtv/channels.php',    'icon' => '📺', 'label' => 'Channels',   'key' => 'channels'],
            ['href' => '/xtreamtv/users.php',       'icon' => '👥', 'label' => 'Users',      'key' => 'users',  'admin' => true],
            ['href' => '/xtreamtv/api_info.php',    'icon' => '🔌', 'label' => 'API Info',   'key' => 'api'],
            ['href' => '/xtreamtv/logs.php',        'icon' => '📋', 'label' => 'Logs',       'key' => 'logs',   'admin' => true],
            ['href' => '/xtreamtv/player.php',     'icon' => '📺', 'label' => 'Live TV',    'key' => 'player'],
            ['href' => '/xtreamtv/settings.php',   'icon' => '⚙️', 'label' => 'Settings',   'key' => 'settings','admin' => true],
        ];

        $navHtml = '';
        foreach ($navItems as $item) {
            if (!empty($item['admin']) && !Security::isAdmin()) continue;
            $active = ($activeNav === $item['key']) ? 'nav-active' : '';
            $navHtml .= <<<HTML
            <a href="{$item['href']}" class="nav-item {$active}">
                <span class="nav-icon">{$item['icon']}</span>
                <span class="nav-label">{$item['label']}</span>
            </a>
            HTML;
        }

        return <<<HTML
<!DOCTYPE html>
<html lang="en" data-theme="dark">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>{$title} — {$appName}</title>
<meta name="author" content="{$author}">
<style>
  /* ══════════════════════════════════════════════
     RESET & BASE
  ══════════════════════════════════════════════ */
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg-base:       #050508;
    --bg-surface:    #0a0a14;
    --bg-card:       rgba(15,15,30,0.85);
    --bg-card-hover: rgba(20,20,42,0.95);
    --neon-blue:     #00b4ff;
    --neon-purple:   #a855f7;
    --neon-cyan:     #06b6d4;
    --neon-green:    #10b981;
    --neon-red:      #ef4444;
    --neon-amber:    #f59e0b;
    --text-primary:  #e2e8f0;
    --text-muted:    #64748b;
    --text-dim:      #94a3b8;
    --border:        rgba(99,102,241,0.15);
    --border-glow:   rgba(0,180,255,0.3);
    --glass-blur:    blur(20px);
    --shadow-neon:   0 0 30px rgba(0,180,255,0.08), 0 8px 32px rgba(0,0,0,0.6);
    --radius:        12px;
    --radius-lg:     20px;
    --nav-width:     240px;
    --transition:    all 0.25s cubic-bezier(0.4,0,0.2,1);
  }
  html { scroll-behavior: smooth; }
  body {
    font-family: 'Segoe UI', system-ui, -apple-system, sans-serif;
    background: var(--bg-base);
    color: var(--text-primary);
    min-height: 100vh;
    display: flex;
    line-height: 1.6;
    overflow-x: hidden;
  }
  /* ── Scrollbar ── */
  ::-webkit-scrollbar { width: 6px; height: 6px; }
  ::-webkit-scrollbar-track { background: var(--bg-base); }
  ::-webkit-scrollbar-thumb { background: rgba(99,102,241,0.4); border-radius: 3px; }

  /* ══════════════════════════════════════════════
     ANIMATED BACKGROUND GRID
  ══════════════════════════════════════════════ */
  body::before {
    content: '';
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background-image:
      linear-gradient(rgba(0,180,255,0.03) 1px, transparent 1px),
      linear-gradient(90deg, rgba(0,180,255,0.03) 1px, transparent 1px);
    background-size: 40px 40px;
  }
  body::after {
    content: '';
    position: fixed; inset: 0; z-index: 0; pointer-events: none;
    background:
      radial-gradient(ellipse 60% 50% at 20% 20%, rgba(168,85,247,0.06) 0%, transparent 60%),
      radial-gradient(ellipse 50% 60% at 80% 80%, rgba(0,180,255,0.05) 0%, transparent 60%);
  }

  /* ══════════════════════════════════════════════
     SIDEBAR NAV
  ══════════════════════════════════════════════ */
  .sidebar {
    position: fixed; top: 0; left: 0; bottom: 0;
    width: var(--nav-width);
    background: rgba(8,8,20,0.95);
    backdrop-filter: var(--glass-blur);
    border-right: 1px solid var(--border);
    display: flex; flex-direction: column;
    z-index: 100;
    box-shadow: 4px 0 40px rgba(0,0,0,0.5);
  }
  .sidebar-logo {
    padding: 24px 20px 20px;
    border-bottom: 1px solid var(--border);
  }
  .logo-text {
    font-size: 1.6rem; font-weight: 800; letter-spacing: -0.5px;
    background: linear-gradient(135deg, var(--neon-blue), var(--neon-purple));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text;
  }
  .logo-sub { font-size: 0.7rem; color: var(--text-muted); letter-spacing: 2px; text-transform: uppercase; margin-top: 2px; }
  .nav-menu { padding: 16px 12px; flex: 1; overflow-y: auto; }
  .nav-section { font-size: 0.65rem; color: var(--text-muted); letter-spacing: 2px; text-transform: uppercase; padding: 8px 8px 4px; margin-top: 8px; }
  .nav-item {
    display: flex; align-items: center; gap: 10px;
    padding: 10px 12px; border-radius: 10px; margin-bottom: 2px;
    color: var(--text-dim); text-decoration: none; font-size: 0.875rem; font-weight: 500;
    transition: var(--transition); position: relative; overflow: hidden;
  }
  .nav-item:hover {
    background: rgba(0,180,255,0.08); color: var(--text-primary);
    transform: translateX(3px);
  }
  .nav-item.nav-active {
    background: linear-gradient(90deg, rgba(0,180,255,0.15), rgba(168,85,247,0.08));
    color: var(--neon-blue);
    border-left: 3px solid var(--neon-blue);
    box-shadow: inset 0 0 20px rgba(0,180,255,0.05);
  }
  .nav-icon { font-size: 1.1rem; width: 20px; text-align: center; }
  .sidebar-user {
    padding: 16px; border-top: 1px solid var(--border);
    display: flex; align-items: center; gap: 10px;
  }
  .user-avatar {
    width: 36px; height: 36px; border-radius: 50%;
    background: linear-gradient(135deg, var(--neon-blue), var(--neon-purple));
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 0.8rem; flex-shrink: 0;
  }
  .user-info { flex: 1; min-width: 0; }
  .user-name { font-size: 0.85rem; font-weight: 600; truncate; }
  .user-role { font-size: 0.7rem; color: var(--text-muted); }
  .btn-logout {
    padding: 6px 10px; border-radius: 8px; font-size: 0.75rem;
    background: rgba(239,68,68,0.1); color: var(--neon-red);
    border: 1px solid rgba(239,68,68,0.2); cursor: pointer; text-decoration: none;
    transition: var(--transition);
  }
  .btn-logout:hover { background: rgba(239,68,68,0.2); }

  /* ══════════════════════════════════════════════
     MAIN CONTENT
  ══════════════════════════════════════════════ */
  .main {
    margin-left: var(--nav-width);
    flex: 1; display: flex; flex-direction: column;
    min-height: 100vh; position: relative; z-index: 1;
  }
  .topbar {
    position: sticky; top: 0; z-index: 50;
    background: rgba(5,5,8,0.9); backdrop-filter: var(--glass-blur);
    border-bottom: 1px solid var(--border);
    padding: 0 32px; height: 60px;
    display: flex; align-items: center; justify-content: space-between;
  }
  .topbar-title { font-size: 1.1rem; font-weight: 700; color: var(--text-primary); }
  .topbar-right { display: flex; align-items: center; gap: 12px; }
  .badge {
    padding: 3px 10px; border-radius: 20px; font-size: 0.7rem; font-weight: 600;
    letter-spacing: 0.5px; text-transform: uppercase;
  }
  .badge-admin { background: rgba(168,85,247,0.15); color: var(--neon-purple); border: 1px solid rgba(168,85,247,0.3); }
  .badge-user  { background: rgba(0,180,255,0.1);   color: var(--neon-blue);   border: 1px solid rgba(0,180,255,0.2); }
  .content { padding: 32px; flex: 1; }

  /* ══════════════════════════════════════════════
     GLASS CARD
  ══════════════════════════════════════════════ */
  .glass {
    background: var(--bg-card);
    backdrop-filter: var(--glass-blur);
    border: 1px solid var(--border);
    border-radius: var(--radius-lg);
    box-shadow: var(--shadow-neon);
    transition: var(--transition);
  }
  .glass:hover { border-color: var(--border-glow); }
  .glass-header {
    padding: 20px 24px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
  }
  .glass-title { font-size: 1rem; font-weight: 700; display: flex; align-items: center; gap: 8px; }
  .glass-body { padding: 24px; }

  /* ══════════════════════════════════════════════
     STAT CARDS
  ══════════════════════════════════════════════ */
  .stats-grid { display: grid; grid-template-columns: repeat(auto-fit, minmax(200px, 1fr)); gap: 16px; margin-bottom: 28px; }
  .stat-card {
    padding: 20px 24px; border-radius: var(--radius-lg);
    border: 1px solid var(--border); background: var(--bg-card);
    backdrop-filter: var(--glass-blur); position: relative; overflow: hidden;
    transition: var(--transition);
  }
  .stat-card::before {
    content: ''; position: absolute; top: 0; left: 0; right: 0; height: 2px;
    background: var(--accent, var(--neon-blue));
  }
  .stat-card:hover { transform: translateY(-2px); border-color: var(--border-glow); }
  .stat-icon { font-size: 1.8rem; margin-bottom: 8px; }
  .stat-value { font-size: 2rem; font-weight: 800; color: var(--accent, var(--neon-blue)); line-height: 1; }
  .stat-label { font-size: 0.78rem; color: var(--text-muted); margin-top: 4px; text-transform: uppercase; letter-spacing: 1px; }

  /* ══════════════════════════════════════════════
     TABLE
  ══════════════════════════════════════════════ */
  .table-wrap { overflow-x: auto; }
  table { width: 100%; border-collapse: collapse; font-size: 0.875rem; }
  thead tr { border-bottom: 1px solid var(--border); }
  th { padding: 12px 16px; text-align: left; font-size: 0.72rem; font-weight: 600;
       color: var(--text-muted); text-transform: uppercase; letter-spacing: 1px; }
  td { padding: 12px 16px; border-bottom: 1px solid rgba(99,102,241,0.06); vertical-align: middle; }
  tr:last-child td { border-bottom: none; }
  tbody tr { transition: var(--transition); }
  tbody tr:hover { background: rgba(0,180,255,0.04); }

  /* ══════════════════════════════════════════════
     BUTTONS
  ══════════════════════════════════════════════ */
  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border-radius: 10px; font-size: 0.85rem; font-weight: 600;
    cursor: pointer; border: 1px solid transparent; text-decoration: none;
    transition: var(--transition); white-space: nowrap;
  }
  .btn-primary {
    background: linear-gradient(135deg, var(--neon-blue), #0080cc);
    color: #fff; border-color: rgba(0,180,255,0.4);
    box-shadow: 0 4px 20px rgba(0,180,255,0.2);
  }
  .btn-primary:hover { transform: translateY(-1px); box-shadow: 0 6px 28px rgba(0,180,255,0.35); }
  .btn-purple {
    background: linear-gradient(135deg, var(--neon-purple), #7c3aed);
    color: #fff; border-color: rgba(168,85,247,0.4);
    box-shadow: 0 4px 20px rgba(168,85,247,0.2);
  }
  .btn-purple:hover { transform: translateY(-1px); box-shadow: 0 6px 28px rgba(168,85,247,0.35); }
  .btn-ghost {
    background: rgba(255,255,255,0.04); color: var(--text-dim);
    border-color: var(--border);
  }
  .btn-ghost:hover { background: rgba(255,255,255,0.08); color: var(--text-primary); }
  .btn-danger {
    background: rgba(239,68,68,0.1); color: var(--neon-red);
    border-color: rgba(239,68,68,0.25);
  }
  .btn-danger:hover { background: rgba(239,68,68,0.2); }
  .btn-sm { padding: 5px 12px; font-size: 0.78rem; border-radius: 8px; }
  .btn-success {
    background: rgba(16,185,129,0.1); color: var(--neon-green);
    border-color: rgba(16,185,129,0.25);
  }

  /* ══════════════════════════════════════════════
     FORMS
  ══════════════════════════════════════════════ */
  .form-group { margin-bottom: 18px; }
  label { display: block; font-size: 0.8rem; font-weight: 600; color: var(--text-dim); margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
  input[type=text], input[type=password], input[type=email], input[type=number],
  input[type=url], select, textarea {
    width: 100%; padding: 10px 14px; border-radius: 10px; font-size: 0.9rem;
    background: rgba(255,255,255,0.04); border: 1px solid var(--border);
    color: var(--text-primary); outline: none; transition: var(--transition);
    font-family: inherit;
  }
  input:focus, select:focus, textarea:focus {
    border-color: var(--neon-blue); box-shadow: 0 0 0 3px rgba(0,180,255,0.1);
    background: rgba(255,255,255,0.06);
  }
  select option { background: #0f0f1e; }
  .form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; }

  /* ══════════════════════════════════════════════
     ALERTS
  ══════════════════════════════════════════════ */
  .alert {
    padding: 12px 16px; border-radius: 10px; font-size: 0.875rem;
    margin-bottom: 20px; display: flex; align-items: center; gap: 10px; border: 1px solid;
  }
  .alert-success { background: rgba(16,185,129,0.1); color: #6ee7b7; border-color: rgba(16,185,129,0.25); }
  .alert-error   { background: rgba(239,68,68,0.1);  color: #fca5a5; border-color: rgba(239,68,68,0.25); }
  .alert-info    { background: rgba(0,180,255,0.08); color: #93c5fd; border-color: rgba(0,180,255,0.2); }
  .alert-warn    { background: rgba(245,158,11,0.1); color: #fcd34d; border-color: rgba(245,158,11,0.25); }

  /* ══════════════════════════════════════════════
     MISC
  ══════════════════════════════════════════════ */
  .tag {
    display: inline-block; padding: 2px 8px; border-radius: 20px;
    font-size: 0.7rem; font-weight: 600;
  }
  .tag-active   { background: rgba(16,185,129,0.15); color: var(--neon-green); }
  .tag-inactive { background: rgba(239,68,68,0.1);   color: var(--neon-red); }
  .tag-admin    { background: rgba(168,85,247,0.15); color: var(--neon-purple); }
  .tag-user     { background: rgba(0,180,255,0.1);   color: var(--neon-blue); }
  .mono { font-family: 'Courier New', monospace; font-size: 0.82rem; }
  .text-muted  { color: var(--text-muted); }
  .text-glow   { color: var(--neon-blue); text-shadow: 0 0 20px rgba(0,180,255,0.5); }
  .flex { display: flex; } .items-center { align-items: center; }
  .justify-between { justify-content: space-between; } .gap-2 { gap: 8px; } .gap-3 { gap: 12px; }
  .mb-4 { margin-bottom: 16px; } .mb-6 { margin-bottom: 24px; } .mt-4 { margin-top: 16px; }
  .p-4 { padding: 16px; }
  .grid-2 { display: grid; grid-template-columns: 1fr 1fr; gap: 20px; }
  .grid-3 { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 20px; }
  .pulse-dot {
    width: 8px; height: 8px; border-radius: 50%; display: inline-block;
    background: var(--neon-green); box-shadow: 0 0 8px var(--neon-green);
    animation: pulse 2s infinite;
  }
  @keyframes pulse { 0%,100%{opacity:1;transform:scale(1)} 50%{opacity:.5;transform:scale(.85)} }

  /* ── Footer ── */
  .footer {
    padding: 16px 32px; border-top: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    font-size: 0.75rem; color: var(--text-muted);
  }
  .footer-credit {
    background: linear-gradient(90deg, var(--neon-blue), var(--neon-purple));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text; font-weight: 700;
  }

  /* ── Modal ── */
  .modal-overlay {
    display: none; position: fixed; inset: 0; z-index: 200;
    background: rgba(0,0,0,0.7); backdrop-filter: blur(6px);
    align-items: center; justify-content: center;
  }
  .modal-overlay.open { display: flex; }
  .modal {
    background: #0d0d1f; border: 1px solid var(--border);
    border-radius: var(--radius-lg); width: min(540px, 95vw);
    box-shadow: 0 25px 80px rgba(0,0,0,0.8), 0 0 60px rgba(0,180,255,0.06);
    overflow: hidden;
  }
  .modal-head {
    padding: 20px 24px; border-bottom: 1px solid var(--border);
    display: flex; align-items: center; justify-content: space-between;
    background: linear-gradient(90deg, rgba(0,180,255,0.05), rgba(168,85,247,0.05));
  }
  .modal-head h3 { font-size: 1rem; font-weight: 700; }
  .modal-close { background: none; border: none; color: var(--text-muted); cursor: pointer; font-size: 1.3rem; padding: 2px 6px; border-radius: 6px; }
  .modal-close:hover { background: rgba(255,255,255,0.08); color: var(--text-primary); }
  .modal-body { padding: 24px; max-height: 70vh; overflow-y: auto; }
  .modal-foot { padding: 16px 24px; border-top: 1px solid var(--border); display: flex; justify-content: flex-end; gap: 10px; }

  /* ── Copy btn ── */
  .copy-btn { cursor: pointer; }
  .copy-btn:active { transform: scale(0.95); }

  /* ── Neon glow accents ── */
  .neon-blue   { color: var(--neon-blue);   }
  .neon-purple { color: var(--neon-purple); }
  .neon-green  { color: var(--neon-green);  }
  .neon-red    { color: var(--neon-red);    }
  .neon-amber  { color: var(--neon-amber);  }

  /* ── Responsive ── */
  @media (max-width: 768px) {
    .sidebar { width: 60px; }
    .nav-label, .logo-sub, .user-info, .btn-logout { display: none; }
    .logo-text { font-size: 1rem; }
    .main { margin-left: 60px; }
    .content { padding: 16px; }
    .form-row, .grid-2, .grid-3 { grid-template-columns: 1fr; }
    .stats-grid { grid-template-columns: 1fr 1fr; }
  }
</style>
</head>
<body>

<!-- ═══ SIDEBAR ═══ -->
<aside class="sidebar">
  <div class="sidebar-logo">
    <div class="logo-text">⚡ {$appName}</div>
    <div class="logo-sub">IPTV Proxy OS</div>
  </div>
  <nav class="nav-menu">
    <div class="nav-section">Navigation</div>
    {$navHtml}
  </nav>
  <div class="sidebar-user">
    <div class="user-avatar">{$username[0]}</div>
    <div class="user-info">
      <div class="user-name">{$username}</div>
      <div class="user-role">{$role}</div>
    </div>
    <a href="/xtreamtv/logout.php" class="btn-logout">✕</a>
  </div>
</aside>

<!-- ═══ MAIN ═══ -->
<main class="main">
  <div class="topbar">
    <div class="topbar-title">{$title}</div>
    <div class="topbar-right">
      <span class="badge badge-{$role}">{$role}</span>
      <span style="font-size:0.75rem;color:var(--text-muted);">{$appName} v{$version}</span>
    </div>
  </div>

  <div class="content">
    {$body}
  </div>

  <footer class="footer">
    <span>© {$year} <span class="footer-credit">{$author}</span> — All Rights Reserved</span>
    <span>{$appName} <strong>v{$version}</strong> &nbsp;|&nbsp; IPTV Proxy OS &nbsp;|&nbsp; Built by <span class="footer-credit">{$author}</span></span>
  </footer>
</main>

<script>
/* ── Global Utilities — XtreamTV by Kobir Shah ── */
console.log('%c⚡ XtreamTV v{$version}', 'color:#00b4ff;font-size:14px;font-weight:bold;');
console.log('%cDeveloped by Kobir Shah', 'color:#a855f7;font-size:11px;');

// Copy to clipboard
function copyText(text) {
  navigator.clipboard.writeText(text).then(() => {
    const el = document.querySelector('[data-copy="' + CSS.escape(text) + '"]');
    if (el) { const orig = el.textContent; el.textContent = '✓ Copied!'; setTimeout(() => el.textContent = orig, 1500); }
  });
}

// Modal helpers
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

// Close modal on overlay click
document.querySelectorAll('.modal-overlay').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});

// Auto-dismiss alerts
document.querySelectorAll('.alert').forEach(a => {
  setTimeout(() => { a.style.opacity = '0'; a.style.transition = '.5s'; setTimeout(() => a.remove(), 500); }, 5000);
});
</script>
</body>
</html>
HTML;
    }

    /** Flash message helper */
    public static function flash(string $key, string $msg): void
    {
        $_SESSION['flash'][$key] = $msg;
    }

    public static function getFlash(): string
    {
        if (empty($_SESSION['flash'])) return '';
        $html = '';
        foreach ($_SESSION['flash'] as $type => $msg) {
            $html .= '<div class="alert alert-' . Security::e($type) . '"><span>' . Security::e($msg) . '</span></div>';
        }
        unset($_SESSION['flash']);
        return $html;
    }
}
