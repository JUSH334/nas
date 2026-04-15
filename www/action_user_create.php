<?php
// action_user_create.php
require_once 'auth.php';
require_admin();
require_once 'db.php';
require_once 'usb_manifest.php';

$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email']    ?? '') ?: null;
$password = $_POST['password'] ?? '';
$role     = in_array($_POST['role'] ?? '', ['admin','user']) ? $_POST['role'] : 'user';

// Quota: convert MB input to bytes; empty/0 = unlimited (NULL)
$quota_mb = trim($_POST['storage_quota_mb'] ?? '');
$quota    = ($quota_mb !== '' && (int)$quota_mb > 0) ? (int)$quota_mb * 1048576 : null;

if ($username === '' || strlen($password) < 8) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Username required and password must be at least 8 characters.'];
    header('Location: /users.php'); exit;
}

// Check for duplicate username
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ?');
$stmt->execute([$username]);
if ($stmt->fetch()) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => "Username \"$username\" is already taken."];
    header('Location: /users.php'); exit;
}

$hash = password_hash($password, PASSWORD_BCRYPT);
$pdo->prepare('INSERT INTO users (username, email, password, role, storage_quota) VALUES (?, ?, ?, ?, ?)')
    ->execute([$username, $email, $hash, $role, $quota]);

// Add the new user to the USB manifest so the watcher starts mirroring their
// uploads the moment they create a file.
update_user_manifest($pdo);

$_SESSION['flash'] = ['type' => 'success', 'msg' => "User \"$username\" created."];
header('Location: /users.php');
