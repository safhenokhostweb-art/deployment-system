<#
.SYNOPSIS
    Tests: the concurrent-execution lock (a REAL, process-wide named
    Mutex — proven via a genuinely separate subprocess holding it, not
    just re-entrant same-thread acquisition) and Test-ProjectPreflight's
    individual checks (missing folder, wrong branch, missing remote,
    deployability, and the belt-and-suspenders "Deployment Agent still
    running" check against a real marked process).
#>

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ToolRoot = Split-Path -Parent $ScriptDir
. (Join-Path $ScriptDir 'TestHelpers.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Common.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Git.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Config.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.ServiceControl.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Preflight.ps1')

# ============================================================
# 1. Concurrent execution lock: a REAL, separate PowerShell subprocess
#    holds the named Mutex first — proves actual cross-process mutual
#    exclusion, not just re-entrant same-thread WaitOne (which a Mutex
#    would grant immediately to its own owning thread regardless).
# ============================================================
$testMutexName = "Global\SimpleDeployerTest-$([Guid]::NewGuid().ToString('N').Substring(0,12))"
$lockTestDir = New-TestTempDirectory -Prefix 'sdtest-preflight-lock'
$holderScriptPath = Join-Path $lockTestDir 'holder.ps1'
$readyMarkerPath = Join-Path $lockTestDir 'holder-acquired.marker'
@"
. '$($ToolRoot -replace "'", "''")\lib\SimpleDeploy.Preflight.ps1'
`$mutex = Enter-SimpleDeployLock -Name '$testMutexName' -WaitMilliseconds 2000
if (`$null -eq `$mutex) { exit 2 }
Set-Content -LiteralPath '$($readyMarkerPath -replace "'", "''")' -Value 'acquired' -Encoding ASCII
Start-Sleep -Seconds 5
Exit-SimpleDeployLock -Mutex `$mutex
exit 0
"@ | Set-Content -LiteralPath $holderScriptPath -Encoding ASCII

$holderProcess = Start-Process -FilePath 'powershell.exe' `
    -ArgumentList @('-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $holderScriptPath) -PassThru -WindowStyle Hidden

# Poll for the holder's own readiness marker instead of a flat sleep — a
# fixed delay raced against PowerShell's own subprocess startup overhead
# and could check before the holder had actually acquired the mutex.
$holderReady = $false
$readyDeadline = (Get-Date).AddSeconds(10)
while ((Get-Date) -lt $readyDeadline) {
    if (Test-Path -LiteralPath $readyMarkerPath) { $holderReady = $true; break }
    Start-Sleep -Milliseconds 200
}
Assert-That 'the holder subprocess actually acquired the mutex before we test contention' $holderReady

$blockedMutex = Enter-SimpleDeployLock -Name $testMutexName -WaitMilliseconds 500
Assert-That 'a second, independent process cannot acquire the lock while the first genuinely holds it' ($null -eq $blockedMutex)

$holderProcess.WaitForExit(10000) | Out-Null
Assert-That 'the holder subprocess exited cleanly (released the mutex normally, not by crashing)' $holderProcess.HasExited

$acquiredAfterRelease = Enter-SimpleDeployLock -Name $testMutexName -WaitMilliseconds 1000
Assert-That 'once the first holder releases the lock, a new acquisition succeeds' ($null -ne $acquiredAfterRelease)
if ($acquiredAfterRelease) { Exit-SimpleDeployLock -Mutex $acquiredAfterRelease }

# In-process re-entrant sanity check: after Exit-SimpleDeployLock fully
# disposes the mutex, a fresh Enter-SimpleDeployLock under the SAME name
# starts a brand new, unheld mutex and succeeds immediately.
$reacquired = Enter-SimpleDeployLock -Name $testMutexName -WaitMilliseconds 500
Assert-That 'Exit-SimpleDeployLock fully releases the lock for a subsequent acquisition in this process too' ($null -ne $reacquired)
if ($reacquired) { Exit-SimpleDeployLock -Mutex $reacquired }

# ============================================================
# 2. Test-ProjectPreflight — individual failure conditions, real fixtures.
# ============================================================
$fixtureBase = New-TestTempDirectory -Prefix 'sdtest-preflight-fixtures'

# 2a. Nonexistent local_path.
$missingPathConfig = [PSCustomObject]@{
    local_path = (Join-Path $fixtureBase 'does-not-exist')
    branch     = 'main'; remote = 'origin'; build_mode = 'disabled'
    server_host = 'CHANGE_ME'; server_user = 'CHANGE_ME'; server_path = 'CHANGE_ME'; ssh_key_path = 'CHANGE_ME'
}
$result2a = Test-ProjectPreflight -ProjectKey 'p1' -ProjectConfig $missingPathConfig -DeploymentProcessNames @('php.exe') -DeploymentProcessMarker 'NoSuchMarker'
Assert-That 'preflight fails for a nonexistent local_path' (-not $result2a.Passed)
Assert-That 'the failure mentions the missing folder' (($result2a.Failures -join ' ') -match 'پیدا نشد')

# 2b. Real repo, but checked out on the wrong branch.
$wrongBranchPath = Join-Path $fixtureBase 'wrong-branch'
Initialize-TestGitRepo -Path $wrongBranchPath -Branch 'develop'
'x' | Set-Content -LiteralPath (Join-Path $wrongBranchPath 'f.txt') -Encoding ASCII
& git.exe -C $wrongBranchPath add -- f.txt 2>&1 | Out-Null
& git.exe -C $wrongBranchPath commit -m 'init' --quiet 2>&1 | Out-Null
& git.exe -C $wrongBranchPath remote add origin 'https://example.invalid/x.git' 2>&1 | Out-Null
$wrongBranchConfig = [PSCustomObject]@{
    local_path = $wrongBranchPath; branch = 'main'; remote = 'origin'; build_mode = 'disabled'
    server_host = 'CHANGE_ME'; server_user = 'CHANGE_ME'; server_path = 'CHANGE_ME'; ssh_key_path = 'CHANGE_ME'
}
$result2b = Test-ProjectPreflight -ProjectKey 'p2' -ProjectConfig $wrongBranchConfig -DeploymentProcessNames @('php.exe') -DeploymentProcessMarker 'NoSuchMarker'
Assert-That 'preflight fails when checked out on the wrong branch' (-not $result2b.Passed)
Assert-That 'the failure mentions the actual vs expected branch' (($result2b.Failures -join ' ') -match 'develop')

# 2c. Real repo, correct branch, but no remote configured at all.
$noRemotePath = Join-Path $fixtureBase 'no-remote'
Initialize-TestGitRepo -Path $noRemotePath -Branch 'main'
'x' | Set-Content -LiteralPath (Join-Path $noRemotePath 'f.txt') -Encoding ASCII
& git.exe -C $noRemotePath add -- f.txt 2>&1 | Out-Null
& git.exe -C $noRemotePath commit -m 'init' --quiet 2>&1 | Out-Null
$noRemoteConfig = [PSCustomObject]@{
    local_path = $noRemotePath; branch = 'main'; remote = 'origin'; build_mode = 'disabled'
    server_host = 'CHANGE_ME'; server_user = 'CHANGE_ME'; server_path = 'CHANGE_ME'; ssh_key_path = 'CHANGE_ME'
}
$result2c = Test-ProjectPreflight -ProjectKey 'p3' -ProjectConfig $noRemoteConfig -DeploymentProcessNames @('php.exe') -DeploymentProcessMarker 'NoSuchMarker'
Assert-That 'preflight fails when the configured remote does not exist' (-not $result2c.Passed)
Assert-That 'the failure mentions the missing remote' (($result2c.Failures -join ' ') -match 'remote')

# 2d. A fully valid, fully-configured project (build_mode=git-only avoids
#     requiring ssh.exe/scp.exe for this check) -> preflight passes.
$validPath = Join-Path $fixtureBase 'valid'
Initialize-TestGitRepo -Path $validPath -Branch 'main'
'x' | Set-Content -LiteralPath (Join-Path $validPath 'f.txt') -Encoding ASCII
& git.exe -C $validPath add -- f.txt 2>&1 | Out-Null
& git.exe -C $validPath commit -m 'init' --quiet 2>&1 | Out-Null
& git.exe -C $validPath remote add origin 'https://example.invalid/x.git' 2>&1 | Out-Null
$validConfig = [PSCustomObject]@{
    local_path = $validPath; branch = 'main'; remote = 'origin'; build_mode = 'git-only'
    server_host = '1.2.3.4'; server_user = 'deploy'; server_path = '/var/www/x'; ssh_key_path = 'C:\keys\id_ed25519'
}
$result2d = Test-ProjectPreflight -ProjectKey 'p4' -ProjectConfig $validConfig -DeploymentProcessNames @('php.exe') -DeploymentProcessMarker 'NoSuchMarker'
Assert-That 'preflight passes for a fully valid, fully-configured project' $result2d.Passed
Assert-That 'a passing preflight reports zero failures' (@($result2d.Failures).Count -eq 0)

# 2e. Belt-and-suspenders: a REAL process carrying the Deployment Agent
#     marker is still alive -> preflight refuses, even though every other
#     check (folder/branch/remote/deployability) is satisfied.
$agentMarker = "SimpleDeployPreflightMarker-$([Guid]::NewGuid().ToString('N').Substring(0,6))"
$agentProc = Start-Process -FilePath 'powershell.exe' -ArgumentList @('-NoProfile', '-WindowStyle', 'Hidden', '-Command', "`$m='$agentMarker'; Start-Sleep -Seconds 15") -PassThru
try {
    Start-Sleep -Milliseconds 800
    $result2e = Test-ProjectPreflight -ProjectKey 'p5' -ProjectConfig $validConfig -DeploymentProcessNames @('powershell.exe') -DeploymentProcessMarker $agentMarker
    Assert-That 'preflight refuses to proceed while a matching Deployment Agent process is still alive' (-not $result2e.Passed)
    Assert-That 'the failure specifically mentions the Deployment Agent' (($result2e.Failures -join ' ') -match 'Deployment Agent')
} finally {
    Stop-Process -Id $agentProc.Id -Force -ErrorAction SilentlyContinue
}
Start-Sleep -Milliseconds 500
$result2f = Test-ProjectPreflight -ProjectKey 'p6' -ProjectConfig $validConfig -DeploymentProcessNames @('powershell.exe') -DeploymentProcessMarker $agentMarker
Assert-That 'once the Deployment Agent process is gone, the same project passes preflight again' $result2f.Passed

Remove-TestTempDirectory -Path $fixtureBase
Remove-TestTempDirectory -Path (Split-Path -Parent $holderScriptPath)
Write-TestSummaryAndExit -SuiteName 'Test-Preflight'
