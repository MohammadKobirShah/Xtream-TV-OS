<?php
/**
 * ============================================================
 *  XtreamTV — Login Page
 *  Developer: Kobir Shah
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/Security.php';

// Already logged in
if (Security::isLoggedIn()) {
    header('Location: /xtreamtv/index.php');
    exit;
}

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Rate limiting: 5 attempts per 5 minutes per IP
    if (!Security::rateLimit('login_' . ($_SERVER['REMOTE_ADDR'] ?? '0'), 5, 300)) {
        $error = 'Too many login attempts. Please wait 5 minutes.';
    } elseif (!Security::verifyCsrf($_POST['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please refresh.';
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        $user = Security::authenticateToken($username, $password);
        if ($user) {
            if (!(bool)$user['is_active']) {
                $error = 'Account is disabled.';
            } elseif ($user['expires_at'] && $user['expires_at'] < time()) {
                $error = 'Account has expired.';
            } else {
                Security::login($user);
                header('Location: /xtreamtv/index.php');
                exit;
            }
        } else {
            $error = 'Invalid username or password.';
            error_log("[XtreamTV][Login] Failed attempt for: {$username} from " . ($_SERVER['REMOTE_ADDR'] ?? '?'));
        }
    }
}

$csrf  = Security::csrfToken();
$appName = APP_NAME;
$author  = APP_AUTHOR;
$year    = date('Y');
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Login — <?= APP_NAME ?></title>
<meta name="author" content="<?= APP_AUTHOR ?>">
<style>
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  :root {
    --bg-base:     #050508;
    --neon-blue:   #00b4ff;
    --neon-purple: #a855f7;
    --text:        #e2e8f0;
    --text-muted:  #64748b;
    --border:      rgba(99,102,241,0.2);
  }
  body {
    font-family: 'Segoe UI', system-ui, sans-serif;
    background: var(--bg-base);
    min-height: 100vh; display: flex; align-items: center; justify-content: center;
    color: var(--text); overflow: hidden;
  }
  /* Animated bg */
  body::before {
    content: ''; position: fixed; inset: 0; z-index: 0;
    background-image:
      linear-gradient(rgba(0,180,255,0.03) 1px, transparent 1px),
      linear-gradient(90deg, rgba(0,180,255,0.03) 1px, transparent 1px);
    background-size: 40px 40px;
  }
  .orb {
    position: fixed; border-radius: 50%; filter: blur(80px); pointer-events: none; z-index: 0;
  }
  .orb-1 { width: 500px; height: 500px; background: rgba(0,180,255,0.07); top:-100px; left:-100px; animation: float1 8s ease-in-out infinite; }
  .orb-2 { width: 400px; height: 400px; background: rgba(168,85,247,0.06); bottom:-80px; right:-80px; animation: float2 10s ease-in-out infinite; }
  @keyframes float1 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(30px,20px)} }
  @keyframes float2 { 0%,100%{transform:translate(0,0)} 50%{transform:translate(-20px,30px)} }

  .login-wrap { position: relative; z-index: 1; width: 100%; max-width: 420px; padding: 16px; }

  .logo-section { text-align: center; margin-bottom: 32px; }
  .logo-icon {
    width: 72px; height: 72px; border-radius: 20px; margin: 0 auto 16px;
    background: linear-gradient(135deg, #001a2e, #0d0d25);
    border: 1px solid rgba(0,180,255,0.3);
    display: flex; align-items: center; justify-content: center;
    font-size: 2rem;
    box-shadow: 0 0 40px rgba(0,180,255,0.15), inset 0 1px 0 rgba(255,255,255,0.05);
  }
  .logo-name {
    font-size: 2rem; font-weight: 800; letter-spacing: -0.5px;
    background: linear-gradient(135deg, var(--neon-blue), var(--neon-purple));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
  }
  .logo-tagline { font-size: 0.78rem; color: var(--text-muted); margin-top: 4px; letter-spacing: 1.5px; text-transform: uppercase; }

  .card {
    background: rgba(10,10,20,0.9);
    backdrop-filter: blur(24px);
    border: 1px solid var(--border);
    border-radius: 20px;
    padding: 36px;
    box-shadow: 0 25px 80px rgba(0,0,0,0.6), 0 0 0 1px rgba(0,180,255,0.03);
  }
  .card-title { font-size: 1.1rem; font-weight: 700; margin-bottom: 6px; }
  .card-sub   { font-size: 0.82rem; color: var(--text-muted); margin-bottom: 28px; }

  .form-group { margin-bottom: 18px; }
  label { display: block; font-size: 0.75rem; font-weight: 600; color: #94a3b8; margin-bottom: 6px; text-transform: uppercase; letter-spacing: 0.5px; }
  input {
    width: 100%; padding: 12px 16px; border-radius: 12px; font-size: 0.95rem;
    background: rgba(255,255,255,0.04); border: 1px solid var(--border);
    color: var(--text); outline: none; transition: all 0.25s; font-family: inherit;
  }
  input:focus { border-color: var(--neon-blue); box-shadow: 0 0 0 3px rgba(0,180,255,0.12); background: rgba(255,255,255,0.06); }

  .btn-login {
    width: 100%; padding: 13px; border-radius: 12px; border: none; cursor: pointer;
    font-size: 0.95rem; font-weight: 700; letter-spacing: 0.3px;
    background: linear-gradient(135deg, var(--neon-blue), #7c3aed);
    color: #fff;
    box-shadow: 0 4px 24px rgba(0,180,255,0.25);
    transition: all 0.25s; margin-top: 8px;
  }
  .btn-login:hover { transform: translateY(-2px); box-shadow: 0 8px 32px rgba(0,180,255,0.4); }
  .btn-login:active { transform: translateY(0); }

  .alert {
    padding: 12px 16px; border-radius: 10px; font-size: 0.85rem; margin-bottom: 20px;
    background: rgba(239,68,68,0.1); color: #fca5a5; border: 1px solid rgba(239,68,68,0.25);
    display: flex; align-items: center; gap: 8px;
  }

  .default-hint {
    margin-top: 20px; padding: 12px 16px; border-radius: 10px; font-size: 0.78rem;
    background: rgba(0,180,255,0.05); border: 1px solid rgba(0,180,255,0.15);
    color: #93c5fd; text-align: center;
  }

  footer {
    text-align: center; margin-top: 24px; font-size: 0.72rem; color: var(--text-muted);
  }
  .credit {
    background: linear-gradient(90deg, var(--neon-blue), var(--neon-purple));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent; background-clip: text;
    font-weight: 700;
  }
  ::-webkit-scrollbar { display: none; }
</style>
</head>
<body>
<div class="orb orb-1"></div>
<div class="orb orb-2"></div>

<div class="login-wrap">
  <div class="logo-section">
    <div class="logo-icon">⚡</div>
    <div class="logo-name"><?= $appName ?></div>
    <div class="logo-tagline">Elite IPTV Proxy OS</div>
  </div>

  <div class="card">
    <div class="card-title">Welcome back</div>
    <div class="card-sub">Sign in to your <?= $appName ?> panel</div>

    <?php if ($error): ?>
    <div class="alert">⚠ <?= htmlspecialchars($error, ENT_QUOTES, 'UTF-8') ?></div>
    <?php endif; ?>

    <form method="POST" action="/xtreamtv/login.php" autocomplete="off">
      <input type="hidden" name="csrf_token" value="<?= $csrf ?>">

      <div class="form-group">
        <label for="username">Username</label>
        <input type="text" id="username" name="username" placeholder="Enter username"
               value="<?= htmlspecialchars($_POST['username'] ?? '', ENT_QUOTES, 'UTF-8') ?>"
               required autofocus autocomplete="username">
      </div>

      <div class="form-group">
        <label for="password">Password</label>
        <input type="password" id="password" name="password" placeholder="••••••••"
               required autocomplete="current-password">
      </div>

      <button type="submit" class="btn-login">⚡ Sign In to <?= $appName ?></button>
    </form>

    <div class="default-hint">
      🔑 Default credentials: <strong>admin</strong> / <strong>admin123</strong><br>
      <small>Change these immediately after first login.</small>
    </div>
  </div>

  <footer>
    <?= $appName ?> v<?= APP_VERSION ?> &nbsp;|&nbsp;
    Developed by <span class="credit"><?= $author ?></span> &nbsp;|&nbsp;
    © <?= $year ?> All Rights Reserved
  </footer>
</div>

<script>
console.log('%c⚡ XtreamTV Login', 'color:#00b4ff;font-weight:bold;font-size:14px;');
console.log('%c✦ Developed by Kobir Shah ✦', 'color:#a855f7;font-size:11px;');
</script>
</body>
</html>
