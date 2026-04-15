<?php
session_start();

// If already logged in, send to file manager
if (isset($_SESSION['user_id'])) {
    header('Location: /index.php');
    exit;
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = trim($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $confirm  = $_POST['confirm']  ?? '';

    // Validate username
    if ($username === '') {
        $error = 'Username is required.';
    } elseif (strlen($username) < 3) {
        $error = 'Username must be at least 3 characters.';
    } elseif (strlen($username) > 50) {
        $error = 'Username must be 50 characters or fewer.';
    } elseif (!preg_match('/^[a-zA-Z0-9_.-]+$/', $username)) {
        $error = 'Username can only contain letters, numbers, underscores, dots, and hyphens.';
    }
    // Validate password
    elseif ($password === '') {
        $error = 'Password is required.';
    } elseif (strlen($password) < 8) {
        $error = 'Password must be at least 8 characters.';
    } elseif ($password !== $confirm) {
        $error = 'Passwords do not match.';
    }
    else {
        require_once 'db.php';

        // Check username uniqueness
        $stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
        $stmt->execute([$username]);
        if ($stmt->fetch()) {
            $error = "Username \"$username\" is already taken.";
        } else {
            // Always create as 'user' role — admin accounts are created by existing admins only
            $hash = password_hash($password, PASSWORD_BCRYPT);
            $pdo->prepare('INSERT INTO users (username, password, role) VALUES (?, ?, ?)')
                ->execute([$username, $hash, 'user']);

            // Add the new self-registered user to the USB manifest so the watcher
            // picks them up on its next 3-second tick.
            require_once 'usb_manifest.php';
            update_user_manifest($pdo);

            $success = "Account created successfully! You can now sign in.";
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NAS — Create Account</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=DM+Sans:wght@300;400;500&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:       #0d0f14;
    --surface:  #161920;
    --border:   #2a2d38;
    --accent:   #4fffb0;
    --accent2:  #00bfff;
    --text:     #e8eaf0;
    --muted:    #6b7080;
    --danger:   #ff4f6a;
    --radius:   6px;
  }
  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
  body { background: var(--bg); color: var(--text); font-family: 'DM Sans', sans-serif;
         min-height: 100vh; display: flex; align-items: center; justify-content: center; overflow: hidden; }

  body::before {
    content: ''; position: fixed; inset: 0;
    background-image: linear-gradient(var(--border) 1px, transparent 1px),
                      linear-gradient(90deg, var(--border) 1px, transparent 1px);
    background-size: 40px 40px; opacity: 0.35; pointer-events: none;
  }
  body::after {
    content: ''; position: fixed; top: -200px; left: 50%; transform: translateX(-50%);
    width: 700px; height: 700px;
    background: radial-gradient(circle, rgba(79,255,176,0.07) 0%, transparent 70%);
    pointer-events: none;
  }

  .card {
    position: relative; background: var(--surface); border: 1px solid var(--border);
    border-radius: 12px; padding: 44px 40px; width: 100%; max-width: 420px;
    animation: fadeUp 0.5s ease both;
  }
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(24px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  .logo { display: flex; align-items: center; gap: 10px; margin-bottom: 32px; }
  .logo-icon {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 8px; display: flex; align-items: center; justify-content: center;
  }
  .logo-text { font-family: 'Space Mono', monospace; font-size: 20px; font-weight: 700; letter-spacing: -0.5px; }
  .logo-text span { color: var(--accent); }

  h1 { font-size: 24px; font-weight: 500; margin-bottom: 6px; letter-spacing: -0.5px; }
  .subtitle { color: var(--muted); font-size: 13px; margin-bottom: 28px; }

  .field { margin-bottom: 16px; }
  label { display: block; font-size: 11px; font-weight: 500; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }
  input[type="text"], input[type="password"] {
    width: 100%; background: var(--bg); border: 1px solid var(--border); border-radius: var(--radius);
    color: var(--text); font-family: 'DM Sans', sans-serif; font-size: 14px; padding: 10px 12px;
    outline: none; transition: border-color 0.2s;
  }
  input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(79,255,176,0.08); }
  .hint { font-size: 11px; color: var(--muted); margin-top: 5px; }

  .error, .success {
    border-radius: var(--radius); padding: 10px 14px; font-size: 13px; margin-bottom: 18px;
  }
  .error { background: rgba(255,79,106,0.1); border: 1px solid rgba(255,79,106,0.3); color: var(--danger); }
  .success { background: rgba(79,255,176,0.1); border: 1px solid rgba(79,255,176,0.3); color: var(--accent); }

  button[type="submit"] {
    width: 100%; background: var(--accent); color: #0d0f14; border: none; border-radius: var(--radius);
    font-family: 'DM Sans', sans-serif; font-size: 15px; font-weight: 600; padding: 12px;
    cursor: pointer; margin-top: 6px; letter-spacing: 0.02em;
    transition: opacity 0.15s, transform 0.15s;
  }
  button[type="submit"]:hover { opacity: 0.92; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(79,255,176,0.25); }
  button[type="submit"]:active { opacity: 1; transform: translateY(0); box-shadow: 0 2px 6px rgba(79,255,176,0.15); }

  .footer { margin-top: 22px; font-size: 12px; color: var(--muted); text-align: center; }
  .footer a { color: var(--accent); text-decoration: none; font-weight: 500; }
  .footer a:hover { text-decoration: underline; }
</style>
</head>
<body>
<div class="card">
  <div class="logo">
    <div class="logo-icon">
      <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#0d0f14" stroke-width="2.5" stroke-linecap="round" stroke-linejoin="round"><ellipse cx="12" cy="5" rx="9" ry="3"/><path d="M3 5v14a9 3 0 0 0 18 0V5"/><path d="M3 12a9 3 0 0 0 18 0"/></svg>
    </div>
    <div class="logo-text">N<span>A</span>S</div>
  </div>

  <h1>Create your account</h1>
  <p class="subtitle">Join the NAS server as a new user</p>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>
  <?php if ($success): ?>
    <div class="success"><?= htmlspecialchars($success) ?></div>
  <?php endif; ?>

  <form method="POST" action="register.php">
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username"
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
             autocomplete="username" autofocus
             minlength="3" maxlength="50"
             pattern="[a-zA-Z0-9_.\-]+">
      <p class="hint">3-50 characters. Letters, numbers, _ . - only.</p>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="new-password" minlength="8">
      <p class="hint">Minimum 8 characters.</p>
    </div>
    <div class="field">
      <label for="confirm">Confirm Password</label>
      <input type="password" id="confirm" name="confirm" autocomplete="new-password" minlength="8">
    </div>
    <button type="submit">Create Account</button>
  </form>

  <div class="footer">
    Already have an account? <a href="/login.php">Sign in</a>
  </div>
</div>
</body>
</html>
