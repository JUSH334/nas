<?php
require_once 'auth.php';
require_login();
require_once 'db.php';

$user = current_user();
$id   = isset($_GET['id']) ? (int)$_GET['id'] : 0;

$stmt = $pdo->prepare('SELECT * FROM files WHERE id = ? AND is_folder = 0');
$stmt->execute([$id]);
$file = $stmt->fetch();

if (!$file) { http_response_code(404); die('File not found.'); }

// Only owner or admin can download
if ($file['owner_id'] != $user['id'] && !is_admin()) {
    http_response_code(403); die('Access denied.');
}

$path = '/var/www/uploads/' . $file['filepath'];
if (!file_exists($path)) { http_response_code(404); die('File missing from disk.'); }

header('Content-Type: ' . ($file['filetype'] ?: 'application/octet-stream'));
header('Content-Disposition: attachment; filename="' . addslashes($file['filename']) . '"');
header('Content-Length: ' . filesize($path));
readfile($path);
