<?php
require_once 'auth.php';
require_admin();
require_once 'db.php';
require_once 'usb_manifest.php';

$user = current_user();

// Keep the user manifest fresh so the USB watcher can mirror per-user folders.
update_user_manifest($pdo);

function fmt($bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1)    . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 1)       . ' KB';
    return $bytes . ' B';
}

function fmt_uptime(int $sec): string {
    if ($sec <= 0) return '—';
    $d = intdiv($sec, 86400);
    $h = intdiv($sec % 86400, 3600);
    $m = intdiv($sec % 3600, 60);
    if ($d > 0) return "{$d}d {$h}h";
    if ($h > 0) return "{$h}h {$m}m";
    return "{$m}m";
}

function fmt_ago(?string $ts): string {
    if (!$ts) return 'never';
    $diff = time() - strtotime($ts);
    if ($diff < 60)    return $diff . 's ago';
    if ($diff < 3600)  return intdiv($diff, 60) . 'm ago';
    if ($diff < 86400) return intdiv($diff, 3600) . 'h ago';
    return intdiv($diff, 86400) . 'd ago';
}

// ── Storage volumes ─────────────────────────────────────
// Measure the actual NAS data mounts, not the container root.
$uploads_mount = '/var/www/uploads';
$backups_mount = '/var/www/backups';

$uploads_total = @disk_total_space($uploads_mount) ?: 0;
$uploads_free  = @disk_free_space($uploads_mount)  ?: 0;
$uploads_used  = $uploads_total - $uploads_free;
$uploads_pct   = $uploads_total > 0 ? round(($uploads_used / $uploads_total) * 100, 1) : 0;

$backups_total = @disk_total_space($backups_mount) ?: 0;
$backups_free  = @disk_free_space($backups_mount)  ?: 0;
$backups_used  = $backups_total - $backups_free;
$backups_pct   = $backups_total > 0 ? round(($backups_used / $backups_total) * 100, 1) : 0;

// Are uploads + backups on the same underlying disk? (true for Docker Desktop on Windows)
$same_volume = ($uploads_total === $backups_total && $uploads_free === $backups_free);

// ── Server CPU / Memory / Uptime / Load (container view) ─
function cpu_usage(): float {
    if (!is_readable('/proc/stat')) return 0;
    $s1 = file('/proc/stat')[0];
    usleep(150000); // 150ms — enough signal, less page lag than 500ms
    $s2 = file('/proc/stat')[0];

    $p1 = array_slice(explode(' ', preg_replace('/\s+/', ' ', trim($s1))), 1);
    $p2 = array_slice(explode(' ', preg_replace('/\s+/', ' ', trim($s2))), 1);

    $idle1 = (float)$p1[3]; $total1 = array_sum(array_map('floatval', $p1));
    $idle2 = (float)$p2[3]; $total2 = array_sum(array_map('floatval', $p2));

    $total_diff = $total2 - $total1;
    $idle_diff  = $idle2  - $idle1;

    return $total_diff > 0 ? round((1 - $idle_diff / $total_diff) * 100, 1) : 0;
}

function mem_info(): array {
    if (!is_readable('/proc/meminfo')) return ['total' => 0, 'used' => 0, 'pct' => 0];
    $info = [];
    foreach (file('/proc/meminfo') as $line) {
        if (!str_contains($line, ':')) continue;
        [$key, $val] = explode(':', $line);
        $info[trim($key)] = (int)trim(str_replace(' kB', '', $val)) * 1024;
    }
    $total     = $info['MemTotal']     ?? 0;
    $available = $info['MemAvailable'] ?? 0;
    $used      = $total - $available;
    return ['total' => $total, 'used' => $used, 'pct' => $total > 0 ? round(($used / $total) * 100, 1) : 0];
}

function load_avg(): array {
    if (!is_readable('/proc/loadavg')) return ['1' => '0.00', '5' => '0.00', '15' => '0.00'];
    $parts = explode(' ', trim(file_get_contents('/proc/loadavg')));
    return ['1' => $parts[0] ?? '0.00', '5' => $parts[1] ?? '0.00', '15' => $parts[2] ?? '0.00'];
}

function uptime_seconds(): int {
    if (!is_readable('/proc/uptime')) return 0;
    $parts = explode(' ', trim(file_get_contents('/proc/uptime')));
    return (int)floatval($parts[0] ?? 0);
}

$cpu        = cpu_usage();
$mem        = mem_info();
$load       = load_avg();
$uptime_s   = uptime_seconds();
$cpu_cores  = (int)trim(@shell_exec('nproc') ?: '1');
$load1_pct  = $cpu_cores > 0 ? min(100, round(((float)$load['1'] / $cpu_cores) * 100)) : 0;

// ── DB-derived stats (source of truth for stored bytes) ──
$total_users   = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$total_files   = $pdo->query('SELECT COUNT(*) FROM files WHERE is_folder = 0')->fetchColumn();
$total_folders = $pdo->query('SELECT COUNT(*) FROM files WHERE is_folder = 1')->fetchColumn();
$uploads_db    = (int)$pdo->query('SELECT COALESCE(SUM(filesize),0) FROM files WHERE is_folder = 0')->fetchColumn();
$backups_db    = (int)$pdo->query('SELECT COALESCE(SUM(filesize),0) FROM backups')->fetchColumn();
$backups_count = (int)$pdo->query('SELECT COUNT(*) FROM backups')->fetchColumn();

// Active sessions = users with a login in the last 30 minutes
$active_users = $pdo->query(
    "SELECT username, role, last_login
     FROM users
     WHERE last_login IS NOT NULL AND last_login >= NOW() - INTERVAL 30 MINUTE
     ORDER BY last_login DESC
     LIMIT 8"
)->fetchAll();
$active_count = count($active_users);

// Last automatic backup
$last_auto = $pdo->query(
    "SELECT filename, filesize, created_at
     FROM backups
     WHERE filename LIKE 'nas_auto_backup_%'
     ORDER BY created_at DESC
     LIMIT 1"
)->fetch();

// Recent uploads (last 10)
$recent = $pdo->query('
    SELECT f.filename, f.filesize, f.filetype, f.created_at, u.username
    FROM files f
    LEFT JOIN users u ON f.owner_id = u.id
    WHERE f.is_folder = 0
    ORDER BY f.created_at DESC
    LIMIT 10
')->fetchAll();

// Per-user storage
$user_storage = $pdo->query('
    SELECT u.username, u.role, u.storage_quota,
           COALESCE(SUM(f.filesize),0) AS used, COUNT(f.id) AS file_count
    FROM users u
    LEFT JOIN files f ON f.owner_id = u.id AND f.is_folder = 0
    GROUP BY u.id
    ORDER BY used DESC
')->fetchAll();

function file_icon(string $type): string {
    if (str_starts_with($type, 'image/')) return 'IMG';
    if (str_starts_with($type, 'video/')) return 'VID';
    if (str_starts_with($type, 'audio/')) return 'AUD';
    if ($type === 'application/pdf')       return 'PDF';
    if (str_contains($type, 'zip') || str_contains($type, 'tar')) return 'ZIP';
    return 'FILE';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NAS — Monitor</title>
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
    background: var(--surface); border-bottom: 1px solid var(--border);
    padding: 0 28px; height: 56px; display: flex; align-items: center; gap: 20px;
    position: sticky; top: 0; z-index: 100;
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

  /* ── Stat cards ── */
  .stat-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));
    gap: 14px;
    margin-bottom: 28px;
  }
  .stat-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 20px;
    animation: fadeUp 0.4s ease both;
  }
  .stat-card:nth-child(2) { animation-delay: .05s; }
  .stat-card:nth-child(3) { animation-delay: .10s; }
  .stat-card:nth-child(4) { animation-delay: .15s; }
  @keyframes fadeUp { from { opacity:0; transform:translateY(12px); } to { opacity:1; transform:translateY(0); } }

  .stat-label { font-size: 11px; font-weight: 500; letter-spacing: .08em; text-transform: uppercase; color: var(--muted); margin-bottom: 10px; }
  .stat-value { font-family: 'Space Mono', monospace; font-size: 26px; font-weight: 700; line-height: 1; }
  .stat-sub   { font-size: 12px; color: var(--muted); margin-top: 6px; }

  /* ── Gauge row ── */
  .gauge-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
    gap: 14px;
    margin-bottom: 28px;
  }
  .gauge-card {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    padding: 22px 24px;
  }
  .gauge-header { display: flex; justify-content: space-between; align-items: baseline; margin-bottom: 14px; }
  .gauge-title  { font-size: 13px; font-weight: 500; }
  .gauge-pct    { font-family: 'Space Mono', monospace; font-size: 22px; font-weight: 700; }
  .gauge-pct.warn   { color: var(--warn); }
  .gauge-pct.danger { color: var(--danger); }
  .gauge-pct.ok     { color: var(--accent); }

  .bar-track {
    height: 8px; background: var(--surface2); border-radius: 99px; overflow: hidden;
  }
  .bar-fill {
    height: 100%; border-radius: 99px;
    background: linear-gradient(90deg, var(--accent), var(--accent2));
    transition: width 0.8s cubic-bezier(.4,0,.2,1);
  }
  .bar-fill.warn   { background: linear-gradient(90deg, var(--warn), #ff944f); }
  .bar-fill.danger { background: linear-gradient(90deg, var(--danger), #ff8f4f); }

  .gauge-meta { display: flex; justify-content: space-between; font-size: 12px; color: var(--muted); margin-top: 10px; }

  /* ── Two-column bottom ── */
  .bottom-grid {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 14px;
  }
  @media (max-width: 700px) { .bottom-grid { grid-template-columns: 1fr; } }

  .panel {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
  }
  .panel-header {
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
    font-size: 13px;
    font-weight: 500;
  }

  /* Recent uploads */
  .upload-list { list-style: none; }
  .upload-item {
    display: flex; align-items: center; gap: 12px;
    padding: 11px 20px;
    border-bottom: 1px solid rgba(42,45,56,0.5);
    font-size: 13px;
  }
  .upload-item:last-child { border-bottom: none; }
  .upload-icon {
    font-family: 'Space Mono', monospace;
    font-size: 10px;
    font-weight: 700;
    letter-spacing: 0.05em;
    color: var(--accent);
    background: rgba(79,255,176,0.08);
    border: 1px solid rgba(79,255,176,0.2);
    padding: 3px 6px;
    border-radius: 4px;
    flex-shrink: 0;
    min-width: 38px;
    text-align: center;
  }
  .upload-name { flex: 1; overflow: hidden; white-space: nowrap; text-overflow: ellipsis; font-weight: 500; }
  .upload-meta { color: var(--muted); font-size: 11px; white-space: nowrap; text-align: right; }

  /* User storage */
  .user-list { list-style: none; }
  .user-item { padding: 12px 20px; border-bottom: 1px solid rgba(42,45,56,0.5); }
  .user-item:last-child { border-bottom: none; }
  .user-row  { display: flex; justify-content: space-between; align-items: center; margin-bottom: 7px; font-size: 13px; }
  .user-name { font-weight: 500; }
  .user-size { font-family: 'Space Mono', monospace; font-size: 12px; color: var(--muted); }

  .mini-bar-track { height: 4px; background: var(--surface2); border-radius: 99px; overflow: hidden; }
  .mini-bar-fill  { height: 100%; border-radius: 99px; background: linear-gradient(90deg, var(--accent2), var(--accent)); }

  .empty { padding: 32px 20px; text-align: center; color: var(--muted); font-size: 13px; }

  /* External Storage panel */
  .ext-storage {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 10px;
    overflow: hidden;
    animation: fadeUp 0.4s ease both;
    transition: border-color 0.4s ease, box-shadow 0.4s ease;
  }
  .ext-storage[data-active="1"] {
    border-color: rgba(79,255,176,0.3);
    box-shadow: 0 0 0 1px rgba(79,255,176,0.05);
  }
  .ext-header {
    display: flex; justify-content: space-between; align-items: center;
    padding: 16px 20px;
    border-bottom: 1px solid var(--border);
  }
  .ext-identity { display: flex; align-items: center; gap: 12px; }
  .ext-icon {
    width: 36px; height: 36px;
    background: rgba(79,255,176,0.08);
    border: 1px solid rgba(79,255,176,0.2);
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    color: var(--accent);
    transition: all 0.4s ease;
  }
  .ext-storage[data-active="0"] .ext-icon {
    color: var(--muted);
    background: var(--surface2);
    border-color: var(--border);
  }
  .ext-title { font-size: 14px; font-weight: 500; }
  .ext-target {
    font-size: 11px; color: var(--muted);
    font-family: 'Space Mono', monospace;
    margin-top: 2px;
  }
  .ext-status { display: flex; align-items: center; gap: 8px; font-size: 12px; }
  .ext-status-dot {
    width: 8px; height: 8px; border-radius: 50%;
    background: var(--muted);
    transition: background 0.3s ease, box-shadow 0.3s ease;
  }
  .ext-storage[data-active="1"] .ext-status-dot {
    background: var(--accent);
    box-shadow: 0 0 8px var(--accent);
  }
  .ext-status-dot.writing {
    animation: pulse-write 0.6s ease-out;
  }
  @keyframes pulse-write {
    0%   { transform: scale(1);   box-shadow: 0 0 8px var(--accent); }
    50%  { transform: scale(1.6); box-shadow: 0 0 16px var(--accent); }
    100% { transform: scale(1);   box-shadow: 0 0 8px var(--accent); }
  }
  .ext-status-text { color: var(--muted); }
  .ext-storage[data-active="1"] .ext-status-text { color: var(--accent); }

  .ext-body {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 24px;
    padding: 20px;
  }
  @media (max-width: 700px) { .ext-body { grid-template-columns: 1fr; } }

  .ext-capacity-header {
    display: flex; justify-content: space-between; align-items: baseline;
    margin-bottom: 10px;
  }
  .ext-capacity-label { font-size: 12px; color: var(--muted); text-transform: uppercase; letter-spacing: 0.08em; }
  .ext-capacity-pct {
    font-family: 'Space Mono', monospace; font-size: 18px; font-weight: 700;
    color: var(--accent);
    transition: color 0.3s ease;
  }
  .ext-capacity-pct.warn   { color: var(--warn); }
  .ext-capacity-pct.danger { color: var(--danger); }
  .ext-capacity-meta {
    display: flex; justify-content: space-between;
    font-size: 12px; color: var(--muted);
    margin-top: 8px;
  }

  .ext-props {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: 8px 18px;
    align-content: start;
  }
  .ext-prop {
    display: flex; justify-content: space-between;
    font-size: 12px;
    padding: 4px 0;
    border-bottom: 1px solid rgba(42,45,56,0.4);
  }
  .ext-prop-key { color: var(--muted); }
  .ext-prop-val { font-family: 'Space Mono', monospace; color: var(--text); }

  /* Charts panel — smooth dropdown */
  .charts-panel {
    background: var(--surface);
    border: 1px solid transparent;
    border-radius: 10px;
    max-height: 0;
    opacity: 0;
    overflow: hidden;
    margin-bottom: 0;
    transition: max-height 0.35s cubic-bezier(.4,0,.2,1),
                opacity 0.25s ease,
                margin-bottom 0.35s cubic-bezier(.4,0,.2,1),
                border-color 0.25s ease;
  }
  .charts-panel.open {
    max-height: 220px;
    opacity: 1;
    margin-bottom: 18px;
    border-color: var(--border);
  }
  .charts-inner { padding: 14px 18px; }
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
    <a class="nav-link active" href="/monitor.php">Monitor</a>
    <?php if (is_admin()): ?>
    <a class="nav-link" href="/logs.php">Logs</a>
    <?php endif; ?>
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
      <h1 class="hero-title">System Health</h1>
      <p class="hero-sub">
        Real-time insights into your NAS server, storage volumes, and active sessions.
        <span id="live-dot" style="display:inline-block;width:8px;height:8px;border-radius:50%;background:var(--accent);margin-left:8px;vertical-align:middle;box-shadow:0 0 8px var(--accent);" title="Live — updating every 3 seconds"></span>
        <span style="font-size:11px;color:var(--muted);margin-left:4px;">live</span>
      </p>
    </div>
    <div class="hero-stat">
      <span class="hero-stat-value" data-m="uptime_fmt"><?= fmt_uptime($uptime_s) ?></span>
      <span class="hero-stat-label">Uptime</span>
    </div>
  </div>

  <div style="display:flex;justify-content:flex-end;margin-bottom:14px;">
    <button id="toggle-charts" type="button" style="background:var(--surface);border:1px solid var(--border);color:var(--text);padding:8px 14px;border-radius:var(--radius);font-family:'DM Sans',sans-serif;font-size:13px;cursor:pointer;display:flex;align-items:center;gap:6px;transition:border-color 0.15s, color 0.15s;" onmouseover="this.style.borderColor='var(--accent)';this.style.color='var(--accent)';" onmouseout="this.style.borderColor='var(--border)';this.style.color='var(--text)';">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="22 12 18 12 15 21 9 3 6 12 2 12"/></svg>
      <span id="toggle-charts-label">Show Charts</span>
    </button>
  </div>

  <div id="charts-panel" class="charts-panel">
    <div class="charts-inner">
      <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:14px;">
        <div>
          <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">CPU %</div>
          <div style="position:relative;height:60px;"><canvas id="chart-cpu"></canvas></div>
        </div>
        <div>
          <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">Memory %</div>
          <div style="position:relative;height:60px;"><canvas id="chart-mem"></canvas></div>
        </div>
        <div>
          <div style="font-size:10px;color:var(--muted);text-transform:uppercase;letter-spacing:0.08em;margin-bottom:4px;">Load (1m)</div>
          <div style="position:relative;height:60px;"><canvas id="chart-load"></canvas></div>
        </div>
      </div>
    </div>
  </div>

  <h1 style="font-size:16px;font-weight:500;margin-bottom:18px;color:var(--muted);">Server</h1>

  <!-- Server stat cards -->
  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-label">Uptime</div>
      <div class="stat-value" style="font-size:20px" data-m="uptime_fmt"><?= fmt_uptime($uptime_s) ?></div>
      <div class="stat-sub">since last server restart</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Load Average</div>
      <div class="stat-value" style="font-size:18px" data-m="load_avg"><?= $load['1'] ?> · <?= $load['5'] ?> · <?= $load['15'] ?></div>
      <div class="stat-sub"><span data-m="cpu_cores"><?= $cpu_cores ?></span> cores · 1m / 5m / 15m</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Active Sessions</div>
      <div class="stat-value" data-m="active_count"><?= $active_count ?></div>
      <div class="stat-sub">logged in last 30 min</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Last Auto Backup</div>
      <div class="stat-value" style="font-size:18px" data-m="last_auto_ago"><?= $last_auto ? fmt_ago($last_auto['created_at']) : 'never' ?></div>
      <div class="stat-sub" data-m="last_auto_size"><?= $last_auto ? fmt((int)$last_auto['filesize']) : 'no backups yet' ?></div>
    </div>
  </div>

  <h1 style="font-size:16px;font-weight:500;margin-bottom:18px;color:var(--muted);">Storage</h1>

  <!-- Storage stat cards -->
  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-label">Total Users</div>
      <div class="stat-value" data-m="total_users"><?= $total_users ?></div>
      <div class="stat-sub">registered accounts</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Files Stored</div>
      <div class="stat-value" data-m="total_files"><?= $total_files ?></div>
      <div class="stat-sub"><span data-m="total_folders"><?= $total_folders ?></span> folders</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Uploads Size</div>
      <div class="stat-value" style="font-size:20px" data-m="uploads_db_fmt"><?= fmt($uploads_db) ?></div>
      <div class="stat-sub">across all users</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Backups Size</div>
      <div class="stat-value" style="font-size:20px" data-m="backups_db_fmt"><?= fmt($backups_db) ?></div>
      <div class="stat-sub"><span data-m="backups_count"><?= $backups_count ?></span> archives</div>
    </div>
  </div>

  <!-- Gauges -->
  <div class="gauge-grid">
    <?php
      $cpu_class = $cpu >= 80 ? 'danger' : ($cpu >= 60 ? 'warn' : 'ok');
      $mem_class = $mem['pct'] >= 85 ? 'danger' : ($mem['pct'] >= 65 ? 'warn' : 'ok');
      $up_class  = $uploads_pct >= 85 ? 'danger' : ($uploads_pct >= 65 ? 'warn' : 'ok');
      $bk_class  = $backups_pct >= 85 ? 'danger' : ($backups_pct >= 65 ? 'warn' : 'ok');
    ?>
    <div class="gauge-card">
      <div class="gauge-header">
        <span class="gauge-title">Uploads Volume</span>
        <span class="gauge-pct <?= $up_class ?>" data-m="uploads_pct" data-suffix="%"><?= $uploads_pct ?>%</span>
      </div>
      <div class="bar-track"><div class="bar-fill <?= $up_class ?>" style="width:<?= $uploads_pct ?>%" data-bar="uploads_pct"></div></div>
      <div class="gauge-meta"><span><span data-m="uploads_used_fmt"><?= fmt($uploads_used) ?></span> used</span><span><span data-m="uploads_total_fmt"><?= fmt($uploads_total) ?></span> total</span></div>
    </div>

    <?php if (!$same_volume): ?>
    <div class="gauge-card">
      <div class="gauge-header">
        <span class="gauge-title">Backups Volume</span>
        <span class="gauge-pct <?= $bk_class ?>" data-m="backups_pct" data-suffix="%"><?= $backups_pct ?>%</span>
      </div>
      <div class="bar-track"><div class="bar-fill <?= $bk_class ?>" style="width:<?= $backups_pct ?>%" data-bar="backups_pct"></div></div>
      <div class="gauge-meta"><span><span data-m="backups_used_fmt"><?= fmt($backups_used) ?></span> used</span><span><span data-m="backups_total_fmt"><?= fmt($backups_total) ?></span> total</span></div>
    </div>
    <?php endif; ?>

    <div class="gauge-card">
      <div class="gauge-header">
        <span class="gauge-title">CPU Usage</span>
        <span class="gauge-pct <?= $cpu_class ?>" data-m="cpu" data-suffix="%"><?= $cpu ?>%</span>
      </div>
      <div class="bar-track"><div class="bar-fill <?= $cpu_class ?>" style="width:<?= $cpu ?>%" data-bar="cpu"></div></div>
      <div class="gauge-meta"><span>load 1m: <span data-m="load1"><?= $load['1'] ?></span> (<span data-m="load1_pct"><?= $load1_pct ?></span>% of <span data-m="cpu_cores"><?= $cpu_cores ?></span>c)</span><span>0–100%</span></div>
    </div>

    <div class="gauge-card">
      <div class="gauge-header">
        <span class="gauge-title">Memory</span>
        <span class="gauge-pct <?= $mem_class ?>" data-m="mem_pct" data-suffix="%"><?= $mem['pct'] ?>%</span>
      </div>
      <div class="bar-track"><div class="bar-fill <?= $mem_class ?>" style="width:<?= $mem['pct'] ?>%" data-bar="mem_pct"></div></div>
      <div class="gauge-meta"><span><span data-m="mem_used_fmt"><?= fmt($mem['used']) ?></span> used</span><span><span data-m="mem_total_fmt"><?= fmt($mem['total']) ?></span> total</span></div>
    </div>
  </div>

  <!-- External Storage panel (USB / secondary destinations) -->
  <div id="ext-storage-panel" class="ext-storage" data-active="0" style="margin-bottom:14px;">
    <div class="ext-header">
      <div class="ext-identity">
        <span class="ext-icon">
          <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="2" y="4" width="20" height="16" rx="2"/><line x1="2" y1="10" x2="22" y2="10"/><line x1="6" y1="15" x2="6.01" y2="15"/><line x1="10" y1="15" x2="10.01" y2="15"/></svg>
        </span>
        <div>
          <div class="ext-title">USB Drive</div>
          <div class="ext-target" data-m="usb_target">—</div>
        </div>
      </div>
      <div class="ext-status">
        <span class="ext-status-dot" id="ext-status-dot"></span>
        <span id="ext-status-text" class="ext-status-text">Checking…</span>
      </div>
    </div>

    <div class="ext-body">
      <div class="ext-capacity">
        <div class="ext-capacity-header">
          <span class="ext-capacity-label">Capacity</span>
          <span class="ext-capacity-pct" data-m="usb_capacity_pct">—</span>
        </div>
        <div class="bar-track"><div class="bar-fill" data-bar="usb_pct" style="width:0%"></div></div>
        <div class="ext-capacity-meta">
          <span><span data-m="usb_used_fmt">—</span> used</span>
          <span><span data-m="usb_total_fmt">—</span> total</span>
        </div>
      </div>

      <div class="ext-props">
        <div class="ext-prop"><span class="ext-prop-key">Role</span><span class="ext-prop-val">Backup + per-user archive</span></div>
        <div class="ext-prop"><span class="ext-prop-key">Backups mirrored</span><span class="ext-prop-val" data-m="usb_count">—</span></div>
        <div class="ext-prop"><span class="ext-prop-key">User archives</span><span class="ext-prop-val"><span data-m="usb_users_archived">—</span> users · <span data-m="usb_user_files">—</span> files</span></div>
        <div class="ext-prop"><span class="ext-prop-key">User data size</span><span class="ext-prop-val" data-m="usb_user_bytes">—</span></div>
        <div class="ext-prop"><span class="ext-prop-key">Orphaned archives</span><span class="ext-prop-val" data-m="usb_orphans">—</span></div>
        <div class="ext-prop"><span class="ext-prop-key">Last sync</span><span class="ext-prop-val" data-m="usb_last_sync">—</span></div>
        <div class="ext-prop"><span class="ext-prop-key">Last write</span><span class="ext-prop-val" data-m="usb_last_write">—</span></div>
        <div class="ext-prop"><span class="ext-prop-key">Sync interval</span><span class="ext-prop-val"><span data-m="usb_poll_s">—</span>s</span></div>
      </div>
    </div>
  </div>

  <!-- Active sessions panel -->
  <div class="panel" style="margin-bottom:14px;">
    <div class="panel-header">Active Sessions <span style="color:var(--muted);font-weight:400;margin-left:6px;">(last 30 min)</span></div>
    <div id="active-sessions-body">
      <?php if ($active_count === 0): ?>
        <div class="empty">No recent logins.</div>
      <?php else: ?>
        <ul class="upload-list">
          <?php foreach ($active_users as $au): ?>
          <li class="upload-item">
            <span class="upload-icon" style="<?= $au['role']==='admin' ? 'color:#00bfff;background:rgba(0,191,255,0.08);border-color:rgba(0,191,255,0.25);' : '' ?>">
              <?= $au['role'] === 'admin' ? 'ADM' : 'USR' ?>
            </span>
            <span class="upload-name"><?= htmlspecialchars($au['username']) ?></span>
            <span class="upload-meta"><?= fmt_ago($au['last_login']) ?></span>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php endif; ?>
    </div>
  </div>

  <!-- Bottom panels -->
  <div class="bottom-grid">

    <!-- Recent uploads -->
    <div class="panel">
      <div class="panel-header">Recent Uploads</div>
      <?php if (empty($recent)): ?>
        <div class="empty">No files uploaded yet.</div>
      <?php else: ?>
      <ul class="upload-list">
        <?php foreach ($recent as $f): ?>
        <li class="upload-item">
          <span class="upload-icon"><?= file_icon($f['filetype'] ?? '') ?></span>
          <span class="upload-name" title="<?= htmlspecialchars($f['filename']) ?>"><?= htmlspecialchars($f['filename']) ?></span>
          <span class="upload-meta">
            <?= fmt((int)$f['filesize']) ?><br>
            <?= htmlspecialchars($f['username']) ?> · <?= date('M j', strtotime($f['created_at'])) ?>
          </span>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

    <!-- Per-user storage -->
    <div class="panel">
      <div class="panel-header">Storage by User</div>
      <?php if (empty($user_storage)): ?>
        <div class="empty">No users yet.</div>
      <?php else:
        $max = max(array_column($user_storage, 'used')) ?: 1;
      ?>
      <ul class="user-list">
        <?php foreach ($user_storage as $u): ?>
        <li class="user-item">
          <div class="user-row">
            <span class="user-name"><?= htmlspecialchars($u['username']) ?>
              <?php if ($u['role'] === 'admin'): ?>
                <span style="font-size:10px;color:var(--accent);font-family:'Space Mono',monospace;margin-left:4px">ADMIN</span>
              <?php endif; ?>
            </span>
            <span class="user-size">
              <?= fmt((int)$u['used']) ?>
              <?= $u['storage_quota'] ? ' / ' . fmt((int)$u['storage_quota']) : ' / unlimited' ?>
              · <?= $u['file_count'] ?> files
            </span>
          </div>
          <?php
            $quota = $u['storage_quota'];
            if ($quota) {
                $pct   = min(100, round($u['used'] / $quota * 100));
                $color = $pct >= 90 ? 'var(--danger)' : ($pct >= 70 ? 'var(--warn)' : null);
          ?>
          <div class="mini-bar-track">
            <div class="mini-bar-fill" style="width:<?= $pct ?>%<?= $color ? ";background:$color" : '' ?>"></div>
          </div>
          <?php } else { ?>
          <div style="font-size:10px;color:var(--muted);font-family:'Space Mono',monospace;">no quota set · unlimited</div>
          <?php } ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

  </div>
</main>

<script src="https://cdn.jsdelivr.net/npm/chart.js@4.4.0/dist/chart.umd.min.js"></script>
<script>
(function() {
  const POLL_MS = 3000;
  const HISTORY = 60;     // ~3 min at 3s
  const history = { cpu: [], mem: [], load: [], labels: [] };
  let charts = null;

  function pulse(el) {
    if (!el) return;
    el.style.transition = 'color 0.3s';
    const orig = el.style.color;
    el.style.color = 'var(--accent)';
    setTimeout(() => { el.style.color = orig; }, 300);
  }

  function classFor(pct, thresholds) {
    if (pct >= thresholds.danger) return 'danger';
    if (pct >= thresholds.warn)   return 'warn';
    return 'ok';
  }

  function applyClass(el, cls) {
    if (!el) return;
    el.classList.remove('ok', 'warn', 'danger');
    el.classList.add(cls);
  }

  async function poll() {
    let data;
    try {
      const r = await fetch('/monitor_data.php', { credentials: 'same-origin' });
      if (!r.ok) throw new Error(r.status);
      data = await r.json();
    } catch (e) {
      // Silently skip — try again next tick. Live dot dims.
      const dot = document.getElementById('live-dot');
      if (dot) dot.style.background = 'var(--muted)';
      return;
    }
    const dot = document.getElementById('live-dot');
    if (dot) dot.style.background = 'var(--accent)';

    // Update every [data-m] element. Special keys handled separately below.
    const usb = data.usb || {};
    const composite = {
      load_avg: `${data.load1} · ${data.load5} · ${data.load15}`,
      last_auto_ago:  data.last_auto ? data.last_auto.ago  : 'never',
      last_auto_size: data.last_auto ? data.last_auto.size : 'no backups yet',
      usb_target:        usb.target || 'No external storage detected',
      usb_capacity_pct:  usb.total_bytes > 0 ? `${usb.pct}%` : '—',
      usb_used_fmt:      usb.used_fmt || '—',
      usb_total_fmt:     usb.total_fmt || '—',
      usb_count:         usb.count != null ? usb.count : '—',
      usb_last_sync:     usb.last_sync_ago || 'never',
      usb_last_write:    usb.last_write_ago || 'idle',
      usb_poll_s:        usb.poll_s || 3,
      usb_users_archived: usb.users_archived != null ? usb.users_archived : '—',
      usb_user_files:     usb.user_files_mirrored != null ? usb.user_files_mirrored : '—',
      usb_user_bytes:     usb.user_bytes_fmt || '—',
      usb_orphans:        usb.orphan_users > 0
                            ? `${usb.orphan_users} user(s) · ${usb.orphan_user_files} file(s) · ${usb.orphan_bytes_fmt}`
                            : 'none',
    };
    document.querySelectorAll('[data-m]').forEach(el => {
      const key = el.dataset.m;
      let val;
      if (key in composite) val = composite[key];
      else if (key in data) val = data[key];
      else return;
      const suffix = el.dataset.suffix || '';
      const next = String(val) + suffix;
      if (el.textContent !== next) {
        el.textContent = next;
        pulse(el);
      }
    });

    // Bar widths + colour classes
    const updateBar = (selector, pct, thresholds) => {
      const fill = document.querySelector(`[data-bar="${selector}"]`);
      const pctEl = document.querySelector(`[data-m="${selector}"]`);
      if (fill) fill.style.width = pct + '%';
      const cls = classFor(pct, thresholds);
      applyClass(fill, cls);
      applyClass(pctEl, cls);
    };
    updateBar('cpu',          data.cpu,          { warn: 60, danger: 80 });
    updateBar('mem_pct',      data.mem_pct,      { warn: 65, danger: 85 });
    updateBar('uploads_pct',  data.uploads_pct,  { warn: 65, danger: 85 });
    updateBar('backups_pct',  data.backups_pct,  { warn: 65, danger: 85 });
    if (usb.total_bytes > 0) {
      updateBar('usb_pct', usb.pct, { warn: 70, danger: 90 });
    }

    // External Storage panel — connection state + write pulse
    const extPanel  = document.getElementById('ext-storage-panel');
    const extStatus = document.getElementById('ext-status-text');
    const extDot    = document.getElementById('ext-status-dot');
    if (extPanel) {
      const wasActive = extPanel.dataset.active === '1';
      const isActive  = !!usb.active;
      extPanel.dataset.active = isActive ? '1' : '0';
      if (extStatus) {
        if (isActive)            extStatus.textContent = 'Connected';
        else if (usb.target)     extStatus.textContent = 'Disconnected';
        else                     extStatus.textContent = 'Not configured';
      }
      // Pulse the status dot when a write just happened
      if (isActive && usb.recently_active && extDot) {
        extDot.classList.remove('writing');
        void extDot.offsetWidth; // restart animation
        extDot.classList.add('writing');
      }
    }
    // Capacity colour mirrors warn/danger thresholds
    const usbPctEl = document.querySelector('[data-m="usb_capacity_pct"]');
    if (usbPctEl) {
      usbPctEl.classList.remove('warn', 'danger');
      if (usb.pct >= 90)      usbPctEl.classList.add('danger');
      else if (usb.pct >= 70) usbPctEl.classList.add('warn');
    }

    // Active sessions list
    const body = document.getElementById('active-sessions-body');
    if (body) {
      if (data.active_count === 0) {
        body.innerHTML = '<div class="empty">No recent logins.</div>';
      } else {
        body.innerHTML = '<ul class="upload-list">' + data.active_users.map(u => {
          const isAdmin = u.role === 'admin';
          const style = isAdmin ? 'color:#00bfff;background:rgba(0,191,255,0.08);border-color:rgba(0,191,255,0.25);' : '';
          const tag = isAdmin ? 'ADM' : 'USR';
          const name = u.username.replace(/[<>&]/g, c => ({ '<': '&lt;', '>': '&gt;', '&': '&amp;' })[c]);
          return `<li class="upload-item"><span class="upload-icon" style="${style}">${tag}</span><span class="upload-name">${name}</span><span class="upload-meta">${u.ago}</span></li>`;
        }).join('') + '</ul>';
      }
    }

    // Push to history buffers
    const t = new Date(data.ts * 1000);
    const label = t.toLocaleTimeString([], { hour: '2-digit', minute: '2-digit', second: '2-digit' });
    history.labels.push(label);
    history.cpu.push(data.cpu);
    history.mem.push(data.mem_pct);
    history.load.push(parseFloat(data.load1));
    if (history.labels.length > HISTORY) {
      history.labels.shift(); history.cpu.shift(); history.mem.shift(); history.load.shift();
    }
    if (charts) {
      charts.cpu.data.labels = history.labels;  charts.cpu.data.datasets[0].data  = history.cpu;  charts.cpu.update('none');
      charts.mem.data.labels = history.labels;  charts.mem.data.datasets[0].data  = history.mem;  charts.mem.update('none');
      charts.load.data.labels = history.labels; charts.load.data.datasets[0].data = history.load; charts.load.update('none');
    }
  }

  function makeChart(canvasId, color, max) {
    return new Chart(document.getElementById(canvasId), {
      type: 'line',
      data: { labels: history.labels.slice(), datasets: [{
        data: history.cpu.slice(), borderColor: color, backgroundColor: color + '22',
        fill: true, tension: 0.3, pointRadius: 0, borderWidth: 2,
      }]},
      options: {
        responsive: true, maintainAspectRatio: false, animation: false,
        plugins: { legend: { display: false }, tooltip: { mode: 'index', intersect: false, displayColors: false } },
        scales: {
          x: { display: false },
          y: { beginAtZero: true, max: max, display: false, grid: { display: false } }
        },
        elements: { line: { borderWidth: 1.5 } }
      }
    });
  }

  document.getElementById('toggle-charts').addEventListener('click', () => {
    const panel = document.getElementById('charts-panel');
    const label = document.getElementById('toggle-charts-label');
    const opening = !panel.classList.contains('open');
    panel.classList.toggle('open');
    label.textContent = opening ? 'Hide Charts' : 'Show Charts';
    if (opening && !charts) {
      // Wait for the slide-down to finish before initializing charts so they
      // measure their parent's full height instead of 0.
      setTimeout(() => {
        charts = {
          cpu:  makeChart('chart-cpu',  '#4fffb0', 100),
          mem:  makeChart('chart-mem',  '#00bfff', 100),
          load: makeChart('chart-load', '#ffb84f', null),
        };
        charts.mem.data.datasets[0].data  = history.mem;
        charts.load.data.datasets[0].data = history.load;
      }, 360);
    } else if (opening && charts) {
      // Already initialized — just trigger a resize after the panel reopens.
      setTimeout(() => { charts.cpu.resize(); charts.mem.resize(); charts.load.resize(); }, 360);
    }
  });

  poll();
  setInterval(poll, POLL_MS);
})();
</script>

</body>
</html>
