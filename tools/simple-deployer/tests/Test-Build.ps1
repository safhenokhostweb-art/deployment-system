<#
.SYNOPSIS
    Tests: isolated local build — working tree left untouched, workspace
    isolation (lives outside the repo), generic Composer path-dependency
    resolution + a REAL composer install against it, a REAL npm build
    (zero external packages, so it runs offline/fast), artifact
    exclusions (node_modules/.git never enter the zip), and a project
    path containing spaces.
#>

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ToolRoot = Split-Path -Parent $ScriptDir
. (Join-Path $ScriptDir 'TestHelpers.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Common.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Git.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Build.ps1')

# Composer needs a php.exe reachable on PATH; Laragon's php is not on the
# system PATH by default in every shell, so make it reachable for THIS
# test process only (never touches any real project/system config).
$laragonPhpDir = 'C:\laragon\bin\php\php-8.3-Win32-vs16-x64'
if ((Test-Path -LiteralPath $laragonPhpDir) -and ($env:PATH -notlike "*$laragonPhpDir*")) {
    $env:PATH = "$laragonPhpDir;$env:PATH"
}
$composerBat = 'C:\laragon\bin\composer\composer.bat'

# ============================================================
# 1. Working tree untouched + workspace isolation: git-archive a real
#    commit into a workspace living OUTSIDE the repo, mutate a file in
#    the workspace, and confirm the real working tree file is unchanged.
# ============================================================
$baseDir = New-TestTempDirectory -Prefix 'sdtest-build-base'
$repoRoot = Join-Path $baseDir 'repo'
Initialize-TestGitRepo -Path $repoRoot
'original content' | Set-Content -LiteralPath (Join-Path $repoRoot 'app.txt') -Encoding UTF8 -NoNewline
& git.exe -C $repoRoot add -- app.txt 2>&1 | Out-Null
& git.exe -C $repoRoot commit -m 'initial' --quiet 2>&1 | Out-Null
$commitSha = Get-LastCommitSha -RepositoryPath $repoRoot

$workspaceBase = Join-Path $baseDir 'workspaces'
New-Item -ItemType Directory -Path $workspaceBase -Force | Out-Null
$workspace = New-BuildWorkspace -BaseDir $workspaceBase -ProjectKey 'demo-project' -JobId 'job-001'

Assert-That 'the workspace root lives outside the repository working tree' (-not $workspace.Root.StartsWith($repoRoot, [StringComparison]::OrdinalIgnoreCase))
Assert-That 'the workspace project dir is a subdirectory named "project"' ((Split-Path -Leaf $workspace.ProjectDir) -eq 'project')

Export-GitArchiveToWorkspace -RepositoryPath $repoRoot -CommitSha $commitSha -DestinationDir $workspace.ProjectDir

$workspaceFile = Join-Path $workspace.ProjectDir 'app.txt'
Assert-That 'git archive populated the workspace with the committed file' (Test-Path -LiteralPath $workspaceFile)
Assert-That 'the archived content matches the committed content' ((Get-Content -LiteralPath $workspaceFile -Raw) -eq 'original content')

'MUTATED IN WORKSPACE' | Set-Content -LiteralPath $workspaceFile -Encoding UTF8 -NoNewline
$realFileContent = Get-Content -LiteralPath (Join-Path $repoRoot 'app.txt') -Raw
Assert-That 'mutating the workspace copy never touches the real working tree file' ($realFileContent -eq 'original content')

# Race guard: if HEAD no longer matches the expected SHA, refuse to build.
& git.exe -C $repoRoot commit --allow-empty -m 'a second commit moves HEAD' --quiet 2>&1 | Out-Null
$raceGuardThrew = $false
try {
    Export-GitArchiveToWorkspace -RepositoryPath $repoRoot -CommitSha $commitSha -DestinationDir (Join-Path $workspaceBase 'race-check')
} catch {
    $raceGuardThrew = $true
}
Assert-That 'exporting a SHA that no longer matches HEAD is refused (race guard)' $raceGuardThrew

# ============================================================
# 2. ZIP-Slip guard on extraction (defense in depth, even though
#    `git archive` itself never produces unsafe entry names).
# ============================================================
Add-Type -AssemblyName System.IO.Compression -ErrorAction SilentlyContinue
Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction SilentlyContinue
$evilZipPath = Join-Path $baseDir 'evil.zip'
$zipStream = [System.IO.File]::Open($evilZipPath, [System.IO.FileMode]::Create)
$zipArchive = New-Object System.IO.Compression.ZipArchive($zipStream, [System.IO.Compression.ZipArchiveMode]::Create)
$entry = $zipArchive.CreateEntry('../escaped.txt')
$writer = New-Object System.IO.StreamWriter($entry.Open())
$writer.Write('escaped')
$writer.Dispose()
$zipArchive.Dispose()
$zipStream.Dispose()

$zipSlipThrew = $false
try {
    Expand-ZipSafely -ZipPath $evilZipPath -Destination (Join-Path $baseDir 'zip-slip-target')
} catch {
    $zipSlipThrew = $true
}
Assert-That 'a zip entry containing ".." is rejected before extraction (ZIP-Slip guard)' $zipSlipThrew

# ============================================================
# 3. Composer path-dependency resolution: fully generic, no project
#    names hardcoded — reads composer.json from the ARCHIVED workspace
#    copy, copies the referenced sibling directory from its real, live
#    location into the workspace at the SAME relative position.
# ============================================================
$composerRepoRoot = Join-Path $baseDir 'composer-repo'
Initialize-TestGitRepo -Path $composerRepoRoot
$sharedLibRealPath = Join-Path $baseDir 'shared-lib'
New-Item -ItemType Directory -Path $sharedLibRealPath -Force | Out-Null
@'
{
    "name": "test/shared-lib",
    "type": "library",
    "version": "1.0.0",
    "autoload": { "psr-4": { "TestShared\\": "src/" } }
}
'@ | Set-Content -LiteralPath (Join-Path $sharedLibRealPath 'composer.json') -Encoding ASCII
New-Item -ItemType Directory -Path (Join-Path $sharedLibRealPath 'src') -Force | Out-Null
'<?php namespace TestShared; class Greeter { public static function hello() { return "hi"; } }' |
    Set-Content -LiteralPath (Join-Path $sharedLibRealPath 'src\Greeter.php') -Encoding ASCII

@'
{
    "name": "test/main",
    "minimum-stability": "dev",
    "prefer-stable": true,
    "require": { "test/shared-lib": "*" },
    "repositories": [ { "type": "path", "url": "../shared-lib" } ]
}
'@ | Set-Content -LiteralPath (Join-Path $composerRepoRoot 'composer.json') -Encoding ASCII
& git.exe -C $composerRepoRoot add -- composer.json 2>&1 | Out-Null
& git.exe -C $composerRepoRoot commit -m 'add composer.json with a path repository' --quiet 2>&1 | Out-Null
$composerCommitSha = Get-LastCommitSha -RepositoryPath $composerRepoRoot

$composerWorkspace = New-BuildWorkspace -BaseDir $workspaceBase -ProjectKey 'composer-demo' -JobId 'job-002'
Export-GitArchiveToWorkspace -RepositoryPath $composerRepoRoot -CommitSha $composerCommitSha -DestinationDir $composerWorkspace.ProjectDir
Resolve-ComposerPathDependencies -RepositoryPath $composerRepoRoot -WorkspaceRoot $composerWorkspace.Root -WorkspaceProjectDir $composerWorkspace.ProjectDir

$copiedSharedLib = Join-Path $composerWorkspace.Root 'shared-lib'
Assert-That 'the path-repository directory was copied into the workspace at the same relative position' (Test-Path -LiteralPath (Join-Path $copiedSharedLib 'composer.json'))
Assert-That 'the real shared-lib source directory is untouched (still present at its real location)' (Test-Path -LiteralPath (Join-Path $sharedLibRealPath 'composer.json'))

if (Test-Path -LiteralPath $composerBat) {
    $composerResult = Invoke-IsolatedComposerInstall -WorkspaceProjectDir $composerWorkspace.ProjectDir -ComposerCommand @($composerBat) -TimeoutSeconds 120
    Assert-That 'composer install ran (composer.json was present)' $composerResult.Ran
    Assert-That 'composer install --no-dev produced vendor/autoload.php' (Test-Path -LiteralPath (Join-Path $composerWorkspace.ProjectDir 'vendor\autoload.php'))
    Assert-That 'composer resolved the LOCAL path dependency (test/shared-lib) into vendor' (Test-Path -LiteralPath (Join-Path $composerWorkspace.ProjectDir 'vendor\test\shared-lib\src\Greeter.php'))
} else {
    Write-Host "    (composer.bat not found at $composerBat -- skipping the real composer install check)" -ForegroundColor Yellow
}

# Absolute path repository URLs are rejected (never silently followed).
$absoluteRepoRoot = Join-Path $baseDir 'composer-abs-repo'
Initialize-TestGitRepo -Path $absoluteRepoRoot
$escapedAbsUrl = (Join-Path $baseDir 'shared-lib').Replace('\', '\\')
@"
{
    "name": "test/main-abs",
    "repositories": [ { "type": "path", "url": "$escapedAbsUrl" } ]
}
"@ | Set-Content -LiteralPath (Join-Path $absoluteRepoRoot 'composer.json') -Encoding ASCII
& git.exe -C $absoluteRepoRoot add -- composer.json 2>&1 | Out-Null
& git.exe -C $absoluteRepoRoot commit -m 'absolute path repo' --quiet 2>&1 | Out-Null
$absCommitSha = Get-LastCommitSha -RepositoryPath $absoluteRepoRoot
$absWorkspace = New-BuildWorkspace -BaseDir $workspaceBase -ProjectKey 'composer-abs-demo' -JobId 'job-003'
Export-GitArchiveToWorkspace -RepositoryPath $absoluteRepoRoot -CommitSha $absCommitSha -DestinationDir $absWorkspace.ProjectDir
$absoluteRejected = $false
try {
    Resolve-ComposerPathDependencies -RepositoryPath $absoluteRepoRoot -WorkspaceRoot $absWorkspace.Root -WorkspaceProjectDir $absWorkspace.ProjectDir
} catch {
    $absoluteRejected = $true
}
Assert-That 'an absolute composer path-repository URL is rejected, never followed' $absoluteRejected

# ============================================================
# 4. npm build — zero external dependencies (so `npm ci` is fast and
#    needs no network), a real "build" script that writes a marker file.
# ============================================================
$npmCmd = (Get-Command npm.cmd -ErrorAction SilentlyContinue)
if ($npmCmd) {
    $npmRepoRoot = Join-Path $baseDir 'npm-repo'
    Initialize-TestGitRepo -Path $npmRepoRoot
    @'
{
    "name": "test-npm-project",
    "version": "1.0.0",
    "private": true,
    "scripts": { "build": "node -e \"require('fs').mkdirSync('dist',{recursive:true});require('fs').writeFileSync('dist/out.txt','built')\"" },
    "dependencies": {}
}
'@ | Set-Content -LiteralPath (Join-Path $npmRepoRoot 'package.json') -Encoding ASCII
    & git.exe -C $npmRepoRoot add -- package.json 2>&1 | Out-Null
    & git.exe -C $npmRepoRoot commit -m 'add package.json with a zero-dependency build script' --quiet 2>&1 | Out-Null
    $npmCommitSha = Get-LastCommitSha -RepositoryPath $npmRepoRoot

    $npmWorkspace = New-BuildWorkspace -BaseDir $workspaceBase -ProjectKey 'npm-demo' -JobId 'job-004'
    Export-GitArchiveToWorkspace -RepositoryPath $npmRepoRoot -CommitSha $npmCommitSha -DestinationDir $npmWorkspace.ProjectDir

    # `npm ci` requires an existing lockfile; generate one (offline-safe
    # since there are zero real dependencies to resolve).
    $lockResult = Invoke-ExternalCommand -FilePath $npmCmd.Source -ArgumentList @('install', '--package-lock-only', '--no-audit', '--no-fund') -WorkingDirectory $npmWorkspace.ProjectDir -TimeoutSeconds 120
    if ($lockResult.ExitCode -eq 0) {
        $npmResult = Invoke-IsolatedNpmBuild -WorkspaceProjectDir $npmWorkspace.ProjectDir -NpmCommand @('npm') -InstallTimeoutSeconds 120 -BuildTimeoutSeconds 120
        Assert-That 'npm ci ran (package.json + lockfile were present)' $npmResult.RanInstall
        Assert-That 'npm run build ran (a "build" script existed in package.json)' $npmResult.RanBuild
        Assert-That 'the build script actually executed and produced its output file' (Test-Path -LiteralPath (Join-Path $npmWorkspace.ProjectDir 'dist\out.txt'))
    } else {
        Write-Host "    (could not generate an offline package-lock.json -- skipping the real npm build check)" -ForegroundColor Yellow
        Write-Host "    $($lockResult.StdErr)" -ForegroundColor Yellow
    }
} else {
    Write-Host '    (npm not found on PATH -- skipping the real npm build check)' -ForegroundColor Yellow
}

# ============================================================
# 5. Artifact exclusions: node_modules/ and .git/ never enter the zip,
#    even if they physically exist inside the workspace project dir.
# ============================================================
$artifactWorkspace = New-BuildWorkspace -BaseDir $workspaceBase -ProjectKey 'artifact-demo' -JobId 'job-005'
$artifactProjectDir = $artifactWorkspace.ProjectDir
New-Item -ItemType Directory -Path (Join-Path $artifactProjectDir 'node_modules\some-pkg') -Force | Out-Null
'module.exports = {}' | Set-Content -LiteralPath (Join-Path $artifactProjectDir 'node_modules\some-pkg\index.js') -Encoding UTF8
New-Item -ItemType Directory -Path (Join-Path $artifactProjectDir '.git') -Force | Out-Null
'ref: refs/heads/main' | Set-Content -LiteralPath (Join-Path $artifactProjectDir '.git\HEAD') -Encoding UTF8
New-Item -ItemType Directory -Path (Join-Path $artifactProjectDir 'vendor') -Force | Out-Null
'<?php // autoloader' | Set-Content -LiteralPath (Join-Path $artifactProjectDir 'vendor\autoload.php') -Encoding UTF8
'<?php echo "app";' | Set-Content -LiteralPath (Join-Path $artifactProjectDir 'index.php') -Encoding UTF8

$artifactZipPath = Join-Path $artifactWorkspace.Root 'artifact.zip'
$artifact = New-DeploymentArtifact -WorkspaceProjectDir $artifactProjectDir -OutputZipPath $artifactZipPath

Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction SilentlyContinue
$readArchive = [System.IO.Compression.ZipFile]::OpenRead($artifactZipPath)
$entryNames = @($readArchive.Entries | ForEach-Object { $_.FullName })
$readArchive.Dispose()

Assert-That 'the artifact zip contains vendor/autoload.php' (@($entryNames) -contains 'vendor/autoload.php')
Assert-That 'the artifact zip contains index.php' (@($entryNames) -contains 'index.php')
Assert-That 'the artifact zip contains ZERO node_modules entries' (-not (@($entryNames) | Where-Object { $_ -match '(^|/)node_modules(/|$)' }))
Assert-That 'the artifact zip contains ZERO .git entries' (-not (@($entryNames) | Where-Object { $_ -match '(^|/)\.git(/|$)' }))
Assert-That 'artifact zip entry paths use forward slashes (Linux-safe), never backslashes' (-not (@($entryNames) | Where-Object { $_.Contains('\') }))
Assert-That 'New-DeploymentArtifact returns a real SHA-256 checksum' ($artifact.ChecksumSha256 -match '^[0-9a-f]{64}$')
Assert-That 'the checksum matches an independently recomputed hash of the same file' ($artifact.ChecksumSha256 -eq (Get-FileSha256 -Path $artifactZipPath))

# ============================================================
# 6. A project path containing spaces round-trips correctly end-to-end
#    (workspace creation, git archive, artifact build) — proves
#    Invoke-ExternalCommand's argv-array safety, not string concatenation.
# ============================================================
$spacedRepoRoot = Join-Path $baseDir 'repo with spaces'
Initialize-TestGitRepo -Path $spacedRepoRoot
'space test' | Set-Content -LiteralPath (Join-Path $spacedRepoRoot 'a b.txt') -Encoding UTF8
& git.exe -C $spacedRepoRoot add -- 'a b.txt' 2>&1 | Out-Null
& git.exe -C $spacedRepoRoot commit -m 'file with a space in its name' --quiet 2>&1 | Out-Null
$spacedCommitSha = Get-LastCommitSha -RepositoryPath $spacedRepoRoot

$spacedWorkspaceBase = Join-Path $baseDir 'workspaces with spaces'
New-Item -ItemType Directory -Path $spacedWorkspaceBase -Force | Out-Null
$spacedWorkspace = New-BuildWorkspace -BaseDir $spacedWorkspaceBase -ProjectKey 'spaced project' -JobId 'job 006'
Export-GitArchiveToWorkspace -RepositoryPath $spacedRepoRoot -CommitSha $spacedCommitSha -DestinationDir $spacedWorkspace.ProjectDir
Assert-That 'git archive succeeds for a repository path containing spaces' (Test-Path -LiteralPath (Join-Path $spacedWorkspace.ProjectDir 'a b.txt'))

$spacedArtifact = New-DeploymentArtifact -WorkspaceProjectDir $spacedWorkspace.ProjectDir -OutputZipPath (Join-Path $spacedWorkspace.Root 'artifact.zip')
Assert-That 'an artifact can be built from a workspace path containing spaces' ($spacedArtifact.SizeBytes -gt 0)

# ============================================================
# 7. Task 4B: -SourceSubdirectory on Export-GitArchiveToWorkspace — a
#    real monorepo fixture (agent/, local-agent/, tools/, root files,
#    and the real deployable app under service/), archived both ways.
# ============================================================
$monoBase = New-TestTempDirectory -Prefix 'sdtest-build-monorepo'
$monoRepo = Join-Path $monoBase 'repo'
Initialize-TestGitRepo -Path $monoRepo
New-Item -ItemType Directory -Path (Join-Path $monoRepo 'service\app') -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $monoRepo 'service\routes') -Force | Out-Null
New-Item -ItemType Directory -Path (Join-Path $monoRepo 'service\database') -Force | Out-Null
'#!/usr/bin/env php' | Set-Content -LiteralPath (Join-Path $monoRepo 'service\artisan') -Encoding ASCII -NoNewline
'{"name":"test/service"}' | Set-Content -LiteralPath (Join-Path $monoRepo 'service\composer.json') -Encoding ASCII -NoNewline
'<?php // Kernel' | Set-Content -LiteralPath (Join-Path $monoRepo 'service\app\Kernel.php') -Encoding ASCII -NoNewline
'<?php // web routes' | Set-Content -LiteralPath (Join-Path $monoRepo 'service\routes\web.php') -Encoding ASCII -NoNewline
'-- migration' | Set-Content -LiteralPath (Join-Path $monoRepo 'service\database\migration.sql') -Encoding ASCII -NoNewline
New-Item -ItemType Directory -Path (Join-Path $monoRepo 'agent') -Force | Out-Null
'<?php // unrelated local agent source' | Set-Content -LiteralPath (Join-Path $monoRepo 'agent\Agent.php') -Encoding ASCII -NoNewline
New-Item -ItemType Directory -Path (Join-Path $monoRepo 'local-agent') -Force | Out-Null
'<?php // unrelated local-agent source' | Set-Content -LiteralPath (Join-Path $monoRepo 'local-agent\Watchdog.php') -Encoding ASCII -NoNewline
New-Item -ItemType Directory -Path (Join-Path $monoRepo 'tools\simple-deployer') -Force | Out-Null
'# this very tool -- must never end up in the app artifact' | Set-Content -LiteralPath (Join-Path $monoRepo 'tools\simple-deployer\README.md') -Encoding ASCII -NoNewline
'# monorepo root readme' | Set-Content -LiteralPath (Join-Path $monoRepo 'README.md') -Encoding ASCII -NoNewline
& git.exe -C $monoRepo add -A 2>&1 | Out-Null
& git.exe -C $monoRepo commit -m 'monorepo fixture: service/ + agent/ + local-agent/ + tools/ + root files' --quiet 2>&1 | Out-Null
$monoCommitSha = Get-LastCommitSha -RepositoryPath $monoRepo

# --- Scenario 1: no -SourceSubdirectory at all (existing default) ---
$monoWorkspaceFull = New-BuildWorkspace -BaseDir $workspaceBase -ProjectKey 'monorepo-full' -JobId 'job-007'
Export-GitArchiveToWorkspace -RepositoryPath $monoRepo -CommitSha $monoCommitSha -DestinationDir $monoWorkspaceFull.ProjectDir
Assert-That 'Scenario 1 (no source_subdirectory): the whole monorepo is archived, exactly as before Task 4B' (Test-Path -LiteralPath (Join-Path $monoWorkspaceFull.ProjectDir 'agent\Agent.php'))
Assert-That 'Scenario 1: service/ is present too, still nested under service/ (unchanged/original layout)' (Test-Path -LiteralPath (Join-Path $monoWorkspaceFull.ProjectDir 'service\artisan'))
Assert-That 'Scenario 1: the monorepo root README is present' (Test-Path -LiteralPath (Join-Path $monoWorkspaceFull.ProjectDir 'README.md'))

# --- Scenario 4: -SourceSubdirectory explicitly $null / '' -- also backward compatible ---
$monoWorkspaceExplicitNull = New-BuildWorkspace -BaseDir $workspaceBase -ProjectKey 'monorepo-explicit-null' -JobId 'job-008'
Export-GitArchiveToWorkspace -RepositoryPath $monoRepo -CommitSha $monoCommitSha -DestinationDir $monoWorkspaceExplicitNull.ProjectDir -SourceSubdirectory $null
Assert-That 'Scenario 4a (-SourceSubdirectory $null): identical to the no-parameter case' (Test-Path -LiteralPath (Join-Path $monoWorkspaceExplicitNull.ProjectDir 'agent\Agent.php'))

$monoWorkspaceExplicitEmpty = New-BuildWorkspace -BaseDir $workspaceBase -ProjectKey 'monorepo-explicit-empty' -JobId 'job-009'
Export-GitArchiveToWorkspace -RepositoryPath $monoRepo -CommitSha $monoCommitSha -DestinationDir $monoWorkspaceExplicitEmpty.ProjectDir -SourceSubdirectory ''
Assert-That 'Scenario 4b (-SourceSubdirectory ''''): also identical to the no-parameter case (config.json fields left blank stay backward compatible)' (Test-Path -LiteralPath (Join-Path $monoWorkspaceExplicitEmpty.ProjectDir 'agent\Agent.php'))

$monoWorkspaceWhitespace = New-BuildWorkspace -BaseDir $workspaceBase -ProjectKey 'monorepo-whitespace' -JobId 'job-011'
Export-GitArchiveToWorkspace -RepositoryPath $monoRepo -CommitSha $monoCommitSha -DestinationDir $monoWorkspaceWhitespace.ProjectDir -SourceSubdirectory '   '
Assert-That 'Scenario 4c (-SourceSubdirectory "   ", whitespace-only): also treated as "no subdirectory" — backward compatible, not rejected as unsafe' (Test-Path -LiteralPath (Join-Path $monoWorkspaceWhitespace.ProjectDir 'agent\Agent.php'))

# --- Scenario 2: -SourceSubdirectory 'service' -- only service/ contents, flattened to the archive root ---
$monoWorkspaceService = New-BuildWorkspace -BaseDir $workspaceBase -ProjectKey 'monorepo-service' -JobId 'job-010'
Export-GitArchiveToWorkspace -RepositoryPath $monoRepo -CommitSha $monoCommitSha -DestinationDir $monoWorkspaceService.ProjectDir -SourceSubdirectory 'service'

Assert-That 'Scenario 2: artisan lands directly at the artifact root (not service\artisan)' (Test-Path -LiteralPath (Join-Path $monoWorkspaceService.ProjectDir 'artisan'))
Assert-That 'Scenario 2: composer.json lands directly at the artifact root' (Test-Path -LiteralPath (Join-Path $monoWorkspaceService.ProjectDir 'composer.json'))
Assert-That 'Scenario 2: app/ lands directly at the artifact root' (Test-Path -LiteralPath (Join-Path $monoWorkspaceService.ProjectDir 'app\Kernel.php'))
Assert-That 'Scenario 2: routes/ lands directly at the artifact root' (Test-Path -LiteralPath (Join-Path $monoWorkspaceService.ProjectDir 'routes\web.php'))
Assert-That 'Scenario 2: database/ lands directly at the artifact root' (Test-Path -LiteralPath (Join-Path $monoWorkspaceService.ProjectDir 'database\migration.sql'))
Assert-That 'Scenario 2: there is NO nested service\artisan (no leftover prefix — this is the exact "service/service/artisan" bug being prevented)' (-not (Test-Path -LiteralPath (Join-Path $monoWorkspaceService.ProjectDir 'service')))
Assert-That 'Scenario 2: agent/ is completely absent from the artifact' (-not (Test-Path -LiteralPath (Join-Path $monoWorkspaceService.ProjectDir 'agent')))
Assert-That 'Scenario 2: local-agent/ is completely absent from the artifact' (-not (Test-Path -LiteralPath (Join-Path $monoWorkspaceService.ProjectDir 'local-agent')))
Assert-That 'Scenario 2: tools/ (this very tool''s own source) is completely absent from the artifact' (-not (Test-Path -LiteralPath (Join-Path $monoWorkspaceService.ProjectDir 'tools')))
Assert-That 'Scenario 2: the monorepo root README.md is absent from the artifact' (-not (Test-Path -LiteralPath (Join-Path $monoWorkspaceService.ProjectDir 'README.md')))

# The final packaged artifact.zip itself must reflect the same flattened,
# scoped structure — proving New-DeploymentArtifact needed no changes at
# all (Export-GitArchiveToWorkspace already handed it a correctly-rooted
# workspace).
$monoServiceArtifactPath = Join-Path $monoWorkspaceService.Root 'artifact.zip'
$monoServiceArtifact = New-DeploymentArtifact -WorkspaceProjectDir $monoWorkspaceService.ProjectDir -OutputZipPath $monoServiceArtifactPath
$monoServiceArchive = [System.IO.Compression.ZipFile]::OpenRead($monoServiceArtifactPath)
$monoServiceEntryNames = @($monoServiceArchive.Entries | ForEach-Object { $_.FullName })
$monoServiceArchive.Dispose()
Assert-That 'the packaged artifact.zip contains artisan at its root' (@($monoServiceEntryNames) -contains 'artisan')
Assert-That 'the packaged artifact.zip contains composer.json at its root' (@($monoServiceEntryNames) -contains 'composer.json')
Assert-That 'the packaged artifact.zip contains NO entry under agent/, local-agent/, tools/, or a leftover service/ prefix' (
    -not (@($monoServiceEntryNames) | Where-Object { $_ -match '^(agent|local-agent|tools|service)/' })
)

# --- Scenario 3: an unsafe -SourceSubdirectory value is rejected BEFORE git is ever invoked with it ---
$unsafeValues = @('../invalid', '..\invalid', 'a/../../etc', 'C:\Windows', '/etc/passwd')
foreach ($unsafeValue in $unsafeValues) {
    $rejected = $false
    $rejectionMessage = $null
    try {
        Export-GitArchiveToWorkspace -RepositoryPath $monoRepo -CommitSha $monoCommitSha `
            -DestinationDir (Join-Path $monoBase "unsafe-check-$([Guid]::NewGuid().ToString('N').Substring(0,8))") `
            -SourceSubdirectory $unsafeValue
    } catch {
        $rejected = $true
        $rejectionMessage = $_.Exception.Message
    }
    Assert-That "Scenario 3: source_subdirectory='$unsafeValue' is rejected, never passed through to git" $rejected
}

# A subdirectory that is syntactically safe but genuinely does not exist
# in that commit is also refused (via git's own real, distinct error),
# never silently falls back to archiving the whole repo.
$nonexistentRejected = $false
try {
    Export-GitArchiveToWorkspace -RepositoryPath $monoRepo -CommitSha $monoCommitSha `
        -DestinationDir (Join-Path $monoBase 'nonexistent-check') -SourceSubdirectory 'this-directory-does-not-exist'
} catch {
    $nonexistentRejected = $true
}
Assert-That 'Scenario 3b: a syntactically-safe but nonexistent source_subdirectory is also refused, never silently archives the whole repo instead' $nonexistentRejected

Remove-TestTempDirectory -Path $monoBase

Remove-TestTempDirectory -Path $baseDir
Write-TestSummaryAndExit -SuiteName 'Test-Build'
