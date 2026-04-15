<?php
require_once 'auth.php';
require_login();
require_once 'db.php';

$user = current_user();
$message = '';
$error = '';

// Fetch current user record
$stmt = $pdo->prepare('SELECT id, username, email, role FROM users WHERE id = ?');
$stmt->execute([$user['id']]);
$me = $stmt->fetch();

// Handle username change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_username') {
    $new_username = trim($_POST['new_username'] ?? '');
    $current_password = $_POST['current_password'] ?? '';

    if ($new_username === '') {
        $error = 'Username cannot be empty.';
    } elseif (strlen($new_username) < 3) {
        $error = 'Username must be at least 3 characters.';
    } elseif (strlen($new_username) > 50) {
        $error = 'Username must be 50 characters or fewer.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $new_username)) {
        $error = 'Username can only contain letters, numbers, underscores, dots, and hyphens.';
    } else {
        // Verify current password for security
        $pwstmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
        $pwstmt->execute([$user['id']]);
        $hash = $pwstmt->fetchColumn();
        if (!password_verify($current_password, $hash)) {
            $error = 'Current password is incorrect.';
        } else {
            // Check uniqueness
            $check = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
            $check->execute([$new_username, $user['id']]);
            if ($check->fetch()) {
                $error = "Username \"$new_username\" is already taken.";
            } else {
                $pdo->prepare('UPDATE users SET username = ? WHERE id = ?')->execute([$new_username, $user['id']]);
                $_SESSION['username'] = $new_username;
                $message = 'Username updated successfully.';
                $me['username'] = $new_username;
            }
        }
    }
}

// Handle password change
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'change_password') {
    $current_password = $_POST['current_password'] ?? '';
    $new_password     = $_POST['new_password']     ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    $pwstmt = $pdo->prepare('SELECT password FROM users WHERE id = ?');
    $pwstmt->execute([$user['id']]);
    $hash = $pwstmt->fetchColumn();

    if (!password_verify($current_password, $hash)) {
        $error = 'Current password is incorrect.';
    } elseif (strlen($new_password) < 8) {
        $error = 'New password must be at least 8 characters.';
    } elseif ($new_password !== $confirm_password) {
        $error = 'New passwords do not match.';
    } elseif ($new_password === $current_password) {
        $error = 'New password must be different from your current password.';
    } else {
        $new_hash = password_hash($new_password, PASSWORD_BCRYPT);
        $pdo->prepare('UPDATE users SET password = ? WHERE id = ?')->execute([$new_hash, $user['id']]);
        $message = 'Password changed successfully.';
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NAS — My Profile</title>
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
  .nav-link { color: var(--muted); text-decoration: none; font-size: 14px; padding: 6px 12px; border-radius: var(--radius); transition: color 0.15s, background 0.15s, box-shadow 0.15s; }
  .nav-link:hover, .nav-link.active { color: var(--text); background: var(--surface2); }
  .nav-link:hover { box-shadow: inset 0 0 0 1px rgba(79,255,176,0.2); }
  .nav-link.active { color: var(--accent); box-shadow: inset 0 0 0 1px rgba(79,255,176,0.3); }
  .nav-user { display: flex; align-items: center; gap: 10px; font-size: 13px; color: var(--muted); }
  .nav-user strong { color: var(--text); }
  .nav-user a { color: var(--muted); text-decoration: none; font-size: 12px; padding: 4px 10px; border: 1px solid var(--border); border-radius: var(--radius); transition: border-color 0.15s, color 0.15s; }
  .nav-user a[href="/logout.php"]:hover { border-color: var(--danger); color: var(--danger); }

  main { padding: 28px; max-width: 720px; width: 100%; margin: 0 auto; flex: 1; }

  .page-hero {
    display: flex; align-items: center; justify-content: space-between; gap: 20px;
    padding: 22px 26px; margin-bottom: 24px;
    background: linear-gradient(135deg, var(--surface) 0%, var(--surface2) 100%);
    border: 1px solid var(--border); border-radius: 10px;
    position: relative; overflow: hidden;
    animation: fadeUp 0.4s ease both;
  }
  .page-hero::before {
    content: ''; position: absolute; top: -60px; right: -60px; width: 220px; height: 220px;
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
  .hero-avatar {
    width: 60px; height: 60px; border-radius: 50%;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    display: flex; align-items: center; justify-content: center;
    font-family: 'Space Mono', monospace; font-size: 22px; font-weight: 700; color: #0d0f14;
    flex-shrink: 0; position: relative; z-index: 1;
  }

  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(12px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  .panel {
    background: var(--surface); border: 1px solid var(--border); border-radius: 10px;
    padding: 24px 26px; margin-bottom: 20px;
    animation: fadeUp 0.4s ease both;
  }
  .panel:nth-of-type(1) { animation-delay: 0.05s; }
  .panel:nth-of-type(2) { animation-delay: 0.10s; }

  .panel h2 {
    font-size: 16px; font-weight: 500; margin-bottom: 4px;
  }
  .panel-sub {
    font-size: 13px; color: var(--muted); margin-bottom: 20px;
  }

  .field { margin-bottom: 14px; }
  label {
    display: block; font-size: 11px; font-weight: 500; letter-spacing: 0.08em;
    text-transform: uppercase; color: var(--muted); margin-bottom: 6px;
  }
  input[type="text"], input[type="password"] {
    width: 100%; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--text); font-family: 'DM Sans', sans-serif; font-size: 14px; padding: 10px 12px;
    outline: none; transition: border-color 0.2s, box-shadow 0.2s;
  }
  input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(79,255,176,0.08); }
  .hint { font-size: 11px; color: var(--muted); margin-top: 5px; }

  .alert { padding: 10px 14px; border-radius: var(--radius); font-size: 13px; margin-bottom: 18px; animation: fadeUp 0.3s ease both; }
  .alert-success { background: rgba(79,255,176,0.1); border: 1px solid rgba(79,255,176,0.3); color: var(--accent); }
  .alert-error   { background: rgba(255,79,106,0.1); border: 1px solid rgba(255,79,106,0.3); color: var(--danger); }

  .btn {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 9px 18px; border-radius: var(--radius);
    font-family: 'DM Sans', sans-serif; font-size: 13px; font-weight: 500;
    cursor: pointer; border: none; text-decoration: none;
    transition: opacity 0.15s, transform 0.1s, box-shadow 0.15s;
  }
  .btn:hover { opacity: 0.92; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(79,255,176,0.15); }
  .btn:active { transform: translateY(0); }
  .btn-primary { background: var(--accent); color: #0d0f14; }

  .role-badge {
    font-size: 10px; font-weight: 700; letter-spacing: 0.1em;
    font-family: 'Space Mono', monospace;
    padding: 3px 8px; border-radius: 4px; text-transform: uppercase;
  }
  .role-badge.admin { color: #00bfff; background: rgba(0,191,255,0.1); border: 1px solid rgba(0,191,255,0.3); }
  .role-badge.user { color: var(--muted); background: var(--surface2); border: 1px solid var(--border); }

  .back-link { display: inline-flex; align-items: center; gap: 6px; color: var(--muted); text-decoration: none; font-size: 13px; margin-bottom: 20px; transition: color 0.15s; }
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
    <a class="nav-link" href="/logs.php">Logs</a>
    <a class="nav-link" href="/backup.php">Backups</a>
    <?php endif; ?>
  </div>
  <div class="nav-user">
    <span>Hello, <strong><?= htmlspecialchars($me['username']) ?></strong></span>
    <span class="role-badge <?= $me['role'] ?>"><?= $me['role'] === 'admin' ? 'Admin' : 'User' ?></span>
    <a href="/logout.php">Sign out</a>
  </div>
</nav>

<main>
  <a class="back-link" href="/">&larr; Back to files</a>

  <div class="page-hero">
    <div>
      <h1 class="hero-title">My Profile</h1>
      <p class="hero-sub">Update your username and password. Your changes are saved securely.</p>
    </div>
    <div class="hero-avatar"><?= strtoupper(substr($me['username'], 0, 2)) ?></div>
  </div>

  <?php if ($message): ?>
    <div class="alert alert-success"><?= htmlspecialchars($message) ?></div>
  <?php endif; ?>
  <?php if ($error): ?>
    <div class="alert alert-error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <!-- Change Username -->
  <div class="panel">
    <h2>Change Username</h2>
    <p class="panel-sub">Current: <strong><?= htmlspecialchars($me['username']) ?></strong></p>
    <form method="post">
      <input type="hidden" name="action" value="change_username">
      <div class="field">
        <label>New Username</label>
        <input type="text" name="new_username" minlength="3" maxlength="50" pattern="[a-zA-Z0-9_.\-]+" required>
        <p class="hint">3-50 characters. Letters, numbers, _ . - only.</p>
      </div>
      <div class="field">
        <label>Current Password</label>
        <input type="password" name="current_password" required autocomplete="current-password">
        <p class="hint">Required for security.</p>
      </div>
      <button type="submit" class="btn btn-primary">Update Username</button>
    </form>
  </div>

  <!-- Change Password -->
  <div class="panel">
    <h2>Change Password</h2>
    <p class="panel-sub">Choose a strong password you don't use elsewhere.</p>
    <form method="post">
      <input type="hidden" name="action" value="change_password">
      <div class="field">
        <label>Current Password</label>
        <input type="password" name="current_password" required autocomplete="current-password">
      </div>
      <div class="field">
        <label>New Password</label>
        <input type="password" name="new_password" minlength="8" required autocomplete="new-password">
        <p class="hint">Minimum 8 characters.</p>
      </div>
      <div class="field">
        <label>Confirm New Password</label>
        <input type="password" name="confirm_password" minlength="8" required autocomplete="new-password">
      </div>
      <button type="submit" class="btn btn-primary">Change Password</button>
    </form>
  </div>
</main>

</body>
</html>
