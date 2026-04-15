<?php
require_once 'auth.php';
require_login();
require_once 'db.php';

$user = current_user();

// Search mode
$search = trim($_GET['q'] ?? '');

// Get current folder (default root = NULL)
$folder_id = isset($_GET['folder']) ? (int)$_GET['folder'] : null;

// Get current folder info
$current_folder = null;
if ($folder_id) {
    $stmt = $pdo->prepare('SELECT * FROM files WHERE id = ? AND is_folder = 1');
    $stmt->execute([$folder_id]);
    $current_folder = $stmt->fetch();
}

// Build breadcrumb trail
function get_breadcrumbs(PDO $pdo, ?int $folder_id): array {
    $crumbs = [];
    $id = $folder_id;
    while ($id) {
        $stmt = $pdo->prepare('SELECT id, filename, parent_id FROM files WHERE id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch();
        if (!$row) break;
        array_unshift($crumbs, $row);
        $id = $row['parent_id'];
    }
    return $crumbs;
}
$breadcrumbs = get_breadcrumbs($pdo, $folder_id);

// List files in current folder
if ($search !== '') {
    // Global search — show all matching files/folders the user can see
    $like = '%' . $search . '%';
    if (is_admin()) {
        $stmt = $pdo->prepare('
            SELECT f.*, u.username AS owner_name
            FROM files f
            LEFT JOIN users u ON f.owner_id = u.id
            WHERE f.filename LIKE ?
            ORDER BY f.is_folder DESC, f.filename ASC
            LIMIT 200
        ');
        $stmt->execute([$like]);
    } else {
        $stmt = $pdo->prepare('
            SELECT DISTINCT f.*, u.username AS owner_name
            FROM files f
            LEFT JOIN users u ON f.owner_id = u.id
            LEFT JOIN permissions p ON p.file_id = f.id AND p.user_id = ?
            WHERE f.filename LIKE ? AND (f.owner_id = ? OR p.can_read = 1)
            ORDER BY f.is_folder DESC, f.filename ASC
            LIMIT 200
        ');
        $stmt->execute([$user['id'], $like, $user['id']]);
    }
} elseif ($folder_id) {
    if (is_admin()) {
        $stmt = $pdo->prepare('
            SELECT f.*, u.username AS owner_name
            FROM files f
            LEFT JOIN users u ON f.owner_id = u.id
            WHERE f.parent_id = ?
            ORDER BY f.is_folder DESC, f.filename ASC
        ');
        $stmt->execute([$folder_id]);
    } else {
        $stmt = $pdo->prepare('
            SELECT DISTINCT f.*, u.username AS owner_name
            FROM files f
            LEFT JOIN users u ON f.owner_id = u.id
            LEFT JOIN permissions p ON p.file_id = f.id AND p.user_id = ?
            WHERE f.parent_id = ? AND (f.owner_id = ? OR p.can_read = 1)
            ORDER BY f.is_folder DESC, f.filename ASC
        ');
        $stmt->execute([$user['id'], $folder_id, $user['id']]);
    }
} else {
    // Root: show items owned by this user (or all if admin)
    if (is_admin()) {
        $stmt = $pdo->prepare('
            SELECT f.*, u.username AS owner_name
            FROM files f
            LEFT JOIN users u ON f.owner_id = u.id
            WHERE f.parent_id IS NULL
            ORDER BY f.is_folder DESC, f.filename ASC
        ');
        $stmt->execute();
    } else {
        $stmt = $pdo->prepare('
            SELECT DISTINCT f.*, u.username AS owner_name
            FROM files f
            LEFT JOIN users u ON f.owner_id = u.id
            LEFT JOIN permissions p ON p.file_id = f.id AND p.user_id = ?
            WHERE f.parent_id IS NULL AND (f.owner_id = ? OR p.can_read = 1)
            ORDER BY f.is_folder DESC, f.filename ASC
        ');
        $stmt->execute([$user['id'], $user['id']]);
    }
}
$items = $stmt->fetchAll();

// Load permissions for all shown items in one query (avoid N+1)
$permissions_by_file = [];
if (!empty($items)) {
    $ids = array_column($items, 'id');
    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $perm_stmt = $pdo->prepare("
        SELECT p.file_id, p.user_id, p.can_read, p.can_write, p.can_delete, u.username
        FROM permissions p
        JOIN users u ON u.id = p.user_id
        WHERE p.file_id IN ($placeholders)
    ");
    $perm_stmt->execute($ids);
    foreach ($perm_stmt->fetchAll() as $p) {
        $permissions_by_file[$p['file_id']][] = $p;
    }
}

// Format file size
function fmt_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

// File icon by MIME type - returns SVG markup
function file_icon(string $type, bool $is_folder): string {
    $color = '#6b7080';
    if ($is_folder) {
        $color = '#4fffb0';
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>';
    }
    if (str_starts_with($type, 'image/')) {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="3" width="18" height="18" rx="2" ry="2"/><circle cx="8.5" cy="8.5" r="1.5"/><polyline points="21 15 16 10 5 21"/></svg>';
    }
    if (str_starts_with($type, 'video/')) {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polygon points="23 7 16 12 23 17 23 7"/><rect x="1" y="5" width="15" height="14" rx="2" ry="2"/></svg>';
    }
    if (str_starts_with($type, 'audio/')) {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M9 18V5l12-2v13"/><circle cx="6" cy="18" r="3"/><circle cx="18" cy="16" r="3"/></svg>';
    }
    if ($type === 'application/pdf') {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
    }
    if (str_contains($type, 'zip') || str_contains($type, 'tar')) {
        return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 8v13H3V8"/><path d="M1 3h22v5H1z"/><path d="M10 12h4"/></svg>';
    }
    return '<svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="' . $color . '" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M14 2H6a2 2 0 0 0-2 2v16a2 2 0 0 0 2 2h12a2 2 0 0 0 2-2V8z"/><polyline points="14 2 14 8 20 8"/></svg>';
}

$folder_param = $folder_id ? "?folder=$folder_id" : '';

// Get current user's storage usage and quota
$storage_stmt = $pdo->prepare('
    SELECT COALESCE(SUM(filesize), 0) AS used
    FROM files WHERE owner_id = ? AND is_folder = 0
');
$storage_stmt->execute([$user['id']]);
$storage_used = (int)$storage_stmt->fetchColumn();

$quota_stmt = $pdo->prepare('SELECT storage_quota FROM users WHERE id = ?');
$quota_stmt->execute([$user['id']]);
$storage_quota = $quota_stmt->fetchColumn();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>NAS — Files</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link href="https://fonts.googleapis.com/css2?family=Space+Mono:wght@400;700&family=DM+Sans:ital,wght@0,300;0,400;0,500;1,400&display=swap" rel="stylesheet">
<style>
  :root {
    --bg:       #0d0f14;
    --surface:  #161920;
    --surface2: #1d2029;
    --border:   #2a2d38;
    --accent:   #4fffb0;
    --accent2:  #00bfff;
    --text:     #e8eaf0;
    --muted:    #6b7080;
    --danger:   #ff4f6a;
    --radius:   6px;
  }

  *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

  body {
    background: var(--bg);
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    min-height: 100vh;
    display: flex;
    flex-direction: column;
  }

  /* ── Top nav ── */
  nav {
    background-color: #161920;
    border-bottom: 1px solid var(--border);
    padding: 0 28px;
    height: 56px;
    display: flex;
    align-items: center;
    gap: 20px;
    position: sticky;
    top: 0;
    z-index: 100;
    box-shadow: 0 1px 0 rgba(0,0,0,0.4), 0 4px 12px rgba(0,0,0,0.25);
  }

  .nav-logo {
    font-family: 'Space Mono', monospace;
    font-weight: 700;
    font-size: 16px;
    color: var(--accent);
    text-decoration: none;
    margin-right: 8px;
  }

  .nav-links { display: flex; gap: 4px; flex: 1; }

  .nav-link {
    color: var(--muted);
    text-decoration: none;
    font-size: 14px;
    padding: 6px 12px;
    border-radius: var(--radius);
    transition: color 0.15s, background 0.15s, box-shadow 0.15s;
  }
  .nav-link:hover, .nav-link.active {
    color: var(--text);
    background: var(--surface2);
  }
  .nav-link:hover { box-shadow: inset 0 0 0 1px rgba(79,255,176,0.2); }
  .nav-link.active { color: var(--accent); box-shadow: inset 0 0 0 1px rgba(79,255,176,0.3); }

  .nav-user {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--muted);
  }
  .nav-user strong { color: var(--text); }
  .nav-user-link {
    color: var(--muted); text-decoration: none;
    padding: 4px 8px; border-radius: var(--radius);
    border: 1px solid transparent;
    transition: border-color 0.15s, color 0.15s;
  }
  .nav-user-link:hover {
    border-color: rgba(79,255,176,0.3);
    color: var(--text);
    background: rgba(79,255,176,0.06);
    box-shadow: 0 0 0 3px rgba(79,255,176,0.08);
  }
  .nav-user-link:hover strong { color: var(--accent); }
  .nav-user a {
    color: var(--muted);
    text-decoration: none;
    font-size: 12px;
    padding: 4px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    transition: border-color 0.15s, color 0.15s;
  }
  .nav-user a[href="/logout.php"]:hover { border-color: var(--danger); color: var(--danger); }

  /* ── Main layout ── */
  main { padding: 28px; max-width: 1100px; width: 100%; margin: 0 auto; flex: 1; }

  /* ── Breadcrumb ── */
  .breadcrumb {
    display: flex;
    align-items: center;
    gap: 6px;
    font-size: 13px;
    color: var(--muted);
    margin-bottom: 20px;
    font-family: 'Space Mono', monospace;
  }
  .breadcrumb a { color: var(--muted); text-decoration: none; transition: color 0.15s; }
  .breadcrumb a:hover { color: var(--accent); }
  .breadcrumb .home-link {
    display: inline-flex; align-items: center;
    padding: 4px 10px;
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    font-size: 12px;
    font-weight: 500;
  }
  .breadcrumb .home-link:hover {
    border-color: var(--accent);
    color: var(--accent);
  }
  .breadcrumb .sep { opacity: 0.4; }
  .breadcrumb .current { color: var(--text); }

  /* ── Toolbar ── */
  .toolbar {
    display: flex;
    align-items: center;
    gap: 10px;
    margin-bottom: 18px;
    flex-wrap: wrap;
  }

  .toolbar h1 {
    font-size: 20px;
    font-weight: 500;
    flex: 1;
  }

  /* Search form */
  .search-form {
    position: relative;
    display: inline-flex;
    align-items: center;
    margin-right: 8px;
  }
  .search-input {
    background: var(--bg) url('data:image/svg+xml;utf8,<svg xmlns=%22http://www.w3.org/2000/svg%22 width=%2214%22 height=%2214%22 viewBox=%220 0 24 24%22 fill=%22none%22 stroke=%22%236b7080%22 stroke-width=%222%22 stroke-linecap=%22round%22 stroke-linejoin=%22round%22><circle cx=%2211%22 cy=%2211%22 r=%228%22/><line x1=%2221%22 y1=%2221%22 x2=%2216.65%22 y2=%2216.65%22/></svg>') no-repeat 12px center;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    padding: 8px 32px 8px 34px;
    outline: none;
    width: 240px;
    transition: border-color 0.15s, box-shadow 0.15s, width 0.2s;
  }
  .search-input:focus {
    border-color: var(--accent);
    box-shadow: 0 0 0 3px rgba(79,255,176,0.08);
    width: 300px;
  }
  .search-input::placeholder { color: var(--muted); }
  .search-clear {
    position: absolute;
    right: 10px;
    color: var(--muted);
    text-decoration: none;
    font-size: 18px;
    line-height: 1;
    padding: 0 4px;
    cursor: pointer;
    transition: color 0.12s;
  }
  .search-clear:hover { color: var(--danger); }

  .btn {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 8px 16px;
    border-radius: var(--radius);
    font-family: 'DM Sans', sans-serif;
    font-size: 13px;
    font-weight: 500;
    cursor: pointer;
    border: none;
    text-decoration: none;
    transition: opacity 0.15s, transform 0.1s;
  }
  .btn:hover { opacity: 0.92; transform: translateY(-1px); box-shadow: 0 4px 14px rgba(79,255,176,0.15); }
  .btn:active { transform: translateY(0); box-shadow: 0 1px 4px rgba(79,255,176,0.12); }
  .btn-secondary:hover { box-shadow: 0 4px 14px rgba(79,255,176,0.08); border-color: rgba(79,255,176,0.3); }
  .btn-danger:hover { box-shadow: 0 4px 14px rgba(255,79,106,0.15); border-color: rgba(255,79,106,0.5); }
  .btn-primary { background: var(--accent); color: #0d0f14; }
  .btn-secondary { background: var(--surface2); color: var(--text); border: 1px solid var(--border); }
  .btn-danger { background: rgba(255,79,106,0.12); color: var(--danger); border: 1px solid rgba(255,79,106,0.25); }

  /* ── Flash messages ── */
  .flash {
    padding: 10px 16px;
    border-radius: var(--radius);
    font-size: 13px;
    margin-bottom: 18px;
  }
  .flash.success { background: rgba(79,255,176,0.1); border: 1px solid rgba(79,255,176,0.3); color: var(--accent); }
  .flash.error   { background: rgba(255,79,106,0.1); border: 1px solid rgba(255,79,106,0.3); color: var(--danger); }

  /* ── File table ── */
  .file-table {
    width: 100%;
    border-collapse: collapse;
    font-size: 14px;
  }

  .file-table thead tr {
    border-bottom: 1px solid var(--border);
  }

  .file-table th {
    text-align: left;
    padding: 10px 14px;
    font-size: 11px;
    font-weight: 500;
    letter-spacing: 0.08em;
    text-transform: uppercase;
    color: var(--muted);
  }

  .file-table tbody tr {
    border-bottom: 1px solid rgba(42,45,56,0.5);
    transition: background 0.12s;
    animation: fadeUp 0.35s ease both;
  }
  .file-table tbody tr:nth-child(1) { animation-delay: 0.05s; }
  .file-table tbody tr:nth-child(2) { animation-delay: 0.09s; }
  .file-table tbody tr:nth-child(3) { animation-delay: 0.13s; }
  .file-table tbody tr:nth-child(4) { animation-delay: 0.17s; }
  .file-table tbody tr:nth-child(5) { animation-delay: 0.21s; }
  .file-table tbody tr:nth-child(6) { animation-delay: 0.25s; }
  .file-table tbody tr:nth-child(7) { animation-delay: 0.29s; }
  .file-table tbody tr:nth-child(n+8) { animation-delay: 0.33s; }
  .file-table tbody tr:hover { background: var(--surface2); }
  .toolbar { animation: fadeUp 0.4s ease both; }
  .file-table { animation: fadeUp 0.4s ease both; animation-delay: 0.05s; }

  .file-table td {
    padding: 11px 14px;
    vertical-align: middle;
  }

  .file-name {
    display: flex;
    align-items: center;
    gap: 10px;
  }
  .file-name .icon { font-size: 18px; line-height: 1; }
  .file-name a { color: var(--text); text-decoration: none; font-weight: 500; }
  .file-name a:hover { color: var(--accent); }

  .file-meta { color: var(--muted); font-size: 12px; }

  /* Owner chip + "you" tag */
  .owner-chip {
    display: inline-flex; align-items: center; gap: 6px;
    font-size: 12px;
  }
  .owner-chip.you { color: var(--text); font-weight: 500; }
  .owner-you-tag {
    font-size: 9px; font-weight: 700; letter-spacing: 0.05em;
    font-family: 'Space Mono', monospace;
    background: rgba(79,255,176,0.12); color: var(--accent);
    border: 1px solid rgba(79,255,176,0.25);
    padding: 1px 5px; border-radius: 3px;
  }

  /* Access cell */
  .access-cell { position: relative; font-size: 12px; }
  .access-private, .access-shared, .access-admin {
    display: inline-flex; align-items: center;
    font-family: 'DM Sans', sans-serif;
    font-size: 11px; font-weight: 600;
    letter-spacing: 0.02em;
    padding: 4px 10px;
    border-radius: 99px;
    line-height: 1.4;
    white-space: nowrap;
    text-decoration: none;
    transition: background 0.15s, border-color 0.15s;
  }
  .access-private {
    color: var(--muted); background: var(--surface2);
    border: 1px solid var(--border);
  }
  .access-shared {
    color: var(--accent); background: rgba(79,255,176,0.06);
    border: 1px solid rgba(79,255,176,0.25);
  }
  .access-shared:hover { background: rgba(79,255,176,0.14); border-color: rgba(79,255,176,0.4); }
  .access-admin {
    color: #00bfff; background: rgba(0,191,255,0.08);
    border: 1px solid rgba(0,191,255,0.25);
  }

  /* Permission chips (R/W/D) */
  .access-chips { display: inline-flex; gap: 3px; }
  .chip {
    font-family: 'Space Mono', monospace;
    font-size: 10px; font-weight: 700;
    padding: 1px 5px; border-radius: 3px;
    border: 1px solid;
  }
  .chip-r { color: var(--accent); background: rgba(79,255,176,0.08); border-color: rgba(79,255,176,0.25); }
  .chip-w { color: #ffb84f; background: rgba(255,184,79,0.08); border-color: rgba(255,184,79,0.25); }
  .chip-d { color: var(--danger); background: rgba(255,79,106,0.08); border-color: rgba(255,79,106,0.25); }


  /* Welcome banner */
  .welcome {
    display: flex; align-items: center; justify-content: space-between; gap: 16px;
    margin-bottom: 24px;
    padding: 20px 24px;
    background: linear-gradient(135deg, var(--surface) 0%, var(--surface2) 100%);
    border: 1px solid var(--border);
    border-radius: 10px;
    animation: fadeUp 0.4s ease both;
  }
  .welcome-title {
    font-size: 22px; font-weight: 500; letter-spacing: -0.3px;
    background: linear-gradient(90deg, var(--accent), var(--accent2));
    -webkit-background-clip: text; -webkit-text-fill-color: transparent;
    background-clip: text; color: transparent;
    margin-bottom: 4px;
  }
  .welcome-sub { font-size: 13px; color: var(--muted); }

  .admin-indicator {
    display: inline-flex; align-items: center; gap: 6px;
    padding: 6px 12px;
    background: rgba(0,191,255,0.08);
    border: 1px solid rgba(0,191,255,0.3);
    color: #00bfff;
    border-radius: 99px;
    font-size: 11px; font-weight: 600;
    letter-spacing: 0.05em;
    font-family: 'Space Mono', monospace;
    flex-shrink: 0;
  }

  /* Admin nav distinction - subtle blue accent line */
  body.admin-mode nav {
    background-image:
      linear-gradient(to bottom, rgba(0,191,255,0.04) 0%, rgba(0,191,255,0) 100%);
  }
  body.admin-mode nav::after {
    content: '';
    position: absolute;
    bottom: 0; left: 0; right: 0;
    height: 1px;
    background: linear-gradient(90deg, transparent, rgba(0,191,255,0.4), transparent);
  }

  /* Admin badge in nav - more prominent */
  .role-badge {
    font-size: 10px; font-weight: 700; letter-spacing: 0.1em;
    font-family: 'Space Mono', monospace;
    padding: 3px 8px; border-radius: 4px;
    text-transform: uppercase;
  }
  .role-badge.admin {
    color: #00bfff; background: rgba(0,191,255,0.1);
    border: 1px solid rgba(0,191,255,0.3);
  }
  .role-badge.user {
    color: var(--muted); background: var(--surface2);
    border: 1px solid var(--border);
  }

  .actions { display: flex; gap: 4px; }
  .action-btn {
    background: none;
    border: 1px solid transparent;
    color: var(--muted);
    cursor: pointer;
    padding: 6px;
    border-radius: var(--radius);
    text-decoration: none;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: color 0.12s, background 0.12s, border-color 0.12s;
  }
  .action-btn:hover { background: var(--surface2); color: var(--accent); border-color: var(--border); }
  .action-btn.del:hover { color: var(--danger); border-color: rgba(255,79,106,0.3); }
  .action-btn.del:hover { color: var(--danger); }

  .empty-state {
    text-align: center;
    padding: 64px 20px;
    color: var(--muted);
  }
  .empty-state .icon { font-size: 48px; margin-bottom: 16px; }
  .empty-state p { font-size: 15px; }

  /* ── Modal ── */
  .modal-backdrop {
    display: none;
    position: fixed;
    inset: 0;
    background: rgba(0,0,0,0.6);
    backdrop-filter: blur(4px);
    z-index: 200;
    align-items: center;
    justify-content: center;
  }
  .modal-backdrop.open { display: flex; }

  .modal {
    background: var(--surface);
    border: 1px solid var(--border);
    border-radius: 12px;
    padding: 32px;
    width: 100%;
    max-width: 440px;
    animation: fadeUp 0.2s ease;
  }
  @keyframes fadeUp {
    from { opacity: 0; transform: translateY(16px); }
    to   { opacity: 1; transform: translateY(0); }
  }

  .modal h2 { font-size: 18px; font-weight: 500; margin-bottom: 20px; }

  .field { margin-bottom: 16px; }
  label { display: block; font-size: 11px; font-weight: 500; letter-spacing: 0.08em; text-transform: uppercase; color: var(--muted); margin-bottom: 6px; }

  input[type="text"], input[type="file"] {
    width: 100%;
    background: var(--bg);
    border: 1px solid var(--border);
    border-radius: var(--radius);
    color: var(--text);
    font-family: 'DM Sans', sans-serif;
    font-size: 14px;
    padding: 10px 12px;
    outline: none;
    transition: border-color 0.2s;
  }
  input:focus { border-color: var(--accent); box-shadow: 0 0 0 3px rgba(79,255,176,0.08); }

  .modal-actions { display: flex; gap: 10px; justify-content: flex-end; margin-top: 24px; }

  /* Upload drop zone */
  .drop-zone {
    border: 2px dashed var(--border);
    border-radius: var(--radius);
    padding: 32px;
    text-align: center;
    color: var(--muted);
    font-size: 14px;
    cursor: pointer;
    transition: border-color 0.2s, background 0.2s;
  }
  .drop-zone:hover, .drop-zone.drag-over {
    border-color: var(--accent);
    background: rgba(79,255,176,0.04);
    color: var(--accent);
  }
  .drop-zone input[type="file"] {
    display: none;
  }
  .drop-zone .dz-icon { font-size: 32px; margin-bottom: 10px; }
</style>
</head>
<body<?= is_admin() ? ' class="admin-mode"' : '' ?>>

<nav>
  <a class="nav-logo" href="/">NAS</a>
  <div class="nav-links">
    <a class="nav-link active" href="/">Files</a>
    <?php if (is_admin()): ?>
    <a class="nav-link" href="/users.php">Users</a>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <a class="nav-link" href="/monitor.php">Monitor</a>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <a class="nav-link" href="/logs.php">Logs</a>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <a class="nav-link" href="/backup.php">Backups</a>
    <?php endif; ?>
  </div>
  <div class="nav-user">
    <a href="/profile.php" class="nav-user-link">Hello, <strong><?= htmlspecialchars($user['username']) ?></strong></a>
    <span class="role-badge <?= is_admin() ? 'admin' : 'user' ?>"><?= is_admin() ? 'Admin' : 'User' ?></span>
    <a href="/logout.php">Sign out</a>
  </div>
</nav>

<main>

  <!-- Breadcrumb (hidden during search) -->
  <?php if ($search === ''): ?>
  <div class="breadcrumb">
    <a href="/" class="home-link">
      <svg width="13" height="13" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:4px;"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
      Home
    </a>
    <?php foreach ($breadcrumbs as $crumb): ?>
      <span class="sep">/</span>
      <a href="/?folder=<?= $crumb['id'] ?>"><?= htmlspecialchars($crumb['filename']) ?></a>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- Flash message -->
  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="flash <?= $_SESSION['flash']['type'] ?>"><?= htmlspecialchars($_SESSION['flash']['msg']) ?></div>
    <?php unset($_SESSION['flash']); ?>
  <?php endif; ?>

  <?php if (!$current_folder): ?>
    <!-- Welcome banner - only on root -->
    <div class="welcome">
      <div>
        <h1 class="welcome-title">Welcome back, <?= htmlspecialchars($user['username']) ?></h1>
        <p class="welcome-sub">
          <?php
            $hour = (int)date('G');
            if      ($hour >= 5  && $hour < 12) $greeting = 'Good morning';
            elseif  ($hour >= 12 && $hour < 17) $greeting = 'Good afternoon';
            elseif  ($hour >= 17 && $hour < 21) $greeting = 'Good evening';
            else                                 $greeting = 'Working late';
            echo $greeting . ". Here's your file library.";
          ?>
        </p>
      </div>
      <?php if (is_admin()): ?>
        <div class="admin-indicator" title="You are signed in as an administrator">
          <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/></svg>
          Admin mode
        </div>
      <?php endif; ?>
    </div>
  <?php endif; ?>

  <!-- Toolbar -->
  <div class="toolbar">
    <h1>
      <?php if ($search !== ''): ?>
        Search: "<?= htmlspecialchars($search) ?>" <span style="color:var(--muted);font-size:15px;font-weight:400;">(<?= count($items) ?> results)</span>
      <?php else: ?>
        <?= $current_folder ? htmlspecialchars($current_folder['filename']) : 'My Files' ?>
      <?php endif; ?>
    </h1>

    <form method="get" class="search-form">
      <input type="text" name="q" value="<?= htmlspecialchars($search) ?>" placeholder="Search files..." class="search-input" autocomplete="off">
      <?php if ($search !== ''): ?>
        <a href="<?= $folder_id ? '/?folder=' . $folder_id : '/' ?>" class="search-clear" title="Clear search">×</a>
      <?php endif; ?>
    </form>
    <button class="btn btn-secondary" onclick="openModal('modal-folder')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><line x1="12" y1="5" x2="12" y2="19"/><line x1="5" y1="12" x2="19" y2="12"/></svg>
      New Folder
    </button>
    <button class="btn btn-primary" onclick="openModal('modal-upload')">
      <svg width="14" height="14" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
      Upload
    </button>
  </div>

  <!-- Storage usage -->
  <div style="background:var(--surface);border:1px solid var(--border);border-radius:10px;padding:14px 20px;margin-bottom:18px;display:flex;align-items:center;gap:16px;">
    <span style="font-size:13px;color:var(--muted);white-space:nowrap;">Storage:</span>
    <?php if ($storage_quota): ?>
      <div style="flex:1;">
        <div style="height:6px;background:var(--surface2);border-radius:99px;overflow:hidden;">
          <?php
            $max = (int)$storage_quota;
            $pct = $max > 0 ? min(100, round($storage_used / $max * 100)) : 0;
            $bar_color = $pct >= 90 ? 'var(--danger)' : ($pct >= 70 ? 'var(--warn)' : 'linear-gradient(90deg, var(--accent), var(--accent2))');
          ?>
          <div style="height:100%;width:<?= $pct ?>%;background:<?= $bar_color ?>;border-radius:99px;transition:width 0.5s;"></div>
        </div>
      </div>
      <span style="font-size:12px;font-family:'Space Mono',monospace;color:var(--text);white-space:nowrap;">
        <?= fmt_size($storage_used) ?> / <?= fmt_size((int)$storage_quota) ?>
      </span>
    <?php else: ?>
      <div style="flex:1;"></div>
      <span style="font-size:12px;font-family:'Space Mono',monospace;color:var(--text);white-space:nowrap;">
        <?= fmt_size($storage_used) ?> used · <span style="color:var(--muted);">unlimited</span>
      </span>
    <?php endif; ?>
  </div>

  <!-- File listing -->
  <?php if (empty($items)): ?>
    <div class="empty-state">
      <div class="icon">
        <svg width="48" height="48" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round"><path d="M22 19a2 2 0 0 1-2 2H4a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h5l2 3h9a2 2 0 0 1 2 2z"/></svg>
      </div>
      <p>This folder is empty.<br>Upload a file or create a folder to get started.</p>
    </div>
  <?php else: ?>
  <table class="file-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Size</th>
        <th>Owner</th>
        <th>Access</th>
        <th>Modified</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item):
        // Determine permissions for this item
        $is_owner = $item['owner_id'] == $user['id'];
        $can_read = $is_owner || is_admin();
        $can_write = $is_owner || is_admin();
        $can_delete = $is_owner || is_admin();

        // If not owner/admin, check the permissions table
        if (!$is_owner && !is_admin()) {
            $pstmt = $pdo->prepare('SELECT can_read, can_write, can_delete FROM permissions WHERE file_id = ? AND user_id = ?');
            $pstmt->execute([$item['id'], $user['id']]);
            $perms = $pstmt->fetch();
            $can_read   = $perms ? (bool)$perms['can_read'] : false;
            $can_write  = $perms ? (bool)$perms['can_write'] : false;
            $can_delete = $perms ? (bool)$perms['can_delete'] : false;
        }
      ?>
      <tr>
        <td class="file-name">
          <span class="icon"><?= file_icon($item['filetype'] ?? '', (bool)$item['is_folder']) ?></span>
          <?php if ($item['is_folder']): ?>
            <a href="/?folder=<?= $item['id'] ?>"><?= htmlspecialchars($item['filename']) ?></a>
          <?php elseif ($can_read): ?>
            <a href="/download.php?id=<?= $item['id'] ?>"><?= htmlspecialchars($item['filename']) ?></a>
          <?php else: ?>
            <span><?= htmlspecialchars($item['filename']) ?></span>
          <?php endif; ?>
        </td>
        <td class="file-meta"><?= $item['is_folder'] ? '—' : fmt_size((int)$item['filesize']) ?></td>
        <td class="file-meta">
          <span class="owner-chip<?= $item['owner_id'] == $user['id'] ? ' you' : '' ?>">
            <?= htmlspecialchars($item['owner_name'] ?? '—') ?>
            <?php if ($item['owner_id'] == $user['id']): ?><span class="owner-you-tag">you</span><?php endif; ?>
          </span>
        </td>
        <td class="access-cell">
          <?php
            $file_perms = $permissions_by_file[$item['id']] ?? [];
            if ($is_owner) {
                if (empty($file_perms)): ?>
                  <span class="access-private">Private</span>
                <?php else: ?>
                  <a class="access-shared" href="/permissions.php?file_id=<?= $item['id'] ?>">
                    <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:3px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                    Shared · <?= count($file_perms) ?>
                  </a>
                <?php endif;
            } elseif (is_admin()) { ?>
              <?php if (empty($file_perms)): ?>
                <span class="access-admin">Admin</span>
              <?php else: ?>
                <a class="access-shared" href="/permissions.php?file_id=<?= $item['id'] ?>">
                  <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" style="vertical-align:-2px;margin-right:3px;"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
                  Shared · <?= count($file_perms) ?>
                </a>
              <?php endif;
            } else {
              // Shared with me — show my permission chips
              ?>
              <span class="access-chips">
                <?php if ($can_read):   ?><span class="chip chip-r" title="Read">R</span><?php endif; ?>
                <?php if ($can_write):  ?><span class="chip chip-w" title="Write">W</span><?php endif; ?>
                <?php if ($can_delete): ?><span class="chip chip-d" title="Delete">D</span><?php endif; ?>
              </span>
            <?php } ?>
        </td>
        <td class="file-meta"><?= date('M j, Y', strtotime($item['updated_at'])) ?></td>
        <td>
          <div class="actions">
            <?php if (!$item['is_folder'] && $can_read): ?>
            <a class="action-btn" href="/download.php?id=<?= $item['id'] ?>" title="Download">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="7 10 12 15 17 10"/><line x1="12" y1="15" x2="12" y2="3"/></svg>
            </a>
            <?php endif; ?>
            <?php if ($is_owner || is_admin() || $can_write): ?>
            <button class="action-btn" onclick="openRename(<?= $item['id'] ?>, '<?= htmlspecialchars(addslashes($item['filename'])) ?>')" title="Rename">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M12 20h9"/><path d="M16.5 3.5a2.121 2.121 0 0 1 3 3L7 19l-4 1 1-4L16.5 3.5z"/></svg>
            </button>
            <?php endif; ?>
            <?php if (is_admin() || $is_owner): ?>
            <a class="action-btn" href="/permissions.php?file_id=<?= $item['id'] ?>" title="Share / Permissions">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><rect x="3" y="11" width="18" height="11" rx="2" ry="2"/><path d="M7 11V7a5 5 0 0 1 10 0v4"/></svg>
            </a>
            <?php endif; ?>
            <?php if ($is_owner || is_admin() || $can_delete): ?>
            <a class="action-btn del" href="/delete.php?id=<?= $item['id'] ?><?= $folder_id ? "&folder=$folder_id" : '' ?>"
               onclick="return confirm('Delete <?= htmlspecialchars(addslashes($item['filename'])) ?>?')"
               title="Delete">
              <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><polyline points="3 6 5 6 21 6"/><path d="M19 6v14a2 2 0 0 1-2 2H7a2 2 0 0 1-2-2V6m3 0V4a2 2 0 0 1 2-2h4a2 2 0 0 1 2 2v2"/></svg>
            </a>
            <?php endif; ?>
          </div>
        </td>
      </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
  <?php endif; ?>
</main>

<!-- New Folder Modal -->
<div class="modal-backdrop" id="modal-folder">
  <div class="modal">
    <h2>New Folder</h2>
    <form method="POST" action="/action_folder.php">
      <input type="hidden" name="parent_id" value="<?= $folder_id ?? '' ?>">
      <div class="field">
        <label>Folder Name</label>
        <input type="text" name="foldername" placeholder="My Folder" autofocus required>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-folder')">Cancel</button>
        <button type="submit" class="btn btn-primary">Create</button>
      </div>
    </form>
  </div>
</div>

<!-- Upload Modal -->
<div class="modal-backdrop" id="modal-upload">
  <div class="modal">
    <h2>Upload File</h2>
    <form method="POST" action="/action_upload.php" enctype="multipart/form-data">
      <input type="hidden" name="folder_id" value="<?= $folder_id ?? '' ?>">
      <div class="drop-zone" id="drop-zone" onclick="document.getElementById('file-input').click()">
        <div class="dz-icon">
          <svg width="32" height="32" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round"><path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/><polyline points="17 8 12 3 7 8"/><line x1="12" y1="3" x2="12" y2="15"/></svg>
        </div>
        <p id="dz-label">Click to choose a file<br><small>or drag &amp; drop here</small></p>
        <input type="file" name="file" id="file-input" required>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-upload')">Cancel</button>
        <button type="submit" class="btn btn-primary">Upload</button>
      </div>
    </form>
  </div>
</div>

<!-- Rename Modal -->
<div class="modal-backdrop" id="modal-rename">
  <div class="modal">
    <h2>Rename</h2>
    <form method="POST" action="/action_rename.php">
      <input type="hidden" name="id" id="rename-id">
      <input type="hidden" name="folder_id" value="<?= $folder_id ?? '' ?>">
      <div class="field">
        <label>New Name</label>
        <input type="text" name="new_name" id="rename-name" required autofocus>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-rename')">Cancel</button>
        <button type="submit" class="btn btn-primary">Rename</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

function openRename(id, name) {
  document.getElementById('rename-id').value = id;
  document.getElementById('rename-name').value = name;
  openModal('modal-rename');
}

// Close modal on backdrop click
document.querySelectorAll('.modal-backdrop').forEach(el => {
  el.addEventListener('click', e => { if (e.target === el) el.classList.remove('open'); });
});

// Drag & drop label
const fileInput = document.getElementById('file-input');
const dzLabel   = document.getElementById('dz-label');
const dropZone  = document.getElementById('drop-zone');

fileInput?.addEventListener('change', () => {
  if (fileInput.files[0]) dzLabel.textContent = fileInput.files[0].name;
});

dropZone?.addEventListener('dragover', e => { e.preventDefault(); dropZone.classList.add('drag-over'); });
dropZone?.addEventListener('dragleave', () => dropZone.classList.remove('drag-over'));
dropZone?.addEventListener('drop', e => {
  e.preventDefault();
  dropZone.classList.remove('drag-over');
  if (e.dataTransfer.files[0]) {
    fileInput.files = e.dataTransfer.files;
    dzLabel.textContent = e.dataTransfer.files[0].name;
  }
});
</script>
</body>
</html>
