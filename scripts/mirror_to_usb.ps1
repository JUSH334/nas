# mirror_to_usb.ps1
# Host-side scheduled task: mirrors NAS backups from ./external_backups/ to a
# secondary destination (USB drive, network share, etc.). Runs on a schedule
# via Task Scheduler — not from inside Docker — so it works regardless of
# WSL2 / Docker Desktop drive-sharing limitations.
#
# After each run it writes a heartbeat file (.usb_sync_status) inside the
# source folder so the NAS web UI knows whether mirroring is currently live.

param(
    [string]$Source = "C:\Users\owner\NAS\external_backups",
    [string]$Target = "D:\nas-backups"
)

$ErrorActionPreference = "Continue"
$heartbeatFile = Join-Path $Source ".usb_sync_status"
$timestamp     = Get-Date -Format "yyyy-MM-dd HH:mm:ss"

# Skip silently if target drive isn't available (USB unplugged)
$targetRoot = [System.IO.Path]::GetPathRoot($Target)
if (-not (Test-Path $targetRoot)) {
    Write-Output "$timestamp SKIP: target drive $targetRoot not available (USB unplugged?)"
    exit 0
}

# Make sure target folder exists
if (-not (Test-Path $Target)) {
    New-Item -ItemType Directory -Path $Target -Force | Out-Null
}

# Mirror with robocopy:
#   /MIR  — mirror tree (deletes target files no longer in source)
#   /R:2  — retry twice on failure
#   /W:5  — wait 5s between retries
#   /NP   — no per-file progress (cleaner log)
#   /NDL  — no directory listing (cleaner log)
#   /NJH  /NJS — no header / summary
$robocopyOutput = robocopy $Source $Target *.zip /MIR /R:2 /W:5 /NP /NDL /NJH /NJS 2>&1
$rc = $LASTEXITCODE

# Robocopy exit codes 0–7 are success-ish, 8+ is real failure
if ($rc -ge 8) {
    Write-Output "$timestamp ERROR: robocopy failed (code $rc)"
    Write-Output $robocopyOutput
    exit 1
}

# Count zips at target after sync
$zipCount = (Get-ChildItem $Target -Filter *.zip -ErrorAction SilentlyContinue | Measure-Object).Count

# Write heartbeat file the container can read
$heartbeat = @{
    last_sync       = $timestamp
    files_mirrored  = $zipCount
    target_path     = $Target
    robocopy_code   = $rc
} | ConvertTo-Json -Compress
Set-Content -Path $heartbeatFile -Value $heartbeat -Encoding ASCII

Write-Output "$timestamp OK: mirrored $zipCount zip(s) to $Target (robocopy code $rc)"
