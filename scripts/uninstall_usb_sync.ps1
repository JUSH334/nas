# uninstall_usb_sync.ps1
# Removes the NAS-USB-Mirror scheduled task.

$taskName = "NAS-USB-Mirror"
$existing = Get-ScheduledTask -TaskName $taskName -ErrorAction SilentlyContinue

if ($existing) {
    Unregister-ScheduledTask -TaskName $taskName -Confirm:$false
    Write-Host "Removed scheduled task '$taskName'." -ForegroundColor Green
} else {
    Write-Host "Task '$taskName' is not installed." -ForegroundColor Yellow
}
