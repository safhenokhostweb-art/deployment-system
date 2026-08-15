<#
.SYNOPSIS
    Pauses/resumes the EXISTING Deployment Hub/Local Agent/Server Agent
    system on Windows for the duration of a SimpleDeploy run, so the two
    systems never build/publish/deploy at the same time. This file NEVER
    modifies that system's code, database, or config.php — it only
    stops/starts its Scheduled Tasks and waits for its own processes to
    actually exit.

.NOTES
    Crash recovery: the moment tasks are successfully paused, a recovery
    marker is written to state/recovery-state.json. It is only removed
    once they have been successfully resumed. If SimpleDeploy itself
    crashes in between, the NEXT run detects this marker on startup and
    asks the operator whether to resume the paused tasks — see
    Test-DeploymentServiceRecovery.
#>

Set-StrictMode -Version Latest

function Get-RecoveryStatePath {
    param([Parameter(Mandatory = $true)][string]$StateDir)
    return Join-Path $StateDir 'recovery-state.json'
}

function Save-RecoveryState {
    param(
        [Parameter(Mandatory = $true)][string]$StateDir,
        # NOT Mandatory, same reasoning as Resume-DeploymentService above:
        # a Mandatory array-typed parameter rejects @() outright. Currently
        # only called with a non-empty array from Suspend-DeploymentService,
        # but a caller (or test fixture reproducing a stale recovery-state
        # marker for zero paused tasks) legitimately needs to pass @() too.
        [Parameter(Mandatory = $false)][string[]]$PausedTaskNames = @()
    )

    if (-not (Test-Path -LiteralPath $StateDir)) {
        New-Item -ItemType Directory -Path $StateDir -Force | Out-Null
    }

    $state = @{
        paused_task_names = $PausedTaskNames
        started_at        = (Get-Date).ToString('o')
        completed         = $false
    }
    $path = Get-RecoveryStatePath -StateDir $StateDir
    ($state | ConvertTo-Json) | Set-Content -LiteralPath $path -Encoding UTF8
    return $path
}

function Complete-RecoveryState {
    param([Parameter(Mandatory = $true)][string]$StateDir)
    $path = Get-RecoveryStatePath -StateDir $StateDir
    if (Test-Path -LiteralPath $path) {
        Remove-Item -LiteralPath $path -Force -ErrorAction SilentlyContinue
    }
}

<#
.OUTPUTS
    $null if no stale recovery state exists, otherwise the parsed state object.
#>
function Get-StaleRecoveryState {
    param([Parameter(Mandatory = $true)][string]$StateDir)

    $path = Get-RecoveryStatePath -StateDir $StateDir
    if (-not (Test-Path -LiteralPath $path)) { return $null }

    try {
        $state = Get-Content -LiteralPath $path -Raw -Encoding UTF8 | ConvertFrom-Json
    } catch {
        # An unreadable/corrupt recovery file is itself evidence of an
        # abnormal previous exit — treat it as stale with no known task list.
        return @{ paused_task_names = @(); started_at = $null; completed = $false }
    }

    if ($state.completed -eq $true) { return $null }
    return $state
}

<#
.SYNOPSIS
    Call once at startup, BEFORE any new pause is attempted. Detects a
    crash from a previous run and — interactively — asks the operator
    exactly the question specified for this tool, or in NonInteractive
    mode follows the caller's own recovery decision.
#>
function Test-DeploymentServiceRecovery {
    param(
        [Parameter(Mandatory = $true)][string]$StateDir,
        [Parameter(Mandatory = $true)][bool]$NonInteractive,
        [Parameter(Mandatory = $false)][ValidateSet('Resume', 'Skip', 'Ask')][string]$NonInteractiveDecision = 'Ask'
    )

    $stale = Get-StaleRecoveryState -StateDir $StateDir
    if ($null -eq $stale) { return }

    Write-Warn 'Deployment Service در اجرای قبلی این ابزار متوقف شده بود و دوباره راه‌اندازی نشده است (احتمالاً به دلیل خروج غیرمنتظره).'
    $taskNames = @($stale.paused_task_names)
    if ($taskNames.Count -gt 0) {
        Write-Info ("Taskهای متوقف‌شده: " + ($taskNames -join ', '))
    }

    $shouldResume = $false
    if ($NonInteractive) {
        if ($NonInteractiveDecision -eq 'Ask') {
            throw 'در حالت NonInteractive و بدون تصمیم صریح، نمی‌توان وضعیت بازیابی قبلی را پردازش کرد. با -ResumeDeploymentService یا -SkipDeploymentService دوباره اجرا کنید.'
        }
        $shouldResume = ($NonInteractiveDecision -eq 'Resume')
    } else {
        $answer = Read-Host 'آیا Deployment Service دوباره راه‌اندازی شود؟ (y/N)'
        $shouldResume = ($answer -match '^(y|yes|بله)$')
    }

    if ($shouldResume -and $taskNames.Count -gt 0) {
        foreach ($taskName in $taskNames) {
            Resume-WindowsScheduledTask -TaskName $taskName | Out-Null
        }
        Write-Success 'Deployment Service بر اساس وضعیت قبلی دوباره راه‌اندازی شد.'
    } else {
        Write-Info 'Deployment Service خاموش باقی می‌ماند. برای بازیابی دستی از -ResumeDeploymentService استفاده کنید.'
    }

    Complete-RecoveryState -StateDir $StateDir
}

<#
.OUTPUTS
    Hashtable: @{ Exists; State (ScheduledTaskState enum or 'NotFound') }
#>
function Get-WindowsScheduledTaskState {
    param([Parameter(Mandatory = $true)][string]$TaskName)

    try {
        $task = Get-ScheduledTask -TaskName $TaskName -ErrorAction Stop
        return @{ Exists = $true; State = [string]$task.State }
    } catch {
        return @{ Exists = $false; State = 'NotFound' }
    }
}

function Stop-WindowsScheduledTask {
    param([Parameter(Mandatory = $true)][string]$TaskName)
    $result = Invoke-ExternalCommand -FilePath 'schtasks.exe' -ArgumentList @('/End', '/TN', $TaskName) -TimeoutSeconds 30
    return $result
}

function Resume-WindowsScheduledTask {
    param([Parameter(Mandatory = $true)][string]$TaskName)
    $result = Invoke-ExternalCommand -FilePath 'schtasks.exe' -ArgumentList @('/Run', '/TN', $TaskName) -TimeoutSeconds 30
    return $result
}

<#
.SYNOPSIS
    Polls for any process whose Name is in $ProcessNames AND whose
    CommandLine contains $CommandLineMarker — matches exactly the
    processes a paused DeploymentLocalAgent/Watchdog task (and anything
    IT spawned — composer/npm/node/php for a build already in progress)
    could have left running for a moment after schtasks /End returns.
#>
function Wait-ForDeploymentProcessesToExit {
    param(
        # NOT Mandatory — same reasoning as Suspend-DeploymentService's own
        # TaskNames/ProcessNames above: a config with an empty
        # windows_process_names legitimately means "nothing configured to
        # check for", not an error, and the Mandatory array-parameter
        # crash bug applies here identically (this function is also
        # called directly from Test-ProjectPreflight for every project).
        [Parameter(Mandatory = $false)][string[]]$ProcessNames = @(),
        [Parameter(Mandatory = $true)][string]$CommandLineMarker,
        [Parameter(Mandatory = $false)][int]$TimeoutSeconds = 30
    )

    if (@($ProcessNames).Count -eq 0) {
        # Nothing configured to match against — trivially "nothing
        # running", not a WQL query with an empty/undefined filter.
        return @{ Success = $true; RemainingProcesses = @() }
    }

    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    $nameFilter = ($ProcessNames | ForEach-Object { "Name = '$_'" }) -join ' OR '

    while ((Get-Date) -lt $deadline) {
        # Named $matchingProcesses, not $matches — $matches is a PowerShell
        # automatic variable populated by the -match operator; assigning to
        # it directly risks confusing/clobbering unrelated regex matching
        # elsewhere in the same scope.
        $matchingProcesses = Get-CimInstance -ClassName Win32_Process -Filter $nameFilter -ErrorAction SilentlyContinue |
            Where-Object { $_.CommandLine -and $_.CommandLine.Contains($CommandLineMarker) }

        if (-not $matchingProcesses -or @($matchingProcesses).Count -eq 0) {
            return @{ Success = $true; RemainingProcesses = @() }
        }

        Start-Sleep -Milliseconds 500
    }

    $stillRunning = Get-CimInstance -ClassName Win32_Process -Filter $nameFilter -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine.Contains($CommandLineMarker) }

    return @{ Success = (@($stillRunning).Count -eq 0); RemainingProcesses = @($stillRunning) }
}

<#
.SYNOPSIS
    The full pause sequence: capture current state -> stop each Running
    task -> wait for its processes to actually exit -> persist a
    crash-recovery marker. Throws (refusing to proceed with any deploy)
    if a task's processes don't exit within the timeout — never silently
    continues with the old service possibly still mid-build.

.OUTPUTS
    Hashtable: @{ PreviousStates (hashtable keyed by task name); PausedTaskNames (string[]) }
#>
function Suspend-DeploymentService {
    param(
        # NOT Mandatory: a Mandatory array-typed parameter throws "Cannot
        # bind argument ... because it is an empty array" the instant a
        # caller passes @() — a perfectly valid, real config (e.g.
        # windows_scheduled_tasks/windows_process_names left empty during
        # initial setup, or a project that genuinely has no competing
        # Scheduled Task). This is the exact same bug already fixed for
        # Resume-DeploymentService's PausedTaskNames parameter below;
        # TaskNames/ProcessNames here were missed at the time. Confirmed
        # empirically to crash Invoke-LauncherDeployFlow (and, with
        # $ErrorActionPreference='Stop', the whole SimpleDeploy.ps1 CLI
        # run) the moment either config array is empty.
        [Parameter(Mandatory = $false)][string[]]$TaskNames = @(),
        [Parameter(Mandatory = $false)][string[]]$ProcessNames = @(),
        [Parameter(Mandatory = $true)][string]$CommandLineMarker,
        [Parameter(Mandatory = $true)][string]$StateDir,
        [Parameter(Mandatory = $false)][int]$ProcessExitTimeoutSeconds = 30
    )

    $previousStates = @{}
    $pausedTaskNames = New-Object System.Collections.Generic.List[string]

    foreach ($taskName in $TaskNames) {
        $state = Get-WindowsScheduledTaskState -TaskName $taskName
        $previousStates[$taskName] = $state.State

        if (-not $state.Exists) {
            Write-Info "Task «$taskName» روی این سیستم وجود ندارد — نادیده گرفته شد."
            continue
        }

        if ($state.State -eq 'Running') {
            Write-Info "در حال متوقف کردن Task «$taskName» (وضعیت فعلی: Running)..."
            Stop-WindowsScheduledTask -TaskName $taskName | Out-Null
            $pausedTaskNames.Add($taskName)
        } else {
            Write-Info "Task «$taskName» در حال اجرا نیست (وضعیت: $($state.State)) — نیازی به توقف نیست."
        }
    }

    if ($pausedTaskNames.Count -gt 0) {
        Save-RecoveryState -StateDir $StateDir -PausedTaskNames $pausedTaskNames.ToArray() | Out-Null

        $wait = Wait-ForDeploymentProcessesToExit -ProcessNames $ProcessNames -CommandLineMarker $CommandLineMarker -TimeoutSeconds $ProcessExitTimeoutSeconds
        if (-not $wait.Success) {
            $pids = ($wait.RemainingProcesses | ForEach-Object { $_.ProcessId }) -join ', '
            throw "پس از توقف Taskها، پردازش‌های مربوط به Deployment Service ظرف $ProcessExitTimeoutSeconds ثانیه خاتمه نیافتند (PID: $pids). عملیات Deploy انجام نمی‌شود."
        }
        Write-Success 'همه پردازش‌های Deployment Service خاتمه یافتند.'
    }

    return @{ PreviousStates = $previousStates; PausedTaskNames = $pausedTaskNames.ToArray() }
}

<#
.SYNOPSIS
    Restarts ONLY the tasks that were genuinely Running before Suspend-DeploymentService
    paused them — a task that was already stopped/disabled is left exactly
    as it was, never started as a side effect of this tool running.
#>
function Resume-DeploymentService {
    param(
        # NOT Mandatory: a Mandatory array-typed parameter throws "Cannot
        # bind argument ... because it is an empty array" the moment a
        # caller passes @() — a completely valid, expected input here (it
        # means Suspend-DeploymentService found nothing that was actually
        # Running, so nothing needs resuming). Confirmed empirically on
        # this exact PowerShell version. The default of @() also means a
        # caller can omit the parameter entirely for the same "nothing to
        # resume" case.
        [Parameter(Mandatory = $false)][string[]]$PausedTaskNames = @(),
        [Parameter(Mandatory = $true)][string]$StateDir
    )

    # @(...) re-wrap: guards against the same empty-array edge case even
    # if a caller somehow passes $null through explicitly.
    $tasksToResume = @($PausedTaskNames)

    if ($tasksToResume.Count -eq 0) {
        Write-Info 'هیچ سرویس یا Taskای توسط این اجرا متوقف نشده بود؛ نیازی به بازگردانی نیست.'
        Complete-RecoveryState -StateDir $StateDir
        return
    }

    foreach ($taskName in $tasksToResume) {
        Write-Info "در حال راه‌اندازی مجدد Task «$taskName»..."
        Resume-WindowsScheduledTask -TaskName $taskName | Out-Null
    }

    Write-Success 'Deployment Service به وضعیت قبل از اجرای این ابزار بازگردانده شد.'
    Complete-RecoveryState -StateDir $StateDir
}
