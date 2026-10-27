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

# The first bash.exe on PATH can be the WSL launcher (System32\bash.exe),
# which cannot open a Windows script path at all and exits 127 -- that
# says nothing about the quoting. Use the first bash that can actually
# run a script from a Windows path (e.g. Git Bash, which is usually not on
# PATH itself but ships next to git.exe as <git root>\bin\bash.exe).
$bashCandidates = @(Get-Command bash.exe -All -ErrorAction SilentlyContinue | ForEach-Object { $_.Source })
$gitExe = (Get-Command git.exe -ErrorAction SilentlyContinue | Select-Object -First 1).Source
if ($gitExe) {
    $gitBash = Join-Path (Split-Path (Split-Path $gitExe -Parent) -Parent) 'bin\bash.exe'
    if (Test-Path -LiteralPath $gitBash) { $bashCandidates = @($gitBash) + $bashCandidates }
}
$bashPath = $null
foreach ($candidate in $bashCandidates) {
    $probePath = Join-Path $env:TEMP "sdtest-bash-probe-$([Guid]::NewGuid().ToString('N').Substring(0,8)).sh"
    Set-Content -LiteralPath $probePath -Value "printf '%s' ok" -Encoding ASCII -NoNewline
    $probe = Invoke-ExternalCommand -FilePath $candidate -ArgumentList @($probePath) -TimeoutSeconds 10
    Remove-Item -LiteralPath $probePath -Force -ErrorAction SilentlyContinue
    if ($probe.StdOut -ceq 'ok') { $bashPath = $candidate; break }
}
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

Remove-TestTempDirectory -Path $fixtureDir
Write-TestSummaryAndExit -SuiteName 'Test-Transfer'
