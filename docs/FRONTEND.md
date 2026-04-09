# NAS Web Server — Frontend Documentation

## Overview

The frontend is server-rendered PHP with embedded HTML, CSS, and JavaScript. There is no separate frontend framework — each `.php` file contains the backend logic at the top and the full HTML page below. This is the traditional LAMP stack approach.

## Design System

### Theme

The interface uses a dark theme with the following color palette:

| Variable | Hex | Usage |
|---|---|---|
| `--bg` | `#0d0f14` | Page background |
| `--surface` | `#161920` | Cards, panels, nav bar |
| `--surface2` | `#1d2029` | Hover states, secondary surfaces |
| `--border` | `#2a2d38` | Borders, dividers |
| `--accent` | `#4fffb0` | Primary actions, active states, success |
| `--accent2` | `#00bfff` | Gradients, secondary accent |
| `--text` | `#e8eaf0` | Primary text |
| `--muted` | `#6b7080` | Secondary text, labels |
| `--danger` | `#ff4f6a` | Destructive actions, errors |
| `--warn` | `#ffb84f` | Warnings, caution states |
| `--radius` | `6px` | Border radius for buttons, inputs |

### Typography

| Font | Usage | Source |
|---|---|---|
| DM Sans (300, 400, 500) | Body text, labels, buttons | Google Fonts |
| Space Mono (400, 700) | Logo, monospace values, badges, code | Google Fonts |

### Common Components

**Buttons:**

| Class | Appearance | Usage |
|---|---|---|
| `.btn-primary` | Green background (`--accent`), dark text | Primary actions (Create, Upload, Save) |
| `.btn-secondary` | Dark background with border | Secondary actions (Cancel, New Folder) |
| `.btn-danger` | Red tinted background | Destructive actions (Delete) |
| `.btn-warn` | Orange background | Caution actions (Restore) |
| `.btn-small` | Smaller padding | Inline actions in lists |
| `.btn-link` | Transparent with border | Tertiary actions (Download) |

**Form Inputs:**
- Dark background (`--bg`) with subtle border
- Green glow on focus (`--accent` border + box-shadow)
- Labels are uppercase, small, muted text

**Flash Messages:**
- `.flash.success` — green tinted background with accent border
- `.flash.error` — red tinted background with danger border

**Modals:**
- `.modal-backdrop` — fixed overlay with blur effect
- `.modal` — centered card with fade-up animation
- Closed by clicking backdrop or Cancel button

## Pages

### Login Page (`login.php`)

```
┌──────────────────────────────┐
│                              │
│      ┌──────────────┐        │
│      │  🖴 NAS       │        │
│      │               │        │
│      │  Welcome back │        │
│      │               │        │
│      │  [Username  ] │        │
│      │  [Password  ] │        │
│      │               │        │
│      │  [Sign In →]  │        │
│      │               │        │
│      │  NAS v1.0     │        │
│      └──────────────┘        │
│                              │
│    (animated grid + glow)    │
└──────────────────────────────┘
```

- Centered card layout with animated grid background
- Radial glow orb effect behind the card
- Error messages appear inline above the form
- Preserves username on failed login attempt

### File Manager (`index.php`)

```
┌──────────────────────────────────────────────────────┐
│  NAS   📁 Files  👥 Users  📊 Monitor  ...  [Admin] │
├──────────────────────────────────────────────────────┤
│  ~ root / Documents                                   │
│                                                       │
│  Documents              [＋ New Folder] [↑ Upload]    │
│                                                       │
│  Name              Size      Owner     Modified       │
│  ─────────────────────────────────────────────────    │
│  📁 Photos          —       admin     Apr 9, 2026  ✏️🔒🗑│
│  📄 report.pdf    2.4 MB    admin     Apr 9, 2026  ⬇✏️🔒🗑│
│  🖼️ image.png     340 KB    admin     Apr 9, 2026  ⬇✏️🔒🗑│
│                                                       │
└──────────────────────────────────────────────────────┘
```

**Features:**
- Breadcrumb navigation (clickable path: `~ root / folder / subfolder`)
- Sortable file listing (folders first, then files alphabetically)
- Per-row actions: Download (files only), Rename, Permissions (admin), Delete
- New Folder modal — text input for folder name
- Upload modal — drag-and-drop zone with click-to-browse fallback
- Flash messages for success/error feedback

**Action Icons:**
| Icon | Action | Visibility |
|---|---|---|
| ⬇ | Download | Files only |
| ✏️ | Rename | Owner or admin |
| 🔒 | Permissions | Admin only |
| 🗑 | Delete | Owner or admin |

### User Management (`users.php`) — Admin Only

```
┌──────────────────────────────────────────────────────┐
│  NAS   📁 Files  👥 Users  📊 Monitor  ...          │
├──────────────────────────────────────────────────────┤
│  Users (3)                         [＋ New User]      │
│                                                       │
│  User              Role     Joined        Last Login  │
│  ─────────────────────────────────────────────────    │
│  [AD] admin        ADMIN    Jan 1, 2026   Apr 9      ✏️  │
│       admin@local                                     │
│  [JO] john         USER     Mar 5, 2026   Apr 8      ✏️🗑│
│       john@test                                       │
└──────────────────────────────────────────────────────┘
```

**Features:**
- User table with avatar circles (first two letters of username)
- Role badges: green `ADMIN`, gray `USER`
- Create User modal: username, email (optional), password (min 8 chars), role selector
- Edit User modal: pre-filled fields, optional password change
- Delete: confirmation dialog, cannot delete yourself
- Flash messages for all operations

### System Monitor (`monitor.php`) — Admin Only

```
┌──────────────────────────────────────────────────────┐
│  NAS   📁 Files  👥 Users  📊 Monitor  ...          │
├──────────────────────────────────────────────────────┤
│  System Monitor                                       │
│                                                       │
│  ┌────────┐ ┌────────┐ ┌────────┐ ┌────────┐        │
│  │Total   │ │Files   │ │Uploads │ │Disk    │        │
│  │Users   │ │Stored  │ │Size    │ │Free    │        │
│  │   3    │ │  12    │ │ 4.2 MB │ │ 45 GB  │        │
│  └────────┘ └────────┘ └────────┘ └────────┘        │
│                                                       │
│  ⚡ CPU Usage        23.4%  ████░░░░░░░░             │
│  🧠 Memory           61.2%  ████████░░░░             │
│  💾 Disk Usage        34.1%  █████░░░░░░░            │
│                                                       │
│  ┌─ Recent Uploads ─┐  ┌─ Storage by User ──┐       │
│  │ 📄 report.pdf    │  │ admin    4.2 MB    │       │
│  │ 🖼️ photo.jpg     │  │ john     1.1 MB    │       │
│  └──────────────────┘  └───────────────────┘        │
└──────────────────────────────────────────────────────┘
```

**Features:**
- Stat cards with animated fade-up entrance
- Gauge bars with color coding:
  - Green (`--accent`): 0–59%
  - Orange (`--warn`): 60–84%
  - Red (`--danger`): 85–100%
- Recent uploads panel (last 10 files with icons, size, uploader)
- Per-user storage panel with mini progress bars

### System Logs (`logs.php`) — Admin Only

```
┌──────────────────────────────────────────────────────┐
│  NAS   📁 Files  📊 Monitor  📋 Logs  💾 Backups    │
├──────────────────────────────────────────────────────┤
│  System Logs                                          │
│                                                       │
│  [Apache Access] [Apache Error] [PHP] [Backup] [Sys] │
│                                          Show last [100▼]│
│  ┌──────────────────────────────────────────────┐    │
│  │ Apache Access Log          /var/log/apache2/ │    │
│  │                                              │    │
│  │ 172.18.0.1 - - [09/Apr/2026:21:16:51 +0000] │    │
│  │ "GET /index.php HTTP/1.1" 200 4523           │    │
│  │ 172.18.0.1 - - [09/Apr/2026:21:16:52 +0000] │    │
│  │ "POST /action_upload.php HTTP/1.1" 302 0     │    │
│  │ ...                                          │    │
│  └──────────────────────────────────────────────┘    │
└──────────────────────────────────────────────────────┘
```

**Features:**
- Tab navigation for different log sources
- Configurable line count (50, 100, 250, 500)
- Monospace font for log content (Space Mono)
- Scrollable log panel (max-height: 600px) with auto-scroll to bottom
- Custom scrollbar styling

### Backup & Restore (`backup.php`) — Admin Only

```
┌──────────────────────────────────────────────────────┐
│  NAS   📁 Files  📊 Monitor  📋 Logs  💾 Backups    │
├──────────────────────────────────────────────────────┤
│  Backup & Restore                                     │
│                                                       │
│  ┌─ Create New Backup ──────────── [Create Backup] ┐ │
│  │  Backs up database and uploaded files as ZIP     │ │
│  └──────────────────────────────────────────────────┘ │
│                                                       │
│  ┌─ Automatic Backups ──── [Daily ▼] [Save Schedule]┐│
│  │  Currently: Daily                                ││
│  └──────────────────────────────────────────────────┘│
│                                                       │
│  ┌─ Saved Backups (2) ─────────────────────────────┐ │
│  │ 📦 nas_backup_2026-04-09_21-28-59.zip           │ │
│  │   1.2 MB · Apr 9, 2026 · by admin               │ │
│  │              [Download] [Restore] [Delete]       │ │
│  └──────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────┘
```

**Features:**
- One-click manual backup creation
- Schedule selector (Daily, Weekly, Monthly, Disabled)
- Backup list with file size, timestamp, creator
- Download, Restore, and Delete actions per backup
- Restore confirmation dialog (inline warning bar)
- Delete confirmation (browser confirm dialog)

### Permissions (`permissions.php`) — Admin Only

```
┌──────────────────────────────────────────────────────┐
│  NAS   📁 Files  👥 Users  📊 Monitor  ...          │
├──────────────────────────────────────────────────────┤
│  ← Back to files                                      │
│                                                       │
│  📄 report.pdf                                        │
│  Owner: admin  OWNER                                  │
│                                                       │
│  ┌─ User Permissions (1) ──────────────────────────┐ │
│  │ john       ☑ Read  ☐ Write  ☐ Delete  [Save][X] │ │
│  │────────────────────────────────────────────────  │ │
│  │ [Select user ▼]  ☑ Read ☐ Write ☐ Delete [Add]  │ │
│  └──────────────────────────────────────────────────┘ │
└──────────────────────────────────────────────────────┘
```

**Features:**
- File/folder info header with owner badge
- Per-user permission rows with checkboxes (Read, Write, Delete)
- Save and Remove buttons per user
- Add new user with dropdown (excludes owner and already-assigned users)
- Back link returns to parent folder

## Navigation

The nav bar appears on every authenticated page:

```
[NAS logo]  [Files] [Users*] [Monitor*] [Logs*] [Backups*]  Hello, admin ADMIN [Sign out]
```

*Items marked with `*` are only visible to admin users.*

The active page is highlighted with the accent color (`--accent`).

## JavaScript

JavaScript is minimal and inline — no external libraries or build tools:

| Feature | Page | Purpose |
|---|---|---|
| `openModal(id)` / `closeModal(id)` | index.php, users.php | Toggle modal visibility |
| `openRename(id, name)` | index.php | Pre-fill rename modal |
| `openEdit(user)` | users.php | Pre-fill edit user modal from JSON |
| Drag & drop | index.php | File upload drop zone |
| Backdrop click | index.php, users.php | Close modal on backdrop click |
| Auto-scroll | logs.php | Scroll log panel to bottom |
| Select redirect | logs.php | Change line count via dropdown |
| Confirm dialog | Various | Browser `confirm()` for destructive actions |

## Responsive Design

- Main content area: `max-width: 1100px`, centered
- Monitor gauge grid: `repeat(auto-fill, minmax(300px, 1fr))`
- Monitor bottom panels: 2-column grid, collapses to 1 column at 700px
- Stat cards: `repeat(auto-fill, minmax(180px, 1fr))`
- Toolbar: `flex-wrap: wrap` for small screens

## Animations

| Animation | Usage | Duration |
|---|---|---|
| `fadeUp` | Modal entrance, stat cards | 0.2–0.5s ease |
| Gauge bar fill | Monitor progress bars | 0.8s cubic-bezier |
| Button hover | All buttons | translateY(-1px), 0.1s |
| Input focus | All inputs | Border color + box-shadow, 0.2s |
| Nav link hover | Navigation | Color + background, 0.15s |
