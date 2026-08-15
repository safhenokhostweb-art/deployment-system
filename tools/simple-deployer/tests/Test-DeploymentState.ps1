<#
.SYNOPSIS
    Tests for the Phase D.1 runtime-only Deployment State Observability
    layer (lib/SimpleDeploy.DeploymentState.ps1): Run ID generation,
    stage-event ordering, rollback-marker parsing, multi-target state
    isolation, and end-to-end failure propagation through the REAL
    Invoke-ApplicationDeployToTargets pipeline (unreachable-on-purpose
    hosts — never a real VPS, never a real SSH connection that succeeds).
#>

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ToolRoot = Split-Path -Parent $ScriptDir
. (Join-Path $ScriptDir 'TestHelpers.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Common.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Git.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Config.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.ServiceControl.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Preflight.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Build.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.ArtifactValidation.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Transfer.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Timing.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.DeploymentState.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Orchestration.ps1')

# ============================================================
# 1. Run ID generation: exact DEP-YYYYMMDD-HHMMSS-XXXX shape, and two
#    IDs generated back-to-back (same or adjacent second) never collide.
# ============================================================
$runId1 = New-DeploymentRunId
Assert-That 'RunId matches DEP-YYYYMMDD-HHMMSS-XXXX' ($runId1 -match '^DEP-\d{8}-\d{6}-[0-9A-F]{4}$')

$generatedIds = New-Object System.Collections.Generic.HashSet[string]
for ($i = 0; $i -lt 200; $i++) { [void]$generatedIds.Add((New-DeploymentRunId)) }
Assert-That '200 back-to-back RunIds are all unique (XXXX suffix avoids same-second collisions)' ($generatedIds.Count -eq 200)

$fixedTime = [datetime]::new(2026, 3, 5, 14, 30, 7, [System.DateTimeKind]::Utc)
$runIdFixed = New-DeploymentRunId -UtcNow $fixedTime
Assert-That 'RunId embeds the exact UTC date/time it was given' ($runIdFixed -like 'DEP-20260305-143007-*')

# ============================================================
# 2. Stage-event ordering: Add-DeploymentRunEvent appends in call order
#    and CurrentStage always reflects the LAST event added.
# ============================================================
$runState = New-DeploymentRunState -RunId 'DEP-TEST-0001' -Application 'order-test'
Assert-That 'a fresh RunState starts with CurrentStage=created and zero events' ($runState.CurrentStage -eq 'created' -and $runState.Events.Count -eq 0)

Add-DeploymentRunEvent -RunState $runState -EventName 'RUN_STARTED'
Add-DeploymentRunEvent -RunState $runState -EventName 'PREFLIGHT_STARTED'
Add-DeploymentRunEvent -RunState $runState -EventName 'PREFLIGHT_COMPLETED'
Add-DeploymentRunEvent -RunState $runState -EventName 'GIT_STARTED'
Add-DeploymentRunEvent -RunState $runState -EventName 'GIT_COMPLETED' -Detail 'commit=abc123'

$expectedOrder = @('RUN_STARTED', 'PREFLIGHT_STARTED', 'PREFLIGHT_COMPLETED', 'GIT_STARTED', 'GIT_COMPLETED')
$actualOrder = @($runState.Events | ForEach-Object { $_.EventName })
Assert-That 'events are recorded in the exact order they were added' (($expectedOrder -join ',') -eq ($actualOrder -join ','))
Assert-That 'CurrentStage reflects the most recently added event' ($runState.CurrentStage -eq 'GIT_COMPLETED')

$gitCompletedEvent = $null
foreach ($evt in $runState.Events) { if ($evt.EventName -eq 'GIT_COMPLETED') { $gitCompletedEvent = $evt } }
Assert-That 'an event Detail is preserved' ($null -ne $gitCompletedEvent -and $gitCompletedEvent.Detail -eq 'commit=abc123')

$allEventsHaveTimestamp = $true
foreach ($evt in $runState.Events) { if ($evt.TimestampUtc -isnot [datetime]) { $allEventsHaveTimestamp = $false } }
Assert-That 'each event carries a TimestampUtc' $allEventsHaveTimestamp

try {
    Add-DeploymentRunEvent -RunState $runState -EventName 'NOT_A_REAL_EVENT'
    Assert-That 'an unknown event name is rejected by ValidateSet' $false
} catch {
    Assert-That 'an unknown event name is rejected by ValidateSet' $true
}

# ============================================================
# 3. Rollback-marker parsing: activate-release.sh's 3 print-only
#    [STATE-MARK] lines, in the exact combined form `log()` produces.
# ============================================================
$noMarkers = ConvertFrom-ActivationRollbackMarkers -StdOut "[activate-release] Deploy با موفقیت کامل شد. commit فعال: abc123`n"
Assert-That 'no markers present -> all flags false, RollbackType $null' `
    (-not $noMarkers.ActivationRollbackStarted -and -not $noMarkers.HealthcheckRollbackStarted -and -not $noMarkers.RollbackCompleted -and $null -eq $noMarkers.RollbackType)

$activationRollbackStdOut = @'
[activate-release] در حال فعال‌سازی release جدید (swap اتمیک، حالت: working_directory)...
[activate-release] خطا پس از فعال‌سازی — شروع rollback خودکار.
[activate-release] [STATE-MARK] ACTIVATION_ROLLBACK_STARTED
[activate-release] در حال rollback به نسخه قبلی...
[activate-release] نسخه قبلی از «/var/www/x.backup-20260305-abc» بازگردانده شد.
[activate-release] [STATE-MARK] ROLLBACK_COMPLETED
[activate-release] خطا: Deploy با خطا مواجه شد و به نسخه قبلی بازگردانده شد — به لاگ‌های بالا نگاه کنید.
'@
$activationRollback = ConvertFrom-ActivationRollbackMarkers -StdOut $activationRollbackStdOut
Assert-That 'an activation-phase failure marks ActivationRollbackStarted=true' $activationRollback.ActivationRollbackStarted
Assert-That 'an activation-phase failure never marks HealthcheckRollbackStarted' (-not $activationRollback.HealthcheckRollbackStarted)
Assert-That 'ROLLBACK_COMPLETED is detected' $activationRollback.RollbackCompleted
Assert-That 'RollbackType is "activation"' ($activationRollback.RollbackType -eq 'activation')

$healthcheckRollbackStdOut = @'
[activate-release] [TIMING-MARK] health_check_start_ms=1000
[activate-release] اجرای health check: https://example.test/health
[activate-release] health check موفق نبود (curl به https://example.test/health دسترسی نداشت یا خطای HTTP دریافت کرد).
[activate-release] خطا پس از فعال‌سازی — شروع rollback خودکار.
[activate-release] [STATE-MARK] HEALTHCHECK_ROLLBACK_STARTED
[activate-release] در حال rollback به نسخه قبلی...
[activate-release] [STATE-MARK] ROLLBACK_COMPLETED
'@
$healthcheckRollback = ConvertFrom-ActivationRollbackMarkers -StdOut $healthcheckRollbackStdOut
Assert-That 'a health-check-phase failure marks HealthcheckRollbackStarted=true' $healthcheckRollback.HealthcheckRollbackStarted
Assert-That 'a health-check-phase failure never marks ActivationRollbackStarted' (-not $healthcheckRollback.ActivationRollbackStarted)
Assert-That 'RollbackType is "healthcheck"' ($healthcheckRollback.RollbackType -eq 'healthcheck')

$emptyResult = ConvertFrom-ActivationRollbackMarkers -StdOut ''
Assert-That 'empty StdOut never throws and returns all-false' (-not $emptyResult.ActivationRollbackStarted -and -not $emptyResult.RollbackCompleted)

# ============================================================
# 4. Multi-target state isolation: one target's Set-DeploymentTargetState
#    call must never change any other target's recorded Stage/Detail.
# ============================================================
$batch = New-DeploymentBatchState -RunId 'DEP-TEST-0002' -Application 'iso-test' -Targets @('target-a', 'target-b', 'target-c')
Assert-That 'every target starts at Stage=pending' (@($batch.TargetStates.Values) | ForEach-Object { $_.Stage -eq 'pending' } | Where-Object { -not $_ } | Measure-Object | ForEach-Object { $_.Count -eq 0 })
Assert-That 'TargetOrder preserves the order Targets were given in' ((@($batch.TargetOrder) -join ',') -eq 'target-a,target-b,target-c')

Set-DeploymentTargetState -BatchState $batch -Target 'target-a' -Stage 'completed'
Set-DeploymentTargetState -BatchState $batch -Target 'target-b' -Stage 'failed' -Detail 'SSH timeout'

Assert-That 'target-a is completed' ($batch.TargetStates['target-a'].Stage -eq 'completed')
Assert-That 'target-b is failed with its own Detail' ($batch.TargetStates['target-b'].Stage -eq 'failed' -and $batch.TargetStates['target-b'].Detail -eq 'SSH timeout')
Assert-That 'target-c is untouched by target-a/target-b updates (still pending, no Detail)' ($batch.TargetStates['target-c'].Stage -eq 'pending' -and $null -eq $batch.TargetStates['target-c'].Detail)

Set-DeploymentTargetState -BatchState $batch -Target 'target-d' -Stage 'in_progress'
Assert-That 'setting a target not in the original list adds it (append-safe)' ($batch.TargetStates['target-d'].Stage -eq 'in_progress' -and $batch.TargetOrder.Contains('target-d'))
Assert-That 'target-c is STILL untouched after target-d was added' ($batch.TargetStates['target-c'].Stage -eq 'pending')

# ============================================================
# 5. Failure propagation: a REAL Invoke-ApplicationDeployToTargets run
#    (real git repo, real commit, build_mode=git-only so no composer/npm
#    needed) against TWO unreachable-on-purpose targets — proves the
#    full RunState/BatchState wiring end-to-end, that one target's
#    failure never blocks the other, and that RUN_FAILED is the final
#    event when every target fails.
# ============================================================
# Real local bare-repo remote (same proven pattern as Test-
# Orchestration.ps1's "real run" section) -- the BUILD/git-push must
# genuinely succeed here; only the two SSH Targets below (127.0.0.1 on
# closed/unlisted ports -- connection-refused, not a DNS lookup) are
# "unreachable-on-purpose", so Transfer/Activation fails fast and
# deterministically for both, independently.
$realBase = New-TestTempDirectory -Prefix 'sdtest-depstate-fail'
$repoPath = Join-Path $realBase 'repo'
Initialize-TestGitRepo -Path $repoPath -Branch 'main'
'v1' | Set-Content -LiteralPath (Join-Path $repoPath 'app.txt') -Encoding ASCII -NoNewline
& git.exe -C $repoPath add -- app.txt 2>&1 | Out-Null
& git.exe -C $repoPath commit -m 'initial' --quiet 2>&1 | Out-Null
$bareRemote = Join-Path $realBase 'repo-remote.git'
& git.exe init --quiet --bare $bareRemote 2>&1 | Out-Null
& git.exe -C $repoPath remote add origin $bareRemote 2>&1 | Out-Null
& git.exe -C $repoPath push --quiet origin main:main 2>&1 | Out-Null
'v2 uncommitted change' | Set-Content -LiteralPath (Join-Path $repoPath 'app.txt') -Encoding ASCII -NoNewline

$fakeKnownHostsDir = New-TestTempDirectory -Prefix 'sdtest-depstate-fail-knownhosts'
$fakeKnownHosts = Join-Path $fakeKnownHostsDir 'known_hosts'
"[127.0.0.1]:65021 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAItest`n[127.0.0.1]:65022 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAItest" | Set-Content -LiteralPath $fakeKnownHosts -Encoding ASCII
$fakeKey = Join-Path $fakeKnownHostsDir 'fake_key'
'not a real key -- connection is expected to fail before this would ever be used for real auth' | Set-Content -LiteralPath $fakeKey -Encoding ASCII

$multiProjectConfig = [PSCustomObject]@{
    local_path = $repoPath; branch = 'main'; remote = 'origin'
    build_mode = 'git-only'; activation_mode = 'working_directory'
    server_host = '127.0.0.1'; server_user = 'deploy'
    server_path = '/var/www/x'; ssh_key_path = $fakeKey
    preserve_paths = @('.env'); retention_count = 3; run_migrations = $false
    database_backup = $false; composer_command = $null; npm_command = $null; php_binary = $null
    health_check_url = 'CHANGE_ME'
}
$multiProjectInfo = [PSCustomObject]@{ Key = 'multi-fail-test-project'; Config = $multiProjectConfig; IsDeployable = $true; BlockingReasons = @() }
$multiDeploymentServiceCfg = [PSCustomObject]@{ windows_process_names = @('php.exe'); windows_process_commandline_marker = 'NoSuchMarkerAtAll' }
$multiSshCfg = [PSCustomObject]@{ known_hosts_path = $fakeKnownHosts }

$fakeTargetA = [PSCustomObject]@{
    TargetKey = 'target-alpha'; ApplicationKey = 'multi-fail-test-project'; Domain = $null; DisplayName = 'target-alpha'
    LifecycleStatus = 'active'; DeploymentChannel = 'stable'
    ServerHost = '127.0.0.1'; ServerPort = 65021; ServerUser = 'deploy'; ServerPath = '/var/www/alpha'
    SshKeyPath = $fakeKey; HealthCheckUrl = 'CHANGE_ME'; RemotePhpBinary = $null
    Enabled = $true; IsTestTarget = $false; Source = 'test-fixture'
}
$fakeTargetB = [PSCustomObject]@{
    TargetKey = 'target-beta'; ApplicationKey = 'multi-fail-test-project'; Domain = $null; DisplayName = 'target-beta'
    LifecycleStatus = 'active'; DeploymentChannel = 'stable'
    ServerHost = '127.0.0.1'; ServerPort = 65022; ServerUser = 'deploy'; ServerPath = '/var/www/beta'
    SshKeyPath = $fakeKey; HealthCheckUrl = 'CHANGE_ME'; RemotePhpBinary = $null
    Enabled = $true; IsTestTarget = $false; Source = 'test-fixture'
}

$multiResult = Invoke-ApplicationDeployToTargets -ProjectInfo $multiProjectInfo -Targets @($fakeTargetA, $fakeTargetB) `
    -CommitMessage 'multi-target failure propagation test' -NonInteractive $true -DryRun $false `
    -DeploymentServiceCfg $multiDeploymentServiceCfg -SshCfg $multiSshCfg -ScriptRoot $ToolRoot

Assert-That 'the build itself reached BuiltArtifact (git-only, no unreachable-host dependency)' ($multiResult.BuildResult.Status -eq 'BuiltArtifact')
Assert-That 'both unreachable targets were attempted (one never blocks the other)' (@($multiResult.TargetResults).Count -eq 2)
Assert-That 'both targets failed (genuinely unreachable hosts)' ((@($multiResult.TargetResults) | Where-Object { $_.Status -eq 'Failed' }).Count -eq 2)

Assert-That 'RunState is present on the returned result' ($null -ne $multiResult.RunState)
$eventNames = @($multiResult.RunState.Events | ForEach-Object { $_.EventName })
Assert-That 'RUN_STARTED is the first event' ($eventNames[0] -eq 'RUN_STARTED')
Assert-That 'BUILD_COMPLETED fired before any TRANSFER_STARTED (build finished before any target was attempted)' `
    ([array]::IndexOf($eventNames, 'BUILD_COMPLETED') -lt [array]::IndexOf($eventNames, 'TRANSFER_STARTED'))
Assert-That 'TRANSFER_STARTED fired twice, once per target' ((@($eventNames) | Where-Object { $_ -eq 'TRANSFER_STARTED' }).Count -eq 2)
Assert-That 'RUN_FAILED is the last event when every target fails' ($eventNames[-1] -eq 'RUN_FAILED')

Assert-That 'BatchState is present on the returned result' ($null -ne $multiResult.BatchState)
Assert-That 'BatchState records both targets as failed, independently' `
    ($multiResult.BatchState.TargetStates['target-alpha'].Stage -eq 'failed' -and $multiResult.BatchState.TargetStates['target-beta'].Stage -eq 'failed')
Assert-That 'each failed target keeps its OWN failure Detail (not shared/overwritten by the other)' `
    ($multiResult.BatchState.TargetStates['target-alpha'].Detail -ne $multiResult.BatchState.TargetStates['target-beta'].Detail)

$multiWorkspaceParent = Join-Path (Join-Path $env:TEMP 'SimpleDeployer\builds') 'multi-fail-test-project'
$multiLeftoverJobDirs = @(if (Test-Path -LiteralPath $multiWorkspaceParent) { @(Get-ChildItem -Path $multiWorkspaceParent -Directory) } else { @() })
Assert-That 'the shared build workspace is still cleaned up once, after every target was attempted' ($multiLeftoverJobDirs.Count -eq 0)

Remove-TestTempDirectory -Path $realBase
Remove-TestTempDirectory -Path $fakeKnownHostsDir

Write-TestSummaryAndExit -SuiteName 'Test-DeploymentState'
