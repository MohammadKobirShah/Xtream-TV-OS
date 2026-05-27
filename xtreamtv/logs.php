<?php
/**
 * ============================================================
 *  XtreamTV — System Logs
 *  Developer: Kobir Shah
 * ============================================================
 */

declare(strict_types=1);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/src/Database.php';
require_once __DIR__ . '/src/View.php';

$e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

ob_start();
?>
<div class="flex items-center justify-between mb-6">
  <div>
    <h2 style="font-size:1.4rem;font-weight:800">📋 System Logs</h2>
  </div>
</div>

<div class="glass" style="padding:48px 24px;text-align:center">
  <div style="font-size:3rem;margin-bottom:16px">📋</div>
  <div style="font-size:1.1rem;font-weight:700;margin-bottom:8px">Access Logging Disabled</div>
  <div style="font-size:0.85rem;color:var(--text-muted);max-width:480px;margin:0 auto">
    The access logging system has been removed. Stream sessions and user access logs
    are no longer tracked in this version. Check the PHP error log at
    <code class="mono">storage/logs/php_error.log</code> for server-side errors.
  </div>
  <div style="margin-top:24px">
    <a href="index.php" class="btn btn-primary">← Back to Dashboard</a>
  </div>
</div>
<?php
$body = ob_get_clean();
echo View::layout('System Logs', $body, 'logs');
