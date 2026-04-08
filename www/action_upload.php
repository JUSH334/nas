<?php
require_once 'auth.php';
require_login();
require_once 'db.php';

$user      = current_user();
$folder_id = !empty($_POST['folder_id']) ? (int)$_POST['folder_id'] : null;
$redirect  = $folder_id ? "/?folder=$folder_id" : '/';

if (empty($_FILES['file']) || $_FILES['file']['error'] !== UPLOAD_ERR_OK) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Upload failed. Please try again.'];
    header("Location: $redirect"); exit;
}

$file     = $_FILES['file'];
$original = basename($file['name']);
$mime     = mime_content_type($file['tmp_name']) ?: 'application/octet-stream';
$size     = $file['size'];

// Sanitize filename
$safe_name = preg_replace('/[^\w.\-]/', '_', $original);

// Build upload path: /var/www/uploads/<user_id>/<safe_name>
$upload_dir = "/var/www/uploads/{$user['id']}";
if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

$dest = "$upload_dir/$safe_name";

// Avoid overwrites by appending a counter
if (file_exists($dest)) {
    $info  = pathinfo($safe_name);
    $base  = $info['filename'];
    $ext   = isset($info['extension']) ? '.' . $info['extension'] : '';
    $i = 1;
    while (file_exists("$upload_dir/{$base}_{$i}{$ext}")) $i++;
    $safe_name = "{$base}_{$i}{$ext}";
    $dest = "$upload_dir/$safe_name";
}

if (!move_uploaded_file($file['tmp_name'], $dest)) {
    $_SESSION['flash'] = ['type' => 'error', 'msg' => 'Could not save file.'];
    header("Location: $redirect"); exit;
}

// Save metadata to DB
$filepath = "{$user['id']}/$safe_name";   // relative to /var/www/uploads/

$stmt = $pdo->prepare('
    INSERT INTO files (owner_id, filename, filepath, filesize, filetype, is_folder, parent_id)
    VALUES (?, ?, ?, ?, ?, 0, ?)
');
$stmt->execute([$user['id'], $safe_name, $filepath, $size, $mime, $folder_id]);

$_SESSION['flash'] = ['type' => 'success', 'msg' => "\"$safe_name\" uploaded successfully."];
header("Location: $redirect");
