# NAS Web Server — Architecture

## System Architecture

```
┌─────────────────────────────────────────────────────────────┐
│                      Docker Environment                      │
│                                                              │
│  ┌──────────────┐    ┌──────────────┐    ┌───────────────┐  │
│  │   nas-web     │    │   nas-db      │    │nas-phpmyadmin │  │
│  │              │    │              │    │               │  │
│  │  Apache 2.4  │    │  MySQL 8.0   │    │  phpMyAdmin   │  │
│  │  PHP 8.2     │◄──►│              │◄──►│               │  │
│  │  Cron        │    │              │    │               │  │
│  │              │    │              │    │               │  │
│  │  Port: 8080  │    │  Port: 3306  │    │  Port: 8081   │  │
│  └──────┬───────┘    └──────┬───────┘    └───────────────┘  │
│         │                   │                                │
│         ▼                   ▼                                │
│  ┌──────────────┐    ┌──────────────┐    ┌───────────────┐  │
│  │  ./www        │    │  db_data     │    │  backups      │  │
│  │  (bind mount) │    │  (volume)    │    │  (volume)     │  │
│  └──────────────┘    └──────────────┘    └───────────────┘  │
│         │                                                    │
│  ┌──────────────┐                                           │
│  │  ./uploads    │                                           │
│  │  (bind mount) │                                           │
│  └──────────────┘                                           │
└─────────────────────────────────────────────────────────────┘
```

## Container Services

| Service | Container | Image | Port | Purpose |
|---|---|---|---|---|
| web | nas-web | Custom (php:8.2-apache) | 8080 | Web server, PHP runtime, cron daemon |
| db | nas-db | mysql:8.0 | 3306 | Relational database |
| phpmyadmin | nas-phpmyadmin | phpmyadmin:latest | 8081 | Database management UI |

## Data Storage

| Volume/Mount | Type | Path in Container | Purpose |
|---|---|---|---|
| `./www` | Bind mount | `/var/www/html` | PHP application source code |
| `./uploads` | Bind mount | `/var/www/uploads` | User-uploaded files |
| `db_data` | Docker volume | `/var/lib/mysql` | MySQL database data |
| `backups` | Docker volume | `/var/www/backups` | Backup ZIP archives |

## Request Flow

```
Browser Request
      │
      ▼
  Apache (port 80 inside container, mapped to 8080)
      │
      ▼
  PHP Script (e.g., index.php)
      │
      ├── auth.php ──► Session check ──► Redirect to login.php if unauthenticated
      │
      ├── db.php ──► PDO connection to MySQL (host: "db", Docker DNS)
      │
      └── Business Logic ──► Query database, read/write files
              │
              ▼
          HTML Response (server-rendered with embedded CSS/JS)
```

## Authentication Flow

```
login.php (POST)
      │
      ├── Query users table by username
      │
      ├── password_verify() against bcrypt hash
      │
      ├── On success: Set $_SESSION[user_id, username, role]
      │               Redirect to index.php
      │
      └── On failure: Show error message

Protected Pages:
      │
      ├── require_login()  ──► Check $_SESSION['user_id'] exists
      │                        Redirect to login.php if not
      │
      └── require_admin()  ──► Check $_SESSION['role'] === 'admin'
                               Return 403 if not
```

## Database Schema

```
┌──────────────┐       ┌──────────────────┐       ┌──────────────┐
│    users      │       │      files        │       │  permissions  │
├──────────────┤       ├──────────────────┤       ├──────────────┤
│ id        PK │◄──┐   │ id            PK │◄──┐   │ id        PK │
│ username     │   │   │ owner_id      FK │───┘   │ file_id   FK │───┐
│ password     │   │   │ filename         │       │ user_id   FK │───┤
│ email        │   │   │ filepath         │       │ can_read     │   │
│ role         │   │   │ filesize         │       │ can_write    │   │
│ created_at   │   │   │ filetype         │       │ can_delete   │   │
│ last_login   │   │   │ is_folder        │       └──────────────┘   │
└──────────────┘   │   │ parent_id     FK │───┐                      │
                   │   │ created_at       │   │   (self-referencing   │
                   │   │ updated_at       │   │    for nested folders)│
                   │   └──────────────────┘   │                      │
                   │                          │                      │
                   │   ┌──────────────────┐   │                      │
                   │   │     backups       │   │                      │
                   │   ├──────────────────┤   │                      │
                   │   │ id            PK │   │                      │
                   └───│ created_by    FK │   │                      │
                       │ filename         │   │                      │
                       │ filepath         │   │                      │
                       │ filesize         │   │                      │
                       │ created_at       │   │                      │
                       └──────────────────┘   │                      │
                                              │                      │
              References: files.parent_id ────┘                      │
              References: permissions.file_id ───────────────────────┘
```

### Cascade Rules

| Relationship | On Delete |
|---|---|
| `files.owner_id` → `users.id` | CASCADE (delete user = delete all their files) |
| `files.parent_id` → `files.id` | CASCADE (delete folder = delete children) |
| `permissions.file_id` → `files.id` | CASCADE (delete file = delete its permissions) |
| `permissions.user_id` → `users.id` | CASCADE (delete user = delete their permissions) |
| `backups.created_by` → `users.id` | SET NULL (delete user = keep backup, set creator to NULL) |

## Security Model

```
                    ┌─────────────┐
                    │    Admin     │
                    │             │
                    │ All pages   │
                    │ All files   │
                    │ User CRUD   │
                    │ Backups     │
                    │ Monitoring  │
                    │ Logs        │
                    │ Permissions │
                    └──────┬──────┘
                           │
              ┌────────────┼────────────┐
              │            │            │
              ▼            ▼            ▼
         ┌─────────┐ ┌─────────┐ ┌─────────┐
         │ User A   │ │ User B   │ │ User C   │
         │          │ │          │ │          │
         │ Own files│ │ Own files│ │ Own files│
         │ only     │ │ only     │ │ only     │
         └─────────┘ └─────────┘ └─────────┘
```

- **Passwords**: Hashed with bcrypt (`PASSWORD_BCRYPT`)
- **Sessions**: PHP native sessions, server-side
- **SQL Injection**: Prevented via PDO prepared statements
- **XSS**: Output escaped with `htmlspecialchars()`
- **File Uploads**: Filenames sanitized, stored outside web root path
- **Credentials**: Stored in `.env` file, excluded from git via `.gitignore`
- **Network**: Docker internal network isolates container-to-container traffic
