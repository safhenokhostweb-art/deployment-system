@echo off
setlocal
set SCRIPT_DIR=%~dp0
start "" /D "%SCRIPT_DIR%" powershell.exe -NoProfile -ExecutionPolicy Bypass -WindowStyle Hidden -File "%SCRIPT_DIR%SimpleDeployLauncher.ps1"
