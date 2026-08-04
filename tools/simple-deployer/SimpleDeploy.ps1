<#
.SYNOPSIS
    SimpleDeploy — یک ابزار مستقل و ساده که فعلاً مسیر عملیاتی Deployment
    Hub/Local Agent/Server Agent را برای Deploy دستی جایگزین می‌کند: تغییرات
    پروژه‌های فعال را commit/push می‌کند، یک Artifact ایزوله می‌سازد، و آن
    را مستقیماً از طریق SSH/SCP روی VPS فعال می‌کند — بدون هیچ وابستگی به
    سرویس Deployment فعلی.

.DESCRIPTION
    سرویس Deployment فعلی حذف یا تغییر داده نمی‌شود؛ این ابزار فقط، در
    صورت نیاز، آن را موقتاً متوقف می‌کند تا تداخلی پیش نیاید و در پایان
    دقیقاً به وضعیت قبلی بازش می‌گرداند.

.PARAMETER Project
    کلید یک پروژه مشخص در config.json (مثلاً customer-site).

.PARAMETER All
    همه پروژه‌های enabled را پردازش کن.

.PARAMETER CommitMessage
    پیام commit. در حالت Interactive اگر داده نشود، از کاربر پرسیده
    می‌شود (یا یک پیام استاندارد پیشنهاد داده می‌شود).

.PARAMETER NonInteractive
    بدون هیچ پرسش تعاملی اجرا شود — CommitMessage و انتخاب پروژه(ها) باید
    از قبل مشخص شده باشند.

.PARAMETER DryRun
    هیچ commit، push، upload، SSH یا تغییر Task واقعی انجام نده — فقط هر
    مرحله، هر دستور، و هر فایلی که قرار است منتقل شود را نمایش بده.

.PARAMETER PauseDeploymentService
    پیش‌فرض true — قبل از Deploy، سرویس Deployment فعلی (Scheduled
    Taskهای ویندوز) موقتاً متوقف و در پایان بازگردانده می‌شود.

.PARAMETER SkipDeploymentService
    سرویس Deployment را اصلاً لمس نکن (نه بررسی، نه توقف). معادل
    -PauseDeploymentService:$false با یک نام صریح‌تر.

.PARAMETER ResumeDeploymentService
    فقط وضعیت بازیابی (crash recovery) قبلی را بررسی و در صورت وجود،
    Taskهای متوقف‌شده را دوباره اجرا کن، سپس خارج شو — هیچ Deploy جدیدی
    انجام نمی‌شود.

.EXAMPLE
    .\SimpleDeploy.ps1
.EXAMPLE
    .\SimpleDeploy.ps1 -Project customer-site
.EXAMPLE
    .\SimpleDeploy.ps1 -All
.EXAMPLE
    .\SimpleDeploy.ps1 -Project customer-site -CommitMessage "fix: ..." -NonInteractive
.EXAMPLE
    .\SimpleDeploy.ps1 -All -DryRun
#>

[CmdletBinding(DefaultParameterSetName = 'Deploy')]
param(
    [Parameter(ParameterSetName = 'Deploy')][string]$Project,
    [Parameter(ParameterSetName = 'Deploy')][switch]$All,
    [Parameter(ParameterSetName = 'Deploy')][string]$CommitMessage,
    [Parameter(ParameterSetName = 'Deploy')][switch]$NonInteractive,
    [Parameter(ParameterSetName = 'Deploy')][switch]$DryRun,
    [Parameter(ParameterSetName = 'Deploy')][bool]$PauseDeploymentService = $true,
    [Parameter(ParameterSetName = 'Deploy')][switch]$SkipDeploymentService,
    [Parameter(ParameterSetName = 'Resume')][switch]$ResumeDeploymentService,
    [Parameter(ParameterSetName = 'Deploy')][string]$ConfigPath
)

Set-StrictMode -Version Latest
$ErrorActionPreference = 'Stop'

$ScriptRoot = $PSScriptRoot
. (Join-Path $ScriptRoot 'lib\SimpleDeploy.Common.ps1')
. (Join-Path $ScriptRoot 'lib\SimpleDeploy.Config.ps1')
. (Join-Path $ScriptRoot 'lib\SimpleDeploy.ServiceControl.ps1')
. (Join-Path $ScriptRoot 'lib\SimpleDeploy.Preflight.ps1')
. (Join-Path $ScriptRoot 'lib\SimpleDeploy.Git.ps1')
. (Join-Path $ScriptRoot 'lib\SimpleDeploy.Build.ps1')
. (Join-Path $ScriptRoot 'lib\SimpleDeploy.Transfer.ps1')
. (Join-Path $ScriptRoot 'lib\SimpleDeploy.Orchestration.ps1')

$StateDir = Join-Path $ScriptRoot 'state'
if (-not $ConfigPath) { $ConfigPath = Join-Path $ScriptRoot 'config.json' }

Write-Host ''
Write-Host '========================================================' -ForegroundColor DarkCyan
Write-Host '   SimpleDeploy — ابزار مستقل Deploy (جایگزین موقت)' -ForegroundColor DarkCyan
Write-Host '========================================================' -ForegroundColor DarkCyan

if ($ResumeDeploymentService) {
    Write-Step 'بررسی وضعیت بازیابی Deployment Service'
    $stale = Get-StaleRecoveryState -StateDir $StateDir
    if ($null -eq $stale) {
        Write-Info 'هیچ وضعیت بازیابی معلقی پیدا نشد — Deployment Service نیازی به بازگردانی ندارد.'
        exit 0
    }
    Test-DeploymentServiceRecovery -StateDir $StateDir -NonInteractive $true -NonInteractiveDecision 'Resume'
    exit 0
}

if ($SkipDeploymentService) { $PauseDeploymentService = $false }

if ($DryRun) {
    Write-Host ''
    Write-Host '  *** حالت DRY-RUN — هیچ تغییری اعمال نخواهد شد ***' -ForegroundColor Yellow
    Write-Host ''
}

$lockMutex = Enter-SimpleDeployLock
if ($null -eq $lockMutex) {
    Write-ErrorFa 'یک نمونه دیگر از SimpleDeploy در حال اجراست. برای جلوگیری از تداخل، این اجرا متوقف می‌شود.'
    exit 1
}

$exitCode = 0
$pausedTaskNames = @()
$weArePausing = $false

try {
    Write-Step 'بررسی وضعیت بازیابی (crash recovery) از اجرای قبلی'
    $decision = if ($NonInteractive) { 'Skip' } else { 'Ask' }
    try {
        Test-DeploymentServiceRecovery -StateDir $StateDir -NonInteractive:([bool]$NonInteractive) -NonInteractiveDecision $decision
    } catch {
        Write-ErrorFa $_.Exception.Message
        exit 1
    }

    Write-Step 'بارگذاری پیکربندی'
    $config = Get-SimpleDeployConfig -ConfigPath $ConfigPath
    Write-Success "پیکربندی از '$ConfigPath' بارگذاری شد."

    $allProjects = Get-EnabledProjects -Config $config
    if ($allProjects.Count -eq 0) {
        Write-ErrorFa 'هیچ پروژه فعالی (enabled=true) در پیکربندی پیدا نشد.'
        exit 1
    }

    $selectedProjects = @()
    if ($All) {
        $selectedProjects = $allProjects
    } elseif ($Project) {
        $selectedProjects = $allProjects | Where-Object { $_.Key -eq $Project }
        if ($selectedProjects.Count -eq 0) {
            Write-ErrorFa "پروژه '$Project' در میان پروژه‌های فعال پیدا نشد."
            exit 1
        }
    } elseif ($NonInteractive) {
        Write-ErrorFa 'در حالت NonInteractive باید -Project یا -All مشخص شود.'
        exit 1
    } else {
        Write-Step 'انتخاب پروژه(ها)'
        for ($i = 0; $i -lt $allProjects.Count; $i++) {
            $p = $allProjects[$i]
            $mark = if ($p.IsDeployable) { '' } else { ' (نیازمند تکمیل پیکربندی)' }
            Write-Host "  [$($i + 1)] $($p.Key)$mark"
        }
        Write-Host '  [0] همه پروژه‌های فعال'
        $choice = Read-Host 'شماره پروژه مورد نظر را وارد کنید (یا 0 برای همه)'
        if ($choice -eq '0') {
            $selectedProjects = $allProjects
        } else {
            $index = [int]$choice - 1
            if ($index -lt 0 -or $index -ge $allProjects.Count) {
                Write-ErrorFa 'انتخاب نامعتبر است.'
                exit 1
            }
            $selectedProjects = @($allProjects[$index])
        }
    }

    $deploymentServiceCfg = $config.deployment_service

    if ($PauseDeploymentService -and -not $DryRun) {
        Write-Step 'توقف موقت Deployment Service'
        $suspendResult = Suspend-DeploymentService `
            -TaskNames $deploymentServiceCfg.windows_scheduled_tasks `
            -ProcessNames $deploymentServiceCfg.windows_process_names `
            -CommandLineMarker $deploymentServiceCfg.windows_process_commandline_marker `
            -StateDir $StateDir `
            -ProcessExitTimeoutSeconds $deploymentServiceCfg.linux_service_stop_timeout_seconds
        $pausedTaskNames = $suspendResult.PausedTaskNames
        $weArePausing = $true
    } elseif ($PauseDeploymentService -and $DryRun) {
        Write-Step 'توقف موقت Deployment Service (DRY-RUN — فقط نمایش)'
        foreach ($taskName in $deploymentServiceCfg.windows_scheduled_tasks) {
            $state = Get-WindowsScheduledTaskState -TaskName $taskName
            Write-Info "Task «$taskName»: $($state.State) — در اجرای واقعی متوقف می‌شد اگر Running بود."
        }
    } else {
        Write-Step 'Deployment Service لمس نمی‌شود (SkipDeploymentService)'
    }

    $results = New-Object System.Collections.Generic.List[object]

    foreach ($projectInfo in $selectedProjects) {
        $result = Invoke-ProjectDeploy `
            -ProjectInfo $projectInfo `
            -CommitMessage $CommitMessage `
            -NonInteractive:([bool]$NonInteractive) `
            -DryRun:([bool]$DryRun) `
            -DeploymentServiceCfg $deploymentServiceCfg `
            -SshCfg $config.ssh `
            -ScriptRoot $ScriptRoot
        $results.Add($result)
    }

    Write-Step 'گزارش نهایی'
    $anyFailed = $false
    foreach ($r in $results) {
        switch ($r.Status) {
            'Succeeded' { Write-Success "$($r.Key): موفق$(if ($r.CommitSha) { " (commit $($r.CommitSha.Substring(0,12)))" })" }
            'Skipped' { Write-Info "$($r.Key): رد شد — $($r.Reason)" }
            'DryRun' { Write-Info "$($r.Key): DRY-RUN کامل شد (هیچ تغییری اعمال نشد)" }
            'Failed' { Write-ErrorFa "$($r.Key): ناموفق — $($r.Reason)"; $anyFailed = $true }
        }
    }

    if ($anyFailed) { $exitCode = 1 }
} finally {
    if ($weArePausing) {
        Write-Step 'بازگردانی Deployment Service به وضعیت قبلی'
        Resume-DeploymentService -PausedTaskNames $pausedTaskNames -StateDir $StateDir
    }
    Exit-SimpleDeployLock -Mutex $lockMutex
}

exit $exitCode
