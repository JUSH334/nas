# NAS Web Server — Testing Documentation

## Overview

The NAS web server includes two test suites:

1. **Unit Tests** — PHP-based tests that run inside the Docker container, validating database schema, CRUD operations, and core logic.
2. **End-to-End (E2E) Tests** — Shell-based tests that run externally via HTTP requests (curl), validating the full user-facing application flow.

---

## Prerequisites

- Docker Desktop installed and running
- Containers running: `docker compose up -d --build`
- Bash shell available (Git Bash on Windows)

---

## Unit Tests

**File:** `tests/unit_test.php`

### What They Test

| Category | Tests | Description |
|---|---|---|
| Database Connection | 1 | PDO connects to MySQL successfully |
| Schema Validation | 14 | All tables exist (`users`, `files`, `permissions`, `backups`) with correct columns |
| Default Admin | 4 | Admin user exists, has correct role, password hash matches `admin123`, email set |
| Password Hashing | 2 | `password_hash` and `password_verify` work correctly; wrong passwords rejected |
| User CRUD | 4 | Create, read, and update user accounts |
| File/Folder CRUD | 4 | Create folders, create files inside folders, verify parent-child relationships, rename files |
| Permissions | 6 | Assign, read, update per-file permissions; duplicate permission constraint enforced |
| Cascade Deletes | 2 | Deleting a folder cascades to child files and their permissions |
| Backups Table | 2 | Create and read backup records |
| Cleanup | 1 | Test data removed after run |

**Total: 45 tests**

### How to Run

```bash
# Copy the test file into the container and execute
docker cp tests/unit_test.php nas-web:/var/www/tests_unit.php
docker exec nas-web php /var/www/tests_unit.php
```

> **Note on Windows:** If `docker exec` translates paths incorrectly, prefix with `MSYS_NO_PATHCONV=1`:
> ```bash
> MSYS_NO_PATHCONV=1 docker cp tests/unit_test.php nas-web:/var/www/tests_unit.php
> MSYS_NO_PATHCONV=1 docker exec nas-web php /var/www/tests_unit.php
> ```

### Expected Output

```
=== Database Connection Tests ===
  PASS: PDO connection established

=== Schema Tests ===
  PASS: Table 'users' exists
  PASS: Table 'files' exists
  ...

==================================================
UNIT TESTS COMPLETE: 45 passed, 0 failed
==================================================
```

### Cleanup

The unit tests create and delete their own test data (test users, files, folders, permissions, backups). No manual cleanup is needed.

---

## End-to-End (E2E) Tests

**File:** `tests/e2e_test.sh`

### What They Test

| Category | Tests | Description |
|---|---|---|
| Page Availability | 3 | Login page loads; unauthenticated users redirected to login |
| Authentication | 4 | Wrong password rejected; correct password logs in; session shows username and admin badge |
| Admin Page Access | 8 | Users, Monitor, Backup, and Logs pages return 200 for admin and display expected content |
| Folder Management | 2 | Create a folder; verify it appears in the file listing |
| File Upload | 2 | Upload a file; verify it appears in the file listing |
| User Management | 5 | Create a user; verify in user list; new user can log in; regular user blocked from admin pages (403) |
| Backup | 2 | Create a backup; verify it appears in the backup list |
| Logout | 2 | Logout succeeds; after logout, user is redirected to login |
| Cleanup | 2 | Test user and test files removed |

**Total: 30 tests**

### How to Run

```bash
bash tests/e2e_test.sh
```

### Expected Output

```
=== Page Availability Tests ===
  PASS: Login page returns 200
  PASS: Unauthenticated index.php redirects to login
  PASS: Redirect lands on login page

=== Authentication Tests ===
  PASS: Wrong password shows error
  PASS: Correct login returns 200 (after redirect)
  ...

==================================================
E2E TESTS COMPLETE: 30 passed, 0 failed
==================================================
```

### What the E2E Tests Do Step-by-Step

1. Verify the login page is accessible
2. Verify unauthenticated access to `index.php` redirects to login
3. Attempt login with wrong password — confirm error message shown
4. Login as `admin` / `admin123` — confirm redirect to file manager
5. Visit each admin page (Users, Monitor, Backups, Logs) — confirm 200 status and expected content
6. Create a folder "E2E_Test_Folder" — confirm it appears in the listing
7. Upload a test file — confirm it appears in the listing
8. Create a new user "e2e_testuser" — confirm it appears in the user list
9. Login as the new user — confirm access to file manager
10. Verify the new user gets 403 on admin-only pages (Users, Backups)
11. Create a backup — confirm it appears in the backup list
12. Clean up test data (delete test user and files)
13. Logout — confirm redirect back to login

### Cleanup

The E2E tests clean up after themselves:
- Test user (`e2e_testuser`) is deleted via the admin interface
- Test files and folders are deleted via direct database commands
- Cookie files are removed from `/tmp`

---

## Troubleshooting

| Issue | Solution |
|---|---|
| `docker exec` path errors on Windows | Prefix commands with `MSYS_NO_PATHCONV=1` |
| Database connection failed | Wait 10-15 seconds after `docker compose up` for MySQL to initialize |
| Unit tests fail on schema | Run `docker compose down -v && docker compose up -d --build` to reset the database |
| E2E tests fail on backup | Ensure the backup directory has correct permissions: `docker exec nas-web chown www-data:www-data /var/www/backups` |
| Logs page times out | Apache logs in Docker are symlinked to stdout/stderr — this is handled in the code but may need a container rebuild |

---

## Running All Tests

To run both test suites in sequence:

```bash
# Unit tests
MSYS_NO_PATHCONV=1 docker cp tests/unit_test.php nas-web:/var/www/tests_unit.php
MSYS_NO_PATHCONV=1 docker exec nas-web php /var/www/tests_unit.php

# E2E tests
bash tests/e2e_test.sh
```

Both suites return exit code `0` on success and `1` (or the number of failures) on failure.
