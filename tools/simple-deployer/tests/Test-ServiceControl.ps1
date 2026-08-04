<#
.SYNOPSIS
    Tests: Windows Scheduled Task pause/resume against a REAL, uniquely-
    named, throwaway test task (never DeploymentLocalAgent/Watchdog),
    process-exit waiting, and crash-recovery state persistence — all with
    real fixtures, no mocking.
#>

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ToolRoot = Split-Path -Parent $ScriptDir
. (Join-Path $ScriptDir 'TestHelpers.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Common.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.ServiceControl.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Git.ps1')

$markerText = "SimpleDeployTestMarker-$([Guid]::NewGuid().ToString('N').Substring(0,6))"
$taskNameRunning = "SimpleDeployTest-Running-$([Guid]::NewGuid().ToString('N').Substring(0,8))"
$taskNameIdle = "SimpleDeployTest-Idle-$([Guid]::NewGuid().ToString('N').Substring(0,8))"
$stateDir = New-TestTempDirectory -Prefix 'sdtest-svc-state'

try {
    # ============================================================
    # 1. Get-WindowsScheduledTaskState: nonexistent task.
    # ============================================================
    $missing = Get-WindowsScheduledTaskState -TaskName "SimpleDeployTest-DoesNotExist-$([Guid]::NewGuid().ToString('N'))"
    Assert-That 'a nonexistent task is reported as Exists=$false' ($missing.Exists -eq $false)
    Assert-That 'a nonexistent task state is "NotFound"' ($missing.State -eq 'NotFound')

    # ============================================================
    # 2. Register two real throwaway tasks: one we will start (Running),
    #    one we deliberately leave idle (Ready) — Suspend-DeploymentService
    #    must treat them differently.
    # ============================================================
    New-TestScheduledTask -TaskName $taskNameRunning -MarkerText $markerText -SleepSeconds 40
    New-TestScheduledTask -TaskName $taskNameIdle -MarkerText "$markerText-idle" -SleepSeconds 40

    $idleState = Get-WindowsScheduledTaskState -TaskName $taskNameIdle
    Assert-That 'a freshly-registered task exists' $idleState.Exists
    Assert-That 'a freshly-registered, never-started task is in the Ready state' ($idleState.State -eq 'Ready')

    Start-ScheduledTask -TaskName $taskNameRunning
    $becameRunning = Wait-ForScheduledTaskState -TaskName $taskNameRunning -ExpectedState 'Running' -TimeoutSeconds 8
    Assert-That 'the started task actually reaches the Running state' $becameRunning

    # ============================================================
    # 3. Suspend-DeploymentService: the Running task is stopped and its
    #    process is confirmed exited; the idle task is left alone
    #    (never started as a side effect); a third, nonexistent task name
    #    is silently skipped, never throws.
    # ============================================================
    $nonexistentTaskName = "SimpleDeployTest-Nonexistent-$([Guid]::NewGuid().ToString('N').Substring(0,8))"
    $suspendResult = Suspend-DeploymentService -TaskNames @($taskNameRunning, $taskNameIdle, $nonexistentTaskName) `
        -ProcessNames @('powershell.exe') -CommandLineMarker $markerText -StateDir $stateDir -ProcessExitTimeoutSeconds 20

    Assert-That 'Suspend-DeploymentService records the Running task previous state as Running' ($suspendResult.PreviousStates[$taskNameRunning] -eq 'Running')
    Assert-That 'Suspend-DeploymentService records the idle task previous state as Ready' ($suspendResult.PreviousStates[$taskNameIdle] -eq 'Ready')
    Assert-That 'only the task that was actually Running is included in PausedTaskNames' (@($suspendResult.PausedTaskNames) -contains $taskNameRunning)
    Assert-That 'the idle task is NOT included in PausedTaskNames (it was never touched)' (-not (@($suspendResult.PausedTaskNames) -contains $taskNameIdle))
    Assert-That 'a nonexistent task name is silently skipped, never included in PausedTaskNames' (-not (@($suspendResult.PausedTaskNames) -contains $nonexistentTaskName))

    $idleStateAfter = Get-WindowsScheduledTaskState -TaskName $taskNameIdle
    Assert-That 'the idle task is still Ready afterward — Suspend never starts a task that was not running' ($idleStateAfter.State -eq 'Ready')

    $stoppedProcs = Get-CimInstance -ClassName Win32_Process -Filter "Name = 'powershell.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine.Contains($markerText) -and (-not $_.CommandLine.Contains("$markerText-idle")) }
    Assert-That 'the previously-Running task''s process actually exited (confirmed via real process list, not just schtasks state)' (@($stoppedProcs).Count -eq 0)

    $runningStateAfter = Get-WindowsScheduledTaskState -TaskName $taskNameRunning
    Assert-That 'the previously-Running task is no longer in the Running state' ($runningStateAfter.State -ne 'Running')

    # ============================================================
    # 4. The recovery-state.json marker was written while paused, and
    #    correctly reflects which task(s) were actually paused.
    # ============================================================
    $staleAfterSuspend = Get-StaleRecoveryState -StateDir $stateDir
    Assert-That 'a recovery-state marker exists immediately after a real pause' ($null -ne $staleAfterSuspend)
    Assert-That 'the recovery marker lists exactly the task(s) that were actually paused' ((@($staleAfterSuspend.paused_task_names) -join ',') -eq $taskNameRunning)

    # ============================================================
    # 5. Resume-DeploymentService: restarts ONLY the paused task, and
    #    clears the recovery marker.
    # ============================================================
    Resume-DeploymentService -PausedTaskNames $suspendResult.PausedTaskNames -StateDir $stateDir
    $resumedToActive = Wait-ForScheduledTaskState -TaskName $taskNameRunning -ExpectedState 'Running' -TimeoutSeconds 8
    Assert-That 'Resume-DeploymentService actually restarts the previously-paused task' $resumedToActive

    $staleAfterResume = Get-StaleRecoveryState -StateDir $stateDir
    Assert-That 'the recovery marker is cleared after a successful resume' ($null -eq $staleAfterResume)

    # Clean up the process this second Start spawned before moving on.
    Remove-TestScheduledTask -TaskName $taskNameRunning
    Start-Sleep -Milliseconds 500

    # ============================================================
    # 6. Wait-ForDeploymentProcessesToExit: a process that outlives the
    #    timeout is correctly reported as still running (refusal path) —
    #    tested directly via Start-Process, no Scheduled Task involved.
    # ============================================================
    $longMarker = "$markerText-longlived"
    $longProc = Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-WindowStyle', 'Hidden', '-Command', "`$m='$longMarker'; Start-Sleep -Seconds 30") -PassThru
    try {
        Start-Sleep -Milliseconds 800
        $waitResult = Wait-ForDeploymentProcessesToExit -ProcessNames @('powershell.exe') -CommandLineMarker $longMarker -TimeoutSeconds 2
        Assert-That 'a process that outlives the timeout is correctly reported as NOT successfully exited' ($waitResult.Success -eq $false)
        Assert-That 'the still-running process is included in RemainingProcesses' (@($waitResult.RemainingProcesses | Where-Object { $_.ProcessId -eq $longProc.Id }).Count -eq 1)
    } finally {
        try { Stop-Process -Id $longProc.Id -Force -ErrorAction SilentlyContinue } catch {}
    }

    $quickWait = Wait-ForDeploymentProcessesToExit -ProcessNames @('powershell.exe') -CommandLineMarker $longMarker -TimeoutSeconds 5
    Assert-That 'after the process is killed, a fresh wait correctly reports success' $quickWait.Success

    # ============================================================
    # 7. Crash recovery: Test-DeploymentServiceRecovery.
    # ============================================================
    # 7a. No marker at all -> no-op, does not throw.
    $noMarkerThrew = $false
    try { Test-DeploymentServiceRecovery -StateDir (New-TestTempDirectory -Prefix 'sdtest-svc-empty') -NonInteractive $true -NonInteractiveDecision 'Ask' } catch { $noMarkerThrew = $true }
    Assert-That 'with no stale recovery marker present, Test-DeploymentServiceRecovery is a silent no-op' (-not $noMarkerThrew)

    # 7b. A stale marker + NonInteractive + decision "Ask" (the unresolved
    #     default) -> must throw rather than silently guessing.
    $recoveryStateDir1 = New-TestTempDirectory -Prefix 'sdtest-svc-recovery1'
    Save-RecoveryState -StateDir $recoveryStateDir1 -PausedTaskNames @('SomeFakeTaskName') | Out-Null
    $askThrew = $false
    try {
        Test-DeploymentServiceRecovery -StateDir $recoveryStateDir1 -NonInteractive $true -NonInteractiveDecision 'Ask'
    } catch {
        $askThrew = $true
    }
    Assert-That 'a stale marker in NonInteractive mode with no explicit decision throws (never silently guesses)' $askThrew
    Remove-TestTempDirectory -Path $recoveryStateDir1

    # 7c. A stale marker + NonInteractive + decision "Skip" -> does not
    #     resume, but the marker IS cleared (operator's explicit decision).
    $recoveryStateDir2 = New-TestTempDirectory -Prefix 'sdtest-svc-recovery2'
    Save-RecoveryState -StateDir $recoveryStateDir2 -PausedTaskNames @('SomeFakeTaskName') | Out-Null
    Test-DeploymentServiceRecovery -StateDir $recoveryStateDir2 -NonInteractive $true -NonInteractiveDecision 'Skip'
    Assert-That 'NonInteractiveDecision=Skip clears the stale marker without resuming anything' ($null -eq (Get-StaleRecoveryState -StateDir $recoveryStateDir2))
    Remove-TestTempDirectory -Path $recoveryStateDir2

    # 7d. A stale marker naming a REAL throwaway task (the idle task
    #     registered in step 2, still present and still not running) +
    #     NonInteractive + decision "Resume" -> the task is genuinely
    #     restarted.
    $recoveryStateDir3 = New-TestTempDirectory -Prefix 'sdtest-svc-recovery3'
    Save-RecoveryState -StateDir $recoveryStateDir3 -PausedTaskNames @($taskNameIdle) | Out-Null
    Test-DeploymentServiceRecovery -StateDir $recoveryStateDir3 -NonInteractive $true -NonInteractiveDecision 'Resume'
    $idleResumed = Wait-ForScheduledTaskState -TaskName $taskNameIdle -ExpectedState 'Running' -TimeoutSeconds 8
    Assert-That 'NonInteractiveDecision=Resume genuinely restarts the task named in the stale marker' $idleResumed
    Assert-That 'the marker is cleared after an explicit Resume decision' ($null -eq (Get-StaleRecoveryState -StateDir $recoveryStateDir3))
    Remove-TestTempDirectory -Path $recoveryStateDir3

    # ============================================================
    # 8. Empty-array safety for Resume-DeploymentService — the exact
    #    reported crash: DeploymentLocalAgent=Ready,
    #    DeploymentLocalAgentWatchdog=Disabled -> nothing paused ->
    #    Resume-DeploymentService(@()) in the finally block used to throw
    #    "Cannot bind argument to parameter 'PausedTaskNames' because it
    #    is an empty array." (a Mandatory array-typed parameter rejects
    #    @() outright in PowerShell — confirmed empirically). Fixed by
    #    making the parameter non-Mandatory with a default of @().
    # ============================================================
    $emptyArrayStateDir = New-TestTempDirectory -Prefix 'sdtest-svc-emptyarray'

    # 8a. Direct call with an explicit empty array — must not throw.
    $emptyArrayThrew = $false
    try {
        Resume-DeploymentService -PausedTaskNames @() -StateDir $emptyArrayStateDir
    } catch {
        $emptyArrayThrew = $true
    }
    Assert-That 'Resume-DeploymentService with an explicit empty array does NOT throw (the exact reported crash)' (-not $emptyArrayThrew)

    # 8b. Omitting -PausedTaskNames entirely relies on the new default (@()) — must also not throw.
    $omittedParamThrew = $false
    try {
        Resume-DeploymentService -StateDir $emptyArrayStateDir
    } catch {
        $omittedParamThrew = $true
    }
    Assert-That 'Resume-DeploymentService with -PausedTaskNames omitted entirely does NOT throw' (-not $omittedParamThrew)

    # 8c. Empty array inside crash-recovery (a stale recovery-state.json
    #     whose own paused_task_names is [] — e.g. a marker written right
    #     before Suspend-DeploymentService would have found nothing to
    #     pause, then the process crashed before Complete-RecoveryState).
    $emptyRecoveryDir = New-TestTempDirectory -Prefix 'sdtest-svc-emptyrecovery'
    Save-RecoveryState -StateDir $emptyRecoveryDir -PausedTaskNames @() | Out-Null
    $emptyRecoveryThrew = $false
    try {
        Test-DeploymentServiceRecovery -StateDir $emptyRecoveryDir -NonInteractive $true -NonInteractiveDecision 'Resume'
    } catch {
        $emptyRecoveryThrew = $true
    }
    Assert-That 'Test-DeploymentServiceRecovery with an empty paused_task_names in the stale marker does NOT throw' (-not $emptyRecoveryThrew)
    Assert-That 'the marker is still cleared even when it named zero tasks' ($null -eq (Get-StaleRecoveryState -StateDir $emptyRecoveryDir))
    Remove-TestTempDirectory -Path $emptyRecoveryDir

    # ============================================================
    # 9. Full state matrix — both tasks non-Running, only one Running,
    #    both Running, and one Disabled — proven against REAL throwaway
    #    Scheduled Tasks end to end through Suspend then Resume.
    # ============================================================
    function New-MatrixTasks {
        param([string]$StateA, [string]$StateB, [string]$Marker)
        $names = @(
            "SimpleDeployTest-MatrixA-$([Guid]::NewGuid().ToString('N').Substring(0,6))",
            "SimpleDeployTest-MatrixB-$([Guid]::NewGuid().ToString('N').Substring(0,6))"
        )
        $states = @($StateA, $StateB)
        for ($i = 0; $i -lt 2; $i++) {
            New-TestScheduledTask -TaskName $names[$i] -MarkerText "$Marker-$i" -SleepSeconds 30
            if ($states[$i] -eq 'Running') {
                Start-ScheduledTask -TaskName $names[$i]
                Wait-ForScheduledTaskState -TaskName $names[$i] -ExpectedState 'Running' -TimeoutSeconds 8 | Out-Null
            } elseif ($states[$i] -eq 'Disabled') {
                Disable-ScheduledTask -TaskName $names[$i] | Out-Null
            }
        }
        return $names
    }

    # 9a. Both non-Running (both Ready) — the exact reported scenario's
    #     task-state shape (Ready + Disabled is covered separately in 9d;
    #     this covers the simpler "both Ready" variant of "غیرRunning").
    $marker9a = "$markerText-9a"
    $names9a = New-MatrixTasks -StateA 'Ready' -StateB 'Ready' -Marker $marker9a
    try {
        $stateDir9a = New-TestTempDirectory -Prefix 'sdtest-svc-matrix9a'
        $suspend9a = Suspend-DeploymentService -TaskNames $names9a -ProcessNames @('powershell.exe') -CommandLineMarker $marker9a -StateDir $stateDir9a -ProcessExitTimeoutSeconds 10
        Assert-That '9a: both-Ready -> PausedTaskNames is empty' (@($suspend9a.PausedTaskNames).Count -eq 0)
        $resume9aThrew = $false
        try { Resume-DeploymentService -PausedTaskNames $suspend9a.PausedTaskNames -StateDir $stateDir9a } catch { $resume9aThrew = $true }
        Assert-That '9a: Resume-DeploymentService on the empty result does not throw' (-not $resume9aThrew)
        Assert-That '9a: task A is still Ready afterward (never started)' ((Get-WindowsScheduledTaskState -TaskName $names9a[0]).State -eq 'Ready')
        Assert-That '9a: task B is still Ready afterward (never started)' ((Get-WindowsScheduledTaskState -TaskName $names9a[1]).State -eq 'Ready')
        Remove-TestTempDirectory -Path $stateDir9a
    } finally {
        $names9a | ForEach-Object { Remove-TestScheduledTask -TaskName $_ }
        Stop-TestMarkerProcesses -MarkerText $marker9a
    }

    # 9b. Only one Running.
    $marker9b = "$markerText-9b"
    $names9b = New-MatrixTasks -StateA 'Running' -StateB 'Ready' -Marker $marker9b
    try {
        $stateDir9b = New-TestTempDirectory -Prefix 'sdtest-svc-matrix9b'
        $suspend9b = Suspend-DeploymentService -TaskNames $names9b -ProcessNames @('powershell.exe') -CommandLineMarker $marker9b -StateDir $stateDir9b -ProcessExitTimeoutSeconds 15
        Assert-That '9b: only the Running task is paused' ((@($suspend9b.PausedTaskNames) -join ',') -eq $names9b[0])
        Resume-DeploymentService -PausedTaskNames $suspend9b.PausedTaskNames -StateDir $stateDir9b
        $resumedA = Wait-ForScheduledTaskState -TaskName $names9b[0] -ExpectedState 'Running' -TimeoutSeconds 8
        Assert-That '9b: the previously-Running task is resumed' $resumedA
        Assert-That '9b: the task that was already Ready is still Ready (never started as a side effect)' ((Get-WindowsScheduledTaskState -TaskName $names9b[1]).State -eq 'Ready')
        Remove-TestTempDirectory -Path $stateDir9b
    } finally {
        $names9b | ForEach-Object { Remove-TestScheduledTask -TaskName $_ }
        Stop-TestMarkerProcesses -MarkerText $marker9b
    }

    # 9c. Both Running.
    $marker9c = "$markerText-9c"
    $names9c = New-MatrixTasks -StateA 'Running' -StateB 'Running' -Marker $marker9c
    try {
        $stateDir9c = New-TestTempDirectory -Prefix 'sdtest-svc-matrix9c'
        $suspend9c = Suspend-DeploymentService -TaskNames $names9c -ProcessNames @('powershell.exe') -CommandLineMarker $marker9c -StateDir $stateDir9c -ProcessExitTimeoutSeconds 15
        Assert-That '9c: both Running tasks are paused' ((@($suspend9c.PausedTaskNames) | Sort-Object) -join ',' -eq (($names9c | Sort-Object) -join ','))
        Resume-DeploymentService -PausedTaskNames $suspend9c.PausedTaskNames -StateDir $stateDir9c
        $resumedBoth = (Wait-ForScheduledTaskState -TaskName $names9c[0] -ExpectedState 'Running' -TimeoutSeconds 8) -and
                       (Wait-ForScheduledTaskState -TaskName $names9c[1] -ExpectedState 'Running' -TimeoutSeconds 8)
        Assert-That '9c: both previously-Running tasks are resumed' $resumedBoth
        Remove-TestTempDirectory -Path $stateDir9c
    } finally {
        $names9c | ForEach-Object { Remove-TestScheduledTask -TaskName $_ }
        Stop-TestMarkerProcesses -MarkerText $marker9c
    }

    # 9d. One Disabled (+ one Ready) — matches the EXACT reported scenario
    #     (DeploymentLocalAgent=Ready, DeploymentLocalAgentWatchdog=Disabled).
    #     A Disabled task must NEVER be started or re-enabled by this tool.
    $marker9d = "$markerText-9d"
    $names9d = New-MatrixTasks -StateA 'Ready' -StateB 'Disabled' -Marker $marker9d
    try {
        $stateDir9d = New-TestTempDirectory -Prefix 'sdtest-svc-matrix9d'
        $suspend9d = Suspend-DeploymentService -TaskNames $names9d -ProcessNames @('powershell.exe') -CommandLineMarker $marker9d -StateDir $stateDir9d -ProcessExitTimeoutSeconds 10
        Assert-That '9d: Suspend records the Disabled task''s previous state as Disabled' ($suspend9d.PreviousStates[$names9d[1]] -eq 'Disabled')
        Assert-That '9d: the Disabled task is never included in PausedTaskNames' (-not (@($suspend9d.PausedTaskNames) -contains $names9d[1]))
        Assert-That '9d: PausedTaskNames is empty (Ready + Disabled -> nothing was actually Running)' (@($suspend9d.PausedTaskNames).Count -eq 0)

        $resume9dThrew = $false
        try { Resume-DeploymentService -PausedTaskNames $suspend9d.PausedTaskNames -StateDir $stateDir9d } catch { $resume9dThrew = $true }
        Assert-That '9d: Resume-DeploymentService does not throw for this exact reported scenario' (-not $resume9dThrew)

        Assert-That '9d: the Disabled task is STILL Disabled afterward (never enabled or started)' ((Get-WindowsScheduledTaskState -TaskName $names9d[1]).State -eq 'Disabled')
        Assert-That '9d: the Ready task is STILL Ready afterward (never started)' ((Get-WindowsScheduledTaskState -TaskName $names9d[0]).State -eq 'Ready')
        Remove-TestTempDirectory -Path $stateDir9d
    } finally {
        $names9d | ForEach-Object { Remove-TestScheduledTask -TaskName $_ }
        Stop-TestMarkerProcesses -MarkerText $marker9d
    }

    # ============================================================
    # 10. Full end-to-end: the REAL SimpleDeploy.ps1 entry point, run as a
    #     genuine subprocess (NOT -DryRun this time — the whole point is
    #     to exercise the real Suspend/Resume-DeploymentService pass
    #     through the finally block), against two REAL throwaway
    #     Scheduled Tasks in exactly the reported state shape (Ready +
    #     Disabled) — reproducing the exact crash report end to end.
    #
    #     SimpleDeploy.ps1 has no CLI override for its own singleton lock
    #     name (Global\SimpleDeployerSingleInstance, hardcoded) — a real
    #     invocation always contends for that exact name. If something on
    #     this machine is already holding it (a genuinely separate concern
    #     from the bug being tested here — Sections 8/9 above already
    #     prove the actual fix via direct function calls, no lock
    #     involved), skip 10a/10b with a clear warning instead of a false
    #     failure, rather than let unrelated lock contention masquerade as
    #     a regression in the fix itself.
    # ============================================================
    $e2eLockAvailable = $false
    try {
        $e2eLockProbe = [System.Threading.Mutex]::OpenExisting('Global\SimpleDeployerSingleInstance')
        $e2eLockProbe.Dispose()
        # The mutex exists and OpenExisting succeeded -- something else
        # may be holding it. Confirm by trying a real (brief) acquisition.
        $probeMutex = New-Object System.Threading.Mutex($true, 'Global\SimpleDeployerSingleInstance', [ref]([bool]$false))
        if ($probeMutex.WaitOne(1000)) {
            $e2eLockAvailable = $true
            $probeMutex.ReleaseMutex()
        }
        $probeMutex.Dispose()
    } catch [System.Threading.WaitHandleCannotBeOpenedException] {
        # Does not exist at all yet -> definitely free.
        $e2eLockAvailable = $true
    }

    if (-not $e2eLockAvailable) {
        Write-Host '    (Global\SimpleDeployerSingleInstance is currently held by something else on this machine -- skipping the real SimpleDeploy.ps1 subprocess scenarios 10a/10b; the fix itself is already proven directly in sections 8-9 above)' -ForegroundColor Yellow
    }

    function New-E2EConfig {
        param(
            [Parameter(Mandatory = $true)][string]$ConfigPath,
            [Parameter(Mandatory = $true)][string[]]$TaskNames,
            [Parameter(Mandatory = $true)][string]$RepoPath,
            [Parameter(Mandatory = $true)][string]$Branch
        )
        $configObj = [PSCustomObject]@{
            deployment_service = [PSCustomObject]@{
                windows_scheduled_tasks            = $TaskNames
                windows_process_names              = @('powershell.exe')
                windows_process_commandline_marker = 'NoSuchDeploymentMarkerAtAll-E2E'
                linux_service_stop_timeout_seconds = 5
            }
            ssh      = [PSCustomObject]@{ known_hosts_path = (Join-Path $env:USERPROFILE '.ssh\known_hosts'); connect_timeout_seconds = 5 }
            projects = [PSCustomObject]@{
                'e2e-resume-project' = [PSCustomObject]@{
                    enabled = $true; local_path = $RepoPath; branch = $Branch; remote = 'origin'
                    build_mode = 'artifact'; activation_mode = 'working_directory'
                    server_host = 'unreachable-on-purpose.invalid'; server_user = 'deploy'
                    server_path = '/var/www/e2e'; ssh_key_path = 'C:\keys\id_ed25519'
                    health_check_url = 'CHANGE_ME'; preserve_paths = @('.env'); retention_count = 3
                    run_migrations = $false; database_backup = $false
                    composer_command = $null; npm_command = $null; php_binary = $null
                }
            }
        }
        ($configObj | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $ConfigPath -Encoding ASCII
    }

    # 10a. Project with NO changes (exact reported scenario): both tasks
    #      set up as Ready + Disabled, a real repo with nothing to commit.
    $e10aNames = @(
        "SimpleDeployTest-E2EReady-$([Guid]::NewGuid().ToString('N').Substring(0,6))",
        "SimpleDeployTest-E2EDisabled-$([Guid]::NewGuid().ToString('N').Substring(0,6))"
    )
    if ($e2eLockAvailable) {
    try {
        New-TestScheduledTask -TaskName $e10aNames[0] -MarkerText "$markerText-10a-0" -SleepSeconds 20
        New-TestScheduledTask -TaskName $e10aNames[1] -MarkerText "$markerText-10a-1" -SleepSeconds 20
        Disable-ScheduledTask -TaskName $e10aNames[1] | Out-Null

        $e10aBase = New-TestTempDirectory -Prefix 'sdtest-svc-e2e-nochange'
        $e10aRepo = Join-Path $e10aBase 'repo'
        Initialize-TestGitRepo -Path $e10aRepo -Branch 'main'
        'v1' | Set-Content -LiteralPath (Join-Path $e10aRepo 'app.txt') -Encoding ASCII -NoNewline
        & git.exe -C $e10aRepo add -- app.txt 2>&1 | Out-Null
        & git.exe -C $e10aRepo commit -m 'initial' --quiet 2>&1 | Out-Null
        & git.exe -C $e10aRepo remote add origin 'https://example.invalid/x.git' 2>&1 | Out-Null

        $e10aConfigPath = Join-Path $e10aBase 'config.json'
        New-E2EConfig -ConfigPath $e10aConfigPath -TaskNames $e10aNames -RepoPath $e10aRepo -Branch 'main'

        $e10aResult = Invoke-ExternalCommand -FilePath 'powershell.exe' -ArgumentList @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $ToolRoot 'SimpleDeploy.ps1'),
            '-Project', 'e2e-resume-project', '-NonInteractive', '-ConfigPath', $e10aConfigPath
        ) -TimeoutSeconds 60

        Assert-That '10a (no changes, Ready+Disabled tasks): the real SimpleDeploy.ps1 run does NOT crash (exit 0)' ($e10aResult.ExitCode -eq 0)
        Assert-That '10a: the exact reported crash message never appears in the output' (-not (($e10aResult.StdOut + $e10aResult.StdErr) -match 'Cannot bind argument to parameter'))
        Assert-That '10a: the "nothing to resume" message is shown' ($e10aResult.StdOut -match 'هیچ سرویس یا Taskای توسط این اجرا متوقف نشده بود')
        Assert-That '10a: the project is reported as skipped due to no changes' ($e10aResult.StdOut -match 'بدون تغییر')
        Assert-That '10a: the Disabled task is still Disabled after the run' ((Get-WindowsScheduledTaskState -TaskName $e10aNames[1]).State -eq 'Disabled')
        Assert-That '10a: the Ready task is still Ready after the run (never started)' ((Get-WindowsScheduledTaskState -TaskName $e10aNames[0]).State -eq 'Ready')

        Remove-TestTempDirectory -Path $e10aBase
    } finally {
        $e10aNames | ForEach-Object { Remove-TestScheduledTask -TaskName $_ }
        Stop-TestMarkerProcesses -MarkerText "$markerText-10a"
    }
    }

    # 10b. Preflight failure (repo checked out on the wrong branch) with
    #      the same Ready+Disabled task shape — the run must still finish
    #      cleanly (project Skipped, whole script exits 0), never crash in
    #      the finally block regardless of how the project itself fared.
    $e10bNames = @(
        "SimpleDeployTest-E2EReady-$([Guid]::NewGuid().ToString('N').Substring(0,6))",
        "SimpleDeployTest-E2EDisabled-$([Guid]::NewGuid().ToString('N').Substring(0,6))"
    )
    if ($e2eLockAvailable) {
    try {
        New-TestScheduledTask -TaskName $e10bNames[0] -MarkerText "$markerText-10b-0" -SleepSeconds 20
        New-TestScheduledTask -TaskName $e10bNames[1] -MarkerText "$markerText-10b-1" -SleepSeconds 20
        Disable-ScheduledTask -TaskName $e10bNames[1] | Out-Null

        $e10bBase = New-TestTempDirectory -Prefix 'sdtest-svc-e2e-preflight'
        $e10bRepo = Join-Path $e10bBase 'repo'
        Initialize-TestGitRepo -Path $e10bRepo -Branch 'develop'
        'v1' | Set-Content -LiteralPath (Join-Path $e10bRepo 'app.txt') -Encoding ASCII -NoNewline
        & git.exe -C $e10bRepo add -- app.txt 2>&1 | Out-Null
        & git.exe -C $e10bRepo commit -m 'initial' --quiet 2>&1 | Out-Null
        & git.exe -C $e10bRepo remote add origin 'https://example.invalid/x.git' 2>&1 | Out-Null

        $e10bConfigPath = Join-Path $e10bBase 'config.json'
        # Config declares branch=main, but the repo is actually on develop -> preflight fails.
        New-E2EConfig -ConfigPath $e10bConfigPath -TaskNames $e10bNames -RepoPath $e10bRepo -Branch 'main'

        $e10bResult = Invoke-ExternalCommand -FilePath 'powershell.exe' -ArgumentList @(
            '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', (Join-Path $ToolRoot 'SimpleDeploy.ps1'),
            '-Project', 'e2e-resume-project', '-NonInteractive', '-ConfigPath', $e10bConfigPath
        ) -TimeoutSeconds 60

        Assert-That '10b (preflight failure, Ready+Disabled tasks): the real SimpleDeploy.ps1 run does NOT crash (exit 0)' ($e10bResult.ExitCode -eq 0)
        Assert-That '10b: the exact reported crash message never appears in the output' (-not (($e10bResult.StdOut + $e10bResult.StdErr) -match 'Cannot bind argument to parameter'))
        Assert-That '10b: the "nothing to resume" message is shown' ($e10bResult.StdOut -match 'هیچ سرویس یا Taskای توسط این اجرا متوقف نشده بود')
        Assert-That '10b: the project is reported as skipped due to preflight failure' ($e10bResult.StdOut -match 'Preflight ناموفق بود')
        Assert-That '10b: the Disabled task is still Disabled after the run' ((Get-WindowsScheduledTaskState -TaskName $e10bNames[1]).State -eq 'Disabled')
        Assert-That '10b: the Ready task is still Ready after the run (never started)' ((Get-WindowsScheduledTaskState -TaskName $e10bNames[0]).State -eq 'Ready')

        Remove-TestTempDirectory -Path $e10bBase
    } finally {
        $e10bNames | ForEach-Object { Remove-TestScheduledTask -TaskName $_ }
        Stop-TestMarkerProcesses -MarkerText "$markerText-10b"
    }
    }

} finally {
    Remove-TestScheduledTask -TaskName $taskNameRunning
    Remove-TestScheduledTask -TaskName $taskNameIdle
    Stop-TestMarkerProcesses -MarkerText $markerText
    Remove-TestTempDirectory -Path $stateDir
}

Write-TestSummaryAndExit -SuiteName 'Test-ServiceControl'
