<?php
// action_user_delete.php
require_once 'auth.php';
require_admin();
require_once 'db.php';

$current = current_user();
$id      = (int)($_GET['id'] ?? 0);

if (!$id || $id === $current['id']) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Cannot delete yourself.'];
    header('Location: /users.php'); exit;
}

$stmt = $pdo->prepare('SELECT username FROM users WHERE id = ?');
$stmt->execute([$id]);
$target = $stmt->fetch();

if (!$target) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'User not found.'];
    header('Location: /users.php'); exit;
}

// Delete physical files from disk
$file_stmt = $pdo->prepare('SELECT filepath FROM files WHERE owner_id = ? AND is_folder = 0');
$file_stmt->execute([$id]);
foreach ($file_stmt->fetchAll() as $f) {
    $path = '/var/www/uploads/' . $f['filepath'];
    if (file_exists($path)) unlink($path);
}

// Remove upload directory if empty
$dir = "/var/www/uploads/$id";
if (is_dir($dir)) @rmdir($dir);

// DB cascade handles files + permissions rows
$pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);

$_SESSION['flash'] = ['type' => 'success', 'msg' => "User \"{$target['username']}\" deleted."];
header('Location: /users.php');
