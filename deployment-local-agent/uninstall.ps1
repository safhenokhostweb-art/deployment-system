<#
.SYNOPSIS
    Uninstalls the Local Development Publisher Agent's Task Scheduler
    task. Non-destructive by default — files (including config.php) are
    kept unless -RemoveFiles is passed.

.PARAMETER InstallDir
    Defaults to C:\ProgramData\DeploymentLocalAgent.

.PARAMETER RemoveFiles
    Also deletes the install directory (including config.php and its
    credentials). Off by default.

.EXAMPLE
    .\uninstall.ps1
    .\uninstall.ps1 -RemoveFiles
#>
param(
    [string]$InstallDir = 'C:\ProgramData\DeploymentLocalAgent',
    [switch]$RemoveFiles
)

$ErrorActionPreference = 'Stop'
$TaskName = 'DeploymentLocalAgent'

Write-Host '=== حذف Local Development Publisher Agent ==='
Write-Host ''

$task = schtasks /Query /TN $TaskName 2>$null
if ($LASTEXITCODE -eq 0) {
    schtasks /End /TN $TaskName 2>$null | Out-Null
    schtasks /Delete /TN $TaskName /F | Out-Null
    Write-Host "Task «$TaskName» متوقف و حذف شد."
} else {
    Write-Host "Task «$TaskName» پیدا نشد — احتمالاً قبلاً حذف شده."
}

if ($RemoveFiles) {
    if (Test-Path $InstallDir) {
        Remove-Item -Path $InstallDir -Recurse -Force
        Write-Host "پوشه نصب حذف شد: $InstallDir"
    }
} else {
    Write-Host ''
    Write-Host "فایل‌های نصب (شامل config.php) در $InstallDir دست‌نخورده ماندند."
    Write-Host 'برای حذف کامل آن‌ها هم: .\uninstall.ps1 -RemoveFiles'
}

Write-Host ''
Write-Host 'توجه: این کار فقط سرویس محلی را متوقف می‌کند — اعتبارنامه این Agent در پنل هنوز فعال است.'
Write-Host 'برای غیرفعال کردن کامل، از پنل هم به Local Agents بروید و این Agent را Disable کنید.'
Write-Host '=============================================='
