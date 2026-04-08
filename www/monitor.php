<?php
require_once 'auth.php';
require_admin();
require_once 'db.php';

$user = current_user();

// ── Disk usage ──────────────────────────────────────────
$upload_dir  = '/var/www/uploads';
$total_disk  = disk_total_space('/');
$free_disk   = disk_free_space('/');
$used_disk   = $total_disk - $free_disk;
$disk_pct    = $total_disk > 0 ? round(($used_disk / $total_disk) * 100, 1) : 0;

// Upload folder size
function dir_size(string $path): int {
    $size = 0;
    if (!is_dir($path)) return 0;
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS)) as $f) {
        $size += $f->getSize();
    }
    return $size;
}
$uploads_used = dir_size($upload_dir);

function fmt($bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1)    . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 1)       . ' KB';
    return $bytes . ' B';
}

// ── CPU & memory (Linux /proc) ───────────────────────────
function cpu_usage(): float {
    // Two samples 500ms apart for a real reading
    $s1 = file('/proc/stat')[0];
    usleep(500000);
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
    $info = [];
    foreach (file('/proc/meminfo') as $line) {
        [$key, $val] = explode(':', $line);
        $info[trim($key)] = (int)trim(str_replace(' kB', '', $val)) * 1024;
    }
    $total     = $info['MemTotal']     ?? 0;
    $available = $info['MemAvailable'] ?? 0;
    $used      = $total - $available;
    return ['total' => $total, 'used' => $used, 'pct' => $total > 0 ? round(($used / $total) * 100, 1) : 0];
}

$cpu = cpu_usage();
$mem = mem_info();

// ── DB stats ─────────────────────────────────────────────
$total_users = $pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$total_files = $pdo->query('SELECT COUNT(*) FROM files WHERE is_folder = 0')->fetchColumn();
$total_folders = $pdo->query('SELECT COUNT(*) FROM files WHERE is_folder = 1')->fetchColumn();

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
    SELECT u.username, u.role, COALESCE(SUM(f.filesize),0) AS used, COUNT(f.id) AS file_count
    FROM users u
    LEFT JOIN files f ON f.owner_id = u.id AND f.is_folder = 0
    GROUP BY u.id
    ORDER BY used DESC
')->fetchAll();

function file_icon(string $type): string {
    if (str_starts_with($type, 'image/')) return '🖼️';
    if (str_starts_with($type, 'video/')) return '🎬';
    if (str_starts_with($type, 'audio/')) return '🎵';
    if ($type === 'application/pdf')       return '📄';
    if (str_contains($type, 'zip') || str_contains($type, 'tar')) return '🗜️';
    return '📃';
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
  .nav-link.active { color: var(--accent); }
  .nav-user { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--muted); }
  .nav-user strong { color: var(--text); }
  .nav-user a { color: var(--muted); text-decoration: none; font-size: 12px; padding: 4px 10px; border: 1px solid var(--border); border-radius: var(--radius); transition: border-color 0.15s, color 0.15s; }
  .nav-user a:hover { border-color: var(--danger); color: var(--danger); }

  main { padding: 28px; max-width: 1100px; width: 100%; margin: 0 auto; flex: 1; }

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
  .upload-icon { font-size: 18px; flex-shrink: 0; }
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
    <a class="nav-link" href="/">📁 Files</a>
    <?php if (is_admin()): ?>
    <a class="nav-link" href="/users.php">👥 Users</a>
    <?php endif; ?>
    <a class="nav-link active" href="/monitor.php">📊 Monitor</a>
  </div>
  <div class="nav-user">
    <span>Hello, <strong><?= htmlspecialchars($user['username']) ?></strong></span>
    <?php if (is_admin()): ?><span style="color:var(--accent);font-size:11px;font-family:'Space Mono',monospace">ADMIN</span><?php endif; ?>
    <a href="/logout.php">Sign out</a>
  </div>
</nav>

<main>
  <h1>System Monitor</h1>

  <!-- Stat cards -->
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
      <div class="stat-value" style="font-size:20px"><?= fmt($uploads_used) ?></div>
      <div class="stat-sub">in /uploads</div>
    </div>
    <div class="stat-card">
      <div class="stat-label">Disk Free</div>
      <div class="stat-value" style="font-size:20px"><?= fmt($free_disk) ?></div>
      <div class="stat-sub">of <?= fmt($total_disk) ?> total</div>
    </div>
  </div>

  <!-- Gauges -->
  <div class="gauge-grid">
    <?php
      $cpu_class = $cpu >= 80 ? 'danger' : ($cpu >= 60 ? 'warn' : 'ok');
      $mem_class = $mem['pct'] >= 85 ? 'danger' : ($mem['pct'] >= 65 ? 'warn' : 'ok');
      $dsk_class = $disk_pct >= 85 ? 'danger' : ($disk_pct >= 65 ? 'warn' : 'ok');
    ?>
    <div class="gauge-card">
      <div class="gauge-header">
        <span class="gauge-title">⚡ CPU Usage</span>
        <span class="gauge-pct <?= $cpu_class ?>"><?= $cpu ?>%</span>
      </div>
      <div class="bar-track"><div class="bar-fill <?= $cpu_class ?>" style="width:<?= $cpu ?>%"></div></div>
      <div class="gauge-meta"><span>0%</span><span>100%</span></div>
    </div>

    <div class="gauge-card">
      <div class="gauge-header">
        <span class="gauge-title">🧠 Memory</span>
        <span class="gauge-pct <?= $mem_class ?>"><?= $mem['pct'] ?>%</span>
      </div>
      <div class="bar-track"><div class="bar-fill <?= $mem_class ?>" style="width:<?= $mem['pct'] ?>%"></div></div>
      <div class="gauge-meta"><span><?= fmt($mem['used']) ?> used</span><span><?= fmt($mem['total']) ?> total</span></div>
    </div>

    <div class="gauge-card">
      <div class="gauge-header">
        <span class="gauge-title">💾 Disk Usage</span>
        <span class="gauge-pct <?= $dsk_class ?>"><?= $disk_pct ?>%</span>
      </div>
      <div class="bar-track"><div class="bar-fill <?= $dsk_class ?>" style="width:<?= $disk_pct ?>%"></div></div>
      <div class="gauge-meta"><span><?= fmt($used_disk) ?> used</span><span><?= fmt($total_disk) ?> total</span></div>
    </div>
  </div>

  <!-- Bottom panels -->
  <div class="bottom-grid">

    <!-- Recent uploads -->
    <div class="panel">
      <div class="panel-header">🕐 Recent Uploads</div>
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
      <div class="panel-header">👤 Storage by User</div>
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
            <span class="user-size"><?= fmt((int)$u['used']) ?> · <?= $u['file_count'] ?> files</span>
          </div>
          <div class="mini-bar-track">
            <div class="mini-bar-fill" style="width:<?= $u['used'] > 0 ? round(($u['used']/$max)*100) : 0 ?>%"></div>
          </div>
        </li>
        <?php endforeach; ?>
      </ul>
      <?php endif; ?>
    </div>

  </div>
</main>

</body>
</html>
