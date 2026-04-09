# NAS Web Server — Backend Documentation

## Technology Stack

- **PHP 8.2** — Server-side scripting
- **Apache 2.4** — Web server with `mod_rewrite` enabled
- **MySQL 8.0** — Relational database
- **PDO** — Database access layer (prepared statements)
- **Cron** — Scheduled task execution (automatic backups)

## File Structure

```
www/
├── index.php                 # File manager (main page)
├── login.php                 # Authentication page
├── logout.php                # Session destruction
├── auth.php                  # Authentication helper functions
├── db.php                    # Database connection (shared)
├── users.php                 # User management page (admin)
├── monitor.php               # System monitoring dashboard (admin)
├── logs.php                  # System log viewer (admin)
├── backup.php                # Backup & restore page (admin)
├── permissions.php           # Per-file permissions page (admin)
├── cron_backup.php           # Automated backup script (cron)
├── download.php              # File download handler
├── delete.php                # File/folder deletion handler
├── action_upload.php         # File upload handler
├── action_folder.php         # Folder creation handler
├── action_rename.php         # File/folder rename handler
├── action_user_create.php    # User creation handler
├── action_user_edit.php      # User edit handler
└── action_user_delete.php    # User deletion handler
```

## Shared Modules

### db.php — Database Connection

Establishes a PDO connection to MySQL using environment variables. Included by any file that needs database access.

- **Connection**: `mysql:host=db` (Docker service name resolved via internal DNS)
- **Credentials**: Read from `MYSQL_DATABASE`, `MYSQL_USER`, `MYSQL_PASSWORD` environment variables
- **Settings**: Exceptions enabled, associative fetch mode, native prepared statements

### auth.php — Authentication Helpers

Provides four functions used across all protected pages:

| Function | Purpose | Used By |
|---|---|---|
| `require_login()` | Redirects to `/login.php` if not authenticated | All protected pages |
| `require_admin()` | Returns 403 if user is not an admin | Admin-only pages |
| `current_user()` | Returns array with `id`, `username`, `role` from session | All pages for display |
| `is_admin()` | Returns boolean for admin check | Nav visibility, action gates |

## Authentication

### login.php

- **Method**: POST form submission
- **Process**:
  1. Query `users` table by username
  2. Verify password with `password_verify()` against bcrypt hash
  3. On success: store `user_id`, `username`, `role` in `$_SESSION`, update `last_login`, redirect to `index.php`
  4. On failure: display error, preserve submitted username
- **Protection**: Already-logged-in users are redirected to `index.php`

### logout.php

- Calls `session_destroy()` and redirects to `/login.php`

## File Management

### action_upload.php

- **Auth**: `require_login()`
- **Process**:
  1. Validate `$_FILES['file']` has no errors
  2. Sanitize filename (replace non-word characters with `_`)
  3. Create user-specific directory: `/var/www/uploads/{user_id}/`
  4. Handle duplicate filenames by appending counter (`file_1.txt`, `file_2.txt`)
  5. Move uploaded file to destination
  6. Insert metadata into `files` table (owner, name, path, size, MIME type, parent folder)
- **Storage**: Files stored at `/var/www/uploads/{user_id}/{filename}`

### download.php

- **Auth**: `require_login()`, owner or admin only
- **Process**:
  1. Fetch file record by ID
  2. Verify ownership or admin role
  3. Serve file with `Content-Disposition: attachment` header

### delete.php

- **Auth**: `require_login()`, owner or admin only
- **Process**:
  1. Fetch file/folder record
  2. Verify ownership or admin role
  3. If file: delete from disk (`/var/www/uploads/`)
  4. Delete from database (foreign key cascades handle children and permissions)

### action_folder.php

- **Auth**: `require_login()`
- **Process**:
  1. Sanitize folder name
  2. Insert into `files` table with `is_folder = 1`, `filetype = 'inode/directory'`
  3. Set `parent_id` for nested folders

### action_rename.php

- **Auth**: `require_login()`, owner or admin only
- **Process**:
  1. Fetch file/folder record
  2. Sanitize new name
  3. If file: rename on disk, update `filepath` in database
  4. If folder: update `filename` only (no disk path)

## User Management (Admin Only)

### action_user_create.php

- **Auth**: `require_admin()`
- **Validation**: Username required, password minimum 8 characters, duplicate username check
- **Password**: Hashed with `password_hash($password, PASSWORD_BCRYPT)`
- **Roles**: `admin` or `user` (validated against whitelist)

### action_user_edit.php

- **Auth**: `require_admin()`
- **Features**: Update username, email, role; optionally change password
- **Password**: If blank, keeps existing password; if provided, re-hashed

### action_user_delete.php

- **Auth**: `require_admin()`
- **Safety**: Cannot delete yourself
- **Cleanup**:
  1. Delete user's physical files from disk
  2. Remove empty upload directory
  3. Delete user from database (cascades to files and permissions)

## System Monitoring (Admin Only)

### monitor.php

Reads system metrics from Linux `/proc` filesystem:

| Metric | Source | Method |
|---|---|---|
| Disk usage | `disk_total_space()`, `disk_free_space()` | PHP built-in |
| Upload folder size | Recursive directory iterator | Custom `dir_size()` |
| CPU usage | `/proc/stat` (two samples, 500ms apart) | Custom `cpu_usage()` |
| Memory | `/proc/meminfo` | Custom `mem_info()` |
| User/file counts | SQL `COUNT(*)` queries | PDO |
| Recent uploads | SQL query (last 10 files) | PDO |
| Per-user storage | SQL `SUM(filesize) GROUP BY user` | PDO |

## System Logs (Admin Only)

### logs.php

Displays server log files with tab-based navigation:

| Log | Path | Notes |
|---|---|---|
| Apache Access | `/var/log/apache2/access.log` | Symlinked to `/dev/stdout` in Docker |
| Apache Error | `/var/log/apache2/error.log` | Symlinked to `/dev/stderr` in Docker |
| PHP Errors | `/var/log/php_errors.log` | Standard PHP error log |
| Backup Log | `/var/log/backup_cron.log` | Output from scheduled backups |
| System Log | `/var/log/syslog` | General system messages |

- **Docker handling**: Detects symlinks to `/dev/stdout`/`/dev/stderr` and reads from `/proc/1/fd/` with a timeout to prevent hangs
- **Line limit**: Configurable (50, 100, 250, 500 lines)

## Backup & Restore (Admin Only)

### backup.php — Manual Backups

**Create backup:**
1. Dump database with `mysqldump --skip-ssl --no-tablespaces`
2. Copy `/var/www/uploads/` directory
3. ZIP everything into `/var/www/backups/nas_backup_{timestamp}.zip`
4. Record in `backups` table

**Restore backup:**
1. Extract ZIP to temp directory
2. Import `database.sql` with `mysql --skip-ssl`
3. Replace `/var/www/uploads/` contents
4. Clean up temp directory

**Other actions:** Download backup ZIP, delete backup (file + DB record)

### Scheduled Backups

- **Schedule options**: Daily (2 AM), Weekly (Sunday 2 AM), Monthly (1st, 2 AM), Disabled
- **Implementation**: Writes cron entries inside the container via `crontab`
- **Script**: `cron_backup.php` runs standalone (no session/auth), connects directly to database
- **Retention**: Automatically keeps only the last 10 automatic backups

## Per-File Permissions (Admin Only)

### permissions.php

Manages the `permissions` table for granular file access control:

| Permission | Column | Default |
|---|---|---|
| Read | `can_read` | 1 (granted) |
| Write | `can_write` | 0 (denied) |
| Delete | `can_delete` | 0 (denied) |

- **Unique constraint**: One permission entry per user per file (`unique_file_user`)
- **Upsert**: Uses `INSERT ... ON DUPLICATE KEY UPDATE` for updates
- **Owner**: Always has full access (not shown in permissions list)

## Error Handling

- **Flash messages**: Stored in `$_SESSION['flash']` with `type` (success/error) and `msg`
- **Database errors**: PDO exceptions caught in `db.php`, returns 500 JSON error
- **Auth errors**: Redirect to login (unauthenticated) or 403 (unauthorized)
- **File errors**: Flash messages for upload failures, missing files, access denied

## Environment Variables

| Variable | Used By | Purpose |
|---|---|---|
| `MYSQL_DATABASE` | db.php, backup.php, cron_backup.php | Database name |
| `MYSQL_USER` | db.php, backup.php, cron_backup.php | Database username |
| `MYSQL_PASSWORD` | db.php, backup.php, cron_backup.php | Database password |
| `MYSQL_ROOT_PASSWORD` | MySQL container only | Root password for MySQL |
