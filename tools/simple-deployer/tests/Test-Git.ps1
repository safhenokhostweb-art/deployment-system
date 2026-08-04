<#
.SYNOPSIS
    Tests: git status/sensitive-file audit/commit/push against REAL git
    repositories (no mocking) — clean project (no changes), blocked
    secret files, wrong branch (via Preflight), commit/push failure, and
    a genuine 40-character SHA.
#>

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ToolRoot = Split-Path -Parent $ScriptDir
. (Join-Path $ScriptDir 'TestHelpers.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Common.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Git.ps1')

# ============================================================
# 1. A project with no changes at all -> Get-GitStatusShort returns empty.
# ============================================================
$repo1 = Join-Path (New-TestTempDirectory -Prefix 'sdtest-git-clean') 'repo'
Initialize-TestGitRepo -Path $repo1
'hello' | Set-Content -LiteralPath (Join-Path $repo1 'file.txt') -Encoding UTF8
& git.exe -C $repo1 add -- file.txt 2>&1 | Out-Null
& git.exe -C $repo1 commit -m 'initial' --quiet 2>&1 | Out-Null

$status1 = Get-GitStatusShort -RepositoryPath $repo1
Assert-That 'a clean repo (no changes) reports zero status lines' (@($status1).Count -eq 0)
Remove-TestTempDirectory -Path (Split-Path -Parent $repo1)

# ============================================================
# 2. Blocked / sensitive files are detected and never staged.
# ============================================================
$repo2 = Join-Path (New-TestTempDirectory -Prefix 'sdtest-git-sensitive') 'repo'
Initialize-TestGitRepo -Path $repo2
'SAFE=1' | Set-Content -LiteralPath (Join-Path $repo2 'ordinary.txt') -Encoding UTF8
'DB_PASSWORD=super-secret' | Set-Content -LiteralPath (Join-Path $repo2 '.env') -Encoding UTF8
'-----BEGIN PRIVATE KEY-----' | Set-Content -LiteralPath (Join-Path $repo2 'id_rsa') -Encoding UTF8
$status2 = Get-GitStatusShort -RepositoryPath $repo2
$sensitive2 = Test-SensitiveFiles -RepositoryPath $repo2 -StatusLines $status2
Assert-That 'a .env file is detected as blocked' (@($sensitive2.BlockedFiles) -contains '.env')
Assert-That 'an id_rsa file is detected as blocked' (@($sensitive2.BlockedFiles) -contains 'id_rsa')
Assert-That 'an ordinary file is NOT blocked' (-not (@($sensitive2.BlockedFiles) -contains 'ordinary.txt'))
Remove-TestTempDirectory -Path (Split-Path -Parent $repo2)

# ============================================================
# 3. A large file is flagged (warning-level, not a hard block).
# ============================================================
$repo3 = Join-Path (New-TestTempDirectory -Prefix 'sdtest-git-large') 'repo'
Initialize-TestGitRepo -Path $repo3
$largeFilePath = Join-Path $repo3 'big.bin'
$fs = [System.IO.File]::Create($largeFilePath)
$fs.SetLength(21MB)
$fs.Close()
$status3 = Get-GitStatusShort -RepositoryPath $repo3
$sensitive3 = Test-SensitiveFiles -RepositoryPath $repo3 -StatusLines $status3
Assert-That 'a 21MB file is flagged as large' (@($sensitive3.LargeFiles).Count -eq 1)
Assert-That 'a large file is NOT in the blocked list (warning only)' (@($sensitive3.BlockedFiles).Count -eq 0)
Remove-TestTempDirectory -Path (Split-Path -Parent $repo3)

# ============================================================
# 4. A genuine commit produces a real, 40-character SHA; push to a
#    nonexistent remote fails cleanly (never partially "succeeds").
# ============================================================
$repo4 = Join-Path (New-TestTempDirectory -Prefix 'sdtest-git-commit') 'repo'
Initialize-TestGitRepo -Path $repo4
'content' | Set-Content -LiteralPath (Join-Path $repo4 'app.txt') -Encoding UTF8
& git.exe -C $repo4 remote add origin 'https://example.invalid/nonexistent-repo.git' 2>&1 | Out-Null

$pushThrew = $false
try {
    Invoke-GitCommitAndPush -RepositoryPath $repo4 -PathsToStage @('app.txt') -CommitMessage 'test commit' -RemoteName 'origin' -Branch 'main' | Out-Null
} catch {
    $pushThrew = $true
    Assert-That 'a failed push error mentions the commit is preserved locally' ($_.Exception.Message -match 'حفظ')
}
Assert-That 'pushing to an unreachable remote throws (deploy must not proceed)' $pushThrew

$shaAfterFailedPush = Get-LastCommitSha -RepositoryPath $repo4
Assert-That 'the local commit itself DID succeed (git commit is not undone after a push failure)' ($shaAfterFailedPush.Length -eq 40)
Assert-That 'the SHA is exactly 40 hex characters' ($shaAfterFailedPush -match '^[0-9a-f]{40}$')

$statusAfterFailedPush = Get-GitStatusShort -RepositoryPath $repo4
Assert-That 'nothing was reset/cleaned after the failed push — the tree is clean because the commit succeeded, not because changes were discarded' (@($statusAfterFailedPush).Count -eq 0)
Remove-TestTempDirectory -Path (Split-Path -Parent $repo4)

# ============================================================
# 5. Rename handling in git status parsing (both old and new paths returned).
# ============================================================
$parsed = ConvertFrom-GitStatusLine -Line 'R  old-name.txt -> new-name.txt'
Assert-That 'a rename status line yields both the old and new path' (@($parsed).Count -eq 2 -and $parsed[0] -eq 'old-name.txt' -and $parsed[1] -eq 'new-name.txt')

Write-TestSummaryAndExit -SuiteName 'Test-Git'
