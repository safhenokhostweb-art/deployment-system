<#
.SYNOPSIS
    Tests: launcher/SimpleDeployLauncher.Engine.ps1 — the async runspace
    engine actually keeps the calling thread free while a background
    operation runs (proving the GUI would never freeze), captures
    Write-Step/Info/Success/Warn/ErrorFa output correctly, propagates a
    real return value, propagates a real error, and Stop-LauncherOperation
    genuinely halts a running operation without corrupting anything —
    all against the real runspace/PowerShell machinery, no mocking.
#>

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ToolRoot = Split-Path -Parent $ScriptDir
. (Join-Path $ScriptDir 'TestHelpers.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Common.ps1')
. (Join-Path $ToolRoot 'launcher\SimpleDeployLauncher.Engine.ps1')

function Wait-ForHandle {
    param($Handle, [int]$TimeoutSeconds = 15)
    $collected = New-Object System.Collections.Generic.List[object]
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while (-not (Test-LauncherOperationComplete -Handle $Handle) -and (Get-Date) -lt $deadline) {
        $collected.AddRange((Receive-LauncherOperationOutput -Handle $Handle))
        Start-Sleep -Milliseconds 50
    }
    $collected.AddRange((Receive-LauncherOperationOutput -Handle $Handle))
    return $collected
}

# ============================================================
# 1. The calling (main) thread stays free while the operation runs — the
#    concrete guarantee behind "پنجره اصلی Freeze نشود". Proven by
#    running a 1-second background operation and confirming the calling
#    thread can do other work (increment a counter on a timer-like loop)
#    THE WHOLE TIME, never blocked waiting on the operation itself.
# ============================================================
$handle1 = Start-LauncherOperation -ToolRoot $ToolRoot -Operation {
    Start-Sleep -Milliseconds 800
    return 'slow-done'
}
$mainThreadTicks = 0
$pollStart = Get-Date
while (-not (Test-LauncherOperationComplete -Handle $handle1)) {
    $mainThreadTicks++
    Start-Sleep -Milliseconds 20
    if (((Get-Date) - $pollStart).TotalSeconds -gt 10) { break }
}
$completion1 = Complete-LauncherOperation -Handle $handle1
Assert-That 'the background operation completed successfully' $completion1.Success
Assert-That 'the background operation''s real return value came through' ($completion1.Result -eq 'slow-done')
Assert-That 'the calling thread was free to do other work many times while the 800ms operation ran (never blocked)' ($mainThreadTicks -gt 5)

# ============================================================
# 2. Write-Step/Info/Success/Warn/ErrorFa are captured correctly, in
#    order, with the correct Type — the exact mechanism the GUI's log
#    panel and per-project status updates depend on.
# ============================================================
$handle2 = Start-LauncherOperation -ToolRoot $ToolRoot -Operation {
    Write-Step 'step-msg'
    Write-Info 'info-msg'
    Write-Success 'success-msg'
    Write-Warn 'warn-msg'
    Write-ErrorFa 'error-msg'
    return $null
}
$output2 = Wait-ForHandle -Handle $handle2
Complete-LauncherOperation -Handle $handle2 | Out-Null
Assert-That 'all 5 output items were captured' (@($output2).Count -eq 5)
Assert-That 'output items arrive in the same order they were written' ((@($output2) | ForEach-Object { $_.Type }) -join ',' -eq 'Step,Info,Success,Warn,Error')
Assert-That 'Write-Step text is preserved exactly' ((@($output2) | Where-Object { $_.Type -eq 'Step' }).Text -eq 'step-msg')
Assert-That 'Write-ErrorFa text is preserved exactly' ((@($output2) | Where-Object { $_.Type -eq 'Error' }).Text -eq 'error-msg')

# ============================================================
# 3. A real exception thrown inside the operation is reported as a
#    failure with the real message, never silently swallowed.
# ============================================================
$handle3 = Start-LauncherOperation -ToolRoot $ToolRoot -Operation {
    throw 'deliberate test failure message'
}
Wait-ForHandle -Handle $handle3 | Out-Null
$completion3 = Complete-LauncherOperation -Handle $handle3
Assert-That 'a thrown exception is reported as Success=$false' (-not $completion3.Success)
Assert-That 'the real exception message is propagated' ($completion3.Error -match 'deliberate test failure message')
Assert-That 'a thrown exception is NOT reported as Cancelled' (-not $completion3.Cancelled)

# ============================================================
# 4. Cancellation: Stop-LauncherOperation genuinely halts a running
#    operation, is reported as Cancelled (distinct from a plain Failed),
#    and — critically — a try/finally INSIDE the operation still runs
#    (proving a cancel mid-flow can still clean up / restore state, e.g.
#    Resume-DeploymentService in Invoke-LauncherDeployFlow's own
#    try/finally, exactly the "safe cancel" guarantee required).
# ============================================================
$cancelMarkerPath = Join-Path (New-TestTempDirectory -Prefix 'sdtest-launcher-cancel') 'finally-ran.marker'
$handle4 = Start-LauncherOperation -ToolRoot $ToolRoot -Variables @{ MarkerPath = $cancelMarkerPath } -Operation {
    try {
        Write-Step 'starting cancellable work'
        for ($i = 0; $i -lt 50; $i++) {
            Start-Sleep -Milliseconds 100
        }
        return 'should-not-reach-here'
    } finally {
        Set-Content -LiteralPath $MarkerPath -Value 'finally-ran' -Encoding ASCII
    }
}
Start-Sleep -Milliseconds 400
Stop-LauncherOperation -Handle $handle4
Wait-ForHandle -Handle $handle4 -TimeoutSeconds 15 | Out-Null
$completion4 = Complete-LauncherOperation -Handle $handle4
Assert-That 'a cancelled operation is reported as Success=$false' (-not $completion4.Success)
Assert-That 'a cancelled operation is reported as Cancelled=$true (distinct from a real failure)' $completion4.Cancelled
Assert-That 'the operation''s own try/finally still ran despite cancellation (safe cleanup guaranteed)' (Test-Path -LiteralPath $cancelMarkerPath)
Remove-TestTempDirectory -Path (Split-Path -Parent $cancelMarkerPath)

# ============================================================
# 5. Variables injected via -Variables are genuinely visible inside the
#    operation (the mechanism Invoke-LauncherDeployFlow/GitOnlyFlow rely
#    on to receive $ProjectInfo/$Config/etc. across the runspace boundary).
# ============================================================
$handle5 = Start-LauncherOperation -ToolRoot $ToolRoot -Variables @{ Alpha = 11; Beta = 'hello' } -Operation {
    return "$Alpha-$Beta"
}
Wait-ForHandle -Handle $handle5 | Out-Null
$completion5 = Complete-LauncherOperation -Handle $handle5
Assert-That 'injected variables are visible inside the background operation' ($completion5.Result -eq '11-hello')

# ============================================================
# 6. Get-DeploymentServiceDisplayStatus — Ready/Disabled/Running task
#    matrix, real throwaway Scheduled Tasks (never
#    DeploymentLocalAgent/Watchdog).
# ============================================================
. (Join-Path $ToolRoot 'lib\SimpleDeploy.ServiceControl.ps1')

$fakeCfgNoTasks = [PSCustomObject]@{ windows_scheduled_tasks = @("SimpleDeployTest-EngineDS-$([Guid]::NewGuid().ToString('N').Substring(0,8))") }
$statusMissing = Get-DeploymentServiceDisplayStatus -DeploymentServiceCfg $fakeCfgNoTasks
Assert-That 'a Deployment Service status for entirely nonexistent tasks reports "یافت نشد"' ($statusMissing.Text -eq 'یافت نشد')

$readyTaskName = "SimpleDeployTest-EngineReady-$([Guid]::NewGuid().ToString('N').Substring(0,6))"
$disabledTaskName = "SimpleDeployTest-EngineDisabled-$([Guid]::NewGuid().ToString('N').Substring(0,6))"
$runningTaskName = "SimpleDeployTest-EngineRunning-$([Guid]::NewGuid().ToString('N').Substring(0,6))"
try {
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' -Argument '-NoProfile -WindowStyle Hidden -Command "Start-Sleep -Seconds 15"'
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddYears(5)
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -DontStopOnIdleEnd -ExecutionTimeLimit ([TimeSpan]::Zero)
    Register-ScheduledTask -TaskName $readyTaskName -Action $action -Trigger $trigger -Settings $settings -ErrorAction Stop | Out-Null
    Register-ScheduledTask -TaskName $disabledTaskName -Action $action -Trigger $trigger -Settings $settings -ErrorAction Stop | Out-Null
    Disable-ScheduledTask -TaskName $disabledTaskName | Out-Null

    $cfgReadyDisabled = [PSCustomObject]@{ windows_scheduled_tasks = @($readyTaskName, $disabledTaskName) }
    $statusRD = Get-DeploymentServiceDisplayStatus -DeploymentServiceCfg $cfgReadyDisabled
    Assert-That 'Ready + Disabled (neither Running) reports "متوقف"' ($statusRD.Text -eq 'متوقف')

    Register-ScheduledTask -TaskName $runningTaskName -Action $action -Trigger $trigger -Settings $settings -ErrorAction Stop | Out-Null
    Start-ScheduledTask -TaskName $runningTaskName
    $becameRunning = $false
    $deadline = (Get-Date).AddSeconds(8)
    while ((Get-Date) -lt $deadline) {
        if ((Get-ScheduledTask -TaskName $runningTaskName).State -eq 'Running') { $becameRunning = $true; break }
        Start-Sleep -Milliseconds 200
    }
    Assert-That 'the throwaway task actually reached Running before checking the aggregate status' $becameRunning

    $cfgWithRunning = [PSCustomObject]@{ windows_scheduled_tasks = @($readyTaskName, $disabledTaskName, $runningTaskName) }
    $statusRun = Get-DeploymentServiceDisplayStatus -DeploymentServiceCfg $cfgWithRunning
    Assert-That 'with at least one task Running, the aggregate status reports "فعال"' ($statusRun.Text -eq 'فعال')

    $statusPaused = Get-DeploymentServiceDisplayStatus -DeploymentServiceCfg $cfgWithRunning -PausedByThisRun $true
    Assert-That 'PausedByThisRun=$true always reports the distinct "موقتاً متوقف‌شده" text regardless of actual task state' ($statusPaused.Text -eq 'موقتاً متوقف‌شده توسط این برنامه')
} finally {
    try { schtasks.exe /End /TN $runningTaskName 2>&1 | Out-Null } catch {}
    Unregister-ScheduledTask -TaskName $readyTaskName -Confirm:$false -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $disabledTaskName -Confirm:$false -ErrorAction SilentlyContinue
    Unregister-ScheduledTask -TaskName $runningTaskName -Confirm:$false -ErrorAction SilentlyContinue
}

Write-TestSummaryAndExit -SuiteName 'Test-LauncherEngine'
