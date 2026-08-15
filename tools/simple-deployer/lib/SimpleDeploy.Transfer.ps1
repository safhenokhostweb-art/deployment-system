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

    if (-not (Test-Path -LiteralPath $KnownHostsPath)) {
        return $false
    }

    # OpenSSH records/looks up a non-default-port host as "[host]:port" in
    # known_hosts (e.g. "[87.107.12.81]:9011"), not the bare hostname —
    # ssh-keygen -F must be given that same bracketed form or the lookup
    # never matches even though the real entry is genuinely present.
    $lookupTarget = if ($Port -ne 22) { "[$HostName]:$Port" } else { $HostName }
    $result = Invoke-ExternalCommand -FilePath 'ssh-keygen.exe' -ArgumentList @('-F', $lookupTarget, '-f', $KnownHostsPath) -TimeoutSeconds 10
    return ($result.ExitCode -eq 0)
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
        [Parameter(Mandatory = $false)][int]$ConnectTimeoutSeconds = 10
    )

    return @(
        '-i', $SshKeyPath,
        '-P', [string]$ServerPort,
        '-o', 'StrictHostKeyChecking=yes',
        '-o', "UserKnownHostsFile=$KnownHostsPath",
        '-o', "ConnectTimeout=$ConnectTimeoutSeconds",
        '-o', 'BatchMode=yes',
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
        [Parameter(Mandatory = $false)][int]$ConnectTimeoutSeconds = 10
    )

    return @(
        '-i', $SshKeyPath,
        '-p', [string]$ServerPort,
        '-o', 'StrictHostKeyChecking=yes',
        '-o', "UserKnownHostsFile=$KnownHostsPath",
        '-o', "ConnectTimeout=$ConnectTimeoutSeconds",
        '-o', 'BatchMode=yes',
        "${ServerUser}@${ServerHost}",
        $RemoteCommand
    )
}

function Invoke-ScpUpload {
    param(
        [Parameter(Mandatory = $true)][string]$SshKeyPath,
        [Parameter(Mandatory = $true)][string]$KnownHostsPath,
        [Parameter(Mandatory = $true)][string]$LocalFilePath,
        [Parameter(Mandatory = $true)][string]$ServerUser,
        [Parameter(Mandatory = $true)][string]$ServerHost,
        [Parameter(Mandatory = $true)][string]$RemoteDestinationPath,
        [Parameter(Mandatory = $false)][int]$ServerPort = 22,
        [Parameter(Mandatory = $false)][int]$TimeoutSeconds = 300
    )

    if (-not (Test-SshHostKnown -HostName $ServerHost -KnownHostsPath $KnownHostsPath -Port $ServerPort)) {
        throw "میزبان '$ServerHost' (پورت $ServerPort) در known_hosts ('$KnownHostsPath') پیدا نشد. به دلایل امنیتی، این ابزار هرگز StrictHostKeyChecking را غیرفعال نمی‌کند — ابتدا fingerprint سرور را به‌صورت مستقل تأیید کرده و با ssh-keyscan آن را به known_hosts اضافه کنید."
    }

    $args = Get-ScpUploadCommandArgs -SshKeyPath $SshKeyPath -KnownHostsPath $KnownHostsPath -LocalFilePath $LocalFilePath -ServerUser $ServerUser -ServerHost $ServerHost -RemoteDestinationPath $RemoteDestinationPath -ServerPort $ServerPort
    $result = Invoke-ExternalCommand -FilePath 'scp.exe' -ArgumentList $args -TimeoutSeconds $TimeoutSeconds
    if ($result.ExitCode -ne 0) {
        throw "انتقال با scp با خطا مواجه شد (exit $($result.ExitCode)):`n$($result.StdErr)"
    }
    return $result
}

function Invoke-SshRemoteCommand {
    param(
        [Parameter(Mandatory = $true)][string]$SshKeyPath,
        [Parameter(Mandatory = $true)][string]$KnownHostsPath,
        [Parameter(Mandatory = $true)][string]$ServerUser,
        [Parameter(Mandatory = $true)][string]$ServerHost,
        [Parameter(Mandatory = $true)][string]$RemoteCommand,
        [Parameter(Mandatory = $false)][int]$ServerPort = 22,
        [Parameter(Mandatory = $false)][int]$TimeoutSeconds = 300
    )

    if (-not (Test-SshHostKnown -HostName $ServerHost -KnownHostsPath $KnownHostsPath -Port $ServerPort)) {
        throw "میزبان '$ServerHost' (پورت $ServerPort) در known_hosts ('$KnownHostsPath') پیدا نشد. به دلایل امنیتی، این ابزار هرگز StrictHostKeyChecking را غیرفعال نمی‌کند — ابتدا fingerprint سرور را به‌صورت مستقل تأیید کرده و با ssh-keyscan آن را به known_hosts اضافه کنید."
    }

    $args = Get-SshRemoteCommandArgs -SshKeyPath $SshKeyPath -KnownHostsPath $KnownHostsPath -ServerUser $ServerUser -ServerHost $ServerHost -RemoteCommand $RemoteCommand -ServerPort $ServerPort
    $result = Invoke-ExternalCommand -FilePath 'ssh.exe' -ArgumentList $args -TimeoutSeconds $TimeoutSeconds
    return $result
}
