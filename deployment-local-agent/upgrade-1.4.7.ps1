<#
Local Agent 1.4.6 -> 1.4.7 upgrade bridge, with an exact 1.4.6 backup.

Same DryRun/Activate/Rollback architecture as upgrade-1.5.0.ps1 (backup +
SHA-256 manifest, isolated staging, config.php byte-identity check,
atomic-ish swap, attestation-gated rollback). The one structural
difference: upgrade-1.5.0.ps1 sources its candidate files from its own
sibling 'agent' folder (the current, live package tree - already at
1.5.0+). This script must never do that, since there is no live 1.4.7
package tree anymore. Instead it extracts the exact, pinned historical
commit's package tree via `git archive` - a read-only Git primitive that
never touches the working tree, index, or HEAD, and cannot be affected by
uncommitted local changes.

Historical commit provenance: 12b49b5131120a6af0f66542d3658460a2fdd4ee
("Synchronize artifact source before build") is the commit that
introduced the 1.4.7 version label and the trusted-workspace security fix
(WorkspaceSourcePreparer::assertTrustedPersistentWorkspace /
assertLocalBranchNotDiverged) this bridge exists to deliver. But it is
NOT the final 1.4.7 state - three more real commits landed against
deployment-local-agent/agent/ while the version constant still read
1.4.7, before 0ded41a bumped it to 1.5.0:
  c8401c6 "Prevent duplicate exact-commit artifact builds"
  25d4976 "Finalize provider-neutral release preparation flow"
  72d8850 "feat(agent): add read-only workspace inventory scanner"
The genuine, complete, final 1.4.7 package tree is therefore the state at
0ded41a's parent commit, a12869c445d3cc5a0b89dc78b8479617ecac42ce (that
commit itself never touches deployment-local-agent/agent/ - its tree for
that path is byte-identical to 72d8850's - it is simply the exact
boundary commit immediately before the version number changed again).
That is the SHA pinned below. Never 12b49b5 alone, and never the current
working tree.
#>
[CmdletBinding()]
param(
    [ValidateSet('DryRun','Activate','Rollback')][string]$Action = 'DryRun',
    [Parameter(Mandatory=$true)][ValidatePattern('^[A-Za-z0-9._-]+$')][string]$RunId,
    [string]$InstallDir = 'C:\ProgramData\DeploymentLocalAgent',
    [string]$StateRoot = '',
    [string]$RollbackAttestation = '',
    [string]$RollbackAttestationSha256 = '',
    [switch]$TestMode,
    [string]$RepoRoot = '',
    [int]$StopTimeoutSeconds = 30
)
$ErrorActionPreference = 'Stop'

$SourceVersion = '1.4.6'
$TargetVersion = '1.4.7'
$HistoricalCommit = 'a12869c445d3cc5a0b89dc78b8479617ecac42ce'
$HistoricalPathPrefix = 'deployment-local-agent/agent'

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
if (-not $RepoRoot) { $RepoRoot = Split-Path -Parent $ScriptDir }
$AgentDir = Join-Path $InstallDir 'agent'
if (-not $StateRoot) { $StateRoot = Join-Path $InstallDir 'storage\upgrades-1.4.7' }
$RunRoot = Join-Path $StateRoot $RunId
$Backup = Join-Path $RunRoot 'backup'
$Stage = Join-Path $RunRoot 'staging'
$HistoricalExtract = Join-Path $RunRoot 'historical-1.4.7'
$Done = Join-Path $RunRoot ($Action.ToLowerInvariant() + '.done')

function Get-AgentVersion([string]$Path) {
    $m = Select-String -Path $Path -Pattern "LOCAL_AGENT_VERSION = '([^']+)'" | Select-Object -First 1
    if (-not $m) { throw "Agent version could not be read from $Path." }
    $m.Matches[0].Groups[1].Value
}
function Get-TreeHashes([string]$Root) {
    $result = [ordered]@{}
    Get-ChildItem -LiteralPath $Root -File -Recurse | Sort-Object FullName | ForEach-Object {
        $relative = $_.FullName.Substring($Root.Length).TrimStart('\','/').Replace('\','/')
        $result[$relative] = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    }
    $result
}
function Restart-Once {
    $restartMarker = Join-Path $RunRoot ($Action.ToLowerInvariant() + '.restart.done')
    if (Test-Path -LiteralPath $restartMarker) { return }
    if ($TestMode) {
        Add-Content -LiteralPath (Join-Path $StateRoot 'restart.log') -Value $RunId
    } else {
        schtasks /End /TN DeploymentLocalAgent 2>$null | Out-Null
        schtasks /Run /TN DeploymentLocalAgent | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Local Agent task restart failed.' }
    }
    New-Item -ItemType File -Path $restartMarker -Force | Out-Null
}

# Reads the live agent.pid the running Local Agent wrote for itself at
# startup (agent.php: `file_put_contents($pidFilePath, getmypid())`, the
# same file the watchdog already trusts, per its own comment: "the watchdog
# always re-validates the PID still belongs to a php.exe process before
# acting on it"). Returns $null rather than throwing when absent/unreadable
# - a missing or unparsable pid file just means there is nothing to wait on.
function Get-CapturedAgentPid([string]$AgentDirPath) {
    $pidPath = Join-Path $AgentDirPath 'storage\agent.pid'
    if (-not (Test-Path -LiteralPath $pidPath)) { return $null }
    $raw = (Get-Content -LiteralPath $pidPath -Raw -ErrorAction SilentlyContinue)
    if (-not $raw) { return $null }
    $raw = $raw.Trim()
    if ($raw -notmatch '^\d+$') { return $null }
    return [int]$raw
}

# Root-cause fix: Move-Item on a live AgentDir was failing with "The
# process cannot access the file because it is being used by another
# process" - the Scheduled Task's own php.exe still had the directory
# open. Stops the task, then verifies BOTH that Task Scheduler no longer
# reports it Running AND that the specific PID the agent itself recorded
# has actually exited, before returning control to the caller - never a
# fire-and-hope stop. Fails closed (throws) if either signal still shows
# the agent alive once the bounded timeout elapses; the live directory is
# never touched in that case, since this always runs strictly before the
# swap. In TestMode there is no real Scheduled Task to poll, so only the
# captured-PID liveness check is meaningful (a real PID a test controls
# directly) - this is what lets tests exercise the exact fail-closed path
# deterministically.
function Stop-AgentTaskAndWait {
    $capturedPid = Get-CapturedAgentPid $AgentDir

    if ($TestMode) {
        Add-Content -LiteralPath (Join-Path $StateRoot 'stop.log') -Value $RunId
    } else {
        schtasks /End /TN DeploymentLocalAgent 2>$null | Out-Null
    }

    $deadline = (Get-Date).AddSeconds($StopTimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        $taskStillRunning = (-not $TestMode) -and ((Get-ScheduledTask -TaskName 'DeploymentLocalAgent').State -eq 'Running')
        $processStillAlive = ($null -ne $capturedPid) -and ($null -ne (Get-Process -Id $capturedPid -ErrorAction SilentlyContinue))
        if (-not $taskStillRunning -and -not $processStillAlive) {
            return
        }
        Start-Sleep -Milliseconds 500
    }
    throw 'Local Agent task/process did not stop within the timeout - refusing to swap a live directory.'
}

# Starts the task only (the "Run" half of what Restart-Once used to do in
# one shot) - kept separate so the caller can poll for the new process's
# own health before declaring activation complete.
function Start-AgentTaskOnly {
    if ($TestMode) {
        Add-Content -LiteralPath (Join-Path $StateRoot 'restart.log') -Value $RunId
    } else {
        schtasks /Run /TN DeploymentLocalAgent | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Local Agent task start failed.' }
    }
}

# Extracts the exact, pinned historical commit's package tree via `git
# archive` into an isolated directory. Never runs checkout/reset/switch,
# never reads the live working tree, and cannot be affected by the 19
# pre-existing uncommitted WIP items elsewhere in the repo. Fails closed
# if the commit does not resolve exactly, or if the extracted agent.php
# does not itself report the expected target version - the second check
# is what makes it structurally impossible for a future accidental change
# to this script (e.g. a bad copy-paste of $PackageDir/agent as the
# source) to silently ship current files under the 1.4.7 label.
function Get-HistoricalPayload([string]$DestDir) {
    if (-not (Test-Path -LiteralPath (Join-Path $RepoRoot '.git'))) {
        throw "RepoRoot '$RepoRoot' is not a Git checkout - cannot extract the historical 1.4.7 payload."
    }
    if (Test-Path -LiteralPath $DestDir) { throw "Historical extraction path already exists: $DestDir" }
    New-Item -ItemType Directory -Path $DestDir -Force | Out-Null

    $resolved = (& git -C $RepoRoot rev-parse "$HistoricalCommit^{commit}" 2>$null)
    if ($resolved) { $resolved = $resolved.Trim() }
    if ($LASTEXITCODE -ne 0 -or $resolved -ne $HistoricalCommit) {
        throw "Historical commit $HistoricalCommit could not be resolved exactly in this checkout (got '$resolved')."
    }

    # `git archive -o <path>` refuses an output path outside the repository
    # working tree (our staging area is deliberately outside it, under
    # InstallDir/StateRoot) - so stdout is captured as a raw binary stream
    # via .NET directly instead, sidestepping both that restriction and
    # PowerShell's own text-pipeline byte-mangling of binary command output.
    $zipPath = Join-Path $DestDir 'payload.zip'
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = 'git'
    $psi.Arguments = "archive --format=zip $HistoricalCommit -- $HistoricalPathPrefix"
    $psi.WorkingDirectory = $RepoRoot
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $proc = [System.Diagnostics.Process]::Start($psi)
    $outStream = [System.IO.File]::Create($zipPath)
    $proc.StandardOutput.BaseStream.CopyTo($outStream)
    $outStream.Close()
    $stderr = $proc.StandardError.ReadToEnd()
    $proc.WaitForExit()
    if ($proc.ExitCode -ne 0 -or -not (Test-Path -LiteralPath $zipPath)) {
        throw "git archive failed to produce the historical 1.4.7 payload: $stderr"
    }

    Expand-Archive -LiteralPath $zipPath -DestinationPath $DestDir -Force
    Remove-Item -LiteralPath $zipPath -Force
    $extractedAgentDir = Join-Path $DestDir ($HistoricalPathPrefix.Replace('/', [IO.Path]::DirectorySeparatorChar))
    if (-not (Test-Path -LiteralPath (Join-Path $extractedAgentDir 'agent.php'))) {
        throw 'Historical payload extraction did not produce an agent.php.'
    }
    $extractedVersion = Get-AgentVersion (Join-Path $extractedAgentDir 'agent.php')
    if ($extractedVersion -ne $TargetVersion) {
        throw "Historical payload reports version '$extractedVersion', expected '$TargetVersion' - refusing to proceed."
    }
    return $extractedAgentDir
}

if (-not (Test-Path -LiteralPath (Join-Path $AgentDir 'config.php'))) { throw 'Existing Local Agent config.php is required.' }

if ($Action -eq 'Rollback') {
    if (Test-Path -LiteralPath $Done) { Write-Output "ROLLBACK_ALREADY_DONE run_id=$RunId"; exit 0 }
    if (-not (Test-Path -LiteralPath $RollbackAttestation) -or $RollbackAttestationSha256 -notmatch '^[0-9a-f]{64}$') { throw 'A hashed Hub rollback attestation is required.' }
    if ((Get-FileHash -LiteralPath $RollbackAttestation -Algorithm SHA256).Hash.ToLowerInvariant() -ne $RollbackAttestationSha256) { throw 'Hub rollback attestation checksum mismatch.' }
    $attestation = Get-Content -LiteralPath $RollbackAttestation -Raw | ConvertFrom-Json
    if ($attestation.run_id -ne $RunId -or $attestation.policy_required -ne $false) { throw 'Hub rollback attestation does not match this run or policy-off state.' }
    $hashPath = Join-Path $Backup 'SHA256.json'
    if (-not (Test-Path -LiteralPath $hashPath)) { throw 'Exact Local Agent backup is missing.' }
    $expected = Get-Content -LiteralPath $hashPath -Raw | ConvertFrom-Json
    $actual = Get-TreeHashes (Join-Path $Backup 'runtime')
    $expectedProperties = @($expected.PSObject.Properties)
    if ($expectedProperties.Count -ne $actual.Count) { throw 'Local Agent backup checksum verification failed.' }
    foreach ($property in $expectedProperties) {
        if (-not $actual.Contains($property.Name) -or $actual[$property.Name] -ne [string]$property.Value) { throw 'Local Agent backup checksum verification failed.' }
    }
    $failed = Join-Path $RunRoot 'failed-1.4.7'
    if (Test-Path -LiteralPath $failed) { throw 'Failed-runtime preservation path already exists.' }
    Move-Item -LiteralPath $AgentDir -Destination $failed
    Copy-Item -LiteralPath (Join-Path $Backup 'runtime') -Destination $AgentDir -Recurse
    if ((Get-AgentVersion (Join-Path $AgentDir 'agent.php')) -ne $SourceVersion) { throw "Rollback did not restore Local Agent $SourceVersion." }
    Restart-Once
    New-Item -ItemType File -Path $Done -Force | Out-Null
    Write-Output "ROLLBACK_OK run_id=$RunId restored_version=$SourceVersion backup=$Backup"
    exit 0
}

if ((Get-AgentVersion (Join-Path $AgentDir 'agent.php')) -ne $SourceVersion) { throw "Active Local Agent must be $SourceVersion." }
if (Test-Path -LiteralPath $Backup) { throw 'This run_id already owns a backup.' }
New-Item -ItemType Directory -Path $Backup -Force | Out-Null
Copy-Item -LiteralPath $AgentDir -Destination (Join-Path $Backup 'runtime') -Recurse
$backupHashes = Get-TreeHashes (Join-Path $Backup 'runtime')
$backupHashes | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $Backup 'SHA256.json') -Encoding utf8
@{ run_id = $RunId; source_version = $SourceVersion; target_version = $TargetVersion; historical_commit = $HistoricalCommit } |
    ConvertTo-Json | Set-Content -LiteralPath (Join-Path $Backup 'metadata.json') -Encoding utf8

$historicalAgentDir = Get-HistoricalPayload $HistoricalExtract

Copy-Item -LiteralPath (Join-Path $Backup 'runtime') -Destination $Stage -Recurse
Copy-Item -LiteralPath (Join-Path $historicalAgentDir 'agent.php') -Destination $Stage -Force
Copy-Item -LiteralPath (Join-Path $historicalAgentDir 'heartbeat-watchdog.php') -Destination $Stage -Force
Get-ChildItem -LiteralPath (Join-Path $historicalAgentDir 'lib') -File -Filter '*.php' | ForEach-Object {
    Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $Stage 'lib') -Force
}
if ((Get-AgentVersion (Join-Path $Stage 'agent.php')) -ne $TargetVersion) { throw 'Staged Local Agent version is invalid.' }
if ((Get-FileHash -LiteralPath (Join-Path $AgentDir 'config.php')).Hash -ne (Get-FileHash -LiteralPath (Join-Path $Stage 'config.php')).Hash) { throw 'config.php changed in staging.' }
Get-TreeHashes $Stage | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $RunRoot 'CANDIDATE-SHA256.json') -Encoding utf8

if ($Action -eq 'DryRun') {
    Remove-Item -LiteralPath $Stage -Recurse -Force
    Remove-Item -LiteralPath $HistoricalExtract -Recurse -Force
    New-Item -ItemType File -Path $Done -Force | Out-Null
    Write-Output "DRY_RUN_OK run_id=$RunId baseline=$SourceVersion target=$TargetVersion historical_commit=$HistoricalCommit backup=$Backup"
    exit 0
}
if (Test-Path -LiteralPath $Done) { Write-Output "ACTIVATION_ALREADY_DONE run_id=$RunId"; exit 0 }

$old = Join-Path $RunRoot 'pre-activation'
$agentDirMoved = $false

try {
    # Steps 1-4 (preflight/backup/candidate/validation) are already fully
    # complete by this point. Only now, after every pre-swap check has
    # passed, do we touch the live Scheduled Task.
    Stop-AgentTaskAndWait

    Move-Item -LiteralPath $AgentDir -Destination $old
    $agentDirMoved = $true
    Move-Item -LiteralPath $Stage -Destination $AgentDir

    if ((Get-FileHash -LiteralPath (Join-Path $AgentDir 'config.php')).Hash -ne (Get-FileHash -LiteralPath (Join-Path $old 'config.php')).Hash) {
        throw 'config.php changed across the swap.'
    }

    # Test-only failure injection, double-gated so it can never fire
    # outside a deliberately-constructed test: proves the automatic
    # rollback below actually restores 1.4.6 from a genuine post-swap
    # failure, not merely from a pre-swap one.
    if ($TestMode -and (Test-Path -LiteralPath (Join-Path $RunRoot 'TEST-FORCE-POST-SWAP-FAILURE'))) {
        throw 'TEST-INJECTED post-swap failure.'
    }

    Start-AgentTaskOnly

    $deadline = (Get-Date).AddSeconds($StopTimeoutSeconds)
    $verified = $false
    while ((Get-Date) -lt $deadline) {
        $taskOk = $TestMode -or ((Get-ScheduledTask -TaskName 'DeploymentLocalAgent').State -eq 'Running')
        $versionOk = (Test-Path -LiteralPath (Join-Path $AgentDir 'agent.php')) -and ((Get-AgentVersion (Join-Path $AgentDir 'agent.php')) -eq $TargetVersion)
        $newPid = Get-CapturedAgentPid $AgentDir
        $processOk = $TestMode -or (($null -ne $newPid) -and ($null -ne (Get-Process -Id $newPid -ErrorAction SilentlyContinue)))
        if ($taskOk -and $versionOk -and $processOk) {
            $verified = $true
            break
        }
        Start-Sleep -Milliseconds 500
    }
    if (-not $verified) {
        throw 'Post-activation health verification failed (task not running, agent process not confirmed, or version mismatch).'
    }

    New-Item -ItemType File -Path $Done -Force | Out-Null
    Write-Output "ACTIVATION_OK run_id=$RunId old_version=$SourceVersion new_version=$TargetVersion historical_commit=$HistoricalCommit backup=$Backup"
}
catch {
    # Point 13: never leave the task stopped after a failed activation
    # when rollback is possible. Restore the exact previous tree (if the
    # swap ever got that far) and always try to bring 1.4.6 back up before
    # re-throwing, so a failed activation attempt is never worse than a
    # no-op from the operator's perspective.
    $failureMessage = $_.Exception.Message

    if ($agentDirMoved) {
        if (Test-Path -LiteralPath $AgentDir) {
            $corrupted = Join-Path $RunRoot 'failed-activation-partial'
            if (Test-Path -LiteralPath $corrupted) { Remove-Item -LiteralPath $corrupted -Recurse -Force }
            Move-Item -LiteralPath $AgentDir -Destination $corrupted
        }
        Move-Item -LiteralPath $old -Destination $AgentDir
    }

    $restoredVersion = $null
    if (Test-Path -LiteralPath (Join-Path $AgentDir 'agent.php')) {
        $restoredVersion = Get-AgentVersion (Join-Path $AgentDir 'agent.php')
    }
    if ($restoredVersion -ne $SourceVersion) {
        throw "CRITICAL: activation failed AND automatic rollback could not confirm $SourceVersion is restored (found '$restoredVersion'). Original failure: $failureMessage"
    }

    Start-AgentTaskOnly

    throw "Activation failed and was automatically rolled back to ${SourceVersion}: $failureMessage"
}
