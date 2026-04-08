<?php
require_once 'auth.php';
require_admin();
require_once 'db.php';

$user = current_user();

// Fetch all users
$users = $pdo->query('
    SELECT id, username, email, role, created_at, last_login
    FROM users
    ORDER BY created_at DESC
')->fetchAll();
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
  .nav-link.active { color: var(--accent); }
  .nav-user { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--muted); }
  .nav-user strong { color: var(--text); }
  .nav-user a { color: var(--muted); text-decoration: none; font-size: 12px; padding: 4px 10px; border: 1px solid var(--border); border-radius: var(--radius); transition: border-color 0.15s, color 0.15s; }
  .nav-user a:hover { border-color: var(--danger); color: var(--danger); }

  main { padding: 28px; max-width: 1000px; width: 100%; margin: 0 auto; flex: 1; }

  .toolbar { display: flex; align-items: center; gap: 10px; margin-bottom: 24px; }
  .toolbar h1 { font-size: 20px; font-weight: 500; flex: 1; }

  .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: var(--radius); font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; border: none; text-decoration: none; transition: opacity 0.15s, transform 0.1s; }
  .btn:hover { opacity: 0.85; transform: translateY(-1px); }
  .btn-primary   { background: var(--accent); color: #0d0f14; }
  .btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
  .btn-danger    { background: rgba(255,79,106,0.12); color: var(--danger); border: 1px solid rgba(255,79,106,0.25); }

  .flash { padding: 10px 16px; border-radius: var(--radius); font-size: 13px; margin-bottom: 18px; }
  .flash.success { background: rgba(79,255,176,0.1); border: 1px solid rgba(79,255,176,0.3); color: var(--accent); }
  .flash.error   { background: rgba(255,79,106,0.1); border: 1px solid rgba(255,79,106,0.3); color: var(--danger); }

  .user-table { width: 100%; border-collapse: collapse; font-size: 14px; }
  .user-table thead tr { border-bottom: 1px solid var(--border); }
  .user-table th { text-align: left; padding: 10px 14px; font-size: 11px; font-weight: 500; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); }
  .user-table tbody tr { border-bottom: 1px solid rgba(42,45,56,0.5); transition: background 0.12s; }
  .user-table tbody tr:hover { background: var(--surface2); }
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

  .actions { display: flex; gap: 8px; }
  .action-btn { background: none; border: none; color: var(--muted); cursor: pointer; font-size: 13px; padding: 4px 10px; border-radius: var(--radius); text-decoration: none; transition: color 0.12s, background 0.12s; }
  .action-btn:hover { background: var(--surface2); color: var(--text); }
  .action-btn.del:hover { color: var(--danger); }

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
    <a class="nav-link" href="/">📁 Files</a>
    <a class="nav-link active" href="/users.php">👥 Users</a>
    <a class="nav-link" href="/monitor.php">📊 Monitor</a>
  </div>
  <div class="nav-user">
    <span>Hello, <strong><?= htmlspecialchars($user['username']) ?></strong></span>
    <span style="color:var(--accent);font-size:11px;font-family:'Space Mono',monospace">ADMIN</span>
    <a href="/logout.php">Sign out</a>
  </div>
</nav>

<main>
  <div class="toolbar">
    <h1>Users <span style="color:var(--muted);font-size:15px;font-weight:400">(<?= count($users) ?>)</span></h1>
    <button class="btn btn-primary" onclick="openModal('modal-create')">＋ New User</button>
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
        <th>Joined</th>
        <th>Last Login</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($users as $u): ?>
      <tr>
        <td>
          <div class="user-info">
            <div class="avatar"><?= strtoupper(substr($u['username'], 0, 2)) ?></div>
            <div>
              <div class="user-name"><?= htmlspecialchars($u['username']) ?></div>
              <div class="user-email"><?= htmlspecialchars($u['email'] ?? '—') ?></div>
            </div>
          </div>
        </td>
        <td>
          <span class="badge badge-<?= $u['role'] ?>"><?= strtoupper($u['role']) ?></span>
        </td>
        <td class="meta"><?= date('M j, Y', strtotime($u['created_at'])) ?></td>
        <td class="meta"><?= $u['last_login'] ? date('M j, Y g:i a', strtotime($u['last_login'])) : 'Never' ?></td>
        <td>
          <div class="actions">
            <button class="action-btn" onclick='openEdit(<?= json_encode($u) ?>)' title="Edit">✏️</button>
            <?php if ($u['id'] != $user['id']): ?>
            <a class="action-btn del"
               href="/action_user_delete.php?id=<?= $u['id'] ?>"
               onclick="return confirm('Delete user <?= htmlspecialchars(addslashes($u['username'])) ?>? This also deletes all their files.')"
               title="Delete">🗑</a>
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
        <label>Email <span style="font-size:10px;opacity:.5">(optional)</span></label>
        <input type="email" name="email">
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
        <label>Email</label>
        <input type="email" name="email" id="edit-email">
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

function openEdit(u) {
  document.getElementById('edit-id').value       = u.id;
  document.getElementById('edit-username').value = u.username;
  document.getElementById('edit-email').value    = u.email || '';
  document.getElementById('edit-role').value     = u.role;
  openModal('modal-edit');
}
</script>
</body>
</html>
