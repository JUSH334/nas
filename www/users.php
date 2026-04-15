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
      <tr<?= $is_self ? ' class="self-row"' : '' ?>>
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
  el.addEventListener('click', async () => {
    const hash = el.dataset.hash;
    if (!hash || hash === '—') return;
    try {
      await navigator.clipboard.writeText(hash);
      el.classList.add('copied');
      setTimeout(() => el.classList.remove('copied'), 700);
    } catch (e) { /* clipboard blocked - user can still select the text manually */ }
  });
});
</script>
</body>
</html>
