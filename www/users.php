<?php
require_once 'auth.php';
require_admin();
require_once 'db.php';
require_once 'usb_manifest.php';

$user = current_user();

// Keep the USB manifest fresh (adds hashes for newly-created users).
$manifest = update_user_manifest($pdo);

// USB active dot = the host-side watcher is currently mirroring. Individual
// users show a green dot only if the overall mirror is active AND they've
// actually uploaded at least one file (otherwise their folder wouldn't
// exist on the USB yet).
$usb_sync_active = false;
$heartbeat = '/var/www/backups/.usb_sync_status';
if (file_exists($heartbeat)) {
    $age = time() - filemtime($heartbeat);
    $h   = @json_decode(@file_get_contents($heartbeat), true) ?: [];
    $usb_sync_active = $age < 15 && ($h['status'] ?? '') === 'ok';
}

// Fetch all users with their storage usage
// Sort: current user first, then admins, then users alphabetically
$stmt = $pdo->prepare('
    SELECT u.id, u.username, u.email, u.role, u.created_at, u.last_login,
           u.storage_quota,
           COALESCE(SUM(CASE WHEN f.is_folder = 0 THEN f.filesize ELSE 0 END), 0) AS storage_used
    FROM users u
    LEFT JOIN files f ON f.owner_id = u.id
    GROUP BY u.id
    ORDER BY
        (u.id = ?) DESC,
        (u.role = "admin") DESC,
        u.username ASC
');
$stmt->execute([$user['id']]);
$users = $stmt->fetchAll();

function fmt_bytes(int $b): string {
    if ($b >= 1073741824) return round($b/1073741824,2).' GB';
    if ($b >= 1048576)   return round($b/1048576,1).' MB';
    if ($b >= 1024)      return round($b/1024,1).' KB';
    return $b.' B';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NAS — Users</title>
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

  main { padding: 28px; max-width: 1000px; width: 100%; margin: 0 auto; flex: 1; }

  .toolbar { display: flex; align-items: center; gap: 10px; margin-bottom: 24px; }
  .toolbar h1 { font-size: 20px; font-weight: 500; flex: 1; }

  /* Search form */
  .search-form { position: relative; display: inline-flex; align-items: center; margin-right: 8px; }
  .search-input {
    background: var(--bg) url('data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2214%22 height=%2214%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%236b7080%22 stroke-width=%222%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22><circle cx=%2211%22 cy=%2211%22 r=%228%22/><line x1=%2221%22 y1=%2221%22 x2=%2216.65%22 y2=%2216.65%22/></svg>') no-repeat 12px center;
    border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--text); font-family: 'DM Sans', sans-serif; font-size: 13px;
    padding: 8px 12px 8px 34px; outline: none; width: 240px;
    transition: border-color 0.15s, box-shadow 0.15s, width 0.2s;
  }
  .search-input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(79,255,176,0.08); width: 300px; }
  .search-input::placeholder { color: var(--muted); }

  .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: var(--radius); font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; border: none; text-decoration: none; transition: opacity 0.15s, transform 0.1s; }
  .btn:hover { opacity: 0.92; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(79,255,176,0.15); }
  .btn:active { transform: translateY(0); box-shadow: 0 1px 4px rgba(79,255,176,0.12); }
  .btn-secondary:hover { box-shadow: 0 4px 14px rgba(79,255,176,0.08); border-color: rgba(79,255,176,0.3); }
  .btn-danger:hover { box-shadow: 0 4px 14px rgba(255,79,106,0.15); border-color: rgba(255,79,106,0.5); }
  .btn-primary   { background: var(--accent); color: #0d0f14; }
  .btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
  .btn-danger    { background: rgba(255,79,106,0.12); color: var(--danger); border: 1px solid rgba(255,79,106,0.25); }

  .flash { padding: 10px 16px; border-radius: var(--radius); font-size: 13px; margin-bottom: 18px; }
  .flash.success { background: rgba(79,255,176,0.1); border: 1px solid rgba(79,255,176,0.3); color: var(--accent); }
  .flash.error   { background: rgba(255,79,106,0.1); border: 1px solid rgba(255,79,106,0.3); color: var(--danger); }

  .user-table { width: 100%; border-collapse: collapse; font-size: 14px; }
  .user-table thead tr { border-bottom: 1px solid var(--border); }
  .user-table th { text-align: left; padding: 10px 14px; font-size: 11px; font-weight: 500; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); }
  /* Page hero - ambient welcome card */
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

  .user-table tbody tr { border-bottom: 1px solid rgba(42,45,56,0.5); transition: background 0.12s; animation: fadeUp 0.35s ease both; }
  .user-table tbody tr:nth-child(1) { animation-delay: 0.05s; }
  .user-table tbody tr:nth-child(2) { animation-delay: 0.10s; }
  .user-table tbody tr:nth-child(3) { animation-delay: 0.15s; }
  .user-table tbody tr:nth-child(4) { animation-delay: 0.20s; }
  .user-table tbody tr:nth-child(n+5) { animation-delay: 0.25s; }
  .user-table tbody tr:hover { background: var(--surface2); }

  /* Highlight current user row */
  .user-table tbody tr.self-row {
    background: rgba(79,255,176,0.04);
    box-shadow: inset 3px 0 0 var(--accent);
  }
  .user-table tbody tr.self-row:hover {
    background: rgba(79,255,176,0.08);
  }

  /* "you" tag next to username */
  .you-tag {
    font-size: 9px; font-weight: 700; letter-spacing: 0.05em;
    font-family: 'Space Mono', monospace;
    background: rgba(79,255,176,0.12); color: var(--accent);
    border: 1px solid rgba(79,255,176,0.25);
    padding: 1px 5px; border-radius: 3px;
    margin-left: 6px;
    vertical-align: middle;
  }
  .toolbar { animation: fadeUp 0.4s ease both; }
  .user-table { animation: fadeUp 0.4s ease both; animation-delay: 0.05s; }
  .user-table td { padding: 13px 14px; vertical-align: middle; }

  .user-info { display: flex; align-items: center; gap: 12px; }
  .avatar {
    width: 34px; height: 34px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    display: flex; align-items: center; justify-content: center;
    font-weight: 700; font-size: 13px; color: #0d0f14; flex-shrink: 0;
    font-family: 'Space Mono', monospace;
  }
  .user-name  { font-weight: 500; }
  .user-email { font-size: 12px; color: var(--muted); }

  .badge {
    display: inline-block; padding: 2px 8px; border-radius: 99px;
    font-size: 11px; font-weight: 600; letter-spacing: 0.05em; font-family: 'Space Mono', monospace;
  }
  .badge-admin { background: rgba(79,255,176,0.12); color: var(--accent); border: 1px solid rgba(79,255,176,0.25); }
  .badge-user  { background: rgba(107,112,128,0.15); color: var(--muted); border: 1px solid var(--border); }

  .meta { color: var(--muted); font-size: 12px; }

  /* USB Archive ID cell - matches the role-badge / Space-Mono code aesthetic */
  .archive-id {
    display: inline-flex; align-items: center; gap: 8px;
    padding: 4px 8px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    background: var(--surface);
    cursor: pointer;
    transition: border-color 0.15s, background 0.15s;
    user-select: all;
  }
  .archive-id:hover {
    border-color: rgba(79,255,176,0.3);
    background: var(--surface2);
  }
  .archive-id.copied {
    border-color: var(--accent);
    background: rgba(79,255,176,0.08);
  }
  .archive-dot {
    width: 6px; height: 6px; border-radius: 50%;
    background: var(--muted);
    flex-shrink: 0;
    transition: background 0.2s, box-shadow 0.2s;
  }
  .archive-dot.on  { background: var(--accent); box-shadow: 0 0 6px var(--accent); }
  .archive-dot.off { background: var(--muted); }
  .archive-hash {
    font-family: 'Space Mono', monospace;
    font-size: 11px;
    color: var(--text);
    letter-spacing: 0.02em;
  }

  .actions { display: flex; gap: 4px; }
  .action-btn { background: none; border: 1px solid transparent; color: var(--muted); cursor: pointer; padding: 6px; border-radius: var(--radius); text-decoration: none; display: inline-flex; align-items: center; justify-content: center; transition: all 0.12s; }
  .action-btn:hover { background: var(--surface2); color: var(--accent); border-color: var(--border); }
  .action-btn.del:hover { color: var(--danger); border-color: rgba(255,79,106,0.3); }

  /* Modal */
  .modal-backdrop { display: none; position: fixed; inset: 0; background: rgba(0,0,0,0.6); backdrop-filter: blur(4px); z-index: 200; align-items: center; justify-content: center; }
  .modal-backdrop.open { display: flex; }
  .modal { background: var(--surface); border: 1px solid var(--border); border-radius: 12px; padding: 32px; width: 100%; max-width: 440px; animation: fadeUp 0.2s ease; }
  @keyframes fadeUp { from { opacity: 0; transform: translateY(16px); } to { opacity: 1; transform: translateY(0); } }
  .modal h2 { font-size: 18px; font-weight: 500; margin-bottom: 20px; }
  .field { margin-bottom: 16px; }
  label { display: block; font-size: 11px; font-weight: 500; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
  input[type="text"], input[type="email"], input[type="password"], select {
    width: 100%; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--text); font-family: 'DM Sans', sans-serif; font-size: 14px; padding: 10px 12px;
    outline: none; transition: border-color 0.2s;
  }
  input:focus, select:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(79,255,176,0.08); }
  select option { background: var(--surface); }
  .hint { font-size: 11px; color: var(--muted); margin-top: 5px; }
  .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 24px; }

  /* User detail modal - wider than the edit modal */
  .modal.wide { max-width: 780px; padding: 0; overflow: hidden; }
  .detail-header { padding: 22px 28px 18px; border-bottom: 1px solid var(--border); display: flex; align-items: center; gap: 14px; }
  .detail-header .avatar { width: 48px; height: 48px; font-size: 16px; }
  .detail-title { font-size: 20px; font-weight: 500; line-height: 1.2; }
  .detail-sub   { font-size: 12px; color: var(--muted); margin-top: 3px; font-family: 'Space Mono', monospace; }
  .detail-summary { display: flex; gap: 18px; padding: 14px 28px; border-bottom: 1px solid var(--border); background: var(--surface2); }
  .detail-summary-item { display: flex; flex-direction: column; gap: 2px; }
  .detail-summary-val { font-family: 'Space Mono', monospace; font-size: 18px; font-weight: 700; color: var(--accent); line-height: 1; }
  .detail-summary-lbl { font-size: 10px; text-transform: uppercase; letter-spacing: 0.1em; color: var(--muted); }
  .detail-tabs { display: flex; gap: 2px; padding: 0 28px; border-bottom: 1px solid var(--border); }
  .detail-tab { background: none; border: none; color: var(--muted); cursor: pointer; font-family: 'DM Sans', sans-serif; font-size: 13px; padding: 12px 14px; position: relative; transition: color 0.15s; }
  .detail-tab:hover { color: var(--text); }
  .detail-tab.active { color: var(--accent); }
  .detail-tab.active::after { content: ''; position: absolute; left: 0; right: 0; bottom: -1px; height: 2px; background: var(--accent); }
  .detail-body { max-height: 420px; overflow-y: auto; }
  .detail-table { width: 100%; border-collapse: collapse; font-size: 13px; }
  .detail-table thead { position: sticky; top: 0; background: var(--surface); z-index: 1; }
  .detail-table th { text-align: left; font-size: 10px; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); font-weight: 500; padding: 10px 28px; border-bottom: 1px solid var(--border); }
  .detail-table td { padding: 10px 28px; border-bottom: 1px solid rgba(42,45,56,0.5); }
  .detail-table tr:last-child td { border-bottom: none; }
  .detail-file-name { display: flex; align-items: center; gap: 8px; font-weight: 500; }
  .detail-file-icon { font-family: 'Space Mono', monospace; font-size: 9px; font-weight: 700; padding: 2px 5px; border-radius: 3px; background: rgba(79,255,176,0.08); color: var(--accent); border: 1px solid rgba(79,255,176,0.2); }
  .detail-file-icon.folder { color: var(--accent2); background: rgba(0,191,255,0.08); border-color: rgba(0,191,255,0.25); }
  .share-chip { display: inline-block; font-family: 'Space Mono', monospace; font-size: 10px; padding: 2px 6px; background: var(--surface); border: 1px solid var(--border); border-radius: 3px; color: var(--text); margin-right: 4px; }
  .perm-pill { display: inline-block; font-family: 'Space Mono', monospace; font-size: 10px; font-weight: 700; letter-spacing: 0.08em; padding: 2px 6px; background: rgba(79,255,176,0.08); color: var(--accent); border: 1px solid rgba(79,255,176,0.25); border-radius: 3px; }
  .detail-empty { padding: 40px 28px; text-align: center; color: var(--muted); font-size: 13px; }
  .detail-close { background: none; border: 1px solid var(--border); color: var(--muted); cursor: pointer; padding: 6px 10px; border-radius: var(--radius); font-family: 'DM Sans', sans-serif; font-size: 12px; transition: all 0.15s; margin-left: auto; }
  .detail-close:hover { border-color: var(--accent); color: var(--accent); }

  /* Make non-action cells on user rows feel clickable */
  tr.user-row { cursor: pointer; transition: background 0.12s; }
  tr.user-row:hover td { background: rgba(79,255,176,0.03); }
</style>
</head>
<body>

<nav>
  <a class="nav-logo" href="/">NAS</a>
  <div class="nav-links">
    <a class="nav-link" href="/">Files</a>
    <a class="nav-link active" href="/users.php">Users</a>
    <a class="nav-link" href="/monitor.php">Monitor</a>
    <a class="nav-link" href="/logs.php">Logs</a>
    <a class="nav-link" href="/backup.php">Backups</a>
  </div>
  <div class="nav-user">
    <a href="/profile.php" class="nav-user-link">Hello, <strong><?= htmlspecialchars($user['username']) ?></strong></a>
    <span class="role-badge admin">Admin</span>
    <a href="/logout.php">Sign out</a>
  </div>
</nav>

<main>
  <div class="page-hero">
    <div>
      <h1 class="hero-title">User Directory</h1>
      <p class="hero-sub">Manage accounts, roles, and storage quotas across your NAS.</p>
    </div>
    <div class="hero-stat">
      <span class="hero-stat-value"><?= count($users) ?></span>
      <span class="hero-stat-label">Total Users</span>
    </div>
  </div>

  <div class="toolbar">
    <h1>Users <span id="user-count" style="color:var(--muted);font-size:15px;font-weight:400">(<?= count($users) ?>)</span></h1>
    <div class="search-form">
      <input type="text" id="user-search" placeholder="Search users..." class="search-input" autocomplete="off" oninput="filterUsers()">
    </div>
    <button class="btn btn-primary" onclick="openModal('modal-create')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      New User
    </button>
  </div>

  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="flash <?= $_SESSION['flash']['type'] ?>"><?= htmlspecialchars($_SESSION['flash']['msg']) ?></div>
    <?php unset($_SESSION['flash']); ?>
  <?php endif; ?>

  <table class="user-table">
    <thead>
      <tr>
        <th>User</th>
        <th>Role</th>
        <th>Storage</th>
        <th title="Folder name on the backup USB drive. Hashed so the drive doesn't reveal usernames. Click to copy.">USB Archive</th>
        <th>Joined</th>
        <th>Last Login</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u):
        $is_self = $u['id'] == $user['id'];
      ?>
      <tr class="user-row<?= $is_self ? ' self-row' : '' ?>" data-user-id="<?= $u['id'] ?>">
        <td>
          <div class="user-info">
            <div class="avatar"><?= strtoupper(substr($u['username'], 0, 2)) ?></div>
            <div>
              <div class="user-name">
                <?= htmlspecialchars($u['username']) ?>
                <?php if ($is_self): ?><span class="you-tag">you</span><?php endif; ?>
              </div>
            </div>
          </div>
        </td>
        <td>
          <span class="badge badge-<?= $u['role'] ?>"><?= strtoupper($u['role']) ?></span>
        </td>
        <td>
          <?php
            $used  = (int)$u['storage_used'];
            $quota = $u['storage_quota'];
            if ($quota) {
                $pct = min(100, round($used / $quota * 100));
                $bar_class = $pct >= 90 ? 'danger' : ($pct >= 70 ? 'warn' : 'ok');
                $color = $pct >= 90 ? 'var(--danger)' : ($pct >= 70 ? 'var(--warn)' : 'var(--accent)');
          ?>
            <div style="min-width:120px">
              <div style="display:flex;justify-content:space-between;font-size:11px;color:var(--muted);margin-bottom:4px">
                <span><?= fmt_bytes($used) ?></span>
                <span><?= fmt_bytes((int)$quota) ?></span>
              </div>
              <div style="height:4px;background:var(--surface2);border-radius:99px;overflow:hidden">
                <div style="height:100%;width:<?= $pct ?>%;background:<?= $color ?>;border-radius:99px"></div>
              </div>
            </div>
          <?php } else { ?>
            <span class="meta"><?= fmt_bytes($used) ?> <span style="color:var(--muted);font-size:11px">used · unlimited</span></span>
          <?php } ?>
        </td>
        <td>
          <?php
            $hash   = $manifest['users'][(string)$u['id']]['hash'] ?? '—';
            $dot_on = $usb_sync_active && $used > 0;
            $dot_title = $usb_sync_active
              ? ($used > 0 ? 'Files mirrored to USB' : 'Ready — user has no uploads yet')
              : 'USB drive not connected';
          ?>
          <div class="archive-id" title="Click to copy" data-hash="<?= htmlspecialchars($hash) ?>">
            <span class="archive-dot <?= $dot_on ? 'on' : 'off' ?>" title="<?= htmlspecialchars($dot_title) ?>"></span>
            <code class="archive-hash"><?= htmlspecialchars($hash) ?></code>
          </div>
        </td>
        <td class="meta"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
        <td class="meta"><?= $u['last_login'] ? date('M j, Y g:i a', strtotime($u['last_login'])) : 'Never' ?></td>
        <td>
          <div class="actions">
            <button class="action-btn" onclick='openEdit(<?= json_encode(['id'=>$u['id'],'username'=>$u['username'],'role'=>$u['role'],'storage_quota'=>$u['storage_quota']]) ?>)' title="Edit">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </button>
            <?php if ($u['id'] != $user['id']): ?>
            <a class="action-btn del"
               href="/action_user_delete.php?id=<?= $u['id'] ?>"
               onclick="return confirm('Delete user <?= htmlspecialchars(addslashes($u['username'])) ?>? This also deletes all their files.')"
               title="Delete">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
</main>

<!-- Create User Modal -->
<div class="modal-backdrop" id="modal-create">
  <div class="modal">
    <h2>New User</h2>
    <form method="POST" action="/action_user_create.php">
      <div class="field">
        <label>Username</label>
        <input type="text" name="username" required autofocus>
      </div>
      <div class="field">
        <label>Password</label>
        <input type="password" name="password" required>
        <p class="hint">Minimum 8 characters.</p>
      </div>
      <div class="field">
        <label>Role</label>
        <select name="role">
          <option value="user">User</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div class="field">
        <label>Storage Quota (MB) <span style="font-size:10px;opacity:.5">(leave blank for unlimited)</span></label>
        <input type="number" name="storage_quota_mb" min="1" placeholder="e.g. 500">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-create')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create User</button>
      </div>
    </form>
  </div>
</div>

<!-- Edit User Modal -->
<div class="modal-backdrop" id="modal-edit">
  <div class="modal">
    <h2>Edit User</h2>
    <form method="POST" action="/action_user_edit.php">
      <input type="hidden" name="id" id="edit-id">
      <div class="field">
        <label>Username</label>
        <input type="text" name="username" id="edit-username" required>
      </div>
      <div class="field">
        <label>New Password <span style="font-size:10px;opacity:.5">(leave blank to keep current)</span></label>
        <input type="password" name="password">
      </div>
      <div class="field">
        <label>Role</label>
        <select name="role" id="edit-role">
          <option value="user">User</option>
          <option value="admin">Admin</option>
        </select>
      </div>
      <div class="field">
        <label>Storage Quota (MB) <span style="font-size:10px;opacity:.5">(leave blank for unlimited)</span></label>
        <input type="number" name="storage_quota_mb" id="edit-quota" min="1" placeholder="e.g. 500">
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-edit')">Cancel</button>
        <button type="submit" class="btn btn-primary">Save Changes</button>
      </div>
    </form>
  </div>
</div>

<!-- User detail modal -->
<div class="modal-backdrop" id="modal-detail">
  <div class="modal wide">
    <div class="detail-header">
      <div class="avatar" id="detail-avatar"></div>
      <div>
        <div class="detail-title" id="detail-title">—</div>
        <div class="detail-sub" id="detail-sub">—</div>
      </div>
      <button class="detail-close" onclick="closeModal('modal-detail')">Close</button>
    </div>
    <div class="detail-summary">
      <div class="detail-summary-item"><span class="detail-summary-val" id="detail-files">0</span><span class="detail-summary-lbl">Files</span></div>
      <div class="detail-summary-item"><span class="detail-summary-val" id="detail-folders">0</span><span class="detail-summary-lbl">Folders</span></div>
      <div class="detail-summary-item"><span class="detail-summary-val" id="detail-size">0 B</span><span class="detail-summary-lbl">Used</span></div>
      <div class="detail-summary-item"><span class="detail-summary-val" id="detail-shared-out">0</span><span class="detail-summary-lbl">Shared out</span></div>
      <div class="detail-summary-item"><span class="detail-summary-val" id="detail-shared-in">0</span><span class="detail-summary-lbl">Shared with</span></div>
    </div>
    <div class="detail-tabs">
      <button class="detail-tab active" data-tab="owned" onclick="showDetailTab('owned')">Files they own</button>
      <button class="detail-tab" data-tab="shared" onclick="showDetailTab('shared')">Shared with them</button>
    </div>
    <div class="detail-body" id="detail-body-owned"></div>
    <div class="detail-body" id="detail-body-shared" style="display:none"></div>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

document.querySelectorAll('.modal-backdrop').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});

function filterUsers() {
  const q = document.getElementById('user-search').value.trim().toLowerCase();
  const rows = document.querySelectorAll('.user-table tbody tr');
  let visible = 0;
  rows.forEach(row => {
    const name  = (row.querySelector('.user-name')?.textContent || '').toLowerCase();
    const match = q === '' || name.includes(q);
    row.style.display = match ? '' : 'none';
    if (match) visible++;
  });
  document.getElementById('user-count').textContent = q ? `(${visible} of ${rows.length})` : `(${rows.length})`;
}

function openEdit(u) {
  document.getElementById('edit-id').value       = u.id;
  document.getElementById('edit-username').value = u.username;
  document.getElementById('edit-role').value     = u.role;
  const qMb = u.storage_quota ? Math.round(u.storage_quota / 1048576) : '';
  document.getElementById('edit-quota').value    = qMb;
  openModal('modal-edit');
}

// Click to copy USB Archive hash to clipboard with a brief "copied" flash
document.querySelectorAll('.archive-id').forEach(el => {
  el.addEventListener('click', async (e) => {
    e.stopPropagation();
    const hash = el.dataset.hash;
    if (!hash || hash === '—') return;
    try {
      await navigator.clipboard.writeText(hash);
      el.classList.add('copied');
      setTimeout(() => el.classList.remove('copied'), 700);
    } catch (err) { /* clipboard blocked - user can still select the text manually */ }
  });
});

// Row click -> open the user detail modal. Ignore clicks that originated
// from the inline action buttons or the archive pill.
document.querySelectorAll('tr.user-row').forEach(tr => {
  tr.addEventListener('click', e => {
    if (e.target.closest('.actions, .archive-id, button, a')) return;
    const userId = tr.dataset.userId;
    if (userId) openUserDetail(userId);
  });
});

function escapeHtml(s) {
  return String(s).replace(/[&<>"']/g, c => ({'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]));
}

function fileIcon(f) {
  if (f.is_folder) return '<span class="detail-file-icon folder">DIR</span>';
  const t = f.filetype || '';
  if (t.startsWith('image/')) return '<span class="detail-file-icon">IMG</span>';
  if (t.startsWith('video/')) return '<span class="detail-file-icon">VID</span>';
  if (t.startsWith('audio/')) return '<span class="detail-file-icon">AUD</span>';
  if (t === 'application/pdf') return '<span class="detail-file-icon">PDF</span>';
  if (t.includes('zip') || t.includes('tar')) return '<span class="detail-file-icon">ZIP</span>';
  return '<span class="detail-file-icon">FILE</span>';
}

function renderOwned(files, userCtx) {
  const body = document.getElementById('detail-body-owned');
  if (!files.length) {
    const note = userCtx && userCtx.role === 'admin'
      ? '<br><span style="font-size:11px;opacity:0.7">Admins have implicit access to every file via role — they don\'t need to own files to manage the system.</span>'
      : '';
    body.innerHTML = '<div class="detail-empty">This user hasn\'t uploaded anything yet.' + note + '</div>';
    return;
  }
  const rows = files.map(f => {
    const date = new Date(f.created_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    const sharedWith = f.shared_with.length
      ? f.shared_with.map(s => `<span class="share-chip" title="${escapeHtml(s.perms)}">${escapeHtml(s.username)} · ${escapeHtml(s.perms)}</span>`).join('')
      : '<span style="color:var(--muted);font-size:11px">—</span>';
    return `<tr>
      <td><div class="detail-file-name">${fileIcon(f)}<span>${escapeHtml(f.filename)}</span></div></td>
      <td style="color:var(--muted)">${escapeHtml(f.size_fmt)}</td>
      <td style="color:var(--muted)">${date}</td>
      <td>${sharedWith}</td>
    </tr>`;
  }).join('');
  body.innerHTML = `<table class="detail-table">
    <thead><tr><th>File</th><th>Size</th><th>Added</th><th>Shared with</th></tr></thead>
    <tbody>${rows}</tbody>
  </table>`;
}

function renderShared(files, userCtx) {
  const body = document.getElementById('detail-body-shared');
  if (!files.length) {
    const msg = userCtx && userCtx.role === 'admin'
      ? 'No explicit shares. Admins have implicit access to all files via their role.'
      : 'No files shared with this user.';
    body.innerHTML = '<div class="detail-empty">' + msg + '</div>';
    return;
  }
  const adminNote = userCtx && userCtx.role === 'admin'
    ? '<div style="padding:10px 28px;font-size:11px;color:var(--muted);background:rgba(0,191,255,0.04);border-bottom:1px solid var(--border);">These explicit shares remain from before this user was promoted. Admins already have access to every file via their role, so these rows are redundant.</div>'
    : '';
  const rows = files.map(f => {
    const date = new Date(f.created_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
    return `<tr>
      <td><div class="detail-file-name">${fileIcon(f)}<span>${escapeHtml(f.filename)}</span></div></td>
      <td style="color:var(--muted)">${escapeHtml(f.size_fmt)}</td>
      <td>${escapeHtml(f.owner_username)}</td>
      <td><span class="perm-pill">${escapeHtml(f.perms)}</span></td>
      <td style="color:var(--muted)">${date}</td>
    </tr>`;
  }).join('');
  body.innerHTML = adminNote + `<table class="detail-table">
    <thead><tr><th>File</th><th>Size</th><th>Owner</th><th>Permissions</th><th>Added</th></tr></thead>
    <tbody>${rows}</tbody>
  </table>`;
}

function showDetailTab(tab) {
  document.querySelectorAll('.detail-tab').forEach(b => b.classList.toggle('active', b.dataset.tab === tab));
  document.getElementById('detail-body-owned').style.display  = tab === 'owned'  ? 'block' : 'none';
  document.getElementById('detail-body-shared').style.display = tab === 'shared' ? 'block' : 'none';
}

async function openUserDetail(userId) {
  // Reset to owned tab first
  showDetailTab('owned');
  document.getElementById('detail-body-owned').innerHTML  = '<div class="detail-empty">Loading…</div>';
  document.getElementById('detail-body-shared').innerHTML = '';
  openModal('modal-detail');

  let data;
  try {
    const r = await fetch('/user_files.php?id=' + encodeURIComponent(userId), { credentials: 'same-origin' });
    if (!r.ok) throw new Error(r.status);
    data = await r.json();
  } catch (e) {
    document.getElementById('detail-body-owned').innerHTML = '<div class="detail-empty">Failed to load user details.</div>';
    return;
  }

  document.getElementById('detail-avatar').textContent = data.user.username.slice(0, 2).toUpperCase();
  document.getElementById('detail-title').textContent  = data.user.username;
  const joined = new Date(data.user.created_at).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
  document.getElementById('detail-sub').textContent    = `${data.user.role} · joined ${joined}`;
  document.getElementById('detail-files').textContent       = data.summary.files;
  document.getElementById('detail-folders').textContent     = data.summary.folders;
  document.getElementById('detail-size').textContent        = data.user.storage_used_fmt;
  document.getElementById('detail-shared-out').textContent  = data.summary.shared_by_them;
  document.getElementById('detail-shared-in').textContent   = data.summary.shared_with_them;

  // Admins have implicit access to every file via their role - explicit
  // permission rows aren't needed. But they can still have leftover rows
  // from before they were promoted, so always show the tab and let the
  // empty state explain the context.
  document.querySelector('.detail-tab[data-tab="shared"]').style.display = '';
  document.getElementById('detail-shared-in').parentElement.style.display = '';

  renderOwned(data.owned, data.user);
  renderShared(data.shared_with, data.user);
}
</script>
</body>
</html>
