<#
.SYNOPSIS
    Tests: scripts/activate-release.sh — run directly via a real, local
    Git Bash (never a real VPS/SSH). Covers checksum mismatch rejection,
    the ZIP-Slip/path-traversal guard, atomic activation in both
    activation modes, automatic rollback on a post-swap failure (a failing
    migrate step, and a failing health check), and a successful health
    check as a positive control.
#>

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ToolRoot = Split-Path -Parent $ScriptDir
. (Join-Path $ScriptDir 'TestHelpers.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Common.ps1')

$bashCmd = Get-Command bash.exe -ErrorAction SilentlyContinue
if (-not $bashCmd) {
    Write-Host 'bash.exe not found on this system -- Test-ActivateRelease requires Git Bash. Skipping entirely.' -ForegroundColor Yellow
    Write-TestSummaryAndExit -SuiteName 'Test-ActivateRelease'
}
$bashPath = $bashCmd.Source
$activateScriptPosix = (Join-Path $ToolRoot 'scripts\activate-release.sh').Replace('\', '/')

function ConvertTo-Posix {
    param([string]$Path)
    return $Path.Replace('\', '/')
}

function New-ZipFromDirectory {
    param([Parameter(Mandatory = $true)][string]$SourceDir, [Parameter(Mandatory = $true)][string]$ZipPath)
    Add-Type -AssemblyName System.IO.Compression -ErrorAction SilentlyContinue
    Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction SilentlyContinue
    if (Test-Path -LiteralPath $ZipPath) { Remove-Item -LiteralPath $ZipPath -Force }
    [System.IO.Compression.ZipFile]::CreateFromDirectory($SourceDir, $ZipPath, [System.IO.Compression.CompressionLevel]::Optimal, $false)
    return @{ Path = $ZipPath; ChecksumSha256 = (Get-FileSha256 -Path $ZipPath) }
}

function New-ZipWithSingleTraversalEntry {
    param([Parameter(Mandatory = $true)][string]$ZipPath)
    Add-Type -AssemblyName System.IO.Compression -ErrorAction SilentlyContinue
    if (Test-Path -LiteralPath $ZipPath) { Remove-Item -LiteralPath $ZipPath -Force }
    $stream = [System.IO.File]::Open($ZipPath, [System.IO.FileMode]::Create)
    $archive = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
    $entry = $archive.CreateEntry('../escaped-outside.txt')
    $writer = New-Object System.IO.StreamWriter($entry.Open())
    $writer.Write('should never land on disk')
    $writer.Dispose()
    $archive.Dispose()
    $stream.Dispose()
    return @{ Path = $ZipPath; ChecksumSha256 = (Get-FileSha256 -Path $ZipPath) }
}

<#
.SYNOPSIS
    Runs activate-release.sh with the given parameters and returns
    @{ ExitCode; StdOut; StdErr }. All paths passed to bash use forward
    slashes (confirmed empirically to work correctly through Git Bash's
    MSYS runtime without any further translation).
#>
function Invoke-ActivateRelease {
    param(
        [Parameter(Mandatory = $true)][string]$ProjectPath,
        [Parameter(Mandatory = $true)][string]$ArchivePath,
        [Parameter(Mandatory = $true)][string]$Checksum,
        [Parameter(Mandatory = $false)][string]$CommitSha = ('b' * 40),
        [Parameter(Mandatory = $false)][string]$ActivationMode = 'working_directory',
        [Parameter(Mandatory = $false)][string]$PreservePaths = '.env,storage,public/storage',
        [Parameter(Mandatory = $false)][int]$RetentionCount = 3,
        [Parameter(Mandatory = $false)][string]$RunMigrations = 'false',
        [Parameter(Mandatory = $false)][string]$PhpBinary = 'php',
        [Parameter(Mandatory = $false)][string]$HealthCheckUrl = '',
        [Parameter(Mandatory = $false)][int]$TimeoutSeconds = 60
    )
    $args = @(
        $activateScriptPosix,
        '--project-path', (ConvertTo-Posix $ProjectPath),
        '--uploaded-archive', (ConvertTo-Posix $ArchivePath),
        '--checksum', $Checksum,
        '--commit-sha', $CommitSha,
        '--activation-mode', $ActivationMode,
        '--preserve-paths', $PreservePaths,
        '--retention-count', [string]$RetentionCount,
        '--run-migrations', $RunMigrations,
        '--php-binary', $PhpBinary
    )
    if ($HealthCheckUrl) { $args += @('--health-check-url', $HealthCheckUrl) }
    # On real Linux, `ln -s` always creates a genuine symlink. Git Bash's
    # own `ln -s` on Windows instead falls back to a plain copy unless
    # explicitly told to use real NTFS symlinks — confirmed empirically —
    # so the releases_symlink activation mode can only be faithfully
    # exercised locally with this override in effect.
    return Invoke-ExternalCommand -FilePath $bashPath -ArgumentList $args -TimeoutSeconds $TimeoutSeconds -EnvironmentOverrides @{ MSYS = 'winsymlinks:nativestrict' }
}

function New-ExecutableBashStub {
    param([Parameter(Mandatory = $true)][string]$Path, [Parameter(Mandatory = $true)][string]$Body)
    "#!/usr/bin/env bash`n$Body`n" | Set-Content -LiteralPath $Path -Encoding ASCII -NoNewline
    Invoke-ExternalCommand -FilePath $bashPath -ArgumentList @('-c', "chmod +x -- '$(ConvertTo-Posix $Path)'") -TimeoutSeconds 10 | Out-Null
}

$base = New-TestTempDirectory -Prefix 'sdtest-activate'

# ============================================================
# 1. Checksum mismatch is rejected BEFORE any extraction — the project
#    directory is left completely untouched.
# ============================================================
$t1Dir = Join-Path $base 't1'
New-Item -ItemType Directory -Path $t1Dir -Force | Out-Null
$t1Src = Join-Path $t1Dir 'src'
New-Item -ItemType Directory -Path $t1Src -Force | Out-Null
'v1' | Set-Content -LiteralPath (Join-Path $t1Src 'index.php') -Encoding ASCII -NoNewline
$t1Zip = New-ZipFromDirectory -SourceDir $t1Src -ZipPath (Join-Path $t1Dir 'artifact.zip')
$t1Project = Join-Path $t1Dir 'project'
New-Item -ItemType Directory -Path $t1Project -Force | Out-Null
'ORIGINAL' | Set-Content -LiteralPath (Join-Path $t1Project 'index.php') -Encoding ASCII -NoNewline

$t1Result = Invoke-ActivateRelease -ProjectPath $t1Project -ArchivePath $t1Zip.Path -Checksum ('0' * 64)
Assert-That 'a checksum mismatch is rejected (nonzero exit)' ($t1Result.ExitCode -ne 0)
Assert-That 'the checksum-mismatch error message is present' ($t1Result.StdErr -match 'checksum' -or $t1Result.StdOut -match 'checksum')
Assert-That 'the project directory is completely untouched after a checksum rejection' ((Get-Content -LiteralPath (Join-Path $t1Project 'index.php') -Raw) -eq 'ORIGINAL')
Assert-That 'no staging directory is left behind after a checksum rejection' (@(Get-ChildItem -Path $t1Dir -Directory -Filter '*.staging-*').Count -eq 0)

# ============================================================
# 2. ZIP-Slip / path-traversal guard: an entry name containing ".." is
#    rejected BEFORE unzip ever runs.
# ============================================================
$t2Dir = Join-Path $base 't2'
New-Item -ItemType Directory -Path $t2Dir -Force | Out-Null
$t2Zip = New-ZipWithSingleTraversalEntry -ZipPath (Join-Path $t2Dir 'evil.zip')
$t2Project = Join-Path $t2Dir 'project'
New-Item -ItemType Directory -Path $t2Project -Force | Out-Null

$t2Result = Invoke-ActivateRelease -ProjectPath $t2Project -ArchivePath $t2Zip.Path -Checksum $t2Zip.ChecksumSha256
Assert-That 'a zip entry containing ".." is rejected (nonzero exit)' ($t2Result.ExitCode -ne 0)
Assert-That 'the rejection message mentions the unsafe entry' (($t2Result.StdOut + $t2Result.StdErr) -match 'traversal|ناامن')
Assert-That 'the traversal target file was never created outside the intended tree' (-not (Test-Path -LiteralPath (Join-Path $t2Dir 'escaped-outside.txt')))

# ============================================================
# 3. Atomic activation, working_directory mode: new content activated,
#    .env/storage preserved from the live directory (not from the
#    artifact), a timestamped backup of the OLD content is created.
# ============================================================
$t3Dir = Join-Path $base 't3'
New-Item -ItemType Directory -Path $t3Dir -Force | Out-Null
$t3Live = Join-Path $t3Dir 'project'
New-Item -ItemType Directory -Path (Join-Path $t3Live 'storage\logs') -Force | Out-Null
'OLD_VERSION' | Set-Content -LiteralPath (Join-Path $t3Live 'index.php') -Encoding ASCII -NoNewline
'DB_PASSWORD=live-secret' | Set-Content -LiteralPath (Join-Path $t3Live '.env') -Encoding ASCII -NoNewline
'existing log entry' | Set-Content -LiteralPath (Join-Path $t3Live 'storage\logs\app.log') -Encoding ASCII -NoNewline

$t3Src = Join-Path $t3Dir 'artifact-src'
New-Item -ItemType Directory -Path $t3Src -Force | Out-Null
'NEW_VERSION' | Set-Content -LiteralPath (Join-Path $t3Src 'index.php') -Encoding ASCII -NoNewline
$t3Zip = New-ZipFromDirectory -SourceDir $t3Src -ZipPath (Join-Path $t3Dir 'artifact.zip')

$t3Result = Invoke-ActivateRelease -ProjectPath $t3Live -ArchivePath $t3Zip.Path -Checksum $t3Zip.ChecksumSha256 -ActivationMode 'working_directory'
Assert-That 'a valid working_directory activation succeeds' ($t3Result.ExitCode -eq 0)
Assert-That 'the new content is now live' ((Get-Content -LiteralPath (Join-Path $t3Live 'index.php') -Raw) -eq 'NEW_VERSION')
Assert-That '.env is preserved from the live directory, not overwritten by the artifact' ((Get-Content -LiteralPath (Join-Path $t3Live '.env') -Raw) -eq 'DB_PASSWORD=live-secret')
Assert-That 'storage/logs is preserved from the live directory' ((Get-Content -LiteralPath (Join-Path $t3Live 'storage\logs\app.log') -Raw) -eq 'existing log entry')
$t3Backups = @(Get-ChildItem -Path $t3Dir -Directory -Filter 'project.backup-*')
Assert-That 'a timestamped backup of the previous version was created' ($t3Backups.Count -eq 1)
Assert-That 'the backup contains the OLD content' ((Get-Content -LiteralPath (Join-Path $t3Backups[0].FullName 'index.php') -Raw) -eq 'OLD_VERSION')
Assert-That 'no staging directory is left behind after a successful activation' (@(Get-ChildItem -Path $t3Dir -Directory -Filter '*.staging-*').Count -eq 0)

# ============================================================
# 4. Atomic activation, releases_symlink mode: first deploy creates
#    releases/<id> + a "current" symlink; second deploy preserves .env
#    from the FIRST release (via the symlink) and repoints "current".
# ============================================================
$t4Dir = Join-Path $base 't4'
New-Item -ItemType Directory -Path $t4Dir -Force | Out-Null
$t4ProjectRoot = Join-Path $t4Dir 'myapp'
New-Item -ItemType Directory -Path $t4ProjectRoot -Force | Out-Null
$t4ProjectPath = Join-Path $t4ProjectRoot 'current'   # not used as the live dir directly in this mode; --project-path still required by the script for naming purposes

$t4SrcA = Join-Path $t4Dir 'artifact-src-a'
New-Item -ItemType Directory -Path $t4SrcA -Force | Out-Null
'RELEASE_A' | Set-Content -LiteralPath (Join-Path $t4SrcA 'index.php') -Encoding ASCII -NoNewline
'DB_PASSWORD=release-a-secret' | Set-Content -LiteralPath (Join-Path $t4SrcA '.env') -Encoding ASCII -NoNewline
$t4ZipA = New-ZipFromDirectory -SourceDir $t4SrcA -ZipPath (Join-Path $t4Dir 'artifact-a.zip')

$t4ResultA = Invoke-ActivateRelease -ProjectPath $t4ProjectPath -ArchivePath $t4ZipA.Path -Checksum $t4ZipA.ChecksumSha256 -ActivationMode 'releases_symlink' -CommitSha ('a' * 40)
Assert-That 'first releases_symlink activation succeeds' ($t4ResultA.ExitCode -eq 0)
$t4CurrentLink = Get-Item -LiteralPath (Join-Path $t4ProjectRoot 'current') -ErrorAction SilentlyContinue
Assert-That 'a "current" symlink now exists after the first deploy' ($null -ne $t4CurrentLink -and ($t4CurrentLink.Attributes -band [System.IO.FileAttributes]::ReparsePoint))
Assert-That 'the "current" symlink content is release A' ((Get-Content -LiteralPath (Join-Path $t4ProjectRoot 'current\index.php') -Raw) -eq 'RELEASE_A')
$t4ReleasesAfterA = @(Get-ChildItem -Path (Join-Path $t4ProjectRoot 'releases') -Directory)
Assert-That 'exactly one release directory exists after the first deploy' ($t4ReleasesAfterA.Count -eq 1)

$t4SrcB = Join-Path $t4Dir 'artifact-src-b'
New-Item -ItemType Directory -Path $t4SrcB -Force | Out-Null
'RELEASE_B' | Set-Content -LiteralPath (Join-Path $t4SrcB 'index.php') -Encoding ASCII -NoNewline
$t4ZipB = New-ZipFromDirectory -SourceDir $t4SrcB -ZipPath (Join-Path $t4Dir 'artifact-b.zip')

Start-Sleep -Seconds 1   # release IDs are timestamp-based; ensure the second one is distinct
$t4ResultB = Invoke-ActivateRelease -ProjectPath $t4ProjectPath -ArchivePath $t4ZipB.Path -Checksum $t4ZipB.ChecksumSha256 -ActivationMode 'releases_symlink' -CommitSha ('c' * 40)
Assert-That 'second releases_symlink activation succeeds' ($t4ResultB.ExitCode -eq 0)
Assert-That 'the "current" symlink now points at release B' ((Get-Content -LiteralPath (Join-Path $t4ProjectRoot 'current\index.php') -Raw) -eq 'RELEASE_B')
Assert-That '.env was preserved into release B from release A (read via the "current" symlink before the swap)' ((Get-Content -LiteralPath (Join-Path $t4ProjectRoot 'current\.env') -Raw) -eq 'DB_PASSWORD=release-a-secret')
$t4ReleasesAfterB = @(Get-ChildItem -Path (Join-Path $t4ProjectRoot 'releases') -Directory)
Assert-That 'both releases still exist (retention count of 3 was not exceeded)' ($t4ReleasesAfterB.Count -eq 2)

# ============================================================
# 5. Automatic rollback on a post-swap failure: a failing `artisan
#    migrate` (simulated via a --php-binary stub that always exits 1)
#    must restore the PREVIOUS content, not leave the new (broken) one live.
# ============================================================
$t5Dir = Join-Path $base 't5'
New-Item -ItemType Directory -Path $t5Dir -Force | Out-Null
$t5Live = Join-Path $t5Dir 'project'
New-Item -ItemType Directory -Path $t5Live -Force | Out-Null
'STABLE_VERSION' | Set-Content -LiteralPath (Join-Path $t5Live 'index.php') -Encoding ASCII -NoNewline

$t5Src = Join-Path $t5Dir 'artifact-src'
New-Item -ItemType Directory -Path $t5Src -Force | Out-Null
'BROKEN_VERSION' | Set-Content -LiteralPath (Join-Path $t5Src 'index.php') -Encoding ASCII -NoNewline
'placeholder' | Set-Content -LiteralPath (Join-Path $t5Src 'artisan') -Encoding ASCII -NoNewline
$t5Zip = New-ZipFromDirectory -SourceDir $t5Src -ZipPath (Join-Path $t5Dir 'artifact.zip')

$t5PhpStub = Join-Path $t5Dir 'fake-php-always-fails.sh'
New-ExecutableBashStub -Path $t5PhpStub -Body 'echo "simulated migrate failure" >&2; exit 1'

$t5Result = Invoke-ActivateRelease -ProjectPath $t5Live -ArchivePath $t5Zip.Path -Checksum $t5Zip.ChecksumSha256 `
    -ActivationMode 'working_directory' -RunMigrations 'true' -PhpBinary (ConvertTo-Posix $t5PhpStub)
Assert-That 'a failing migrate step causes activation to report failure (nonzero exit)' ($t5Result.ExitCode -ne 0)
Assert-That 'the failure output mentions rollback' (($t5Result.StdOut + $t5Result.StdErr) -match 'rollback|بازگردا')
Assert-That 'after the rollback, the STABLE (previous) version is live again — never the broken one' ((Get-Content -LiteralPath (Join-Path $t5Live 'index.php') -Raw) -eq 'STABLE_VERSION')
$t5FailedDirs = @(Get-ChildItem -Path $t5Dir -Directory -Filter 'project.failed-*')
Assert-That 'the broken release was preserved under a ".failed-" directory, not silently discarded' ($t5FailedDirs.Count -eq 1)
Assert-That 'the ".failed-" directory actually contains the broken content' ((Get-Content -LiteralPath (Join-Path $t5FailedDirs[0].FullName 'index.php') -Raw) -eq 'BROKEN_VERSION')

# ============================================================
# 6. Automatic rollback on a failing health check.
# ============================================================
$t6Dir = Join-Path $base 't6'
New-Item -ItemType Directory -Path $t6Dir -Force | Out-Null
$t6Live = Join-Path $t6Dir 'project'
New-Item -ItemType Directory -Path $t6Live -Force | Out-Null
'STABLE_V2' | Set-Content -LiteralPath (Join-Path $t6Live 'index.php') -Encoding ASCII -NoNewline

$t6Src = Join-Path $t6Dir 'artifact-src'
New-Item -ItemType Directory -Path $t6Src -Force | Out-Null
'BROKEN_V2' | Set-Content -LiteralPath (Join-Path $t6Src 'index.php') -Encoding ASCII -NoNewline
$t6Zip = New-ZipFromDirectory -SourceDir $t6Src -ZipPath (Join-Path $t6Dir 'artifact.zip')

# Port 1 on loopback: no listener will ever be there -> curl fails fast.
$t6Result = Invoke-ActivateRelease -ProjectPath $t6Live -ArchivePath $t6Zip.Path -Checksum $t6Zip.ChecksumSha256 `
    -ActivationMode 'working_directory' -HealthCheckUrl 'http://127.0.0.1:1/health'
Assert-That 'a failing health check causes activation to report failure (nonzero exit)' ($t6Result.ExitCode -ne 0)
Assert-That 'after a failed health check, the STABLE (previous) version is live again' ((Get-Content -LiteralPath (Join-Path $t6Live 'index.php') -Raw) -eq 'STABLE_V2')

# ============================================================
# 7. A successful health check (positive control) — a real local
#    HttpListener answering 200 OK — activation must NOT roll back.
# ============================================================
$listenerPort = Get-Random -Minimum 20000 -Maximum 60000
$listenerJob = Start-Job -ScriptBlock {
    param($Port)
    $l = [System.Net.HttpListener]::new()
    $l.Prefixes.Add("http://127.0.0.1:$Port/")
    $l.Start()
    $ctx = $l.GetContext()
    $ctx.Response.StatusCode = 200
    $ctx.Response.Close()
    $l.Stop()
} -ArgumentList $listenerPort
try {
    Start-Sleep -Milliseconds 500
    Assert-That 'the background HTTP listener for the positive health-check control is running' ($listenerJob.State -eq 'Running')

    if ($true) {
        $t7Dir = Join-Path $base 't7'
        New-Item -ItemType Directory -Path $t7Dir -Force | Out-Null
        $t7Live = Join-Path $t7Dir 'project'
        New-Item -ItemType Directory -Path $t7Live -Force | Out-Null
        'OLD_V3' | Set-Content -LiteralPath (Join-Path $t7Live 'index.php') -Encoding ASCII -NoNewline
        $t7Src = Join-Path $t7Dir 'artifact-src'
        New-Item -ItemType Directory -Path $t7Src -Force | Out-Null
        'GOOD_V3' | Set-Content -LiteralPath (Join-Path $t7Src 'index.php') -Encoding ASCII -NoNewline
        $t7Zip = New-ZipFromDirectory -SourceDir $t7Src -ZipPath (Join-Path $t7Dir 'artifact.zip')

        $t7Result = Invoke-ActivateRelease -ProjectPath $t7Live -ArchivePath $t7Zip.Path -Checksum $t7Zip.ChecksumSha256 `
            -ActivationMode 'working_directory' -HealthCheckUrl "http://127.0.0.1:$listenerPort/health"

        Wait-Job -Job $listenerJob -Timeout 10 | Out-Null
        Remove-Job -Job $listenerJob -Force -ErrorAction SilentlyContinue

        Assert-That 'a successful health check (real HTTP 200) allows activation to complete successfully' ($t7Result.ExitCode -eq 0)
        Assert-That 'the new version is live after a successful health check (no rollback)' ((Get-Content -LiteralPath (Join-Path $t7Live 'index.php') -Raw) -eq 'GOOD_V3')
    }
} finally {
    Remove-Job -Job $listenerJob -Force -ErrorAction SilentlyContinue
}

Remove-TestTempDirectory -Path $base
Write-TestSummaryAndExit -SuiteName 'Test-ActivateRelease'
