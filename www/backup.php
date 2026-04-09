<?php
require_once 'auth.php';
require_admin();
require_once 'db.php';

$user = current_user();

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
    } else {
        $error = "Backup failed. MySQL dump returned code {$return_code}.";
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
    $schedule = $_POST['schedule'] ?? 'none';
    $cron_map = [
        'daily'   => '0 2 * * *',      // 2:00 AM daily
        'weekly'  => '0 2 * * 0',      // 2:00 AM Sunday
        'monthly' => '0 2 1 * *',      // 2:00 AM 1st of month
        'none'    => '',
    ];

    $cron_expr = $cron_map[$schedule] ?? '';
    $cron_job  = "$cron_expr php /var/www/html/cron_backup.php >> /var/log/backup_cron.log 2>&1";

    // Remove existing NAS backup cron entries
    exec("crontab -l 2>/dev/null | grep -v 'cron_backup.php' | crontab - 2>&1");

    if ($cron_expr !== '') {
        // Add new cron entry
        exec("(crontab -l 2>/dev/null; echo '$cron_job') | crontab - 2>&1");
        $message = "Automatic backups scheduled: $schedule";
    } else {
        $message = "Automatic backups disabled.";
    }
}

// Read current schedule
$current_schedule = 'none';
$cron_output = [];
exec('crontab -l 2>/dev/null', $cron_output);
$cron_text = implode("\n", $cron_output);
if (str_contains($cron_text, '0 2 * * * php') && str_contains($cron_text, 'cron_backup.php')) {
    $current_schedule = 'daily';
} elseif (str_contains($cron_text, '0 2 * * 0') && str_contains($cron_text, 'cron_backup.php')) {
    $current_schedule = 'weekly';
} elseif (str_contains($cron_text, '0 2 1 * *') && str_contains($cron_text, 'cron_backup.php')) {
    $current_schedule = 'monthly';
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
  .nav-link.active { color: var(--accent); }
  .nav-user { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--muted); }
  .nav-user strong { color: var(--text); }
  .nav-user a { color: var(--muted); text-decoration: none; font-size: 12px; padding: 4px 10px; border: 1px solid var(--border); border-radius: var(--radius); transition: border-color 0.15s, color 0.15s; }
  .nav-user a:hover { border-color: var(--danger); color: var(--danger); }

  main { padding: 28px; max-width: 1100px; width: 100%; margin: 0 auto; flex: 1; }

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
  .btn:hover { opacity: 0.85; }
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
  .backup-icon { font-size: 24px; flex-shrink: 0; }
  .backup-info { flex: 1; }
  .backup-name { font-weight: 500; font-size: 14px; margin-bottom: 3px; }
  .backup-meta { font-size: 12px; color: var(--muted); }
  .backup-actions { display: flex; gap: 6px; flex-shrink: 0; }

  .empty { padding: 40px 20px; text-align: center; color: var(--muted); font-size: 13px; }

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
    <span>Hello, <strong><?= htmlspecialchars($user['username']) ?></strong></span>
    <?php if (is_admin()): ?><span style="color:var(--accent);font-size:11px;font-family:'Space Mono',monospace">ADMIN</span><?php endif; ?>
    <a href="/logout.php">Sign out</a>
  </div>
</nav>

<main>
  <h1>Backup &amp; Restore</h1>

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
  <div class="create-card">
    <div class="create-info">
      <h2>Automatic Backups</h2>
      <p>Schedule recurring backups. Currently: <strong style="color:var(--accent)"><?= $current_schedule === 'none' ? 'Disabled' : ucfirst($current_schedule) ?></strong></p>
    </div>
    <form method="post" style="display:flex;align-items:center;gap:10px;">
      <input type="hidden" name="action" value="schedule">
      <select name="schedule" style="background:var(--bg);border:1px solid var(--border);border-radius:var(--radius);color:var(--text);font-size:13px;padding:8px 12px;">
        <option value="none" <?= $current_schedule === 'none' ? 'selected' : '' ?>>Disabled</option>
        <option value="daily" <?= $current_schedule === 'daily' ? 'selected' : '' ?>>Daily (2:00 AM)</option>
        <option value="weekly" <?= $current_schedule === 'weekly' ? 'selected' : '' ?>>Weekly (Sunday 2:00 AM)</option>
        <option value="monthly" <?= $current_schedule === 'monthly' ? 'selected' : '' ?>>Monthly (1st, 2:00 AM)</option>
      </select>
      <button type="submit" class="btn btn-primary">Save Schedule</button>
    </form>
  </div>

  <!-- Backup list -->
  <div class="panel">
    <div class="panel-header">Saved Backups (<?= count($backups) ?>)</div>
    <?php if (empty($backups)): ?>
      <div class="empty">No backups yet. Create one above.</div>
    <?php else: ?>
    <ul class="backup-list">
      <?php foreach ($backups as $b): ?>
      <li class="backup-item" id="backup-<?= $b['id'] ?>">
        <span class="backup-icon">📦</span>
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

</body>
</html>
