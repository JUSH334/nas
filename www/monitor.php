<?php
require_once 'auth.php';
require_admin();
require_once 'db.php';

$user = current_user();

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
      <p class="hero-sub">Real-time insights into your NAS server, storage volumes, and active sessions.</p>
    </div>
    <div class="hero-stat">
      <span class="hero-stat-value"><?= fmt_uptime($uptime_s) ?></span>
      <span class="hero-stat-label">Uptime</span>
    </div>
  </div>

  <h1 style="font-size:16px;font-weight:500;margin-bottom:18px;color:var(--muted);">Server</h1>

  <!-- Server stat cards -->
  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-label">Uptime</div>
      <div class="stat-value" style="font-size:20px"><?= fmt_uptime($uptime_s) ?></div>
      <div class="stat-sub">since last server restart</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Load Average</div>
      <div class="stat-value" style="font-size:18px"><?= $load['1'] ?> · <?= $load['5'] ?> · <?= $load['15'] ?></div>
      <div class="stat-sub"><?= $cpu_cores ?> cores · 1m / 5m / 15m</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Active Sessions</div>
      <div class="stat-value"><?= $active_count ?></div>
      <div class="stat-sub">logged in last 30 min</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Last Auto Backup</div>
      <div class="stat-value" style="font-size:18px"><?= $last_auto ? fmt_ago($last_auto['created_at']) : 'never' ?></div>
      <div class="stat-sub"><?= $last_auto ? fmt((int)$last_auto['filesize']) : 'no backups yet' ?></div>
    </div>
  </div>

  <h1 style="font-size:16px;font-weight:500;margin-bottom:18px;color:var(--muted);">Storage</h1>

  <!-- Storage stat cards -->
  <div class="stat-grid">
    <div class="stat-card">
      <div class="stat-label">Total Users</div>
      <div class="stat-value"><?= $total_users ?></div>
      <div class="stat-sub">registered accounts</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Files Stored</div>
      <div class="stat-value"><?= $total_files ?></div>
      <div class="stat-sub"><?= $total_folders ?> folders</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Uploads Size</div>
      <div class="stat-value" style="font-size:20px"><?= fmt($uploads_db) ?></div>
      <div class="stat-sub">across all users</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Backups Size</div>
      <div class="stat-value" style="font-size:20px"><?= fmt($backups_db) ?></div>
      <div class="stat-sub"><?= $backups_count ?> archives</div>
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
        <span class="gauge-pct <?= $up_class ?>"><?= $uploads_pct ?>%</span>
      </div>
      <div class="bar-track"><div class="bar-fill <?= $up_class ?>" style="width:<?= $uploads_pct ?>%"></div></div>
      <div class="gauge-meta"><span><?= fmt($uploads_used) ?> used</span><span><?= fmt($uploads_total) ?> total</span></div>
    </div>

    <?php if (!$same_volume): ?>
    <div class="gauge-card">
      <div class="gauge-header">
        <span class="gauge-title">Backups Volume</span>
        <span class="gauge-pct <?= $bk_class ?>"><?= $backups_pct ?>%</span>
      </div>
      <div class="bar-track"><div class="bar-fill <?= $bk_class ?>" style="width:<?= $backups_pct ?>%"></div></div>
      <div class="gauge-meta"><span><?= fmt($backups_used) ?> used</span><span><?= fmt($backups_total) ?> total</span></div>
    </div>
    <?php endif; ?>

    <div class="gauge-card">
      <div class="gauge-header">
        <span class="gauge-title">CPU Usage</span>
        <span class="gauge-pct <?= $cpu_class ?>"><?= $cpu ?>%</span>
      </div>
      <div class="bar-track"><div class="bar-fill <?= $cpu_class ?>" style="width:<?= $cpu ?>%"></div></div>
      <div class="gauge-meta"><span>load 1m: <?= $load['1'] ?> (<?= $load1_pct ?>% of <?= $cpu_cores ?>c)</span><span>0–100%</span></div>
    </div>

    <div class="gauge-card">
      <div class="gauge-header">
        <span class="gauge-title">Memory</span>
        <span class="gauge-pct <?= $mem_class ?>"><?= $mem['pct'] ?>%</span>
      </div>
      <div class="bar-track"><div class="bar-fill <?= $mem_class ?>" style="width:<?= $mem['pct'] ?>%"></div></div>
      <div class="gauge-meta"><span><?= fmt($mem['used']) ?> used</span><span><?= fmt($mem['total']) ?> total</span></div>
    </div>
  </div>

  <?php if ($same_volume): ?>
  <p style="font-size:11px;color:var(--muted);margin:-14px 0 22px;font-family:'Space Mono',monospace;">
    NOTE — uploads and backups are bind-mounted to the same host disk, so they share one volume gauge.
  </p>
  <?php endif; ?>

  <!-- Active sessions panel -->
  <div class="panel" style="margin-bottom:14px;">
    <div class="panel-header">Active Sessions <span style="color:var(--muted);font-weight:400;margin-left:6px;">(last 30 min)</span></div>
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
          <?php } else {
            $bar_pct = $max > 0 ? round(($u['used']/$max)*100) : 0;
          ?>
          <div class="mini-bar-track">
            <div class="mini-bar-fill" style="width:<?= $bar_pct ?>%"></div>
          </div>
          <?php } ?>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

  </div>
</main>

</body>
</html>
