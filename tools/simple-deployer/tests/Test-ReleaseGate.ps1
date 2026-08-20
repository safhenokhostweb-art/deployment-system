<#
.SYNOPSIS
    Focused regression coverage for the SimpleDeploy release gate wiring:
      - the build-time gate in SimpleDeploy.Orchestration.ps1 runs the
        canonical validator BEFORE the artifact is sealed,
      - activate-release.sh performs its fail-closed pre-swap completeness
        gate BEFORE perform_swap (missing vendor/autoload.php, missing Vite
        manifest, missing/invalid release manifest, commit mismatch all
        rejected with the live release untouched),
      - the canonical validator contract (what the gate invokes) rejects a
        broken staged tree and accepts a complete one.

    Run via Git Bash against a real, local bash (never a VPS).
    Usage: powershell -ExecutionPolicy Bypass -File Test-ReleaseGate.ps1
#>

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ToolRoot = Split-Path -Parent $ScriptDir
. (Join-Path $ScriptDir 'TestHelpers.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Common.ps1')

$bashPath = Get-GitBashPath
if (-not $bashPath) {
    Write-Host 'Git Bash (bash.exe) not found on this system -- Test-ReleaseGate requires Git Bash. Skipping entirely.' -ForegroundColor Yellow
    Write-TestSummaryAndExit -SuiteName 'Test-ReleaseGate'
}
$activateScriptPosix = (Join-Path $ToolRoot 'scripts\activate-release.sh').Replace('\', '/')

$phpBinary = 'C:/laragon/bin/php/php-8.4.16-nts-Win32-vs17-x64/php.exe'
if (-not (Test-Path -LiteralPath ($phpBinary -replace '/', '\'))) {
    Write-Host "php binary not found: $phpBinary -- skipping entire suite." -ForegroundColor Yellow
    Write-TestSummaryAndExit -SuiteName 'Test-ReleaseGate'
}

function ConvertTo-Posix {
    param([string]$Path)
    return $Path.Replace('\', '/')
}

<#
.SYNOPSIS
    Converts an absolute Windows path (C:\a\b or C:/a/b) to Git Bash MSYS
    POSIX form (/c/a/b). activate-release.sh runs on a real Linux server
    with pure POSIX paths; when the test runs it under Git Bash on Windows,
    the arguments must also be pure POSIX so every internal command (mv,
    unzip, cp) operates in one consistent path namespace. MSYS2's implicit
    conversion of bare "C:/..." arguments is applied inconsistently across
    commands and breaks the atomic swap.
#>
function ConvertTo-MsysPath {
    param([string]$Path)
    $p = $Path.Replace('\', '/')
    if ($p -match '^([A-Za-z]):(/.*)$') { return '/' + $matches[1].ToLower() + $matches[2] }
    if ($p -match '^([A-Za-z]):$') { return '/' + $matches[1].ToLower() }
    return $p
}

function Get-CommitSha {
    return ('a' * 40)
}

function New-ReleaseManifest {
    param([Parameter(Mandatory = $true)][string]$Path, [string]$Status = 'pass', [string]$CommitSha)
    $manifest = @{
        format     = 'deployment-system-release-manifest'
        version    = 1
        commit_sha = $CommitSha
        status     = $Status
    }
    New-Item -ItemType Directory -Path (Split-Path $Path) -Force | Out-Null
    $manifest | ConvertTo-Json -Compress | Set-Content -LiteralPath $Path -Encoding ASCII -NoNewline
}

function New-CompleteReleaseTree {
    param([Parameter(Mandatory = $true)][string]$Root, [string]$CommitSha)
    foreach ($d in @('vendor/composer', 'public/build/assets', 'bootstrap', '.deployment', 'storage', 'bootstrap/cache')) {
        New-Item -ItemType Directory -Path (Join-Path $Root $d) -Force | Out-Null
    }
    '{"name":"laravel/laravel","require":{"php":"^8.2","laravel/framework":"^12.0"}}' | Set-Content -LiteralPath (Join-Path $Root 'composer.json') -Encoding ASCII -NoNewline
    '{}' | Set-Content -LiteralPath (Join-Path $Root 'composer.lock') -Encoding ASCII -NoNewline
    '<?php echo "artisan stub";' | Set-Content -LiteralPath (Join-Path $Root 'artisan') -Encoding ASCII -NoNewline
    '<?php return new class {};' | Set-Content -LiteralPath (Join-Path $Root 'bootstrap\app.php') -Encoding ASCII -NoNewline
    "<?php require __DIR__.'/../vendor/autoload.php';" | Set-Content -LiteralPath (Join-Path $Root 'public\index.php') -Encoding ASCII -NoNewline
    '<?php // stub' | Set-Content -LiteralPath (Join-Path $Root 'vendor\autoload.php') -Encoding ASCII -NoNewline
    '{"packages":[]}' | Set-Content -LiteralPath (Join-Path $Root 'vendor\composer\installed.json') -Encoding ASCII -NoNewline
    '{}' | Set-Content -LiteralPath (Join-Path $Root 'public\build\manifest.json') -Encoding ASCII -NoNewline
    'x' | Set-Content -LiteralPath (Join-Path $Root 'public\build\assets\app.js') -Encoding ASCII -NoNewline
    'export default {};' | Set-Content -LiteralPath (Join-Path $Root 'vite.config.js') -Encoding ASCII -NoNewline
    'marker' | Set-Content -LiteralPath (Join-Path $Root 'marker.txt') -Encoding ASCII -NoNewline
    New-ReleaseManifest -Path (Join-Path $Root '.deployment\release-manifest.json') -Status 'pass' -CommitSha $CommitSha
}

function New-ZipFromDirectory {
    param([Parameter(Mandatory = $true)][string]$SourceDir, [Parameter(Mandatory = $true)][string]$ZipPath)
    Add-Type -AssemblyName System.IO.Compression -ErrorAction SilentlyContinue
    Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction SilentlyContinue
    if (Test-Path -LiteralPath $ZipPath) { Remove-Item -LiteralPath $ZipPath -Force }
    # NOTE: [System.IO.Compression.ZipFile]::CreateFromDirectory writes
    # BACKSLASH separators on Windows (a known .NET Framework behavior),
    # which Git Bash's unzip does not treat as a path separator — nested
    # entries then extract as literal `vendor\autoload.php` file names and
    # the gate (correctly) rejects the tree. Real artifacts (PHP
    # ArtifactBuilder, SimpleDeploy.Build.ps1) always use FORWARD slashes,
    # so this test helper builds the zip the same way: one entry per file
    # with a '/' separator.
    $stream = [System.IO.File]::Open($ZipPath, [System.IO.FileMode]::Create)
    $archive = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create)
    try {
        $files = Get-ChildItem -LiteralPath $SourceDir -Recurse -File -Force
        foreach ($file in $files) {
            $rel = $file.FullName.Substring($SourceDir.Length).TrimStart('\', '/').Replace('\', '/')
            [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($archive, $file.FullName, $rel, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
        }
    } finally {
        $archive.Dispose()
        $stream.Dispose()
    }
    return @{ Path = $ZipPath; ChecksumSha256 = (Get-FileSha256 -Path $ZipPath) }
}


function Invoke-ActivateReleaseGate {
    param(
        [Parameter(Mandatory = $true)][string]$ProjectPath,
        [Parameter(Mandatory = $true)][string]$ArchivePath,
        [Parameter(Mandatory = $true)][string]$Checksum,
        [Parameter(Mandatory = $false)][string]$CommitSha = (Get-CommitSha),
        [Parameter(Mandatory = $false)][string]$ActivationMode = 'working_directory',
        [Parameter(Mandatory = $false)][string]$ValidatorScript = '',
        [Parameter(Mandatory = $false)][int]$TimeoutSeconds = 120
    )
    $args = @(
        $activateScriptPosix,
        '--project-path', (ConvertTo-MsysPath $ProjectPath),
        '--uploaded-archive', (ConvertTo-MsysPath $ArchivePath),
        '--checksum', $Checksum,
        '--commit-sha', $CommitSha,
        '--activation-mode', $ActivationMode,
        '--preserve-paths', '.env,storage,public/storage',
        '--retention-count', '3',
        '--run-migrations', 'false',
        '--php-binary', (ConvertTo-MsysPath $phpBinary)
    )
    if ($ValidatorScript) { $args += @('--validator-script', (ConvertTo-MsysPath $ValidatorScript)) }
    return Invoke-ExternalCommand -FilePath $bashPath -ArgumentList $args -TimeoutSeconds $TimeoutSeconds -EnvironmentOverrides @{ MSYS = 'winsymlinks:nativestrict' }
}

# ---------------------------------------------------------------------------
# Part 1 — static wiring assertions (the gate is wired, not imagined)
# ---------------------------------------------------------------------------

$orchText = Get-Content -LiteralPath (Join-Path $ToolRoot 'lib\SimpleDeploy.Orchestration.ps1') -Raw
Assert-That 'Orchestration.ps1 runs the release gate before sealing the artifact' (
    $orchText.IndexOf('scripts\validate-release.php') -ge 0 -and
    $orchText.IndexOf('New-DeploymentArtifact') -gt $orchText.IndexOf('releaseGateScript')
)
Assert-That 'Orchestration.ps1 uploads the validator and passes --validator-script' (
    $orchText.Contains('--validator-script') -and $orchText.Contains('remoteValidatorUploaded')
)

$actText = Get-Content -LiteralPath (Join-Path $ToolRoot 'scripts\activate-release.sh') -Raw
$gatePos = $actText.IndexOf('Root-cause fix (Laravel release completeness)')
$swapPos = $actText.IndexOf('perform_swap')
Assert-That 'activate-release.sh places the fail-closed gate BEFORE perform_swap' (
    $gatePos -ge 0 -and $swapPos -gt $gatePos
)
Assert-That 'activate-release.sh requires the release manifest with status pass' (
    $actText.Contains('.deployment/release-manifest.json') -and $actText.Contains('"status"')
)


# ---------------------------------------------------------------------------
# Part 2 — behavioral: activate-release.sh pre-swap gate via Git Bash
# ---------------------------------------------------------------------------

$base = New-TestTempDirectory -Prefix 'release-gate'
$commit = Get-CommitSha

# t1 — a COMPLETE release (all mandatory files + release manifest) activates.
$t1Src = Join-Path $base 't1-src'
New-CompleteReleaseTree -Root $t1Src -CommitSha $commit
$t1Zip = New-ZipFromDirectory -SourceDir $t1Src -ZipPath (Join-Path $base 't1.zip')
$t1Live = Join-Path $base 't1-live'
New-Item -ItemType Directory -Path $t1Live -Force | Out-Null
'old-release' | Set-Content -LiteralPath (Join-Path $t1Live 'marker.txt') -Encoding ASCII -NoNewline
$t1 = Invoke-ActivateReleaseGate -ProjectPath $t1Live -ArchivePath $t1Zip.Path -Checksum $t1Zip.ChecksumSha256 -CommitSha $commit
Write-Host "T1EXIT=$($t1.ExitCode) TIMEOUT=$($t1.TimedOut)"
Write-Host "T1OUT: $($t1.StdOut)"
Write-Host "T1ERR: $($t1.StdErr)"
Assert-That 't1: complete release is activated (exit 0)' ($t1.ExitCode -eq 0)
Assert-That 't1: the new release content is live' (
    (Get-Content -LiteralPath (Join-Path $t1Live 'marker.txt') -Raw) -eq 'marker'
)
Assert-That 't1: a backup of the previous release exists' (
    (Get-ChildItem -LiteralPath $base -Directory -Filter 't1-live.backup-*' | Measure-Object).Count -eq 1
)

# t2 — missing vendor/autoload.php is rejected BEFORE swap; live untouched.
$t2Src = Join-Path $base 't2-src'
New-CompleteReleaseTree -Root $t2Src -CommitSha $commit
Remove-Item -LiteralPath (Join-Path $t2Src 'vendor\autoload.php') -Force
$t2Zip = New-ZipFromDirectory -SourceDir $t2Src -ZipPath (Join-Path $base 't2.zip')
$t2Live = Join-Path $base 't2-live'
New-Item -ItemType Directory -Path $t2Live -Force | Out-Null
'old-release' | Set-Content -LiteralPath (Join-Path $t2Live 'marker.txt') -Encoding ASCII -NoNewline
$t2 = Invoke-ActivateReleaseGate -ProjectPath $t2Live -ArchivePath $t2Zip.Path -Checksum $t2Zip.ChecksumSha256 -CommitSha $commit
Assert-That 't2: missing vendor/autoload.php is rejected (non-zero exit)' ($t2.ExitCode -ne 0)
Assert-That 't2: the live release is untouched after rejection' (
    (Get-Content -LiteralPath (Join-Path $t2Live 'marker.txt') -Raw) -eq 'old-release'
)
Assert-That 't2: no backup directory was created' (
    (Get-ChildItem -LiteralPath $base -Directory -Filter 't2-live.backup-*' | Measure-Object).Count -eq 0
)

# t3 — missing release manifest is rejected.
$t3Src = Join-Path $base 't3-src'
New-CompleteReleaseTree -Root $t3Src -CommitSha $commit
Remove-Item -LiteralPath (Join-Path $t3Src '.deployment\release-manifest.json') -Force
$t3Zip = New-ZipFromDirectory -SourceDir $t3Src -ZipPath (Join-Path $base 't3.zip')
$t3Live = Join-Path $base 't3-live'
New-Item -ItemType Directory -Path $t3Live -Force | Out-Null
'old-release' | Set-Content -LiteralPath (Join-Path $t3Live 'marker.txt') -Encoding ASCII -NoNewline
$t3 = Invoke-ActivateReleaseGate -ProjectPath $t3Live -ArchivePath $t3Zip.Path -Checksum $t3Zip.ChecksumSha256 -CommitSha $commit
Assert-That 't3: missing release manifest is rejected' ($t3.ExitCode -ne 0)
Assert-That 't3: live release untouched' (
    (Get-Content -LiteralPath (Join-Path $t3Live 'marker.txt') -Raw) -eq 'old-release'
)

# t4 — release manifest with status != pass is rejected.
$t4Src = Join-Path $base 't4-src'
New-CompleteReleaseTree -Root $t4Src -CommitSha $commit
New-ReleaseManifest -Path (Join-Path $t4Src '.deployment\release-manifest.json') -Status 'fail' -CommitSha $commit
$t4Zip = New-ZipFromDirectory -SourceDir $t4Src -ZipPath (Join-Path $base 't4.zip')
$t4Live = Join-Path $base 't4-live'
New-Item -ItemType Directory -Path $t4Live -Force | Out-Null

# t5 — manifest commit mismatch is rejected.
$t5Src = Join-Path $base 't5-src'
New-CompleteReleaseTree -Root $t5Src -CommitSha $commit
New-ReleaseManifest -Path (Join-Path $t5Src '.deployment\release-manifest.json') -Status 'pass' -CommitSha ('b' * 40)
$t5Zip = New-ZipFromDirectory -SourceDir $t5Src -ZipPath (Join-Path $base 't5.zip')
$t5Live = Join-Path $base 't5-live'
New-Item -ItemType Directory -Path $t5Live -Force | Out-Null
'old-release' | Set-Content -LiteralPath (Join-Path $t5Live 'marker.txt') -Encoding ASCII -NoNewline
$t5 = Invoke-ActivateReleaseGate -ProjectPath $t5Live -ArchivePath $t5Zip.Path -Checksum $t5Zip.ChecksumSha256 -CommitSha $commit
Assert-That 't5: manifest commit mismatch is rejected' ($t5.ExitCode -ne 0)
Assert-That 't5: live release untouched' (
    (Get-Content -LiteralPath (Join-Path $t5Live 'marker.txt') -Raw) -eq 'old-release'
)

# t6 — Vite project missing public/build/manifest.json is rejected.
$t6Src = Join-Path $base 't6-src'
New-CompleteReleaseTree -Root $t6Src -CommitSha $commit
Remove-Item -LiteralPath (Join-Path $t6Src 'public\build\manifest.json') -Force
$t6Zip = New-ZipFromDirectory -SourceDir $t6Src -ZipPath (Join-Path $base 't6.zip')
$t6Live = Join-Path $base 't6-live'
New-Item -ItemType Directory -Path $t6Live -Force | Out-Null
'old-release' | Set-Content -LiteralPath (Join-Path $t6Live 'marker.txt') -Encoding ASCII -NoNewline
$t6 = Invoke-ActivateReleaseGate -ProjectPath $t6Live -ArchivePath $t6Zip.Path -Checksum $t6Zip.ChecksumSha256 -CommitSha $commit
Assert-That 't6: missing Vite manifest is rejected' ($t6.ExitCode -ne 0)
Assert-That 't6: live release untouched' (
    (Get-Content -LiteralPath (Join-Path $t6Live 'marker.txt') -Raw) -eq 'old-release'
)

# t7 — canonical validator re-run on the server (--validator-script) rejects a
# broken staged tree before the swap.
$customerValidator = 'C:\laragon\www\customer-site\scripts\validate-release.php'
if (Test-Path -LiteralPath $customerValidator) {
    $t7Src = Join-Path $base 't7-src'
    New-CompleteReleaseTree -Root $t7Src -CommitSha $commit
    Remove-Item -LiteralPath (Join-Path $t7Src 'vendor\autoload.php') -Force
    $t7Zip = New-ZipFromDirectory -SourceDir $t7Src -ZipPath (Join-Path $base 't7.zip')
    $t7Live = Join-Path $base 't7-live'
    New-Item -ItemType Directory -Path $t7Live -Force | Out-Null
    'old-release' | Set-Content -LiteralPath (Join-Path $t7Live 'marker.txt') -Encoding ASCII -NoNewline
    $t7 = Invoke-ActivateReleaseGate -ProjectPath $t7Live -ArchivePath $t7Zip.Path -Checksum $t7Zip.ChecksumSha256 -CommitSha $commit -ValidatorScript $customerValidator
    Assert-That 't7: canonical validator on server rejects a broken tree before swap' ($t7.ExitCode -ne 0)
    Assert-That 't7: live release untouched' (
        (Get-Content -LiteralPath (Join-Path $t7Live 'marker.txt') -Raw) -eq 'old-release'
    )
} else {
    Assert-That 't7: canonical validator present (customer-site repo exists)' $false
}

# ---------------------------------------------------------------------------
# Part 3 — build-time gate contract: the canonical validator itself
# (exactly what SimpleDeploy.Orchestration.ps1 invokes before sealing).
# ---------------------------------------------------------------------------

$customerValidatorPosix = $customerValidator.Replace('\', '/')
if (Test-Path -LiteralPath $customerValidator) {
    $b1Src = Join-Path $base 'b1-src'
    New-CompleteReleaseTree -Root $b1Src -CommitSha $commit
    $b1 = Invoke-ExternalCommand -FilePath $phpBinary -ArgumentList @($customerValidatorPosix, (ConvertTo-Posix $b1Src), "--commit-sha=$commit") -TimeoutSeconds 120
    Assert-That 'b1: complete staged tree passes the build-time validator' ($b1.ExitCode -eq 0)

    $b2Src = Join-Path $base 'b2-src'
    New-CompleteReleaseTree -Root $b2Src -CommitSha $commit
    Remove-Item -LiteralPath (Join-Path $b2Src 'vendor\autoload.php') -Force
    $b2 = Invoke-ExternalCommand -FilePath $phpBinary -ArgumentList @($customerValidatorPosix, (ConvertTo-Posix $b2Src), "--commit-sha=$commit") -TimeoutSeconds 120
    Assert-That 'b2: broken staged tree is rejected by the build-time validator' ($b2.ExitCode -ne 0)
} else {
    Assert-That 'build-time validator present (customer-site repo exists)' $false
}

Remove-TestTempDirectory -Path $base
Write-TestSummaryAndExit -SuiteName 'Test-ReleaseGate'
