<#
.SYNOPSIS
    Shared assertion helpers for SimpleDeploy's own test suite. No
    testing framework (Pester version compatibility on this machine is
    an old 3.4.0, bundled with Windows PowerShell — a modern Pester 5.x
    syntax would risk silently not running at all) — plain scripts with
    real fixtures and real assertions, matching this project's own
    established PHP test-suite philosophy exactly (see local-agent/tests/).
#>

Set-StrictMode -Version Latest

$Global:TestPassCount = 0
$Global:TestFailures = New-Object System.Collections.Generic.List[string]

function Assert-That {
    param(
        [Parameter(Mandatory = $true)][string]$Description,
        [Parameter(Mandatory = $true)][bool]$Condition
    )
    if ($Condition) {
        $Global:TestPassCount++
    } else {
        $Global:TestFailures.Add($Description)
    }
}

function New-TestTempDirectory {
    param([Parameter(Mandatory = $false)][string]$Prefix = 'sdtest')
    $dir = Join-Path $env:TEMP "$Prefix-$([System.Guid]::NewGuid().ToString('N').Substring(0,10))"
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    return $dir
}

function Remove-TestTempDirectory {
    param([Parameter(Mandatory = $true)][string]$Path)
    if (Test-Path -LiteralPath $Path) {
        Remove-Item -LiteralPath $Path -Recurse -Force -ErrorAction SilentlyContinue
    }
}

function Initialize-TestGitRepo {
    param(
        [Parameter(Mandatory = $true)][string]$Path,
        [Parameter(Mandatory = $false)][string]$Branch = 'main'
    )
    New-Item -ItemType Directory -Path $Path -Force | Out-Null
    & git.exe init --quiet -b $Branch $Path 2>&1 | Out-Null
    & git.exe -C $Path config user.email 'test@example.test' 2>&1 | Out-Null
    & git.exe -C $Path config user.name 'SimpleDeploy Test' 2>&1 | Out-Null
}

<#
.SYNOPSIS
    Registers a real, throwaway Scheduled Task whose action is a
    powershell.exe process that stays alive for $SleepSeconds and carries
    $MarkerText as a literal, matchable substring of its own CommandLine
    (Win32_Process.CommandLine reports the raw argument text regardless
    of what the interpreter does with it, so the marker survives even
    though it's assigned to a throwaway variable, never executed for its
    own sake). A far-future one-time trigger means it never fires on its
    own — tests start it explicitly via Start-ScheduledTask.
#>
function New-TestScheduledTask {
    param(
        [Parameter(Mandatory = $true)][string]$TaskName,
        [Parameter(Mandatory = $false)][string]$MarkerText = 'SimpleDeployTestMarker',
        [Parameter(Mandatory = $false)][int]$SleepSeconds = 25
    )
    $action = New-ScheduledTaskAction -Execute 'powershell.exe' `
        -Argument "-NoProfile -WindowStyle Hidden -Command `"`$m='$MarkerText'; Start-Sleep -Seconds $SleepSeconds`""
    $trigger = New-ScheduledTaskTrigger -Once -At (Get-Date).AddYears(5)
    $settings = New-ScheduledTaskSettingsSet -AllowStartIfOnBatteries -DontStopIfGoingOnBatteries -DontStopOnIdleEnd -ExecutionTimeLimit ([TimeSpan]::Zero)
    Register-ScheduledTask -TaskName $TaskName -Action $action -Trigger $trigger -Settings $settings `
        -Description 'SimpleDeploy throwaway test task — safe to delete' -ErrorAction Stop | Out-Null
}

function Remove-TestScheduledTask {
    param([Parameter(Mandatory = $true)][string]$TaskName)
    try { schtasks.exe /End /TN $TaskName 2>&1 | Out-Null } catch {}
    Unregister-ScheduledTask -TaskName $TaskName -Confirm:$false -ErrorAction SilentlyContinue
}

<#
.SYNOPSIS
    Force-kills any leftover process carrying $MarkerText in its
    CommandLine — a final safety net so a failed/interrupted test never
    leaves a throwaway sleep process running.
#>
function Stop-TestMarkerProcesses {
    param([Parameter(Mandatory = $false)][string]$MarkerText = 'SimpleDeployTestMarker')
    Get-CimInstance -ClassName Win32_Process -Filter "Name = 'powershell.exe'" -ErrorAction SilentlyContinue |
        Where-Object { $_.CommandLine -and $_.CommandLine.Contains($MarkerText) } |
        ForEach-Object { Stop-Process -Id $_.ProcessId -Force -ErrorAction SilentlyContinue }
}

function Wait-ForScheduledTaskState {
    param(
        [Parameter(Mandatory = $true)][string]$TaskName,
        [Parameter(Mandatory = $true)][string]$ExpectedState,
        [Parameter(Mandatory = $false)][int]$TimeoutSeconds = 8
    )
    $deadline = (Get-Date).AddSeconds($TimeoutSeconds)
    while ((Get-Date) -lt $deadline) {
        $state = (Get-ScheduledTask -TaskName $TaskName -ErrorAction SilentlyContinue).State
        if ([string]$state -eq $ExpectedState) { return $true }
        Start-Sleep -Milliseconds 300
    }
    return $false
}

function Write-TestSummaryAndExit {
    param([Parameter(Mandatory = $true)][string]$SuiteName)



    Write-Host ''
    foreach ($failure in $Global:TestFailures) {
        Write-Host "FAIL: $failure" -ForegroundColor Red
    }
    Write-Host ''
    $failCount = $Global:TestFailures.Count
    $color = if ($failCount -eq 0) { 'Green' } else { 'Red' }
    Write-Host "[$SuiteName] $($Global:TestPassCount) passed, $failCount failed." -ForegroundColor $color

    if ($failCount -gt 0) { exit 1 }
    exit 0
}

<#
.SYNOPSIS
    Locates a bash.exe that can actually run the POSIX test scripts.

    `Get-Command bash.exe` on Windows commonly resolves to the WSL shim
    (C:\Windows\System32\bash.exe), which runs inside the WSL Linux
    environment and has NO access to the Windows filesystem - every script
    invocation then fails with exit 127. Git Bash's bash.exe
    (C:\Program Files\Git\bin\bash.exe) is a native Windows binary that
    reads C:/... and /c/... paths correctly. Prefer known Git install
    paths; accept a PATH-discovered bash only when it is not the WSL shim.
#>
function Get-GitBashPath {
    foreach ($candidate in @(
        'C:\Program Files\Git\bin\bash.exe',
        'C:\Program Files (x86)\Git\bin\bash.exe'
    )) {
        if (Test-Path -LiteralPath $candidate) { return $candidate }
    }
    $cmd = Get-Command bash.exe -ErrorAction SilentlyContinue
    if ($cmd -and $cmd.Source -notmatch '\\Windows\\(System32|SysWOW64)\\') {
        return $cmd.Source
    }
    return $null
}

<#
.SYNOPSIS
    Like New-TestTempDirectory, but under a LONG-form temp path.

    $env:TEMP often carries the 8.3 short segment (e.g. C:\Users\FARSHA~1\...)
    while $env:USERPROFILE is long (C:\Users\Farshad-IT\...). Git Bash/MSYS2
    path handling becomes inconsistent when a path mixes the short 8.3 name:
    unzip -d writes into a differently-resolved directory, so activate-release.sh
    never finds composer.json in staging, silently skips the gate, and swaps an
    empty tree. Resolving the temp dir through the long USERPROFILE keeps every
    path in one consistent namespace.
#>
function New-LongTempDirectory {
    param([Parameter(Mandatory = $false)][string]$Prefix = 'sdtest')
    $base = $env:TEMP
    if ($env:USERPROFILE -and $env:USERPROFILE -notmatch '~') {
        $candidate = Join-Path $env:USERPROFILE 'AppData\Local\Temp'
        if (Test-Path -LiteralPath $candidate) { $base = $candidate }
    }
    $dir = Join-Path $base "$Prefix-$([System.Guid]::NewGuid().ToString('N').Substring(0,10))"
    New-Item -ItemType Directory -Path $dir -Force | Out-Null
    return $dir
}