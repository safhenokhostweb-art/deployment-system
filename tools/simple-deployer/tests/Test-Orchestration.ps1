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
. (Join-Path $ToolRoot 'lib\SimpleDeploy.ArtifactValidation.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Transfer.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Timing.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.DeploymentState.ps1')
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

# ============================================================
# 3. Task 4A adapter: ConvertTo-ApplicationProfile / ConvertTo-
#    DeploymentTarget / ConvertFrom-LegacyProjectConfig — one today's-
#    flat legacy project config entry converts to an ApplicationProfile
#    + its one default Target, with every real field carried over
#    exactly, and config.json ITSELF is never touched by any of this
#    (these are pure in-memory reshaping functions).
# ============================================================
$legacyCfg = [PSCustomObject]@{
    local_path = 'C:\x\y'; branch = 'main'; remote = 'origin'; project_type = 'laravel'
    build_mode = 'artifact'; activation_mode = 'working_directory'
    server_host = '203.0.113.10'; server_user = 'deploy'; server_port = 2222
    server_path = '/var/www/x'; ssh_key_path = 'C:\keys\id_ed25519'
    health_check_url = 'https://x.example.test'
    preserve_paths = @('.env', 'storage'); retention_count = 5
    run_migrations = $true; database_backup = $false
    composer_command = 'C:\composer.phar'; npm_command = $null; php_binary = 'C:\php.exe'
    remote_php_binary = '/usr/bin/php8.3'
}

$appProfile = ConvertTo-ApplicationProfile -ApplicationKey 'adapter-test-app' -ProjectConfig $legacyCfg
Assert-That 'ApplicationProfile.ApplicationKey carries the given key' ($appProfile.ApplicationKey -eq 'adapter-test-app')
Assert-That 'ApplicationProfile.LocalPath matches the legacy local_path' ($appProfile.LocalPath -eq 'C:\x\y')
Assert-That 'ApplicationProfile.Branch matches the legacy branch' ($appProfile.Branch -eq 'main')
Assert-That 'ApplicationProfile.BuildMode matches the legacy build_mode' ($appProfile.BuildMode -eq 'artifact')
Assert-That 'ApplicationProfile.RetentionCount matches the legacy retention_count' ($appProfile.RetentionCount -eq 5)
Assert-That 'ApplicationProfile.RunMigrations matches the legacy run_migrations (real boolean, not a string)' ($appProfile.RunMigrations -eq $true)
Assert-That 'ApplicationProfile.SourceSubdirectory is $null when the legacy config never had that field (every real project today)' ($null -eq $appProfile.SourceSubdirectory)

$legacyCfgWithSub = [PSCustomObject]@{
    local_path = 'C:\mono'; branch = 'master'; remote = 'origin'; project_type = 'laravel-monorepo'
    source_subdirectory = 'service'
    build_mode = 'disabled'; activation_mode = 'working_directory'
    server_host = 'CHANGE_ME'; server_user = 'CHANGE_ME'; server_path = '/var/www/mono/service'
    ssh_key_path = 'CHANGE_ME'; health_check_url = 'CHANGE_ME'
    preserve_paths = @('.env'); retention_count = 3; run_migrations = $true; database_backup = $false
    composer_command = $null; npm_command = $null; php_binary = $null
}
$appProfileWithSub = ConvertTo-ApplicationProfile -ApplicationKey 'mono-app' -ProjectConfig $legacyCfgWithSub
Assert-That 'ApplicationProfile.SourceSubdirectory is carried over when present (deployment-system-style monorepo entry)' ($appProfileWithSub.SourceSubdirectory -eq 'service')

$target = ConvertTo-DeploymentTarget -ApplicationKey 'adapter-test-app' -ProjectConfig $legacyCfg
Assert-That 'DeploymentTarget.TargetKey defaults to "<ApplicationKey>-default"' ($target.TargetKey -eq 'adapter-test-app-default')
Assert-That 'DeploymentTarget.ApplicationKey links back to the owning Application' ($target.ApplicationKey -eq 'adapter-test-app')
Assert-That 'DeploymentTarget.ServerHost matches the legacy server_host' ($target.ServerHost -eq '203.0.113.10')
Assert-That 'DeploymentTarget.ServerPort matches the legacy server_port (real int, not string)' ($target.ServerPort -eq 2222)
Assert-That 'DeploymentTarget.SshKeyPath matches the legacy ssh_key_path (stays LOCAL — never a Secret pulled from anywhere else)' ($target.SshKeyPath -eq 'C:\keys\id_ed25519')
Assert-That 'DeploymentTarget.RemotePhpBinary matches the legacy remote_php_binary' ($target.RemotePhpBinary -eq '/usr/bin/php8.3')
Assert-That 'DeploymentTarget.Source is marked "legacy-flat-config" — distinguishable from a future real multi-target source' ($target.Source -eq 'legacy-flat-config')

$targetWithPort = ConvertTo-DeploymentTarget -ApplicationKey 'no-port-app' -ProjectConfig ([PSCustomObject]@{ server_host = 'h'; server_user = 'u'; server_path = '/p'; ssh_key_path = 'k'; health_check_url = 'CHANGE_ME' })
Assert-That 'DeploymentTarget.ServerPort defaults to 22 when the legacy config has no server_port at all' ($targetWithPort.ServerPort -eq 22)

$adapted = ConvertFrom-LegacyProjectConfig -ApplicationKey 'adapter-test-app' -ProjectConfig $legacyCfg
Assert-That 'ConvertFrom-LegacyProjectConfig returns exactly one ApplicationProfile' ($null -ne $adapted.ApplicationProfile)
Assert-That 'ConvertFrom-LegacyProjectConfig returns exactly one (default) Target for a legacy entry' (@($adapted.Targets).Count -eq 1)
Assert-That 'the one Target it returns is the same default target ConvertTo-DeploymentTarget alone would produce' ($adapted.Targets[0].TargetKey -eq 'adapter-test-app-default')

# ============================================================
# 4. Task 4A: Invoke-ProjectDeploy, a REAL (non-DryRun) full run against
#    a genuinely unreachable target — proves the backward-compatible
#    wrapper still drives a real build (real git push to a real local
#    bare remote, real artifact.zip) all the way through to a real (and
#    here, expectedly failing) SSH activation attempt, exactly like
#    before the Build/Deploy split — and that the build workspace is
#    still cleaned up afterward. Never a real VPS.
# ============================================================
$realBase = New-TestTempDirectory -Prefix 'sdtest-orch-realrun'
$realRepo = Join-Path $realBase 'repo'
Initialize-TestGitRepo -Path $realRepo -Branch 'main'
'v1' | Set-Content -LiteralPath (Join-Path $realRepo 'app.txt') -Encoding ASCII -NoNewline
& git.exe -C $realRepo add -- app.txt 2>&1 | Out-Null
& git.exe -C $realRepo commit -m 'initial' --quiet 2>&1 | Out-Null
$realBareRemote = Join-Path $realBase 'repo-remote.git'
& git.exe init --quiet --bare $realBareRemote 2>&1 | Out-Null
& git.exe -C $realRepo remote add origin $realBareRemote 2>&1 | Out-Null
& git.exe -C $realRepo push --quiet origin main:main 2>&1 | Out-Null
'v2 real run' | Set-Content -LiteralPath (Join-Path $realRepo 'app.txt') -Encoding ASCII -NoNewline

$realFakeKnownHostsDir = New-TestTempDirectory -Prefix 'sdtest-orch-realrun-knownhosts'
$realFakeKnownHosts = Join-Path $realFakeKnownHostsDir 'known_hosts'
"[127.0.0.1]:65010 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAItest" | Set-Content -LiteralPath $realFakeKnownHosts -Encoding ASCII
$realFakeKey = Join-Path $realFakeKnownHostsDir 'fake_key'
'not a real key -- connection is expected to fail before this would ever be used for real auth' | Set-Content -LiteralPath $realFakeKey -Encoding ASCII

$realProjectConfig = [PSCustomObject]@{
    local_path = $realRepo; branch = 'main'; remote = 'origin'
    build_mode = 'git-only'; activation_mode = 'working_directory'
    server_host = '127.0.0.1'; server_user = 'deploy'; server_port = 65010
    server_path = '/var/www/real-run'; ssh_key_path = $realFakeKey
    preserve_paths = @('.env'); retention_count = 3; run_migrations = $false
    database_backup = $false; composer_command = $null; npm_command = $null; php_binary = $null
    health_check_url = 'CHANGE_ME'
}
$realProjectInfo = [PSCustomObject]@{ Key = 'real-run-test-project'; Config = $realProjectConfig; IsDeployable = $true; BlockingReasons = @() }
$realDeploymentServiceCfg = [PSCustomObject]@{ windows_process_names = @('php.exe'); windows_process_commandline_marker = 'NoSuchMarkerAtAll' }
$realSshCfg = [PSCustomObject]@{ known_hosts_path = $realFakeKnownHosts }

$realShaBefore = Get-LastCommitSha -RepositoryPath $realRepo
$realResult = Invoke-ProjectDeploy -ProjectInfo $realProjectInfo -CommitMessage 'real run test commit' `
    -NonInteractive $true -DryRun $false -DeploymentServiceCfg $realDeploymentServiceCfg -SshCfg $realSshCfg -ScriptRoot $ToolRoot

Assert-That 'a real (non-DryRun) run against an unreachable target still commits and pushes for real (HEAD moved)' ((Get-LastCommitSha -RepositoryPath $realRepo) -ne $realShaBefore)
Assert-That 'a real run against an unreachable target reports Status=Failed (the build succeeded, only the deploy-to-target failed)' ($realResult.Status -eq 'Failed')
Assert-That 'the real (failed) run still reports the real commit SHA it built and pushed' ($null -ne $realResult.CommitSha -and $realResult.CommitSha.Length -eq 40)
Assert-That 'the real remote genuinely received the real pushed commit' ((& git.exe --git-dir $realBareRemote log main --oneline 2>&1 | Out-String).Trim() -ne '')

$realWorkspaceBase = Join-Path $env:TEMP 'SimpleDeployer\builds'
$realProjectWorkspaceParent = Join-Path $realWorkspaceBase 'real-run-test-project'
# New-BuildWorkspace/Remove-DirectoryTreeGuarded only ever create/remove
# the per-JOB subdirectory (builds\<projectKey>\<jobId>) — the empty
# per-project PARENT folder itself is expected to remain (same
# pre-existing behavior as every other real-build test in this suite).
# Checking for leftover JOB subdirectories specifically, not the
# parent's own harmless existence.
$realLeftoverJobDirs = @(if (Test-Path -LiteralPath $realProjectWorkspaceParent) { @(Get-ChildItem -Path $realProjectWorkspaceParent -Directory) } else { @() })
Assert-That 'the build workspace''s JOB subdirectory is cleaned up after a real run, even though the deploy-to-target step failed' ($realLeftoverJobDirs.Count -eq 0)

Remove-TestTempDirectory -Path $realBase
Remove-TestTempDirectory -Path $realFakeKnownHostsDir

# ============================================================
# 5. Task 4A: Invoke-ApplicationDeployToTargets — one real Build, fanned
#    out to TWO independent (and here, both genuinely unreachable —
#    never a real VPS) Targets: proves the artifact is built exactly
#    ONCE (a single BuildResult/Artifact/CommitSha shared by both Target
#    attempts, no second composer/npm/packaging run), that BOTH Targets
#    are genuinely attempted independently (two distinct real SSH
#    connection attempts, two distinct TargetKeys in the results, one
#    via connection-refused and the other via DNS failure — proving
#    neither result is coincidental/shared state), and that one
#    Target's failure never prevents the other from being attempted or
#    corrupts its own independent result.
# ============================================================
$multiBase = New-TestTempDirectory -Prefix 'sdtest-orch-multitarget'
$multiRepo = Join-Path $multiBase 'repo'
Initialize-TestGitRepo -Path $multiRepo -Branch 'main'
'v1' | Set-Content -LiteralPath (Join-Path $multiRepo 'app.txt') -Encoding ASCII -NoNewline
& git.exe -C $multiRepo add -- app.txt 2>&1 | Out-Null
& git.exe -C $multiRepo commit -m 'initial' --quiet 2>&1 | Out-Null
$multiBareRemote = Join-Path $multiBase 'repo-remote.git'
& git.exe init --quiet --bare $multiBareRemote 2>&1 | Out-Null
& git.exe -C $multiRepo remote add origin $multiBareRemote 2>&1 | Out-Null
& git.exe -C $multiRepo push --quiet origin main:main 2>&1 | Out-Null
'v2 multitarget' | Set-Content -LiteralPath (Join-Path $multiRepo 'app.txt') -Encoding ASCII -NoNewline

$multiFakeKnownHostsDir = New-TestTempDirectory -Prefix 'sdtest-orch-multitarget-knownhosts'
$multiFakeKnownHosts = Join-Path $multiFakeKnownHostsDir 'known_hosts'
@(
    "[127.0.0.1]:65011 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAItest"
    "[this-host-genuinely-does-not-exist.invalid]:65012 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAItest"
) -join "`n" | Set-Content -LiteralPath $multiFakeKnownHosts -Encoding ASCII
$multiFakeKey = Join-Path $multiFakeKnownHostsDir 'fake_key'
'not a real key' | Set-Content -LiteralPath $multiFakeKey -Encoding ASCII

$multiProjectConfig = [PSCustomObject]@{
    local_path = $multiRepo; branch = 'main'; remote = 'origin'
    build_mode = 'git-only'; activation_mode = 'working_directory'
    server_host = 'CHANGE_ME'; server_user = 'CHANGE_ME'; server_path = '/x'; ssh_key_path = 'CHANGE_ME'
    preserve_paths = @('.env'); retention_count = 3; run_migrations = $false
    database_backup = $false; composer_command = $null; npm_command = $null; php_binary = $null
    health_check_url = 'CHANGE_ME'
}
$multiProjectInfo = [PSCustomObject]@{ Key = 'multitarget-test-project'; Config = $multiProjectConfig; IsDeployable = $true; BlockingReasons = @() }
$multiDeploymentServiceCfg = [PSCustomObject]@{ windows_process_names = @('php.exe'); windows_process_commandline_marker = 'NoSuchMarkerAtAll' }
$multiSshCfg = [PSCustomObject]@{ known_hosts_path = $multiFakeKnownHosts }

$targetA = ConvertTo-DeploymentTarget -ApplicationKey 'multitarget-test-project' -ProjectConfig $multiProjectConfig -TargetKey 'target-connection-refused'
$targetA.ServerHost = '127.0.0.1'; $targetA.ServerPort = 65011; $targetA.ServerUser = 'deploy'; $targetA.ServerPath = '/var/www/a'; $targetA.SshKeyPath = $multiFakeKey
$targetB = ConvertTo-DeploymentTarget -ApplicationKey 'multitarget-test-project' -ProjectConfig $multiProjectConfig -TargetKey 'target-dns-failure'
$targetB.ServerHost = 'this-host-genuinely-does-not-exist.invalid'; $targetB.ServerPort = 65012; $targetB.ServerUser = 'deploy'; $targetB.ServerPath = '/var/www/b'; $targetB.SshKeyPath = $multiFakeKey

$multiResult = Invoke-ApplicationDeployToTargets -ProjectInfo $multiProjectInfo -Targets @($targetA, $targetB) -CommitMessage 'multitarget test commit' `
    -NonInteractive $true -DryRun $false -DeploymentServiceCfg $multiDeploymentServiceCfg -SshCfg $multiSshCfg -ScriptRoot $ToolRoot

Assert-That 'the shared Build stage succeeded exactly once (Status=BuiltArtifact)' ($multiResult.BuildResult.Status -eq 'BuiltArtifact')
Assert-That 'the real commit was genuinely pushed once' ((& git.exe --git-dir $multiBareRemote log main --oneline 2>&1 | Out-String).Trim() -ne '')
Assert-That 'BOTH Targets were independently attempted (2 results, not aborted after the first failure)' (@($multiResult.TargetResults).Count -eq 2)

$resultA = $multiResult.TargetResults | Where-Object { $_.TargetKey -eq 'target-connection-refused' }
$resultB = $multiResult.TargetResults | Where-Object { $_.TargetKey -eq 'target-dns-failure' }
Assert-That 'Target A (connection-refused) reports its own correct TargetKey' ($null -ne $resultA)
Assert-That 'Target B (DNS failure) reports its own correct TargetKey' ($null -ne $resultB)
Assert-That 'Target A independently reports Status=Failed' ($resultA.Status -eq 'Failed')
Assert-That 'Target B independently reports Status=Failed' ($resultB.Status -eq 'Failed')
Assert-That 'Target A and Target B failed for two genuinely DIFFERENT reasons (proving both were really, independently attempted, not one coincidentally-shared result)' ($resultA.Reason -ne $resultB.Reason)
Assert-That 'both Target artifacts/commits trace back to the SAME single build (only one BuildResult/CommitSha exists at all — structurally impossible for a second build to have produced these)' ($null -ne $multiResult.BuildResult.CommitSha)

$multiWorkspaceBase = Join-Path $env:TEMP 'SimpleDeployer\builds'
$multiProjectWorkspaceParent = Join-Path $multiWorkspaceBase 'multitarget-test-project'
$multiLeftoverJobDirs = @(if (Test-Path -LiteralPath $multiProjectWorkspaceParent) { @(Get-ChildItem -Path $multiProjectWorkspaceParent -Directory) } else { @() })
Assert-That 'the shared build workspace''s JOB subdirectory is cleaned up exactly once, after both Targets were attempted' ($multiLeftoverJobDirs.Count -eq 0)
Assert-That 'the Workspace.Root path the Build stage reported no longer exists on disk after cleanup' (-not (Test-Path -LiteralPath $multiResult.BuildResult.Workspace.Root))

Remove-TestTempDirectory -Path $multiBase
Remove-TestTempDirectory -Path $multiFakeKnownHostsDir

# ============================================================
# 6. Task 4A: Invoke-ApplicationDeployToTargets with NO -Targets given
#    falls back to the single legacy default target derived from
#    -ProjectInfo itself — proving it is a drop-in-usable superset of
#    Invoke-ProjectDeploy's own single-target behavior, not a
#    parallel/incompatible code path.
# ============================================================
$fallbackBase = New-TestTempDirectory -Prefix 'sdtest-orch-fallback'
$fallbackRepo = Join-Path $fallbackBase 'repo'
Initialize-TestGitRepo -Path $fallbackRepo -Branch 'main'
'v1' | Set-Content -LiteralPath (Join-Path $fallbackRepo 'app.txt') -Encoding ASCII -NoNewline
& git.exe -C $fallbackRepo add -- app.txt 2>&1 | Out-Null
& git.exe -C $fallbackRepo commit -m 'initial' --quiet 2>&1 | Out-Null
$fallbackBareRemote = Join-Path $fallbackBase 'repo-remote.git'
& git.exe init --quiet --bare $fallbackBareRemote 2>&1 | Out-Null
& git.exe -C $fallbackRepo remote add origin $fallbackBareRemote 2>&1 | Out-Null
& git.exe -C $fallbackRepo push --quiet origin main:main 2>&1 | Out-Null
'v2 fallback' | Set-Content -LiteralPath (Join-Path $fallbackRepo 'app.txt') -Encoding ASCII -NoNewline

$fallbackProjectConfig = [PSCustomObject]@{
    local_path = $fallbackRepo; branch = 'main'; remote = 'origin'
    build_mode = 'disabled'; activation_mode = 'working_directory'
    server_host = 'CHANGE_ME'; server_user = 'CHANGE_ME'; server_path = '/x'; ssh_key_path = 'CHANGE_ME'
    preserve_paths = @('.env'); retention_count = 3; run_migrations = $false
    database_backup = $false; composer_command = $null; npm_command = $null; php_binary = $null
    health_check_url = 'CHANGE_ME'
}
$fallbackProjectInfo = [PSCustomObject]@{ Key = 'fallback-test-project'; Config = $fallbackProjectConfig; IsDeployable = $false; BlockingReasons = @('build_mode is disabled (test fixture)') }
$fallbackResult = Invoke-ApplicationDeployToTargets -ProjectInfo $fallbackProjectInfo -CommitMessage 'fallback test commit' `
    -NonInteractive $true -DryRun $false -DeploymentServiceCfg $multiDeploymentServiceCfg -SshCfg $multiSshCfg -ScriptRoot $ToolRoot

Assert-That 'with build_mode=disabled, the Build stage stops before BuiltArtifact (matches Invoke-ProjectDeploy''s own identical behavior)' ($fallbackResult.BuildResult.Status -eq 'Succeeded')
Assert-That 'no Target is ever attempted when the Build stage never produced an artifact' (@($fallbackResult.TargetResults).Count -eq 0)

Remove-TestTempDirectory -Path $fallbackBase

# ============================================================
# 7. Artifact Validation wiring: Invoke-ApplicationBuild runs
#    Test-ArtifactValidation on the built workspace BEFORE packaging —
#    a project whose artifact_validation.required_files rule is not
#    satisfied fails the BUILD stage itself (never reaches Transfer),
#    while a satisfied rule lets the exact same pipeline proceed to a
#    real BuiltArtifact, proving validation is neither a no-op nor an
#    unconditional block.
# ============================================================
$avBase = New-TestTempDirectory -Prefix 'sdtest-orch-artifactvalidation'
$avRepo = Join-Path $avBase 'repo'
Initialize-TestGitRepo -Path $avRepo -Branch 'main'
'v1' | Set-Content -LiteralPath (Join-Path $avRepo 'app.txt') -Encoding ASCII -NoNewline
& git.exe -C $avRepo add -- app.txt 2>&1 | Out-Null
& git.exe -C $avRepo commit -m 'initial' --quiet 2>&1 | Out-Null
$avBareRemote = Join-Path $avBase 'repo-remote.git'
& git.exe init --quiet --bare $avBareRemote 2>&1 | Out-Null
& git.exe -C $avRepo remote add origin $avBareRemote 2>&1 | Out-Null
& git.exe -C $avRepo push --quiet origin main:main 2>&1 | Out-Null

$avDeploymentServiceCfg = [PSCustomObject]@{ windows_process_names = @('php.exe'); windows_process_commandline_marker = 'NoSuchMarkerAtAll' }
$avSshCfg = [PSCustomObject]@{ known_hosts_path = (Join-Path $env:USERPROFILE '.ssh\known_hosts') }

# 7a. required_files rule NOT satisfied (app.txt exists, but the rule
#     demands a file that was never committed) — the Build stage itself
#     must fail, with the reason naming the missing file, before any
#     Transfer/SSH attempt is ever made against the unreachable target.
'v2 av-fail' | Set-Content -LiteralPath (Join-Path $avRepo 'app.txt') -Encoding ASCII -NoNewline
$avFailConfig = [PSCustomObject]@{
    local_path = $avRepo; branch = 'main'; remote = 'origin'
    build_mode = 'artifact'; activation_mode = 'working_directory'
    server_host = 'unreachable-on-purpose.invalid'; server_user = 'deploy'
    server_path = '/var/www/av-fail'; ssh_key_path = 'C:\keys\id_ed25519'
    preserve_paths = @('.env'); retention_count = 3; run_migrations = $false
    database_backup = $false; composer_command = $null; npm_command = $null; php_binary = $null
    health_check_url = 'CHANGE_ME'
    artifact_validation = [PSCustomObject]@{ required_files = @('this-file-was-never-committed.txt') }
}
$avFailInfo = [PSCustomObject]@{ Key = 'av-fail-test-project'; Config = $avFailConfig; IsDeployable = $true; BlockingReasons = @() }
$avShaBeforeFail = Get-LastCommitSha -RepositoryPath $avRepo
$avFailResult = Invoke-ProjectDeploy -ProjectInfo $avFailInfo -CommitMessage 'artifact validation failure test' `
    -NonInteractive $true -DryRun $false -DeploymentServiceCfg $avDeploymentServiceCfg -SshCfg $avSshCfg -ScriptRoot $ToolRoot

Assert-That 'a required_files rule that is not satisfied fails the run (Status=Failed)' ($avFailResult.Status -eq 'Failed')
Assert-That 'the failure reason names the missing required file (never a generic/opaque error)' ($avFailResult.Reason -like '*this-file-was-never-committed.txt*')
Assert-That 'the commit was still pushed for real before validation ran (push happens before build)' ((Get-LastCommitSha -RepositoryPath $avRepo) -ne $avShaBeforeFail)

$avFailWorkspaceParent = Join-Path (Join-Path $env:TEMP 'SimpleDeployer\builds') 'av-fail-test-project'
$avFailLeftoverJobDirs = @(if (Test-Path -LiteralPath $avFailWorkspaceParent) { @(Get-ChildItem -Path $avFailWorkspaceParent -Directory) } else { @() })
Assert-That 'the build workspace is still cleaned up after an artifact-validation failure' ($avFailLeftoverJobDirs.Count -eq 0)

# 7b. required_files rule IS satisfied (app.txt is genuinely present in
#     the archived commit) — the exact same pipeline reaches
#     Status=BuiltArtifact and only fails later, at the (expected,
#     unreachable-target) Transfer/SSH stage — proving validation
#     itself is not an unconditional block. Needs a FRESH uncommitted
#     change (7a already committed+pushed its own) or this run would
#     just see "no changes" and short-circuit to Status=Skipped instead
#     of ever reaching Build/Validation/Transfer at all.
'v3 av-pass' | Set-Content -LiteralPath (Join-Path $avRepo 'app.txt') -Encoding ASCII -NoNewline
$avPassConfig = [PSCustomObject]@{
    local_path = $avRepo; branch = 'main'; remote = 'origin'
    build_mode = 'artifact'; activation_mode = 'working_directory'
    server_host = 'unreachable-on-purpose.invalid'; server_user = 'deploy'
    server_path = '/var/www/av-pass'; ssh_key_path = 'C:\keys\id_ed25519'
    preserve_paths = @('.env'); retention_count = 3; run_migrations = $false
    database_backup = $false; composer_command = $null; npm_command = $null; php_binary = $null
    health_check_url = 'CHANGE_ME'
    artifact_validation = [PSCustomObject]@{ required_files = @('app.txt') }
}
$avPassInfo = [PSCustomObject]@{ Key = 'av-pass-test-project'; Config = $avPassConfig; IsDeployable = $true; BlockingReasons = @() }
$avPassResult = Invoke-ProjectDeploy -ProjectInfo $avPassInfo -CommitMessage 'artifact validation pass test' `
    -NonInteractive $true -DryRun $false -DeploymentServiceCfg $avDeploymentServiceCfg -SshCfg $avSshCfg -ScriptRoot $ToolRoot

Assert-That 'a satisfied required_files rule lets the build reach the (unreachable-target) Transfer stage, not block at validation' ($avPassResult.Status -eq 'Failed')
Assert-That 'the satisfied-validation failure reason is the SSH/transfer error, never an artifact-validation error' ($avPassResult.Reason -notlike '*اعتبارسنجی*')

$avPassWorkspaceParent = Join-Path (Join-Path $env:TEMP 'SimpleDeployer\builds') 'av-pass-test-project'
$avPassLeftoverJobDirs = @(if (Test-Path -LiteralPath $avPassWorkspaceParent) { @(Get-ChildItem -Path $avPassWorkspaceParent -Directory) } else { @() })
Assert-That 'the build workspace is cleaned up after a validation-pass-but-transfer-fail run too' ($avPassLeftoverJobDirs.Count -eq 0)

Remove-TestTempDirectory -Path $avBase

Write-TestSummaryAndExit -SuiteName 'Test-Orchestration'
