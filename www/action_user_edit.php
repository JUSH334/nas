<?php
// action_user_edit.php
require_once 'auth.php';
require_admin();
require_once 'db.php';

$id       = (int)($_POST['id'] ?? 0);
$username = trim($_POST['username'] ?? '');
$email    = trim($_POST['email']    ?? '') ?: null;
$password = $_POST['password'] ?? '';
$role     = in_array($_POST['role'] ?? '', ['admin','user']) ? $_POST['role'] : 'user';

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
    $pdo->prepare('UPDATE users SET username=?, email=?, password=?, role=? WHERE id=?')
        ->execute([$username, $email, $hash, $role, $id]);
} else {
    $pdo->prepare('UPDATE users SET username=?, email=?, role=? WHERE id=?')
        ->execute([$username, $email, $role, $id]);
}

$_SESSION['flash'] = ['type' => 'success', 'msg' => "User \"$username\" updated."];
header('Location: /users.php');
