<?php
// monitor_data.php — JSON endpoint polled by monitor.php for live updates.
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

function fmt_uptime(int $sec): string {
    if ($sec <= 0) return '—';
    $d = intdiv($sec, 86400);
    $h = intdiv($sec % 86400, 3600);
    $m = intdiv($sec % 3600, 60);
    if ($d > 0) return "{$d}d {$h}h";
    if ($h > 0) return "{$h}h {$m}m";
    return "{$m}m";
}

function fmt_ago(?string $ts): string {
    if (!$ts) return 'never';
    $diff = time() - strtotime($ts);
    if ($diff < 0) $diff = abs($diff); // tolerate timezone mismatch
    if ($diff < 60)    return $diff . 's ago';
    if ($diff < 3600)  return intdiv($diff, 60) . 'm ago';
    if ($diff < 86400) return intdiv($diff, 3600) . 'h ago';
    return intdiv($diff, 86400) . 'd ago';
}

function fmt_ago_unix(int $unix): string {
    if ($unix <= 0) return 'never';
    $diff = abs(time() - $unix);
    if ($diff < 60)    return $diff . 's ago';
    if ($diff < 3600)  return intdiv($diff, 60) . 'm ago';
    if ($diff < 86400) return intdiv($diff, 3600) . 'h ago';
    return intdiv($diff, 86400) . 'd ago';
}

// CPU sample
$cpu = 0.0;
if (is_readable('/proc/stat')) {
    $s1 = file('/proc/stat')[0];
    usleep(150000);
    $s2 = file('/proc/stat')[0];
    $p1 = array_slice(explode(' ', preg_replace('/\s+/', ' ', trim($s1))), 1);
    $p2 = array_slice(explode(' ', preg_replace('/\s+/', ' ', trim($s2))), 1);
    $idle1 = (float)$p1[3]; $total1 = array_sum(array_map('floatval', $p1));
    $idle2 = (float)$p2[3]; $total2 = array_sum(array_map('floatval', $p2));
    $td = $total2 - $total1; $id = $idle2 - $idle1;
    $cpu = $td > 0 ? round((1 - $id / $td) * 100, 1) : 0;
}

// Memory
$mem = ['total' => 0, 'used' => 0, 'pct' => 0];
if (is_readable('/proc/meminfo')) {
    $info = [];
    foreach (file('/proc/meminfo') as $line) {
        if (!str_contains($line, ':')) continue;
        [$k, $v] = explode(':', $line);
        $info[trim($k)] = (int)trim(str_replace(' kB', '', $v)) * 1024;
    }
    $total = $info['MemTotal'] ?? 0;
    $avail = $info['MemAvailable'] ?? 0;
    $used  = $total - $avail;
    $mem = ['total' => $total, 'used' => $used, 'pct' => $total > 0 ? round(($used / $total) * 100, 1) : 0];
}

// Load avg + uptime + cores
$load = ['1' => '0.00', '5' => '0.00', '15' => '0.00'];
if (is_readable('/proc/loadavg')) {
    $parts = explode(' ', trim(file_get_contents('/proc/loadavg')));
    $load = ['1' => $parts[0] ?? '0.00', '5' => $parts[1] ?? '0.00', '15' => $parts[2] ?? '0.00'];
}
$uptime_s  = is_readable('/proc/uptime') ? (int)floatval(explode(' ', trim(file_get_contents('/proc/uptime')))[0]) : 0;
$cpu_cores = (int)trim(@shell_exec('nproc') ?: '1');
$load1_pct = $cpu_cores > 0 ? min(100, round(((float)$load['1'] / $cpu_cores) * 100)) : 0;

// Disk volumes
$uploads_total = @disk_total_space('/var/www/uploads') ?: 0;
$uploads_free  = @disk_free_space('/var/www/uploads')  ?: 0;
$uploads_used  = $uploads_total - $uploads_free;
$uploads_pct   = $uploads_total > 0 ? round(($uploads_used / $uploads_total) * 100, 1) : 0;

$backups_total = @disk_total_space('/var/www/backups') ?: 0;
$backups_free  = @disk_free_space('/var/www/backups')  ?: 0;
$backups_used  = $backups_total - $backups_free;
$backups_pct   = $backups_total > 0 ? round(($backups_used / $backups_total) * 100, 1) : 0;

// DB stats
$total_users   = (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn();
$total_files   = (int)$pdo->query('SELECT COUNT(*) FROM files WHERE is_folder = 0')->fetchColumn();
$total_folders = (int)$pdo->query('SELECT COUNT(*) FROM files WHERE is_folder = 1')->fetchColumn();
$uploads_db    = (int)$pdo->query('SELECT COALESCE(SUM(filesize),0) FROM files WHERE is_folder = 0')->fetchColumn();
$backups_db    = (int)$pdo->query('SELECT COALESCE(SUM(filesize),0) FROM backups')->fetchColumn();
$backups_count = (int)$pdo->query('SELECT COUNT(*) FROM backups')->fetchColumn();

$active_users = $pdo->query(
    "SELECT username, role, last_login FROM users
     WHERE last_login IS NOT NULL AND last_login >= NOW() - INTERVAL 30 MINUTE
     ORDER BY last_login DESC LIMIT 8"
)->fetchAll();

$last_auto = $pdo->query(
    "SELECT filename, filesize, created_at FROM backups
     WHERE filename LIKE 'nas_auto_backup_%'
     ORDER BY created_at DESC LIMIT 1"
)->fetch();

// USB mirror status (read from heartbeat file written by host watcher)
$usb = [
    'active'        => false,
    'count'         => 0,
    'last'          => null,
    'target'        => '',
    'total_bytes'   => 0,
    'free_bytes'    => 0,
    'used_bytes'    => 0,
    'pct'           => 0,
    'used_fmt'      => '—',
    'total_fmt'     => '—',
    'last_write_ago'=> null,
    'recently_active' => false,
    'poll_s'        => 3,
];
$heartbeat = '/var/www/backups/.usb_sync_status';
if (file_exists($heartbeat)) {
    $age = time() - filemtime($heartbeat);
    $h = @json_decode(@file_get_contents($heartbeat), true) ?: [];
    $total  = (int)($h['target_total_bytes'] ?? 0);
    $free   = (int)($h['target_free_bytes']  ?? 0);
    $used   = $total - $free;
    $lw_unix = (int)($h['last_write_unix'] ?? 0);
    $ls_unix = (int)($h['last_sync_unix']  ?? 0);
    $usb = [
        'active'         => $age < 15 && ($h['status'] ?? '') === 'ok',
        'age_s'          => $age,
        'last'           => $h['last_sync'] ?? null,
        'count'          => (int)($h['files_mirrored'] ?? 0),
        'target'         => $h['target_path'] ?? '',
        'status'         => $h['status'] ?? 'unknown',
        'total_bytes'    => $total,
        'free_bytes'     => $free,
        'used_bytes'     => $used,
        'pct'            => $total > 0 ? round(($used / $total) * 100, 1) : 0,
        'used_fmt'       => $total > 0 ? fmt($used)  : '—',
        'total_fmt'      => $total > 0 ? fmt($total) : '—',
        'last_sync_ago'  => $ls_unix > 0 ? fmt_ago_unix($ls_unix) : 'never',
        'last_write_ago' => $lw_unix > 0 ? fmt_ago_unix($lw_unix) : 'idle',
        'recently_active'=> $lw_unix > 0 && abs(time() - $lw_unix) < 5,
        'poll_s'         => (int)($h['poll_interval_s'] ?? 3),
    ];
}

echo json_encode([
    'ts'             => time(),
    'cpu'            => $cpu,
    'mem_pct'        => $mem['pct'],
    'mem_used'       => $mem['used'],
    'mem_total'      => $mem['total'],
    'mem_used_fmt'   => fmt($mem['used']),
    'mem_total_fmt'  => fmt($mem['total']),
    'load1'          => $load['1'],
    'load5'          => $load['5'],
    'load15'         => $load['15'],
    'load1_pct'      => $load1_pct,
    'cpu_cores'      => $cpu_cores,
    'uptime_s'       => $uptime_s,
    'uptime_fmt'     => fmt_uptime($uptime_s),
    'uploads_pct'    => $uploads_pct,
    'uploads_used_fmt'  => fmt($uploads_used),
    'uploads_total_fmt' => fmt($uploads_total),
    'backups_pct'    => $backups_pct,
    'backups_used_fmt'  => fmt($backups_used),
    'backups_total_fmt' => fmt($backups_total),
    'total_users'    => $total_users,
    'total_files'    => $total_files,
    'total_folders'  => $total_folders,
    'uploads_db_fmt' => fmt($uploads_db),
    'backups_db_fmt' => fmt($backups_db),
    'backups_count'  => $backups_count,
    'active_count'   => count($active_users),
    'active_users'   => array_map(fn($u) => [
        'username' => $u['username'],
        'role'     => $u['role'],
        'ago'      => fmt_ago($u['last_login']),
    ], $active_users),
    'last_auto'      => $last_auto ? [
        'ago'  => fmt_ago($last_auto['created_at']),
        'size' => fmt((int)$last_auto['filesize']),
    ] : null,
    'usb'            => $usb,
]);
