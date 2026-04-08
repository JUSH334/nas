<?php
require_once 'auth.php';
require_login();
require_once 'db.php';

$user      = current_user();
$parent_id = !empty($_POST['parent_id']) ? (int)$_POST['parent_id'] : null;
$name      = trim($_POST['foldername'] ?? '');
$redirect  = $parent_id ? "/?folder=$parent_id" : '/';

if ($name === '') {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Folder name cannot be empty.'];
    header("Location: $redirect"); exit;
}

$safe = preg_replace('/[^\w.\- ]/', '_', $name);

$stmt = $pdo->prepare('
    INSERT INTO files (owner_id, filename, filepath, filesize, filetype, is_folder, parent_id)
    VALUES (?, ?, ?, 0, "inode/directory", 1, ?)
');
$stmt->execute([$user['id'], $safe, '', $parent_id]);

$_SESSION['flash'] = ['type' => 'success', 'msg' => "Folder \"$safe\" created."];
header("Location: $redirect");
