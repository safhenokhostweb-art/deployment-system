<#
.SYNOPSIS
    Upgrades an already-installed Local Development Publisher Agent to
    the version shipped in THIS package — copies agent.php +
    heartbeat-watchdog.php + lib/*.php only, never touches config.php,
    then restarts the main Task Scheduler task. Also creates the
    DeploymentLocalAgentWatchdog task if this installation predates it
    (never re-runs Hub registration — this is upgrade-only, install.ps1
    owns first-time setup).

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
$WatchdogTaskName = 'DeploymentLocalAgentWatchdog'
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

# config.php is deliberately never touched — only agent.php +
# heartbeat-watchdog.php + lib/*.php.
Copy-Item -Path $newAgentPhp -Destination $agentDir -Force
Copy-Item -Path (Join-Path $PackageDir 'agent\heartbeat-watchdog.php') -Destination $agentDir -Force
Copy-Item -Path (Join-Path $PackageDir 'agent\lib\*.php') -Destination (Join-Path $agentDir 'lib') -Force
Write-Host 'فایل‌های agent.php، heartbeat-watchdog.php و lib/*.php بروزرسانی شدند — config.php دست‌نخورده ماند.'

$task = schtasks /Query /TN $TaskName 2>$null
if ($LASTEXITCODE -eq 0) {
    schtasks /End /TN $TaskName 2>$null | Out-Null
    schtasks /Run /TN $TaskName | Out-Null
    Write-Host "Task «$TaskName» دوباره اجرا شد."
} else {
    Write-Host 'هشدار: Task Scheduler برای این Agent پیدا نشد — دستی «php agent.php» را اجرا کنید.' -ForegroundColor Yellow
}

$watchdogTask = schtasks /Query /TN $WatchdogTaskName 2>$null
if ($LASTEXITCODE -eq 0) {
    schtasks /End /TN $WatchdogTaskName 2>$null | Out-Null
    schtasks /Run /TN $WatchdogTaskName | Out-Null
    Write-Host "Task «$WatchdogTaskName» دوباره اجرا شد."
} else {
    # Root-cause fix: an installation from before the Watchdog existed
    # (exactly this project's own real installed agent, at the time this
    # comment was written) must end up with it after an upgrade — not
    # just a warning telling the operator to re-run install.ps1, which
    # would also needlessly re-run Hub registration on an
    # already-registered, already-approved agent. Same XML-render +
    # schtasks /Create as install.ps1's own Watchdog setup.
    Write-Host "Task «$WatchdogTaskName» پیدا نشد — این نصب از قبل از افزوده‌شدن Watchdog است؛ اکنون ثبت می‌شود ..."

    $watchdogTemplatePath = Join-Path $PackageDir 'config\windows-task-watchdog.xml.example'
    $watchdogPhp = Join-Path $agentDir 'heartbeat-watchdog.php'
    $currentUser = "$env:USERDOMAIN\$env:USERNAME"

    $foundPhp = Get-Command php.exe -ErrorAction SilentlyContinue
    $phpPath = if ($foundPhp) { $foundPhp.Source } else { $null }

    if (-not $phpPath) {
        Write-Host 'هشدار: php.exe در PATH پیدا نشد — Watchdog ثبت نشد. آن را دستی با install.ps1 یا install\install-windows-task.ps1 نصب کنید.' -ForegroundColor Yellow
    } else {
        $watchdogXml = Get-Content -Path $watchdogTemplatePath -Raw -Encoding UTF8
        $watchdogXml = $watchdogXml.Replace('CHANGE_ME_WINDOWS_USER', $currentUser)
        $watchdogXml = $watchdogXml.Replace('CHANGE_ME_PHP_EXE', $phpPath)
        $watchdogXml = $watchdogXml.Replace('CHANGE_ME_WATCHDOG_PHP', $watchdogPhp)
        $watchdogXml = $watchdogXml.Replace('CHANGE_ME_LOCAL_AGENT_DIR', $agentDir)
        $watchdogXml = $watchdogXml.Replace('encoding="UTF-8"', 'encoding="UTF-16"')

        $watchdogRenderedPath = Join-Path $env:TEMP 'deployment-local-agent-watchdog-task.xml'
        [System.IO.File]::WriteAllText($watchdogRenderedPath, $watchdogXml, [System.Text.Encoding]::Unicode)

        schtasks /Create /TN $WatchdogTaskName /XML $watchdogRenderedPath /F | Out-Null
        $watchdogCreateExitCode = $LASTEXITCODE
        Remove-Item $watchdogRenderedPath -ErrorAction SilentlyContinue

        if ($watchdogCreateExitCode -ne 0) {
            Write-Host 'هشدار: ثبت Task Scheduler برای Watchdog ناموفق بود.' -ForegroundColor Yellow
        } else {
            Write-Host "Task «$WatchdogTaskName» ثبت و اجرا شد."
            schtasks /Run /TN $WatchdogTaskName | Out-Null
        }
    }
}

Write-Host ''
Write-Host '=============================================='
Write-Host "بروزرسانی از v$oldVersion به v$newVersion کامل شد."
Write-Host '=============================================='
