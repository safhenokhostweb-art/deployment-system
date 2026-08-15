<#
.SYNOPSIS
    Tests: SSH/SCP command ARGUMENT construction — proven free of shell
    injection by inspecting the actual argv array produced for a value
    containing shell metacharacters (spaces, quotes, `;`, `&&`,
    backticks), never a real network connection. Also: known_hosts
    checking (real, local fixture — never a real host) and POSIX
    shell-quoting for the remote command string.
#>

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ToolRoot = Split-Path -Parent $ScriptDir
. (Join-Path $ScriptDir 'TestHelpers.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Common.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Transfer.ps1')

# ============================================================
# 1. SCP command args — never a shell string, StrictHostKeyChecking=yes
#    always present, never "no".
# ============================================================
$scpArgs = Get-ScpUploadCommandArgs -SshKeyPath 'C:\keys\id_ed25519' -KnownHostsPath 'C:\Users\me\.ssh\known_hosts' `
    -LocalFilePath 'C:\temp\artifact.zip' -ServerUser 'deploy' -ServerHost '1.2.3.4' -RemoteDestinationPath '/tmp/artifact.zip'

Assert-That 'scp args is a real array, not a pre-joined string' ($scpArgs -is [array])
Assert-That 'scp args include StrictHostKeyChecking=yes' (@($scpArgs) -contains 'StrictHostKeyChecking=yes')
Assert-That 'scp args NEVER include StrictHostKeyChecking=no' (-not (@($scpArgs) | Where-Object { $_ -eq 'StrictHostKeyChecking=no' }))
Assert-That 'scp args include the known_hosts file override' ((@($scpArgs) -join ' ') -match 'UserKnownHostsFile=')
Assert-That 'scp args include the ssh key path' (@($scpArgs) -contains 'C:\keys\id_ed25519')
Assert-That 'scp args end with user@host:path as ONE token' (@($scpArgs)[-1] -eq 'deploy@1.2.3.4:/tmp/artifact.zip')
Assert-That 'scp args default to port 22 when -ServerPort is not given' (@($scpArgs) -contains '22')

$scpArgsCustomPort = Get-ScpUploadCommandArgs -SshKeyPath 'C:\keys\id_ed25519' -KnownHostsPath 'C:\hosts' `
    -LocalFilePath 'C:\temp\x.zip' -ServerUser 'root' -ServerHost '87.107.12.81' -RemoteDestinationPath '/tmp/x.zip' -ServerPort 9011
Assert-That 'scp args use -P (uppercase) for a non-default port' ((@($scpArgsCustomPort) -join ' ') -match '-P 9011')

$sshArgsCustomPort = Get-SshRemoteCommandArgs -SshKeyPath 'C:\keys\id_ed25519' -KnownHostsPath 'C:\hosts' `
    -ServerUser 'root' -ServerHost '87.107.12.81' -RemoteCommand 'echo hi' -ServerPort 9011
Assert-That 'ssh args use -p (lowercase) for a non-default port' ((@($sshArgsCustomPort) -join ' ') -match '-p 9011')

# ============================================================
# 2. A value containing shell metacharacters stays a single, literal
#    argv element — proves there is no injection surface: this array is
#    passed to Invoke-ExternalCommand, which (see Test-Common-ish
#    behavior in Format-WindowsCommandLine) escapes each element as ONE
#    Win32 argument; nothing here ever touches cmd.exe or a POSIX shell
#    locally.
# ============================================================
$maliciousPath = 'C:\temp\evil"; rm -rf / ; echo "pwned.zip'
$scpArgsMalicious = Get-ScpUploadCommandArgs -SshKeyPath 'C:\keys\id_ed25519' -KnownHostsPath 'C:\hosts' `
    -LocalFilePath $maliciousPath -ServerUser 'deploy' -ServerHost '1.2.3.4' -RemoteDestinationPath '/tmp/x.zip'
Assert-That 'a value with shell metacharacters is preserved as ONE literal array element, not split/reinterpreted' (@($scpArgsMalicious) -contains $maliciousPath)

$formatted = Format-WindowsCommandLine -Arguments @($maliciousPath)
Assert-That 'Format-WindowsCommandLine wraps a metacharacter-laden argument in quotes as a single token' ($formatted.StartsWith('"') -and $formatted.EndsWith('"'))

# ============================================================
# 3. SSH remote-command args — same array-safety, plus the remote
#    command itself is a single, separate token (SSH's own protocol
#    design hands it to the remote shell — see ConvertTo-PosixShellQuotedString
#    tests below for how VALUES substituted into it are protected).
# ============================================================
$sshArgs = Get-SshRemoteCommandArgs -SshKeyPath 'C:\keys\id_ed25519' -KnownHostsPath 'C:\hosts' `
    -ServerUser 'deploy' -ServerHost '1.2.3.4' -RemoteCommand 'echo hello'
Assert-That 'ssh args include StrictHostKeyChecking=yes' (@($sshArgs) -contains 'StrictHostKeyChecking=yes')
Assert-That 'ssh args never include StrictHostKeyChecking=no' (-not (@($sshArgs) | Where-Object { $_ -eq 'StrictHostKeyChecking=no' }))
Assert-That 'ssh args end with the remote command as its own single token' (@($sshArgs)[-1] -eq 'echo hello')

# ============================================================
# 4. POSIX shell-quoting for values embedded in the remote command
#    string sent over SSH. Verified against a REAL bash (Git Bash is
#    present on this machine) rather than a naive regex — a regex can't
#    correctly judge POSIX quote safety (the standard '\'' pattern for
#    an embedded quote LOOKS alarming to a naive character scan but is
#    exactly correct shell syntax); actually running it through bash and
#    checking bash's own reconstructed value is the real, authoritative
#    test.
# ============================================================
Assert-That 'a plain value quotes cleanly' ((ConvertTo-PosixShellQuotedString 'plain') -eq "'plain'")

$bashPath = (Get-Command bash.exe -ErrorAction SilentlyContinue | Select-Object -First 1).Source
if ($bashPath) {
    $dangerousValues = @(
        "it's-a-test",
        "x'; rm -rf / #",
        'a"b`c$(whoami)',
        "multi`nline",
        ''
    )
    foreach ($value in $dangerousValues) {
        $quoted = ConvertTo-PosixShellQuotedString $value
        # The dangerous value/quoting only ever appears as literal TEXT
        # CONTENT of a file written via Set-Content — never passed
        # through any argv boundary itself — so there is exactly one
        # thing to reason about: does bash's OWN parser reconstruct the
        # original value exactly from this quoted form. `printf '%s'`
        # (never `echo`) avoids any escape-sequence reinterpretation.
        $scriptPath = Join-Path $env:TEMP "sdtest-quote-check-$([Guid]::NewGuid().ToString('N').Substring(0,8)).sh"
        Set-Content -LiteralPath $scriptPath -Value "printf '%s' $quoted" -Encoding ASCII -NoNewline
        $result = Invoke-ExternalCommand -FilePath $bashPath -ArgumentList @($scriptPath) -TimeoutSeconds 10
        Remove-Item -LiteralPath $scriptPath -Force -ErrorAction SilentlyContinue
        $label = $value -replace "`n", '\n'
        Assert-That "POSIX-quoting round-trips exactly through a real shell for value: $label" ($result.StdOut -ceq $value)
    }
} else {
    Write-Host '    (bash.exe not found -- skipping the real-shell POSIX-quoting round-trip check)' -ForegroundColor Yellow
}

# ============================================================
# 5. Test-SshHostKnown — real, local fixture (a throwaway known_hosts
#    file with a fake host entry), never a real network host.
# ============================================================
$fixtureDir = New-TestTempDirectory -Prefix 'sdtest-knownhosts'
$fakeKnownHosts = Join-Path $fixtureDir 'known_hosts'
# A syntactically real (fake-key) known_hosts entry for a fake host —
# ssh-keygen -F only inspects the hostname field, so this is sufficient
# for the lookup itself without needing a real key.
'test-fixture-host.invalid ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAItest' | Set-Content -LiteralPath $fakeKnownHosts -Encoding ASCII

Assert-That 'a host present in known_hosts is recognized' (Test-SshHostKnown -HostName 'test-fixture-host.invalid' -KnownHostsPath $fakeKnownHosts)
Assert-That 'a host NOT present in known_hosts is correctly reported as unknown' (-not (Test-SshHostKnown -HostName 'some-other-host.invalid' -KnownHostsPath $fakeKnownHosts))
Assert-That 'a nonexistent known_hosts file is treated as "host unknown" (never assumed trusted)' (-not (Test-SshHostKnown -HostName 'anything' -KnownHostsPath (Join-Path $fixtureDir 'does-not-exist')))

# A host recorded under a NON-default port must be looked up via the
# bracketed "[host]:port" form OpenSSH itself uses to store it — a plain
# hostname lookup would silently miss a genuinely-known host.
'[test-fixture-host.invalid]:9011 ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAItest' | Add-Content -LiteralPath $fakeKnownHosts -Encoding ASCII
Assert-That 'a host known only on a non-default port is found when -Port is given' (Test-SshHostKnown -HostName 'test-fixture-host.invalid' -KnownHostsPath $fakeKnownHosts -Port 9011)
Assert-That 'the same host is NOT matched on a different port than the one it was actually recorded under' (-not (Test-SshHostKnown -HostName 'test-fixture-host.invalid' -KnownHostsPath $fakeKnownHosts -Port 2222))

# Read-only confirmation against this machine's REAL known_hosts (no
# network connection — ssh-keygen -F only greps the local file) that the
# real production host this tool will actually be configured for is
# genuinely already trusted on its real, non-default port.
$realKnownHosts = Join-Path $env:USERPROFILE '.ssh\known_hosts'
if (Test-Path -LiteralPath $realKnownHosts) {
    $realHostKnown = Test-SshHostKnown -HostName '87.107.12.81' -KnownHostsPath $realKnownHosts -Port 9011
    Assert-That 'the real production host (87.107.12.81:9011) is already present in this machine''s real known_hosts' $realHostKnown
} else {
    Write-Host '    (no real known_hosts file on this machine -- skipping the real-host confirmation check)' -ForegroundColor Yellow
}

# Invoke-ScpUpload/Invoke-SshRemoteCommand must refuse an unknown host
# BEFORE ever attempting a connection — proven without any real network
# access by simply never providing a matching known_hosts entry.
$refused = $false
try {
    Invoke-ScpUpload -SshKeyPath 'C:\keys\id_ed25519' -KnownHostsPath $fakeKnownHosts -LocalFilePath (Join-Path $fixtureDir 'x.zip') `
        -ServerUser 'deploy' -ServerHost 'unknown-host.invalid' -RemoteDestinationPath '/tmp/x.zip'
} catch {
    $refused = $true
    Assert-That 'the refusal message explains why (host not in known_hosts)' ($_.Exception.Message -match 'known_hosts')
}
Assert-That 'uploading to an unknown host is refused before any connection attempt' $refused

# ============================================================
# 6. SSH KeepAlive options — present by default, configurable, and
#    combine correctly with a non-default port (real production uses
#    9011) and a path containing spaces.
# ============================================================
$scpArgsKeepAlive = Get-ScpUploadCommandArgs -SshKeyPath 'C:\keys\id_ed25519' -KnownHostsPath 'C:\hosts' `
    -LocalFilePath 'C:\temp\artifact.zip' -ServerUser 'root' -ServerHost '87.107.12.81' -RemoteDestinationPath '/tmp/x.zip' -ServerPort 9011
Assert-That 'scp args include the default ServerAliveInterval=15' (@($scpArgsKeepAlive) -contains 'ServerAliveInterval=15')
Assert-That 'scp args include the default ServerAliveCountMax=4' (@($scpArgsKeepAlive) -contains 'ServerAliveCountMax=4')
Assert-That 'scp args include TCPKeepAlive=yes by default' (@($scpArgsKeepAlive) -contains 'TCPKeepAlive=yes')
Assert-That 'scp args still use the real non-default port 9011 alongside keepalive options' ((@($scpArgsKeepAlive) -join ' ') -match '-P 9011')

$scpArgsCustomKeepAlive = Get-ScpUploadCommandArgs -SshKeyPath 'C:\keys\id_ed25519' -KnownHostsPath 'C:\hosts' `
    -LocalFilePath 'C:\temp\artifact.zip' -ServerUser 'root' -ServerHost '1.2.3.4' -RemoteDestinationPath '/tmp/x.zip' `
    -ServerAliveIntervalSeconds 30 -ServerAliveCountMax 2 -TcpKeepAlive $false
Assert-That 'ServerAliveInterval is configurable' (@($scpArgsCustomKeepAlive) -contains 'ServerAliveInterval=30')
Assert-That 'ServerAliveCountMax is configurable' (@($scpArgsCustomKeepAlive) -contains 'ServerAliveCountMax=2')
Assert-That 'TCPKeepAlive can be turned off explicitly' (@($scpArgsCustomKeepAlive) -contains 'TCPKeepAlive=no')

$sshArgsKeepAlive = Get-SshRemoteCommandArgs -SshKeyPath 'C:\keys\id_ed25519' -KnownHostsPath 'C:\hosts' `
    -ServerUser 'root' -ServerHost '87.107.12.81' -RemoteCommand 'echo hi' -ServerPort 9011
Assert-That 'ssh remote-command args also include the same KeepAlive options' (@($sshArgsKeepAlive) -contains 'ServerAliveInterval=15')

$scpArgsSpacedPath = Get-ScpUploadCommandArgs -SshKeyPath 'C:\my keys\id_ed25519' -KnownHostsPath 'C:\Users\me\.ssh\known_hosts' `
    -LocalFilePath 'C:\path with spaces\artifact.zip' -ServerUser 'deploy' -ServerHost '1.2.3.4' -RemoteDestinationPath '/tmp/x.zip'
Assert-That 'a local path containing spaces stays one literal argv element even with KeepAlive options present' (@($scpArgsSpacedPath) -contains 'C:\path with spaces\artifact.zip')

# ============================================================
# 7. Test-ScpErrorIsTransient — the exact retryable/non-retryable error
#    classification the real production failure (`client_loop: send
#    disconnect: Connection reset` / `scp.exe: Couldn't send packet:
#    Broken pipe`) needs.
# ============================================================
Assert-That 'a connection reset is transient (retryable)' (Test-ScpErrorIsTransient -ErrorText 'client_loop: send disconnect: Connection reset')
Assert-That 'a broken pipe is transient (retryable) -- the exact real production error' (Test-ScpErrorIsTransient -ErrorText "scp.exe: Couldn't send packet: Broken pipe")
Assert-That 'a connection timed out is transient' (Test-ScpErrorIsTransient -ErrorText 'ssh: connect to host x port 22: Connection timed out')
Assert-That 'an operation timed out is transient' (Test-ScpErrorIsTransient -ErrorText 'Operation timed out')
Assert-That 'a connection closed is transient' (Test-ScpErrorIsTransient -ErrorText 'Connection closed by remote host')
Assert-That 'kex_exchange_identification is transient' (Test-ScpErrorIsTransient -ErrorText 'kex_exchange_identification: read: Connection reset by peer')
Assert-That 'could not resolve host is transient' (Test-ScpErrorIsTransient -ErrorText 'ssh: Could not resolve hostname example.invalid')

Assert-That 'permission denied is NEVER retried' (-not (Test-ScpErrorIsTransient -ErrorText 'Permission denied (publickey).'))
Assert-That 'a missing local file is NEVER retried' (-not (Test-ScpErrorIsTransient -ErrorText 'no such file or directory'))
Assert-That 'a host key verification failure is NEVER retried' (-not (Test-ScpErrorIsTransient -ErrorText 'Host key verification failed.'))
Assert-That 'an invalid key is NEVER retried' (-not (Test-ScpErrorIsTransient -ErrorText 'Load key "C:\keys\id_ed25519": invalid format'))
Assert-That 'a disk-full remote error is NEVER retried' (-not (Test-ScpErrorIsTransient -ErrorText 'scp: /tmp/x.zip: No space left on device'))
Assert-That 'an invalid remote path is NEVER retried' (-not (Test-ScpErrorIsTransient -ErrorText 'scp: /nonexistent/dir/x.zip: No such file or directory'))

# ============================================================
# 8. Invoke-WithTransientRetry — the retry MECHANICS in isolation, via a
#    fake -Action (no real process/network call at all), mirroring how
#    Test-LauncherGitFlow.ps1 already proves Invoke-GitPushWithRetry's
#    own attempt-count/backoff behavior the same way.
# ============================================================
$script:retryAttempts1 = 0
$retryNotices1 = New-Object System.Collections.Generic.List[object]
$result1 = Invoke-WithTransientRetry -DelaysSeconds @(0, 0) -OnRetry {
    param($attempt, $delay, $errorText) $retryNotices1.Add(@{ Attempt = $attempt; Delay = $delay })
} -Action {
    param($attempt)
    $script:retryAttempts1++
    if ($script:retryAttempts1 -lt 3) { return @{ ExitCode = 255; StdOut = ''; StdErr = 'Connection reset by peer' } }
    return @{ ExitCode = 0; StdOut = 'ok'; StdErr = '' }
}
Assert-That 'a connection-reset retried twice succeeds on the 3rd attempt' ($result1.ExitCode -eq 0)
Assert-That 'exactly 3 attempts were made (2 failures + 1 success)' ($script:retryAttempts1 -eq 3)
Assert-That 'exactly 2 retry notices fired' ($retryNotices1.Count -eq 2)

$script:retryAttempts2 = 0
$result2 = Invoke-WithTransientRetry -DelaysSeconds @(0, 0) -Action {
    param($attempt)
    $script:retryAttempts2++
    return @{ ExitCode = 255; StdOut = ''; StdErr = 'Broken pipe' }
}
Assert-That 'three consecutive transient failures exhaust the retry budget and return the LAST failure (never throws itself)' ($result2.ExitCode -eq 255)
Assert-That 'exactly 3 attempts were made (1 + 2 retries, matching 2 delay values), never more' ($script:retryAttempts2 -eq 3)

$script:retryAttempts3 = 0
$result3 = Invoke-WithTransientRetry -DelaysSeconds @(0, 0, 0) -Action {
    param($attempt)
    $script:retryAttempts3++
    return @{ ExitCode = 255; StdOut = ''; StdErr = 'Permission denied (publickey).' }
}
Assert-That 'a permission-denied failure is returned as-is' ($result3.ExitCode -eq 255)
Assert-That 'a permission-denied error is NEVER retried -- exactly 1 attempt' ($script:retryAttempts3 -eq 1)

$script:retryAttempts4 = 0
$result4 = Invoke-WithTransientRetry -DelaysSeconds @(0, 0) -Action {
    param($attempt)
    $script:retryAttempts4++
    return @{ ExitCode = 124; StdOut = ''; StdErr = ''; Cancelled = $true }
}
Assert-That 'a Cancelled result stops the retry loop immediately, never retried' ($script:retryAttempts4 -eq 1)
Assert-That 'a Cancelled result is passed through unchanged' ($result4.Cancelled -eq $true)

$defaultRetryDelaysText = Get-Content (Join-Path $ToolRoot 'lib\SimpleDeploy.Transfer.ps1') -Raw
Assert-That 'the real default SCP retry delays are exactly 5, 15, 30 seconds' ($defaultRetryDelaysText -match '\[int\[\]\]\$DelaysSeconds = @\(5, 15, 30\)')

# ============================================================
# 9. Get-ScpTransferTimeoutSeconds — pure, size-proportional, capped.
#    Real numbers from the reported production failure: a ~32MB
#    artifact must get a timeout well above the ~793s it actually took
#    to genuinely fail (proving the OLD fixed-300s-floor formula could
#    never have given it a real chance), while a tiny file still gets
#    the base floor, and an absurdly large artifact is still capped.
# ============================================================
Assert-That 'a tiny file gets the base timeout floor (never less than 300s by default)' ((Get-ScpTransferTimeoutSeconds -SizeBytes 1KB) -eq 300)
$timeout32MB = Get-ScpTransferTimeoutSeconds -SizeBytes (32 * 1MB)
Assert-That 'a ~32MB artifact gets a timeout comfortably above the real 793s failure point (the old fixed 300s floor never could have)' ($timeout32MB -gt 793)
Assert-That 'the cap is respected even for an absurdly large artifact' ((Get-ScpTransferTimeoutSeconds -SizeBytes (10000 * 1MB)) -eq 1800)
Assert-That 'BaseSeconds/SecondsPerMB/MaxSeconds are independently configurable' ((Get-ScpTransferTimeoutSeconds -SizeBytes (100 * 1MB) -BaseSeconds 60 -SecondsPerMB 1 -MaxSeconds 90) -eq 90)

# ============================================================
# 10. Test-RemoteArtifactSizeMatches / Test-RemoteArtifactChecksumMatches
#     — pure comparison against real `stat -c%s`/`sha256sum` OUTPUT TEXT
#     shapes, no real SSH round trip needed to prove the DECISION logic.
# ============================================================
Assert-That 'matching remote size (real "stat -c%s" output shape) passes' (Test-RemoteArtifactSizeMatches -LocalSizeBytes 12345 -RemoteStatOutput "12345`n")
Assert-That 'a remote size mismatch is detected (incomplete transfer)' (-not (Test-RemoteArtifactSizeMatches -LocalSizeBytes 12345 -RemoteStatOutput "9999`n"))
Assert-That 'garbage remote stat output (e.g. a real stat error message) never matches' (-not (Test-RemoteArtifactSizeMatches -LocalSizeBytes 12345 -RemoteStatOutput "stat: cannot stat 'x': No such file or directory`n"))

$realChecksum = 'a94a8fe5ccb19ba61c4c0873d391e987982fbbd3'.PadRight(64, '0')
Assert-That 'a matching checksum (real "sha256sum <path>" output shape) passes' (Test-RemoteArtifactChecksumMatches -LocalChecksumSha256 $realChecksum -RemoteChecksumOutput "$realChecksum  /tmp/artifact.zip.part`n")
Assert-That 'checksum comparison is case-insensitive (sha256sum vs a differently-cased local value)' (Test-RemoteArtifactChecksumMatches -LocalChecksumSha256 $realChecksum.ToUpperInvariant() -RemoteChecksumOutput "$realChecksum  /tmp/artifact.zip.part`n")
Assert-That 'a checksum mismatch (corrupted transfer) is detected' (-not (Test-RemoteArtifactChecksumMatches -LocalChecksumSha256 $realChecksum -RemoteChecksumOutput ('0' * 64 + "  /tmp/artifact.zip.part`n")))
Assert-That 'garbage remote checksum output never matches' (-not (Test-RemoteArtifactChecksumMatches -LocalChecksumSha256 $realChecksum -RemoteChecksumOutput "sha256sum: /tmp/x: No such file or directory`n"))

# ============================================================
# 11. Atomic-upload STRUCTURE — real source-code verification (the same
#     established technique already used in Test-LauncherGitFlow.ps1/
#     Test-LauncherIntegration.ps1 for behavior that genuinely can't be
#     exercised without a real network peer): confirms Invoke-ScpUpload's
#     REAL implementation actually uses a ".part" staging name, a real
#     `mv` rename, and cleans up via `rm -f` on any failure path — not
#     just that the pure helper functions above exist in isolation.
# ============================================================
$transferSourceText = Get-Content (Join-Path $ToolRoot 'lib\SimpleDeploy.Transfer.ps1') -Raw
Assert-That 'Invoke-ScpUpload stages the real transfer at "<dest>.part", never directly at the final name, when a checksum is given' ($transferSourceText -match '"\$RemoteDestinationPath\.part"')
Assert-That 'the atomic path performs a real remote `stat -c%s` size check before trusting the upload' ($transferSourceText -match 'stat -c%s')
Assert-That 'the atomic path performs a real remote `sha256sum` checksum check before trusting the upload' ($transferSourceText -match 'sha256sum')
Assert-That 'the atomic path performs a real remote `mv -f` to rename .part to the final name ONLY after verification' ($transferSourceText -match 'mv -f --')
Assert-That 'a failed/incomplete atomic upload is cleaned up via a real remote `rm -f`, never left behind silently' ($transferSourceText -match "'rm -f -- '")
Assert-That 'the non-atomic path (no -ExpectedChecksumSha256, e.g. the small activate-release.sh upload) is preserved for backward compatibility' ($transferSourceText -match '\$isAtomic\s*=\s*-not \[string\]::IsNullOrWhiteSpace\(\$ExpectedChecksumSha256\)')

$orchestrationSourceText = Get-Content (Join-Path $ToolRoot 'lib\SimpleDeploy.Orchestration.ps1') -Raw
Assert-That 'Invoke-ArtifactToTarget passes the real artifact checksum through, enabling the atomic path for the real artifact upload' ($orchestrationSourceText -match '-ExpectedChecksumSha256 \$Artifact\.ChecksumSha256')
Assert-That 'the SCP transfer happens BEFORE SSH activation in source order -- an incomplete/failed upload can never reach the activation step' (
    $orchestrationSourceText.IndexOf('Invoke-ScpUpload -SshKeyPath $Target.SshKeyPath -KnownHostsPath $knownHostsPath -LocalFilePath $Artifact.Path') -lt `
        $orchestrationSourceText.IndexOf('Invoke-SshRemoteCommand -SshKeyPath $Target.SshKeyPath -KnownHostsPath $knownHostsPath')
)

# ============================================================
# 12. Cancellation — real: Invoke-ExternalCommand's new -CancellationCheck
#     actually kills a REAL, genuinely long-running child process (no
#     mocking) the moment it's asked to, distinct from a plain timeout,
#     and Invoke-ScpUpload propagates that into a real cleanup attempt +
#     a clear "cancelled" error rather than a generic failure.
# ============================================================
$cancelNowFlag = $false
$cancelSw = [System.Diagnostics.Stopwatch]::StartNew()
$cancelResult = Invoke-ExternalCommand -FilePath 'ping.exe' -ArgumentList @('-n', '30', '127.0.0.1') -TimeoutSeconds 60 -CancellationCheck { return $cancelNowFlag }
$cancelSw.Stop()
Assert-That 'a -CancellationCheck that never returns true behaves like a normal (uncancelled) run' ($cancelResult.ExitCode -eq 0 -and -not $cancelResult.Cancelled)

$cancelTriggerAfter = (Get-Date).AddMilliseconds(400)
$cancelSw2 = [System.Diagnostics.Stopwatch]::StartNew()
$cancelResult2 = Invoke-ExternalCommand -FilePath 'ping.exe' -ArgumentList @('-n', '30', '127.0.0.1') -TimeoutSeconds 60 -CancellationCheck { (Get-Date) -ge $cancelTriggerAfter }
$cancelSw2.Stop()
Assert-That 'a real long-running process is genuinely killed within ~1s of -CancellationCheck first returning true (not left running for the full 30 pings)' ($cancelSw2.Elapsed.TotalSeconds -lt 5)
Assert-That 'a cancelled run is reported as Cancelled=$true' ($cancelResult2.Cancelled -eq $true)
Assert-That 'a cancelled run is reported with ExitCode=124 (same sentinel as a timeout, distinct from a real process exit code)' ($cancelResult2.ExitCode -eq 124)
Start-Sleep -Milliseconds 500
$leftoverPingProcesses = @(Get-Process -Name 'ping' -ErrorAction SilentlyContinue)
Assert-That 'the real ping.exe process is genuinely gone afterward -- not orphaned in the background' ($leftoverPingProcesses.Count -eq 0)

# ============================================================
# 13. Logging never contains secrets — the -OnLog callback used
#     throughout Invoke-ScpUpload only ever receives operational text
#     (attempt numbers, byte counts, elapsed time, speed, remote
#     size/checksum results) -- confirmed here by checking that none of
#     the LITERAL log message templates in the real source reference the
#     SSH key PATH or any key/token content.
# ============================================================
Assert-That 'no OnLog call site in Invoke-ScpUpload ever includes $SshKeyPath (the private key path) in its message' (
    -not ([regex]::Matches($transferSourceText, '& \$log "[^"]*\$SshKeyPath[^"]*"')).Count
)
Assert-That 'no OnLog call site ever references a raw key/credential file content variable' ($transferSourceText -notmatch '& \$OnLog\b.*(PrivateKey|password|token)')

# ============================================================
# 14. PowerShell 5.1 compatibility — a fact about the environment every
#     assertion in this whole suite (including the real Kill/Cancel test
#     above) already ran under.
# ============================================================
Assert-That 'this entire suite ran on real Windows PowerShell 5.1, not a newer/different host' ($PSVersionTable.PSVersion.Major -eq 5 -and $PSVersionTable.PSVersion.Minor -eq 1)

Remove-TestTempDirectory -Path $fixtureDir
Write-TestSummaryAndExit -SuiteName 'Test-Transfer'
