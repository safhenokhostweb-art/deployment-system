<#
.SYNOPSIS
    Tests: launcher/SimpleDeployLauncher.GitFlow.ps1 — error
    classification, retry policy (exact attempt counts, retryable vs
    non-retryable), ahead-of-origin count, connectivity probing (against
    a real local bare repo, never GitHub), the full check/no-changes/
    changed-file/unpushed-commit matrix, sensitive-file blocking, and log
    secret-redaction. All real git repos, no mocking, never a real
    network host.
#>

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ToolRoot = Split-Path -Parent $ScriptDir
. (Join-Path $ScriptDir 'TestHelpers.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Common.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Config.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Git.ps1')
. (Join-Path $ToolRoot 'launcher\SimpleDeployLauncher.Status.ps1')
. (Join-Path $ToolRoot 'launcher\SimpleDeployLauncher.GitFlow.ps1')
. (Join-Path $ToolRoot 'launcher\SimpleDeployLauncher.Log.ps1')

# ============================================================
# 1. Get-GitErrorCategory — pure classification, instant.
# ============================================================
Assert-That 'permission denied is classified as PermissionDenied (non-retryable)' ((Get-GitErrorCategory -ErrorText 'ERROR: Permission denied (publickey).') -eq 'PermissionDenied')
Assert-That 'repository not found is classified as RepositoryNotFound (non-retryable)' ((Get-GitErrorCategory -ErrorText 'remote: Repository not found.') -eq 'RepositoryNotFound')
Assert-That 'non-fast-forward is classified as NonFastForward (non-retryable)' ((Get-GitErrorCategory -ErrorText '! [rejected] main -> main (non-fast-forward)') -eq 'NonFastForward')
Assert-That 'connection reset is classified as NetworkTransient (retryable)' ((Get-GitErrorCategory -ErrorText 'error: RPC failed; curl 56 Connection reset by peer') -eq 'NetworkTransient')
Assert-That 'a timeout is classified as NetworkTransient (retryable)' ((Get-GitErrorCategory -ErrorText 'ssh: connect to host github.com port 22: Operation timed out') -eq 'NetworkTransient')
Assert-That 'a broken pipe is classified as NetworkTransient (retryable)' ((Get-GitErrorCategory -ErrorText 'write error: Broken pipe') -eq 'NetworkTransient')
Assert-That 'a DNS failure is classified as DnsFailure (retryable)' ((Get-GitErrorCategory -ErrorText 'ssh: Could not resolve hostname github.com') -eq 'DnsFailure')
Assert-That 'an unrecognized error is classified as Unknown (non-retryable by default)' ((Get-GitErrorCategory -ErrorText 'some completely different git error') -eq 'Unknown')

# ============================================================
# 2. Invoke-GitPushWithRetry — exact attempt counts and stop conditions.
#    Tiny delays (0s) used here to keep the suite fast; a SEPARATE test
#    below confirms the real production defaults are 5/15/30.
# ============================================================
$script:attemptCount = 0
$retryNotices = New-Object System.Collections.Generic.List[object]
$result = Invoke-GitPushWithRetry -DelaysSeconds @(0, 0) -OnRetry {
    param($attempt, $delay, $category) $retryNotices.Add(@{ Attempt = $attempt; Delay = $delay; Category = $category })
} -PushAction {
    $script:attemptCount++
    if ($script:attemptCount -lt 3) { throw 'Connection reset by peer' }
    return 'success-on-third-try'
}
Assert-That 'a transient error retried twice succeeds on the 3rd attempt' ($result -eq 'success-on-third-try')
Assert-That 'exactly 3 attempts were made (2 failures + 1 success)' ($script:attemptCount -eq 3)
Assert-That 'exactly 2 retry notices fired' ($retryNotices.Count -eq 2)

$script:attemptCount2 = 0
$permissionThrew = $false
try {
    Invoke-GitPushWithRetry -DelaysSeconds @(0, 0, 0) -PushAction {
        $script:attemptCount2++
        throw 'Permission denied (publickey).'
    }
} catch {
    $permissionThrew = $true
}
Assert-That 'a permission-denied error throws' $permissionThrew
Assert-That 'a permission-denied error is NEVER retried — exactly 1 attempt' ($script:attemptCount2 -eq 1)

$script:attemptCount3 = 0
$exhaustedThrew = $false
try {
    Invoke-GitPushWithRetry -DelaysSeconds @(0, 0) -PushAction {
        $script:attemptCount3++
        throw 'Connection timed out'
    }
} catch {
    $exhaustedThrew = $true
}
Assert-That 'a persistently transient error eventually throws once retries are exhausted' $exhaustedThrew
Assert-That 'exactly 3 attempts were made (1 + 2 retries, matching 2 delay values), never more' ($script:attemptCount3 -eq 3)

$defaultParam = (Get-Command Invoke-GitPushWithRetry).Parameters['DelaysSeconds']
$defaultDelays = (Get-Content (Join-Path $ToolRoot 'launcher\SimpleDeployLauncher.GitFlow.ps1') -Raw)
Assert-That 'the real default retry delays are exactly 5, 15, 30 seconds' ($defaultDelays -match '\[int\[\]\]\$DelaysSeconds = @\(5, 15, 30\)')

# ============================================================
# 3. Get-AheadCommitCount / Get-ProjectCheckResult — the full
#    NoChanges / ChangesReady / UnpushedCommit matrix, real repos.
# ============================================================
function New-CheckFixtureProject {
    param([string]$RepoPath, [string]$Branch = 'main')
    Initialize-TestGitRepo -Path $RepoPath -Branch $Branch
    'v1' | Set-Content -LiteralPath (Join-Path $RepoPath 'app.txt') -Encoding ASCII -NoNewline
    & git.exe -C $RepoPath add -- app.txt 2>&1 | Out-Null
    & git.exe -C $RepoPath commit -m 'initial' --quiet 2>&1 | Out-Null

    # A per-repo-unique bare remote name — NOT a shared "remote.git" next
    # to every fixture under the same base temp dir, which would silently
    # make multiple unrelated fixtures push into the SAME bare repo and
    # collide with non-fast-forward rejections. Confirmed empirically.
    $bareRemotePath = Join-Path (Split-Path -Parent $RepoPath) "$(Split-Path -Leaf $RepoPath)-remote.git"
    & git.exe init --quiet --bare $bareRemotePath 2>&1 | Out-Null
    & git.exe -C $RepoPath remote add origin $bareRemotePath 2>&1 | Out-Null
    & git.exe -C $RepoPath push --quiet origin ${Branch}:${Branch} 2>&1 | Out-Null
    & git.exe -C $RepoPath fetch --quiet origin 2>&1 | Out-Null

    return [PSCustomObject]@{
        Key    = 'fixture-project'
        Config = [PSCustomObject]@{ local_path = $RepoPath; branch = $Branch; remote = 'origin'; build_mode = 'disabled' }
        IsDeployable = $false
        BlockingReasons = @('build_mode is disabled (test fixture)')
    }
}

$base3 = New-TestTempDirectory -Prefix 'sdtest-launcher-check'

# 3a. No changes at all.
$repo3a = Join-Path $base3 'nochange'
$proj3a = New-CheckFixtureProject -RepoPath $repo3a
$check3a = Get-ProjectCheckResult -ProjectInfo $proj3a
Assert-That 'a project with no changes and nothing ahead reports StatusKey=NoChanges' ($check3a.StatusKey -eq 'NoChanges')
Assert-That 'NoChanges: HasUncommittedChanges is false' (-not $check3a.HasUncommittedChanges)
Assert-That 'NoChanges: HasUnpushedCommit is false' (-not $check3a.HasUnpushedCommit)
Assert-That 'NoChanges: AheadCount is 0' ($check3a.AheadCount -eq 0)

# 3b. A changed (uncommitted) file.
$repo3b = Join-Path $base3 'changed'
$proj3b = New-CheckFixtureProject -RepoPath $repo3b
'v2 edited' | Set-Content -LiteralPath (Join-Path $repo3b 'app.txt') -Encoding ASCII -NoNewline
$check3b = Get-ProjectCheckResult -ProjectInfo $proj3b
Assert-That 'a project with an uncommitted change reports StatusKey=ChangesReady' ($check3b.StatusKey -eq 'ChangesReady')
Assert-That 'ChangesReady: HasUncommittedChanges is true' $check3b.HasUncommittedChanges
Assert-That 'ChangesReady: HasUnpushedCommit is false (uncommitted changes take priority)' (-not $check3b.HasUnpushedCommit)

# 3c. Clean tree but a local commit unpushed to origin.
$repo3c = Join-Path $base3 'unpushed'
$proj3c = New-CheckFixtureProject -RepoPath $repo3c
'v2' | Set-Content -LiteralPath (Join-Path $repo3c 'app.txt') -Encoding ASCII -NoNewline
& git.exe -C $repo3c add -- app.txt 2>&1 | Out-Null
& git.exe -C $repo3c commit -m 'second commit, never pushed' --quiet 2>&1 | Out-Null
$check3c = Get-ProjectCheckResult -ProjectInfo $proj3c
Assert-That 'a clean tree with an unpushed commit reports StatusKey=UnpushedCommit (never NoChanges)' ($check3c.StatusKey -eq 'UnpushedCommit')
Assert-That 'UnpushedCommit: HasUncommittedChanges is false' (-not $check3c.HasUncommittedChanges)
Assert-That 'UnpushedCommit: HasUnpushedCommit is true' $check3c.HasUnpushedCommit
Assert-That 'UnpushedCommit: AheadCount is exactly 1' ($check3c.AheadCount -eq 1)

# ============================================================
# 4. Invoke-LauncherGitOnlyFlow — the three outcomes, proven against the
#    same real fixtures, including "never create a duplicate commit" for
#    the unpushed-commit case.
# ============================================================

# 4a. NoChanges -> no mutation at all.
$shaBefore4a = Get-LastCommitSha -RepositoryPath $repo3a
$flow4a = Invoke-LauncherGitOnlyFlow -ProjectInfo $proj3a -Check (Get-ProjectCheckResult -ProjectInfo $proj3a)
Assert-That 'Invoke-LauncherGitOnlyFlow on a no-changes project returns Status=NoChanges' ($flow4a.Status -eq 'NoChanges')
Assert-That 'no commit was created for a no-changes project' ((Get-LastCommitSha -RepositoryPath $repo3a) -eq $shaBefore4a)

# 4b. ChangesReady with a sensitive file present -> hard block, nothing pushed.
$repo4b = Join-Path $base3 'sensitive'
$proj4b = New-CheckFixtureProject -RepoPath $repo4b
'DB_PASSWORD=secret' | Set-Content -LiteralPath (Join-Path $repo4b '.env') -Encoding ASCII -NoNewline
$check4b = Get-ProjectCheckResult -ProjectInfo $proj4b
$blockedThrew = $false
try {
    Invoke-LauncherGitOnlyFlow -ProjectInfo $proj4b -Check $check4b | Out-Null
} catch {
    $blockedThrew = $true
    Assert-That 'the sensitive-file block error names the blocked file' ($_.Exception.Message -match '\.env')
}
Assert-That 'a sensitive file in the change set blocks the flow entirely' $blockedThrew

# 4c. ChangesReady, real commit+push, exactly one new commit, real 40-char SHA.
$shaBefore4c = Get-LastCommitSha -RepositoryPath $repo3b
$check4c = Get-ProjectCheckResult -ProjectInfo $proj3b
$progressEvents = New-Object System.Collections.Generic.List[string]
$flow4c = Invoke-LauncherGitOnlyFlow -ProjectInfo $proj3b -Check $check4c -CommitMessage 'test: launcher git-only commit' -ProgressCallback {
    param($statusKey) $progressEvents.Add($statusKey)
}
Assert-That 'a real commit+push succeeds and reports Status=Succeeded' ($flow4c.Status -eq 'Succeeded')
Assert-That 'a genuine 40-character commit SHA is returned' ($flow4c.CommitSha -match '^[0-9a-f]{40}$')
Assert-That 'the SHA actually changed (a new commit was made)' ($flow4c.CommitSha -ne $shaBefore4c)
Assert-That 'progress callbacks fired for Committing then Pushing then PushSucceeded, in order' (($progressEvents -join ',') -eq 'Committing,Pushing,PushSucceeded')
$statusAfter4c = @(Get-GitStatusShort -RepositoryPath $repo3b)
Assert-That 'the working tree is clean after a successful commit+push' ($statusAfter4c.Count -eq 0)

# 4d. UnpushedCommit -> pushes the EXISTING commit, creates NO new commit.
$shaBefore4d = Get-LastCommitSha -RepositoryPath $repo3c
$check4d = Get-ProjectCheckResult -ProjectInfo $proj3c
$flow4d = Invoke-LauncherGitOnlyFlow -ProjectInfo $proj3c -Check $check4d
Assert-That 'pushing an already-existing unpushed commit reports Status=Succeeded' ($flow4d.Status -eq 'Succeeded')
Assert-That 'the SHA is UNCHANGED — the existing commit was pushed, not duplicated' ($flow4d.CommitSha -eq $shaBefore4d)
$checkAfter4d = Get-ProjectCheckResult -ProjectInfo $proj3c
Assert-That 'after pushing, the project no longer reports an unpushed commit' (-not $checkAfter4d.HasUnpushedCommit)

Remove-TestTempDirectory -Path $base3

# ============================================================
# 5. Test-GitConnectivity — against a REAL local bare repo (success) and
#    a genuinely unreachable path (failure, correctly categorized).
#    Never touches a real network host.
# ============================================================
$base5 = New-TestTempDirectory -Prefix 'sdtest-launcher-connectivity'
$repo5 = Join-Path $base5 'repo'
$proj5 = New-CheckFixtureProject -RepoPath $repo5
$connOk = Test-GitConnectivity -RepositoryPath $repo5 -RemoteName 'origin' -TimeoutSeconds 10
Assert-That 'connectivity check against a real, reachable local bare repo succeeds' $connOk.Ok

& git.exe -C $repo5 remote set-url origin 'https://this-host-does-not-exist.invalid.test/x.git' 2>&1 | Out-Null
$connFail = Test-GitConnectivity -RepositoryPath $repo5 -RemoteName 'origin' -TimeoutSeconds 10
Assert-That 'connectivity check against an unresolvable host fails' (-not $connFail.Ok)
Assert-That 'the failure is categorized as a DNS failure' ($connFail.Category -eq 'DnsFailure')
Remove-TestTempDirectory -Path $base5

# ============================================================
# 6. Log secret redaction.
# ============================================================
$pemBlock = "-----BEGIN OPENSSH PRIVATE KEY-----`nb3BlbnNzaC1rZXk-fake-content-here`n-----END OPENSSH PRIVATE KEY-----"
$redactedPem = Protect-LauncherLogText -Text "some output`n$pemBlock`nmore output"
Assert-That 'a PEM private key block is fully redacted' ($redactedPem -notmatch 'BEGIN OPENSSH PRIVATE KEY' -and $redactedPem -match '\[REDACTED-PRIVATE-KEY\]')
Assert-That 'text around the redacted key block is preserved' ($redactedPem -match 'some output' -and $redactedPem -match 'more output')

$redactedPassword = Protect-LauncherLogText -Text 'DB_PASSWORD=SuperSecret123'
Assert-That 'a PASSWORD=value line has its value redacted' ($redactedPassword -match 'DB_PASSWORD=\[REDACTED\]' -and $redactedPassword -notmatch 'SuperSecret123')

$redactedToken = Protect-LauncherLogText -Text 'GITHUB_TOKEN: ghp_abcdef1234567890'
Assert-That 'a TOKEN: value line has its value redacted' ($redactedToken -notmatch 'ghp_abcdef1234567890')

$normalText = Protect-LauncherLogText -Text 'commit abc123def push succeeded to origin/main'
Assert-That 'ordinary, non-secret text passes through unchanged' ($normalText -eq 'commit abc123def push succeeded to origin/main')

# Complete-LauncherRunLog writes a real file — confirm the secret never
# lands on disk even when the caller passes raw (unredacted) text in.
$logsDir6 = New-TestTempDirectory -Prefix 'sdtest-launcher-logfile'
$runLog6 = New-LauncherRunLog -ProjectKey 'redaction-test' -LogsRootDir $logsDir6
Add-LauncherRunLogEvent -RunLog $runLog6 -Stage 'Push' -Message 'about to push with DB_PASSWORD=ExtremelySecretValue999'
$writtenPath = Complete-LauncherRunLog -RunLog $runLog6 -Outcome 'Succeeded' -CommitSha ('a' * 40) -ErrorText 'API_TOKEN=AnotherSecretValue888 failed validation'
$writtenContent = Get-Content -LiteralPath $writtenPath -Raw
Assert-That 'the written log file never contains the raw secret value (password)' ($writtenContent -notmatch 'ExtremelySecretValue999')
Assert-That 'the written log file never contains the raw secret value (token)' ($writtenContent -notmatch 'AnotherSecretValue888')
Assert-That 'the written log file DOES contain the real 40-character commit SHA (not itself a secret)' ($writtenContent -match ('a' * 40))
Remove-TestTempDirectory -Path $logsDir6

# ============================================================
# 7. A project path containing spaces — proves the NEW launcher
#    functions (Get-AheadCommitCount, Test-GitConnectivity,
#    Invoke-GitPushOnly) are argv-safe, not just the pre-existing ones.
# ============================================================
$base7 = New-TestTempDirectory -Prefix 'sdtest-launcher-spaces'
$repo7 = Join-Path $base7 'repo with spaces'
$proj7 = New-CheckFixtureProject -RepoPath $repo7
'v2' | Set-Content -LiteralPath (Join-Path $repo7 'app.txt') -Encoding ASCII -NoNewline
& git.exe -C $repo7 add -- app.txt 2>&1 | Out-Null
& git.exe -C $repo7 commit -m 'second commit' --quiet 2>&1 | Out-Null
$check7 = Get-ProjectCheckResult -ProjectInfo $proj7
Assert-That 'ahead-count works correctly for a repository path containing spaces' ($check7.AheadCount -eq 1)
$conn7 = Test-GitConnectivity -RepositoryPath $repo7 -RemoteName 'origin' -TimeoutSeconds 10
Assert-That 'connectivity check works correctly for a repository path containing spaces' $conn7.Ok
$flow7 = Invoke-LauncherGitOnlyFlow -ProjectInfo $proj7 -Check $check7
Assert-That 'push-only (unpushed-commit) flow works correctly for a repository path containing spaces' ($flow7.Status -eq 'Succeeded')
Remove-TestTempDirectory -Path $base7

Write-TestSummaryAndExit -SuiteName 'Test-LauncherGitFlow'
