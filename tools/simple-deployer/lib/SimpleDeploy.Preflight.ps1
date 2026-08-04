<#
.SYNOPSIS
    Preflight checks — every one of them must pass before ANY change is
    made for a given project. A failed preflight skips that project with
    a clear Persian message; it never partially proceeds.
#>

Set-StrictMode -Version Latest

<#
.SYNOPSIS
    A single, process-wide (not just this tool's own instances — any
    process on the machine) named Mutex, so two SimpleDeploy invocations
    can never run concurrently. Call Exit-SimpleDeployLock in a `finally`
    to release it — .NET releases it automatically if the process dies
    without doing so, so a crash can never leave a permanently stuck lock.

.OUTPUTS
    The acquired Mutex object, or $null if another instance already holds it.
#>
function Enter-SimpleDeployLock {
    param(
        [Parameter(Mandatory = $false)][string]$Name = 'Global\SimpleDeployerSingleInstance',
        [Parameter(Mandatory = $false)][int]$WaitMilliseconds = 1000
    )

    $createdNew = $false
    $mutex = New-Object System.Threading.Mutex($true, $Name, [ref]$createdNew)
    if ($createdNew) {
        return $mutex
    }

    $acquired = $mutex.WaitOne($WaitMilliseconds)
    if ($acquired) {
        return $mutex
    }

    $mutex.Dispose()
    return $null
}

function Exit-SimpleDeployLock {
    param([Parameter(Mandatory = $true)]$Mutex)
    try {
        $Mutex.ReleaseMutex()
    } catch {
        # Already released or abandoned — never fatal at cleanup time.
    }
    $Mutex.Dispose()
}

<#
.OUTPUTS
    Hashtable: @{ Passed (bool); Failures (string[]) }
#>
function Test-ProjectPreflight {
    param(
        [Parameter(Mandatory = $true)][string]$ProjectKey,
        [Parameter(Mandatory = $true)]$ProjectConfig,
        [Parameter(Mandatory = $true)][string[]]$DeploymentProcessNames,
        [Parameter(Mandatory = $true)][string]$DeploymentProcessMarker
    )

    $failures = New-Object System.Collections.Generic.List[string]
    $localPath = [string]$ProjectConfig.local_path

    if (-not (Test-Path -LiteralPath $localPath -PathType Container)) {
        $failures.Add("پوشه پروژه پیدا نشد: $localPath")
        return @{ Passed = $false; Failures = $failures.ToArray() }
    }

    if (-not (Test-Path -LiteralPath (Join-Path $localPath '.git') -PathType Container)) {
        $failures.Add("مسیر '$localPath' یک Git repository نیست (پوشه .git پیدا نشد).")
        return @{ Passed = $false; Failures = $failures.ToArray() }
    }

    try {
        $currentBranch = Get-CurrentGitBranch -RepositoryPath $localPath
        if ($currentBranch -ne $ProjectConfig.branch) {
            $failures.Add("branch فعلی '$currentBranch' است، در حالی که در پیکربندی '$($ProjectConfig.branch)' انتظار می‌رفت.")
        }
    } catch {
        $failures.Add("خواندن branch فعلی با خطا مواجه شد: $($_.Exception.Message)")
    }

    try {
        if (-not (Test-GitRemoteExists -RepositoryPath $localPath -RemoteName $ProjectConfig.remote)) {
            $failures.Add("remote با نام '$($ProjectConfig.remote)' در این پروژه پیدا نشد.")
        }
    } catch {
        $failures.Add("بررسی remote با خطا مواجه شد: $($_.Exception.Message)")
    }

    if (-not (Get-Command git.exe -ErrorAction SilentlyContinue)) {
        $failures.Add('git.exe در PATH این سیستم پیدا نشد.')
    }

    $buildMode = [string]$ProjectConfig.build_mode
    if ($buildMode -ne 'disabled' -and $buildMode -ne 'git-only') {
        if (-not (Get-Command ssh.exe -ErrorAction SilentlyContinue)) {
            $failures.Add('ssh.exe در PATH این سیستم پیدا نشد (OpenSSH Client ویندوز را نصب/فعال کنید).')
        }
        if (-not (Get-Command scp.exe -ErrorAction SilentlyContinue)) {
            $failures.Add('scp.exe در PATH این سیستم پیدا نشد.')
        }
    }

    # Deliberately NOT a hard preflight failure: Get-ProjectDeployability
    # (server_host/user/key placeholders, build_mode=disabled, an invalid
    # retention_count, etc.) governs whether the BUILD/TRANSFER/ACTIVATE
    # stages may run — Invoke-ProjectDeploy already checks IsDeployable
    # separately, AFTER commit+push, and skips only those later stages.
    # Treating it as a preflight failure here would block git status/
    # commit/push too, defeating the explicit design goal (see
    # config.json's own sandogh-family-fund entry) that an
    # not-yet-fully-configured project should still get its local commits
    # pushed — only the actual deploy to a server it can't yet reach
    # should be withheld.

    # Belt-and-suspenders on top of the global Suspend-DeploymentService
    # call already made before any project's preflight runs — confirms
    # nothing matching the Deployment Service reappeared in the meantime
    # (e.g. Task Scheduler's own retry policy) right before this specific
    # project's build starts.
    $wait = Wait-ForDeploymentProcessesToExit -ProcessNames $DeploymentProcessNames -CommandLineMarker $DeploymentProcessMarker -TimeoutSeconds 0
    if (-not $wait.Success) {
        $pids = ($wait.RemainingProcesses | ForEach-Object { $_.ProcessId }) -join ', '
        $failures.Add("Deployment Agent هنوز در حال اجراست (PID: $pids) — تا پایان آن، Deploy دستی انجام نمی‌شود.")
    }

    return @{ Passed = ($failures.Count -eq 0); Failures = $failures.ToArray() }
}
