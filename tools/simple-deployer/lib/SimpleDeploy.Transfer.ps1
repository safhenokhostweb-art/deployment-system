<#
.SYNOPSIS
    SSH/SCP command construction and invocation. Command ARGUMENT ARRAYS
    are built by dedicated Get-*CommandArgs functions, kept separate from
    the functions that actually invoke them — this lets tests verify the
    exact argv contents (no shell string ever built, no injection
    surface) without opening a real network connection.

.NOTES
    StrictHostKeyChecking is always explicitly "yes" — this file contains
    no code path that can set it to "no", and known_hosts verification is
    never bypassed. A host that isn't already in known_hosts is refused,
    not silently trusted (see Test-SshHostKnown) — trust-on-first-use
    auto-acceptance is exactly the MITM exposure "StrictHostKeyChecking=no
    ممنوع" rules out.
#>

Set-StrictMode -Version Latest

<#
.SYNOPSIS
    Standard POSIX single-quote escaping: wraps $Value in single quotes,
    replacing any literal `'` inside it with `'\''` — the standard,
    correct way to pass an arbitrary string through as ONE argument to a
    remote bash command, used for every value substituted into the
    activate-release.sh invocation string sent over SSH. This is
    necessary in addition to (not instead of) the local argv-array safety
    Invoke-ExternalCommand already provides — SSH's own remote-command
    argument is, by the protocol's own design, interpreted by the
    REMOTE shell, so it needs its own, independent quoting discipline.
#>
function ConvertTo-PosixShellQuotedString {
    param([Parameter(Mandatory = $true)][AllowEmptyString()][string]$Value)
    return "'" + $Value.Replace("'", "'\''") + "'"
}

function Get-KnownHostsPath {
    param([Parameter(Mandatory = $false)][string]$ConfiguredPath)
    if ($ConfiguredPath) {
        return [System.Environment]::ExpandEnvironmentVariables($ConfiguredPath.Replace('%USERPROFILE%', $env:USERPROFILE))
    }
    return Join-Path $env:USERPROFILE '.ssh\known_hosts'
}


# A single Deploy calls Invoke-ScpUpload twice (artifact + activate
# script) and Invoke-SshRemoteCommand once, each independently running
# Test-SshHostKnown -> its own ssh-keygen.exe process for the exact same
# (KnownHostsPath, HostName, Port) question. This tool never writes to
# known_hosts itself (see the file-level .NOTES), so the answer cannot
# change between these calls within one run — memoizing removes two
# redundant ssh-keygen.exe spawns per Deploy with no behavior change.
$script:SshHostKnownCache = @{}

<#
.SYNOPSIS
    Read-only check — never connects, never modifies known_hosts. Uses
    ssh-keygen's own lookup (-F), the standard, safe way to answer "is
    this host already trusted" without a network round trip.
#>
function Test-SshHostKnown {
    param(
        [Parameter(Mandatory = $true)][string]$HostName,
        [Parameter(Mandatory = $true)][string]$KnownHostsPath,
        [Parameter(Mandatory = $false)][int]$Port = 22
    )

    $cacheKey = "$KnownHostsPath|$HostName|$Port"
    if ($script:SshHostKnownCache.ContainsKey($cacheKey)) {
        return $script:SshHostKnownCache[$cacheKey]
    }

    if (-not (Test-Path -LiteralPath $KnownHostsPath)) {
        $script:SshHostKnownCache[$cacheKey] = $false
        return $false
    }

    # OpenSSH records/looks up a non-default-port host as "[host]:port" in
    # known_hosts (e.g. "[87.107.12.81]:9011"), not the bare hostname —
    # ssh-keygen -F must be given that same bracketed form or the lookup
    # never matches even though the real entry is genuinely present.
    $lookupTarget = if ($Port -ne 22) { "[$HostName]:$Port" } else { $HostName }
    $result = Invoke-ExternalCommand -FilePath 'ssh-keygen.exe' -ArgumentList @('-F', $lookupTarget, '-f', $KnownHostsPath) -TimeoutSeconds 10
    $known = ($result.ExitCode -eq 0)
    $script:SshHostKnownCache[$cacheKey] = $known
    return $known
}

<#
.SYNOPSIS
    Shared SSH `-o` options every invocation (SCP upload and SSH remote
    command alike) passes: StrictHostKeyChecking/known_hosts/BatchMode
    (unchanged, pre-existing) plus, new here, ServerAliveInterval/
    ServerAliveCountMax/TCPKeepAlive — real safety nets for the actual,
    concrete failure reported against a real VPS (`client_loop: send
    disconnect: Connection reset` / `scp.exe: Couldn't send packet:
    Broken pipe`, ~793s into a transfer): ConnectTimeout only bounds the
    INITIAL handshake, never a long-running transfer/session afterward —
    with no keepalive configured at all, an idle or slow-but-active
    connection has zero resilience against a NAT/firewall/middlebox
    silently dropping it partway through. ServerAliveCountMax=4 at the
    default 15s interval means a genuinely dead connection is detected
    and reported within ~60s, instead of the client waiting on a socket
    that may never come back. All three are configurable (with these
    same values as the safe defaults) so a real server's own sshd
    ClientAliveInterval policy can be matched if needed, without a code
    change.
#>
function Get-SshKeepAliveOptionArgs {
    param(
        [Parameter(Mandatory = $false)][int]$ServerAliveIntervalSeconds = 15,
        [Parameter(Mandatory = $false)][int]$ServerAliveCountMax = 4,
        [Parameter(Mandatory = $false)][bool]$TcpKeepAlive = $true
    )
    return @(
        '-o', "ServerAliveInterval=$ServerAliveIntervalSeconds",
        '-o', "ServerAliveCountMax=$ServerAliveCountMax",
        '-o', "TCPKeepAlive=$(if ($TcpKeepAlive) { 'yes' } else { 'no' })"
    )
}

function Get-ScpUploadCommandArgs {
    param(
        [Parameter(Mandatory = $true)][string]$SshKeyPath,
        [Parameter(Mandatory = $true)][string]$KnownHostsPath,
        [Parameter(Mandatory = $true)][string]$LocalFilePath,
        [Parameter(Mandatory = $true)][string]$ServerUser,
        [Parameter(Mandatory = $true)][string]$ServerHost,
        [Parameter(Mandatory = $true)][string]$RemoteDestinationPath,
        [Parameter(Mandatory = $false)][int]$ServerPort = 22,
        [Parameter(Mandatory = $false)][int]$ConnectTimeoutSeconds = 10,
        [Parameter(Mandatory = $false)][int]$ServerAliveIntervalSeconds = 15,
        [Parameter(Mandatory = $false)][int]$ServerAliveCountMax = 4,
        [Parameter(Mandatory = $false)][bool]$TcpKeepAlive = $true
    )

    return @(
        '-i', $SshKeyPath,
        '-P', [string]$ServerPort,
        '-o', 'StrictHostKeyChecking=yes',
        '-o', "UserKnownHostsFile=$KnownHostsPath",
        '-o', "ConnectTimeout=$ConnectTimeoutSeconds",
        '-o', 'BatchMode=yes'
    ) + (Get-SshKeepAliveOptionArgs -ServerAliveIntervalSeconds $ServerAliveIntervalSeconds -ServerAliveCountMax $ServerAliveCountMax -TcpKeepAlive $TcpKeepAlive) + @(
        $LocalFilePath,
        "${ServerUser}@${ServerHost}:${RemoteDestinationPath}"
    )
}

function Get-SshRemoteCommandArgs {
    param(
        [Parameter(Mandatory = $true)][string]$SshKeyPath,
        [Parameter(Mandatory = $true)][string]$KnownHostsPath,
        [Parameter(Mandatory = $true)][string]$ServerUser,
        [Parameter(Mandatory = $true)][string]$ServerHost,
        [Parameter(Mandatory = $true)][string]$RemoteCommand,
        [Parameter(Mandatory = $false)][int]$ServerPort = 22,
        [Parameter(Mandatory = $false)][int]$ConnectTimeoutSeconds = 10,
        [Parameter(Mandatory = $false)][int]$ServerAliveIntervalSeconds = 15,
        [Parameter(Mandatory = $false)][int]$ServerAliveCountMax = 4,
        [Parameter(Mandatory = $false)][bool]$TcpKeepAlive = $true
    )

    return @(
        '-i', $SshKeyPath,
        '-p', [string]$ServerPort,
        '-o', 'StrictHostKeyChecking=yes',
        '-o', "UserKnownHostsFile=$KnownHostsPath",
        '-o', "ConnectTimeout=$ConnectTimeoutSeconds",
        '-o', 'BatchMode=yes'
    ) + (Get-SshKeepAliveOptionArgs -ServerAliveIntervalSeconds $ServerAliveIntervalSeconds -ServerAliveCountMax $ServerAliveCountMax -TcpKeepAlive $TcpKeepAlive) + @(
        "${ServerUser}@${ServerHost}",
        $RemoteCommand
    )
}

<#
.SYNOPSIS
    Recognizes exactly the transient, connection-level class of SCP/SSH
    failure — the same real-world error class already proven for git
    push (SimpleDeployLauncher.GitFlow.ps1's Get-GitErrorCategory /
    SimpleDeploy.Git.ps1's Test-GitPushErrorIsTransient), reused here by
    name/spirit but as its own function since the exact phrase list a
    real transfer failure can contain differs from a git push failure.
    Deliberately narrow: permission/host-key/missing-path/disk-full
    errors are REAL rejections, never retried, so a broken deploy never
    silently "succeeds" on a later attempt against a fundamentally wrong
    target.
#>
function Test-ScpErrorIsTransient {
    param([Parameter(Mandatory = $true)][AllowEmptyString()][string]$ErrorText)
    return ($ErrorText -match '(?i)connection reset|broken pipe|connection timed out|operation timed out|connection closed|kex_exchange_identification|could not resolve host')
}

<#
.SYNOPSIS
    Generic, transient-error-only bounded retry — mirrors
    SimpleDeployLauncher.GitFlow.ps1's own Invoke-GitPushWithRetry
    exactly (same 3-attempt/5s-15s-30s-backoff shape). Kept generic
    (Test-ScpErrorIsTransient is only the DEFAULT classifier, injectable
    via -IsTransient) and NEVER itself throws, purely so its own retry
    MECHANICS — exact attempt count, exact backoff delays, stop
    immediately on a non-transient error — can be unit tested with a
    fake -Action, with zero real process/network calls, the same way
    Invoke-GitPushWithRetry already is in Test-LauncherGitFlow.ps1.

.PARAMETER Action
    Scriptblock invoked as `& $Action $attemptNumber`, must return a
    hashtable shaped like Invoke-ExternalCommand's own result
    (@{ExitCode;StdOut;StdErr;...}).

.OUTPUTS
    The LAST attempt's own result hashtable, success or final failure
    alike — the caller decides what a nonzero final ExitCode means
    (e.g. cleanup a partial upload before throwing its own message).
#>
function Invoke-WithTransientRetry {
    param(
        [Parameter(Mandatory = $true)][scriptblock]$Action,
        [Parameter(Mandatory = $false)][int[]]$DelaysSeconds = @(5, 15, 30),
        [Parameter(Mandatory = $false)][scriptblock]$OnRetry = $null,
        [Parameter(Mandatory = $false)][scriptblock]$IsTransient = ${function:Test-ScpErrorIsTransient}
    )

    $attempt = 0
    while ($true) {
        $attempt++
        $result = & $Action $attempt
        if ($result.ExitCode -eq 0) { return $result }
        # -ContainsKey guard (not just $result.Cancelled): under this
        # host's own Set-StrictMode, reading a genuinely-absent key on a
        # Hashtable throws PropertyNotFoundStrict, same as a missing
        # PSCustomObject property elsewhere in this codebase -- confirmed
        # empirically. $Action's real contract only requires
        # ExitCode/StdOut/StdErr; Cancelled is Invoke-ExternalCommand's
        # own addition, not guaranteed on every possible -Action result
        # (e.g. a test's own fake action).
        if ($result.ContainsKey('Cancelled') -and $result.Cancelled) { return $result }

        $isTransientError = & $IsTransient $result.StdErr
        if (-not $isTransientError -or $attempt -gt $DelaysSeconds.Count) {
            return $result
        }
        $delay = $DelaysSeconds[$attempt - 1]
        if ($OnRetry) { & $OnRetry $attempt $delay $result.StdErr }
        Start-Sleep -Seconds $delay
    }
}

<#
.SYNOPSIS
    Size-proportional transfer timeout: max($BaseSeconds, size-based),
    capped at $MaxSeconds so a single stalled/adversarial transfer can
    never block forever. Pure function — no process/network I/O — so
    it's directly unit-testable.

    $SecondsPerMB=30 is deliberately calibrated to the WORST case
    actually observed in the field, not an optimistic guess: the real
    reported failure was a ~32MB artifact that ran for ~793s before
    genuinely failing — that's ~41KB/s average, i.e. ~25s/MB needed just
    to BREAK EVEN with what already happened once; 30s/MB leaves a small
    margin above that instead of exactly matching it. (An earlier draft
    of this function used 5s/MB — a much faster ~200KB/s assumption —
    which computed only 160s for a 32MB artifact, STRICTLY WORSE than
    the already-broken fixed-300s-floor this whole fix exists to
    replace; caught by this file's own test suite before ever shipping,
    not by inspection.) $MaxSeconds=1800 (30 minutes) keeps real
    headroom above the ~960s this now computes for 32MB, while still
    bounding the worst case for a much larger artifact.
#>
function Get-ScpTransferTimeoutSeconds {
    param(
        [Parameter(Mandatory = $true)][long]$SizeBytes,
        [Parameter(Mandatory = $false)][int]$BaseSeconds = 300,
        [Parameter(Mandatory = $false)][double]$SecondsPerMB = 30,
        [Parameter(Mandatory = $false)][int]$MaxSeconds = 1800
    )
    $bySize = [int]([Math]::Ceiling(($SizeBytes / 1MB) * $SecondsPerMB))
    $computed = [Math]::Max($BaseSeconds, $bySize)
    return [Math]::Min($computed, $MaxSeconds)
}

<#
.SYNOPSIS
    Pure comparison — no I/O — so the "does this remote size match"
    DECISION can be unit-tested against fake `stat -c%s` output strings
    without ever needing a real SSH round trip.
#>
function Test-RemoteArtifactSizeMatches {
    param(
        [Parameter(Mandatory = $true)][long]$LocalSizeBytes,
        [Parameter(Mandatory = $true)][AllowEmptyString()][string]$RemoteStatOutput
    )
    $trimmed = $RemoteStatOutput.Trim()
    if ($trimmed -notmatch '^\d+$') { return $false }
    return ([long]$trimmed -eq $LocalSizeBytes)
}

<#
.SYNOPSIS
    Pure comparison — no I/O — parses a real `sha256sum <path>` output
    line ("<64-hex-hash>  <filename>") and compares it against the
    already-known local checksum, case-insensitively. Unit-testable with
    fake remote output strings, no real SSH round trip needed.
#>
function Test-RemoteArtifactChecksumMatches {
    param(
        [Parameter(Mandatory = $true)][string]$LocalChecksumSha256,
        [Parameter(Mandatory = $true)][AllowEmptyString()][string]$RemoteChecksumOutput
    )
    $match = [regex]::Match($RemoteChecksumOutput.Trim(), '^([0-9a-fA-F]{64})')
    if (-not $match.Success) { return $false }
    return ($match.Groups[1].Value.ToLowerInvariant() -eq $LocalChecksumSha256.ToLowerInvariant())
}

function Remove-RemoteFileBestEffort {
    <#
    .SYNOPSIS
        Best-effort `rm -f` of a partial/incomplete remote file — never
        throws itself (a cleanup failure must never mask the REAL error
        that triggered the cleanup in the first place), so a leftover
        `.part` file is a possible remnant of a genuinely double-failed
        run, never a silently-swallowed exception from this helper.
    #>
    param(
        [Parameter(Mandatory = $true)][string]$SshKeyPath,
        [Parameter(Mandatory = $true)][string]$KnownHostsPath,
        [Parameter(Mandatory = $true)][string]$ServerUser,
        [Parameter(Mandatory = $true)][string]$ServerHost,
        [Parameter(Mandatory = $false)][int]$ServerPort = 22,
        [Parameter(Mandatory = $true)][string]$RemotePath,
        [Parameter(Mandatory = $false)][scriptblock]$OnLog = $null
    )
    try {
        if ($OnLog) { & $OnLog "پاک‌سازی فایل ناقص روی سرور: $RemotePath" }
        Invoke-SshRemoteCommand -SshKeyPath $SshKeyPath -KnownHostsPath $KnownHostsPath -ServerUser $ServerUser -ServerHost $ServerHost `
            -RemoteCommand ('rm -f -- ' + (ConvertTo-PosixShellQuotedString $RemotePath)) -ServerPort $ServerPort -TimeoutSeconds 30 | Out-Null
    } catch {
        if ($OnLog) { & $OnLog "پاک‌سازی فایل ناقص روی سرور موفق نبود (نادیده گرفته شد): $($_.Exception.Message)" }
    }
}

<#
.SYNOPSIS
    Uploads $LocalFilePath over SCP — with SSH KeepAlive options, a
    bounded transient-error retry (default 3 attempts, 5s/15s/30s
    backoff — see Invoke-WithTransientRetry/Test-ScpErrorIsTransient),
    and, whenever -ExpectedChecksumSha256 is given, a full atomic
    upload: the real transfer lands at "$RemoteDestinationPath.part",
    is verified on the server (real remote file size, then real remote
    sha256sum — both compared against the local file BEFORE any rename
    happens), and only THEN atomically renamed (`mv`) to its real final
    name. A verification or rename failure removes the `.part` remnant
    and throws — the real, final destination path is never created
    except as a complete, verified copy, so a caller (e.g. the
    activation script) can never observe a partial artifact there.

    -ExpectedChecksumSha256 omitted (the default) keeps the exact prior
    behavior otherwise (a direct, non-atomic upload straight to
    -RemoteDestinationPath) — used for the small activate-release.sh
    script upload, where the atomicity/checksum ceremony isn't the
    point (a corrupt copy of a few-KB shell script fails loudly and
    obviously the moment `bash` tries to run it).
#>
function Invoke-ScpUpload {
    param(
        [Parameter(Mandatory = $true)][string]$SshKeyPath,
        [Parameter(Mandatory = $true)][string]$KnownHostsPath,
        [Parameter(Mandatory = $true)][string]$LocalFilePath,
        [Parameter(Mandatory = $true)][string]$ServerUser,
        [Parameter(Mandatory = $true)][string]$ServerHost,
        [Parameter(Mandatory = $true)][string]$RemoteDestinationPath,
        [Parameter(Mandatory = $false)][int]$ServerPort = 22,
        [Parameter(Mandatory = $false)][int]$TimeoutSeconds = 300,
        [Parameter(Mandatory = $false)][AllowNull()][string]$ExpectedChecksumSha256 = $null,
        [Parameter(Mandatory = $false)][int[]]$DelaysSeconds = @(5, 15, 30),
        [Parameter(Mandatory = $false)][scriptblock]$OnRetry = $null,
        [Parameter(Mandatory = $false)][scriptblock]$OnLog = $null,
        [Parameter(Mandatory = $false)][int]$ServerAliveIntervalSeconds = 15,
        [Parameter(Mandatory = $false)][int]$ServerAliveCountMax = 4,
        [Parameter(Mandatory = $false)][bool]$TcpKeepAlive = $true,
        [Parameter(Mandatory = $false)][scriptblock]$CancellationCheck = $null
    )

    if (-not (Test-SshHostKnown -HostName $ServerHost -KnownHostsPath $KnownHostsPath -Port $ServerPort)) {
        throw "میزبان '$ServerHost' (پورت $ServerPort) در known_hosts ('$KnownHostsPath') پیدا نشد. به دلایل امنیتی، این ابزار هرگز StrictHostKeyChecking را غیرفعال نمی‌کند — ابتدا fingerprint سرور را به‌صورت مستقل تأیید کرده و با ssh-keyscan آن را به known_hosts اضافه کنید."
    }

    $isAtomic = -not [string]::IsNullOrWhiteSpace($ExpectedChecksumSha256)
    $uploadDestination = if ($isAtomic) { "$RemoteDestinationPath.part" } else { $RemoteDestinationPath }
    $localSizeBytes = (Get-Item -LiteralPath $LocalFilePath).Length
    $totalAttempts = $DelaysSeconds.Count + 1

    $uploadAction = {
        param($attempt)
        if ($OnLog) { & $OnLog "SCP attempt $attempt/$totalAttempts -> $uploadDestination ($([Math]::Round($localSizeBytes / 1KB, 1)) KB)" }
        $attemptStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
        $scpArgs = Get-ScpUploadCommandArgs -SshKeyPath $SshKeyPath -KnownHostsPath $KnownHostsPath -LocalFilePath $LocalFilePath `
            -ServerUser $ServerUser -ServerHost $ServerHost -RemoteDestinationPath $uploadDestination -ServerPort $ServerPort `
            -ServerAliveIntervalSeconds $ServerAliveIntervalSeconds -ServerAliveCountMax $ServerAliveCountMax -TcpKeepAlive $TcpKeepAlive
        $r = Invoke-ExternalCommand -FilePath 'scp.exe' -ArgumentList $scpArgs -TimeoutSeconds $TimeoutSeconds -CancellationCheck $CancellationCheck
        $attemptStopwatch.Stop()
        $elapsedSeconds = [Math]::Max(0.001, $attemptStopwatch.Elapsed.TotalSeconds)
        $speedKBs = [Math]::Round(($localSizeBytes / 1KB) / $elapsedSeconds, 1)
        if ($OnLog) { & $OnLog "SCP attempt $attempt finished: exit=$($r.ExitCode) elapsed=$([Math]::Round($elapsedSeconds,1))s avg_speed=${speedKBs}KB/s" }
        return $r
    }.GetNewClosure()

    $finalResult = Invoke-WithTransientRetry -Action $uploadAction -DelaysSeconds $DelaysSeconds -OnRetry {
        param($attempt, $delay, $errorText)
        if ($OnLog) { & $OnLog "SCP attempt $attempt با خطای گذرا مواجه شد، تلاش مجدد پس از ${delay}s: $errorText" }
        if ($OnRetry) { & $OnRetry $attempt $delay $errorText }
    }.GetNewClosure()

    if ($finalResult.Cancelled) {
        if ($isAtomic) {
            Remove-RemoteFileBestEffort -SshKeyPath $SshKeyPath -KnownHostsPath $KnownHostsPath -ServerUser $ServerUser -ServerHost $ServerHost -ServerPort $ServerPort -RemotePath $uploadDestination -OnLog $OnLog
        }
        throw 'انتقال توسط کاربر لغو شد.'
    }

    if ($finalResult.ExitCode -ne 0) {
        if ($isAtomic) {
            Remove-RemoteFileBestEffort -SshKeyPath $SshKeyPath -KnownHostsPath $KnownHostsPath -ServerUser $ServerUser -ServerHost $ServerHost -ServerPort $ServerPort -RemotePath $uploadDestination -OnLog $OnLog
        }
        throw "انتقال با scp با خطا مواجه شد (exit $($finalResult.ExitCode)) پس از $totalAttempts تلاش:`n$($finalResult.StdErr)"
    }

    if (-not $isAtomic) {
        return $finalResult
    }

    # --- Atomic path only: verify remote size, verify remote checksum, THEN rename. ---
    # Each of these is now retried the SAME way the scp upload itself is
    # (Invoke-WithTransientRetry/Test-ScpErrorIsTransient) -- previously a
    # single transient network blip (e.g. "Connection reset by ...") on
    # just the lightweight follow-up `stat`/`sha256sum`/`mv` SSH call was
    # enough to make a fully, correctly uploaded artifact look like a
    # size/checksum mismatch and get deleted, aborting an otherwise-good
    # Deploy. A genuine mismatch (file really is missing/wrong size) is
    # NOT a transient error pattern, so it still fails immediately as before.
    if ($OnLog) { & $OnLog 'بررسی صحت فایل روی سرور (حجم)...' }
    $sizeCheckAction = {
        param($attempt)
        Invoke-SshRemoteCommand -SshKeyPath $SshKeyPath -KnownHostsPath $KnownHostsPath -ServerUser $ServerUser -ServerHost $ServerHost `
            -RemoteCommand ('stat -c%s -- ' + (ConvertTo-PosixShellQuotedString $uploadDestination)) -ServerPort $ServerPort -TimeoutSeconds 30 `
            -ServerAliveIntervalSeconds $ServerAliveIntervalSeconds -ServerAliveCountMax $ServerAliveCountMax -TcpKeepAlive $TcpKeepAlive
    }.GetNewClosure()
    $sizeCheck = Invoke-WithTransientRetry -Action $sizeCheckAction -DelaysSeconds $DelaysSeconds -OnRetry {
        param($attempt, $delay, $errorText)
        if ($OnLog) { & $OnLog "بررسی حجم با خطای گذرا مواجه شد، تلاش مجدد پس از ${delay}s: $errorText" }
    }.GetNewClosure()
    if (-not (Test-RemoteArtifactSizeMatches -LocalSizeBytes $localSizeBytes -RemoteStatOutput $sizeCheck.StdOut)) {
        if ($OnLog) { & $OnLog "عدم تطابق حجم: local=$localSizeBytes remote='$($sizeCheck.StdOut.Trim())'" }
        Remove-RemoteFileBestEffort -SshKeyPath $SshKeyPath -KnownHostsPath $KnownHostsPath -ServerUser $ServerUser -ServerHost $ServerHost -ServerPort $ServerPort -RemotePath $uploadDestination -OnLog $OnLog
        throw "حجم فایل منتقل‌شده روی سرور با فایل محلی مطابقت ندارد (local=$localSizeBytes bytes, remote='$($sizeCheck.StdOut.Trim())') — انتقال ناقص بوده؛ فایل ناقص پاک شد و Activation انجام نمی‌شود."
    }
    if ($OnLog) { & $OnLog "بررسی حجم موفق (remote size = $($sizeCheck.StdOut.Trim()) bytes)." }

    if ($OnLog) { & $OnLog 'بررسی صحت فایل روی سرور (checksum)...' }
    $checksumCheckAction = {
        param($attempt)
        Invoke-SshRemoteCommand -SshKeyPath $SshKeyPath -KnownHostsPath $KnownHostsPath -ServerUser $ServerUser -ServerHost $ServerHost `
            -RemoteCommand ('sha256sum -- ' + (ConvertTo-PosixShellQuotedString $uploadDestination)) -ServerPort $ServerPort -TimeoutSeconds 60 `
            -ServerAliveIntervalSeconds $ServerAliveIntervalSeconds -ServerAliveCountMax $ServerAliveCountMax -TcpKeepAlive $TcpKeepAlive
    }.GetNewClosure()
    $checksumCheck = Invoke-WithTransientRetry -Action $checksumCheckAction -DelaysSeconds $DelaysSeconds -OnRetry {
        param($attempt, $delay, $errorText)
        if ($OnLog) { & $OnLog "بررسی checksum با خطای گذرا مواجه شد، تلاش مجدد پس از ${delay}s: $errorText" }
    }.GetNewClosure()
    if (-not (Test-RemoteArtifactChecksumMatches -LocalChecksumSha256 $ExpectedChecksumSha256 -RemoteChecksumOutput $checksumCheck.StdOut)) {
        if ($OnLog) { & $OnLog "عدم تطابق checksum: local=$ExpectedChecksumSha256 remote-output='$($checksumCheck.StdOut.Trim())'" }
        Remove-RemoteFileBestEffort -SshKeyPath $SshKeyPath -KnownHostsPath $KnownHostsPath -ServerUser $ServerUser -ServerHost $ServerHost -ServerPort $ServerPort -RemotePath $uploadDestination -OnLog $OnLog
        throw 'checksum فایل منتقل‌شده روی سرور با checksum محلی مطابقت ندارد — فایل ناقص/خراب پاک شد و Activation انجام نمی‌شود.'
    }
    if ($OnLog) { & $OnLog 'بررسی checksum موفق — فایل روی سرور کامل و سالم است.' }

    $renameAction = {
        param($attempt)
        Invoke-SshRemoteCommand -SshKeyPath $SshKeyPath -KnownHostsPath $KnownHostsPath -ServerUser $ServerUser -ServerHost $ServerHost `
            -RemoteCommand ('mv -f -- ' + (ConvertTo-PosixShellQuotedString $uploadDestination) + ' ' + (ConvertTo-PosixShellQuotedString $RemoteDestinationPath)) `
            -ServerPort $ServerPort -TimeoutSeconds 30 -ServerAliveIntervalSeconds $ServerAliveIntervalSeconds -ServerAliveCountMax $ServerAliveCountMax -TcpKeepAlive $TcpKeepAlive
    }.GetNewClosure()
    $renameResult = Invoke-WithTransientRetry -Action $renameAction -DelaysSeconds $DelaysSeconds -OnRetry {
        param($attempt, $delay, $errorText)
        if ($OnLog) { & $OnLog "rename اتمیک با خطای گذرا مواجه شد، تلاش مجدد پس از ${delay}s: $errorText" }
    }.GetNewClosure()
    if ($renameResult.ExitCode -ne 0) {
        Remove-RemoteFileBestEffort -SshKeyPath $SshKeyPath -KnownHostsPath $KnownHostsPath -ServerUser $ServerUser -ServerHost $ServerHost -ServerPort $ServerPort -RemotePath $uploadDestination -OnLog $OnLog
        throw "rename اتمیک فایل روی سرور (از .part به نام نهایی) با خطا مواجه شد: $($renameResult.StdErr)"
    }
    if ($OnLog) { & $OnLog "rename اتمیک موفق -> $RemoteDestinationPath" }

    return $finalResult
}

function Invoke-SshRemoteCommand {
    param(
        [Parameter(Mandatory = $true)][string]$SshKeyPath,
        [Parameter(Mandatory = $true)][string]$KnownHostsPath,
        [Parameter(Mandatory = $true)][string]$ServerUser,
        [Parameter(Mandatory = $true)][string]$ServerHost,
        [Parameter(Mandatory = $true)][string]$RemoteCommand,
        [Parameter(Mandatory = $false)][int]$ServerPort = 22,
        [Parameter(Mandatory = $false)][int]$TimeoutSeconds = 300,
        [Parameter(Mandatory = $false)][int]$ServerAliveIntervalSeconds = 15,
        [Parameter(Mandatory = $false)][int]$ServerAliveCountMax = 4,
        [Parameter(Mandatory = $false)][bool]$TcpKeepAlive = $true,
        [Parameter(Mandatory = $false)][scriptblock]$CancellationCheck = $null
    )

    if (-not (Test-SshHostKnown -HostName $ServerHost -KnownHostsPath $KnownHostsPath -Port $ServerPort)) {
        throw "میزبان '$ServerHost' (پورت $ServerPort) در known_hosts ('$KnownHostsPath') پیدا نشد. به دلایل امنیتی، این ابزار هرگز StrictHostKeyChecking را غیرفعال نمی‌کند — ابتدا fingerprint سرور را به‌صورت مستقل تأیید کرده و با ssh-keyscan آن را به known_hosts اضافه کنید."
    }

    $sshArgs = Get-SshRemoteCommandArgs -SshKeyPath $SshKeyPath -KnownHostsPath $KnownHostsPath -ServerUser $ServerUser -ServerHost $ServerHost -RemoteCommand $RemoteCommand -ServerPort $ServerPort `
        -ServerAliveIntervalSeconds $ServerAliveIntervalSeconds -ServerAliveCountMax $ServerAliveCountMax -TcpKeepAlive $TcpKeepAlive
    $result = Invoke-ExternalCommand -FilePath 'ssh.exe' -ArgumentList $sshArgs -TimeoutSeconds $TimeoutSeconds -CancellationCheck $CancellationCheck
    return $result
}
