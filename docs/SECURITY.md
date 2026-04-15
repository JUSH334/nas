# NAS Security Model

This document explains the layered defenses protecting the NAS web server.
Each layer addresses a different class of threat — together they cover the
"firewall and user permissions" requirement of the project brief.

---

## 1. Network layer (firewall)

The Docker Compose stack runs four containers: `nas-web`, `nas-db`,
`nas-phpmyadmin`, and `nas-tunnel`. Containers communicate over a private
Docker network that is **not reachable from the host or the internet**.

| Service | Internal port | Host port | Public via tunnel |
|---|---|---|---|
| nas-web | 80 | **8080** | Yes (HTTPS) |
| nas-db (MySQL) | 3306 | *not exposed* | No |
| nas-phpmyadmin | 80 | 8081 | No |
| nas-tunnel | — | — | Outbound only |

**Why MySQL is not exposed to the host.** Earlier versions mapped
`3306:3306`, which let any program on the LAN connect to the database
directly. We removed that mapping (`expose:` instead of `ports:`),
so MySQL is now reachable only by the web and phpmyadmin containers
through the internal Docker bridge. This is the same effect a
host firewall would have, achieved at the container layer.

**Verification:**
```
nmap -p 3306 localhost   # → 3306/tcp closed
```

**Cloudflare Tunnel** opens an *outbound* connection to Cloudflare's edge
network — no inbound port is opened on the router. This means:
- Your home IP address is never exposed to visitors
- HTTPS is terminated by Cloudflare with a valid certificate
- The tunnel can be torn down instantly with `docker compose stop tunnel`

---

## 2. Authentication layer

- **Passwords are bcrypt-hashed** (`password_hash()` / `password_verify()`).
  Plaintext passwords are never stored or logged.
- **Sessions** are PHP server-side, identified by a random session ID cookie.
- **Login rate limiting**: 5 failed attempts from the same IP within 5
  minutes triggers a lockout. Tracked per-IP in `/tmp/nas_login_attempts/`
  and cleared on any successful login.
  - Defends against brute-force attempts on the publicly tunneled URL.
- **Default admin credentials** (`admin / admin123`) MUST be changed
  before any public deployment. Change via `/profile.php` after login.

---

## 3. Authorization layer (user permissions)

Two complementary mechanisms:

### Role-based (page-level)
Defined in `auth.php`:
- `require_login()` — gates every protected page.
- `require_admin()` — restricts user management, monitoring, logs,
  and backup pages to the `admin` role.

### File-based (per-resource)
Defined in `permissions.php` and the `permissions` table:
- Every file or folder has independent `can_read`, `can_write`,
  and `can_delete` flags per user.
- File owners and admins can grant/revoke permissions.
- Permission checks happen on every action (`action_*.php`) so
  URL-tampering does not bypass them.

### Storage quota (per-user resource limit)
- Admins can set a `storage_quota` (in MB) on each user.
- `action_upload.php` blocks uploads that would exceed the quota.
- Prevents a single user from filling the disk and DoS'ing others.

---

## 4. Application layer

- **PDO prepared statements** for every database query → SQL injection safe.
- **`htmlspecialchars()`** on every dynamic value rendered in HTML →
  XSS safe.
- **`escapeshellarg()`** on every value passed to `exec()` (backup, restore,
  rename) → command injection safe.
- **Apache `.htaccess`** denies direct access to sensitive files
  (`.env`, `*.sql`, log files).
- **File uploads** are stored under per-user directories
  (`/var/www/uploads/<user_id>/`) with the database holding the canonical
  filename — uploaded files are never executed by Apache.

---

## 5. Operational hardening checklist

Before exposing the NAS publicly via the tunnel:

- [ ] Change the default `admin` password
- [ ] Set a strong `MYSQL_ROOT_PASSWORD` and `MYSQL_PASSWORD` in `.env`
- [ ] Confirm `.env` is in `.gitignore` (it is)
- [ ] Confirm port 3306 is not exposed (`nmap -p 3306 localhost`)
- [ ] Take a manual backup (`/backup.php`) so a restore point exists
- [ ] Tear the tunnel down when not actively demoing
  (`docker compose stop tunnel`)

---

## What is intentionally out of scope

- **Two-factor authentication.** Would require TOTP libraries and QR
  enrollment — not in the project requirements.
- **HTTPS on the local port (`8080`).** Cloudflare Tunnel handles HTTPS
  for the public URL; locally we accept plain HTTP because traffic never
  leaves the host.
- **Audit log of every action.** Apache access logs and the application
  backup log cover the major actions; per-action audit (who renamed what)
  would need a dedicated `audit_log` table.
- **Host-level firewall (UFW / Windows Defender rules).** The Docker
  port mappings are the effective boundary for this stack — adding a
  second host firewall on top would not change which services are
  reachable.
