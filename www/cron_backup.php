<?php
// cron_backup.php — Called by cron for scheduled automatic backups
// This script runs outside of a web request, so no session/auth needed.

$host = getenv('MYSQL_DATABASE') ? 'db' : 'db';
$db   = getenv('MYSQL_DATABASE') ?: 'nas_db';
$user = getenv('MYSQL_USER')     ?: 'nas_user';
$pass = getenv('MYSQL_PASSWORD') ?: '';

try {
    $pdo = new PDO(
        "mysql:host=db;dbname=$db;charset=utf8mb4",
        $user,
        $pass,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
} catch (PDOException $e) {
    echo date('Y-m-d H:i:s') . " ERROR: Database connection failed: " . $e->getMessage() . "\n";
    exit(1);
}

$backup_dir = '/var/www/backups';
if (!is_dir($backup_dir)) mkdir($backup_dir, 0755, true);

$timestamp   = date('Y-m-d_H-i-s');
$backup_name = "nas_auto_backup_{$timestamp}";
$backup_path = "{$backup_dir}/{$backup_name}";
mkdir($backup_path, 0755, true);

// 1. Dump database
$dump_file = "{$backup_path}/database.sql";
$cmd = sprintf(
    'mysqldump --skip-ssl --no-tablespaces -h db -u %s -p%s %s > %s 2>&1',
    escapeshellarg($user),
    escapeshellarg($pass),
    escapeshellarg($db),
    escapeshellarg($dump_file)
);
exec($cmd, $output, $return_code);

// 2. Copy uploads
$upload_src = '/var/www/uploads';
$upload_dst = "{$backup_path}/uploads";
if (is_dir($upload_src)) {
    exec("cp -r " . escapeshellarg($upload_src) . " " . escapeshellarg($upload_dst));
}

// 3. Zip it
$zip_file = "{$backup_dir}/{$backup_name}.zip";
exec("cd " . escapeshellarg($backup_path) . " && zip -r " . escapeshellarg($zip_file) . " . 2>&1");
exec("rm -rf " . escapeshellarg($backup_path));

if ($return_code === 0 && file_exists($zip_file)) {
    $size = filesize($zip_file);
    // Record in DB (created_by = 1 for admin / auto)
    $stmt = $pdo->prepare('INSERT INTO backups (filename, filepath, filesize, created_by) VALUES (?, ?, ?, 1)');
    $stmt->execute(["{$backup_name}.zip", $zip_file, $size]);

    // Keep only the last 10 automatic backups
    $old = $pdo->query("SELECT id, filepath FROM backups WHERE filename LIKE 'nas_auto_backup_%' ORDER BY created_at DESC LIMIT 100 OFFSET 10")->fetchAll();
    foreach ($old as $o) {
        if (file_exists($o['filepath'])) unlink($o['filepath']);
        $pdo->prepare('DELETE FROM backups WHERE id = ?')->execute([$o['id']]);
    }

    echo date('Y-m-d H:i:s') . " OK: Backup created: {$backup_name}.zip (" . round($size / 1024) . " KB)\n";
} else {
    echo date('Y-m-d H:i:s') . " ERROR: Backup failed (code $return_code)\n";
    exit(1);
}
