<#
.SYNOPSIS
    Upgrades an already-installed Local Development Publisher Agent to
    the version shipped in THIS package — copies agent.php + lib/*.php
    only, never touches config.php, then restarts the Task Scheduler
    task.

.PARAMETER InstallDir
    Defaults to C:\ProgramData\DeploymentLocalAgent.

.EXAMPLE
    .\upgrade.ps1
#>
param(
    [string]$InstallDir = 'C:\ProgramData\DeploymentLocalAgent'
)

$ErrorActionPreference = 'Stop'
$PackageDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$TaskName = 'DeploymentLocalAgent'
$agentDir = Join-Path $InstallDir 'agent'

Write-Host '=== بروزرسانی Local Development Publisher Agent ==='
Write-Host ''

if (-not (Test-Path (Join-Path $agentDir 'config.php'))) {
    Write-Error "config.php در $agentDir پیدا نشد — این مسیر یک نصب موجود به نظر نمی‌رسد. ابتدا install.ps1 را اجرا کنید."
    exit 1
}

$oldVersion = 'unknown'
$oldAgentPhp = Join-Path $agentDir 'agent.php'
if (Test-Path $oldAgentPhp) {
    $match = Select-String -Path $oldAgentPhp -Pattern "LOCAL_AGENT_VERSION = '([^']+)'" | Select-Object -First 1
    if ($match) {
        $oldVersion = $match.Matches[0].Groups[1].Value
    }
}

$newVersion = 'unknown'
$newAgentPhp = Join-Path $PackageDir 'agent\agent.php'
$match = Select-String -Path $newAgentPhp -Pattern "LOCAL_AGENT_VERSION = '([^']+)'" | Select-Object -First 1
if ($match) {
    $newVersion = $match.Matches[0].Groups[1].Value
}

Write-Host "نسخه فعلی: $oldVersion"
Write-Host "نسخه این بسته: $newVersion"
Write-Host ''

# config.php is deliberately never touched — only agent.php + lib/*.php.
Copy-Item -Path $newAgentPhp -Destination $agentDir -Force
Copy-Item -Path (Join-Path $PackageDir 'agent\lib\*.php') -Destination (Join-Path $agentDir 'lib') -Force
Write-Host 'فایل‌های agent.php و lib/*.php بروزرسانی شدند — config.php دست‌نخورده ماند.'

$task = schtasks /Query /TN $TaskName 2>$null
if ($LASTEXITCODE -eq 0) {
    schtasks /End /TN $TaskName 2>$null | Out-Null
    schtasks /Run /TN $TaskName | Out-Null
    Write-Host "Task «$TaskName» دوباره اجرا شد."
} else {
    Write-Host 'هشدار: Task Scheduler برای این Agent پیدا نشد — دستی «php agent.php» را اجرا کنید.' -ForegroundColor Yellow
}

Write-Host ''
Write-Host '=============================================='
Write-Host "بروزرسانی از v$oldVersion به v$newVersion کامل شد."
Write-Host '=============================================='
