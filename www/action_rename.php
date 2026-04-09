<?php
require_once 'auth.php';
require_login();
require_once 'db.php';

$user      = current_user();
$id        = (int)($_POST['id'] ?? 0);
$new_name  = trim($_POST['new_name'] ?? '');
$folder_id = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
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

if ($new_name === '') {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Name cannot be empty.'];
    header("Location: $redirect"); exit;
}

$safe_name = preg_replace('/[^\w.\- ]/', '_', $new_name);

// If it's a real file, rename on disk too
if (!$file['is_folder'] && $file['filepath']) {
    $upload_dir = '/var/www/uploads/';
    $old_path = $upload_dir . $file['filepath'];
    $dir = dirname($file['filepath']);
    $new_filepath = ($dir === '.' ? '' : $dir . '/') . $safe_name;
    $new_path = $upload_dir . $new_filepath;

    if (file_exists($old_path)) {
        rename($old_path, $new_path);
    }

    $stmt = $pdo->prepare('UPDATE files SET filename = ?, filepath = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$safe_name, $new_filepath, $id]);
} else {
    $stmt = $pdo->prepare('UPDATE files SET filename = ?, updated_at = NOW() WHERE id = ?');
    $stmt->execute([$safe_name, $id]);
}

$_SESSION['flash'] = ['type' => 'success', 'msg' => "Renamed to \"$safe_name\"."];
header("Location: $redirect");
