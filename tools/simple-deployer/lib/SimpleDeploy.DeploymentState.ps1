<#
.SYNOPSIS
    Runtime-only (no database, no disk persistence) Deployment State
    Observability layer: a Run ID, an ordered event timeline for one
    Deploy run (RunState), a per-target state snapshot for a multi-target
    Deploy (BatchState), a parser for the print-only rollback markers
    activate-release.sh now emits, and a live-report formatter that
    reuses the SAME Write-Step/Write-Info channel every other stage of
    this tool already writes to (see SimpleDeploy.Timing.ps1's own
    .NOTES for why that specific choice means no new UI surface, log
    channel, or control is needed).

.NOTES
    Every function here is purely additive and observational: nothing in
    this file decides whether a stage runs, retries, or fails, and
    nothing here is written to any file or database. A caller that never
    calls into this file (e.g. an older test, or a future caller that
    doesn't pass a RunState/BatchState) is completely unaffected -- every
    wiring point elsewhere accepts these as optional, $null-default
    parameters, exactly like -TimingReport before it.
#>

Set-StrictMode -Version Latest

$script:DeploymentRunEventNames = @(
    'RUN_STARTED',
    'PREFLIGHT_STARTED', 'PREFLIGHT_COMPLETED',
    'GIT_STARTED', 'GIT_COMPLETED',
    'BUILD_STARTED', 'BUILD_COMPLETED',
    'ARTIFACT_VALIDATION_STARTED', 'ARTIFACT_VALIDATION_COMPLETED',
    'TRANSFER_STARTED', 'TRANSFER_COMPLETED',
    'ACTIVATION_STARTED', 'ACTIVATION_COMPLETED',
    'HEALTHCHECK_STARTED', 'HEALTHCHECK_COMPLETED',
    'RUN_COMPLETED', 'RUN_FAILED'
)

<#
.SYNOPSIS
    Generates a Run ID in the form DEP-YYYYMMDD-HHMMSS-XXXX. Runtime
    memory only -- never written to disk or a database by this
    function; it is the caller's responsibility to only ever hold it in
    a local variable / RunState object for the lifetime of one Deploy.

.NOTES
    XXXX is a 4-character uppercase hex suffix so that two runs started
    within the same second still get distinct IDs.
#>
function New-DeploymentRunId {
    param(
        [Parameter(Mandatory = $false)][datetime]$UtcNow = (Get-Date).ToUniversalTime()
    )
    $suffix = '{0:X4}' -f (Get-Random -Minimum 0 -Maximum 65536)
    return 'DEP-{0}-{1}-{2}' -f $UtcNow.ToString('yyyyMMdd'), $UtcNow.ToString('HHmmss'), $suffix
}

<#
.SYNOPSIS
    Creates a fresh, empty RunState for one Deploy run -- pass it into
    Add-DeploymentRunEvent as each named stage starts/completes.
#>
function New-DeploymentRunState {
    param(
        [Parameter(Mandatory = $true)][string]$RunId,
        [Parameter(Mandatory = $true)][string]$Application
    )
    return [PSCustomObject]@{
        RunId        = $RunId
        Application  = $Application
        StartedAtUtc = (Get-Date).ToUniversalTime()
        CurrentStage = 'created'
        Events       = New-Object System.Collections.Generic.List[object]
    }
}

<#
.SYNOPSIS
    Appends one named stage event to $RunState.Events and updates
    $RunState.CurrentStage. $EventName must be one of the 17 fixed
    stage-event names this Deploy pipeline recognizes.
#>
function Add-DeploymentRunEvent {
    param(
        [Parameter(Mandatory = $true)]$RunState,
        [Parameter(Mandatory = $true)][ValidateSet(
            'RUN_STARTED',
            'PREFLIGHT_STARTED', 'PREFLIGHT_COMPLETED',
            'GIT_STARTED', 'GIT_COMPLETED',
            'BUILD_STARTED', 'BUILD_COMPLETED',
            'ARTIFACT_VALIDATION_STARTED', 'ARTIFACT_VALIDATION_COMPLETED',
            'TRANSFER_STARTED', 'TRANSFER_COMPLETED',
            'ACTIVATION_STARTED', 'ACTIVATION_COMPLETED',
            'HEALTHCHECK_STARTED', 'HEALTHCHECK_COMPLETED',
            'RUN_COMPLETED', 'RUN_FAILED'
        )][string]$EventName,
        [Parameter(Mandatory = $false)][string]$Target = $null,
        [Parameter(Mandatory = $false)][string]$Detail = $null
    )

    $RunState.Events.Add([PSCustomObject]@{
        EventName    = $EventName
        Target       = $Target
        Detail       = $Detail
        TimestampUtc = (Get-Date).ToUniversalTime()
    })
    $RunState.CurrentStage = $EventName
}

<#
.SYNOPSIS
    Creates a runtime-only per-target state snapshot for one multi-target
    Deploy (Invoke-ApplicationDeployToTargets). Every target starts at
    Stage 'pending'; call Set-DeploymentTargetState as each target
    progresses. No database, no disk -- lives only for the duration of
    the Deploy call that created it.
#>
function New-DeploymentBatchState {
    param(
        [Parameter(Mandatory = $true)][string]$RunId,
        [Parameter(Mandatory = $true)][string]$Application,
        [Parameter(Mandatory = $true)][string[]]$Targets
    )

    $targetOrder = New-Object System.Collections.Generic.List[string]
    $targetStates = [ordered]@{}
    foreach ($t in $Targets) {
        $targetOrder.Add($t)
        $targetStates[$t] = [PSCustomObject]@{
            Stage        = 'pending'
            UpdatedAtUtc = (Get-Date).ToUniversalTime()
            Detail       = $null
        }
    }

    return [PSCustomObject]@{
        RunId        = $RunId
        Application  = $Application
        StartedAtUtc = (Get-Date).ToUniversalTime()
        TargetOrder  = $targetOrder
        TargetStates = $targetStates
    }
}

<#
.SYNOPSIS
    Updates one target's Stage/Detail inside $BatchState -- isolated
    from every other target in the same batch (each target has its own
    entry in $BatchState.TargetStates, so one target's failure never
    overwrites another target's recorded state).
#>
function Set-DeploymentTargetState {
    param(
        [Parameter(Mandatory = $true)]$BatchState,
        [Parameter(Mandatory = $true)][string]$Target,
        [Parameter(Mandatory = $true)][string]$Stage,
        [Parameter(Mandatory = $false)][string]$Detail = $null
    )

    if (-not $BatchState.TargetStates.Contains($Target)) {
        $BatchState.TargetOrder.Add($Target)
        $BatchState.TargetStates[$Target] = [PSCustomObject]@{
            Stage        = $Stage
            UpdatedAtUtc = (Get-Date).ToUniversalTime()
            Detail       = $Detail
        }
        return
    }

    $BatchState.TargetStates[$Target].Stage        = $Stage
    $BatchState.TargetStates[$Target].UpdatedAtUtc  = (Get-Date).ToUniversalTime()
    $BatchState.TargetStates[$Target].Detail        = $Detail
}

<#
.SYNOPSIS
    Parses the 3 print-only "[STATE-MARK] <NAME>" lines activate-
    release.sh now emits around its existing, unchanged automatic
    rollback (perform_rollback) into a small result object. Never
    throws; an empty/absent marker set just returns all-false.
#>
function ConvertFrom-ActivationRollbackMarkers {
    param(
        [Parameter(Mandatory = $false)][string]$StdOut = ''
    )

    $result = [PSCustomObject]@{
        ActivationRollbackStarted  = $false
        HealthcheckRollbackStarted = $false
        RollbackCompleted          = $false
        RollbackType               = $null
    }

    if ([string]::IsNullOrEmpty($StdOut)) {
        return $result
    }

    if ($StdOut -match '\[STATE-MARK\]\s+ACTIVATION_ROLLBACK_STARTED') {
        $result.ActivationRollbackStarted = $true
        $result.RollbackType = 'activation'
    }
    if ($StdOut -match '\[STATE-MARK\]\s+HEALTHCHECK_ROLLBACK_STARTED') {
        $result.HealthcheckRollbackStarted = $true
        $result.RollbackType = 'healthcheck'
    }
    if ($StdOut -match '\[STATE-MARK\]\s+ROLLBACK_COMPLETED') {
        $result.RollbackCompleted = $true
    }

    return $result
}

<#
.SYNOPSIS
    Prints a formatted live-state block (Deployment ID / Application /
    Stage / Elapsed / per-Target Stage / Completed count / Failed count)
    via Write-Step/Write-Info -- the same channel Write-DeployTimingReport
    already uses, which the GUI's "گزارش زنده" (live report) log box
    already drains via Start-LauncherOperation's Write-Info override. No
    new UI control or output channel is introduced.

.NOTES
    -BatchState is optional: a single-target or not-yet-batched caller
    can pass just -RunState and still get the Deployment ID/Application/
    Stage/Elapsed header lines.
#>
function Format-DeploymentLiveReportBlock {
    param(
        [Parameter(Mandatory = $true)]$RunState,
        [Parameter(Mandatory = $false)]$BatchState = $null
    )

    $elapsed = (Get-Date).ToUniversalTime() - $RunState.StartedAtUtc

    Write-Step 'وضعیت زنده Deploy (Live Deployment State)'
    Write-Info ('  Deployment ID : {0}' -f $RunState.RunId)
    Write-Info ('  Application   : {0}' -f $RunState.Application)
    Write-Info ('  Stage         : {0}' -f $RunState.CurrentStage)
    Write-Info ('  Elapsed       : {0:hh\:mm\:ss}' -f $elapsed)

    if ($BatchState) {
        $completedCount = 0
        $failedCount = 0
        foreach ($targetName in $BatchState.TargetOrder) {
            $targetState = $BatchState.TargetStates[$targetName]
            Write-Info ('  Target: {0,-20} Stage: {1}' -f $targetName, $targetState.Stage)
            if ($targetState.Stage -eq 'completed') {
                $completedCount++
            } elseif ($targetState.Stage -like 'failed*') {
                $failedCount++
            }
        }
        Write-Info ('  Completed     : {0}' -f $completedCount)
        Write-Info ('  Failed        : {0}' -f $failedCount)
    }
}
