<#
.SYNOPSIS
    Git status/sensitive-file audit/commit/push for one project. Never
    force-pushes, never runs `git reset`/`git clean` against the user's
    own working tree changes, never commits a blocked/sensitive path.
#>

Set-StrictMode -Version Latest

# Patterns checked against each changed file's own relative path (case-insensitive).
# Matches this project's own established list (.env/secrets/private keys/real
# agent config) plus a large-file safety net.
$script:SensitiveFilePatterns = @(
    '(^|[\\/])\.env($|\.[^\\/]*$)',
    '(^|[\\/])\.env\.[^\\/]*$',
    'secret',
    'credential',
    '(^|[\\/])id_rsa($|\.)',
    '(^|[\\/])id_ed25519($|\.)',
    '\.pem$',
    '\.ppk$',
    '(^|[\\/])config\.php$',
    '(^|[\\/])\.ssh[\\/]',
    'private[_-]?key'
)
$script:LargeFileThresholdBytes = 20MB

function Get-GitStatusShort {
    param(
        [Parameter(Mandatory = $true)][string]$RepositoryPath
    )
    $result = Invoke-ExternalCommand -FilePath 'git.exe' -ArgumentList @('status', '--short') -WorkingDirectory $RepositoryPath -TimeoutSeconds 30
    if ($result.ExitCode -ne 0) {
        throw "git status در '$RepositoryPath' با خطا مواجه شد: $($result.StdErr)"
    }
    $lines = $result.StdOut -split "`r?`n" | Where-Object { $_ -ne '' }
    return $lines
}

<#
.SYNOPSIS
    Parses `git status --short` lines into their relative path (handling
    the rename "old -> new" form by returning both sides).
#>
function ConvertFrom-GitStatusLine {
    param([Parameter(Mandatory = $true)][string]$Line)

    $pathPart = $Line.Substring(3)
    if ($pathPart -match '^(.+) -> (.+)$') {
        return @($Matches[1].Trim('"'), $Matches[2].Trim('"'))
    }
    return @($pathPart.Trim('"'))
}

<#
.OUTPUTS
    Hashtable: @{ BlockedFiles (string[]); LargeFiles (string[]) }
#>
function Test-SensitiveFiles {
    param(
        [Parameter(Mandatory = $true)][string]$RepositoryPath,
        [Parameter(Mandatory = $true)][string[]]$StatusLines
    )

    $blocked = New-Object System.Collections.Generic.List[string]
    $large = New-Object System.Collections.Generic.List[string]

    foreach ($line in $StatusLines) {
        foreach ($path in (ConvertFrom-GitStatusLine -Line $line)) {
            foreach ($pattern in $script:SensitiveFilePatterns) {
                if ($path -imatch $pattern) {
                    $blocked.Add($path)
                    break
                }
            }

            $fullPath = Join-Path $RepositoryPath $path
            if (Test-Path -LiteralPath $fullPath -PathType Leaf) {
                $size = (Get-Item -LiteralPath $fullPath).Length
                if ($size -gt $script:LargeFileThresholdBytes) {
                    $large.Add("$path ($([math]::Round($size / 1MB, 1)) MB)")
                }
            }
        }
    }

    return @{
        BlockedFiles = ($blocked | Select-Object -Unique)
        LargeFiles   = ($large | Select-Object -Unique)
    }
}

function Get-CurrentGitBranch {
    param([Parameter(Mandatory = $true)][string]$RepositoryPath)
    $result = Invoke-ExternalCommand -FilePath 'git.exe' -ArgumentList @('branch', '--show-current') -WorkingDirectory $RepositoryPath -TimeoutSeconds 15
    if ($result.ExitCode -ne 0) {
        throw "دریافت branch فعلی در '$RepositoryPath' با خطا مواجه شد: $($result.StdErr)"
    }
    return $result.StdOut.Trim()
}

function Test-GitRemoteExists {
    param(
        [Parameter(Mandatory = $true)][string]$RepositoryPath,
        [Parameter(Mandatory = $true)][string]$RemoteName
    )
    $result = Invoke-ExternalCommand -FilePath 'git.exe' -ArgumentList @('remote') -WorkingDirectory $RepositoryPath -TimeoutSeconds 15
    if ($result.ExitCode -ne 0) { return $false }
    $remotes = $result.StdOut -split "`r?`n" | Where-Object { $_ -ne '' }
    return $remotes -contains $RemoteName
}

function Get-LastCommitSha {
    param([Parameter(Mandatory = $true)][string]$RepositoryPath)
    $result = Invoke-ExternalCommand -FilePath 'git.exe' -ArgumentList @('rev-parse', 'HEAD') -WorkingDirectory $RepositoryPath -TimeoutSeconds 15
    if ($result.ExitCode -ne 0) {
        throw "دریافت commit فعلی در '$RepositoryPath' با خطا مواجه شد: $($result.StdErr)"
    }
    $sha = $result.StdOut.Trim()
    if ($sha.Length -ne 40) {
        throw "SHA بازگردانده‌شده توسط git معتبر نیست (طول $($sha.Length) کاراکتر، انتظار ۴۰ کاراکتر): '$sha'"
    }
    return $sha
}

<#
.SYNOPSIS
    Stages EXACTLY the given (already sensitive-file-audited) list of
    paths — never `git add -A`/`git add .` blindly — commits, and pushes
    to the given remote/branch. Never --force. Never resets/cleans
    anything. Throws on any failure (commit or push), leaving the
    caller's own git state exactly as git itself left it (a failed push
    still has the local commit — this function does not attempt to undo
    that; see README-FA.md for why undoing a real local commit is worse
    than leaving it unpushed).

.OUTPUTS
    Hashtable: @{ CommitSha (40-char string) }
#>
function Invoke-GitCommitAndPush {
    param(
        [Parameter(Mandatory = $true)][string]$RepositoryPath,
        [Parameter(Mandatory = $true)][string[]]$PathsToStage,
        [Parameter(Mandatory = $true)][string]$CommitMessage,
        [Parameter(Mandatory = $true)][string]$RemoteName,
        [Parameter(Mandatory = $true)][string]$Branch
    )

    if ($PathsToStage.Count -eq 0) {
        throw 'فهرست فایل‌های آماده‌سازی (stage) خالی است — چیزی برای commit وجود ندارد.'
    }

    $addArgs = @('add', '--') + $PathsToStage
    $addResult = Invoke-ExternalCommand -FilePath 'git.exe' -ArgumentList $addArgs -WorkingDirectory $RepositoryPath -TimeoutSeconds 120
    if ($addResult.ExitCode -ne 0) {
        throw "git add در '$RepositoryPath' با خطا مواجه شد: $($addResult.StdErr)"
    }

    $commitResult = Invoke-ExternalCommand -FilePath 'git.exe' -ArgumentList @('commit', '-m', $CommitMessage) -WorkingDirectory $RepositoryPath -TimeoutSeconds 60
    if ($commitResult.ExitCode -ne 0) {
        throw "git commit در '$RepositoryPath' با خطا مواجه شد: $($commitResult.StdErr)$($commitResult.StdOut)"
    }

    $sha = Get-LastCommitSha -RepositoryPath $RepositoryPath

    # Never --force, never --force-with-lease — a rejected push (e.g. the
    # remote moved) must surface as a real failure, not be silently
    # overwritten.
    $pushResult = Invoke-ExternalCommand -FilePath 'git.exe' -ArgumentList @('push', $RemoteName, $Branch) -WorkingDirectory $RepositoryPath -TimeoutSeconds 180
    if ($pushResult.ExitCode -ne 0) {
        throw "git push به '$RemoteName/$Branch' با خطا مواجه شد — Deploy برای این پروژه متوقف می‌شود (commit محلی حفظ شده و push نشده باقی می‌ماند):`n$($pushResult.StdErr)"
    }

    return @{ CommitSha = $sha }
}
