<#
.SYNOPSIS
    Shared, dependency-free helpers used by every other SimpleDeploy
    library file. No external module (Pester, etc.) is required to run
    the tool itself — only to run its own test suite, and even that uses
    plain assertion scripts, not a framework, to avoid any version
    mismatch with whatever Pester happens to be installed.

.NOTES
    Every external process invocation in this whole tool goes through
    Invoke-ExternalCommand below, which builds ProcessStartInfo's
    Arguments string via Format-WindowsCommandLine — a manual
    implementation of the standard Win32/CRT argv-escaping algorithm
    (the same one .NET's own ProcessStartInfo.ArgumentList uses
    internally; that convenience property was empirically confirmed
    ABSENT on this exact Windows PowerShell 5.1 / .NET Framework 4.8
    host, hence reproducing it by hand) — never a single naively
    interpolated string, never cmd.exe, never Invoke-Expression. This is
    the PowerShell equivalent of the array-form proc_open() already used
    throughout this project's Local/Server Agents: each element of
    -ArgumentList is its own token, so a path or branch name containing
    spaces, quotes, `;`, `&&`, or backticks can never be reinterpreted as
    a second command.
#>

Set-StrictMode -Version Latest

# Persian text in Write-Host output / exception messages otherwise
# renders as mojibake on a stock Windows PowerShell 5.1 console, whose
# default output encoding is the legacy system codepage, not UTF-8 —
# confirmed empirically. Every entry point (SimpleDeploy.ps1, every
# tests/*.ps1 file) dot-sources this file first, so setting it here
# covers all of them from one place.
try {
    [Console]::OutputEncoding = [System.Text.Encoding]::UTF8
} catch {
    # Some hosts (e.g. certain CI/redirected-output contexts) don't
    # allow changing this — never fatal, output just falls back to
    # whatever the host's own default is.
}

function Write-Step {
    param([Parameter(Mandatory = $true)][string]$Message)
    Write-Host ""
    Write-Host "==> $Message" -ForegroundColor Cyan
}

function Write-Info {
    param([Parameter(Mandatory = $true)][string]$Message)
    Write-Host "    $Message" -ForegroundColor Gray
}

function Write-Success {
    param([Parameter(Mandatory = $true)][string]$Message)
    Write-Host "    [OK] $Message" -ForegroundColor Green
}

function Write-Warn {
    param([Parameter(Mandatory = $true)][string]$Message)
    Write-Host "    [هشدار] $Message" -ForegroundColor Yellow
}

function Write-ErrorFa {
    param([Parameter(Mandatory = $true)][string]$Message)
    Write-Host "    [خطا] $Message" -ForegroundColor Red
}

<#
.SYNOPSIS
    The ONLY way this tool ever invokes an external executable (git, ssh,
    scp, composer, npm, taskkill, schtasks, ...). Always argv-array form
    — each element of -ArgumentList is one token, correctly escaped by
    Format-WindowsCommandLineArguments below, exactly reproducing the
    "array = one token each" injection-safety this project's own PHP
    Agents already get for free from array-form proc_open(). NOT built
    via ProcessStartInfo's own ArgumentList collection — confirmed
    empirically absent from this exact Windows PowerShell 5.1 / .NET
    Framework 4.8 host (a PropertyNotFoundException at runtime,
    regardless of what the general .NET Framework 4.7.2+ documentation
    claims) — so the same Win32 argument-escaping algorithm .NET's own
    ArgumentList uses internally is reproduced by hand here instead.

.OUTPUTS
    Hashtable: @{ ExitCode; StdOut; StdErr; TimedOut }
#>
function Format-WindowsCommandLineArgument {
    param([Parameter(Mandatory = $true)][AllowEmptyString()][string]$Argument)

    if ($Argument -eq '') { return '""' }
    if ($Argument -notmatch '[\s"]') { return $Argument }

    # Standard Win32/CRT argv escaping (the same algorithm .NET's own
    # ProcessStartInfo.ArgumentList uses internally): backslashes are
    # only special immediately before a literal `"`, or at the very end
    # of a quoted argument.
    $result = New-Object System.Text.StringBuilder
    [void]$result.Append('"')
    $backslashCount = 0
    foreach ($ch in $Argument.ToCharArray()) {
        if ($ch -eq '\') {
            $backslashCount++
            continue
        }
        if ($ch -eq '"') {
            [void]$result.Append('\' * (($backslashCount * 2) + 1))
            [void]$result.Append('"')
            $backslashCount = 0
            continue
        }
        if ($backslashCount -gt 0) {
            [void]$result.Append('\' * $backslashCount)
            $backslashCount = 0
        }
        [void]$result.Append($ch)
    }
    if ($backslashCount -gt 0) {
        [void]$result.Append('\' * ($backslashCount * 2))
    }
    [void]$result.Append('"')
    return $result.ToString()
}

function Format-WindowsCommandLine {
    param([Parameter(Mandatory = $true)][AllowEmptyCollection()][string[]]$Arguments)
    return (($Arguments | ForEach-Object { Format-WindowsCommandLineArgument -Argument $_ }) -join ' ')
}

<#
.SYNOPSIS
    Resolves a bare executable name (e.g. "npm", "composer" — this tool's
    own default command-prefix fallback in Resolve-CommandPrefix) to its
    real file path.

.NOTES
    ProcessStartInfo/CreateProcess perform only a LITERAL PATH search on
    the given file name — unlike cmd.exe or Get-Command, they never
    consult %PATHEXT% to try appending .exe/.cmd/.bat/etc. On a real
    Node.js install there usually IS no bare "npm" file at all — only
    "npm.cmd" — so passing "npm" straight through as ProcessStartInfo's
    FileName fails with a Win32Exception ("The system cannot find the
    file specified") even though npm is genuinely on PATH. Confirmed
    empirically: this broke every default (unconfigured) composer/npm
    invocation in the whole tool. Get-Command performs the correct
    PATHEXT-aware resolution, so try that first; fall back to the
    original value unchanged if it can't resolve anything (an
    already-correct absolute path, or a genuinely missing tool — in
    which case the same clear "not found" error surfaces as before).
#>

# Every git/ssh/scp/composer/npm/... invocation in this whole tool goes
# through Invoke-ExternalCommand -> Resolve-ExecutablePath, and a single
# Deploy already runs 10+ external commands. Get-Command's PATH/PATHEXT
# search is deterministic for a given executable name within one process
# run (PATH doesn't change mid-run), so memoizing it here removes a real,
# repeated cost with zero behavior change: the exact same resolved path
# (or unresolved fallback) is returned every time, just without redoing
# the search. Never shared across process runs (this is a plain
# in-process hashtable, not a file), so a PATH change takes effect
# normally on the next run.
$script:ResolvedExecutablePathCache = @{}

function Resolve-ExecutablePath {
    param([Parameter(Mandatory = $true)][string]$FilePath)

    if ($script:ResolvedExecutablePathCache.ContainsKey($FilePath)) {
        return $script:ResolvedExecutablePathCache[$FilePath]
    }

    $resolved = Get-Command -Name $FilePath -CommandType Application -ErrorAction SilentlyContinue | Select-Object -First 1
    $result = if ($resolved -and $resolved.Source) { $resolved.Source } else { $FilePath }
    $script:ResolvedExecutablePathCache[$FilePath] = $result
    return $result
}

function Stop-ProcessTreeGuarded {
    <#
    .SYNOPSIS
        Kills a process AND its descendants. `Process.Kill(Boolean
        entireProcessTree)` looks like the obvious way to do this but
        that overload DOES NOT EXIST on .NET Framework 4.8 (Windows
        PowerShell 5.1's runtime) -- it was only added in .NET Core 3.0.
        Confirmed empirically on this exact host: calling it throws
        System.Management.Automation.MethodException ("Cannot find an
        overload for 'Kill' and the argument count: '1'"). Every prior
        call site that did `try { $process.Kill($true) } catch {}`
        therefore silently did NOTHING on a timeout/cancel -- the
        "killed" process kept running completely unsupervised, and the
        caller's blocking wait on its output streams then waited for
        that orphaned process's own eventual, unbounded, natural exit
        instead. taskkill.exe /T /F is the standard, dependency-free,
        already-a-Windows-built-in way to kill a real process tree on
        .NET Framework; the plain no-argument Kill() (which DOES exist)
        is called first as a fast, direct fallback in case taskkill
        itself is ever unavailable.
    #>
    param([Parameter(Mandatory = $true)][int]$ProcessId)
    try {
        Start-Process -FilePath 'taskkill.exe' -ArgumentList @('/PID', $ProcessId, '/T', '/F') `
            -WindowStyle Hidden -Wait -ErrorAction SilentlyContinue | Out-Null
    } catch {}
    try {
        $stillThere = Get-Process -Id $ProcessId -ErrorAction SilentlyContinue
        if ($stillThere) { $stillThere.Kill() }
    } catch {}
}

function Invoke-ExternalCommand {
    param(
        [Parameter(Mandatory = $true)][string]$FilePath,
        [Parameter(Mandatory = $false)][string[]]$ArgumentList = @(),
        [Parameter(Mandatory = $false)][string]$WorkingDirectory = $null,
        [Parameter(Mandatory = $false)][int]$TimeoutSeconds = 0,
        [Parameter(Mandatory = $false)][hashtable]$EnvironmentOverrides = $null,
        # Optional -- polled every ~250ms while waiting for the process to
        # exit; if it ever returns $true, the process (tree) is killed
        # immediately and the result comes back with Cancelled=$true.
        # $null (the default) is EXACTLY today's unchanged single blocking
        # WaitForExit() behavior for every existing caller.
        [Parameter(Mandatory = $false)][scriptblock]$CancellationCheck = $null
    )

    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = Resolve-ExecutablePath -FilePath $FilePath
    $psi.Arguments = Format-WindowsCommandLine -Arguments $ArgumentList
    if ($WorkingDirectory) { $psi.WorkingDirectory = $WorkingDirectory }
    $psi.UseShellExecute = $false
    $psi.RedirectStandardOutput = $true
    $psi.RedirectStandardError = $true
    $psi.StandardOutputEncoding = [System.Text.Encoding]::UTF8
    $psi.StandardErrorEncoding = [System.Text.Encoding]::UTF8
    $psi.CreateNoWindow = $true

    if ($EnvironmentOverrides) {
        foreach ($key in $EnvironmentOverrides.Keys) {
            $psi.EnvironmentVariables[$key] = $EnvironmentOverrides[$key]
        }
    }

    $process = New-Object System.Diagnostics.Process
    $process.StartInfo = $psi

    try {
        [void]$process.Start()

        # ReadToEndAsync() on each stream, started immediately after
        # Start(), completes only when its pipe actually reaches EOF
        # (the child closes/exits) — this is unambiguous and needs no
        # timing heuristic. An earlier line-based OutputDataReceived +
        # Register-ObjectEvent design was empirically found to RACE and
        # silently drop output: the very first Invoke-ExternalCommand
        # call in a session could return empty StdOut for a short-lived
        # child (e.g. `bash -c "printf hello"`), because the event
        # subscription wasn't reliably "hot" before the child had already
        # exited. ReadToEndAsync also preserves embedded newlines exactly
        # as emitted, unlike splitting into lines and rejoining with
        # AppendLine (which normalizes every line ending to
        # Environment.NewLine and adds one even after a final line that
        # never had one).
        $stdoutTask = $process.StandardOutput.ReadToEndAsync()
        $stderrTask = $process.StandardError.ReadToEndAsync()

        $timedOut = $false
        $cancelled = $false
        if ($CancellationCheck -or $TimeoutSeconds -gt 0) {
            # Polling wait (250ms slices) instead of one single blocking
            # WaitForExit(ms) call -- needed so -CancellationCheck can
            # actually be noticed WHILE a command is still running, not
            # only after it would have timed out anyway. Produces the
            # exact same observable result as the old single-wait form
            # for a plain -TimeoutSeconds caller with no
            # -CancellationCheck (still returns once the process exits,
            # or once the same total timeout has elapsed) -- only the
            # wait MECHANISM changed, not the outcome.
            $deadline = if ($TimeoutSeconds -gt 0) { (Get-Date).AddSeconds($TimeoutSeconds) } else { $null }
            while ($true) {
                if ($process.WaitForExit(250)) { break }
                if ($CancellationCheck -and (& $CancellationCheck)) {
                    $cancelled = $true
                    break
                }
                if ($deadline -and (Get-Date) -ge $deadline) {
                    $timedOut = $true
                    break
                }
            }
            if ($timedOut -or $cancelled) {
                Stop-ProcessTreeGuarded -ProcessId $process.Id
                $process.WaitForExit(5000) | Out-Null
            }
        } else {
            $process.WaitForExit()
        }

        # The streams close as part of process exit, so these tasks are
        # normally already complete by now; bound the wait defensively.
        [System.Threading.Tasks.Task]::WaitAll(@($stdoutTask, $stderrTask), 5000) | Out-Null

        $exitCode = if ($timedOut -or $cancelled) { 124 } else { $process.ExitCode }

        return @{
            ExitCode  = $exitCode
            StdOut    = $stdoutTask.Result
            StdErr    = $stderrTask.Result
            TimedOut  = $timedOut
            Cancelled = $cancelled
        }
    } finally {
        $process.Dispose()
    }
}

function Get-FileSha256 {
    param([Parameter(Mandatory = $true)][string]$Path)
    $hash = Get-FileHash -Path $Path -Algorithm SHA256
    return $hash.Hash.ToLowerInvariant()
}

function New-UniqueTempDirectory {
    param([Parameter(Mandatory = $false)][string]$Prefix = 'simpledeploy')
    $root = Join-Path $env:TEMP "$Prefix-$([System.Guid]::NewGuid().ToString('N').Substring(0, 12))"
    New-Item -ItemType Directory -Path $root -Force | Out-Null
    return $root
}

<#
.SYNOPSIS
    Recursively deletes $Path, but ONLY if it is confirmed (via resolved
    real path comparison) to be nested inside $ExpectedFloor and is not
    equal to $ExpectedFloor itself — mirrors the guarded deletion already
    proven in this project's Local Agent (BuildWorkspace::guardedRemoveTree)
    so a coding mistake here fails loudly instead of silently deleting
    something unexpected (a project's own working tree, for instance).
#>
function Remove-DirectoryTreeGuarded {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $true)][string]$ExpectedFloor
    )

    if (-not (Test-Path -LiteralPath $Path)) { return }

    $resolvedPath = (Resolve-Path -LiteralPath $Path -ErrorAction Stop).ProviderPath.TrimEnd('\', '/')
    $resolvedFloor = (Resolve-Path -LiteralPath $ExpectedFloor -ErrorAction Stop).ProviderPath.TrimEnd('\', '/')

    if ($resolvedPath -ieq $resolvedFloor) {
        throw "Refusing to delete '$Path' — it resolved to its own expected floor ('$ExpectedFloor'), not a specific subdirectory of it."
    }
    if (-not $resolvedPath.StartsWith("$resolvedFloor\", [StringComparison]::OrdinalIgnoreCase)) {
        throw "Refusing to delete '$Path' — it is not confirmed to be inside its expected root ('$ExpectedFloor')."
    }

    Remove-Item -LiteralPath $resolvedPath -Recurse -Force -ErrorAction Stop
}

function ConvertTo-UnixPath {
    param([Parameter(Mandatory = $true)][string]$Path)
    return $Path.Replace('\', '/')
}

<#
.SYNOPSIS
    Loads and parses a JSON config file. Object keys starting with "_"
    (e.g. "_comment", "_audit") are documentation-only annotations kept
    IN the file for the human operator's benefit and are simply ignored
    by every reader in this tool — never treated as real settings.
#>
function Get-SimpleDeployConfig {
    param([Parameter(Mandatory = $true)][string]$ConfigPath)

    if (-not (Test-Path -LiteralPath $ConfigPath)) {
        throw "فایل پیکربندی پیدا نشد: $ConfigPath`nابتدا config.example.json را به config.json کپی کرده و مقادیر CHANGE_ME را تکمیل کنید."
    }

    $raw = Get-Content -LiteralPath $ConfigPath -Raw -Encoding UTF8
    try {
        return $raw | ConvertFrom-Json
    } catch {
        throw "فایل پیکربندی '$ConfigPath' یک JSON معتبر نیست: $($_.Exception.Message)"
    }
}

function Test-PlaceholderValue {
    param([Parameter(Mandatory = $false)][AllowNull()][string]$Value)
    if ([string]::IsNullOrWhiteSpace($Value)) { return $true }
    if ($Value -match '^CHANGE_ME') { return $true }
    # "***" is this tool's own convention (used in a real, audited
    # config.json) for "this field genuinely could not be extracted from
    # any real source — do not guess". Must be recognized here too, or a
    # field literally containing the three characters "***" would be
    # treated as a valid server_host/user/key path and the tool would try
    # to actually connect to a host named "***".
    if ($Value -eq '***') { return $true }
    return $false
}
