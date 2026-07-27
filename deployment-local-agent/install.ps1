<#
.SYNOPSIS
    Installs the Local Development Publisher Agent: copies its files to
    C:\ProgramData\DeploymentLocalAgent, registers it with the Deployment
    Hub, and sets it up to start automatically at Windows login.

.DESCRIPTION
    Wraps the agent's own, already-tested `agent.php install` command —
    this script does not implement registration itself, only the
    surrounding file-copy and Task Scheduler setup. Must be run in an
    elevated PowerShell prompt (Run as Administrator).

    RegistrationId/RegistrationToken come from the Hub's "Local Agents"
    page ("Create Registration Token" — an Admin issues them, shown only
    once). This script cannot obtain them on its own.

.PARAMETER HubUrl
    Base URL of the Deployment Hub, e.g. https://deployment.example.test

.PARAMETER RegistrationId
    The one-time registration id shown in the Hub's Local Agents page.

.PARAMETER RegistrationToken
    The one-time registration token shown alongside it.

.PARAMETER Name
    Display name for this agent in the Hub. Defaults to this machine's
    hostname.

.PARAMETER InstallDir
    Where to install. Defaults to C:\ProgramData\DeploymentLocalAgent.

.PARAMETER PhpPath
    Absolute path to php.exe. Auto-detected from PATH if omitted.

.EXAMPLE
    .\install.ps1 -HubUrl https://deployment.example.test -RegistrationId <id> -RegistrationToken <token>
#>
param(
    [Parameter(Mandatory = $true)][string]$HubUrl,
    [Parameter(Mandatory = $true)][string]$RegistrationId,
    [Parameter(Mandatory = $true)][string]$RegistrationToken,
    [string]$Name,
    [string]$InstallDir = 'C:\ProgramData\DeploymentLocalAgent',
    [string]$PhpPath
)

$ErrorActionPreference = 'Stop'
$PackageDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$TaskName = 'DeploymentLocalAgent'

function Fail($message) {
    Write-Host ''
    Write-Host "خطا: $message" -ForegroundColor Red
    exit 1
}

Write-Host '=== نصب Local Development Publisher Agent ==='
Write-Host ''

# --- Phase A: pre-flight checks ------------------------------------------

if (-not $PhpPath) {
    $found = Get-Command php.exe -ErrorAction SilentlyContinue
    if (-not $found) {
        Fail 'php.exe در PATH پیدا نشد. مسیر آن را با -PhpPath مشخص کنید.'
    }
    $PhpPath = $found.Source
}
if (-not (Test-Path $PhpPath)) {
    Fail "php.exe در مسیر مشخص‌شده پیدا نشد: $PhpPath"
}
Write-Host "PHP یافت شد: $PhpPath"

$gitFound = Get-Command git.exe -ErrorAction SilentlyContinue
if (-not $gitFound) {
    Fail 'git.exe در PATH پیدا نشد — بدون آن Publish واقعی ممکن نیست. Git for Windows را نصب کنید.'
}
Write-Host "Git یافت شد: $($gitFound.Source)"

# Phase 5.5: informational only, never fails the install — Publish uses
# whatever git credential setup the developer's own session already has
# (SSH key OR an HTTPS credential manager), so a missing SSH key here is
# a heads-up, not a blocker.
$sshDir = Join-Path $env:USERPROFILE '.ssh'
$sshKeyFound = @('id_rsa', 'id_ed25519', 'id_ecdsa') | Where-Object { Test-Path (Join-Path $sshDir $_) } | Select-Object -First 1
if ($sshKeyFound) {
    Write-Host "کلید SSH یافت شد: $(Join-Path $sshDir $sshKeyFound)"
} else {
    Write-Host 'هشدار: کلید SSH در %USERPROFILE%\.ssh پیدا نشد — اگر Repository شما از HTTPS با Credential Manager استفاده می‌کند مشکلی نیست، در غیر این صورت Push واقعی ممکن است ناموفق شود.' -ForegroundColor Yellow
}

$currentPrincipal = New-Object Security.Principal.WindowsPrincipal([Security.Principal.WindowsIdentity]::GetCurrent())
if (-not $currentPrincipal.IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)) {
    Fail 'این اسکریپت باید در یک PowerShell با دسترسی Administrator اجرا شود (Run as Administrator).'
}

# --- Phase B: copy files ---------------------------------------------------

$agentDir = Join-Path $InstallDir 'agent'
New-Item -ItemType Directory -Force -Path $agentDir | Out-Null
New-Item -ItemType Directory -Force -Path (Join-Path $agentDir 'lib') | Out-Null

Copy-Item -Path (Join-Path $PackageDir 'agent\agent.php') -Destination $agentDir -Force
Copy-Item -Path (Join-Path $PackageDir 'agent\lib\*.php') -Destination (Join-Path $agentDir 'lib') -Force
Copy-Item -Path (Join-Path $PackageDir 'config\config.example.php') -Destination $agentDir -Force

Write-Host "فایل‌های Agent در $agentDir کپی شدند."

# --- Phase C: register with the Hub (reuses agent.php's own, already-tested install command) ---

$agentPhp = Join-Path $agentDir 'agent.php'
if (-not $Name) {
    $Name = $env:COMPUTERNAME
}

Write-Host ''
Write-Host 'در حال ثبت‌نام در Hub ...'
Push-Location $agentDir
try {
    & $PhpPath $agentPhp install --hub=$HubUrl --id=$RegistrationId --token=$RegistrationToken --name=$Name
    $registerExitCode = $LASTEXITCODE
} finally {
    Pop-Location
}

if ($registerExitCode -ne 0) {
    Fail 'ثبت‌نام ناموفق بود — پیام بالا را بررسی کنید (توکن ممکن است منقضی یا قبلاً استفاده شده باشد).'
}

# --- Phase D: Windows Task Scheduler (LogonTrigger — see the plan's own
# rationale for why this is deliberately NOT "whether logged on or not":
# the agent must run in the developer's own session to reach their SSH
# key / git credentials) ---

$templatePath = Join-Path $PackageDir 'config\windows-task.xml.example'
$currentUser = "$env:USERDOMAIN\$env:USERNAME"

$xml = Get-Content -Path $templatePath -Raw -Encoding UTF8
$xml = $xml.Replace('CHANGE_ME_WINDOWS_USER', $currentUser)
$xml = $xml.Replace('CHANGE_ME_PHP_EXE', $PhpPath)
$xml = $xml.Replace('CHANGE_ME_AGENT_PHP', $agentPhp)
$xml = $xml.Replace('CHANGE_ME_LOCAL_AGENT_DIR', $agentDir)
# Shipped as UTF-8 so it opens correctly in any editor; Task Scheduler's
# XML import wants an actual UTF-16 file whose own declaration says so.
$xml = $xml.Replace('encoding="UTF-8"', 'encoding="UTF-16"')

$renderedPath = Join-Path $env:TEMP 'deployment-local-agent-task.xml'
[System.IO.File]::WriteAllText($renderedPath, $xml, [System.Text.Encoding]::Unicode)

Write-Host ''
Write-Host "در حال ثبت Task Scheduler برای کاربر $currentUser ..."
schtasks /Create /TN $TaskName /XML $renderedPath /F | Out-Null
$taskExitCode = $LASTEXITCODE
Remove-Item $renderedPath -ErrorAction SilentlyContinue

if ($taskExitCode -ne 0) {
    Write-Host 'هشدار: ثبت Task Scheduler ناموفق بود — Agent را دستی با «php agent.php» اجرا کنید یا بعداً از install\install-windows-task.ps1 استفاده کنید.' -ForegroundColor Yellow
} else {
    Write-Host "Task «$TaskName» ثبت شد — از ورود بعدی به ویندوز، Agent خودکار اجرا می‌شود."
    schtasks /Run /TN $TaskName | Out-Null
}

# --- Phase E: one status poll (pending-approval is a NORMAL outcome, not an error) ---

Write-Host ''
Write-Host 'در حال بررسی وضعیت اولیه ...'
Push-Location $agentDir
try {
    & $PhpPath $agentPhp --once 2>&1 | Out-Null
} finally {
    Pop-Location
}

Write-Host ''
Write-Host '=============================================='
Write-Host 'نصب کامل شد.'
Write-Host "نام Agent: $Name"
Write-Host "مسیر نصب: $agentDir"
Write-Host ''
Write-Host 'مرحله بعدی: یک Admin باید این Agent را در پنل (Local Agents -> Pending) تایید کند.'
Write-Host 'پس از تایید، agent_token و signing_secret نمایش‌داده‌شده در پنل را در این فایل جایگزین کنید:'
Write-Host "    $agentDir\config.php"
Write-Host 'سپس یک نگاشت پروژه (Project Mapping) برای این Agent در پنل اضافه کنید.'
Write-Host '=============================================='
