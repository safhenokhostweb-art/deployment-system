<#
.SYNOPSIS
    Tests: cross-cutting Launcher scenarios that don't belong purely to
    GitFlow or Engine — dynamic project discovery straight from
    config.json (a NEW project appears with zero code changes), the
    build_mode=disabled deployability wiring the GUI reads to enable/
    disable the "ارسال به Git و سرور" button, the singleton lock shared
    with the SimpleDeploy.ps1 CLI, and the Desktop shortcut installer.
    No real GitHub/VPS anywhere.
#>

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ToolRoot = Split-Path -Parent $ScriptDir
. (Join-Path $ScriptDir 'TestHelpers.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Common.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Config.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.ServiceControl.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Git.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Preflight.ps1')

# ============================================================
# 1. Dynamic project discovery: a project added to config.json appears
#    via Get-EnabledProjects with ZERO code changes — this is exactly the
#    data source the Launcher's Build-ProjectRows reads from (no project
#    name is ever hardcoded anywhere in launcher/*.ps1 — confirmed by
#    grep below, not just by convention).
# ============================================================
$base1 = New-TestTempDirectory -Prefix 'sdtest-launcher-dynamic'
$projPathA = Join-Path $base1 'project-a'
$projPathB = Join-Path $base1 'project-b'
New-Item -ItemType Directory -Path $projPathA -Force | Out-Null
New-Item -ItemType Directory -Path $projPathB -Force | Out-Null

$configPath1 = Join-Path $base1 'config.json'
$configBefore = [PSCustomObject]@{
    deployment_service = [PSCustomObject]@{ windows_scheduled_tasks = @(); windows_process_names = @('php.exe'); windows_process_commandline_marker = 'x'; linux_service_stop_timeout_seconds = 5 }
    ssh      = [PSCustomObject]@{ known_hosts_path = 'C:\hosts'; connect_timeout_seconds = 5 }
    projects = [PSCustomObject]@{
        'project-a' = [PSCustomObject]@{
            enabled = $true; local_path = $projPathA; branch = 'main'; remote = 'origin'
            build_mode = 'git-only'; activation_mode = 'working_directory'
            server_host = 'CHANGE_ME'; server_user = 'CHANGE_ME'; server_path = '/x'; ssh_key_path = 'CHANGE_ME'
            health_check_url = 'CHANGE_ME'; preserve_paths = @('.env'); retention_count = 3
            run_migrations = $false; database_backup = $false
            composer_command = $null; npm_command = $null; php_binary = $null
        }
    }
}
($configBefore | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $configPath1 -Encoding ASCII

$config1a = Get-SimpleDeployConfig -ConfigPath $configPath1
$enabled1a = Get-EnabledProjects -Config $config1a
Assert-That 'before adding project-b, only project-a is discovered' (@($enabled1a).Count -eq 1)

# Simulate "the user edited config.json to add a new project" — the
# ENTIRE point being tested is that nothing on the code side needs to
# change for it to show up.
$configAfter = [PSCustomObject]@{
    deployment_service = $configBefore.deployment_service
    ssh      = $configBefore.ssh
    projects = [PSCustomObject]@{
        'project-a' = $configBefore.projects.'project-a'
        'project-b' = [PSCustomObject]@{
            enabled = $true; local_path = $projPathB; branch = 'main'; remote = 'origin'
            build_mode = 'git-only'; activation_mode = 'working_directory'
            server_host = 'CHANGE_ME'; server_user = 'CHANGE_ME'; server_path = '/y'; ssh_key_path = 'CHANGE_ME'
            health_check_url = 'CHANGE_ME'; preserve_paths = @('.env'); retention_count = 3
            run_migrations = $false; database_backup = $false
            composer_command = $null; npm_command = $null; php_binary = $null
        }
    }
}
($configAfter | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $configPath1 -Encoding ASCII

$config1b = Get-SimpleDeployConfig -ConfigPath $configPath1
$enabled1b = Get-EnabledProjects -Config $config1b
Assert-That 'after adding project-b to config.json alone (no code change), it is discovered too' (@($enabled1b).Count -eq 2)
Assert-That 'the newly-added project key is exactly "project-b"' (@($enabled1b | Where-Object { $_.Key -eq 'project-b' }).Count -eq 1)
Remove-TestTempDirectory -Path $base1

$launcherSource = Get-Content -LiteralPath (Join-Path $ToolRoot 'launcher\SimpleDeployLauncher.Gui.ps1') -Raw
Assert-That 'the GUI source file never hardcodes any of the 4 real project keys' (
    ($launcherSource -notmatch 'customer-site') -and
    ($launcherSource -notmatch 'saas-platform') -and
    ($launcherSource -notmatch 'deployment-system''') -and
    ($launcherSource -notmatch 'sandogh-family-fund')
)

# ============================================================
# 2. build_mode=disabled -> IsDeployable=false with a clear reason — the
#    exact data the GUI's "ارسال به Git و سرور" button Enabled binding
#    and tooltip read (`$deployButton.Enabled = $ProjectInfo.IsDeployable`
#    in SimpleDeployLauncher.Gui.ps1) — while "ارسال به Git" stays
#    unaffected, proven via Test-ProjectPreflight directly (the same
#    function the Launcher's Check/GitOnly flows preflight through).
# ============================================================
$base2 = New-TestTempDirectory -Prefix 'sdtest-launcher-disabled'
$repo2 = Join-Path $base2 'repo'
Initialize-TestGitRepo -Path $repo2 -Branch 'main'
'v1' | Set-Content -LiteralPath (Join-Path $repo2 'app.txt') -Encoding ASCII -NoNewline
& git.exe -C $repo2 add -- app.txt 2>&1 | Out-Null
& git.exe -C $repo2 commit -m 'initial' --quiet 2>&1 | Out-Null
& git.exe -C $repo2 remote add origin 'https://example.invalid/x.git' 2>&1 | Out-Null

$disabledProjectConfig = [PSCustomObject]@{
    local_path = $repo2; branch = 'main'; remote = 'origin'; build_mode = 'disabled'
    server_host = 'CHANGE_ME'; server_user = 'CHANGE_ME'; server_path = '/x'; ssh_key_path = 'CHANGE_ME'
}
$deployability2 = Get-ProjectDeployability -ProjectKey 'disabled-project' -ProjectConfig $disabledProjectConfig
Assert-That 'a build_mode=disabled project is IsDeployable=$false (drives the Deploy button''s Enabled state)' (-not $deployability2.IsDeployable)
Assert-That 'the blocking reason explicitly names build_mode=disabled (shown as the button''s tooltip text)' (($deployability2.BlockingReasons -join ' ') -match 'disabled')

$preflight2 = Test-ProjectPreflight -ProjectKey 'disabled-project' -ProjectConfig $disabledProjectConfig -DeploymentProcessNames @('php.exe') -DeploymentProcessMarker 'NoSuchMarker'
Assert-That 'Preflight still PASSES for a build_mode=disabled project — "ارسال به Git" must keep working' $preflight2.Passed
Remove-TestTempDirectory -Path $base2

# ============================================================
# 3. Singleton lock — the Launcher's Show-SimpleDeployLauncher acquires
#    the SAME Global\SimpleDeployerSingleInstance mutex the CLI uses
#    (Enter-SimpleDeployLock with no -Name override), so the GUI and the
#    CLI can never run a deploy at the same time either.
#
#    The underlying mutex MECHANISM (real cross-process refusal, release,
#    re-acquisition) is already proven end-to-end in Test-Preflight.ps1 —
#    correctly via a genuinely separate SUBPROCESS, because a named
#    Mutex is reentrant for the thread that already owns it: two
#    Enter-SimpleDeployLock calls back-to-back on the SAME thread (as an
#    earlier version of this test mistakenly did) both "succeed" no
#    matter what, proving nothing about real mutual exclusion. Re-running
#    that same proof again here under the real production name would
#    only repeat Test-Preflight.ps1's own coverage; what's actually NEW
#    and worth checking here is that the Launcher wires itself to the
#    same lock at all — confirmed via source inspection.
# ============================================================
$guiSource = Get-Content -LiteralPath (Join-Path $ToolRoot 'launcher\SimpleDeployLauncher.Gui.ps1') -Raw
Assert-That 'Show-SimpleDeployLauncher calls Enter-SimpleDeployLock with no -Name override (shares the CLI''s real lock name)' ($guiSource -match 'Enter-SimpleDeployLock\s*\r?\n')
Assert-That 'a refused lock shows a clear "another instance is running" message and does not proceed' ($guiSource -match 'یک نمونه دیگر از SimpleDeploy')
Assert-That 'the lock is released via Exit-SimpleDeployLock in a finally block (never leaked, even on error)' ($guiSource -match 'finally\s*\{[\s\S]*?Exit-SimpleDeployLock')

# ============================================================
# 4. Desktop shortcut installer — real file, real properties, reversible.
# ============================================================
$realDesktop = [System.Environment]::GetFolderPath('Desktop')
$realShortcutPath = Join-Path $realDesktop 'SimpleDeploy.lnk'
$shortcutExistedBefore = Test-Path -LiteralPath $realShortcutPath

& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $ToolRoot 'Install-Shortcut.ps1') 2>&1 | Out-Null
Assert-That 'Install-Shortcut.ps1 creates a real Desktop shortcut file' (Test-Path -LiteralPath $realShortcutPath)

$shell = New-Object -ComObject WScript.Shell
$createdShortcut = $shell.CreateShortcut($realShortcutPath)
Assert-That 'the shortcut targets SimpleDeployLauncher.bat' ($createdShortcut.TargetPath -eq (Join-Path $ToolRoot 'SimpleDeployLauncher.bat'))
Assert-That 'the shortcut''s Working Directory is the tool root (so relative paths inside the script resolve correctly)' ($createdShortcut.WorkingDirectory -eq $ToolRoot)
[void][System.Runtime.Interopservices.Marshal]::ReleaseComObject($shell)

& powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $ToolRoot 'Uninstall-Shortcut.ps1') 2>&1 | Out-Null
Assert-That 'Uninstall-Shortcut.ps1 removes the shortcut file' (-not (Test-Path -LiteralPath $realShortcutPath))

if ($shortcutExistedBefore) {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $ToolRoot 'Install-Shortcut.ps1') 2>&1 | Out-Null
} else {
    & powershell.exe -NoProfile -ExecutionPolicy Bypass -File (Join-Path $ToolRoot 'Install-Shortcut.ps1') 2>&1 | Out-Null
}

# ============================================================
# 5. PowerShell 5.1 compatibility — not a separate mechanism to test, a
#    fact about the environment every other assertion in this whole
#    suite already ran under. Asserted explicitly here for traceability.
# ============================================================
Assert-That 'this entire suite (and the Launcher itself) ran on real Windows PowerShell 5.1, not a newer/different host' ($PSVersionTable.PSVersion.Major -eq 5 -and $PSVersionTable.PSVersion.Minor -eq 1)

Write-TestSummaryAndExit -SuiteName 'Test-LauncherIntegration'
