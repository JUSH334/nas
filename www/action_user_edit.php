<?php
// action_user_edit.php
require_once 'auth.php';
require_admin();
require_once 'db.php';
require_once 'usb_manifest.php';

$id       = (int)($_POST['id'] ?? 0);
$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email']    ?? '') ?: null;
$password = $_POST['password'] ?? '';
$role     = in_array($_POST['role'] ?? '', ['admin','user']) ? $_POST['role'] : 'user';

// Quota: convert MB to bytes; empty/0 = unlimited (NULL)
$quota_mb = trim($_POST['storage_quota_mb'] ?? '');
$quota    = ($quota_mb !== '' && (int)$quota_mb > 0) ? (int)$quota_mb * 1048576 : null;

if (!$id || $username === '') {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Invalid request.'];
    header('Location: /users.php'); exit;
}

// Check duplicate username (excluding this user)
$stmt = $pdo->prepare('SELECT id FROM users WHERE username = ? AND id != ?');
$stmt->execute([$username, $id]);
if ($stmt->fetch()) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => "Username \"$username\" is already taken."];
    header('Location: /users.php'); exit;
}

if ($password !== '') {
    if (strlen($password) < 8) {
        $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Password must be at least 8 characters.'];
        header('Location: /users.php'); exit;
    }
    $hash = password_hash($password, PASSWORD_BCRYPT);
    $pdo->prepare('UPDATE users SET username=?, email=?, password=?, role=?, storage_quota=? WHERE id=?')
        ->execute([$username, $email, $hash, $role, $quota, $id]);
} else {
    $pdo->prepare('UPDATE users SET username=?, email=?, role=?, storage_quota=? WHERE id=?')
        ->execute([$username, $email, $role, $quota, $id]);
}

// Refresh manifest so username changes flow through to the UI side of the
// USB archive display. (Hash stays stable - it's keyed by user_id + salt.)
update_user_manifest($pdo);

$_SESSION['flash'] = ['type' => 'success', 'msg' => "User \"$username\" updated."];
header('Location: /users.php');
