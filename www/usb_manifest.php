<?php
// usb_manifest.php - Shared helper for the per-user USB archive feature.
//
// Writes a manifest file the host-side watcher reads to discover which
// upload directories to mirror, and what hashed folder name to use for each
// one. The manifest lives on the host only (never on the USB), so the USB
// contains only opaque u_<hash> folders - physical theft doesn't reveal who
// owns what.
//
// Hash scheme: "u_" + first 12 chars of sha256(salt + user_id)
// Salt is generated once and stored in the manifest itself.

function update_user_manifest(PDO $pdo): array {
    $path = '/var/www/backups/.user_manifest.json';
    $data = file_exists($path) ? (json_decode(@file_get_contents($path), true) ?: []) : [];

    // One-time salt generation - stable across manifest regenerations
    if (empty($data['salt'])) {
        $data['salt'] = bin2hex(random_bytes(16));
    }

    $users = [];
    foreach ($pdo->query('SELECT id, username FROM users ORDER BY id')->fetchAll() as $u) {
        $hash = 'u_' . substr(hash('sha256', $data['salt'] . $u['id']), 0, 12);
        $users[(string)$u['id']] = [
            'hash'     => $hash,
            'username' => $u['username'],
        ];
    }
    $data['users']      = $users;
    $data['updated_at'] = date('Y-m-d H:i:s');

    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT), LOCK_EX);
    return $data;
}

// Resolves a hash back to its username - for rendering in the UI.
// Returns null if not found (e.g. deleted user whose files are still on USB).
function username_for_hash(array $manifest, string $hash): ?string {
    foreach ($manifest['users'] ?? [] as $uid => $meta) {
        if (($meta['hash'] ?? '') === $hash) return $meta['username'];
    }
    return null;
}
