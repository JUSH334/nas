<?php
require_once 'auth.php';
require_login();
require_once 'db.php';

$user      = current_user();
$id        = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$folder_id = !empty($_GET['folder']) ? (int)$_GET['folder'] : null;
$redirect  = $folder_id ? "/?folder=$folder_id" : '/';

$stmt = $pdo->prepare('SELECT * FROM files WHERE id = ?');
$stmt->execute([$id]);
$file = $stmt->fetch();

if (!$file) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Item not found.'];
    header("Location: $redirect"); exit;
}

if ($file['owner_id'] != $user['id'] && !is_admin()) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Access denied.'];
    header("Location: $redirect"); exit;
}

// Delete from disk if it's a file
if (!$file['is_folder'] && $file['filepath']) {
    $path = '/var/www/uploads/' . $file['filepath'];
    if (file_exists($path)) unlink($path);
}

// DB delete cascades to children + permissions via foreign keys
$pdo->prepare('DELETE FROM files WHERE id = ?')->execute([$id]);

$_SESSION['flash'] = ['type' => 'success', 'msg' => '"' . htmlspecialchars($file['filename']) . '" deleted.'];
header("Location: $redirect");
