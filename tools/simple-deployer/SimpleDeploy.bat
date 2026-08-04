@echo off
REM SimpleDeploy.bat -- double-click launcher for SimpleDeploy.ps1.
REM Any extra arguments given to this .bat are passed straight through to
REM the PowerShell script (e.g. `SimpleDeploy.bat -Project customer-site`).
chcp 65001 >nul
setlocal
set "SCRIPT_DIR=%~dp0"
powershell.exe -NoProfile -ExecutionPolicy Bypass -File "%SCRIPT_DIR%SimpleDeploy.ps1" %*
set "EXIT_CODE=%ERRORLEVEL%"
echo.
echo (کد خروج: %EXIT_CODE%)
pause
exit /b %EXIT_CODE%
