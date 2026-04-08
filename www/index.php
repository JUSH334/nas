<?php
require_once 'auth.php';
require_login();
require_once 'db.php';

$user = current_user();

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
if ($folder_id) {
    $stmt = $pdo->prepare('
        SELECT f.*, u.username AS owner_name
        FROM files f
        LEFT JOIN users u ON f.owner_id = u.id
        WHERE f.parent_id = ?
        ORDER BY f.is_folder DESC, f.filename ASC
    ');
    $stmt->execute([$folder_id]);
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
            SELECT f.*, u.username AS owner_name
            FROM files f
            LEFT JOIN users u ON f.owner_id = u.id
            WHERE f.parent_id IS NULL AND f.owner_id = ?
            ORDER BY f.is_folder DESC, f.filename ASC
        ');
        $stmt->execute([$user['id']]);
    }
}
$items = $stmt->fetchAll();

// Format file size
function fmt_size(int $bytes): string {
    if ($bytes < 1024) return $bytes . ' B';
    if ($bytes < 1048576) return round($bytes / 1024, 1) . ' KB';
    if ($bytes < 1073741824) return round($bytes / 1048576, 1) . ' MB';
    return round($bytes / 1073741824, 2) . ' GB';
}

// File icon by MIME type
function file_icon(string $type, bool $is_folder): string {
    if ($is_folder) return '📁';
    if (str_starts_with($type, 'image/')) return '🖼️';
    if (str_starts_with($type, 'video/')) return '🎬';
    if (str_starts_with($type, 'audio/')) return '🎵';
    if ($type === 'application/pdf') return '📄';
    if (str_contains($type, 'zip') || str_contains($type, 'tar')) return '🗜️';
    return '📃';
}

$folder_param = $folder_id ? "?folder=$folder_id" : '';
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
    background: var(--surface);
    border-bottom: 1px solid var(--border);
    padding: 0 28px;
    height: 56px;
    display: flex;
    align-items: center;
    gap: 20px;
    position: sticky;
    top: 0;
    z-index: 100;
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
    transition: color 0.15s, background 0.15s;
  }
  .nav-link:hover, .nav-link.active {
    color: var(--text);
    background: var(--surface2);
  }
  .nav-link.active { color: var(--accent); }

  .nav-user {
    display: flex;
    align-items: center;
    gap: 10px;
    font-size: 13px;
    color: var(--muted);
  }
  .nav-user strong { color: var(--text); }
  .nav-user a {
    color: var(--muted);
    text-decoration: none;
    font-size: 12px;
    padding: 4px 10px;
    border: 1px solid var(--border);
    border-radius: var(--radius);
    transition: border-color 0.15s, color 0.15s;
  }
  .nav-user a:hover { border-color: var(--danger); color: var(--danger); }

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
  .breadcrumb a { color: var(--muted); text-decoration: none; }
  .breadcrumb a:hover { color: var(--accent); }
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
  .btn:hover { opacity: 0.85; transform: translateY(-1px); }
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
  }
  .file-table tbody tr:hover { background: var(--surface2); }

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

  .actions { display: flex; gap: 8px; }
  .action-btn {
    background: none;
    border: none;
    color: var(--muted);
    cursor: pointer;
    font-size: 13px;
    padding: 4px 8px;
    border-radius: var(--radius);
    text-decoration: none;
    transition: color 0.12s, background 0.12s;
  }
  .action-btn:hover { background: var(--surface2); color: var(--text); }
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
<body>

<nav>
  <a class="nav-logo" href="/">NAS</a>
  <div class="nav-links">
    <a class="nav-link active" href="/">📁 Files</a>
    <?php if (is_admin()): ?>
    <a class="nav-link" href="/users.php">👥 Users</a>
    <?php endif; ?>
    <?php if (is_admin()): ?>
    <a class="nav-link" href="/monitor.php">📊 Monitor</a>
    <?php endif; ?>
  </div>
  <div class="nav-user">
    <span>Hello, <strong><?= htmlspecialchars($user['username']) ?></strong></span>
    <?php if (is_admin()): ?><span style="color:var(--accent);font-size:11px;font-family:'Space Mono',monospace">ADMIN</span><?php endif; ?>
    <a href="/logout.php">Sign out</a>
  </div>
</nav>

<main>

  <!-- Breadcrumb -->
  <div class="breadcrumb">
    <a href="/">~ root</a>
    <?php foreach ($breadcrumbs as $crumb): ?>
      <span class="sep">/</span>
      <a href="/?folder=<?= $crumb['id'] ?>"><?= htmlspecialchars($crumb['filename']) ?></a>
    <?php endforeach; ?>
  </div>

  <!-- Flash message -->
  <?php if (!empty($_SESSION['flash'])): ?>
    <div class="flash <?= $_SESSION['flash']['type'] ?>"><?= htmlspecialchars($_SESSION['flash']['msg']) ?></div>
    <?php unset($_SESSION['flash']); ?>
  <?php endif; ?>

  <!-- Toolbar -->
  <div class="toolbar">
    <h1><?= $current_folder ? htmlspecialchars($current_folder['filename']) : 'My Files' ?></h1>
    <button class="btn btn-secondary" onclick="openModal('modal-folder')">＋ New Folder</button>
    <button class="btn btn-primary" onclick="openModal('modal-upload')">↑ Upload</button>
  </div>

  <!-- File listing -->
  <?php if (empty($items)): ?>
    <div class="empty-state">
      <div class="icon">📭</div>
      <p>This folder is empty.<br>Upload a file or create a folder to get started.</p>
    </div>
  <?php else: ?>
  <table class="file-table">
    <thead>
      <tr>
        <th>Name</th>
        <th>Size</th>
        <th>Owner</th>
        <th>Modified</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($items as $item): ?>
      <tr>
        <td class="file-name">
          <span class="icon"><?= file_icon($item['filetype'] ?? '', (bool)$item['is_folder']) ?></span>
          <?php if ($item['is_folder']): ?>
            <a href="/?folder=<?= $item['id'] ?>"><?= htmlspecialchars($item['filename']) ?></a>
          <?php else: ?>
            <a href="/download.php?id=<?= $item['id'] ?>"><?= htmlspecialchars($item['filename']) ?></a>
          <?php endif; ?>
        </td>
        <td class="file-meta"><?= $item['is_folder'] ? '—' : fmt_size((int)$item['filesize']) ?></td>
        <td class="file-meta"><?= htmlspecialchars($item['owner_name'] ?? '—') ?></td>
        <td class="file-meta"><?= date('M j, Y', strtotime($item['updated_at'])) ?></td>
        <td>
          <div class="actions">
            <?php if (!$item['is_folder']): ?>
            <a class="action-btn" href="/download.php?id=<?= $item['id'] ?>" title="Download">⬇</a>
            <?php endif; ?>
            <?php if (is_admin() || $item['owner_id'] == $user['id']): ?>
            <a class="action-btn del" href="/delete.php?id=<?= $item['id'] ?><?= $folder_id ? "&folder=$folder_id" : '' ?>"
               onclick="return confirm('Delete <?= htmlspecialchars(addslashes($item['filename'])) ?>?')"
               title="Delete">🗑</a>
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
        <div class="dz-icon">☁️</div>
        <p id="dz-label">Click to choose a file<br><small>or drag & drop here</small></p>
        <input type="file" name="file" id="file-input" required>
      </div>
      <div class="modal-actions">
        <button type="button" class="btn btn-secondary" onclick="closeModal('modal-upload')">Cancel</button>
        <button type="submit" class="btn btn-primary">Upload</button>
      </div>
    </form>
  </div>
</div>

<script>
function openModal(id)  { document.getElementById(id).classList.add('open'); }
function closeModal(id) { document.getElementById(id).classList.remove('open'); }

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
