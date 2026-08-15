<#
.SYNOPSIS
    A minimal, purely-additive performance timing layer for Invoke-
    ProjectDeploy: records how long each named stage of ONE Deploy took,
    and nothing else. No stage this file touches is skipped, reordered,
    retried, or given a different outcome, return value, or exit code
    because of it — every function that gained an optional
    -TimingReport parameter for this behaves EXACTLY as it did before
    when that parameter is omitted (the default everywhere is $null),
    so this file is invisible to every existing caller/test that doesn't
    explicitly opt in.

.NOTES
    The report is emitted via Write-Step/Write-Info — the SAME functions
    every other stage of this tool already uses to reach both the CLI
    console and (via Start-LauncherOperation's Write-Info override,
    OutputQueue, and pollTimer) the GUI's own "گزارش زنده" (live report)
    log box. No new UI surface, control, or output channel is added.
#>

Set-StrictMode -Version Latest

<#
.OUTPUTS
    A fresh, empty timing report — pass this same object into
    Add-DeployStageTiming for every stage of one Deploy, then into
    Write-DeployTimingReport once at the end.
#>
function New-DeployTimingReport {
    return [PSCustomObject]@{
        StageOrder = New-Object System.Collections.Generic.List[string]
        Stages     = @{}
    }
}

<#
.SYNOPSIS
    Records a stage's duration into $Report. Pass either a running
    [System.Diagnostics.Stopwatch] (stopped here) via -Stopwatch, or an
    already-known duration in milliseconds via -ElapsedMs.

.NOTES
    If $StageName was already recorded once this Deploy (e.g. "SCP
    transfer", covering two separate uploads), the new duration is ADDED
    to the existing one rather than overwriting it — so a caller can
    time each part of a multi-call stage separately and still get one
    combined total for that row, in the exact order it was first seen.
#>
function Add-DeployStageTiming {
    param(
        [Parameter(Mandatory = $true)]$Report,
        [Parameter(Mandatory = $true)][string]$StageName,
        [Parameter(Mandatory = $false)]$Stopwatch = $null,
        [Parameter(Mandatory = $false)][double]$ElapsedMs = -1,
        # Optional: when given, also appends a "STAGE END" marker to the
        # internal launcher-debug.log (see Write-LauncherDebugLog in
        # SimpleDeployLauncher.Log.ps1) -- $null (the default) is exactly
        # the pre-existing, unchanged behavior for every caller that
        # doesn't pass it (SimpleDeploy.ps1's CLI path never dot-sources
        # Log.ps1 at all, so Write-LauncherDebugLog wouldn't even exist
        # there -- guarded below via Get-Command, never assumed present).
        [Parameter(Mandatory = $false)][string]$ToolRoot = $null
    )

    $ms = if ($Stopwatch) { $Stopwatch.Stop(); $Stopwatch.Elapsed.TotalMilliseconds } else { $ElapsedMs }
    if (-not $Report.Stages.ContainsKey($StageName)) {
        $Report.Stages[$StageName] = 0.0
        $Report.StageOrder.Add($StageName)
    }
    $Report.Stages[$StageName] += $ms

    if ($ToolRoot -and (Get-Command Write-LauncherDebugLog -ErrorAction SilentlyContinue)) {
        Write-LauncherDebugLog -ToolRoot $ToolRoot -Marker 'STAGE END' -Detail "stage=$StageName elapsedMs=$([math]::Round($ms, 0))"
    }
}

<#
.SYNOPSIS
    Pairs with Add-DeployStageTiming's own "STAGE END" marker -- call
    right before starting a stage's Stopwatch. Same $ToolRoot-guarded,
    Get-Command-checked, silently-no-ops-on-the-CLI-path behavior.
#>
function Write-DeployStageStartMarker {
    param(
        [Parameter(Mandatory = $true)][string]$StageName,
        [Parameter(Mandatory = $false)][string]$ToolRoot = $null
    )
    if ($ToolRoot -and (Get-Command Write-LauncherDebugLog -ErrorAction SilentlyContinue)) {
        Write-LauncherDebugLog -ToolRoot $ToolRoot -Marker 'STAGE START' -Detail "stage=$StageName"
    }
}

<#
.SYNOPSIS
    Prints every recorded stage (in the order first recorded) plus the
    overall total, via Write-Step/Write-Info — see the file .NOTES for
    why that specific choice means no new UI surface is needed.
#>
function Write-DeployTimingReport {
    param(
        [Parameter(Mandatory = $true)]$Report,
        [Parameter(Mandatory = $true)][double]$TotalMs
    )

    Write-Step 'گزارش زمان‌بندی Deploy (Performance Timing)'
    foreach ($stageName in $Report.StageOrder) {
        $ms = $Report.Stages[$stageName]
        Write-Info ('  {0,-20} {1,10:N0} ms' -f $stageName, $ms)
    }
    Write-Info ('  {0,-20} {1,10:N0} ms' -f 'Total duration', $TotalMs)
}
