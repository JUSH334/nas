<?php
require_once 'auth.php';
require_admin();
require_once 'db.php';

$user = current_user();

// ── USB sync status (read-only — actual sync runs host-side) ────
// The host scheduled task (scripts/mirror_to_usb.ps1) writes a heartbeat
// file in /var/www/backups after each successful USB mirror. We read it
// to know whether the USB is currently being mirrored.
function usb_sync_status(): array {
    $heartbeat = '/var/www/backups/.usb_sync_status';
    if (!file_exists($heartbeat)) return ['active' => false, 'last' => null, 'count' => 0];
    $age = time() - filemtime($heartbeat);
    $data = @json_decode(@file_get_contents($heartbeat), true) ?: [];
    return [
        'active' => $age < 900,           // heartbeat fresh within 15 minutes
        'age_s'  => $age,
        'last'   => $data['last_sync'] ?? null,
        'count'  => (int)($data['files_mirrored'] ?? 0),
        'target' => $data['target_path']  ?? '',
    ];
}

function usb_destination_active(): bool {
    return usb_sync_status()['active'];
}

// ── Handle actions ──────────────────────────────────────
$message = '';
$error   = '';

// Create backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    $backup_dir = '/var/www/backups';
    if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);

    $timestamp = date('Y-m-d_H-i-s');
    $backup_name = "nas_backup_{$timestamp}";
    $backup_path = "{$backup_dir}/{$backup_name}";
    mkdir($backup_path, 0755, true);

    // 1. Dump the database
    $db_host = 'db';
    $db_name = $_ENV['MYSQL_DATABASE'] ?? getenv('MYSQL_DATABASE') ?? 'nas_db';
    $db_user = $_ENV['MYSQL_USER']     ?? getenv('MYSQL_USER')     ?? 'nas_user';
    $db_pass = $_ENV['MYSQL_PASSWORD'] ?? getenv('MYSQL_PASSWORD') ?? '';

    $dump_file = "{$backup_path}/database.sql";
    $cmd = sprintf(
        'mysqldump --skip-ssl --no-tablespaces -h %s -u %s -p%s %s > %s 2>&1',
        escapeshellarg($db_host),
        escapeshellarg($db_user),
        escapeshellarg($db_pass),
        escapeshellarg($db_name),
        escapeshellarg($dump_file)
    );
    exec($cmd, $output, $return_code);

    // 2. Copy uploaded files
    $upload_src = '/var/www/uploads';
    $upload_dst = "{$backup_path}/uploads";
    if (is_dir($upload_src)) {
        exec("cp -r " . escapeshellarg($upload_src) . " " . escapeshellarg($upload_dst));
    }

    // 3. Create a zip archive
    $zip_file = "{$backup_dir}/{$backup_name}.zip";
    exec("cd " . escapeshellarg($backup_path) . " && zip -r " . escapeshellarg($zip_file) . " . 2>&1", $zip_out, $zip_code);

    // Clean up the unzipped folder
    exec("rm -rf " . escapeshellarg($backup_path));

    if ($return_code === 0 && file_exists($zip_file)) {
        // Record in DB
        $size = filesize($zip_file);
        $stmt = $pdo->prepare('INSERT INTO backups (filename, filepath, filesize, created_by) VALUES (?, ?, ?, ?)');
        $stmt->execute(["{$backup_name}.zip", $zip_file, $size, $user['id']]);
        $message = "Backup created successfully: {$backup_name}.zip";

        // Log to backup log (USB mirror happens out-of-band via scheduled task)
        $log_entry = date('Y-m-d H:i:s') . " OK: Manual backup created by {$user['username']}: {$backup_name}.zip (" . round($size / 1024) . " KB)\n";
        file_put_contents('/var/log/backup_cron.log', $log_entry, FILE_APPEND);
    } else {
        $error = "Backup failed. MySQL dump returned code {$return_code}.";
        $log_entry = date('Y-m-d H:i:s') . " ERROR: Manual backup by {$user['username']} failed (code {$return_code})\n";
        file_put_contents('/var/log/backup_cron.log', $log_entry, FILE_APPEND);
    }
}

// Delete backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['backup_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM backups WHERE id = ?');
    $stmt->execute([$id]);
    $backup = $stmt->fetch();
    if ($backup) {
        if (file_exists($backup['filepath'])) unlink($backup['filepath']);
        $pdo->prepare('DELETE FROM backups WHERE id = ?')->execute([$id]);
        $message = "Backup deleted.";
    }
}

// Restore backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'restore') {
    $id = (int)($_POST['backup_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM backups WHERE id = ?');
    $stmt->execute([$id]);
    $backup = $stmt->fetch();

    if ($backup && file_exists($backup['filepath'])) {
        $restore_dir = '/tmp/nas_restore_' . time();
        mkdir($restore_dir, 0755, true);

        // Extract zip
        exec("unzip -o " . escapeshellarg($backup['filepath']) . " -d " . escapeshellarg($restore_dir) . " 2>&1");

        // Restore database
        $db_host = 'db';
        $db_name = $_ENV['MYSQL_DATABASE'] ?? getenv('MYSQL_DATABASE') ?? 'nas_db';
        $db_user = $_ENV['MYSQL_USER']     ?? getenv('MYSQL_USER')     ?? 'nas_user';
        $db_pass = $_ENV['MYSQL_PASSWORD'] ?? getenv('MYSQL_PASSWORD') ?? '';

        $dump_file = "{$restore_dir}/database.sql";
        if (file_exists($dump_file)) {
            $cmd = sprintf(
                'mysql --skip-ssl -h %s -u %s -p%s %s < %s 2>&1',
                escapeshellarg($db_host),
                escapeshellarg($db_user),
                escapeshellarg($db_pass),
                escapeshellarg($db_name),
                escapeshellarg($dump_file)
            );
            exec($cmd, $output, $return_code);
        }

        // Restore uploaded files
        $upload_restore = "{$restore_dir}/uploads";
        if (is_dir($upload_restore)) {
            exec("rm -rf /var/www/uploads/*");
            exec("cp -r {$upload_restore}/* /var/www/uploads/ 2>/dev/null");
        }

        // Clean up
        exec("rm -rf " . escapeshellarg($restore_dir));

        $message = "Restore completed from: " . htmlspecialchars($backup['filename']);
    } else {
        $error = "Backup file not found.";
    }
}

// Schedule backup
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'schedule') {
    $frequency = $_POST['frequency'] ?? 'none';
    // Parse HH:MM from time_slot
    $time_slot = $_POST['time_slot'] ?? '02:00';
    [$hour, $minute] = array_pad(explode(':', $time_slot), 2, '0');
    $hour   = max(0, min(23, (int)$hour));
    $minute = max(0, min(59, (int)$minute));
    $weekdays  = $_POST['weekdays'] ?? [];           // array of 0-6
    $days_of_month = $_POST['days_of_month'] ?? [];

    $cron_expr = '';
    if ($frequency === 'daily') {
        $cron_expr = "$minute $hour * * *";
    } elseif ($frequency === 'weekly') {
        // Filter to 0-6 and build comma-separated list
        $days = array_filter(array_map('intval', $weekdays), fn($d) => $d >= 0 && $d <= 6);
        $days = array_unique($days);
        if (empty($days)) $days = [0]; // default Sunday
        sort($days);
        $cron_expr = "$minute $hour * * " . implode(',', $days);
    } elseif ($frequency === 'monthly') {
        $dom = array_filter(array_map('intval', $days_of_month), fn($d) => $d >= 1 && $d <= 31);
        $dom = array_unique($dom);
        if (empty($dom)) $dom = [1];
        sort($dom);
        $cron_expr = "$minute $hour " . implode(',', $dom) . " * *";
    }

    $cron_job = "$cron_expr php /var/www/html/cron_backup.php >> /var/log/backup_cron.log 2>&1";

    exec("crontab -l 2>/dev/null | grep -v 'cron_backup.php' | crontab - 2>&1");

    if ($cron_expr !== '') {
        exec("(crontab -l 2>/dev/null; echo '$cron_job') | crontab - 2>&1");
        $message = "Backup schedule saved: $cron_expr";
    } else {
        $message = "Automatic backups disabled.";
    }
}

// Read current schedule
$current_schedule = 'none';
$current_time = '02:00';
$current_weekdays = [0];  // Sunday by default
$current_days_of_month = [1];
$cron_output = [];
exec('crontab -l 2>/dev/null', $cron_output);
foreach ($cron_output as $line) {
    if (str_contains($line, 'cron_backup.php')) {
        if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\S+)\s+(\S+)\s+(\S+)\s+/', $line, $m)) {
            $min  = (int)$m[1];
            $hour = (int)$m[2];
            $day  = $m[3];
            $week = $m[5];
            $current_time = sprintf('%02d:%02d', $hour, $min);

            if ($day !== '*' && $day !== '?') {
                $current_schedule = 'monthly';
                $current_days_of_month = array_map('intval', explode(',', $day));
            } elseif ($week !== '*' && $week !== '?') {
                $current_schedule = 'weekly';
                $current_weekdays = array_map('intval', explode(',', $week));
            } else {
                $current_schedule = 'daily';
            }
        }
        break;
    }
}

// Download backup
if (isset($_GET['download'])) {
    $id = (int)$_GET['download'];
    $stmt = $pdo->prepare('SELECT * FROM backups WHERE id = ?');
    $stmt->execute([$id]);
    $backup = $stmt->fetch();
    if ($backup && file_exists($backup['filepath'])) {
        header('Content-Type: application/zip');
        header('Content-Disposition: attachment; filename="' . $backup['filename'] . '"');
        header('Content-Length: ' . filesize($backup['filepath']));
        readfile($backup['filepath']);
        exit;
    }
}

// List existing backups
$backups = $pdo->query('
    SELECT b.*, u.username AS created_by_name
    FROM backups b
    LEFT JOIN users u ON b.created_by = u.id
    ORDER BY b.created_at DESC
')->fetchAll();

function fmt_size($bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1)    . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 1)       . ' KB';
    return $bytes . ' B';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NAS — Backup &amp; Restore</title>
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

  .alert {
    padding: 12px 18px; border-radius: var(--radius); font-size: 13px; margin-bottom: 20px;
  }
  .alert-success { background: rgba(79,255,176,0.1); border: 1px solid var(--accent); color: var(--accent); }
  .alert-error   { background: rgba(255,79,106,0.1); border: 1px solid var(--danger); color: var(--danger); }

  /* Create backup card */
  .create-card {
    background: var(--surface); border: 1px solid var(--border); border-radius: 10px;
    padding: 24px; margin-bottom: 28px; display: flex; align-items: center; justify-content: space-between;
  }
  .create-info h2 { font-size: 15px; font-weight: 500; margin-bottom: 6px; }
  .create-info p  { font-size: 13px; color: var(--muted); }

  .btn {
    padding: 8px 18px; border: none; border-radius: var(--radius); cursor: pointer;
    font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500;
    transition: opacity 0.15s;
  }
  .btn:hover { opacity: 0.92; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(79,255,176,0.15); }
  .btn:active { transform: translateY(0); box-shadow: 0 1px 4px rgba(79,255,176,0.12); }
  .btn-link:hover { box-shadow: 0 4px 14px rgba(79,255,176,0.08); border-color: rgba(79,255,176,0.3); transform: translateY(-1px); }
  .btn-danger:hover { box-shadow: 0 4px 14px rgba(255,79,106,0.15); border-color: rgba(255,79,106,0.5); }
  .btn-warn:hover { box-shadow: 0 4px 14px rgba(255,184,79,0.15); }
  .btn-primary { background: var(--accent); color: #0d0f14; }
  .btn-danger  { background: var(--danger); color: #fff; }
  .btn-warn    { background: var(--warn); color: #0d0f14; }
  .btn-small   { padding: 5px 12px; font-size: 12px; }
  .btn-link    { background: none; border: 1px solid var(--border); color: var(--text); }

  /* Backup list */
  .panel {
    background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden;
  }
  .panel-header {
    padding: 16px 20px; border-bottom: 1px solid var(--border); font-size: 13px; font-weight: 500;
  }

  .backup-list { list-style: none; }
  .backup-item {
    display: flex; align-items: center; gap: 16px;
    padding: 14px 20px; border-bottom: 1px solid rgba(42,45,56,0.5);
  }
  .backup-item:last-child { border-bottom: none; }
  .backup-icon { color: var(--accent); flex-shrink: 0; display: flex; align-items: center; justify-content: center; width: 36px; height: 36px; background: rgba(79,255,176,0.08); border: 1px solid rgba(79,255,176,0.2); border-radius: 6px; }
  .backup-info { flex: 1; }
  .backup-name { font-weight: 500; font-size: 14px; margin-bottom: 3px; }
  .backup-meta { font-size: 12px; color: var(--muted); }
  .backup-actions { display: flex; gap: 6px; flex-shrink: 0; }

  .empty { padding: 40px 20px; text-align: center; color: var(--muted); font-size: 13px; }

  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
  }
  .create-card, .panel {
    animation: fadeUp 0.4s ease both;
  }
  .create-card:nth-of-type(2) { animation-delay: 0.08s; }
  .panel { animation-delay: 0.16s; }
  .backup-item {
    animation: fadeUp 0.35s ease both;
  }
  .backup-item:nth-child(1) { animation-delay: 0.20s; }
  .backup-item:nth-child(2) { animation-delay: 0.24s; }
  .backup-item:nth-child(3) { animation-delay: 0.28s; }
  .backup-item:nth-child(4) { animation-delay: 0.32s; }
  .backup-item:nth-child(n+5) { animation-delay: 0.36s; }

  /* Fix dropdown options to match dark theme */
  select option {
    background: var(--surface);
    color: var(--text);
    padding: 8px;
  }
  select { color-scheme: dark; }
  select:focus { border-color: var(--accent) !important; outline: none; }
  .time-select:hover { border-color: var(--accent); }

  /* Time picker component */
  .time-picker {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    padding: 4px 8px;
    transition: border-color 0.15s;
  }
  .time-picker:hover, .time-picker:focus-within { border-color: var(--accent); }

  .tp-field {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: 1px;
  }
  .tp-arrow {
    background: none;
    border: none;
    color: var(--muted);
    cursor: pointer;
    padding: 2px 6px;
    border-radius: 3px;
    display: flex;
    align-items: center;
    justify-content: center;
    transition: color 0.12s, background 0.12s;
  }
  .tp-arrow:hover { color: var(--accent); background: var(--surface2); }
  .tp-arrow:active { transform: scale(0.95); }

  .tp-input {
    background: transparent;
    border: none;
    color: var(--text);
    font-family: 'Space Mono', monospace;
    font-size: 16px;
    font-weight: 600;
    width: 28px;
    text-align: center;
    padding: 2px 0;
    outline: none;
  }
  .tp-input:focus { color: var(--accent); }
  .tp-input::-webkit-outer-spin-button,
  .tp-input::-webkit-inner-spin-button { -webkit-appearance: none; margin: 0; }

  .tp-sep {
    color: var(--muted);
    font-family: 'Space Mono', monospace;
    font-size: 16px;
    font-weight: 600;
    margin: 0 -2px;
  }

  .tp-ampm {
    background: var(--surface2);
    border: 1px solid var(--border);
    color: var(--text);
    font-family: 'Space Mono', monospace;
    font-size: 11px;
    font-weight: 700;
    letter-spacing: 0.05em;
    padding: 4px 8px;
    border-radius: 4px;
    cursor: pointer;
    margin-left: 6px;
    transition: all 0.12s;
  }
  .tp-ampm:hover { color: var(--accent); border-color: var(--accent); }

  .confirm-restore {
    display: none;
    background: var(--surface2); border: 1px solid var(--warn); border-radius: var(--radius);
    padding: 12px 16px; margin-top: 8px; font-size: 13px;
  }
  .confirm-restore.show { display: flex; align-items: center; gap: 12px; }
  .confirm-restore span { color: var(--warn); flex: 1; }
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
    <a class="nav-link" href="/logs.php">Logs</a>
    <a class="nav-link active" href="/backup.php">Backups</a>
  </div>
  <div class="nav-user">
    <a href="/profile.php" class="nav-user-link">Hello, <strong><?= htmlspecialchars($user['username']) ?></strong></a>
    <?php if (is_admin()): ?><span class="role-badge admin">Admin</span><?php endif; ?>
    <a href="/logout.php">Sign out</a>
  </div>
</nav>

<main>
  <?php $usb = usb_sync_status(); ?>
  <div class="page-hero">
    <div>
      <h1 class="hero-title">Data Protection</h1>
      <p class="hero-sub">
        Your files are safe. Create manual backups or schedule them automatically.
        <span id="usb-badge" style="display:inline-block;margin-left:10px;padding:3px 8px;border-radius:99px;font-size:11px;font-family:'Space Mono',monospace;vertical-align:middle;<?= $usb['active'] ? 'background:rgba(79,255,176,0.1);border:1px solid rgba(79,255,176,0.3);color:var(--accent);' : 'background:var(--surface2);border:1px solid var(--border);color:var(--muted);' ?>">
          <span id="usb-dot" style="display:inline-block;width:6px;height:6px;border-radius:50%;margin-right:5px;vertical-align:middle;<?= $usb['active'] ? 'background:var(--accent);box-shadow:0 0 6px var(--accent);' : 'background:var(--muted);' ?>"></span><span id="usb-text"><?= $usb['active'] ? 'USB mirror active · ' . $usb['count'] . ' file(s)' : 'USB mirror inactive' ?></span>
        </span>
      </p>
    </div>
    <div class="hero-stat">
      <span class="hero-stat-value"><?= count($backups) ?></span>
      <span class="hero-stat-label">Backups</span>
    </div>
  </div>

  <h1 style="font-size:16px;font-weight:500;margin-bottom:18px;color:var(--muted);">Backup &amp; Restore</h1>

  <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- Create backup -->
  <div class="create-card">
    <div class="create-info">
      <h2>Create New Backup</h2>
      <p>Backs up the database and all uploaded files into a downloadable ZIP archive.</p>
    </div>
    <form method="post">
      <input type="hidden" name="action" value="create">
      <button type="submit" class="btn btn-primary">Create Backup</button>
    </form>
  </div>

  <!-- Schedule automatic backups -->
  <div class="create-card" style="flex-direction:column;align-items:stretch;gap:16px;">
    <div class="create-info">
      <h2>Automatic Backups</h2>
      <p>Schedule recurring backups with flexible frequency. Currently:
        <strong style="color:var(--accent)">
          <?php if ($current_schedule === 'none'): ?>
            Disabled
          <?php elseif ($current_schedule === 'daily'): ?>
            Daily at <?= $current_time ?>
          <?php elseif ($current_schedule === 'weekly'):
            $day_names = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
            $selected_day_names = array_map(fn($d) => $day_names[$d] ?? '?', $current_weekdays);
          ?>
            Weekly on <?= implode(', ', $selected_day_names) ?> at <?= $current_time ?>
          <?php else:
            $day_list = implode(', ', array_map(function($d) {
                $suffix = ($d % 10 === 1 && $d !== 11) ? 'st' : (($d % 10 === 2 && $d !== 12) ? 'nd' : (($d % 10 === 3 && $d !== 13) ? 'rd' : 'th'));
                return $d . $suffix;
            }, $current_days_of_month));
          ?>
            Monthly on the <?= $day_list ?> at <?= $current_time ?>
          <?php endif; ?>
        </strong>
      </p>
    </div>
    <form method="post" id="schedule-form" style="display:flex;flex-direction:column;gap:14px;">
      <input type="hidden" name="action" value="schedule">

      <!-- Frequency + time picker -->
      <div style="display:flex;align-items:center;gap:10px;flex-wrap:wrap;">
        <label style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;">Frequency:</label>
        <select name="frequency" id="freq-select" onchange="updateScheduleUI()" style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);font-size:13px;padding:8px 30px 8px 12px;cursor:pointer;outline:none;appearance:none;-webkit-appearance:none;font-family:'DM Sans',sans-serif;background-image:url('data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2212%22 height=%2212%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%236b7080%22 stroke-width=%222%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22><polyline points=%226 9 12 15 18 9%22/></svg>');background-repeat:no-repeat;background-position:right 10px center;">
          <option value="none" <?= $current_schedule === 'none' ? 'selected' : '' ?>>Disabled</option>
          <option value="daily" <?= $current_schedule === 'daily' ? 'selected' : '' ?>>Daily</option>
          <option value="weekly" <?= $current_schedule === 'weekly' ? 'selected' : '' ?>>Weekly</option>
          <option value="monthly" <?= $current_schedule === 'monthly' ? 'selected' : '' ?>>Monthly</option>
        </select>

        <label style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;">Time:</label>
        <?php
          [$ch, $cm] = explode(':', $current_time);
          $ch = (int)$ch; $cm = (int)$cm;
          // Convert to 12-hour format for display
          $display_hour = $ch === 0 ? 12 : ($ch > 12 ? $ch - 12 : $ch);
          $ampm = $ch < 12 ? 'AM' : 'PM';
        ?>
        <div class="time-picker">
          <!-- Hour -->
          <div class="tp-field">
            <button type="button" class="tp-arrow" onclick="tpAdjust('hour', 1)" aria-label="Increase hour">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
            </button>
            <input type="text" class="tp-input" id="tp-hour" value="<?= sprintf('%02d', $display_hour) ?>" maxlength="2" oninput="tpValidate('hour')" onblur="tpPad('hour')" />
            <button type="button" class="tp-arrow" onclick="tpAdjust('hour', -1)" aria-label="Decrease hour">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
          </div>

          <span class="tp-sep">:</span>

          <!-- Minute -->
          <div class="tp-field">
            <button type="button" class="tp-arrow" onclick="tpAdjust('minute', 5)" aria-label="Increase minute">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="18 15 12 9 6 15"/></svg>
            </button>
            <input type="text" class="tp-input" id="tp-minute" value="<?= sprintf('%02d', $cm) ?>" maxlength="2" oninput="tpValidate('minute')" onblur="tpPad('minute')" />
            <button type="button" class="tp-arrow" onclick="tpAdjust('minute', -5)" aria-label="Decrease minute">
              <svg width="10" height="10" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"><polyline points="6 9 12 15 18 9"/></svg>
            </button>
          </div>

          <!-- AM/PM toggle -->
          <button type="button" class="tp-ampm" id="tp-ampm" onclick="tpToggleAmPm()"><?= $ampm ?></button>

          <!-- Hidden field that gets submitted -->
          <input type="hidden" name="time_slot" id="tp-value" value="<?= htmlspecialchars($current_time) ?>">
        </div>
      </div>

      <!-- Weekly: day selector -->
      <div id="weekly-picker" style="<?= $current_schedule === 'weekly' ? '' : 'display:none;' ?>">
        <label style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;margin-bottom:6px;display:block;">Days of the Week:</label>
        <div style="display:flex;gap:6px;flex-wrap:wrap;">
          <?php
            $day_names = ['Sun','Mon','Tue','Wed','Thu','Fri','Sat'];
            foreach ($day_names as $i => $name):
              $checked = in_array($i, $current_weekdays);
          ?>
            <label style="cursor:pointer;">
              <input type="checkbox" name="weekdays[]" value="<?= $i ?>" <?= $checked ? 'checked' : '' ?> style="display:none;" onchange="this.nextElementSibling.style.background = this.checked ? 'var(--accent)' : 'var(--surface2)'; this.nextElementSibling.style.color = this.checked ? '#0d0f14' : 'var(--text)'; this.nextElementSibling.style.borderColor = this.checked ? 'var(--accent)' : 'var(--border)';">
              <span style="display:inline-block;padding:6px 14px;background:<?= $checked ? 'var(--accent)' : 'var(--surface2)' ?>;color:<?= $checked ? '#0d0f14' : 'var(--text)' ?>;border:1px solid <?= $checked ? 'var(--accent)' : 'var(--border)' ?>;border-radius:var(--radius);font-size:12px;font-weight:500;font-family:'Space Mono',monospace;transition:all 0.15s;"><?= $name ?></span>
            </label>
          <?php endforeach; ?>
        </div>
      </div>

      <!-- Monthly: day-of-month picker (calendar-style grid) -->
      <div id="monthly-picker" style="<?= $current_schedule === 'monthly' ? '' : 'display:none;' ?>">
        <div style="display:flex;justify-content:space-between;align-items:center;margin-bottom:8px;">
          <label style="font-size:12px;color:var(--muted);text-transform:uppercase;letter-spacing:0.05em;">Days of the Month:</label>
          <div style="display:flex;gap:6px;">
            <button type="button" onclick="setMonthPreset('first')" style="padding:4px 10px;background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:4px;font-size:11px;cursor:pointer;font-family:'Space Mono',monospace;">1st</button>
            <button type="button" onclick="setMonthPreset('mid')" style="padding:4px 10px;background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:4px;font-size:11px;cursor:pointer;font-family:'Space Mono',monospace;">15th</button>
            <button type="button" onclick="setMonthPreset('bi')" style="padding:4px 10px;background:var(--surface2);border:1px solid var(--border);color:var(--text);border-radius:4px;font-size:11px;cursor:pointer;font-family:'Space Mono',monospace;">1st &amp; 15th</button>
            <button type="button" onclick="setMonthPreset('clear')" style="padding:4px 10px;background:var(--surface2);border:1px solid var(--border);color:var(--muted);border-radius:4px;font-size:11px;cursor:pointer;font-family:'Space Mono',monospace;">Clear</button>
          </div>
        </div>
        <div style="display:grid;grid-template-columns:repeat(7, 1fr);gap:4px;max-width:400px;background:var(--surface2);padding:8px;border-radius:var(--radius);border:1px solid var(--border);">
          <?php for ($d = 1; $d <= 31; $d++):
            $checked = in_array($d, $current_days_of_month);
            $warn = $d >= 29;  // Days that don't exist in all months
          ?>
            <label style="cursor:pointer;position:relative;">
              <input type="checkbox" class="dom-check" name="days_of_month[]" value="<?= $d ?>" <?= $checked ? 'checked' : '' ?> style="display:none;" onchange="toggleDom(this)">
              <span data-dom="<?= $d ?>" style="display:flex;align-items:center;justify-content:center;height:36px;background:<?= $checked ? 'var(--accent)' : 'var(--bg)' ?>;color:<?= $checked ? '#0d0f14' : ($warn ? 'var(--warn)' : 'var(--text)') ?>;border:1px solid <?= $checked ? 'var(--accent)' : 'var(--border)' ?>;border-radius:4px;font-size:13px;font-weight:500;font-family:'Space Mono',monospace;transition:all 0.12s;"><?= $d ?></span>
            </label>
          <?php endfor; ?>
        </div>
        <p style="font-size:11px;color:var(--muted);margin-top:8px;">
          <span style="color:var(--warn)">Orange days</span> (29-31) don't exist in every month — backup will skip months where that day doesn't exist.
        </p>
      </div>

      <div>
        <button type="submit" class="btn btn-primary">Save Schedule</button>
      </div>
    </form>
  </div>

  <script>
    // ── Time picker ─────────────────────────────────────
    function tpSyncValue() {
      let h = parseInt(document.getElementById('tp-hour').value) || 12;
      let m = parseInt(document.getElementById('tp-minute').value) || 0;
      const ampm = document.getElementById('tp-ampm').textContent;

      // Convert 12h to 24h
      if (ampm === 'AM') {
        if (h === 12) h = 0;
      } else {
        if (h !== 12) h += 12;
      }
      document.getElementById('tp-value').value =
        String(h).padStart(2, '0') + ':' + String(m).padStart(2, '0');
    }

    function tpAdjust(field, step) {
      const input = document.getElementById('tp-' + field);
      let v = parseInt(input.value) || 0;
      const max = field === 'hour' ? 12 : 59;
      const min = field === 'hour' ? 1 : 0;
      v += step;
      if (v > max) v = min;
      if (v < min) v = max;
      input.value = String(v).padStart(2, '0');
      // When hour hits 12 via incrementing past 11, or 12->1 via AM/PM noon/midnight swap
      if (field === 'hour' && (v === 12 && step === 1)) tpToggleAmPm();
      tpSyncValue();
    }

    function tpValidate(field) {
      const input = document.getElementById('tp-' + field);
      let cleaned = input.value.replace(/\D/g, '');
      const max = field === 'hour' ? 12 : 59;
      const min = field === 'hour' ? 1 : 0;
      if (cleaned === '') { tpSyncValue(); return; }
      let v = parseInt(cleaned);
      if (v > max) v = max;
      if (field === 'hour' && v < min) v = min;
      input.value = cleaned;
      tpSyncValue();
    }

    function tpPad(field) {
      const input = document.getElementById('tp-' + field);
      let v = parseInt(input.value) || (field === 'hour' ? 12 : 0);
      const max = field === 'hour' ? 12 : 59;
      const min = field === 'hour' ? 1 : 0;
      if (v > max) v = max;
      if (v < min) v = min;
      input.value = String(v).padStart(2, '0');
      tpSyncValue();
    }

    function tpToggleAmPm() {
      const btn = document.getElementById('tp-ampm');
      btn.textContent = btn.textContent === 'AM' ? 'PM' : 'AM';
      tpSyncValue();
    }

    function updateScheduleUI() {
      const freq = document.getElementById('freq-select').value;
      document.getElementById('weekly-picker').style.display = freq === 'weekly' ? '' : 'none';
      document.getElementById('monthly-picker').style.display = freq === 'monthly' ? '' : 'none';
    }

    function toggleDom(input) {
      const span = input.nextElementSibling;
      const day = parseInt(span.dataset.dom);
      const isWarn = day >= 29;
      if (input.checked) {
        span.style.background = 'var(--accent)';
        span.style.color = '#0d0f14';
        span.style.borderColor = 'var(--accent)';
      } else {
        span.style.background = 'var(--bg)';
        span.style.color = isWarn ? 'var(--warn)' : 'var(--text)';
        span.style.borderColor = 'var(--border)';
      }
    }

    function setMonthPreset(preset) {
      const map = {
        'first': [1],
        'mid':   [15],
        'bi':    [1, 15],
        'clear': []
      };
      const selected = map[preset] || [];
      document.querySelectorAll('.dom-check').forEach(cb => {
        cb.checked = selected.includes(parseInt(cb.value));
        toggleDom(cb);
      });
    }
  </script>

  <!-- Backup list -->
  <div class="panel">
    <div class="panel-header">Saved Backups (<span id="backup-count"><?= count($backups) ?></span>)</div>
    <?php if (empty($backups)): ?>
      <div class="empty">No backups yet. Create one above.</div>
    <?php else: ?>
    <ul class="backup-list">
      <?php foreach ($backups as $b): ?>
      <li class="backup-item" id="backup-<?= $b['id'] ?>">
        <span class="backup-icon">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 16V8a2 2 0 0 0-1-1.73l-7-4a2 2 0 0 0-2 0l-7 4A2 2 0 0 0 3 8v8a2 2 0 0 0 1 1.73l7 4a2 2 0 0 0 2 0l7-4A2 2 0 0 0 21 16z"/><polyline points="3.27 6.96 12 12.01 20.73 6.96"/><line x1="12" y1="22.08" x2="12" y2="12"/></svg>
        </span>
        <div class="backup-info">
          <div class="backup-name"><?= htmlspecialchars($b['filename']) ?></div>
          <div class="backup-meta">
            <?= fmt_size((int)$b['filesize']) ?> &middot;
            <?= date('M j, Y g:i A', strtotime($b['created_at'])) ?> &middot;
            by <?= htmlspecialchars($b['created_by_name'] ?? 'unknown') ?>
          </div>
          <div class="confirm-restore" id="confirm-<?= $b['id'] ?>">
            <span>This will overwrite current files and database. Are you sure?</span>
            <form method="post" style="display:inline">
              <input type="hidden" name="action" value="restore">
              <input type="hidden" name="backup_id" value="<?= $b['id'] ?>">
              <button type="submit" class="btn btn-warn btn-small">Yes, Restore</button>
            </form>
            <button class="btn btn-link btn-small" onclick="document.getElementById('confirm-<?= $b['id'] ?>').classList.remove('show')">Cancel</button>
          </div>
        </div>
        <div class="backup-actions">
          <a href="?download=<?= $b['id'] ?>" class="btn btn-link btn-small">Download</a>
          <button class="btn btn-link btn-small" onclick="document.getElementById('confirm-<?= $b['id'] ?>').classList.add('show')">Restore</button>
          <form method="post" style="display:inline" onsubmit="return confirm('Delete this backup?')">
            <input type="hidden" name="action" value="delete">
            <input type="hidden" name="backup_id" value="<?= $b['id'] ?>">
            <button type="submit" class="btn btn-danger btn-small">Delete</button>
          </form>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
</main>

<script>
(function() {
  const POLL_MS = 5000;
  let lastCount = parseInt(document.getElementById('backup-count').textContent, 10) || 0;

  function setUsbBadge(usb) {
    const badge = document.getElementById('usb-badge');
    const dot   = document.getElementById('usb-dot');
    const text  = document.getElementById('usb-text');
    if (!badge || !dot || !text) return;
    if (usb.active) {
      badge.style.background   = 'rgba(79,255,176,0.1)';
      badge.style.border       = '1px solid rgba(79,255,176,0.3)';
      badge.style.color        = 'var(--accent)';
      dot.style.background     = 'var(--accent)';
      dot.style.boxShadow      = '0 0 6px var(--accent)';
      text.textContent         = `USB mirror active · ${usb.count} file(s)`;
    } else {
      badge.style.background   = 'var(--surface2)';
      badge.style.border       = '1px solid var(--border)';
      badge.style.color        = 'var(--muted)';
      dot.style.background     = 'var(--muted)';
      dot.style.boxShadow      = 'none';
      text.textContent         = 'USB mirror inactive';
    }
  }

  async function poll() {
    let data;
    try {
      const r = await fetch('/backup_data.php', { credentials: 'same-origin' });
      if (!r.ok) return;
      data = await r.json();
    } catch (e) { return; }

    setUsbBadge(data.usb || { active: false, count: 0 });

    const countEl = document.getElementById('backup-count');
    if (countEl) countEl.textContent = data.count;

    // If a new backup appeared (e.g. cron just ran), reload the page so the
    // list, action buttons, and IDs all stay consistent with the server view.
    if (data.count !== lastCount) {
      lastCount = data.count;
      setTimeout(() => location.reload(), 400);
    }
  }

  setInterval(poll, POLL_MS);
  poll();
})();
</script>

</body>
</html>
