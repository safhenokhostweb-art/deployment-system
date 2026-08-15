<#
.SYNOPSIS
    Isolated local build — mirrors the exact same root-cause fixes already
    proven in this project's own Local Agent (BuildWorkspace/
    WorkspaceSourcePreparer/ArtifactBuilder): every project gets its own
    disposable workspace OUTSIDE its own working tree; `git archive` of
    the EXACT pushed commit populates it (never the live working tree's
    current file contents); Composer/npm run only inside that workspace,
    never touching the real vendor/node_modules; the final artifact is a
    deterministic zip excluding node_modules/.git.

.NOTES
    Windows Junction detection: this file's ZIP-Slip/entry-safety logic
    intentionally does NOT attempt to distinguish a Windows Junction from
    an ordinary directory (a distinction this project's PHP Local Agent
    had to solve the hard way — see SafeFilesystem.php's own docblock for
    why `Test-Path -PathType Container`/naive checks are NOT reliable
    signals on Windows). Copy-Item -Recurse follows and copies the REAL,
    resolved content of a Junction (never recreates a link), which is
    exactly the safe behavior wanted here — no special-casing needed.
#>

Set-StrictMode -Version Latest

function New-BuildWorkspace {
    param(
        [Parameter(Mandatory = $true)][string]$BaseDir,
        [Parameter(Mandatory = $true)][string]$ProjectKey,
        [Parameter(Mandatory = $true)][string]$JobId
    )

    $safeProjectKey = ($ProjectKey -replace '[^A-Za-z0-9_.-]', '_')
    $safeJobId = ($JobId -replace '[^A-Za-z0-9_.-]', '_')
    $root = Join-Path (Join-Path $BaseDir $safeProjectKey) $safeJobId

    if (Test-Path -LiteralPath $root) {
        Remove-DirectoryTreeGuarded -Path $root -ExpectedFloor $BaseDir
    }

    New-Item -ItemType Directory -Path $root -Force | Out-Null
    $projectDir = Join-Path $root 'project'
    New-Item -ItemType Directory -Path $projectDir -Force | Out-Null

    return @{ Root = $root; ProjectDir = $projectDir }
}

<#
.SYNOPSIS
    Race-guard (confirm HEAD is still exactly $CommitSha) then `git
    archive` that exact commit into a temp zip, extracted with an
    explicit ZIP-Slip guard (entry names containing "..", starting with
    "/", or matching a Windows drive letter are rejected before
    extraction — the same rule already proven in this project's PHP
    SafeFilesystem::extractZipSafely()).

.PARAMETER SourceSubdirectory
    Optional (Task 4B) — for a monorepo whose real deployable app lives
    below the repository root (e.g. deployment-system's own service/
    Laravel app next to unrelated agent/, local-agent/, tools/ source).
    $null/empty (the default) is EXACTLY today's unchanged behavior —
    the whole commit is archived, nothing about this path is exercised
    for customer-site/saas-platform or any other project that has never
    set this field.

    When given, uses git's own `<commit>:<subdirectory>` tree-ish
    addressing (`git archive $CommitSha:$SourceSubdirectory`) — NOT
    `git archive $CommitSha -- $SourceSubdirectory` (which would keep
    the subdirectory as a path PREFIX inside the archive, e.g.
    "service/artisan"). The colon form makes the subdirectory's own
    tree object the archive ROOT, so entries land as "artisan",
    "composer.json", "app/..." with NO prefix at all — confirmed
    empirically against a real repo before relying on it here. This is
    git's own standard, documented mechanism for rooting an archive at a
    subtree, not a fragile move/rename workaround: it is structurally
    impossible for a file outside that subtree to ever appear in the
    resulting archive, since git resolves a distinct tree object for it.
#>
function Export-GitArchiveToWorkspace {
    param(
        [Parameter(Mandatory = $true)][string]$RepositoryPath,
        [Parameter(Mandatory = $true)][string]$CommitSha,
        [Parameter(Mandatory = $true)][string]$DestinationDir,
        [Parameter(Mandatory = $false)][string]$SourceSubdirectory = $null
    )

    $actualHead = Get-LastCommitSha -RepositoryPath $RepositoryPath
    if ($actualHead -ne $CommitSha) {
        throw "Refusing to build an artifact: HEAD اکنون '$actualHead' است، در حالی که انتظار می‌رفت دقیقاً '$CommitSha' باشد — به‌نظر می‌رسد working tree در حین build تغییر کرده است."
    }

    $treeIsh = $CommitSha
    if (-not [string]::IsNullOrWhiteSpace($SourceSubdirectory)) {
        # Defense in depth: git itself already refuses "../outside" (exits
        # 128, "fatal: ... is outside repository") and a nonexistent/
        # absolute path (exits 128, "fatal: not a valid object name") —
        # confirmed empirically — but this tool never relies solely on a
        # downstream command's own error text for a security boundary
        # (same standard already applied to ZIP-Slip / path-repository
        # checks elsewhere in this file): reject obviously-unsafe values
        # here first, with a clear Persian message, before ever invoking
        # git with them.
        $normalizedSub = $SourceSubdirectory.Trim().Replace('\', '/').Trim('/')
        $hasTraversalSegment = @($normalizedSub -split '/') -contains '..'
        if ([string]::IsNullOrWhiteSpace($normalizedSub) -or
            $normalizedSub.StartsWith('/') -or
            ($normalizedSub -match '^[A-Za-z]:') -or
            $hasTraversalSegment) {
            throw "source_subdirectory مقدار نامعتبر دارد ('$SourceSubdirectory') — فقط یک مسیر نسبی داخل خود repository مجاز است (نه مسیر مطلق، نه ../)."
        }
        $treeIsh = "${CommitSha}:${normalizedSub}"
    }

    $tempZip = Join-Path $env:TEMP "simpledeploy-archive-$([System.Guid]::NewGuid().ToString('N').Substring(0,10)).zip"
    try {
        $result = Invoke-ExternalCommand -FilePath 'git.exe' -ArgumentList @('archive', '--format=zip', '-o', $tempZip, $treeIsh) -WorkingDirectory $RepositoryPath -TimeoutSeconds 300
        if ($result.ExitCode -ne 0) {
            $subdirNote = if ($SourceSubdirectory) { " (source_subdirectory='$SourceSubdirectory' — بررسی کنید این مسیر واقعاً در این commit وجود دارد)" } else { '' }
            throw "git archive برای commit '$CommitSha'$subdirNote با خطا مواجه شد: $($result.StdErr)"
        }
        if (-not (Test-Path -LiteralPath $tempZip) -or (Get-Item -LiteralPath $tempZip).Length -eq 0) {
            throw 'git archive یک فایل خالی یا غیرقابل‌خواندن تولید کرد.'
        }

        Expand-ZipSafely -ZipPath $tempZip -Destination $DestinationDir
    } finally {
        if (Test-Path -LiteralPath $tempZip) {
            Remove-Item -LiteralPath $tempZip -Force -ErrorAction SilentlyContinue
        }
    }
}

function Expand-ZipSafely {
    param(
        [Parameter(Mandatory = $true)][string]$ZipPath,
        [Parameter(Mandatory = $true)][string]$Destination
    )

    Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction SilentlyContinue
    $archive = [System.IO.Compression.ZipFile]::OpenRead($ZipPath)
    try {
        foreach ($entry in $archive.Entries) {
            $name = $entry.FullName
            if ($name.StartsWith('/') -or $name.Contains('..') -or ($name -match '^[A-Za-z]:[\\/]')) {
                throw "Refusing to extract a zip entry with an unsafe path: $name"
            }
        }

        if (-not (Test-Path -LiteralPath $Destination)) {
            New-Item -ItemType Directory -Path $Destination -Force | Out-Null
        }

        foreach ($entry in $archive.Entries) {
            if ($entry.FullName.EndsWith('/')) { continue } # directory entry
            $targetPath = Join-Path $Destination $entry.FullName
            $targetDir = Split-Path -Parent $targetPath
            if (-not (Test-Path -LiteralPath $targetDir)) {
                New-Item -ItemType Directory -Path $targetDir -Force | Out-Null
            }
            [System.IO.Compression.ZipFileExtensions]::ExtractToFile($entry, $targetPath, $true)
        }
    } finally {
        $archive.Dispose()
    }
}

<#
.SYNOPSIS
    Reads composer.json FROM THE ALREADY-ARCHIVED WORKSPACE COPY (the
    exact published commit — not the live working tree, same
    commit-fidelity reasoning as the archive step itself), finds every
    `"repositories": [{"type": "path", "url": "..."}]` entry, and copies
    each referenced directory from its real, live location (the only
    place it actually exists) into the workspace at the SAME relative
    position it holds next to the real project — fully generic, no
    project names hardcoded.
#>
function Resolve-ComposerPathDependencies {
    param(
        [Parameter(Mandatory = $true)][string]$RepositoryPath,
        [Parameter(Mandatory = $true)][string]$WorkspaceRoot,
        [Parameter(Mandatory = $true)][string]$WorkspaceProjectDir
    )

    $composerJsonPath = Join-Path $WorkspaceProjectDir 'composer.json'
    if (-not (Test-Path -LiteralPath $composerJsonPath)) { return }

    $composerJson = Get-Content -LiteralPath $composerJsonPath -Raw -Encoding UTF8 | ConvertFrom-Json
    if (-not $composerJson.PSObject.Properties['repositories']) { return }

    foreach ($repository in $composerJson.repositories) {
        if ($repository.type -ne 'path') { continue }
        $url = [string]$repository.url
        if ([string]::IsNullOrWhiteSpace($url)) { continue }

        if ($url.StartsWith('/') -or ($url -match '^[A-Za-z]:[\\/]')) {
            throw "composer.json یک path repository مطلق تعریف کرده ('$url') — این ابزار فقط از path repositoryهای نسبی (نسبت به composer.json) پشتیبانی می‌کند."
        }

        $realSourcePath = Join-Path $RepositoryPath $url
        if (-not (Test-Path -LiteralPath $realSourcePath -PathType Container)) {
            throw "composer.json یک path repository تعریف کرده ('$url') که پیدا نشد: $realSourcePath"
        }
        $resolvedSource = (Resolve-Path -LiteralPath $realSourcePath).ProviderPath

        # Same relative position next to "project/" as the real source
        # holds next to the real repository — computed via .NET's own
        # normalization (handles ../ segments correctly on Windows). Both
        # sides of the boundary check MUST go through the SAME
        # normalization function: GetFullPath silently expands an 8.3
        # short name (e.g. "FARSHA~1", which $env:TEMP can be on this
        # host) to its long form, but Resolve-Path does not — mixing the
        # two produced two strings that referred to the identical real
        # directory but compared as different, causing every in-bounds
        # path repository to be falsely rejected as "outside the
        # workspace". Confirmed empirically.
        $targetPath = [System.IO.Path]::GetFullPath((Join-Path $WorkspaceProjectDir $url))
        $normalizedRoot = [System.IO.Path]::GetFullPath($WorkspaceRoot).TrimEnd('\', '/')
        if (-not $targetPath.StartsWith("$normalizedRoot\", [StringComparison]::OrdinalIgnoreCase) -and $targetPath -ne $normalizedRoot) {
            throw "path repository '$url' خارج از مرز workspace ایزوله resolve می‌شود ($targetPath) — از کپی آن صرف‌نظر شد."
        }

        $targetParent = Split-Path -Parent $targetPath
        if (-not (Test-Path -LiteralPath $targetParent)) {
            New-Item -ItemType Directory -Path $targetParent -Force | Out-Null
        }
        Copy-Item -LiteralPath $resolvedSource -Destination $targetPath -Recurse -Force
    }
}

function Invoke-PrefixedCommand {
    param(
        [Parameter(Mandatory = $true)][string[]]$CommandPrefix,
        [Parameter(Mandatory = $true)][string[]]$SubcommandArgs,
        [Parameter(Mandatory = $true)][string]$WorkingDirectory,
        [Parameter(Mandatory = $false)][int]$TimeoutSeconds = 600
    )
    $full = @($CommandPrefix) + @($SubcommandArgs)
    return Invoke-ExternalCommand -FilePath $full[0] -ArgumentList $full[1..($full.Count - 1)] -WorkingDirectory $WorkingDirectory -TimeoutSeconds $TimeoutSeconds
}

<#
.PARAMETER ComposerCommand
    Base argv for invoking Composer — e.g. @('composer') (default,
    relies on PATH) or an explicit path, optionally as
    @($phpBinary, 'composer.phar') (two argv elements — never a shell
    string), mirroring the exact same PATH-independence fix already
    proven necessary for this project's own PHP Local Agent (a Windows
    Scheduled Task's PATH does not necessarily contain composer/npm even
    when an interactive shell's does).
#>
function Invoke-IsolatedComposerInstall {
    param(
        [Parameter(Mandatory = $true)][string]$WorkspaceProjectDir,
        [Parameter(Mandatory = $true)][string[]]$ComposerCommand,
        [Parameter(Mandatory = $false)][int]$TimeoutSeconds = 600
    )

    if (-not (Test-Path -LiteralPath (Join-Path $WorkspaceProjectDir 'composer.json'))) {
        return @{ Ran = $false }
    }

    $composerArgs = @('install', '--no-dev', '--prefer-dist', '--optimize-autoloader', '--no-interaction', '--no-scripts')
    $result = Invoke-PrefixedCommand -CommandPrefix $ComposerCommand -SubcommandArgs $composerArgs -WorkingDirectory $WorkspaceProjectDir -TimeoutSeconds $TimeoutSeconds
    if ($result.ExitCode -ne 0) {
        throw "composer install با خطا مواجه شد (exit $($result.ExitCode)):`n$($result.StdErr)$($result.StdOut)"
    }
    if (-not (Test-Path -LiteralPath (Join-Path $WorkspaceProjectDir 'vendor\autoload.php'))) {
        throw 'composer install موفق گزارش شد اما vendor\autoload.php وجود ندارد.'
    }

    return @{ Ran = $true }
}

function Invoke-IsolatedNpmBuild {
    param(
        [Parameter(Mandatory = $true)][string]$WorkspaceProjectDir,
        [Parameter(Mandatory = $true)][string[]]$NpmCommand,
        [Parameter(Mandatory = $false)][int]$InstallTimeoutSeconds = 600,
        [Parameter(Mandatory = $false)][int]$BuildTimeoutSeconds = 600
    )

    if (-not (Test-Path -LiteralPath (Join-Path $WorkspaceProjectDir 'package.json'))) {
        return @{ RanInstall = $false; RanBuild = $false }
    }

    # Root-cause fix already proven for this project's PHP Local Agent:
    # npm can finish all of its real work and still linger as a live OS
    # process afterward (registry keep-alive sockets, the update-notifier
    # check) — these three flags remove two of the known contributors.
    $quietFlags = @('--no-audit', '--no-fund', '--no-update-notifier')

    $ciArgs = @('ci', '--include=dev') + $quietFlags
    $ciResult = Invoke-PrefixedCommand -CommandPrefix $NpmCommand -SubcommandArgs $ciArgs -WorkingDirectory $WorkspaceProjectDir -TimeoutSeconds $InstallTimeoutSeconds
    if ($ciResult.ExitCode -ne 0) {
        throw "npm ci با خطا مواجه شد (exit $($ciResult.ExitCode)):`n$($ciResult.StdErr)$($ciResult.StdOut)"
    }

    $ranBuild = $false
    $packageJson = Get-Content -LiteralPath (Join-Path $WorkspaceProjectDir 'package.json') -Raw -Encoding UTF8 | ConvertFrom-Json
    if ($packageJson.PSObject.Properties['scripts'] -and $packageJson.scripts.PSObject.Properties['build']) {
        $buildArgs = @('run', 'build') + $quietFlags
        $buildResult = Invoke-PrefixedCommand -CommandPrefix $NpmCommand -SubcommandArgs $buildArgs -WorkingDirectory $WorkspaceProjectDir -TimeoutSeconds $BuildTimeoutSeconds
        if ($buildResult.ExitCode -ne 0) {
            throw "npm run build با خطا مواجه شد (exit $($buildResult.ExitCode)):`n$($buildResult.StdErr)$($buildResult.StdOut)"
        }
        $ranBuild = $true
    }

    return @{ RanInstall = $true; RanBuild = $ranBuild }
}

<#
.SYNOPSIS
    Builds the final, deterministic artifact zip directly from the
    workspace's project directory — `node_modules/` and `.git/` are
    excluded wherever found (node_modules never belongs in the artifact;
    .git is never produced by `git archive` in the first place, excluded
    here purely as defense in depth).
#>
function New-DeploymentArtifact {
    param(
        [Parameter(Mandatory = $true)][string]$WorkspaceProjectDir,
        [Parameter(Mandatory = $true)][string]$OutputZipPath
    )

    if (Test-Path -LiteralPath $OutputZipPath) {
        Remove-Item -LiteralPath $OutputZipPath -Force
    }

    Add-Type -AssemblyName System.IO.Compression.FileSystem -ErrorAction SilentlyContinue
    Add-Type -AssemblyName System.IO.Compression -ErrorAction SilentlyContinue

    # GetFullPath (not Resolve-Path) — Get-ChildItem's own .FullName values
    # are already in long-name form, and Resolve-Path's ProviderPath does
    # NOT expand an 8.3 short name (e.g. "FARSHA~1") the way GetFullPath
    # does; mixing the two here silently truncated every entry name by a
    # few characters. Confirmed empirically (same root cause as the
    # workspace-escape guard fix in Resolve-ComposerPathDependencies above).
    $rootLength = ([System.IO.Path]::GetFullPath($WorkspaceProjectDir)).TrimEnd('\', '/').Length

    $files = Get-ChildItem -LiteralPath $WorkspaceProjectDir -Recurse -File -Force | Where-Object {
        $relative = $_.FullName.Substring($rootLength + 1)
        ($relative -notmatch '(^|[\\/])node_modules([\\/]|$)') -and ($relative -notmatch '(^|[\\/])\.git([\\/]|$)')
    }

    if (@($files).Count -eq 0) {
        throw 'هیچ فایلی برای ساخت Artifact پیدا نشد — workspace خالی است.'
    }

    $zipStream = [System.IO.File]::Open($OutputZipPath, [System.IO.FileMode]::CreateNew)
    try {
        $zipArchive = New-Object System.IO.Compression.ZipArchive($zipStream, [System.IO.Compression.ZipArchiveMode]::Create)
        try {
            # Deterministic order — same content always produces the same
            # entry sequence, matching this project's own PHP ArtifactBuilder.
            $sortedFiles = $files | Sort-Object { $_.FullName.Substring($rootLength + 1).Replace('\', '/') }
            foreach ($file in $sortedFiles) {
                $relativePath = $file.FullName.Substring($rootLength + 1).Replace('\', '/')
                [System.IO.Compression.ZipFileExtensions]::CreateEntryFromFile($zipArchive, $file.FullName, $relativePath, [System.IO.Compression.CompressionLevel]::Optimal) | Out-Null
            }
        } finally {
            $zipArchive.Dispose()
        }
    } finally {
        $zipStream.Dispose()
    }

    $checksum = Get-FileSha256 -Path $OutputZipPath
    return @{ Path = $OutputZipPath; ChecksumSha256 = $checksum; SizeBytes = (Get-Item -LiteralPath $OutputZipPath).Length }
}
