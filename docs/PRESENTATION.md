# NAS Web Server — Presentation Reference

Group 4 · CS 5531: Advanced Operating Systems

This is a live reference for the in-class presentation. For each slide:
- **Slide content** — what to put on the slide (keep bullets short)
- **Talking points** — what to say out loud while it's up

---

## Slide 1 — Title

**NAS Web Server**
CS 5531: Advanced Operating Systems · Group 4

> Quick intro — name the project, name the team. ~10 seconds.

---

## Slide 2 — Meet the team

- **Josh Castro** — Backend & Server
- **Michael Karr** — Backend & Server
- **Bosia N'dri** — Frontend & Server

> One-sentence each: "I worked on X."

---

## Slide 3 — What is a NAS Web Server?

A self-hosted file storage platform running entirely in Docker.

### Talking points
*"Think of it as a self-hosted Google Drive. Users upload files, organize them in folders, share them. Admins on top of that get monitoring, backups, user management, and per-file access control. The whole thing runs in Docker, so it's reproducible anywhere — clone the repo, run one command, you have a working NAS."*

---

## Slide 4 — Tech Stack

**Frontend:** HTML, CSS, JavaScript
**Backend:** PHP 8.2 + Apache 2.4
**Database:** MySQL 8.0
**Infrastructure:** Docker Compose

### Talking points
*"Classic LAMP stack — Linux, Apache, MySQL, PHP — running in containers instead of installed on the host. Four containers in total: one for the web app, one for MySQL, one for phpMyAdmin, and one for the public tunnel. Docker means our laptops, our teammate's laptop, and the demo machine all behave identically — no `it works on my machine` problems."*

---

## Slide 5 — NAS CloudFlare

### Slide content
- **Public HTTPS access without port forwarding**
- Outbound tunnel from `cloudflared` container → Cloudflare edge
- Visitors hit a `*.trycloudflare.com` URL
- No router config, no public IP exposed, free HTTPS
- Stop with `docker compose stop tunnel`

### Talking points
*"The project required port forwarding for remote access. The traditional approach — opening a port on the router and pointing a DDNS hostname at our public IP — has problems: it exposes our home IP, requires router admin access, and doesn't work behind CGNAT (common on mobile hotspots and dorm Wi-Fi)."*

*"Instead, we used Cloudflare Tunnel. The way it works: a tiny container called `cloudflared` makes an outbound connection to Cloudflare's servers and keeps it open — like leaving a phone line off the hook. When someone visits our public URL, Cloudflare receives the request first and pushes it back down that already-open connection to our NAS."*

*"Why this is better for us: zero router config, the URL has automatic HTTPS, our home IP stays hidden, and it works from any network. We can take it down instantly with one Docker command. The textbook port-forward solves the same problem, but with more setup and more attack surface."*

---

## Slide 6 — Database Schema

### Slide content
**4 tables** (with arrows showing foreign keys):
- `users` — id, username, password (bcrypt), email, **role**, **storage_quota**, last_login
- `files` — id, **owner_id**, filename, filepath, filesize, filetype, **is_folder**, **parent_id**
- `permissions` — id, **file_id**, **user_id**, can_read, can_write, can_delete
- `backups` — id, filename, filepath, filesize, **created_by**, created_at

**Relationships:** users → files (1:N) · files → files (parent_id, self-reference for folders) · files → permissions (1:N) · users → permissions (1:N)

### Talking points
*"Four tables. The interesting design choices:"*

*"**`files` is self-referencing** — a folder is just a file row with `is_folder=1`, and other files point to it via `parent_id`. This means we get unlimited folder nesting for free, no special tree table needed."*

*"**`permissions` is a separate table, not columns on `files`** — because permissions are many-to-many. One file can have permissions for multiple users, and one user can have permissions on many files. Putting it in its own table keeps the schema clean and lets us add new permission types later without an `ALTER TABLE`."*

*"**Cascade deletes everywhere** — if a user is deleted, their files cascade. If a folder is deleted, every file inside it cascades. We never have orphan rows."*

*"**`storage_quota` is nullable** — null means unlimited, a number means the cap in bytes. The upload handler checks this before accepting a file and rejects with a friendly message if exceeded."*

---

## Slide 7 — Key Features (overview grid)

Four boxes — File Mgmt, User Mgmt, Permissions, Monitoring & Backups.

### Talking points
*"Brief tour of what we built. Each of these gets its own slide next, so I'll go quick. File management is the user-facing core. User management and permissions are the admin-facing access controls. Monitoring and backups are operational features for keeping the system healthy."*

---

## Slide 8 — File Management

### Slide content
- Upload (drag-and-drop or button), download, rename, delete
- Nested folders with breadcrumb navigation
- Per-user upload directories: `/uploads/<user_id>/`
- Storage quota enforced at upload time
- Files stored on host disk via Docker bind mount → survive container rebuilds

> Fix typo on the slide: "File Managment" → "File Management"

### Talking points
*"Files are stored in two places that have to stay in sync: the actual file on disk, and a database row holding metadata (owner, size, parent folder, MIME type). The database is the source of truth — every action goes through it."*

*"**Why per-user upload directories?** Each user's files live under `/uploads/<their_id>/`, so even if two users upload a file with the same name, they don't collide. It also makes per-user backups and quota calculations trivial — just look at one folder."*

*"**Why bind-mount to the host?** The `uploads/` folder lives on our actual computer, mounted into the container. If the container crashes or gets rebuilt, the files are still there. We learned this the hard way — early on we had files inside the container and lost them all on a rebuild."*

*"Folders aren't real folders on disk — they're database rows. This sounds weird but it lets us do things like rename a folder instantly (just update one row) instead of moving every file underneath."*

---

## Slide 9 — User Management

### Slide content
- Two roles: `admin` and `user`
- Self-registration (locks new accounts to `user` role)
- Profile page: change own username/password
- Admin can: create, edit, delete users · set roles · set storage quotas
- bcrypt password hashing
- Login rate limiting: 5 failed attempts / 5 min lockout per IP

### Talking points
*"The role split is intentionally simple — admin and user. Everything role-related goes through `require_admin()` at the top of protected pages, so adding a new admin-only feature is a one-line gate."*

*"**Why bcrypt?** It's slow on purpose. If our database ever leaks, bcrypt's per-hash work factor means an attacker can only test a few thousand passwords per second instead of billions. We never store, log, or transmit plaintext passwords."*

*"**Self-registration was a deliberate choice** — anyone can sign up, but the role is hardcoded to `user`. Admins are only created by other admins, so an attacker can't grant themselves admin via the registration form."*

*"**Rate limiting matters because the site is public** through the tunnel. Without it, someone could try a million passwords against `admin`. With it, they get five tries every five minutes per IP — brute force becomes computationally pointless."*

---

## Slide 10 — Permissions

### Slide content
- Two layers: **page-level** (role) + **per-file** (granular)
- Per-file flags: `can_read`, `can_write`, `can_delete`
- Owner always has full access (implicit, not stored)
- Admins can override anyone's permissions
- Checked on every action — URL tampering doesn't bypass

### Talking points
*"The brief asked for read/write/edit/admin. We went further: every file or folder has its own access list, separate from the user's role."*

*"**Two-layer model.** Layer one is page-level: only admins see the Users page, only admins manage backups. Layer two is per-resource: even within the file manager, user A can give user B read-only access to one folder without sharing anything else. Like Google Drive's share button."*

*"**Why owners aren't in the permissions table.** We could store an explicit row saying 'owner X has full access to file Y' — but that's redundant with the `owner_id` column on `files`. So our permission check is: 'are you the owner OR an admin OR do you have an explicit row?' Three short conditions covering every case."*

*"**Defense in depth.** The UI hides delete buttons users can't click, but we don't trust the UI. Every action handler — upload, rename, delete, download — re-checks permissions server-side. Even if someone crafts a custom URL to delete a file they don't own, the check rejects it."*

---

## Slide 11 — Monitoring & Backups

### Slide content
**Monitor**
- Uptime, load average, CPU, memory
- Disk volume usage (real host disk, not container)
- Active sessions (last 30 min)
- Last automatic backup timestamp
- Per-user storage breakdown · recent uploads feed

**Backup**
- Manual backup button (one-click ZIP)
- Cron-driven schedule via UI calendar/time picker
- ZIP contains DB dump + every uploaded file
- Restore = wipe + reimport (atomic)
- Auto-rotation: keeps last 10 scheduled backups
- Stored in bind-mounted `external_backups/` (survives rebuilds)

### Talking points
*"**Two features that work together**: monitoring tells you when something needs attention, backups give you the option to recover when something breaks. Awareness and insurance."*

*"**Real disk usage, not the container's.** Earlier our disk gauge read the container's root filesystem — which is a Docker overlay layer and means nothing. We pointed it at the actual mount where uploads live, so the percentage reflects the real disk a real admin would care about."*

*"**Source of truth is the database.** 'Files Stored Size' isn't from walking the filesystem — it's a SUM query on the files table. Faster, and it can never disagree with the per-user breakdown shown lower on the page."*

*"**Why one ZIP for backups?** A backup includes the database AND the files. If we backed up only files, we'd lose ownership and permissions. If we backed up only the database, we'd have records pointing at files that no longer exist. Bundling them means restore is one atomic operation — you rewind to that exact moment."*

*"**Auto-rotation prevents disk fill.** Scheduled backups run every hour or whatever the admin sets. If we never deleted old ones, the disk fills and the next backup fails silently. We keep the last 10, delete older ones."*

*"**Why backups live outside the container.** They're bind-mounted to a folder on our host PC. Containers are designed to be disposable — you can rebuild them anytime. If backups lived inside the container, the very thing meant to protect us would die with the container. Putting them outside means a `docker compose down -v` doesn't destroy our recovery point. The backup outlives the thing it's backing up — which is the whole point."*

---

## Slide 12 — Demonstration

> Live demo. See "Demo Script" section below for the order to click through.

---

## Slide 13 — Testing

**75 automated tests (45 unit + 30 end-to-end), all passing.**

### Talking points
*"Two layers of automated testing. Unit tests run inside the container against the live database — they cover schema, CRUD, cascade behavior, and the default admin seed. End-to-end tests run from outside as HTTP requests — they simulate a real user logging in, navigating pages, uploading files, getting blocked from admin pages, and so on. Both suites self-clean: every test inserts and removes its own data, so they can run repeatedly without polluting state. We re-run the full suite before any commit to main."*

---

## Slide 14 — Roadmap

### Slide content
**Short-term**
- Two-factor authentication (TOTP)
- Per-action audit log (who renamed/deleted what)
- File previews (images, PDFs) in browser
- Public share links with expiry

**Medium-term**
- Stable Cloudflare named tunnel (custom subdomain)
- Off-site backup target (Backblaze B2 or Cloudflare R2)
- Mobile-friendly responsive UI

**Long-term**
- Real-time collaboration (websockets)
- File versioning (keep N revisions)
- Multi-server replication

### Talking points
*"What we'd add next, ranked by impact-vs-effort."*

*"**TOTP two-factor** — biggest security win for the smallest effort. The Google Authenticator standard is well-documented, and we already have rate limiting as the foundation."*

*"**Audit log** — right now Apache logs every page hit, but we don't have a clean answer to 'who deleted this folder.' One small `audit_log` table and a helper called from every action handler would close that."*

*"**File previews** — the biggest UX gap. Right now you have to download a file to see it. Inline image and PDF previews are mostly a frontend change."*

*"**Off-site backups** — currently backups live on the same machine as the data, which means a disk failure could lose both. Pushing zips to Backblaze B2 (free 10 GB tier) gives us 3-2-1 backup compliance: 3 copies, 2 different media, 1 offsite."*

*"Long-term we'd look at versioning and replication — both are huge undertakings that real NAS products like Synology took years to build."*

---

## Slide 15 — Thank you

> Open for questions.

---

# Demo Script (for slide 12)

Have these tabs/windows open before starting:
1. Browser tab on `http://localhost:8080` (logged out)
2. Browser tab on the public tunnel URL (logged out)
3. Terminal with `nmap -p 3306,8080 localhost` typed but not run
4. Phone with the tunnel URL ready to refresh (proves it's actually internet-facing)

### Order of clicks
1. **Local login** as admin → land on file manager. Show file list, drag a file to upload, watch it appear.
2. **Create a folder**, navigate into it, upload a file inside.
3. **Open Permissions** for one file → grant a regular user read-only.
4. **Log out, log in as that regular user** → show they can see but not delete the shared file.
5. **Log back in as admin → Monitor page**. Point out: uptime, load average, disk gauge, active sessions list (you should see yourself), last backup timestamp.
6. **Backup page** → click "Create Backup," watch it appear in the list, mention the auto-rotation policy.
7. **Switch to terminal** → run `nmap -p 3306,8080 localhost`. Show 8080 open, 3306 closed. Explain firewall design.
8. **Phone demo** → refresh the tunnel URL, log in. Proves the public URL works from off-network.

Total demo time target: ~4 minutes.

---

# Anticipated Questions

### "What happens if MySQL dies during a backup?"
*"The `mysqldump` command fails with a non-zero exit code, the backup script exits before zipping, and no database row is inserted — so a corrupt backup never appears in the backup list. Worst case the user sees an error, retries, and gets a clean backup."*

### "Can you scale this to 1000 users?"
*"Not without changes. The page-render queries that walk every file would need pagination, and the per-user storage SUM would need an index. But the schema itself scales fine — we'd optimize the read paths, not the data model."*

### "Why didn't you use a real port forward?"
*"Three reasons: it requires router admin access we may not have on a school network, it doesn't work behind CGNAT which many ISPs use, and it exposes our home IP. Cloudflare Tunnel solves the same requirement — public HTTPS access — without those downsides. We have notes on what the textbook port-forward setup would look like if a teacher wants to see we understand the alternative."*

### "How is CPU/memory accurate when running in a container?"
*"They reflect what the server process can see — the same numbers a Linux server would report on bare metal. For our use case (is the NAS overloaded, do we have memory headroom) that's the right scope. A real production NAS would also expose host-level metrics via a sidecar agent, but that's out of scope for this project."*

### "What's stopping someone from uploading a malicious PHP file and executing it?"
*"Two things. First, uploads are stored under `/var/www/uploads/`, which Apache is configured to never execute as PHP. Second, the URL path users see is the database `filename`, not a real filesystem path — Apache never serves the upload path directly. So even a `.php` file in the upload folder is treated as a static download, not code."*

### "What's the difference between Uploads Size and Uploads Volume?"
*"Uploads Size is the total bytes users have stored — a sum of every file's size from the database. Uploads Volume is how full the underlying disk is, including everything else on that drive. You can have 50 MB of uploads on a disk that's 99% full of other things. The two answer different questions: 'how much have we stored' vs 'how much room is left'."*

### "How do you protect against SQL injection?"
*"Every query uses PDO prepared statements — values are bound separately from the query template, so input can never be interpreted as SQL. We have zero string-concatenated queries in the codebase."*

### "What if two users edit the same file at the same time?"
*"We don't currently support concurrent editing — files are uploaded as whole blobs, not edited in place. If two users upload a file with the same name to the same folder, the second one overwrites the first. Real conflict resolution would need versioning, which is on the roadmap."*

---

# Last-Minute Checklist

Before walking into the room:
- [ ] Containers running: `docker compose ps` shows all four healthy
- [ ] Tunnel URL works: open in incognito, log in successfully
- [ ] Default admin password changed (do this even if you change it back after — proves the workflow exists)
- [ ] Manual backup taken so the "Last Auto Backup" card has a recent value
- [ ] At least one regular user account exists for the permission demo
- [ ] At least one shared file with explicit permissions for the demo
- [ ] Terminal commands typed and ready (not yet run)
- [ ] Phone on cellular data, tunnel URL bookmarked
- [ ] Slides on a USB stick as backup in case the demo machine fails
