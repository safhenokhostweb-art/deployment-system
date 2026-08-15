<#
.SYNOPSIS
    Tests: -DryRun performs genuinely ZERO commit/push/build/upload/SSH —
    both at the Invoke-ProjectDeploy unit level (a real git repo with
    real uncommitted changes, proven untouched afterward) and as a full
    end-to-end SimpleDeploy.ps1 subprocess run against a real fixture
    config.json (never a real VPS, never the real Deployment Scheduled
    Tasks — the fixture config names only nonexistent throwaway tasks).
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
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Transfer.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Orchestration.ps1')

# ============================================================
# 1. Unit level: Invoke-ProjectDeploy -DryRun against a REAL repo with
#    REAL uncommitted changes — the repo must be byte-for-byte untouched
#    afterward (no commit, no staged files, same status lines), and the
#    call must return quickly (strong evidence no real network/SSH
#    attempt was made against the deliberately unreachable server_host).
# ============================================================
$repoPath = Join-Path (New-TestTempDirectory -Prefix 'sdtest-orch-dryrun') 'repo'
Initialize-TestGitRepo -Path $repoPath -Branch 'main'
'v1' | Set-Content -LiteralPath (Join-Path $repoPath 'app.txt') -Encoding ASCII -NoNewline
& git.exe -C $repoPath add -- app.txt 2>&1 | Out-Null
& git.exe -C $repoPath commit -m 'initial' --quiet 2>&1 | Out-Null
& git.exe -C $repoPath remote add origin 'https://example.invalid/x.git' 2>&1 | Out-Null
'v2 uncommitted change' | Set-Content -LiteralPath (Join-Path $repoPath 'app.txt') -Encoding ASCII -NoNewline
'new file' | Set-Content -LiteralPath (Join-Path $repoPath 'new.txt') -Encoding ASCII -NoNewline

$shaBeforeDryRun = Get-LastCommitSha -RepositoryPath $repoPath
$statusBeforeDryRun = @(@(Get-GitStatusShort -RepositoryPath $repoPath) | Sort-Object)

$projectConfig = [PSCustomObject]@{
    local_path = $repoPath; branch = 'main'; remote = 'origin'
    build_mode = 'artifact'; activation_mode = 'working_directory'
    server_host = 'unreachable-on-purpose.invalid'; server_user = 'deploy'
    server_path = '/var/www/x'; ssh_key_path = 'C:\keys\id_ed25519'
    preserve_paths = @('.env'); retention_count = 3; run_migrations = $false
    database_backup = $false; composer_command = $null; npm_command = $null; php_binary = $null
    health_check_url = 'CHANGE_ME'
}
$projectInfo = [PSCustomObject]@{ Key = 'dryrun-test-project'; Config = $projectConfig; IsDeployable = $true; BlockingReasons = @() }
$deploymentServiceCfg = [PSCustomObject]@{ windows_process_names = @('php.exe'); windows_process_commandline_marker = 'NoSuchMarkerAtAll' }
$sshCfg = [PSCustomObject]@{ known_hosts_path = (Join-Path $env:USERPROFILE '.ssh\known_hosts') }

$sw = [System.Diagnostics.Stopwatch]::StartNew()
$dryRunResult = Invoke-ProjectDeploy -ProjectInfo $projectInfo -CommitMessage 'should never be used' `
    -NonInteractive $true -DryRun $true -DeploymentServiceCfg $deploymentServiceCfg -SshCfg $sshCfg -ScriptRoot $ToolRoot
$sw.Stop()

Assert-That 'DryRun returns Status=DryRun' ($dryRunResult.Status -eq 'DryRun')
Assert-That 'DryRun returns no commit SHA (nothing was committed)' ($null -eq $dryRunResult.CommitSha)
Assert-That 'DryRun completes quickly — no real SSH connection attempt against the unreachable host was made' ($sw.Elapsed.TotalSeconds -lt 10)

$shaAfterDryRun = Get-LastCommitSha -RepositoryPath $repoPath
Assert-That 'DryRun creates ZERO commits — HEAD is unchanged' ($shaAfterDryRun -eq $shaBeforeDryRun)

$statusAfterDryRun = @(@(Get-GitStatusShort -RepositoryPath $repoPath) | Sort-Object)
Assert-That 'DryRun leaves the working tree exactly as it was (same uncommitted changes, nothing staged/reset)' (($statusAfterDryRun -join '|') -eq ($statusBeforeDryRun -join '|'))

$workspaceBase = Join-Path $env:TEMP 'SimpleDeployer\builds'
$leftoverWorkspaces = @(if (Test-Path -LiteralPath $workspaceBase) { @(Get-ChildItem -Path $workspaceBase -Directory -Filter 'dryrun-test-project*') } else { @() })
Assert-That 'DryRun never creates a build workspace (no git-archive/build/artifact step runs)' ($leftoverWorkspaces.Count -eq 0)

# 1b. Same DryRun call again, but for a project that is NOT yet fully
#     configured for deploy (IsDeployable=$false) — still returns DryRun,
#     still touches nothing, and surfaces the blocking reasons instead of
#     pretending it would deploy.
$notDeployableInfo = [PSCustomObject]@{ Key = 'dryrun-not-deployable'; Config = $projectConfig; IsDeployable = $false; BlockingReasons = @('server_host هنوز CHANGE_ME است.') }
$dryRunResult2 = Invoke-ProjectDeploy -ProjectInfo $notDeployableInfo -CommitMessage 'x' `
    -NonInteractive $true -DryRun $true -DeploymentServiceCfg $deploymentServiceCfg -SshCfg $sshCfg -ScriptRoot $ToolRoot
Assert-That 'DryRun for a not-yet-deployable project still returns Status=DryRun (never crashes/deploys)' ($dryRunResult2.Status -eq 'DryRun')
$shaAfterDryRun2 = Get-LastCommitSha -RepositoryPath $repoPath
Assert-That 'the not-yet-deployable DryRun also creates zero commits' ($shaAfterDryRun2 -eq $shaBeforeDryRun)

Remove-TestTempDirectory -Path (Split-Path -Parent $repoPath)

# ============================================================
# 2. Full end-to-end: the REAL SimpleDeploy.ps1 entry point, run as a
#    genuine subprocess with -DryRun -NonInteractive against a real
#    fixture config.json. The fixture's deployment_service task names are
#    deliberately nonexistent throwaway names — never
#    DeploymentLocalAgent/Watchdog — so even the (read-only, DryRun-only)
#    state-preview step cannot touch the real Deployment Service.
# ============================================================
# SimpleDeploy.ps1 has no CLI override for its own singleton lock name
# (Global\SimpleDeployerSingleInstance, hardcoded) — a real invocation
# always contends for that exact name. If something else on this machine
# already holds it (unrelated to this specific test), skip with a clear
# warning instead of a false failure — section 1 above already proves
# DryRun's own core behavior via a direct Invoke-ProjectDeploy call, no
# lock involved.
$e2eLockAvailable = $false
try {
    $e2eLockProbe = [System.Threading.Mutex]::OpenExisting('Global\SimpleDeployerSingleInstance')
    $e2eLockProbe.Dispose()
    $probeMutex = New-Object System.Threading.Mutex($true, 'Global\SimpleDeployerSingleInstance', [ref]([bool]$false))
    if ($probeMutex.WaitOne(1000)) {
        $e2eLockAvailable = $true
        $probeMutex.ReleaseMutex()
    }
    $probeMutex.Dispose()
} catch [System.Threading.WaitHandleCannotBeOpenedException] {
    $e2eLockAvailable = $true
}

$e2eBase = New-TestTempDirectory -Prefix 'sdtest-orch-e2e'
$e2eRepo = Join-Path $e2eBase 'repo'
Initialize-TestGitRepo -Path $e2eRepo -Branch 'main'
'v1' | Set-Content -LiteralPath (Join-Path $e2eRepo 'app.txt') -Encoding ASCII -NoNewline
& git.exe -C $e2eRepo add -- app.txt 2>&1 | Out-Null
& git.exe -C $e2eRepo commit -m 'initial' --quiet 2>&1 | Out-Null
& git.exe -C $e2eRepo remote add origin 'https://example.invalid/x.git' 2>&1 | Out-Null
'uncommitted change for e2e dry run' | Set-Content -LiteralPath (Join-Path $e2eRepo 'app.txt') -Encoding ASCII -NoNewline

$e2eShaBefore = Get-LastCommitSha -RepositoryPath $e2eRepo

$e2eConfigPath = Join-Path $e2eBase 'config.json'
$e2eConfigObj = [PSCustomObject]@{
    deployment_service = [PSCustomObject]@{
        windows_scheduled_tasks           = @("SimpleDeployTestNonexistent-$([Guid]::NewGuid().ToString('N').Substring(0,8))")
        windows_process_names             = @('php.exe')
        windows_process_commandline_marker = 'NoSuchDeploymentMarkerAtAll'
        linux_service_stop_timeout_seconds = 5
    }
    ssh     = [PSCustomObject]@{ known_hosts_path = (Join-Path $env:USERPROFILE '.ssh\known_hosts'); connect_timeout_seconds = 5 }
    projects = [PSCustomObject]@{
        'e2e-dryrun-project' = [PSCustomObject]@{
            enabled = $true; local_path = $e2eRepo; branch = 'main'; remote = 'origin'
            build_mode = 'artifact'; activation_mode = 'working_directory'
            server_host = 'unreachable-on-purpose.invalid'; server_user = 'deploy'
            server_path = '/var/www/e2e'; ssh_key_path = 'C:\keys\id_ed25519'
            health_check_url = 'CHANGE_ME'; preserve_paths = @('.env'); retention_count = 3
            run_migrations = $false; database_backup = $false
            composer_command = $null; npm_command = $null; php_binary = $null
        }
    }
}
($e2eConfigObj | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $e2eConfigPath -Encoding ASCII

if ($e2eLockAvailable) {
    $e2eSimpleDeployPs1 = Join-Path $ToolRoot 'SimpleDeploy.ps1'
    $e2eResult = Invoke-ExternalCommand -FilePath 'powershell.exe' -ArgumentList @(
        '-NoProfile', '-ExecutionPolicy', 'Bypass', '-File', $e2eSimpleDeployPs1,
        '-Project', 'e2e-dryrun-project', '-DryRun', '-NonInteractive', '-ConfigPath', $e2eConfigPath
    ) -TimeoutSeconds 60

    Assert-That 'the real SimpleDeploy.ps1 -DryRun exits with code 0' ($e2eResult.ExitCode -eq 0)
    Assert-That 'the DryRun banner is shown to the operator' ($e2eResult.StdOut -match 'DRY-RUN')
    Assert-That 'the DryRun completion is reported for the project in the final report' ($e2eResult.StdOut -match 'DRY-RUN کامل شد')

    $e2eShaAfter = Get-LastCommitSha -RepositoryPath $e2eRepo
    Assert-That 'the real end-to-end -DryRun run creates ZERO commits' ($e2eShaAfter -eq $e2eShaBefore)
    $e2eStatusAfter = @(Get-GitStatusShort -RepositoryPath $e2eRepo)
    Assert-That 'the real end-to-end -DryRun run leaves the uncommitted change exactly as it was' ($e2eStatusAfter.Count -eq 1)
} else {
    Write-Host '    (Global\SimpleDeployerSingleInstance is currently held by something else on this machine -- skipping the real SimpleDeploy.ps1 subprocess e2e scenario)' -ForegroundColor Yellow
}

Remove-TestTempDirectory -Path $e2eBase
Write-TestSummaryAndExit -SuiteName 'Test-Orchestration'
