# NAS Web Server

A web-based interface for managing a Network Attached Storage (NAS) server.
Built for Project 2: Linux Web-Server.

Includes file management, per-user permissions, role-based access control,
system monitoring, scheduled backups + restore, login rate limiting, and
optional public access via Cloudflare Tunnel.

---

## Tech Stack

- **Apache 2.4** — web server
- **PHP 8.2** — backend
- **MySQL 8.0** — database
- **phpMyAdmin** — DB admin UI
- **Cloudflare Tunnel** (`cloudflared`) — public HTTPS access without port forwarding
- **Docker Compose** — container orchestration

---

## Prerequisites

- [Docker Desktop](https://www.docker.com/products/docker-desktop/) (Windows / macOS) or Docker Engine + Docker Compose (Linux)
- ~2 GB free disk space for images
- A web browser

That's it. No PHP, MySQL, or Apache installation needed on your machine — everything runs inside containers.

---

## Setup (5 minutes)

### 1. Clone the repository
```bash
git clone <repo-url>
cd NAS
```

### 2. Create the `.env` file in the project root
```bash
MYSQL_ROOT_PASSWORD=changeme_root
MYSQL_DATABASE=nas_db
MYSQL_USER=nas_user
MYSQL_PASSWORD=changeme_user
```
> Pick your own passwords. This file is gitignored — never commit it.

### 3. Start the stack
```bash
docker compose up -d
```
First run takes a few minutes (downloading images, initializing the database).

### 4. Verify everything is running
```bash
docker compose ps
```
You should see four containers: `nas-web`, `nas-db`, `nas-phpmyadmin`, `nas-tunnel`.

### 5. Open the app
Visit **http://localhost:8080** and log in with the seeded admin account:

| Username | Password |
|----------|----------|
| `admin`  | `admin123` |


---

## Service URLs

| Service             | URL                          | Purpose |
|---------------------|------------------------------|---------|
| NAS Web App         | http://localhost:8080        | Main interface |
| phpMyAdmin          | http://localhost:8081        | DB admin (use `nas_user` + your `.env` password) |
| Public tunnel URL   | see "Remote Access" below    | HTTPS access from anywhere |

---

## Remote Access (Cloudflare Tunnel)

The `nas-tunnel` container automatically exposes the NAS to a public HTTPS URL on every startup. **No router config, no port forwarding, no Cloudflare account required.**

### Get the current URL

**PowerShell (Windows):**
```powershell
docker logs nas-tunnel | Select-String "trycloudflare.com"
```

**Bash (macOS/Linux/Git Bash):**
```bash
docker logs nas-tunnel 2>&1 | grep -oE "https://[a-zA-Z0-9-]+\.trycloudflare\.com" | head -1
```

### Notes
- The URL **changes every time** the tunnel container is restarted.
- To stop public access without taking down the rest of the stack:
  ```bash
  docker compose stop tunnel
  ```
- To restart and get a new URL:
  ```bash
  docker compose up -d tunnel
  ```

---

## Optional: USB Backup Mirror (Windows)

You can plug in any USB drive (flash stick or external HDD) and have the NAS automatically mirror every backup to it within ~3 seconds of creation. This is the "secondary backup destination" pattern real NAS products call **External Storage**.

**Important:** This is fully optional. The NAS works perfectly without it — without a USB plugged in, the External Storage panel just shows "Disconnected" and nothing breaks.

### Setup (3 steps, ~1 minute)

1. **Plug in your USB drive.** Note the drive letter Windows assigns (e.g. `D:`, `E:`, `F:`).

2. **Edit the watcher path.** Open [scripts/mirror_watcher.ps1](scripts/mirror_watcher.ps1) and change line 9 if your drive isn't `D:`:
   ```powershell
   [string]$Target = "D:\nas-backups"   # change D: to your drive letter
   ```

3. **Run the install script** in PowerShell (no admin needed):
   ```powershell
   .\scripts\install_usb_sync.ps1
   ```

That's it. The script registers a hidden Windows scheduled task that starts at user logon, polls every 3 seconds, and mirrors any new backup zip from `external_backups/` to the USB drive. Confirm by visiting the **Monitor** page in the NAS — the **External Storage** panel should show a green dot, capacity gauge, and live file count.

### To remove later
```powershell
.\scripts\uninstall_usb_sync.ps1
```

### What gets mirrored
Two things, continuously (append-only, never deletes):

1. **`D:\nas-backups\`** — every backup ZIP as soon as it's created
2. **`D:\nas-users\u_<hash>\`** — every user's uploaded files, one folder per user, named by a hash of the user ID so physical theft of the drive doesn't reveal who owns what. The hash → username mapping lives only on the NAS host (in `external_backups/.user_manifest.json`), never on the USB.

### Physical security recommendation
For a production deployment, **turn on BitLocker** on the USB drive — right-click the drive in File Explorer → *"Turn on BitLocker"* → set a passphrase. The entire drive becomes AES-256 encrypted at the block level. Combined with the hashed user folder names, this means:
- **Drive lost or stolen** → raw bytes unreadable without the passphrase
- **Drive found plugged in** → folders are anonymous, no way to tell who owns what

### Notes
- **File system:** if your USB is formatted as **FAT32**, large backups (>4 GB) will fail to copy. Reformat the drive as **exFAT** or **NTFS** before use (right-click drive in File Explorer → Format).
- **Drive letter changes:** Windows usually keeps the same letter for the same physical port. If it shifts, just re-edit `mirror_watcher.ps1` and re-run `install_usb_sync.ps1`.
- **Unplug-safe:** if you yank the USB, the watcher logs a "paused" message and waits. Plug it back in and it resumes within 3 seconds. The NAS itself is unaffected.
- **Linux/Mac users:** the watcher is Windows-only (uses Task Scheduler + robocopy). The equivalent on Linux would be a tiny bash script + cron + `rsync` — easy to port if needed.

---

## Features

### File Management ([index.php](www/index.php))
- Upload, download, rename, delete files
- Create, rename, delete folders
- Breadcrumb navigation, search, drag-and-drop upload

### User Management ([users.php](www/users.php), admin only)
- Create / edit / delete users
- Assign roles (`admin` / `user`)
- Set per-user storage quotas (enforced at upload)

### Per-File Permissions ([permissions.php](www/permissions.php))
- File owners and admins can grant `read` / `write` / `delete` to other users
- Permissions checked on every action (URL tampering won't bypass them)

### System Monitoring ([monitor.php](www/monitor.php), admin only)
- Server uptime, CPU, memory, load average
- Disk volume usage (real host disk, not container overlay)
- Active sessions (users logged in within 30 min)
- Last automatic backup
- Per-user storage breakdown
- Recent uploads feed

### Backup & Restore ([backup.php](www/backup.php), admin only)
- Manual backups (database + uploads → single zip)
- Scheduled cron-driven backups with calendar UI
- Restore from any archive (rewinds DB and files)
- Auto-rotation: keeps last 10 automatic backups
- Backups stored in `./external_backups/` on the host (survives container rebuilds)
- Optional **USB drive mirror** via host-side watcher (see "Optional: USB Backup Mirror" above) — every backup is automatically copied to a second physical device within ~3 seconds of creation

### Logs ([logs.php](www/logs.php), admin only)
- Apache access log
- PHP error log
- Backup operation log

### Security
- bcrypt password hashing
- PHP server-side sessions
- Login rate limiting: 5 failed attempts / 5 min lockout per IP
- MySQL not exposed to host (internal Docker network only)
- PDO prepared statements (SQL injection safe)
- `htmlspecialchars` everywhere (XSS safe)
- `escapeshellarg` on every shell call (command injection safe)

See [docs/SECURITY.md](docs/SECURITY.md) for the full layered defense breakdown.

---

## Project Structure

```
NAS/
├── docker-compose.yml         # All four containers (web, db, phpmyadmin, tunnel)
├── .env                       # DB credentials (gitignored — you create this)
├── README.md                  # You are here
├── web/
│   ├── Dockerfile             # PHP 8.2 + Apache + cron + zip image
│   └── start.sh               # Container entrypoint (starts cron + Apache)
├── www/                       # Application code (PHP)
│   ├── index.php              # File manager
│   ├── login.php              # Auth + rate limiting
│   ├── register.php           # Self-registration
│   ├── profile.php            # Change own username/password
│   ├── users.php              # User admin
│   ├── permissions.php        # Per-file ACL
│   ├── monitor.php            # System dashboard
│   ├── backup.php             # Backup management
│   ├── cron_backup.php        # Standalone script run by cron
│   ├── logs.php               # Log viewer
│   ├── auth.php / db.php      # Shared helpers
│   └── action_*.php           # POST handlers (upload, delete, rename, etc.)
├── sql/
│   └── init.sql               # Schema, runs on first DB start
├── uploads/                   # User files (gitignored, bind-mounted)
├── external_backups/          # Backup archives (gitignored, bind-mounted)
├── docs/
│   ├── ARCHITECTURE.md        # System overview
│   ├── BACKEND.md             # PHP layer
│   ├── FRONTEND.md            # UI layer
│   ├── SECURITY.md            # Defense layers
│   └── PRESENTATION.md        # Slide-by-slide reference
├── scripts/                   # Optional Windows host-side helpers (USB mirror)
│   ├── install_usb_sync.ps1     # Register the watcher as a logon task
│   ├── uninstall_usb_sync.ps1   # Remove the task
│   ├── mirror_watcher.ps1       # Long-running mirror daemon (3s polling)
│   ├── mirror_watcher_launcher.vbs  # Hidden launcher (no console window)
│   └── mirror_to_usb.ps1        # One-shot manual mirror
└── tests/
    ├── unit_test.php          # 45 DB / business-logic tests
    ├── e2e_test.sh            # 30 HTTP end-to-end tests
    └── TESTING.md             # How to run them
```

---

## Running the Tests

### Unit tests (database / business logic — 45 tests)
```bash
docker cp tests/unit_test.php nas-web:/tmp/unit_test.php
docker exec nas-web php /tmp/unit_test.php
```

### End-to-end tests (HTTP requests against the live stack — 30 tests)
```bash
bash tests/e2e_test.sh
```
> Requires `curl` and `bash`. On Windows use Git Bash or WSL.

Both should report `0 failed`.

---

## Common Operations

### Stop everything
```bash
docker compose down
```

### Stop and **wipe the database** (destructive)
```bash
docker compose down -v
```
Use this if you want a clean reset. The seeded admin account will be recreated on next startup.

### Rebuild after editing the Dockerfile
```bash
docker compose up -d --build
```

### View live logs
```bash
docker compose logs -f web      # Apache + PHP
docker compose logs -f db       # MySQL
docker compose logs -f tunnel   # Cloudflare tunnel
```

### Make a manual backup right now (no UI)
```bash
docker exec nas-web php /var/www/html/cron_backup.php
```

---

## Troubleshooting

**"Connection refused" on first start**
MySQL takes ~10 seconds to initialize on first run. Wait, then refresh.

**`.env` is missing or wrong**
Check `docker compose ps` — if the `db` container keeps restarting, check `docker logs nas-db` for password mismatch errors.

**Tunnel URL doesn't work**
The URL takes ~5 seconds to become reachable after container start. If still failing, run `docker logs nas-tunnel` to confirm the URL was issued.

**Port 8080 / 8081 already in use**
Edit the host-side ports in [docker-compose.yml](docker-compose.yml) (e.g. `"9080:80"`).

**Lost admin password**
Reset via phpMyAdmin (http://localhost:8081) — open the `users` table and update the row's `password` field with a new bcrypt hash:
```bash
docker exec nas-web php -r "echo password_hash('newpass', PASSWORD_BCRYPT);"
```

---

## Project Requirements Coverage

This project was built against the Project 2 brief. All requirements covered:

- ✅ File management (upload/download/delete + folder ops)
- ✅ User management (create/modify/delete + permissions)
- ✅ System monitoring (disk, CPU, logs)
- ✅ Backup and restore (scheduled + manual)
- ✅ Linux + Apache + MySQL + PHP setup
- ✅ Web interface (HTML/CSS/JS + PHP backend + MySQL)
- ✅ Port forwarding (via Cloudflare Tunnel)
- ✅ User access control (roles + auth + rate limiting)
- ✅ Firewall + permissions (MySQL not exposed, app-level ACLs)
