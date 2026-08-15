<#
.SYNOPSIS
    Tests: a REAL WinForms Application.Run + PerformClick pass through
    Show-SimpleDeployLauncher itself (not a re-implementation of the GUI
    logic) — proving the actual "بررسی و توقف در حالت Running" (Launcher
    hang) bug stays fixed: after a real button click drives a real
    background operation through the real Engine.ps1/pollTimer machinery,
    the window returns to its released state (buttons re-enabled,
    $GuiState.CurrentHandle cleared) within a bounded time, for both a
    successful and a failing real (local bare-repo, never GitHub/VPS)
    git-only flow — and that the internal DEBUG lifecycle log records
    the full RUNSPACE STARTED -> RUNSPACE COMPLETED -> ENDINVOKE CALLED
    -> GUI STATE RELEASED -> ONCOMPLETE CALLED sequence for both the
    real background Check and the real background Push.

.NOTES
    Show-SimpleDeployLauncher's own Application.Run($form) call blocks
    the calling thread until the form closes — so the driving logic
    (click the button, wait for release, then close the form) is
    supplied via -TestAutomationHook, invoked with the real ($form,
    $GuiState) right before Application.Run starts. The hook registers
    its OWN Timers that perform a real PerformClick() and then poll for
    release before closing the form — genuinely ending Application.Run,
    never force-killing the process.

    Show-LauncherConfirm/New-LauncherInputDialog are real, native modal
    dialogs (MessageBox.Show / a WinForms Form) that block whatever
    thread shows them until a human answers — there is no human here.
    Rather than fake keystrokes (proven unreliable in practice: a
    blindly-sent {ENTER} can land on whichever control happens to have
    default focus before the intended click even fires, triggering the
    wrong action entirely), these two functions are redefined AFTER
    dot-sourcing the real Gui.ps1 to auto-answer exactly what a human
    clicking "Yes"/accepting the suggested commit message would — a
    standard test-double for the one genuinely-interactive dependency;
    every other part of the flow (the click, the background Check, the
    background Push, Engine.ps1, pollTimer, the debug log) runs for
    real.
#>

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ToolRoot = Split-Path -Parent $ScriptDir
. (Join-Path $ScriptDir 'TestHelpers.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Common.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Config.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.ServiceControl.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Preflight.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Git.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Build.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.ArtifactValidation.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Transfer.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Timing.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.DeploymentState.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Orchestration.ps1')
. (Join-Path $ToolRoot 'launcher\SimpleDeployLauncher.Status.ps1')
. (Join-Path $ToolRoot 'launcher\SimpleDeployLauncher.GitFlow.ps1')
. (Join-Path $ToolRoot 'launcher\SimpleDeployLauncher.Log.ps1')
. (Join-Path $ToolRoot 'launcher\SimpleDeployLauncher.Engine.ps1')
. (Join-Path $ToolRoot 'launcher\SimpleDeployLauncher.Gui.ps1')

function Show-LauncherConfirm {
    param([Parameter(Mandatory = $true)][string]$Text, [Parameter(Mandatory = $false)][string]$Title = 'SimpleDeploy')
    return $true
}
function New-LauncherInputDialog {
    param([Parameter(Mandatory = $true)][string]$Prompt, [Parameter(Mandatory = $false)][string]$DefaultValue = '', [Parameter(Mandatory = $false)][string]$Title = 'SimpleDeploy')
    return $DefaultValue
}
# The failure-path test below deliberately triggers a real push
# rejection — Show-LauncherError's real MessageBox.Show() would then
# block with nobody to click "OK", exactly like the two functions above.
# Stubbed the same way, purely so the test can observe the release
# without a human at the keyboard; the real Show-LauncherError is never
# touched.
function Show-LauncherError {
    param([Parameter(Mandatory = $true)][string]$Text, [Parameter(Mandatory = $false)][string]$Title = 'SimpleDeploy')
}
function Show-LauncherInfo {
    param([Parameter(Mandatory = $true)][string]$Text, [Parameter(Mandatory = $false)][string]$Title = 'SimpleDeploy')
}

function New-GuiFlowFixtureProject {
    param(
        [Parameter(Mandatory = $true)][string]$RepoPath,
        [Parameter(Mandatory = $false)][string]$Branch = 'main',
        [Parameter(Mandatory = $false)][bool]$PushWillSucceed = $true
    )
    Initialize-TestGitRepo -Path $RepoPath -Branch $Branch
    'v1' | Set-Content -LiteralPath (Join-Path $RepoPath 'app.txt') -Encoding ASCII -NoNewline
    & git.exe -C $RepoPath add -- app.txt 2>&1 | Out-Null
    & git.exe -C $RepoPath commit -m 'initial' --quiet 2>&1 | Out-Null

    $bareRemotePath = Join-Path (Split-Path -Parent $RepoPath) "$(Split-Path -Leaf $RepoPath)-remote.git"
    & git.exe init --quiet --bare $bareRemotePath 2>&1 | Out-Null

    if ($PushWillSucceed) {
        & git.exe -C $RepoPath remote add origin $bareRemotePath 2>&1 | Out-Null
        & git.exe -C $RepoPath push --quiet origin ${Branch}:${Branch} 2>&1 | Out-Null
    } else {
        # A real, deterministic, fully-local, non-retryable rejection —
        # never a real network host and never a slow DNS/connection-
        # timeout class error (which Invoke-GitPushWithRetry would retry
        # for real, on the real 5s/15s/30s production schedule, making a
        # bounded test impossible): seed the bare remote's own history,
        # via a separate throwaway clone, with a commit our fixture repo
        # never had as an ancestor. Our repo's own push is then rejected
        # as non-fast-forward — fast, deterministic, and fully offline.
        $seedClone = Join-Path (Split-Path -Parent $RepoPath) "$(Split-Path -Leaf $RepoPath)-seed"
        & git.exe clone --quiet $bareRemotePath $seedClone 2>&1 | Out-Null
        & git.exe -C $seedClone checkout --quiet -b $Branch 2>&1 | Out-Null
        & git.exe -C $seedClone config user.email 'test@example.test' 2>&1 | Out-Null
        & git.exe -C $seedClone config user.name 'SimpleDeploy Test' 2>&1 | Out-Null
        'unrelated' | Set-Content -LiteralPath (Join-Path $seedClone 'other.txt') -Encoding ASCII -NoNewline
        & git.exe -C $seedClone add -- other.txt 2>&1 | Out-Null
        & git.exe -C $seedClone commit -m 'unrelated seed commit' --quiet 2>&1 | Out-Null
        & git.exe -C $seedClone push --quiet origin "${Branch}:${Branch}" 2>&1 | Out-Null
        & git.exe -C $RepoPath remote add origin $bareRemotePath 2>&1 | Out-Null
    }

    # A real, unstaged change so "ارسال به Git" has something to commit
    # and push on this run (a clean tree with nothing ahead would leave
    # the Check step alone with no real background Git operation to
    # drive through Engine.ps1/pollTimer).
    'v2' | Set-Content -LiteralPath (Join-Path $RepoPath 'app.txt') -Encoding ASCII -NoNewline
}

function New-GuiFlowFixtureConfig {
    param([Parameter(Mandatory = $true)][string]$ConfigPath, [Parameter(Mandatory = $true)][string]$RepoPath, [Parameter(Mandatory = $false)][string]$Branch = 'main')
    $cfg = [PSCustomObject]@{
        deployment_service = [PSCustomObject]@{
            windows_scheduled_tasks           = @()
            windows_process_names             = @()
            windows_process_commandline_marker = 'SimpleDeployGuiFlowTestMarker'
            linux_service_stop_timeout_seconds = 5
        }
        ssh      = [PSCustomObject]@{ known_hosts_path = (Join-Path (Split-Path -Parent $ConfigPath) 'known_hosts'); connect_timeout_seconds = 5 }
        projects = [PSCustomObject]@{
            'guiflow-project' = [PSCustomObject]@{
                enabled = $true; local_path = $RepoPath; branch = $Branch; remote = 'origin'
                build_mode = 'disabled'; activation_mode = 'working_directory'
                server_host = 'CHANGE_ME'; server_user = 'CHANGE_ME'; server_path = '/x'; ssh_key_path = 'CHANGE_ME'
                health_check_url = 'CHANGE_ME'; preserve_paths = @('.env'); retention_count = 3
                run_migrations = $false; database_backup = $false
                composer_command = $null; npm_command = $null; php_binary = $null
            }
        }
    }
    ($cfg | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $ConfigPath -Encoding ASCII
}

<#
.SYNOPSIS
    Runs Show-SimpleDeployLauncher for real (real Application.Run, real
    Engine.ps1 runspace, real Git subprocess) against a throwaway
    ToolRoot copy (own state/logs/config dirs — never the developer's
    own real config.json/state), clicks the given project's "GitButton"
    once the window is up, and returns what happened.
#>
function Invoke-RealGuiFlowClick {
    param(
        [Parameter(Mandatory = $true)][string]$FixtureToolRoot,
        [Parameter(Mandatory = $true)][string]$ConfigPath,
        [Parameter(Mandatory = $false)][int]$TimeoutSeconds = 30
    )

    # A plain hashtable, not [hashtable]::Synchronized() -- everything
    # that touches $observed (the click Timer, the release-poll Timer,
    # the real pollTimer inside Show-SimpleDeployLauncher) runs on this
    # SAME UI thread via the SAME message loop, so no real cross-thread
    # access ever happens and no synchronization is needed. Confirmed
    # empirically that the synchronized wrapper actively breaks this:
    # PowerShell's dot-notation property SET (`$observed.ButtonClicked =
    # $true`) threw "The property 'ButtonClicked' cannot be found on
    # this object" against the Synchronized() wrapper, silently caught by
    # Show-SimpleDeployLauncher's own Application.ThreadException safety
    # net (an undismissed MessageBox nobody was there to click), which is
    # exactly why the click appeared to do nothing until this test's own
    # timeout closed the window.
    $observed = @{
        ButtonClicked      = $false
        ReleasedInTime     = $false
        HandleClearedAtEnd = $false
        ButtonsReenabled   = $false
        DebugLogPath       = (Join-Path $FixtureToolRoot 'state\launcher-debug.log')
        Trace              = New-Object System.Collections.Generic.List[string]
    }

    $hook = {
        param($form, $GuiState)
        # $observed is stored INSIDE $GuiState (as __Observed), not
        # captured directly by the nested Timer closures below. $GuiState
        # arrives as a real parameter binding (bound fresh each time this
        # hook itself is invoked) and is proven to survive being read AND
        # written from deeply-nested `.GetNewClosure()` handlers -- the
        # real pollTimer inside Show-SimpleDeployLauncher does exactly
        # this constantly. $observed, by contrast, would otherwise reach
        # these Timer closures only via a SECOND layer of
        # `.GetNewClosure()` (this hook's own closure capturing it, then
        # each Timer's closure capturing it again) -- confirmed
        # empirically that a Hashtable captured through two nested
        # GetNewClosure layers like that loses its normal Hashtable
        # identity by the time it reaches the innermost closure: plain
        # dot-notation property SET on it then throws "The property ...
        # cannot be found on this object", the standard error for setting
        # an undeclared property on a fixed-schema object, not a
        # Hashtable. Routing through $GuiState sidesteps the whole issue
        # by reusing the one path already proven to work.
        $GuiState.__Observed = $observed
        $observed.Trace.Add('hook invoked')

        $clickTimer = New-Object System.Windows.Forms.Timer
        $clickTimer.Interval = 300
        $clickTimer.Add_Tick({
            $clickTimer.Stop()
            $row = $GuiState.GuiRows['guiflow-project']
            if ($row -and $row.GitButton.Enabled) {
                $GuiState.__Observed.ButtonClicked = $true
                $row.GitButton.PerformClick()
                $GuiState.__Observed.Trace.Add('PerformClick returned')
            } else {
                $GuiState.__Observed.Trace.Add("click skipped; rowFound=$($null -ne $row) enabled=$(if ($row) { $row.GitButton.Enabled } else { 'n/a' })")
            }
        }.GetNewClosure())
        # Anchored on $GuiState (guaranteed to stay rooted for as long as
        # Application.Run($form) is pumping — the real pollTimer's own
        # closures read/write it constantly) so the .NET GC can never
        # collect this Timer once this hook's own single, synchronous
        # invocation returns. A local variable alone is NOT enough: unlike
        # the real pollTimer (rooted on Show-SimpleDeployLauncher's own
        # still-executing stack frame the whole time), this hook's stack
        # frame returns immediately after .Start() below, and a
        # System.Windows.Forms.Timer holds no other strong reference to
        # itself — confirmed empirically as a real cause of the click
        # never firing before anchoring on $GuiState.
        $GuiState.__TestClickTimer = $clickTimer
        $clickTimer.Start()

        # A second Timer (independent of the app's own pollTimer) polls
        # for release and then closes the form — this is the test's own
        # waiting/assertion mechanism, not a modification of the real
        # completion logic under test.
        $releaseTimer = New-Object System.Windows.Forms.Timer
        $releaseTimer.Interval = 100
        $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
        $releaseTimer.Add_Tick({
            if ($GuiState.__Observed.ButtonClicked -and -not $GuiState.CurrentHandle -and -not $GuiState.PollTickBusy) {
                $GuiState.__Observed.ReleasedInTime = $true
                $GuiState.__Observed.HandleClearedAtEnd = ($null -eq $GuiState.CurrentHandle)
                $row = $GuiState.GuiRows['guiflow-project']
                $GuiState.__Observed.ButtonsReenabled = ($row.GitButton.Enabled -and $row.CheckButton.Enabled)
                $releaseTimer.Stop()
                $form.Close()
                return
            }
            if ((Get-Date) -gt $deadline) {
                $releaseTimer.Stop()
                $form.Close()
                return
            }
        }.GetNewClosure())
        $GuiState.__TestReleaseTimer = $releaseTimer
        $releaseTimer.Start()
    }.GetNewClosure()

    # Run in-process (not a subprocess) so $observed (a synchronized
    # hashtable, safe for this same-process Timer-callback use) is
    # directly readable afterward — Show-SimpleDeployLauncher's own
    # Application.Run blocks this call until $form.Close() above runs.
    Show-SimpleDeployLauncher -ToolRoot $FixtureToolRoot -ConfigPath $ConfigPath -TestAutomationHook $hook

    return $observed
}

# ============================================================
# Shared fixture ToolRoot: a real copy of the launcher/lib/scripts
# directories (Show-SimpleDeployLauncher's own dot-sourcing is relative
# to -ToolRoot) with a throwaway state/ and logs/ directory — never the
# developer's own real tools/simple-deployer/config.json or state/.
# ============================================================
function New-FixtureToolRoot {
    $fixtureRoot = New-TestTempDirectory -Prefix 'sdtest-guiflow-toolroot'
    foreach ($dirName in @('launcher', 'lib', 'scripts')) {
        Copy-Item -LiteralPath (Join-Path $ToolRoot $dirName) -Destination (Join-Path $fixtureRoot $dirName) -Recurse -Force
    }
    New-Item -ItemType Directory -Path (Join-Path $fixtureRoot 'state') -Force | Out-Null
    New-Item -ItemType Directory -Path (Join-Path $fixtureRoot 'logs') -Force | Out-Null
    return $fixtureRoot
}

Add-Type -AssemblyName System.Windows.Forms
Add-Type -AssemblyName System.Drawing

# ============================================================
# 1. Successful "ارسال به Git" click: real bare-repo push succeeds ->
#    the real completion path (RUNSPACE STARTED -> RUNSPACE COMPLETED ->
#    ENDINVOKE CALLED -> GUI STATE RELEASED -> ONCOMPLETE CALLED) runs
#    for both the background Check and the background Push,
#    CurrentHandle is cleared, and both buttons are re-enabled, all
#    within the timeout — the exact "does not leave Running" guarantee
#    under a real Application.Run + PerformClick test.
# ============================================================
$fixture1 = New-FixtureToolRoot
$repo1 = Join-Path $fixture1 'repo'
New-GuiFlowFixtureProject -RepoPath $repo1 -PushWillSucceed $true
$config1 = Join-Path $fixture1 'config.json'
New-GuiFlowFixtureConfig -ConfigPath $config1 -RepoPath $repo1

$result1 = Invoke-RealGuiFlowClick -FixtureToolRoot $fixture1 -ConfigPath $config1 -TimeoutSeconds 30
if (-not $result1.ReleasedInTime) {
    Write-Host "TRACE1: $($result1.Trace -join ' | ')" -ForegroundColor Yellow
    $rt1 = Join-Path $fixture1 'raw-trace.log'
    if (Test-Path -LiteralPath $rt1) { Write-Host "RAWTRACE1:`n$(Get-Content -LiteralPath $rt1 -Raw)" -ForegroundColor Yellow }
}

Assert-That 'success case: the GitButton was actually clicked (PerformClick reached)' $result1.ButtonClicked
Assert-That 'success case: the Launcher released back to Ready within the timeout (never stuck Running)' $result1.ReleasedInTime
Assert-That 'success case: $GuiState.CurrentHandle was cleared after completion' $result1.HandleClearedAtEnd
Assert-That 'success case: buttons were re-enabled after completion' $result1.ButtonsReenabled

$pushedLog1 = & git.exe --git-dir (Join-Path $fixture1 'repo-remote.git') log main --oneline 2>&1 | Out-String
Assert-That 'success case: the commit was genuinely pushed to the real local bare remote (2 commits on main)' (@($pushedLog1 -split "`n" | Where-Object { $_.Trim() -ne '' }).Count -eq 2)

if (Test-Path -LiteralPath $result1.DebugLogPath) {
    $debugLog1 = Get-Content -LiteralPath $result1.DebugLogPath -Raw
    Assert-That 'success case: the internal debug log recorded RUNSPACE STARTED at least twice (Check + Push)' (@([regex]::Matches($debugLog1, 'RUNSPACE STARTED')).Count -ge 2)
    Assert-That 'success case: the internal debug log recorded RUNSPACE COMPLETED at least twice (Check + Push)' (@([regex]::Matches($debugLog1, 'RUNSPACE COMPLETED')).Count -ge 2)
    Assert-That 'success case: the internal debug log recorded ENDINVOKE CALLED' ($debugLog1 -match 'ENDINVOKE CALLED')
    Assert-That 'success case: the internal debug log recorded GUI STATE RELEASED' ($debugLog1 -match 'GUI STATE RELEASED')
    Assert-That 'success case: the internal debug log recorded ONCOMPLETE CALLED' ($debugLog1 -match 'ONCOMPLETE CALLED')
} else {
    Assert-That 'success case: the internal debug log file was created' $false
}

Remove-TestTempDirectory -Path $fixture1

# ============================================================
# 2. Failing "ارسال به Git" click: a real, local, non-fast-forward push
#    rejection -> the Launcher must STILL release (buttons re-enabled,
#    CurrentHandle cleared), never stay stuck Running just because the
#    underlying operation failed.
# ============================================================
$fixture2 = New-FixtureToolRoot
$repo2 = Join-Path $fixture2 'repo'
New-GuiFlowFixtureProject -RepoPath $repo2 -PushWillSucceed $false
$config2 = Join-Path $fixture2 'config.json'
New-GuiFlowFixtureConfig -ConfigPath $config2 -RepoPath $repo2

$result2 = Invoke-RealGuiFlowClick -FixtureToolRoot $fixture2 -ConfigPath $config2 -TimeoutSeconds 30
if (-not $result2.ReleasedInTime) {
    Write-Host "TRACE2: $($result2.Trace -join ' | ')" -ForegroundColor Yellow
    if (Test-Path -LiteralPath $result2.DebugLogPath) { Write-Host "DEBUGLOG2:`n$(Get-Content -LiteralPath $result2.DebugLogPath -Raw)" -ForegroundColor Yellow }
}

Assert-That 'failure case: the GitButton was actually clicked' $result2.ButtonClicked
Assert-That 'failure case: the Launcher still released back to Ready within the timeout despite the push failing' $result2.ReleasedInTime
Assert-That 'failure case: $GuiState.CurrentHandle was cleared after completion' $result2.HandleClearedAtEnd
Assert-That 'failure case: buttons were re-enabled after completion (never left disabled on failure)' $result2.ButtonsReenabled

if (Test-Path -LiteralPath $result2.DebugLogPath) {
    $debugLog2 = Get-Content -LiteralPath $result2.DebugLogPath -Raw
    Assert-That 'failure case: the internal debug log still recorded GUI STATE RELEASED despite the failure' ($debugLog2 -match 'GUI STATE RELEASED')
}

Remove-TestTempDirectory -Path $fixture2

Write-TestSummaryAndExit -SuiteName 'Test-LauncherGuiFlow'
