<?php
require_once 'auth.php';
require_admin();
require_once 'db.php';

$user = current_user();

// Which log to view
$log_type = $_GET['log'] ?? 'access';

$log_files = [
    'access'     => ['label' => 'Access Log',  'path' => '/var/log/apache2/access.log'],
    'error'      => ['label' => 'Error Log',   'path' => '/var/log/apache2/error.log'],
    'backup_log' => ['label' => 'Backup Log',  'path' => '/var/log/backup_cron.log'],
];

$current_log = $log_files[$log_type] ?? $log_files['access'];
$log_content = '';
$line_count  = (int)($_GET['lines'] ?? 100);

$log_path = $current_log['path'];

if (file_exists($log_path) && !is_link($log_path) && is_readable($log_path)) {
    $output = [];
    exec("tail -n " . escapeshellarg($line_count) . " " . escapeshellarg($log_path) . " 2>&1", $output);
    $log_content = implode("\n", $output);
    if (trim($log_content) === '') {
        $log_content = "(Log is empty — activity will appear here as the server handles requests.)";
    }
} else {
    $log_content = "(Log file not available: {$log_path})";
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NAS — System Logs</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:       #0d0f14;
    --surface:  #161920;
    --surface2: #1d2029;
    --border:   #2a2d38;
    --accent:   #4fffb0;
    --accent2:  #00bfff;
    --text:     #e8eaf0;
    --muted:    #6b7080;
    --danger:   #ff4f6a;
    --warn:     #ffb84f;
    --radius:   6px;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: 'DM Sans', sans-serif; min-height: 100vh; display: flex; flex-direction: column; }

  nav {
    background-color: #161920; border-bottom: 1px solid var(--border);
    padding: 0 28px; height: 56px; display: flex; align-items: center; gap: 20px;
    position: sticky; top: 0; z-index: 100;
    box-shadow: 0 1px 0 rgba(0,0,0,0.4), 0 4px 12px rgba(0,0,0,0.25);
  }
  .nav-logo { font-family: 'Space Mono', monospace; font-weight: 700; font-size: 16px; color: var(--accent); text-decoration: none; margin-right: 8px; }
  .nav-links { display: flex; gap: 4px; flex: 1; }
  .nav-link { color: var(--muted); text-decoration: none; font-size: 14px; padding: 6px 12px; border-radius: var(--radius); transition: color 0.15s, background 0.15s; }
  .nav-link:hover, .nav-link.active { color: var(--text); background: var(--surface2); }
  .nav-link:hover { box-shadow: inset 0 0 0 1px rgba(79,255,176,0.2); }
  .nav-link.active { color: var(--accent); box-shadow: inset 0 0 0 1px rgba(79,255,176,0.3); }
  .nav-user { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--muted); }
  .nav-user strong { color: var(--text); }
  .nav-user-link { color: var(--muted); text-decoration: none; padding: 4px 8px; border-radius: var(--radius); border: 1px solid transparent; transition: border-color 0.15s, color 0.15s; }
  .nav-user-link:hover { border-color: rgba(79,255,176,0.3); color: var(--text); background: rgba(79,255,176,0.06); box-shadow: 0 0 0 3px rgba(79,255,176,0.08); }
  .nav-user-link:hover strong { color: var(--accent); }
  .role-badge { font-size: 10px; font-weight: 700; letter-spacing: 0.1em; font-family: 'Space Mono', monospace; padding: 3px 8px; border-radius: 4px; text-transform: uppercase; }
  .role-badge.admin { color: #00bfff; background: rgba(0,191,255,0.1); border: 1px solid rgba(0,191,255,0.3); }
  .nav-user a { color: var(--muted); text-decoration: none; font-size: 12px; padding: 4px 10px; border: 1px solid var(--border); border-radius: var(--radius); transition: border-color 0.15s, color 0.15s; }
  .nav-user a[href="/logout.php"]:hover { border-color: var(--danger); color: var(--danger); }

  main { padding: 28px; max-width: 1100px; width: 100%; margin: 0 auto; flex: 1; }

  .page-hero {
    display: flex; align-items: center; justify-content: space-between; gap: 20px;
    padding: 22px 26px; margin-bottom: 24px;
    background: linear-gradient(135deg, var(--surface) 0%, var(--surface2) 100%);
    border: 1px solid var(--border); border-radius: 10px;
    position: relative; overflow: hidden;
    animation: fadeUp 0.4s ease both;
  }
  .page-hero::before {
    content: ''; position: absolute;
    top: -60px; right: -60px; width: 220px; height: 220px;
    background: radial-gradient(circle, rgba(79,255,176,0.10) 0%, transparent 70%);
    pointer-events: none;
  }
  .hero-title {
    font-size: 22px; font-weight: 500; letter-spacing: -0.3px;
    background: linear-gradient(90deg, var(--accent), var(--accent2));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text; color: transparent;
    margin-bottom: 4px;
  }
  .hero-sub { font-size: 13px; color: var(--muted); }
  .hero-stat { display: flex; flex-direction: column; align-items: flex-end; gap: 2px; flex-shrink: 0; position: relative; z-index: 1; }
  .hero-stat-value { font-family: 'Space Mono', monospace; font-size: 28px; font-weight: 700; color: var(--accent); line-height: 1; }
  .hero-stat-label { font-size: 10px; text-transform: uppercase; letter-spacing: 0.1em; color: var(--muted); }
  h1 { font-size: 20px; font-weight: 500; margin-bottom: 24px; }

  .controls {
    display: flex; gap: 10px; margin-bottom: 20px; flex-wrap: wrap; align-items: center;
  }
  .tab {
    padding: 7px 14px; border-radius: var(--radius); font-size: 13px;
    background: var(--surface); border: 1px solid var(--border);
    color: var(--muted); text-decoration: none; transition: all 0.15s;
  }
  .tab:hover { color: var(--text); background: var(--surface2); box-shadow: 0 0 0 1px rgba(79,255,176,0.2); transform: translateY(-1px); }
  .tab { transition: color 0.15s, background 0.15s, box-shadow 0.15s, transform 0.15s, border-color 0.15s; }
  .tab.active { color: var(--accent); border-color: var(--accent); background: rgba(79,255,176,0.06); }

  .line-select {
    margin-left: auto; display: flex; align-items: center; gap: 8px; font-size: 13px; color: var(--muted);
  }
  .line-select select {
    background: var(--surface); border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--text); font-size: 13px; padding: 6px 10px;
  }
  .line-select select:focus { border-color: var(--accent); outline: none; }

  .log-panel {
    background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden;
    animation: fadeUp 0.4s ease both;
    animation-delay: 0.08s;
  }
  .controls { animation: fadeUp 0.4s ease both; }
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .log-header {
    padding: 14px 20px; border-bottom: 1px solid var(--border);
    font-size: 13px; font-weight: 500; display: flex; justify-content: space-between; align-items: center;
  }
  .log-header span { color: var(--muted); font-size: 12px; }

  .log-content {
    padding: 16px 20px;
    font-family: 'Space Mono', monospace;
    font-size: 12px;
    line-height: 1.7;
    color: var(--text);
    max-height: 600px;
    overflow-y: auto;
    white-space: pre-wrap;
    word-break: break-all;
  }
  .log-content::-webkit-scrollbar { width: 6px; }
  .log-content::-webkit-scrollbar-track { background: var(--surface); }
  .log-content::-webkit-scrollbar-thumb { background: var(--border); border-radius: 3px; }

  .empty { padding: 40px 20px; text-align: center; color: var(--muted); font-size: 13px; }

  .btn { display: inline-flex; align-items: center; gap: 6px; padding: 7px 14px; border-radius: var(--radius); font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; border: none; text-decoration: none; transition: opacity 0.15s; }
  .btn:hover { opacity: 0.92; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(79,255,176,0.15); }
  .btn:active { transform: translateY(0); }
  .btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
</style>
</head>
<body>

<nav>
  <a class="nav-logo" href="/">NAS</a>
  <div class="nav-links">
    <a class="nav-link" href="/">Files</a>
    <?php if (is_admin()): ?>
    <a class="nav-link" href="/users.php">Users</a>
    <?php endif; ?>
    <a class="nav-link" href="/monitor.php">Monitor</a>
    <a class="nav-link active" href="/logs.php">Logs</a>
    <?php if (is_admin()): ?>
    <a class="nav-link" href="/backup.php">Backups</a>
    <?php endif; ?>
  </div>
  <div class="nav-user">
    <a href="/profile.php" class="nav-user-link">Hello, <strong><?= htmlspecialchars($user['username']) ?></strong></a>
    <?php if (is_admin()): ?><span class="role-badge admin">Admin</span><?php endif; ?>
    <a href="/logout.php">Sign out</a>
  </div>
</nav>

<main>
  <div class="page-hero">
    <div>
      <h1 class="hero-title">Activity Logs</h1>
      <p class="hero-sub">Every action, every request — audited in one place.</p>
    </div>
    <div class="hero-stat">
      <span class="hero-stat-value"><?= $line_count ?></span>
      <span class="hero-stat-label">Lines Shown</span>
    </div>
  </div>

  <h1 style="font-size:16px;font-weight:500;margin-bottom:18px;color:var(--muted);">Logs</h1>

  <div class="controls">
    <?php foreach ($log_files as $key => $info): ?>
    <a class="tab <?= $key === $log_type ? 'active' : '' ?>" href="?log=<?= $key ?>&lines=<?= $line_count ?>"><?= $info['label'] ?></a>
    <?php endforeach; ?>

    <div class="line-select">
      <span>Show last</span>
      <select onchange="window.location='?log=<?= $log_type ?>&lines='+this.value">
        <?php foreach ([50, 100, 250, 500] as $n): ?>
        <option value="<?= $n ?>" <?= $n === $line_count ? 'selected' : '' ?>><?= $n ?> lines</option>
        <?php endforeach; ?>
      </select>
    </div>
  </div>

  <div class="log-panel">
    <div class="log-header">
      <?= $current_log['label'] ?>
      <span><?= $current_log['path'] ?></span>
    </div>
    <?php if (trim($log_content) === '' || str_starts_with($log_content, '(Log file')): ?>
      <div class="empty"><?= htmlspecialchars($log_content ?: 'Log is empty.') ?></div>
    <?php else: ?>
      <div class="log-content" id="log-content"><?= htmlspecialchars($log_content) ?></div>
    <?php endif; ?>
  </div>
</main>

<script>
// Auto-scroll to bottom of log
const lc = document.getElementById('log-content');
if (lc) lc.scrollTop = lc.scrollHeight;
</script>
</body>
</html>
