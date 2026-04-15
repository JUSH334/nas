' mirror_watcher_launcher.vbs
' Tiny wrapper that launches the PowerShell watcher with a fully hidden window.
' PowerShell's own -WindowStyle Hidden flickers a console briefly when started
' from Task Scheduler. Running PowerShell via wscript with WindowStyle 0
' suppresses the window completely.
Set ws = CreateObject("WScript.Shell")
scriptDir = CreateObject("Scripting.FileSystemObject").GetParentFolderName(WScript.ScriptFullName)
psCmd = "powershell.exe -NoProfile -ExecutionPolicy Bypass -File """ & scriptDir & "\mirror_watcher.ps1"""
ws.Run psCmd, 0, False
