<#
.SYNOPSIS
    Creates a Desktop shortcut named "SimpleDeploy" that launches
    SimpleDeployLauncher.bat with the correct Working Directory — no
    third-party tool involved, just the standard WScript.Shell COM object
    already built into every Windows install.
#>

[CmdletBinding()]
param()

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch {}

$ScriptRoot = $PSScriptRoot
$batPath = Join-Path $ScriptRoot 'SimpleDeployLauncher.bat'
if (-not (Test-Path -LiteralPath $batPath)) {
    throw "SimpleDeployLauncher.bat پیدا نشد: $batPath"
}

$desktopPath = [System.Environment]::GetFolderPath('Desktop')
$shortcutPath = Join-Path $desktopPath 'SimpleDeploy.lnk'

$shell = New-Object -ComObject WScript.Shell
$shortcut = $shell.CreateShortcut($shortcutPath)
$shortcut.TargetPath = $batPath
$shortcut.WorkingDirectory = $ScriptRoot
$shortcut.Description = 'SimpleDeploy — ارسال تغییرات لوکال به GitHub و سرور'
$shortcut.WindowStyle = 7  # Minimized — the .bat itself hides the console; this avoids a brief flash of a normal window during that handoff.
# A generic, already-on-every-Windows-install icon (shell32.dll) — no
# download, no embedded third-party asset. Index 46 is a standard
# "send/upload" style icon in the shared Windows icon set.
$shell32Path = Join-Path $env:SystemRoot 'System32\shell32.dll'
if (Test-Path -LiteralPath $shell32Path) {
    $shortcut.IconLocation = "$shell32Path,46"
}
$shortcut.Save()

Write-Host "Shortcut ساخته شد: $shortcutPath"
Write-Host "Target: $batPath"
Write-Host "Working Directory: $ScriptRoot"
