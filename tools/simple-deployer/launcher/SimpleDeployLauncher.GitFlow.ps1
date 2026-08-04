<#
.SYNOPSIS
    Launcher-specific Git orchestration — built ENTIRELY on top of the
    existing lib/SimpleDeploy.Git.ps1 functions (Get-GitStatusShort,
    Test-SensitiveFiles, Invoke-GitCommitAndPush, ...). Nothing here
    re-implements commit/push mechanics; this file adds only what the
    Launcher specifically needs and the library did not already provide:
    an "ahead of origin" count, a read-only connectivity probe, retry
    classification/wrapping for `git push`, and the "clean tree but an
    unpushed local commit exists" flow (Invoke-GitCommitAndPush always
    couples add+commit+push, so a separate push-only helper is needed for
    that one case — still just `git push`, the same command
    Invoke-GitCommitAndPush already runs internally).
#>

Set-StrictMode -Version Latest

<#
.SYNOPSIS
    Classifies a git error message into a retry-relevant category. Used
    both by the connectivity check (to explain WHY it failed) and by
    Invoke-GitPushWithRetry (to decide whether to retry at all).
#>
function Get-GitErrorCategory {
    param([Parameter(Mandatory = $true)][AllowEmptyString()][string]$ErrorText)

    if ($ErrorText -match '(?i)permission denied') { return 'PermissionDenied' }
    if ($ErrorText -match '(?i)repository not found') { return 'RepositoryNotFound' }
    if ($ErrorText -match '(?i)non-fast-forward|failed to push some refs') { return 'NonFastForward' }
    if ($ErrorText -match '(?i)could not resolve host|name or service not known|temporary failure in name resolution|nodename nor servname') { return 'DnsFailure' }
    if ($ErrorText -match '(?i)connection reset|connection timed out|timed out|operation timed out|broken pipe|network is unreachable|could not read from remote repository|kex_exchange_identification') { return 'NetworkTransient' }
    return 'Unknown'
}

<#
.OUTPUTS
    $null if the ahead-count can't be determined (e.g. the remote-tracking
    ref has never been fetched locally) — never throws, since this is a
    purely informational number for the Check step.
#>
function Get-AheadCommitCount {
    param(
        [Parameter(Mandatory = $true)][string]$RepositoryPath,
        [Parameter(Mandatory = $true)][string]$RemoteName,
        [Parameter(Mandatory = $true)][string]$Branch
    )
    $ref = "$RemoteName/$Branch"
    $result = Invoke-ExternalCommand -FilePath 'git.exe' -ArgumentList @('rev-list', '--count', "$ref..HEAD") -WorkingDirectory $RepositoryPath -TimeoutSeconds 15
    if ($result.ExitCode -ne 0) { return $null }
    $trimmed = $result.StdOut.Trim()
    if ($trimmed -match '^\d+$') { return [int]$trimmed }
    return $null
}

<#
.SYNOPSIS
    Read-only network reachability probe (`git ls-remote`) — never
    mutates anything locally or remotely. Used by the "بررسی" (Check)
    button and to give a clear alias/key/network diagnosis before a real
    push attempt.
#>
function Test-GitConnectivity {
    param(
        [Parameter(Mandatory = $true)][string]$RepositoryPath,
        [Parameter(Mandatory = $true)][string]$RemoteName,
        [Parameter(Mandatory = $false)][int]$TimeoutSeconds = 10
    )
    # No specific ref (e.g. "HEAD") requested — querying with an explicit
    # ref FAILS whenever the remote's own symbolic HEAD doesn't happen to
    # resolve (e.g. its default branch differs from whatever was pushed,
    # or a freshly-created bare remote's HEAD still points at a "master"
    # that was never created) even though the remote is genuinely
    # reachable and has real refs. Confirmed empirically. Listing ALL
    # refs is the more robust reachability probe.
    $result = Invoke-ExternalCommand -FilePath 'git.exe' -ArgumentList @('ls-remote', '--exit-code', $RemoteName) -WorkingDirectory $RepositoryPath -TimeoutSeconds $TimeoutSeconds
    if ($result.ExitCode -eq 0) {
        return @{ Ok = $true; Category = $null; Message = $null }
    }
    $combined = ($result.StdErr + "`n" + $result.StdOut).Trim()
    return @{ Ok = $false; Category = (Get-GitErrorCategory -ErrorText $combined); Message = $combined }
}

<#
.SYNOPSIS
    Runs $PushAction (a scriptblock performing a real `git push`, directly
    or via Invoke-GitCommitAndPush) with the exact retry policy specified:
    up to 3 attempts total, delays of 5/15/30 seconds between them.
    Connection reset/timeout/DNS/broken-pipe errors are retried;
    permission-denied, repository-not-found, and non-fast-forward errors
    (and anything unclassified) stop immediately without retrying.
#>
function Invoke-GitPushWithRetry {
    param(
        [Parameter(Mandatory = $true)][scriptblock]$PushAction,
        [Parameter(Mandatory = $false)][int[]]$DelaysSeconds = @(5, 15, 30),
        [Parameter(Mandatory = $false)][scriptblock]$OnRetry
    )

    $maxAttempts = $DelaysSeconds.Count + 1
    $attempt = 0
    while ($true) {
        $attempt++
        try {
            return & $PushAction
        } catch {
            $category = Get-GitErrorCategory -ErrorText $_.Exception.Message
            $retryable = @('NetworkTransient', 'DnsFailure') -contains $category
            if (-not $retryable -or $attempt -ge $maxAttempts) {
                throw
            }
            $delay = $DelaysSeconds[$attempt - 1]
            if ($OnRetry) { & $OnRetry $attempt $delay $category }
            Start-Sleep -Seconds $delay
        }
    }
}

<#
.SYNOPSIS
    `git push` alone, no add/commit — for the "working tree clean but a
    local commit is still unpushed" case, where Invoke-GitCommitAndPush
    cannot be used (it would fail on `git commit` with nothing staged).
    Runs the identical `git push <remote> <branch>` command
    Invoke-GitCommitAndPush itself runs internally, never --force.
#>
function Invoke-GitPushOnly {
    param(
        [Parameter(Mandatory = $true)][string]$RepositoryPath,
        [Parameter(Mandatory = $true)][string]$RemoteName,
        [Parameter(Mandatory = $true)][string]$Branch,
        [Parameter(Mandatory = $false)][int]$TimeoutSeconds = 120
    )
    $result = Invoke-ExternalCommand -FilePath 'git.exe' -ArgumentList @('push', $RemoteName, $Branch) -WorkingDirectory $RepositoryPath -TimeoutSeconds $TimeoutSeconds
    if ($result.ExitCode -ne 0) {
        throw "git push با خطا مواجه شد: $($result.StdErr)$($result.StdOut)"
    }
    return @{ Success = $true }
}

<#
.SYNOPSIS
    The full, non-mutating "بررسی" (Check) computation for one project:
    folder/git/branch/remote sanity, uncommitted-changes status, the
    "clean but unpushed" case, ahead-of-origin count, and deployability
    (reusing Get-ProjectDeployability — no re-implementation). Never
    calls git status --short's, ls-remote aside, anything that writes.
#>
function Get-ProjectCheckResult {
    param([Parameter(Mandatory = $true)]$ProjectInfo)

    $cfg = $ProjectInfo.Config
    $result = [ordered]@{
        Key                   = $ProjectInfo.Key
        FolderExists          = $false
        IsGitRepo             = $false
        Branch                = $null
        ExpectedBranch        = [string]$cfg.branch
        BranchMatches         = $false
        RemoteExists          = $false
        StatusLines           = @()
        HasUncommittedChanges = $false
        AheadCount            = $null
        HasUnpushedCommit     = $false
        IsDeployable          = $ProjectInfo.IsDeployable
        BlockingReasons       = $ProjectInfo.BlockingReasons
        StatusKey             = 'Failed'
        FailureReason         = $null
    }

    if (-not (Test-Path -LiteralPath $cfg.local_path -PathType Container)) {
        $result.FailureReason = "پوشه پروژه پیدا نشد: $($cfg.local_path)"
        return [PSCustomObject]$result
    }
    $result.FolderExists = $true

    if (-not (Test-Path -LiteralPath (Join-Path $cfg.local_path '.git') -PathType Container)) {
        $result.FailureReason = 'این مسیر یک Git repository نیست.'
        return [PSCustomObject]$result
    }
    $result.IsGitRepo = $true

    $result.Branch = Get-CurrentGitBranch -RepositoryPath $cfg.local_path
    $result.BranchMatches = ($result.Branch -eq [string]$cfg.branch)
    $result.RemoteExists = Test-GitRemoteExists -RepositoryPath $cfg.local_path -RemoteName $cfg.remote

    $statusLines = @(Get-GitStatusShort -RepositoryPath $cfg.local_path)
    $result.StatusLines = $statusLines
    $result.HasUncommittedChanges = (@($statusLines).Count -gt 0)

    if ($result.RemoteExists) {
        $result.AheadCount = Get-AheadCommitCount -RepositoryPath $cfg.local_path -RemoteName $cfg.remote -Branch $result.Branch
    }
    $result.HasUnpushedCommit = (-not $result.HasUncommittedChanges) -and ($null -ne $result.AheadCount) -and ($result.AheadCount -gt 0)

    if (-not $result.BranchMatches) {
        $result.FailureReason = "branch فعلی '$($result.Branch)' است، در حالی که '$($cfg.branch)' انتظار می‌رفت."
        $result.StatusKey = 'Failed'
    } elseif (-not $result.RemoteExists) {
        $result.FailureReason = "remote '$($cfg.remote)' پیدا نشد."
        $result.StatusKey = 'Failed'
    } elseif ($result.HasUncommittedChanges) {
        $result.StatusKey = 'ChangesReady'
    } elseif ($result.HasUnpushedCommit) {
        $result.StatusKey = 'UnpushedCommit'
    } else {
        $result.StatusKey = 'NoChanges'
    }

    return [PSCustomObject]$result
}

<#
.SYNOPSIS
    The mutating half of "ارسال به Git": sensitive-file audit (a hard
    block, never something a confirmation can override), then commit+push
    (or push-only for the "clean but unpushed" case) with retry. Assumes
    the operator has ALREADY seen the changed-files list and confirmed —
    that confirmation is the GUI's own job, done via a real dialog on the
    UI thread BEFORE this function is ever called from a background
    operation, so this function needs no confirm callback and no
    interactive prompt of any kind. $Check must be an already-computed
    Get-ProjectCheckResult result (avoids re-running `git status` twice
    for the same user action).

.PARAMETER ProgressCallback
    scriptblock invoked as & $ProgressCallback 'StatusKey' at each phase
    transition (Committing/Pushing/PushSucceeded) — the GUI uses this to
    update the project's status label live from the background thread's
    own output queue.
#>
function Invoke-LauncherGitOnlyFlow {
    param(
        [Parameter(Mandatory = $true)]$ProjectInfo,
        [Parameter(Mandatory = $true)]$Check,
        [Parameter(Mandatory = $false)][string]$CommitMessage,
        [Parameter(Mandatory = $false)][scriptblock]$ProgressCallback,
        [Parameter(Mandatory = $false)][scriptblock]$RetryNoticeCallback
    )

    $cfg = $ProjectInfo.Config

    if (-not $Check.IsGitRepo -or -not $Check.BranchMatches -or -not $Check.RemoteExists) {
        throw "Preflight ناموفق بود: $($Check.FailureReason)"
    }

    if (-not $Check.HasUncommittedChanges -and -not $Check.HasUnpushedCommit) {
        return [PSCustomObject]@{ Status = 'NoChanges'; CommitSha = $null }
    }

    $onRetry = { param($attempt, $delay, $category) if ($RetryNoticeCallback) { & $RetryNoticeCallback $attempt $delay $category } }

    if ($Check.HasUncommittedChanges) {
        $sensitive = Test-SensitiveFiles -RepositoryPath $cfg.local_path -StatusLines $Check.StatusLines
        if (@($sensitive.BlockedFiles).Count -gt 0) {
            throw "فایل حساس/مسدود در تغییرات پیدا شد (commit نخواهد شد): $($sensitive.BlockedFiles -join ', ')"
        }

        if ($ProgressCallback) { & $ProgressCallback 'Committing' }
        $pathsToStage = New-Object System.Collections.Generic.List[string]
        foreach ($line in $Check.StatusLines) {
            foreach ($p in (ConvertFrom-GitStatusLine -Line $line)) { $pathsToStage.Add($p) }
        }
        $pathsToStage = @($pathsToStage | Select-Object -Unique)

        $effectiveMessage = if ([string]::IsNullOrWhiteSpace($CommitMessage)) {
            "SimpleDeploy: به‌روزرسانی خودکار $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
        } else {
            $CommitMessage
        }

        if ($ProgressCallback) { & $ProgressCallback 'Pushing' }
        $pushResult = Invoke-GitPushWithRetry -OnRetry $onRetry -PushAction {
            Invoke-GitCommitAndPush -RepositoryPath $cfg.local_path -PathsToStage $pathsToStage -CommitMessage $effectiveMessage -RemoteName $cfg.remote -Branch $cfg.branch
        }
        if ($ProgressCallback) { & $ProgressCallback 'PushSucceeded' }
        return [PSCustomObject]@{ Status = 'Succeeded'; CommitSha = $pushResult.CommitSha }
    } else {
        # Working tree clean, but HEAD is ahead of origin — push the
        # EXISTING commit; never create a duplicate/empty commit for it.
        if ($ProgressCallback) { & $ProgressCallback 'Pushing' }
        Invoke-GitPushWithRetry -OnRetry $onRetry -PushAction {
            Invoke-GitPushOnly -RepositoryPath $cfg.local_path -RemoteName $cfg.remote -Branch $cfg.branch
        } | Out-Null
        if ($ProgressCallback) { & $ProgressCallback 'PushSucceeded' }
        $sha = Get-LastCommitSha -RepositoryPath $cfg.local_path
        return [PSCustomObject]@{ Status = 'Succeeded'; CommitSha = $sha }
    }
}
