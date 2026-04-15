<?php
// backup_data.php - JSON endpoint polled by backup.php for live updates.
require_once 'auth.php';
require_admin();
require_once 'db.php';

header('Content-Type: application/json');

function fmt($bytes): string {
    if ($bytes >= 1073741824) return round($bytes / 1073741824, 2) . ' GB';
    if ($bytes >= 1048576)    return round($bytes / 1048576, 1)    . ' MB';
    if ($bytes >= 1024)       return round($bytes / 1024, 1)       . ' KB';
    return $bytes . ' B';
}

// USB heartbeat
$usb = ['active' => false, 'count' => 0, 'last' => null, 'target' => ''];
$heartbeat = '/var/www/backups/.usb_sync_status';
if (file_exists($heartbeat)) {
    $age = time() - filemtime($heartbeat);
    $h = @json_decode(@file_get_contents($heartbeat), true) ?: [];
    $usb = [
        'active' => $age < 15,
        'last'   => $h['last_sync'] ?? null,
        'count'  => (int)($h['files_mirrored'] ?? 0),
        'target' => $h['target_path']  ?? '',
        'status' => $h['status'] ?? 'unknown',
    ];
}

// All backups
$backups = $pdo->query(
    "SELECT b.id, b.filename, b.filepath, b.filesize, b.created_at, u.username
     FROM backups b
     LEFT JOIN users u ON b.created_by = u.id
     ORDER BY b.created_at DESC"
)->fetchAll();

$out = array_map(function($b) {
    $is_auto = strpos($b['filename'], 'nas_auto_backup_') === 0;
    return [
        'id'         => (int)$b['id'],
        'filename'   => $b['filename'],
        'filesize'   => (int)$b['filesize'],
        'size_fmt'   => fmt((int)$b['filesize']),
        'created_at' => $b['created_at'],
        'created_ts' => strtotime($b['created_at']),
        'username'   => $b['username'] ?? 'system',
        'is_auto'    => $is_auto,
    ];
}, $backups);

echo json_encode([
    'ts'      => time(),
    'count'   => count($out),
    'backups' => $out,
    'usb'     => $usb,
]);
