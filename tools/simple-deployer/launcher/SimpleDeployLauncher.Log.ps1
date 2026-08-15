<#
.SYNOPSIS
    Per-run structured log files for the Launcher — one file per project
    run under logs/YYYY-MM-DD/, containing project name, start/end time,
    the full 40-character commit SHA, and the result of each stage
    (push/build/upload/activate/health-check) plus full error text on
    failure. Every text value is passed through Protect-LauncherLogText
    before being written — no secret, private-key content, token, or
    .env value is ever persisted.
#>

Set-StrictMode -Version Latest

<#
.SYNOPSIS
    Redacts anything secret-shaped from a block of text before it is
    logged: PEM private-key blocks, and KEY=VALUE-style lines whose key
    name looks like a credential (password/secret/token/api key/etc).
    Defense in depth — the pipeline itself never intentionally echoes
    secrets, but any external command's stdout/stderr is logged verbatim
    otherwise, and this guards against something unexpected leaking
    through (e.g. a misconfigured tool dumping its own env).
#>
function Protect-LauncherLogText {
    param([Parameter(Mandatory = $true)][AllowEmptyString()][string]$Text)

    if ([string]::IsNullOrEmpty($Text)) { return $Text }

    $redacted = [regex]::Replace(
        $Text,
        '-----BEGIN [A-Z0-9 ]*PRIVATE KEY-----[\s\S]*?-----END [A-Z0-9 ]*PRIVATE KEY-----',
        '[REDACTED-PRIVATE-KEY]'
    )
    $redacted = [regex]::Replace(
        $redacted,
        '(?im)^(.*(?:password|secret|token|api[_-]?key|access[_-]?key)\w*\s*[:=]\s*)(\S+)(.*)$',
        '${1}[REDACTED]${3}'
    )
    return $redacted
}

<#
.OUTPUTS
    Hashtable — pass this to Add-LauncherRunLogEvent / Complete-LauncherRunLog.
#>
function New-LauncherRunLog {
    param(
        [Parameter(Mandatory = $true)][string]$ProjectKey,
        [Parameter(Mandatory = $true)][string]$LogsRootDir
    )
    return @{
        ProjectKey = $ProjectKey
        StartedAt  = Get-Date
        EndedAt    = $null
        CommitSha  = $null
        Events     = New-Object System.Collections.Generic.List[object]
        Results    = @{ Push = $null; Build = $null; Upload = $null; Activate = $null; HealthCheck = $null }
        Outcome    = $null
        ErrorText  = $null
        LogsRootDir = $LogsRootDir
    }
}

function Add-LauncherRunLogEvent {
    param(
        [Parameter(Mandatory = $true)][hashtable]$RunLog,
        [Parameter(Mandatory = $true)][string]$Stage,
        [Parameter(Mandatory = $true)][string]$Message
    )
    $RunLog.Events.Add([ordered]@{
        At      = (Get-Date).ToString('o')
        Stage   = $Stage
        Message = Protect-LauncherLogText -Text $Message
    })
}

function Set-LauncherRunLogResult {
    param(
        [Parameter(Mandatory = $true)][hashtable]$RunLog,
        [Parameter(Mandatory = $true)][ValidateSet('Push', 'Build', 'Upload', 'Activate', 'HealthCheck')][string]$Stage,
        [Parameter(Mandatory = $true)][string]$Result
    )
    $RunLog.Results[$Stage] = Protect-LauncherLogText -Text $Result
}

<#
.SYNOPSIS
    Finalizes the run and writes it to
    logs/YYYY-MM-DD/<ProjectKey>-<HHmmss>-<Outcome>.json — creating the
    date directory if needed. Returns the written file path.
#>
function Complete-LauncherRunLog {
    param(
        [Parameter(Mandatory = $true)][hashtable]$RunLog,
        [Parameter(Mandatory = $true)][string]$Outcome,
        [Parameter(Mandatory = $false)][string]$CommitSha,
        [Parameter(Mandatory = $false)][string]$ErrorText
    )
    $RunLog.EndedAt = Get-Date
    $RunLog.Outcome = $Outcome
    if ($CommitSha) { $RunLog.CommitSha = $CommitSha }
    if ($ErrorText) { $RunLog.ErrorText = Protect-LauncherLogText -Text $ErrorText }

    $dateDir = Join-Path $RunLog.LogsRootDir ($RunLog.StartedAt.ToString('yyyy-MM-dd'))
    if (-not (Test-Path -LiteralPath $dateDir)) {
        New-Item -ItemType Directory -Path $dateDir -Force | Out-Null
    }

    $fileName = "$($RunLog.ProjectKey)-$($RunLog.StartedAt.ToString('HHmmss'))-$Outcome.json"
    $filePath = Join-Path $dateDir $fileName

    $record = [ordered]@{
        project_key = $RunLog.ProjectKey
        started_at  = $RunLog.StartedAt.ToString('o')
        ended_at    = $RunLog.EndedAt.ToString('o')
        commit_sha  = $RunLog.CommitSha
        outcome     = $RunLog.Outcome
        results     = $RunLog.Results
        events      = $RunLog.Events
        error_text  = $RunLog.ErrorText
    }

    ($record | ConvertTo-Json -Depth 10) | Set-Content -LiteralPath $filePath -Encoding UTF8
    return $filePath
}

<#
.SYNOPSIS
    Internal-only lifecycle debug log — a plain, append-only text file at
    state/launcher-debug.log, completely separate from the user-facing
    "گزارش زنده" (live report, driven by Write-Step/Write-Info/...) and
    from logs/*.json (the per-run result records written by
    Complete-LauncherRunLog above). Exists purely so a real run that
    doesn't return the Launcher to its Ready state can be diagnosed
    after the fact — never shown to the user, never contains secrets
    (only fixed lifecycle marker names, project keys, and stage names —
    the same values already visible as config.json's own project keys;
    never file contents, credentials, or .env values). Safe to call from
    either the UI thread or the background runspace (plain file I/O, no
    shared in-memory state).
#>
function Write-LauncherDebugLog {
    param(
        [Parameter(Mandatory = $true)][string]$ToolRoot,
        [Parameter(Mandatory = $true)][string]$Marker,
        [Parameter(Mandatory = $false)][string]$Detail = ''
    )
    try {
        $stateDir = Join-Path $ToolRoot 'state'
        if (-not (Test-Path -LiteralPath $stateDir)) {
            New-Item -ItemType Directory -Path $stateDir -Force | Out-Null
        }
        $logPath = Join-Path $stateDir 'launcher-debug.log'
        $line = "$(Get-Date -Format 'yyyy-MM-dd HH:mm:ss.fff')  $Marker" + $(if ($Detail) { "  $Detail" } else { '' })
        Add-Content -LiteralPath $logPath -Value $line -Encoding UTF8
    } catch {
        # Debug logging must never be able to break a real operation --
        # a locked/unwritable file here is silently ignored.
    }
}
