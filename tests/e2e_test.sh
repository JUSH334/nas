#!/bin/bash
# End-to-End Tests for NAS Web Server
# Tests the live web server via HTTP requests using curl
# Run: bash tests/e2e_test.sh

BASE="http://localhost:8080"
COOKIE_JAR="/tmp/nas_e2e_cookies.txt"
PASSED=0
FAILED=0
ERRORS=()

pass() {
    PASSED=$((PASSED + 1))
    echo "  PASS: $1"
}

fail() {
    FAILED=$((FAILED + 1))
    ERRORS+=("$1")
    echo "  FAIL: $1"
}

check_status() {
    local name="$1"
    local actual="$2"
    local expected="$3"
    if [ "$actual" = "$expected" ]; then
        pass "$name"
    else
        fail "$name (got $actual, expected $expected)"
    fi
}

check_contains() {
    local name="$1"
    local body="$2"
    local pattern="$3"
    if echo "$body" | grep -qi "$pattern"; then
        pass "$name"
    else
        fail "$name"
    fi
}

# Clean cookies
rm -f "$COOKIE_JAR"

echo ""
echo "=== Page Availability Tests ==="

RESP=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/login.php")
check_status "Login page returns 200" "$RESP" "200"

RESP=$(curl -s -o /dev/null -w "%{http_code}" -L "$BASE/index.php" -c "$COOKIE_JAR")
check_status "Unauthenticated index.php redirects to login" "$RESP" "200"

BODY=$(curl -s -L "$BASE/index.php" -b "$COOKIE_JAR")
check_contains "Redirect lands on login page" "$BODY" "password"

echo ""
echo "=== Authentication Tests ==="

BODY=$(curl -s -X POST "$BASE/login.php" \
    -d "username=admin&password=wrongpassword" \
    -c "$COOKIE_JAR" -b "$COOKIE_JAR")
check_contains "Wrong password shows error" "$BODY" "invalid\|incorrect"

rm -f "$COOKIE_JAR"
RESP=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/login.php" \
    -d "username=admin&password=admin123" \
    -c "$COOKIE_JAR" -b "$COOKIE_JAR" -L)
check_status "Correct login returns 200 (after redirect)" "$RESP" "200"

BODY=$(curl -s "$BASE/index.php" -b "$COOKIE_JAR")
check_contains "Authenticated index shows file manager" "$BODY" "Files\|Upload"
check_contains "Nav shows admin badge" "$BODY" "ADMIN"

echo ""
echo "=== Admin Page Access Tests ==="

RESP=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/users.php" -b "$COOKIE_JAR")
check_status "Users page returns 200 for admin" "$RESP" "200"

BODY=$(curl -s "$BASE/users.php" -b "$COOKIE_JAR")
check_contains "Users page shows user table" "$BODY" "user-table\|Users"

RESP=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/monitor.php" -b "$COOKIE_JAR")
check_status "Monitor page returns 200 for admin" "$RESP" "200"

BODY=$(curl -s "$BASE/monitor.php" -b "$COOKIE_JAR")
check_contains "Monitor page shows system stats" "$BODY" "CPU\|Disk\|Memory"

RESP=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/backup.php" -b "$COOKIE_JAR")
check_status "Backup page returns 200 for admin" "$RESP" "200"

BODY=$(curl -s "$BASE/backup.php" -b "$COOKIE_JAR")
check_contains "Backup page shows create option" "$BODY" "Create.*Backup\|backup"

RESP=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/logs.php" -b "$COOKIE_JAR")
check_status "Logs page returns 200 for admin" "$RESP" "200"

BODY=$(curl -s "$BASE/logs.php" -b "$COOKIE_JAR")
check_contains "Logs page shows log tabs" "$BODY" "Apache\|Access\|Error"

echo ""
echo "=== Folder Management Tests ==="

RESP=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/action_folder.php" \
    -d "foldername=E2E_Test_Folder&parent_id=" \
    -b "$COOKIE_JAR" -c "$COOKIE_JAR" -L)
check_status "Create folder returns 200" "$RESP" "200"

BODY=$(curl -s "$BASE/index.php" -b "$COOKIE_JAR")
check_contains "New folder appears in file listing" "$BODY" "E2E_Test_Folder"

echo ""
echo "=== File Upload Tests ==="

echo "Hello NAS E2E Test" > /tmp/nas_e2e_test_file.txt
RESP=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/action_upload.php" \
    -F "file=@/tmp/nas_e2e_test_file.txt" \
    -F "folder_id=" \
    -b "$COOKIE_JAR" -c "$COOKIE_JAR" -L)
check_status "File upload returns 200" "$RESP" "200"

BODY=$(curl -s "$BASE/index.php" -b "$COOKIE_JAR")
check_contains "Uploaded file appears in listing" "$BODY" "nas_e2e_test_file"

echo ""
echo "=== User Management Tests ==="

RESP=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/action_user_create.php" \
    -d "username=e2e_testuser&password=testpass123&email=e2e@test.com&role=user" \
    -b "$COOKIE_JAR" -c "$COOKIE_JAR" -L)
check_status "Create user returns 200" "$RESP" "200"

BODY=$(curl -s "$BASE/users.php" -b "$COOKIE_JAR")
check_contains "New user appears in user list" "$BODY" "e2e_testuser"

# Test new user can log in
E2E_COOKIE="/tmp/nas_e2e_user_cookies.txt"
rm -f "$E2E_COOKIE"
curl -s -o /dev/null -X POST "$BASE/login.php" \
    -d "username=e2e_testuser&password=testpass123" \
    -c "$E2E_COOKIE" -b "$E2E_COOKIE" -L

BODY=$(curl -s "$BASE/index.php" -b "$E2E_COOKIE")
check_contains "New user can log in and see file manager" "$BODY" "e2e_testuser"

# Regular user should NOT access admin pages
RESP=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/users.php" -b "$E2E_COOKIE")
check_status "Regular user blocked from users page (403)" "$RESP" "403"

RESP=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/backup.php" -b "$E2E_COOKIE")
check_status "Regular user blocked from backup page (403)" "$RESP" "403"

rm -f "$E2E_COOKIE"

echo ""
echo "=== Backup Tests ==="

RESP=$(curl -s -o /dev/null -w "%{http_code}" -X POST "$BASE/backup.php" \
    -d "action=create" \
    -b "$COOKIE_JAR" -c "$COOKIE_JAR" -L)
check_status "Create backup returns 200" "$RESP" "200"

BODY=$(curl -s "$BASE/backup.php" -b "$COOKIE_JAR")
check_contains "Backup appears in backup list" "$BODY" "nas_backup_"

echo ""
echo "=== Cleanup ==="

# Delete test user
E2E_USER_ID=$(curl -s "$BASE/users.php" -b "$COOKIE_JAR" | grep -o 'action_user_delete.php?id=[0-9]*' | grep -o '[0-9]*' | tail -1)
if [ -n "$E2E_USER_ID" ]; then
    curl -s -o /dev/null "$BASE/action_user_delete.php?id=$E2E_USER_ID" -b "$COOKIE_JAR" -c "$COOKIE_JAR" -L
    pass "Cleanup: test user deleted"
fi

# Delete test files via DB
MSYS_NO_PATHCONV=1 docker exec nas-db mysql -u nas_user -pchangeme_user nas_db -e "DELETE FROM files WHERE filename = 'nas_e2e_test_file.txt';" 2>/dev/null
MSYS_NO_PATHCONV=1 docker exec nas-db mysql -u nas_user -pchangeme_user nas_db -e "DELETE FROM files WHERE filename = 'E2E_Test_Folder';" 2>/dev/null
pass "Cleanup: test files removed"

echo ""
echo "=== Logout Test ==="

RESP=$(curl -s -o /dev/null -w "%{http_code}" "$BASE/logout.php" -b "$COOKIE_JAR" -c "$COOKIE_JAR" -L)
check_status "Logout redirects successfully" "$RESP" "200"

BODY=$(curl -s "$BASE/index.php" -b "$COOKIE_JAR" -L)
check_contains "After logout, redirected to login" "$BODY" "password"

# Cleanup
rm -f "$COOKIE_JAR" /tmp/nas_e2e_test_file.txt

echo ""
echo "=================================================="
echo "E2E TESTS COMPLETE: $PASSED passed, $FAILED failed"
if [ ${#ERRORS[@]} -gt 0 ]; then
    echo "Failed tests:"
    for e in "${ERRORS[@]}"; do
        echo "  - $e"
    done
fi
echo "=================================================="
echo ""

exit $FAILED
