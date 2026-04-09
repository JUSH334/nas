<?php
require_once 'auth.php';
require_admin();
require_once 'db.php';

$user    = current_user();
$file_id = (int)($_GET['file_id'] ?? 0);
$message = '';
$error   = '';

// Get the file/folder info
$stmt = $pdo->prepare('SELECT * FROM files WHERE id = ?');
$stmt->execute([$file_id]);
$file = $stmt->fetch();

if (!$file) {
    header('Location: /'); exit;
}

// Handle permission update
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $target_user = (int)($_POST['user_id'] ?? 0);
    $can_read    = isset($_POST['can_read'])   ? 1 : 0;
    $can_write   = isset($_POST['can_write'])  ? 1 : 0;
    $can_delete  = isset($_POST['can_delete']) ? 1 : 0;

    // Upsert permission
    $stmt = $pdo->prepare('
        INSERT INTO permissions (file_id, user_id, can_read, can_write, can_delete)
        VALUES (?, ?, ?, ?, ?)
        ON DUPLICATE KEY UPDATE can_read = VALUES(can_read), can_write = VALUES(can_write), can_delete = VALUES(can_delete)
    ');
    $stmt->execute([$file_id, $target_user, $can_read, $can_write, $can_delete]);
    $message = 'Permissions updated.';
}

// Handle permission removal
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove') {
    $target_user = (int)($_POST['user_id'] ?? 0);
    $pdo->prepare('DELETE FROM permissions WHERE file_id = ? AND user_id = ?')->execute([$file_id, $target_user]);
    $message = 'Permission removed.';
}

// Get current permissions for this file
$perms = $pdo->prepare('
    SELECT p.*, u.username
    FROM permissions p
    JOIN users u ON p.user_id = u.id
    WHERE p.file_id = ?
    ORDER BY u.username
');
$perms->execute([$file_id]);
$permissions = $perms->fetchAll();

// Get users who don't have permissions set yet (for adding)
$assigned_ids = array_column($permissions, 'user_id');
$assigned_ids[] = $file['owner_id']; // exclude owner
$placeholders = implode(',', array_fill(0, count($assigned_ids), '?'));
$available = $pdo->prepare("SELECT id, username, role FROM users WHERE id NOT IN ($placeholders) ORDER BY username");
$available->execute($assigned_ids);
$available_users = $available->fetchAll();

// Get owner info
$owner_stmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
$owner_stmt->execute([$file['owner_id']]);
$owner = $owner_stmt->fetch();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NAS — Permissions</title>
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

  main { padding: 28px; max-width: 800px; width: 100%; margin: 0 auto; flex: 1; }
  h1 { font-size: 20px; font-weight: 500; margin-bottom: 8px; }
  .subtitle { font-size: 13px; color: var(--muted); margin-bottom: 24px; }

  .alert { padding: 12px 18px; border-radius: var(--radius); font-size: 13px; margin-bottom: 20px; }
  .alert-success { background: rgba(79,255,176,0.1); border: 1px solid var(--accent); color: var(--accent); }
  .alert-error   { background: rgba(255,79,106,0.1); border: 1px solid var(--danger); color: var(--danger); }

  .panel { background: var(--surface); border: 1px solid var(--border); border-radius: 10px; overflow: hidden; margin-bottom: 24px; }
  .panel-header { padding: 16px 20px; border-bottom: 1px solid var(--border); font-size: 13px; font-weight: 500; }

  .perm-row {
    display: flex; align-items: center; gap: 16px;
    padding: 14px 20px; border-bottom: 1px solid rgba(42,45,56,0.5);
  }
  .perm-row:last-child { border-bottom: none; }
  .perm-user { font-weight: 500; font-size: 14px; min-width: 120px; }
  .perm-checks { display: flex; gap: 16px; flex: 1; }
  .perm-check { display: flex; align-items: center; gap: 6px; font-size: 13px; }
  .perm-check input { accent-color: var(--accent); width: 16px; height: 16px; }
  .perm-actions { flex-shrink: 0; }

  .btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: var(--radius); font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500; cursor: pointer; border: none; text-decoration: none; transition: opacity 0.15s; }
  .btn:hover { opacity: 0.85; }
  .btn-primary   { background: var(--accent); color: #0d0f14; }
  .btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
  .btn-danger    { background: rgba(255,79,106,0.12); color: var(--danger); border: 1px solid rgba(255,79,106,0.25); }
  .btn-small     { padding: 5px 12px; font-size: 12px; }

  .add-form { padding: 20px; display: flex; align-items: center; gap: 12px; flex-wrap: wrap; }
  .add-form select { background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius); color: var(--text); font-size: 13px; padding: 8px 12px; }
  .add-form select:focus { border-color: var(--accent); outline: none; }

  .owner-badge { font-size: 11px; color: var(--accent); font-family: 'Space Mono', monospace; margin-left: 6px; }
  .empty { padding: 32px 20px; text-align: center; color: var(--muted); font-size: 13px; }

  .back-link { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); text-decoration: none; font-size: 13px; margin-bottom: 20px; }
  .back-link:hover { color: var(--accent); }
</style>
</head>
<body>

<nav>
  <a class="nav-logo" href="/">NAS</a>
  <div class="nav-links">
    <a class="nav-link" href="/">Files</a>
    <?php if (is_admin()): ?>
    <a class="nav-link" href="/users.php">Users</a>
    <a class="nav-link" href="/monitor.php">Monitor</a>
    <a class="nav-link" href="/backup.php">Backups</a>
    <?php endif; ?>
  </div>
  <div class="nav-user">
    <span>Hello, <strong><?= htmlspecialchars($user['username']) ?></strong></span>
    <?php if (is_admin()): ?><span style="color:var(--accent);font-size:11px;font-family:'Space Mono',monospace">ADMIN</span><?php endif; ?>
    <a href="/logout.php">Sign out</a>
  </div>
</nav>

<main>
  <a class="back-link" href="<?= $file['parent_id'] ? '/?folder=' . $file['parent_id'] : '/' ?>">&larr; Back to files</a>

  <h1><?= $file['is_folder'] ? '📁' : '📄' ?> <?= htmlspecialchars($file['filename']) ?></h1>
  <p class="subtitle">Owner: <?= htmlspecialchars($owner['username'] ?? 'unknown') ?><span class="owner-badge">OWNER</span> &middot; Manage who can access this <?= $file['is_folder'] ? 'folder' : 'file' ?></p>

  <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- Current permissions -->
  <div class="panel">
    <div class="panel-header">User Permissions (<?= count($permissions) ?>)</div>
    <?php if (empty($permissions)): ?>
      <div class="empty">No permissions assigned yet. Only the owner can access this <?= $file['is_folder'] ? 'folder' : 'file' ?>.</div>
    <?php else: ?>
      <?php foreach ($permissions as $p): ?>
      <form method="post" class="perm-row">
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="user_id" value="<?= $p['user_id'] ?>">
        <span class="perm-user"><?= htmlspecialchars($p['username']) ?></span>
        <div class="perm-checks">
          <label class="perm-check">
            <input type="checkbox" name="can_read" <?= $p['can_read'] ? 'checked' : '' ?>> Read
          </label>
          <label class="perm-check">
            <input type="checkbox" name="can_write" <?= $p['can_write'] ? 'checked' : '' ?>> Write
          </label>
          <label class="perm-check">
            <input type="checkbox" name="can_delete" <?= $p['can_delete'] ? 'checked' : '' ?>> Delete
          </label>
        </div>
        <div class="perm-actions" style="display:flex;gap:6px;">
          <button type="submit" class="btn btn-primary btn-small">Save</button>
          <button type="submit" name="action" value="remove" class="btn btn-danger btn-small" onclick="return confirm('Remove permissions for <?= htmlspecialchars(addslashes($p['username'])) ?>?')">Remove</button>
        </div>
      </form>
      <?php endforeach; ?>
    <?php endif; ?>

    <!-- Add new user permission -->
    <?php if (!empty($available_users)): ?>
    <form method="post" class="add-form" style="border-top: 1px solid var(--border);">
      <input type="hidden" name="action" value="update">
      <select name="user_id" required>
        <option value="">Select a user...</option>
        <?php foreach ($available_users as $au): ?>
        <option value="<?= $au['id'] ?>"><?= htmlspecialchars($au['username']) ?> (<?= $au['role'] ?>)</option>
        <?php endforeach; ?>
      </select>
      <label class="perm-check"><input type="checkbox" name="can_read" checked> Read</label>
      <label class="perm-check"><input type="checkbox" name="can_write"> Write</label>
      <label class="perm-check"><input type="checkbox" name="can_delete"> Delete</label>
      <button type="submit" class="btn btn-primary btn-small">Add</button>
    </form>
    <?php endif; ?>
  </div>
</main>

</body>
</html>
