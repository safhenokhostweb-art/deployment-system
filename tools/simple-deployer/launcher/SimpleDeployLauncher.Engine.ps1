<#
.SYNOPSIS
    The async execution engine: runs a scriptblock in a fresh, isolated
    PowerShell Runspace (never on the WinForms UI thread) and streams its
    Write-Step/Write-Info/Write-Success/Write-Warn/Write-ErrorFa calls
    into a thread-safe queue the UI drains on a Timer tick. Nothing here
    re-implements Git/Build/Transfer/Activation — every operation just
    calls straight into the existing lib/*.ps1 functions; this file only
    supplies the threading/output-capture plumbing a console-oriented
    library needs to run cleanly under a GUI.

.NOTES
    Cancellation (Stop-LauncherOperation) calls [powershell]::Stop(),
    which requests the pipeline to stop at its next safe checkpoint — it
    does NOT force-kill an external process (git/scp/ssh) that is
    currently mid-flight inside Invoke-ExternalCommand's own
    $process.WaitForExit(). That external command is always allowed to
    finish naturally; the stop only prevents the NEXT step from starting.
    This is exactly the "cancel never performs a destructive action mid
    commit/push" behavior required — achieved by NOT implementing any
    special mid-process kill logic, deliberately.
#>

Set-StrictMode -Version Latest

$script:LauncherLibFiles = @(
    'lib\SimpleDeploy.Common.ps1',
    'lib\SimpleDeploy.Config.ps1',
    'lib\SimpleDeploy.ServiceControl.ps1',
    'lib\SimpleDeploy.Preflight.ps1',
    'lib\SimpleDeploy.Git.ps1',
    'lib\SimpleDeploy.Build.ps1',
    'lib\SimpleDeploy.Transfer.ps1',
    'lib\SimpleDeploy.Orchestration.ps1',
    'launcher\SimpleDeployLauncher.Status.ps1',
    'launcher\SimpleDeployLauncher.GitFlow.ps1',
    'launcher\SimpleDeployLauncher.Log.ps1',
    # Invoke-LauncherDeployFlow (used by the "ارسال به Git و سرور" -Operation
    # scriptblock, which runs entirely inside the background runspace) is
    # defined in THIS file, but this file was never listed in its own
    # dot-source set -- so the background runspace never had it, and any
    # Deploy run hit "The term 'Invoke-LauncherDeployFlow' is not
    # recognized...". Git-only worked because Invoke-LauncherGitOnlyFlow
    # lives in GitFlow.ps1, which WAS already listed above.
    'launcher\SimpleDeployLauncher.Engine.ps1'
)

# Set-StrictMode -Version Latest (below) throws on reading a never-yet-set
# variable, so these need an explicit $null initializer here rather than
# relying on PowerShell's usual "unset variable reads as $null" default.
$script:SharedRunspace = $null
$script:SharedRunspaceWarmupPs = $null
$script:SharedRunspaceWarmupHostRunspace = $null
$script:SharedRunspaceWarmupResult = $null

<#
.SYNOPSIS
    Creates ONE Runspace with every SimpleDeploy library dot-sourced and
    Write-Step/Write-Info/Write-Success/Write-Warn/Write-ErrorFa
    redefined to push onto whatever $OutputQueue is currently set in that
    runspace's own scope — the ONLY change made to those functions'
    behavior; their call SITES throughout lib/*.ps1 are completely
    untouched.

.NOTES
    Measured at ~400-900ms (Runspace creation + dot-sourcing 11 files).
    Start-LauncherOperation used to pay this cost on the UI thread EVERY
    single time any operation started; for "بررسی همه پروژه‌ها" across N
    projects that meant N x ~400-900ms of real UI-thread blocking even
    though each individual git command is fast — the actual root cause of
    the reported Refresh/Check freeze. Get-LauncherRunspace below now
    creates this ONCE and Start-LauncherOperation reuses it for the rest
    of the window's lifetime (safe because the whole GUI already only
    ever runs one operation at a time — buttons are disabled for the
    duration of every tracked operation, and "بررسی همه پروژه‌ها" is
    strictly sequential by its own design).
#>
function New-LauncherRunspace {
    param([Parameter(Mandatory = $true)][string]$ToolRoot)

    $rs = [runspacefactory]::CreateRunspace()
    $rs.ThreadOptions = [System.Management.Automation.Runspaces.PSThreadOptions]::ReuseThread
    $rs.Open()
    $rs.SessionStateProxy.SetVariable('ToolRoot', $ToolRoot)
    $rs.SessionStateProxy.SetVariable('LauncherLibFiles', $script:LauncherLibFiles)

    $initPs = [powershell]::Create()
    $initPs.Runspace = $rs
    [void]$initPs.AddScript({
        foreach ($relativePath in $LauncherLibFiles) {
            . (Join-Path $ToolRoot $relativePath)
        }

        function Write-Step    { param($Message) $OutputQueue.Enqueue(@{ Type = 'Step';    Text = $Message }) }
        function Write-Info    { param($Message) $OutputQueue.Enqueue(@{ Type = 'Info';    Text = $Message }) }
        function Write-Success { param($Message) $OutputQueue.Enqueue(@{ Type = 'Success'; Text = $Message }) }
        function Write-Warn    { param($Message) $OutputQueue.Enqueue(@{ Type = 'Warn';    Text = $Message }) }
        function Write-ErrorFa { param($Message) $OutputQueue.Enqueue(@{ Type = 'Error';   Text = $Message }) }
    })
    [void]$initPs.Invoke()
    if ($initPs.HadErrors) {
        $errText = ($initPs.Streams.Error | ForEach-Object { $_.Exception.Message }) -join "`n"
        $initPs.Dispose(); $rs.Close(); $rs.Dispose()
        throw "راه‌اندازی Runspace با خطا مواجه شد: $errText"
    }
    $initPs.Dispose()
    return $rs
}

<#
.SYNOPSIS
    Kicks off New-LauncherRunspace on a background operation of its own
    (a throwaway Runspace + BeginInvoke, exactly the same async mechanism
    every real operation already uses in this file) so the ~400-900ms
    cost is paid while the Launcher window is first appearing — never on
    the UI thread, never on the user's first click. Call once, right when
    Show-SimpleDeployLauncher starts building the window.
#>
function Start-LauncherRunspaceWarmup {
    param([Parameter(Mandatory = $true)][string]$ToolRoot)
    if ($script:SharedRunspace -or $script:SharedRunspaceWarmupPs) { return }

    $hostRs = [runspacefactory]::CreateRunspace()
    $hostRs.Open()
    $hostRs.SessionStateProxy.SetVariable('ToolRoot', $ToolRoot)
    $hostRs.SessionStateProxy.SetVariable('LauncherEngineFile', (Join-Path $ToolRoot 'launcher\SimpleDeployLauncher.Engine.ps1'))

    $ps = [powershell]::Create()
    $ps.Runspace = $hostRs
    [void]$ps.AddScript({
        . $LauncherEngineFile
        return New-LauncherRunspace -ToolRoot $ToolRoot
    })
    $script:SharedRunspaceWarmupPs = $ps
    $script:SharedRunspaceWarmupHostRunspace = $hostRs
    $script:SharedRunspaceWarmupResult = $ps.BeginInvoke()
}

<#
.SYNOPSIS
    Returns the shared, already-dot-sourced Runspace, waiting on the
    background warm-up if it hasn't finished yet (only possible if the
    user's very first click happens before warm-up completes — bounded by
    the same ~400-900ms it always took before, and it can only ever
    happen once per window, never once-per-project like before).
#>
function Get-LauncherRunspace {
    param([Parameter(Mandatory = $true)][string]$ToolRoot)

    if ($script:SharedRunspace -and $script:SharedRunspace.RunspaceStateInfo.State -eq 'Opened') {
        return $script:SharedRunspace
    }
    if ($script:SharedRunspaceWarmupPs) {
        $result = $script:SharedRunspaceWarmupPs.EndInvoke($script:SharedRunspaceWarmupResult)
        try { $script:SharedRunspaceWarmupPs.Dispose() } catch {}
        try { $script:SharedRunspaceWarmupHostRunspace.Close(); $script:SharedRunspaceWarmupHostRunspace.Dispose() } catch {}
        $script:SharedRunspaceWarmupPs = $null
        $script:SharedRunspaceWarmupHostRunspace = $null
        $script:SharedRunspace = $result[0]
        return $script:SharedRunspace
    }
    $script:SharedRunspace = New-LauncherRunspace -ToolRoot $ToolRoot
    return $script:SharedRunspace
}

<#
.SYNOPSIS
    Closes the shared Runspace (and, if warm-up was still in flight,
    waits for and closes that too). Call exactly once, when the Launcher
    window itself is closing — never between individual operations, since
    the whole point is reusing this same Runspace across all of them.
#>
function Close-LauncherRunspace {
    if ($script:SharedRunspaceWarmupPs) {
        try { $script:SharedRunspaceWarmupPs.Stop() } catch {}
        try {
            $result = $script:SharedRunspaceWarmupPs.EndInvoke($script:SharedRunspaceWarmupResult)
            if ($result -and $result[0]) {
                try { $result[0].Close(); $result[0].Dispose() } catch {}
            }
        } catch {}
        try { $script:SharedRunspaceWarmupPs.Dispose() } catch {}
        try { $script:SharedRunspaceWarmupHostRunspace.Close(); $script:SharedRunspaceWarmupHostRunspace.Dispose() } catch {}
        $script:SharedRunspaceWarmupPs = $null
        $script:SharedRunspaceWarmupHostRunspace = $null
    }
    if ($script:SharedRunspace) {
        try { $script:SharedRunspace.Close() } catch {}
        try { $script:SharedRunspace.Dispose() } catch {}
        $script:SharedRunspace = $null
    }
}

<#
.SYNOPSIS
    Starts $Operation (a scriptblock) asynchronously on the shared,
    already-initialized Runspace (see Get-LauncherRunspace) — never
    creates a new one per call.

.PARAMETER Variables
    Hashtable of name -> value injected into the runspace's own scope
    before $Operation runs (e.g. @{ ProjectInfo = $projectInfo }).

.OUTPUTS
    A handle object to pass to Test-LauncherOperationComplete /
    Complete-LauncherOperation / Stop-LauncherOperation.
#>
function Start-LauncherOperation {
    param(
        [Parameter(Mandatory = $true)][string]$ToolRoot,
        [Parameter(Mandatory = $true)][scriptblock]$Operation,
        [Parameter(Mandatory = $false)][hashtable]$Variables = @{}
    )

    $outputQueue = New-Object System.Collections.Concurrent.ConcurrentQueue[object]
    $rs = Get-LauncherRunspace -ToolRoot $ToolRoot
    $rs.SessionStateProxy.SetVariable('OutputQueue', $outputQueue)
    foreach ($key in $Variables.Keys) {
        $rs.SessionStateProxy.SetVariable($key, $Variables[$key])
    }

    $ps = [powershell]::Create()
    $ps.Runspace = $rs
    [void]$ps.AddScript($Operation)
    $asyncResult = $ps.BeginInvoke()

    # A Hashtable, not a PSCustomObject: Stop-LauncherOperation needs to
    # mutate CancelRequested on this SAME handle instance afterward, and
    # hashtable values are trivially mutable in place.
    return @{
        PowerShell      = $ps
        Runspace        = $rs
        AsyncResult     = $asyncResult
        OutputQueue     = $outputQueue
        CancelRequested = $false
    }
}

function Test-LauncherOperationComplete {
    param([Parameter(Mandatory = $true)]$Handle)
    return $Handle.AsyncResult.IsCompleted
}

<#
.SYNOPSIS
    Call only once Test-LauncherOperationComplete is $true. Always
    disposes the runspace/powershell instance, even on failure.

.OUTPUTS
    Hashtable: @{ Success; Cancelled; Result; Error }. Cancelled is
    distinct from a plain failure — the GUI shows "لغو شد" for it, not
    "ناموفق"; it is only ever $true when Stop-LauncherOperation was
    actually called on this same handle (never inferred from matching
    the exception message, which could vary across PowerShell versions).
#>
function Complete-LauncherOperation {
    param([Parameter(Mandatory = $true)]$Handle)
    try {
        # [powershell]::EndInvoke() always returns a PSDataCollection —
        # unlike a plain PowerShell `return` inside a script, it does NOT
        # auto-collapse a single emitted object down to a bare scalar.
        # Every caller of this function expects "the operation's return
        # value" in the same shape they'd get from calling the scriptblock
        # directly (scalar for one object, $null for none, array only for
        # genuinely multiple objects) — normalize here, once, rather than
        # letting every call site rediscover this via `-eq` silently
        # comparing a string against a 1-element array (which throws a
        # type-conversion error, not a false-negative — confirmed
        # empirically while testing this very function).
        $rawResult = $Handle.PowerShell.EndInvoke($Handle.AsyncResult)
        $result = $rawResult
        if ($null -ne $rawResult -and $rawResult.Count -eq 1) {
            $result = $rawResult[0]
        } elseif ($null -ne $rawResult -and $rawResult.Count -eq 0) {
            $result = $null
        }
        $errorRecords = @($Handle.PowerShell.Streams.Error)
        if ($errorRecords.Count -gt 0) {
            $combined = ($errorRecords | ForEach-Object { $_.Exception.Message }) -join "`n"
            return @{ Success = $false; Cancelled = [bool]$Handle.CancelRequested; Result = $result; Error = $combined }
        }
        return @{ Success = $true; Cancelled = $false; Result = $result; Error = $null }
    } catch {
        return @{ Success = $false; Cancelled = [bool]$Handle.CancelRequested; Result = $null; Error = $_.Exception.Message }
    } finally {
        # $Handle.Runspace is the SHARED runspace (see Get-LauncherRunspace)
        # and stays open/reused for the next operation — only Close-LauncherRunspace,
        # called once when the Launcher window itself closes, ever closes it.
        try { $Handle.PowerShell.Dispose() } catch {}
    }
}

<#
.SYNOPSIS
    Requests a safe stop — see the file-level .NOTES on why this can
    never interrupt an in-flight external process mid-command.
#>
function Stop-LauncherOperation {
    param([Parameter(Mandatory = $true)]$Handle)
    $Handle.CancelRequested = $true
    try { $Handle.PowerShell.Stop() } catch {}
}

<#
.SYNOPSIS
    Drains every currently-queued output item without blocking — call
    from the UI thread's Timer tick.

.NOTES
    CALLERS: capture this as `$x = Receive-LauncherOperationOutput ...` or
    `$list.AddRange((Receive-LauncherOperationOutput ...))` — plain
    parens, NEVER an extra `@(...)` wrap around the call. The function
    itself already guarantees a flat array back (see the comma-operator
    note below); wrapping the call site in `@()` on top of that nests the
    already-array return value one level deeper (`Object[]` of one
    `Object[]` instead of a flat `Object[]` of hashtables) — confirmed
    empirically, it silently produces elements whose .Type/.Text
    properties don't exist because the element is itself an array, not a
    hashtable.
#>
function Receive-LauncherOperationOutput {
    param([Parameter(Mandatory = $true)]$Handle)
    # The unary comma (",") below forces PowerShell to emit the array as
    # ONE pipeline object instead of unrolling it — without it, a
    # zero-item result collapses to $null and a one-item result unwraps
    # to a bare scalar on the caller's side, both breaking any caller
    # that always expects an array back. Confirmed empirically.
    $items = New-Object System.Collections.Generic.List[object]
    $item = $null
    while ($Handle.OutputQueue.TryDequeue([ref]$item)) {
        $items.Add($item)
    }
    return ,$items.ToArray()
}

<#
.SYNOPSIS
    The full "ارسال به Git و سرور" flow for ONE project — mirrors
    SimpleDeploy.ps1's own top-level try/finally around
    Suspend-DeploymentService/Invoke-ProjectDeploy/Resume-DeploymentService
    exactly (same functions, same order, same safety guarantees already
    proven for the CLI entry point), just scoped to a single project
    instead of a whole selected batch. Meant to run INSIDE a
    Start-LauncherOperation runspace — never call this directly from the
    UI thread.
#>
function Invoke-LauncherDeployFlow {
    param(
        [Parameter(Mandatory = $true)]$ProjectInfo,
        [Parameter(Mandatory = $true)]$Config,
        [Parameter(Mandatory = $true)][string]$ToolRoot,
        [Parameter(Mandatory = $true)][string]$StateDir,
        [Parameter(Mandatory = $false)][string]$CommitMessage
    )

    $deploymentServiceCfg = $Config.deployment_service
    $suspendResult = Suspend-DeploymentService `
        -TaskNames $deploymentServiceCfg.windows_scheduled_tasks `
        -ProcessNames $deploymentServiceCfg.windows_process_names `
        -CommandLineMarker $deploymentServiceCfg.windows_process_commandline_marker `
        -StateDir $StateDir `
        -ProcessExitTimeoutSeconds $deploymentServiceCfg.linux_service_stop_timeout_seconds

    try {
        return Invoke-ProjectDeploy -ProjectInfo $ProjectInfo -CommitMessage $CommitMessage `
            -NonInteractive $true -DryRun $false `
            -DeploymentServiceCfg $deploymentServiceCfg -SshCfg $Config.ssh -ScriptRoot $ToolRoot
    } finally {
        Resume-DeploymentService -PausedTaskNames $suspendResult.PausedTaskNames -StateDir $StateDir
    }
}

<#
.SYNOPSIS
    Read-only summary of the Windows Scheduled Task side of the
    Deployment Service, for the small status indicator in the UI. Never
    changes anything. $PausedByThisRun lets the caller show the
    "موقتاً متوقف‌شده توسط این برنامه" state distinctly from "already
    stopped for some other reason".
#>
function Get-DeploymentServiceDisplayStatus {
    param(
        [Parameter(Mandatory = $true)]$DeploymentServiceCfg,
        [Parameter(Mandatory = $false)][bool]$PausedByThisRun = $false
    )

    if ($PausedByThisRun) {
        return @{ Text = 'موقتاً متوقف‌شده توسط این برنامه'; Color = 'Yellow' }
    }

    $anyRunning = $false
    $anyExists = $false
    foreach ($taskName in $DeploymentServiceCfg.windows_scheduled_tasks) {
        $state = Get-WindowsScheduledTaskState -TaskName $taskName
        if ($state.Exists) {
            $anyExists = $true
            if ($state.State -eq 'Running') { $anyRunning = $true }
        }
    }

    if (-not $anyExists) {
        return @{ Text = 'یافت نشد'; Color = 'Gray' }
    }
    if ($anyRunning) {
        return @{ Text = 'فعال'; Color = 'Green' }
    }
    return @{ Text = 'متوقف'; Color = 'Gray' }
}
