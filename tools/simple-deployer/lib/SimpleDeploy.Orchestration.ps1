<#
.SYNOPSIS
    Runs the full per-project pipeline: preflight -> git status/sensitive
    scan -> show changes -> commit confirmation -> commit+push -> (if
    deployable) build -> transfer -> activate -> report. One project's
    failure never aborts another's — the caller (SimpleDeploy.ps1) calls
    this once per selected project and collects each result independently.
#>

Set-StrictMode -Version Latest

function Resolve-CommandPrefix {
    param(
        [Parameter(Mandatory = $false)][AllowNull()]$ConfiguredValue,
        [Parameter(Mandatory = $true)][string]$DefaultExecutable,
        [Parameter(Mandatory = $false)][AllowNull()][string]$PhpBinary
    )

    $prefix = $null
    if ($ConfiguredValue) {
        $prefix = @($ConfiguredValue | Where-Object { $_ })
    }
    if (-not $prefix -or $prefix.Count -eq 0) {
        $prefix = @($DefaultExecutable)
    }

    if ($PhpBinary -and $prefix[0].ToLowerInvariant().EndsWith('.phar')) {
        $prefix = @($PhpBinary) + $prefix
    }

    return $prefix
}

<#
.SYNOPSIS
    Parses the two additive "[TIMING-MARK] health_check_start_ms=... /
    health_check_end_ms=..." lines activate-release.sh prints around its
    own health-check block (see that script's own comment) out of the
    combined stdout of the ONE ssh command that ran the whole activation.
    Never throws — a missing/unparseable marker (e.g. the remote script
    exited before printing the end marker) just means the health-check
    portion can't be isolated from the rest of "SSH activation" this
    time; the caller already has the correct combined total either way.

.OUTPUTS
    Hashtable: @{ HealthCheckMs (double or $null) }
#>
function Get-HealthCheckDurationFromActivationOutput {
    param([Parameter(Mandatory = $false)][AllowEmptyString()][string]$StdOut)

    if ([string]::IsNullOrEmpty($StdOut)) { return @{ HealthCheckMs = $null } }

    $startMatch = [regex]::Match($StdOut, 'health_check_start_ms=(\d+)')
    $endMatch = [regex]::Match($StdOut, 'health_check_end_ms=(\d+)')
    if (-not $startMatch.Success -or -not $endMatch.Success) { return @{ HealthCheckMs = $null } }

    $startMs = [double]$startMatch.Groups[1].Value
    $endMs = [double]$endMatch.Groups[1].Value
    $delta = $endMs - $startMs
    if ($delta -lt 0) { return @{ HealthCheckMs = $null } }
    return @{ HealthCheckMs = $delta }
}

<#
.SYNOPSIS
    Stage 1 of the split pipeline (Task 4A): preflight -> git status/
    sensitive scan -> show changes -> commit confirmation -> commit+push
    -> (if deployable and build_mode != 'disabled') build ONE artifact.
    Never transfers or activates anything on any server — that is
    Invoke-ArtifactToTarget's job. Kept as a single function rather than
    splitting further: every one of this function's own early-return
    points (no changes, sensitive file, user cancels, build_mode
    disabled, not yet deployable) is a genuine STOP for the whole
    pipeline, not a boundary between two stages that both run anyway.

.OUTPUTS
    Hashtable: @{
        Key; Status ('Skipped'|'DryRun'|'Succeeded'|'BuiltArtifact'|'Failed');
        CommitSha; Reason;
        Artifact  (only when Status='BuiltArtifact': @{Path;ChecksumSha256;SizeBytes});
        Workspace (only when Status='BuiltArtifact': @{Root;ProjectDir;BaseDir} —
            ownership of cleaning this up (Remove-DirectoryTreeGuarded
            -Path Workspace.Root -ExpectedFloor Workspace.BaseDir) now
            belongs to the CALLER, once every Target that needs the
            Artifact has been attempted — never cleaned up here on the
            success path, since the artifact file itself still needs to
            exist afterward)
    }

    Status='Succeeded' here means EXACTLY what it always meant on this
    return path before this split: git push completed but no server
    deploy happens this call (not yet deployable, build_mode=disabled,
    or the user declined the deploy confirmation) — never confused with
    a Target actually being deployed, which Invoke-ArtifactToTarget
    reports independently.
#>
function Invoke-ApplicationBuild {
    param(
        [Parameter(Mandatory = $true)]$ProjectInfo,
        [Parameter(Mandatory = $false)][string]$CommitMessage,
        [Parameter(Mandatory = $true)][bool]$NonInteractive,
        [Parameter(Mandatory = $true)][bool]$DryRun,
        [Parameter(Mandatory = $true)]$DeploymentServiceCfg,
        [Parameter(Mandatory = $true)][string]$ScriptRoot,
        # Optional shared timing report — $null (the default) means this
        # call's own stages simply aren't recorded into any report,
        # exactly the right behavior for the multi-target fan-out path
        # (Invoke-ApplicationDeployToTargets), which times the Build
        # stage separately from each Target's own SCP/SSH stages to
        # avoid stage-name collisions across Targets. The single-Target
        # backward-compat wrapper (Invoke-ProjectDeploy) passes its own
        # report here, reproducing the exact combined report it always
        # printed.
        [Parameter(Mandatory = $false)]$TimingReport = $null,
        # Optional runtime-only (no DB) Deployment State Observability
        # RunState (see SimpleDeploy.DeploymentState.ps1) — $null (the
        # default) means this call simply emits no events, exactly like
        # -TimingReport above. Every Add-DeploymentRunEvent call below is
        # guarded the same way -TimingReport's Add-DeployStageTiming
        # calls already are.
        [Parameter(Mandatory = $false)]$RunState = $null
    )

    $key = $ProjectInfo.Key
    $cfg = $ProjectInfo.Config

    Write-Step "پردازش پروژه: $key"

    if ($RunState) { Add-DeploymentRunEvent -RunState $RunState -EventName 'PREFLIGHT_STARTED' }
    Write-DeployStageStartMarker -StageName 'Preflight' -ToolRoot $ScriptRoot
    $preflightStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
    $preflight = Test-ProjectPreflight -ProjectKey $key -ProjectConfig $cfg `
        -DeploymentProcessNames $DeploymentServiceCfg.windows_process_names `
        -DeploymentProcessMarker $DeploymentServiceCfg.windows_process_commandline_marker
    if ($TimingReport) { Add-DeployStageTiming -Report $TimingReport -StageName 'Preflight' -Stopwatch $preflightStopwatch -ToolRoot $ScriptRoot }
    if (-not $preflight.Passed) {
        foreach ($f in $preflight.Failures) { Write-ErrorFa $f }
        return @{ Key = $key; Status = 'Skipped'; CommitSha = $null; Reason = 'Preflight ناموفق بود.'; Artifact = $null; Workspace = $null }
    }
    Write-Success 'Preflight موفق بود.'
    if ($RunState) { Add-DeploymentRunEvent -RunState $RunState -EventName 'PREFLIGHT_COMPLETED' }

    if ($RunState) { Add-DeploymentRunEvent -RunState $RunState -EventName 'GIT_STARTED' }
    Write-DeployStageStartMarker -StageName 'Git status' -ToolRoot $ScriptRoot
    $gitStatusStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
    $statusLines = Get-GitStatusShort -RepositoryPath $cfg.local_path
    if ($TimingReport) { Add-DeployStageTiming -Report $TimingReport -StageName 'Git status' -Stopwatch $gitStatusStopwatch -ToolRoot $ScriptRoot }
    if (@($statusLines).Count -eq 0) {
        Write-Info 'هیچ تغییری در working tree پیدا نشد.'
        return @{ Key = $key; Status = 'Skipped'; CommitSha = $null; Reason = 'بدون تغییر.'; Artifact = $null; Workspace = $null }
    }

    Write-Info "تغییرات ($(@($statusLines).Count) مورد):"
    foreach ($line in $statusLines) { Write-Host "      $line" }

    $sensitive = Test-SensitiveFiles -RepositoryPath $cfg.local_path -StatusLines $statusLines
    if (@($sensitive.BlockedFiles).Count -gt 0) {
        foreach ($f in $sensitive.BlockedFiles) { Write-ErrorFa "فایل حساس/مسدود (commit نخواهد شد): $f" }
        return @{ Key = $key; Status = 'Skipped'; CommitSha = $null; Reason = 'فایل حساس در تغییرات پیدا شد.'; Artifact = $null; Workspace = $null }
    }
    if (@($sensitive.LargeFiles).Count -gt 0) {
        foreach ($f in $sensitive.LargeFiles) { Write-Warn "فایل حجیم: $f" }
    }

    $pathsToStage = New-Object System.Collections.Generic.List[string]
    foreach ($line in $statusLines) {
        foreach ($p in (ConvertFrom-GitStatusLine -Line $line)) { $pathsToStage.Add($p) }
    }
    $pathsToStage = $pathsToStage | Select-Object -Unique

    if ($DryRun) {
        Write-Info 'DRY-RUN: این فایل‌ها commit/push می‌شدند:'
        foreach ($p in $pathsToStage) { Write-Host "      $p" }
        Write-Info "DRY-RUN: build_mode='$($cfg.build_mode)'  activation_mode='$($cfg.activation_mode)'"
        if (-not $ProjectInfo.IsDeployable) {
            Write-Warn 'این پروژه هنوز برای Deploy واقعی (build/transfer/activate) پیکربندی کامل ندارد:'
            foreach ($r in $ProjectInfo.BlockingReasons) { Write-Warn "  - $r" }
        } else {
            $dryRunPort = if ($cfg.PSObject.Properties['server_port'] -and $cfg.server_port) { [int]$cfg.server_port } else { 22 }
            Write-Info "DRY-RUN: در اجرای واقعی به $($cfg.server_user)@$($cfg.server_host):${dryRunPort}:$($cfg.server_path) منتقل و فعال می‌شد."
        }
        return @{ Key = $key; Status = 'DryRun'; CommitSha = $null; Reason = $null; Artifact = $null; Workspace = $null }
    }

    $effectiveMessage = $CommitMessage
    if (-not $effectiveMessage) {
        $suggested = "SimpleDeploy: به‌روزرسانی خودکار $(Get-Date -Format 'yyyy-MM-dd HH:mm')"
        if ($NonInteractive) {
            $effectiveMessage = $suggested
        } else {
            $typed = Read-Host "پیام commit را وارد کنید (خالی = «$suggested»)"
            $effectiveMessage = if ([string]::IsNullOrWhiteSpace($typed)) { $suggested } else { $typed }
        }
    }

    if (-not $NonInteractive) {
        $confirm = Read-Host 'آیا این تغییرات commit و push شوند؟ (y/N)'
        if ($confirm -notmatch '^(y|yes|بله)$') {
            return @{ Key = $key; Status = 'Skipped'; CommitSha = $null; Reason = 'توسط کاربر لغو شد (تأیید commit).'; Artifact = $null; Workspace = $null }
        }
    }

    $pushResult = Invoke-GitCommitAndPush -RepositoryPath $cfg.local_path -PathsToStage $pathsToStage -CommitMessage $effectiveMessage -RemoteName $cfg.remote -Branch $cfg.branch -TimingReport $TimingReport -ToolRoot $ScriptRoot
    $sha = $pushResult.CommitSha
    Write-Success "Push موفق بود. commit: $sha"
    if ($RunState) { Add-DeploymentRunEvent -RunState $RunState -EventName 'GIT_COMPLETED' }

    if (-not $ProjectInfo.IsDeployable) {
        Write-Warn 'تغییرات commit/push شدند اما این پروژه هنوز برای Deploy واقعی پیکربندی کامل ندارد — Deploy انجام نمی‌شود:'
        foreach ($r in $ProjectInfo.BlockingReasons) { Write-Warn "  - $r" }
        return @{ Key = $key; Status = 'Succeeded'; CommitSha = $sha; Reason = 'فقط git push انجام شد؛ Deploy تا تکمیل پیکربندی انجام نمی‌شود.'; Artifact = $null; Workspace = $null }
    }

    if ($cfg.build_mode -eq 'disabled') {
        return @{ Key = $key; Status = 'Succeeded'; CommitSha = $sha; Reason = 'build_mode=disabled — فقط git push انجام شد.'; Artifact = $null; Workspace = $null }
    }

    if (-not $NonInteractive) {
        $confirmDeploy = Read-Host "آیا commit «$($sha.Substring(0,12))» روی سرور Deploy شود؟ (y/N)"
        if ($confirmDeploy -notmatch '^(y|yes|بله)$') {
            return @{ Key = $key; Status = 'Succeeded'; CommitSha = $sha; Reason = 'Push انجام شد؛ Deploy توسط کاربر لغو شد.'; Artifact = $null; Workspace = $null }
        }
    }

    if ($RunState) { Add-DeploymentRunEvent -RunState $RunState -EventName 'BUILD_STARTED' }
    Write-Step "Build ($($cfg.build_mode))"
    $jobId = (Get-Date -Format 'yyyyMMddHHmmss') + '-' + $sha.Substring(0, 12)
    $workspaceBase = Join-Path $env:TEMP 'SimpleDeployer\builds'
    $workspace = New-BuildWorkspace -BaseDir $workspaceBase -ProjectKey $key -JobId $jobId

    try {
        Write-DeployStageStartMarker -StageName 'Build artifact' -ToolRoot $ScriptRoot
        $buildArtifactStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
        # $cfg.PSObject.Properties[...] guard (not just $cfg.source_subdirectory)
        # because every project config that predates Task 4A/4B (i.e. every
        # real project except deployment-system today) has no such property
        # at all — under Set-StrictMode, reading a genuinely-absent
        # PSCustomObject property throws, exactly the same reason every
        # other optional field in this function (server_port,
        # remote_php_binary, ...) is already guarded this way.
        $sourceSubdirectory = if ($cfg.PSObject.Properties['source_subdirectory']) { $cfg.source_subdirectory } else { $null }
        Export-GitArchiveToWorkspace -RepositoryPath $cfg.local_path -CommitSha $sha -DestinationDir $workspace.ProjectDir -SourceSubdirectory $sourceSubdirectory
        if ($TimingReport) { Add-DeployStageTiming -Report $TimingReport -StageName 'Build artifact' -Stopwatch $buildArtifactStopwatch -ToolRoot $ScriptRoot }
        Write-Success 'کد دقیقاً از همان commit استخراج شد (working tree اصلی دست‌نخورده باقی ماند).'

        if ($cfg.build_mode -eq 'artifact') {
            Resolve-ComposerPathDependencies -RepositoryPath $cfg.local_path -WorkspaceRoot $workspace.Root -WorkspaceProjectDir $workspace.ProjectDir

            if (Test-Path -LiteralPath (Join-Path $workspace.ProjectDir 'composer.json')) {
                $composerCommand = Resolve-CommandPrefix -ConfiguredValue $cfg.composer_command -DefaultExecutable 'composer' -PhpBinary $cfg.php_binary
                Write-DeployStageStartMarker -StageName 'Composer install' -ToolRoot $ScriptRoot
                $composerStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
                $composerRan = Invoke-IsolatedComposerInstall -WorkspaceProjectDir $workspace.ProjectDir -ComposerCommand $composerCommand
                if ($TimingReport) { Add-DeployStageTiming -Report $TimingReport -StageName 'Composer install' -Stopwatch $composerStopwatch -ToolRoot $ScriptRoot }
                if ($composerRan.Ran) { Write-Success 'composer install (ایزوله، --no-scripts) کامل شد.' }
            }

            if (Test-Path -LiteralPath (Join-Path $workspace.ProjectDir 'package.json')) {
                $npmCommand = Resolve-CommandPrefix -ConfiguredValue $cfg.npm_command -DefaultExecutable 'npm'
                Write-DeployStageStartMarker -StageName 'npm build' -ToolRoot $ScriptRoot
                $npmStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
                $npmRan = Invoke-IsolatedNpmBuild -WorkspaceProjectDir $workspace.ProjectDir -NpmCommand $npmCommand
                if ($TimingReport) { Add-DeployStageTiming -Report $TimingReport -StageName 'npm build' -Stopwatch $npmStopwatch -ToolRoot $ScriptRoot }
                if ($npmRan.RanBuild) { Write-Success 'npm run build (ایزوله) کامل شد.' }
            }
        } elseif ($cfg.build_mode -eq 'files-only') {
            Write-Info 'build_mode=files-only — فقط فایل‌های working tree فعلی (بدون build) بسته‌بندی می‌شوند.'
        }
        # git-only: the git-archived tracked source alone (already in the
        # workspace) is exactly what gets packaged — no extra step needed.

        if ($RunState) { Add-DeploymentRunEvent -RunState $RunState -EventName 'BUILD_COMPLETED' }

        # $cfg.PSObject.Properties[...] guard — same reasoning as
        # source_subdirectory above: artifact_validation is absent on
        # every pre-existing config.json entry, so this is a no-op
        # (Test-ArtifactValidation short-circuits Passed=$true) for every
        # project that hasn't opted in yet.
        $artifactValidationRules = if ($cfg.PSObject.Properties['artifact_validation']) { $cfg.artifact_validation } else { $null }
        if ($RunState) { Add-DeploymentRunEvent -RunState $RunState -EventName 'ARTIFACT_VALIDATION_STARTED' }
        Write-DeployStageStartMarker -StageName 'Artifact validation' -ToolRoot $ScriptRoot
        $artifactValidationStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
        $artifactValidationResult = Test-ArtifactValidation -ArtifactRoot $workspace.ProjectDir -ArtifactValidation $artifactValidationRules
        if ($TimingReport) { Add-DeployStageTiming -Report $TimingReport -StageName 'Artifact validation' -Stopwatch $artifactValidationStopwatch -ToolRoot $ScriptRoot }
        if (-not $artifactValidationResult.Passed) {
            $reasonText = "اعتبارسنجی Artifact ناموفق بود:`n" + (($artifactValidationResult.Failures | ForEach-Object { "  - $_" }) -join "`n")
            throw $reasonText
        }
        Write-Success 'اعتبارسنجی Artifact موفق بود.'
        if ($RunState) { Add-DeploymentRunEvent -RunState $RunState -EventName 'ARTIFACT_VALIDATION_COMPLETED' }

        $artifactPath = Join-Path $workspace.Root 'artifact.zip'
        Write-DeployStageStartMarker -StageName 'Artifact packaging' -ToolRoot $ScriptRoot
        $packagingStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
        $artifact = New-DeploymentArtifact -WorkspaceProjectDir $workspace.ProjectDir -OutputZipPath $artifactPath
        if ($TimingReport) { Add-DeployStageTiming -Report $TimingReport -StageName 'Artifact packaging' -Stopwatch $packagingStopwatch -ToolRoot $ScriptRoot }
        Write-Success "Artifact ساخته شد ($([math]::Round($artifact.SizeBytes / 1KB, 1)) KB، checksum $($artifact.ChecksumSha256.Substring(0,16))...)"

        return @{
            Key = $key; Status = 'BuiltArtifact'; CommitSha = $sha; Reason = $null
            Artifact = $artifact
            Workspace = @{ Root = $workspace.Root; ProjectDir = $workspace.ProjectDir; BaseDir = $workspaceBase }
        }
    } catch {
        try {
            Remove-DirectoryTreeGuarded -Path $workspace.Root -ExpectedFloor $workspaceBase
        } catch {
            Write-Warn "پاک‌سازی workspace موقت ناموفق بود: $($_.Exception.Message)"
        }
        return @{ Key = $key; Status = 'Failed'; CommitSha = $sha; Reason = $_.Exception.Message; Artifact = $null; Workspace = $null }
    }
}

<#
.SYNOPSIS
    Stage 2 of the split pipeline (Task 4A): transfers an ALREADY-BUILT
    artifact (see Invoke-ApplicationBuild) to ONE Deployment Target and
    activates it there — the same two Invoke-ScpUpload calls + one
    Invoke-SshRemoteCommand call this always was, just reading
    server_host/server_port/server_user/server_path/ssh_key_path/
    health_check_url/remote_php_binary from -Target instead of a
    project's own flat config. lib/SimpleDeploy.Transfer.ps1 itself
    needed ZERO changes for this — it already took these as explicit
    parameters (confirmed in the Task 3 audit). Never builds anything
    and never touches any OTHER Target — calling this once per Target,
    in a loop, with the SAME Artifact/CommitSha, IS the "one Build ->
    many Targets" fan-out (see Invoke-ApplicationDeployToTargets).

.PARAMETER ActivationSettings
    Hashtable: @{ ActivationMode; PreservePaths; RetentionCount;
    RunMigrations; DatabaseBackup } — per the Task 3 schema design these
    come from the Application Profile (they describe how THIS
    Application's release mechanics work), not from the Target, but are
    needed here because they are part of activate-release.sh's own
    command line.

.OUTPUTS
    Hashtable: @{ TargetKey; Status ('Succeeded'|'Failed'); Reason;
    HealthCheckMs (double or $null) } — always returned, even on
    failure; never throws, so a caller looping over multiple Targets
    never needs its own try/catch per Target.
#>
function Invoke-ArtifactToTarget {
    param(
        [Parameter(Mandatory = $true)]$Artifact,
        [Parameter(Mandatory = $true)][string]$CommitSha,
        [Parameter(Mandatory = $true)]$Target,
        [Parameter(Mandatory = $true)]$ActivationSettings,
        [Parameter(Mandatory = $true)]$SshCfg,
        [Parameter(Mandatory = $true)][string]$ScriptRoot,
        [Parameter(Mandatory = $false)]$TimingReport = $null,
        [Parameter(Mandatory = $false)][scriptblock]$CancellationCheck = $null,
        # Optional runtime-only RunState (see SimpleDeploy.DeploymentState.ps1)
        # -- $null (the default) means no events are emitted, exactly like
        # -TimingReport. Guarded the same way at every call site below.
        [Parameter(Mandatory = $false)]$RunState = $null
    )

    $targetKey = $Target.TargetKey
    $rollbackInfo = $null
    try {
        $knownHostsPath = Get-KnownHostsPath -ConfiguredPath $SshCfg.known_hosts_path
        $jobId = (Get-Date -Format 'yyyyMMddHHmmss') + '-' + $CommitSha.Substring(0, 12)
        # TargetKey (not just JobId) in the remote temp filenames — unlike
        # the old single-Target path, two Targets can now genuinely be
        # processed for the SAME commit in the same run; including
        # TargetKey guarantees their remote temp paths never collide even
        # if both happen to land on the very same server within the same
        # second.
        $remoteTempArchive = "/tmp/simpledeploy-$($Target.ApplicationKey)-${targetKey}-${jobId}.zip"
        $remoteTempScript = "/tmp/simpledeploy-activate-$($Target.ApplicationKey)-${targetKey}-${jobId}.sh"
        $serverPort = if ($Target.ServerPort) { [int]$Target.ServerPort } else { 22 }

        # All optional, all with the exact same safe defaults
        # Get-ScpTransferTimeoutSeconds/Invoke-ScpUpload themselves already
        # default to — reading them from $SshCfg only when a real config
        # actually sets them (every pre-existing config.json has none of
        # these fields yet) means a config that predates this fix behaves
        # identically to one that explicitly writes out these same default
        # numbers.
        $serverAliveIntervalSeconds = if ($SshCfg.PSObject.Properties['server_alive_interval_seconds'] -and $SshCfg.server_alive_interval_seconds) { [int]$SshCfg.server_alive_interval_seconds } else { 15 }
        $serverAliveCountMax = if ($SshCfg.PSObject.Properties['server_alive_count_max'] -and $SshCfg.server_alive_count_max) { [int]$SshCfg.server_alive_count_max } else { 4 }
        $tcpKeepAlive = if ($SshCfg.PSObject.Properties['tcp_keepalive']) { [bool]$SshCfg.tcp_keepalive } else { $true }
        $transferBaseTimeoutSeconds = if ($SshCfg.PSObject.Properties['transfer_base_timeout_seconds'] -and $SshCfg.transfer_base_timeout_seconds) { [int]$SshCfg.transfer_base_timeout_seconds } else { 300 }
        $transferSecondsPerMB = if ($SshCfg.PSObject.Properties['transfer_seconds_per_mb'] -and $SshCfg.transfer_seconds_per_mb) { [double]$SshCfg.transfer_seconds_per_mb } else { 30 }
        $transferMaxTimeoutSeconds = if ($SshCfg.PSObject.Properties['transfer_max_timeout_seconds'] -and $SshCfg.transfer_max_timeout_seconds) { [int]$SshCfg.transfer_max_timeout_seconds } else { 1800 }
        $artifactTransferTimeout = Get-ScpTransferTimeoutSeconds -SizeBytes $Artifact.SizeBytes -BaseSeconds $transferBaseTimeoutSeconds -SecondsPerMB $transferSecondsPerMB -MaxSeconds $transferMaxTimeoutSeconds

        $onTransferLog = { param($msg) Write-Info $msg }

        if ($RunState) { Add-DeploymentRunEvent -RunState $RunState -EventName 'TRANSFER_STARTED' -Target $targetKey }
        Write-Step "انتقال به سرور ($targetKey)"
        Write-DeployStageStartMarker -StageName 'SCP transfer' -ToolRoot $ScriptRoot
        $scpArtifactStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
        Invoke-ScpUpload -SshKeyPath $Target.SshKeyPath -KnownHostsPath $knownHostsPath -LocalFilePath $Artifact.Path `
            -ServerUser $Target.ServerUser -ServerHost $Target.ServerHost -RemoteDestinationPath $remoteTempArchive -ServerPort $serverPort `
            -TimeoutSeconds $artifactTransferTimeout -ExpectedChecksumSha256 $Artifact.ChecksumSha256 `
            -ServerAliveIntervalSeconds $serverAliveIntervalSeconds -ServerAliveCountMax $serverAliveCountMax -TcpKeepAlive $tcpKeepAlive `
            -CancellationCheck $CancellationCheck -OnLog $onTransferLog
        if ($TimingReport) { Add-DeployStageTiming -Report $TimingReport -StageName 'SCP transfer' -Stopwatch $scpArtifactStopwatch -ToolRoot $ScriptRoot }
        Write-Success 'انتقال Artifact کامل شد (اتمیک، حجم و checksum روی سرور تأیید شد).'

        $activateScriptLocal = Join-Path $ScriptRoot 'scripts\activate-release.sh'
        $scpScriptStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
        Invoke-ScpUpload -SshKeyPath $Target.SshKeyPath -KnownHostsPath $knownHostsPath -LocalFilePath $activateScriptLocal `
            -ServerUser $Target.ServerUser -ServerHost $Target.ServerHost -RemoteDestinationPath $remoteTempScript -ServerPort $serverPort `
            -ServerAliveIntervalSeconds $serverAliveIntervalSeconds -ServerAliveCountMax $serverAliveCountMax -TcpKeepAlive $tcpKeepAlive `
            -CancellationCheck $CancellationCheck -OnLog $onTransferLog
        if ($TimingReport) { Add-DeployStageTiming -Report $TimingReport -StageName 'SCP transfer' -Stopwatch $scpScriptStopwatch -ToolRoot $ScriptRoot }
        Write-Success 'انتقال اسکریپت فعال‌سازی کامل شد.'
        if ($RunState) { Add-DeploymentRunEvent -RunState $RunState -EventName 'TRANSFER_COMPLETED' -Target $targetKey }

        if ($RunState) { Add-DeploymentRunEvent -RunState $RunState -EventName 'ACTIVATION_STARTED' -Target $targetKey }
        Write-Step "فعال‌سازی روی سرور ($targetKey)"
        # --php-binary below is a LINUX server path (e.g. /usr/bin/php8.3,
        # read from Target.RemotePhpBinary) — deliberately separate from
        # the Application Profile's ComposerCommand/PhpBinary (WINDOWS
        # paths, used only for the local isolated build in
        # Invoke-ApplicationBuild). Conflating the two would send a
        # Windows path to the Linux `artisan migrate` invocation (or vice
        # versa) and fail outright.
        $preservePathsCsv = ($ActivationSettings.PreservePaths -join ',')
        $remoteCommandParts = @(
            'bash', (ConvertTo-PosixShellQuotedString $remoteTempScript),
            '--project-path', (ConvertTo-PosixShellQuotedString $Target.ServerPath),
            '--uploaded-archive', (ConvertTo-PosixShellQuotedString $remoteTempArchive),
            '--checksum', (ConvertTo-PosixShellQuotedString $Artifact.ChecksumSha256),
            '--commit-sha', (ConvertTo-PosixShellQuotedString $CommitSha),
            '--activation-mode', (ConvertTo-PosixShellQuotedString $ActivationSettings.ActivationMode),
            '--preserve-paths', (ConvertTo-PosixShellQuotedString $preservePathsCsv),
            '--retention-count', (ConvertTo-PosixShellQuotedString ([string]$ActivationSettings.RetentionCount)),
            '--run-migrations', (ConvertTo-PosixShellQuotedString $(if ($ActivationSettings.RunMigrations) { 'true' } else { 'false' })),
            '--php-binary', (ConvertTo-PosixShellQuotedString $(if ($Target.RemotePhpBinary) { $Target.RemotePhpBinary } else { 'php' })),
            '--health-check-url', (ConvertTo-PosixShellQuotedString $(if (Test-PlaceholderValue $Target.HealthCheckUrl) { '' } else { $Target.HealthCheckUrl })),
            '--database-backup', (ConvertTo-PosixShellQuotedString $(if ($ActivationSettings.DatabaseBackup) { 'true' } else { 'false' }))
        )
        $remoteCommand = ($remoteCommandParts -join ' ') + '; STATUS=$?; rm -f ' + (ConvertTo-PosixShellQuotedString $remoteTempArchive) + ' ' + (ConvertTo-PosixShellQuotedString $remoteTempScript) + '; exit $STATUS'

        Write-DeployStageStartMarker -StageName 'SSH activation' -ToolRoot $ScriptRoot
        $sshStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
        $activateResult = Invoke-SshRemoteCommand -SshKeyPath $Target.SshKeyPath -KnownHostsPath $knownHostsPath `
            -ServerUser $Target.ServerUser -ServerHost $Target.ServerHost -RemoteCommand $remoteCommand -ServerPort $serverPort -TimeoutSeconds 600
        $sshStopwatch.Stop()
        if (Get-Command Write-LauncherDebugLog -ErrorAction SilentlyContinue) {
            Write-LauncherDebugLog -ToolRoot $ScriptRoot -Marker 'STAGE END' -Detail "stage=SSH activation+HealthCheck (raw ssh call) target=$targetKey exitCode=$($activateResult.ExitCode) elapsedMs=$([math]::Round($sshStopwatch.Elapsed.TotalMilliseconds, 0))"
        }

        # Splits the one measured SSH wall-clock time into "SSH
        # activation" (connection + extract/swap/migrate/cache/queue/
        # permissions) and "Health check" using the [TIMING-MARK] lines
        # activate-release.sh prints around its own health-check block —
        # see Get-HealthCheckDurationFromActivationOutput. If the markers
        # aren't both present, the whole measured time is attributed to
        # "SSH activation" and "Health check" is simply not recorded this
        # run, rather than guessing.
        $healthCheckDuration = Get-HealthCheckDurationFromActivationOutput -StdOut $activateResult.StdOut
        $totalSshMs = $sshStopwatch.Elapsed.TotalMilliseconds
        if ($TimingReport) {
            if ($null -ne $healthCheckDuration.HealthCheckMs) {
                Add-DeployStageTiming -Report $TimingReport -StageName 'Health check' -ElapsedMs $healthCheckDuration.HealthCheckMs -ToolRoot $ScriptRoot
                Add-DeployStageTiming -Report $TimingReport -StageName 'SSH activation' -ElapsedMs ([Math]::Max(0, $totalSshMs - $healthCheckDuration.HealthCheckMs)) -ToolRoot $ScriptRoot
            } else {
                Add-DeployStageTiming -Report $TimingReport -StageName 'SSH activation' -ElapsedMs $totalSshMs -ToolRoot $ScriptRoot
            }
        }

        # Parses the 3 print-only "[STATE-MARK] ..." lines activate-
        # release.sh now emits around its own already-existing, unchanged
        # automatic rollback (perform_rollback) — purely observational,
        # never throws, never influences $activateResult.ExitCode below.
        $rollbackInfo = ConvertFrom-ActivationRollbackMarkers -StdOut $activateResult.StdOut

        if ($RunState) {
            Add-DeploymentRunEvent -RunState $RunState -EventName 'ACTIVATION_COMPLETED' -Target $targetKey
            if ($null -ne $healthCheckDuration.HealthCheckMs) {
                Add-DeploymentRunEvent -RunState $RunState -EventName 'HEALTHCHECK_STARTED' -Target $targetKey
                $healthCheckDetail = if ($rollbackInfo.RollbackCompleted) { "rollback_type=$($rollbackInfo.RollbackType)" } else { $null }
                Add-DeploymentRunEvent -RunState $RunState -EventName 'HEALTHCHECK_COMPLETED' -Target $targetKey -Detail $healthCheckDetail
            }
        }

        if ($activateResult.StdOut) { Write-Host $activateResult.StdOut }
        if ($activateResult.ExitCode -ne 0) {
            throw "فعال‌سازی روی سرور با خطا مواجه شد (exit $($activateResult.ExitCode)):`n$($activateResult.StdErr)"
        }
        Write-Success "Deploy برای '$targetKey' با موفقیت کامل شد."

        return @{ TargetKey = $targetKey; Status = 'Succeeded'; Reason = $null; HealthCheckMs = $healthCheckDuration.HealthCheckMs; RollbackInfo = $rollbackInfo }
    } catch {
        return @{ TargetKey = $targetKey; Status = 'Failed'; Reason = $_.Exception.Message; HealthCheckMs = $null; RollbackInfo = $rollbackInfo }
    }
}

<#
.SYNOPSIS
    The real "one Build -> Fan-out to many Targets" entry point: builds
    exactly ONE artifact (Invoke-ApplicationBuild), then calls
    Invoke-ArtifactToTarget once per Target, in order, continuing to the
    next Target even if an earlier one failed — the explicit safe
    default from the architecture: one Target's failure must never abort
    or corrupt the others, and each Target's result is reported
    independently. The shared build workspace is cleaned up exactly
    once, after every Target has been attempted — never per-Target.

.PARAMETER Targets
    Array of Target objects (ConvertTo-DeploymentTarget shape). If
    omitted or empty, defaults to the single legacy target derived from
    -ProjectInfo itself — so this function is already usable today for a
    "1 Application, 1 legacy Target" call with zero extra setup, not
    only once real multi-target Targets exist (e.g. from a future
    Registry).

.OUTPUTS
    Hashtable: @{ Key; BuildResult (Invoke-ApplicationBuild's own
    return value); TargetResults (array, empty if the build itself never
    reached Status='BuiltArtifact'); RunState (runtime-only Deployment
    State Observability object, see SimpleDeploy.DeploymentState.ps1 —
    always present); BatchState (per-Target state snapshot, $null if the
    build itself never reached Status='BuiltArtifact') } — RunState/
    BatchState are purely additive keys (this function's return shape
    was never documented as fixed/exact, unlike Invoke-ProjectDeploy's).
#>
function Invoke-ApplicationDeployToTargets {
    param(
        [Parameter(Mandatory = $true)]$ProjectInfo,
        [Parameter(Mandatory = $false)][array]$Targets = @(),
        [Parameter(Mandatory = $false)][string]$CommitMessage,
        [Parameter(Mandatory = $true)][bool]$NonInteractive,
        [Parameter(Mandatory = $true)][bool]$DryRun,
        [Parameter(Mandatory = $true)]$DeploymentServiceCfg,
        [Parameter(Mandatory = $true)]$SshCfg,
        [Parameter(Mandatory = $true)][string]$ScriptRoot
    )

    $key = $ProjectInfo.Key
    $cfg = $ProjectInfo.Config
    $totalStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
    $timing = New-DeployTimingReport
    $buildResult = $null

    # Runtime-only (no DB, no disk) Deployment State Observability layer
    # — see SimpleDeploy.DeploymentState.ps1's own .NOTES. $runState is
    # never $null here (unlike -TimingReport elsewhere), so every event
    # call below is unconditional; a caller that never reads $runState
    # back is completely unaffected, since nothing here changes based on
    # its contents.
    $runId = New-DeploymentRunId
    $runState = New-DeploymentRunState -RunId $runId -Application $key
    Add-DeploymentRunEvent -RunState $runState -EventName 'RUN_STARTED'

    try {
        $buildResult = Invoke-ApplicationBuild -ProjectInfo $ProjectInfo -CommitMessage $CommitMessage `
            -NonInteractive $NonInteractive -DryRun $DryRun -DeploymentServiceCfg $DeploymentServiceCfg `
            -ScriptRoot $ScriptRoot -TimingReport $timing -RunState $runState

        if ($buildResult.Status -ne 'BuiltArtifact') {
            Add-DeploymentRunEvent -RunState $runState -EventName $(if ($buildResult.Status -eq 'Failed') { 'RUN_FAILED' } else { 'RUN_COMPLETED' }) -Detail $buildResult.Status
            Format-DeploymentLiveReportBlock -RunState $runState
            return @{ Key = $key; BuildResult = $buildResult; TargetResults = @(); RunState = $runState; BatchState = $null }
        }

        $effectiveTargets = if ($Targets -and @($Targets).Count -gt 0) { @($Targets) } else { @(ConvertTo-DeploymentTarget -ApplicationKey $key -ProjectConfig $cfg) }
        $activationSettings = @{
            ActivationMode = $cfg.activation_mode
            PreservePaths  = $cfg.preserve_paths
            RetentionCount = $cfg.retention_count
            RunMigrations  = $cfg.run_migrations
            DatabaseBackup = $cfg.database_backup
        }

        # Runtime-only DeploymentBatch: one entry per Target, isolated
        # from every other Target (see Set-DeploymentTargetState's own
        # .NOTES) — never persisted, lives only for this function call.
        $targetKeys = @($effectiveTargets | ForEach-Object { $_.TargetKey })
        $batchState = New-DeploymentBatchState -RunId $runId -Application $key -Targets $targetKeys

        $targetResults = New-Object System.Collections.Generic.List[object]
        foreach ($target in $effectiveTargets) {
            Set-DeploymentTargetState -BatchState $batchState -Target $target.TargetKey -Stage 'in_progress'
            $r = Invoke-ArtifactToTarget -Artifact $buildResult.Artifact -CommitSha $buildResult.CommitSha `
                -Target $target -ActivationSettings $activationSettings -SshCfg $SshCfg -ScriptRoot $ScriptRoot -RunState $runState
            $targetResults.Add($r)
            Set-DeploymentTargetState -BatchState $batchState -Target $target.TargetKey `
                -Stage $(if ($r.Status -eq 'Succeeded') { 'completed' } else { 'failed' }) -Detail $r.Reason
            Format-DeploymentLiveReportBlock -RunState $runState -BatchState $batchState
            # Deliberately no early-exit/break here regardless of $r.Status
            # — one Target's failure must never prevent the remaining
            # Targets from being attempted.
        }

        # NOT `@($targetResults) | Where-Object ... | Select-Object -First 1`
        # -- confirmed empirically that wrapping a List[object] of
        # Hashtables in @() before that exact pipeline throws a genuine
        # PowerShell 5.1 "Argument types do not match" ArgumentException;
        # piping the List[object] directly (already enumerable) does not.
        $anyFailed = $targetResults | Where-Object { $_.Status -ne 'Succeeded' } | Select-Object -First 1
        Add-DeploymentRunEvent -RunState $runState -EventName $(if ($anyFailed) { 'RUN_FAILED' } else { 'RUN_COMPLETED' })

        return @{ Key = $key; BuildResult = $buildResult; TargetResults = $targetResults.ToArray(); RunState = $runState; BatchState = $batchState }
    } finally {
        if ($buildResult -and $buildResult.Workspace) {
            try {
                Remove-DirectoryTreeGuarded -Path $buildResult.Workspace.Root -ExpectedFloor $buildResult.Workspace.BaseDir
            } catch {
                Write-Warn "پاک‌سازی workspace موقت ناموفق بود: $($_.Exception.Message)"
            }
        }
        Write-DeployTimingReport -Report $timing -TotalMs $totalStopwatch.Elapsed.TotalMilliseconds
        if (Get-Command Write-LauncherDebugLog -ErrorAction SilentlyContinue) {
            Write-LauncherDebugLog -ToolRoot $ScriptRoot -Marker 'STAGE END' -Detail "stage=Invoke-ApplicationDeployToTargets(whole function) project=$key totalMs=$([math]::Round($totalStopwatch.Elapsed.TotalMilliseconds, 0))"
        }
    }
}

<#
.SYNOPSIS
    Backward-compatible entry point — EXACT same signature, EXACT same
    return shape (@{Key;Status;CommitSha;Reason}), and EXACTLY the same
    observable behavior as before the Task 4A Build/Deploy split: build
    once (Invoke-ApplicationBuild) then, if an artifact was actually
    built, deploy it to the ONE legacy Target implied by this project's
    own flat config (ConvertTo-DeploymentTarget) via
    Invoke-ArtifactToTarget. SimpleDeploy.ps1 (CLI) and
    Invoke-LauncherDeployFlow (Launcher) both call this exact function
    with this exact signature and are completely unaffected by the
    internal split.
#>
function Invoke-ProjectDeploy {
    param(
        [Parameter(Mandatory = $true)]$ProjectInfo,
        [Parameter(Mandatory = $false)][string]$CommitMessage,
        [Parameter(Mandatory = $true)][bool]$NonInteractive,
        [Parameter(Mandatory = $true)][bool]$DryRun,
        [Parameter(Mandatory = $true)]$DeploymentServiceCfg,
        [Parameter(Mandatory = $true)]$SshCfg,
        [Parameter(Mandatory = $true)][string]$ScriptRoot
    )

    $key = $ProjectInfo.Key
    $cfg = $ProjectInfo.Config

    # Performance timing layer (see SimpleDeploy.Timing.ps1) — purely
    # additive instrumentation; no stage is skipped, reordered, retried,
    # or given a different outcome/return value because of this. The
    # report prints via Write-Step/Write-Info in the `finally` below, so
    # it fires on every exit path (Skipped/DryRun/Succeeded/Failed
    # alike) and reaches the same console/GUI live-report output every
    # other line in this function already reaches — identical to before
    # this Task's Build/Deploy split.
    $totalStopwatch = [System.Diagnostics.Stopwatch]::StartNew()
    $timing = New-DeployTimingReport
    $buildResult = $null

    # Runtime-only (no DB, no disk) Deployment State Observability layer
    # — same as Invoke-ApplicationDeployToTargets above; this is the
    # actual CLI/GUI legacy entry point, so wiring it here too (beyond
    # the literal 3 functions named for Task 2) means every real Deploy
    # gets a Run ID and a live-state report, not only multi-Target ones.
    $runId = New-DeploymentRunId
    $runState = New-DeploymentRunState -RunId $runId -Application $key
    Add-DeploymentRunEvent -RunState $runState -EventName 'RUN_STARTED'

    try {
        $buildResult = Invoke-ApplicationBuild -ProjectInfo $ProjectInfo -CommitMessage $CommitMessage `
            -NonInteractive $NonInteractive -DryRun $DryRun -DeploymentServiceCfg $DeploymentServiceCfg `
            -ScriptRoot $ScriptRoot -TimingReport $timing -RunState $runState

        if ($buildResult.Status -ne 'BuiltArtifact') {
            Add-DeploymentRunEvent -RunState $runState -EventName $(if ($buildResult.Status -eq 'Failed') { 'RUN_FAILED' } else { 'RUN_COMPLETED' }) -Detail $buildResult.Status
            Format-DeploymentLiveReportBlock -RunState $runState
            return @{ Key = $key; Status = $buildResult.Status; CommitSha = $buildResult.CommitSha; Reason = $buildResult.Reason }
        }

        $target = ConvertTo-DeploymentTarget -ApplicationKey $key -ProjectConfig $cfg
        $activationSettings = @{
            ActivationMode = $cfg.activation_mode
            PreservePaths  = $cfg.preserve_paths
            RetentionCount = $cfg.retention_count
            RunMigrations  = $cfg.run_migrations
            DatabaseBackup = $cfg.database_backup
        }
        $deployResult = Invoke-ArtifactToTarget -Artifact $buildResult.Artifact -CommitSha $buildResult.CommitSha `
            -Target $target -ActivationSettings $activationSettings -SshCfg $SshCfg -ScriptRoot $ScriptRoot -TimingReport $timing -RunState $runState

        Add-DeploymentRunEvent -RunState $runState -EventName $(if ($deployResult.Status -eq 'Succeeded') { 'RUN_COMPLETED' } else { 'RUN_FAILED' })
        Format-DeploymentLiveReportBlock -RunState $runState

        if ($deployResult.Status -eq 'Succeeded') {
            return @{ Key = $key; Status = 'Succeeded'; CommitSha = $buildResult.CommitSha; Reason = $null }
        }
        return @{ Key = $key; Status = 'Failed'; CommitSha = $buildResult.CommitSha; Reason = $deployResult.Reason }
    } finally {
        if ($buildResult -and $buildResult.Workspace) {
            try {
                Remove-DirectoryTreeGuarded -Path $buildResult.Workspace.Root -ExpectedFloor $buildResult.Workspace.BaseDir
            } catch {
                Write-Warn "پاک‌سازی workspace موقت ناموفق بود: $($_.Exception.Message)"
            }
        }
        Write-DeployTimingReport -Report $timing -TotalMs $totalStopwatch.Elapsed.TotalMilliseconds
        if (Get-Command Write-LauncherDebugLog -ErrorAction SilentlyContinue) {
            Write-LauncherDebugLog -ToolRoot $ScriptRoot -Marker 'STAGE END' -Detail "stage=Invoke-ProjectDeploy(whole function) project=$key totalMs=$([math]::Round($totalStopwatch.Elapsed.TotalMilliseconds, 0))"
        }
    }
}
