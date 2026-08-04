<#
.SYNOPSIS
    Removes the Desktop shortcut created by Install-Shortcut.ps1, if
    present. Never touches anything else on the Desktop.
#>

[CmdletBinding()]
param()

Set-StrictMode -Version Latest
try { [Console]::OutputEncoding = [System.Text.Encoding]::UTF8 } catch {}

$desktopPath = [System.Environment]::GetFolderPath('Desktop')
$shortcutPath = Join-Path $desktopPath 'SimpleDeploy.lnk'

if (Test-Path -LiteralPath $shortcutPath) {
    Remove-Item -LiteralPath $shortcutPath -Force
    Write-Host "Shortcut حذف شد: $shortcutPath"
} else {
    Write-Host 'هیچ Shortcutای برای حذف پیدا نشد.'
}
