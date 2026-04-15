Get-WmiObject Win32_Process -Filter "Name='powershell.exe'" |
    Where-Object { $_.CommandLine -and $_.CommandLine -like '*mirror_watcher*' } |
    Select-Object ProcessId, ParentProcessId, CommandLine | Format-List
