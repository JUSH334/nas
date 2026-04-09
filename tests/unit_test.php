<?php
/**
 * Unit Tests for NAS Web Server
 * Run inside the web container:
 *   docker exec nas-web php /var/www/html/../tests/unit_test.php
 */

$passed = 0;
$failed = 0;
$errors = [];

function assert_true($condition, string $name) {
    global $passed, $failed, $errors;
    if ($condition) {
        $passed++;
        echo "  PASS: $name\n";
    } else {
        $failed++;
        $errors[] = $name;
        echo "  FAIL: $name\n";
    }
}

function assert_equals($expected, $actual, string $name) {
    assert_true($expected === $actual, "$name (expected: " . var_export($expected, true) . ", got: " . var_export($actual, true) . ")");
}

// ─── Database Connection ────────────────────────────────
echo "\n=== Database Connection Tests ===\n";

$db   = getenv('MYSQL_DATABASE') ?: 'nas_db';
$user = getenv('MYSQL_USER')     ?: 'nas_user';
$pass = getenv('MYSQL_PASSWORD') ?: '';

try {
    $pdo = new PDO("mysql:host=db;dbname=$db;charset=utf8mb4", $user, $pass, [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    ]);
    assert_true(true, "PDO connection established");
} catch (PDOException $e) {
    assert_true(false, "PDO connection established - " . $e->getMessage());
    echo "\nCannot continue without database. Exiting.\n";
    exit(1);
}

// ─── Schema Tests ───────────────────────────────────────
echo "\n=== Schema Tests ===\n";

$tables = $pdo->query("SHOW TABLES")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('users', $tables), "Table 'users' exists");
assert_true(in_array('files', $tables), "Table 'files' exists");
assert_true(in_array('permissions', $tables), "Table 'permissions' exists");
assert_true(in_array('backups', $tables), "Table 'backups' exists");

// Check users table columns
$cols = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('id', $cols), "users.id column exists");
assert_true(in_array('username', $cols), "users.username column exists");
assert_true(in_array('password', $cols), "users.password column exists");
assert_true(in_array('role', $cols), "users.role column exists");
assert_true(in_array('email', $cols), "users.email column exists");

// Check files table columns
$cols = $pdo->query("DESCRIBE files")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('owner_id', $cols), "files.owner_id column exists");
assert_true(in_array('filename', $cols), "files.filename column exists");
assert_true(in_array('is_folder', $cols), "files.is_folder column exists");
assert_true(in_array('parent_id', $cols), "files.parent_id column exists");

// Check permissions table columns
$cols = $pdo->query("DESCRIBE permissions")->fetchAll(PDO::FETCH_COLUMN);
assert_true(in_array('file_id', $cols), "permissions.file_id column exists");
assert_true(in_array('user_id', $cols), "permissions.user_id column exists");
assert_true(in_array('can_read', $cols), "permissions.can_read column exists");
assert_true(in_array('can_write', $cols), "permissions.can_write column exists");
assert_true(in_array('can_delete', $cols), "permissions.can_delete column exists");

// ─── Default Admin Tests ────────────────────────────────
echo "\n=== Default Admin Tests ===\n";

$admin = $pdo->query("SELECT * FROM users WHERE username = 'admin'")->fetch();
assert_true($admin !== false, "Default admin user exists");
assert_equals('admin', $admin['role'], "Admin has 'admin' role");
assert_true(password_verify('admin123', $admin['password']), "Admin password hash matches 'admin123'");
assert_equals('admin@localhost', $admin['email'], "Admin email is admin@localhost");

// ─── Password Hashing Tests ────────────────────────────
echo "\n=== Password Hashing Tests ===\n";

$hash = password_hash('testpass', PASSWORD_BCRYPT);
assert_true(password_verify('testpass', $hash), "password_hash/password_verify works correctly");
assert_true(!password_verify('wrongpass', $hash), "Wrong password is rejected");

// ─── User CRUD Tests ───────────────────────────────────
echo "\n=== User CRUD Tests ===\n";

// Create test user
$test_hash = password_hash('test123', PASSWORD_BCRYPT);
$stmt = $pdo->prepare("INSERT INTO users (username, password, email, role) VALUES (?, ?, ?, ?)");
$stmt->execute(['test_unit_user', $test_hash, 'test@test.com', 'user']);
$test_user_id = $pdo->lastInsertId();
assert_true($test_user_id > 0, "Test user created with ID $test_user_id");

// Read test user
$fetched = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$fetched->execute([$test_user_id]);
$test_user = $fetched->fetch();
assert_equals('test_unit_user', $test_user['username'], "Test user username matches");
assert_equals('user', $test_user['role'], "Test user role is 'user'");

// Update test user
$pdo->prepare("UPDATE users SET role = 'admin' WHERE id = ?")->execute([$test_user_id]);
$fetched->execute([$test_user_id]);
$updated = $fetched->fetch();
assert_equals('admin', $updated['role'], "Test user role updated to 'admin'");

// ─── File/Folder CRUD Tests ────────────────────────────
echo "\n=== File/Folder CRUD Tests ===\n";

// Create folder
$stmt = $pdo->prepare("INSERT INTO files (owner_id, filename, filepath, filesize, filetype, is_folder, parent_id) VALUES (?, ?, ?, 0, 'inode/directory', 1, NULL)");
$stmt->execute([$test_user_id, 'Test_Folder', '']);
$folder_id = $pdo->lastInsertId();
assert_true($folder_id > 0, "Folder created with ID $folder_id");

// Create file inside folder
$stmt = $pdo->prepare("INSERT INTO files (owner_id, filename, filepath, filesize, filetype, is_folder, parent_id) VALUES (?, ?, ?, ?, ?, 0, ?)");
$stmt->execute([$test_user_id, 'testfile.txt', 'testfile.txt', 1024, 'text/plain', $folder_id]);
$file_id = $pdo->lastInsertId();
assert_true($file_id > 0, "File created inside folder with ID $file_id");

// Verify parent-child relationship
$child = $pdo->prepare("SELECT * FROM files WHERE id = ?");
$child->execute([$file_id]);
$child_file = $child->fetch();
assert_equals((int)$folder_id, (int)$child_file['parent_id'], "File parent_id matches folder ID");

// Rename file
$pdo->prepare("UPDATE files SET filename = 'renamed_file.txt' WHERE id = ?")->execute([$file_id]);
$child->execute([$file_id]);
assert_equals('renamed_file.txt', $child->fetch()['filename'], "File renamed successfully");

// ─── Permissions Tests ──────────────────────────────────
echo "\n=== Permissions Tests ===\n";

// Assign permission
$stmt = $pdo->prepare("INSERT INTO permissions (file_id, user_id, can_read, can_write, can_delete) VALUES (?, ?, 1, 0, 0)");
$stmt->execute([$file_id, $admin['id']]);
$perm_id = $pdo->lastInsertId();
assert_true($perm_id > 0, "Permission created");

// Read permission
$perm = $pdo->prepare("SELECT * FROM permissions WHERE file_id = ? AND user_id = ?");
$perm->execute([$file_id, $admin['id']]);
$p = $perm->fetch();
assert_equals(1, (int)$p['can_read'], "can_read is 1");
assert_equals(0, (int)$p['can_write'], "can_write is 0");
assert_equals(0, (int)$p['can_delete'], "can_delete is 0");

// Update permission
$pdo->prepare("UPDATE permissions SET can_write = 1, can_delete = 1 WHERE id = ?")->execute([$perm_id]);
$perm->execute([$file_id, $admin['id']]);
$p = $perm->fetch();
assert_equals(1, (int)$p['can_write'], "can_write updated to 1");
assert_equals(1, (int)$p['can_delete'], "can_delete updated to 1");

// Unique constraint test
try {
    $stmt = $pdo->prepare("INSERT INTO permissions (file_id, user_id, can_read, can_write, can_delete) VALUES (?, ?, 1, 1, 1)");
    $stmt->execute([$file_id, $admin['id']]);
    assert_true(false, "Duplicate permission should fail");
} catch (PDOException $e) {
    assert_true(str_contains($e->getMessage(), 'Duplicate'), "Duplicate permission correctly rejected");
}

// ─── Cascade Delete Tests ───────────────────────────────
echo "\n=== Cascade Delete Tests ===\n";

// Delete folder should cascade to child file and its permissions
$pdo->prepare("DELETE FROM files WHERE id = ?")->execute([$folder_id]);

$child->execute([$file_id]);
assert_true($child->fetch() === false, "Child file cascaded on folder delete");

$perm->execute([$file_id, $admin['id']]);
assert_true($perm->fetch() === false, "Permission cascaded on file delete");

// ─── Backups Table Tests ────────────────────────────────
echo "\n=== Backups Table Tests ===\n";

$stmt = $pdo->prepare("INSERT INTO backups (filename, filepath, filesize, created_by) VALUES (?, ?, ?, ?)");
$stmt->execute(['test_backup.zip', '/var/www/backups/test_backup.zip', 5000, $test_user_id]);
$backup_id = $pdo->lastInsertId();
assert_true($backup_id > 0, "Backup record created");

$b = $pdo->prepare("SELECT * FROM backups WHERE id = ?");
$b->execute([$backup_id]);
$backup = $b->fetch();
assert_equals('test_backup.zip', $backup['filename'], "Backup filename matches");

// Cleanup
$pdo->prepare("DELETE FROM backups WHERE id = ?")->execute([$backup_id]);

// ─── Cleanup ────────────────────────────────────────────
echo "\n=== Cleanup ===\n";
$pdo->prepare("DELETE FROM users WHERE id = ?")->execute([$test_user_id]);
$check = $pdo->prepare("SELECT * FROM users WHERE id = ?");
$check->execute([$test_user_id]);
assert_true($check->fetch() === false, "Test user deleted");

// ─── Summary ────────────────────────────────────────────
echo "\n" . str_repeat('=', 50) . "\n";
echo "UNIT TESTS COMPLETE: $passed passed, $failed failed\n";
if ($failed > 0) {
    echo "Failed tests:\n";
    foreach ($errors as $e) echo "  - $e\n";
}
echo str_repeat('=', 50) . "\n\n";

exit($failed > 0 ? 1 : 0);
