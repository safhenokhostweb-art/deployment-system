<#
.SYNOPSIS
    Registers the Local Development Publisher Agent as a Windows Task
    Scheduler task that starts at login and restarts on failure — see
    spec section 8, "Windows Login -> Local Agent Start -> Heartbeat".

.DESCRIPTION
    Must be run once, by the developer, in an elevated PowerShell prompt
    (Run as Administrator). Does not require config.php to already exist,
    but agent.php will exit immediately (and Task Scheduler will keep
    restarting it, per RestartOnFailure) until `php agent.php install
    --hub=... --id=... --token=...` has been run and the Hub-approved
    agent_token/signing_secret pasted into config.php — same order of
    operations as install.sh on the production Server Agent.

.PARAMETER PhpPath
    Absolute path to php.exe. Auto-detected from PATH if omitted.

.EXAMPLE
    .\install-windows-task.ps1
    .\install-windows-task.ps1 -PhpPath "C:\laragon\bin\php\php-8.4\php.exe"
#>
param(
    [string]$PhpPath
)

$ErrorActionPreference = 'Stop'

$InstallDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$LocalAgentDir = Split-Path -Parent $InstallDir
$AgentPhp = Join-Path $LocalAgentDir 'agent.php'
$TaskName = 'LocalDevelopmentPublisherAgent'

if (-not (Test-Path $AgentPhp)) {
    Write-Error "agent.php پیدا نشد: $AgentPhp"
    exit 1
}

if (-not $PhpPath) {
    $found = Get-Command php.exe -ErrorAction SilentlyContinue
    if (-not $found) {
        Write-Error 'php.exe در PATH پیدا نشد. مسیر آن را با -PhpPath مشخص کنید، مثال: .\install-windows-task.ps1 -PhpPath "C:\laragon\bin\php\php-8.4\php.exe"'
        exit 1
    }
    $PhpPath = $found.Source
}

if (-not (Test-Path $PhpPath)) {
    Write-Error "php.exe در مسیر مشخص‌شده پیدا نشد: $PhpPath"
    exit 1
}

$templatePath = Join-Path $InstallDir 'windows-task.xml.example'
if (-not (Test-Path $templatePath)) {
    Write-Error "الگوی windows-task.xml.example پیدا نشد: $templatePath"
    exit 1
}

$currentUser = "$env:USERDOMAIN\$env:USERNAME"

$xml = Get-Content -Path $templatePath -Raw -Encoding UTF8
$xml = $xml.Replace('CHANGE_ME_WINDOWS_USER', $currentUser)
$xml = $xml.Replace('CHANGE_ME_PHP_EXE', $PhpPath)
$xml = $xml.Replace('CHANGE_ME_AGENT_PHP', $AgentPhp)
$xml = $xml.Replace('CHANGE_ME_LOCAL_AGENT_DIR', $LocalAgentDir)

# The template is shipped as plain UTF-8 (so it opens correctly in any
# editor), but Task Scheduler's XML import wants an actual UTF-16 file
# whose own declaration says so — schtasks silently fails to parse a
# UTF-8-encoded file otherwise. Both the bytes and the declaration are
# switched here, only for the rendered copy.
$xml = $xml.Replace('encoding="UTF-8"', 'encoding="UTF-16"')
$renderedPath = Join-Path $env:TEMP 'local-development-publisher-agent-task.xml'
[System.IO.File]::WriteAllText($renderedPath, $xml, [System.Text.Encoding]::Unicode)

Write-Host "در حال ثبت Task Scheduler برای کاربر $currentUser ..."
schtasks /Create /TN $TaskName /XML $renderedPath /F

if ($LASTEXITCODE -ne 0) {
    Write-Error 'ثبت Task Scheduler ناموفق بود — این اسکریپت را در یک PowerShell با دسترسی Administrator اجرا کنید.'
    Remove-Item $renderedPath -ErrorAction SilentlyContinue
    exit 1
}

Remove-Item $renderedPath -ErrorAction SilentlyContinue

Write-Host ''
Write-Host '=============================================='
Write-Host "Task «$TaskName» ثبت شد — از ورود بعدی به ویندوز، Local Agent خودکار اجرا می‌شود."
Write-Host 'برای اجرای فوری (بدون خروج از ویندوز):'
Write-Host "    schtasks /Run /TN $TaskName"
Write-Host 'برای مشاهده وضعیت:'
Write-Host "    schtasks /Query /TN $TaskName /V /FO LIST"
Write-Host 'برای حذف:'
Write-Host "    schtasks /Delete /TN $TaskName /F"
Write-Host '=============================================='
Write-Host ''
Write-Host 'اگر هنوز config.php نساخته‌اید:'
Write-Host '    php agent.php install --hub=https://... --id=<registration_id> --token=<registration_token>'
