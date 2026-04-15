# install_usb_sync.ps1
# Registers a Windows scheduled task that runs the watcher continuously,
# starting at user logon and restarting it if it dies. The watcher polls
# every 3 seconds, so backups appear on the USB within ~3s of being created.
#
# Run once. Survives reboots. To remove later: uninstall_usb_sync.ps1.

$taskName     = "NAS-USB-Mirror"
$launcherPath = Join-Path $PSScriptRoot "mirror_watcher_launcher.vbs"
$scriptPath   = Join-Path $PSScriptRoot "mirror_watcher.ps1"

if (-not (Test-Path $launcherPath) -or -not (Test-Path $scriptPath)) {
    Write-Error "Cannot find launcher or watcher script in $PSScriptRoot"
    exit 1
}

# Remove the old polling task (or any previous version) cleanly
$existing = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
if ($existing) {
    Write-Host "Removing existing task '$taskName'..."
    Stop-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
}

# Also kill any orphan watcher processes from a previous run
Get-WmiObject Win32_Process -Filter "Name='powershell.exe'" |
    Where-Object { $_.CommandLine -and $_.CommandLine -like "*mirror_watcher.ps1*" } |
    ForEach-Object {
        Write-Host "Stopping orphan watcher PID $($_.ProcessId)"
        Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue
    }

$action = New-ScheduledTaskAction `
    -Execute "wscript.exe" `
    -Argument "`"$launcherPath`""

$trigger = New-ScheduledTaskTrigger -AtLogOn -User $env:USERNAME

$settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -ExecutionTimeLimit ([System.TimeSpan]::Zero) `
    -RestartCount 3 `
    -RestartInterval (New-TimeSpan -Minutes 1)

Register-ScheduledTask `
    -TaskName $taskName `
    -Action $action `
    -Trigger $trigger `
    -Settings $settings `
    -Description "Continuously mirrors NAS backups to the USB drive (within ~3s of creation)" `
    -Force | Out-Null

Write-Host ""
Write-Host "Installed scheduled task '$taskName'." -ForegroundColor Green
Write-Host "  Trigger: at user logon"
Write-Host "  Action:  runs mirror_watcher.ps1 continuously"
Write-Host "  Polling: every 3 seconds"
Write-Host ""
Write-Host "Starting watcher now..."
Start-ScheduledTask -TaskName $taskName
Start-Sleep -Seconds 4

# Confirm the watcher is actually running
$running = Get-WmiObject Win32_Process -Filter "Name='powershell.exe'" |
    Where-Object { $_.CommandLine -and $_.CommandLine -like "*mirror_watcher.ps1*" }

if ($running) {
    Write-Host "Watcher is running (PID $($running.ProcessId))." -ForegroundColor Green
    Write-Host "Backups created in external_backups\ will appear on the USB within ~3 seconds."
} else {
    Write-Host "Watcher did not start - check Task Scheduler History for the task." -ForegroundColor Yellow
}
