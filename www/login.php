<?php
session_start();

// Redirect if already logged in
if (isset($_SESSION['user_id'])) {
    header('Location: index.php');
    exit;
}

// ── Login rate limiting: 5 failed attempts per IP per 5 minutes ──
$ip          = $_SERVER['REMOTE_ADDR'] ?? 'unknown';
$rate_dir    = '/tmp/nas_login_attempts';
@mkdir($rate_dir, 0700, true);
$rate_file   = $rate_dir . '/' . hash('sha256', $ip);
$now         = time();
$window      = 300;   // 5 minutes
$max_tries   = 5;

$attempts = [];
if (file_exists($rate_file)) {
    $attempts = array_filter(
        json_decode(file_get_contents($rate_file), true) ?: [],
        fn($t) => $t > $now - $window
    );
}
$locked_out = count($attempts) >= $max_tries;
$retry_in   = $locked_out ? $window - ($now - min($attempts)) : 0;

$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($locked_out) {
        $error = "Too many failed attempts. Try again in " . ceil($retry_in / 60) . " minute(s).";
    } else {
        $username = trim($_POST['username'] ?? '');
        $password = $_POST['password'] ?? '';

        if ($username === '' || $password === '') {
            $error = 'Please enter your username and password.';
        } else {
            require_once 'db.php';
            $stmt = $pdo->prepare('SELECT id, username, password, role FROM users WHERE username = ?');
            $stmt->execute([$username]);
            $user = $stmt->fetch();

            if ($user && password_verify($password, $user['password'])) {
                @unlink($rate_file); // clear attempts on success
                $pdo->prepare('UPDATE users SET last_login = NOW() WHERE id = ?')->execute([$user['id']]);

                $_SESSION['user_id']   = $user['id'];
                $_SESSION['username']  = $user['username'];
                $_SESSION['role']      = $user['role'];

                header('Location: index.php');
                exit;
            } else {
                $attempts[] = $now;
                file_put_contents($rate_file, json_encode(array_values($attempts)), LOCK_EX);
                $remaining = $max_tries - count($attempts);
                $error = $remaining > 0
                    ? "Invalid username or password. ($remaining attempt(s) remaining)"
                    : "Too many failed attempts. Try again in 5 minutes.";
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NAS — Sign In</title>
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

  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    min-height: 100vh;
    display: flex;
    align-items: center;
    justify-content: center;
    overflow: hidden;
  }

  /* Animated grid background */
  body::before {
    content: '';
    position: fixed;
    inset: 0;
    background-image:
      linear-gradient(var(--border) 1px, transparent 1px),
      linear-gradient(90deg, var(--border) 1px, transparent 1px);
    background-size: 40px 40px;
    opacity: 0.35;
    pointer-events: none;
  }

  /* Glow orb */
  body::after {
    content: '';
    position: fixed;
    top: -200px; left: 50%;
    transform: translateX(-50%);
    width: 700px; height: 700px;
    background: radial-gradient(circle, rgba(79,255,176,0.07) 0%, transparent 70%);
    pointer-events: none;
  }

  .card {
    position: relative;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 48px 44px;
    width: 100%;
    max-width: 420px;
    animation: fadeUp 0.5s ease both;
  }

  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(24px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  .logo {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 36px;
  }

  .logo-icon {
    width: 36px; height: 36px;
    background: linear-gradient(135deg, var(--accent), var(--accent2));
    border-radius: 8px;
    display: flex; align-items: center; justify-content: center;
    font-size: 18px;
  }

  .logo-text {
    font-family: 'Space Mono', monospace;
    font-size: 20px;
    font-weight: 700;
    letter-spacing: -0.5px;
  }

  .logo-text span {
    color: var(--accent);
  }

  h1 {
    font-size: 26px;
    font-weight: 500;
    margin-bottom: 6px;
    letter-spacing: -0.5px;
  }

  .subtitle {
    color: var(--muted);
    font-size: 14px;
    margin-bottom: 32px;
  }

  .field {
    margin-bottom: 18px;
  }

  label {
    display: block;
    font-size: 12px;
    font-weight: 500;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--muted);
    margin-bottom: 7px;
  }

  input[type="text"],
  input[type="password"] {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    font-size: 15px;
    padding: 11px 14px;
    outline: none;
    transition: border-color 0.2s;
  }

  input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(79,255,176,0.08);
  }

  .error {
    background: rgba(255,79,106,0.1);
    border: 1px solid rgba(255,79,106,0.3);
    color: var(--danger);
    border-radius: var(--radius);
    padding: 10px 14px;
    font-size: 13px;
    margin-bottom: 20px;
  }

  button[type="submit"] {
    width: 100%;
    background: var(--accent);
    color: #0d0f14;
    border: none;
    border-radius: var(--radius);
    font-family: 'DM Sans', sans-serif;
    font-size: 15px;
    font-weight: 600;
    padding: 13px;
    cursor: pointer;
    margin-top: 8px;
    letter-spacing: 0.02em;
    transition: opacity 0.15s, transform 0.15s;
  }

  button[type="submit"]:hover  { opacity: 0.92; transform: translateY(-1px); box-shadow: 0 6px 20px rgba(79,255,176,0.25); }
  button[type="submit"]:active { opacity: 1;    transform: translateY(0); box-shadow: 0 2px 6px rgba(79,255,176,0.15); }

  .footer {
    margin-top: 28px;
    font-size: 12px;
    color: var(--muted);
    text-align: center;
    font-family: 'Space Mono', monospace;
  }
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

  <h1>Welcome back</h1>
  <p class="subtitle">Sign in to access your storage</p>

  <?php if ($error): ?>
    <div class="error"><?= htmlspecialchars($error) ?></div>
  <?php endif; ?>

  <form method="POST" action="login.php">
    <div class="field">
      <label for="username">Username</label>
      <input type="text" id="username" name="username"
             value="<?= htmlspecialchars($_POST['username'] ?? '') ?>"
             autocomplete="username" autofocus>
    </div>
    <div class="field">
      <label for="password">Password</label>
      <input type="password" id="password" name="password" autocomplete="current-password">
    </div>
    <button type="submit">Sign In</button>
  </form>

  <div class="footer">
    Need an account? <a href="/register.php" style="color:var(--accent);text-decoration:none;font-family:'DM Sans',sans-serif;font-weight:500;">Create one</a>
  </div>
</div>
</body>
</html>
