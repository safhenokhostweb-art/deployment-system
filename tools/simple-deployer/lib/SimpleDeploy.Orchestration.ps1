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
    Write-Step "پردازش پروژه: $key"

    $preflight = Test-ProjectPreflight -ProjectKey $key -ProjectConfig $cfg `
        -DeploymentProcessNames $DeploymentServiceCfg.windows_process_names `
        -DeploymentProcessMarker $DeploymentServiceCfg.windows_process_commandline_marker
    if (-not $preflight.Passed) {
        foreach ($f in $preflight.Failures) { Write-ErrorFa $f }
        return @{ Key = $key; Status = 'Skipped'; CommitSha = $null; Reason = 'Preflight ناموفق بود.' }
    }
    Write-Success 'Preflight موفق بود.'

    $statusLines = Get-GitStatusShort -RepositoryPath $cfg.local_path
    if (@($statusLines).Count -eq 0) {
        Write-Info 'هیچ تغییری در working tree پیدا نشد.'
        return @{ Key = $key; Status = 'Skipped'; CommitSha = $null; Reason = 'بدون تغییر.' }
    }

    Write-Info "تغییرات ($(@($statusLines).Count) مورد):"
    foreach ($line in $statusLines) { Write-Host "      $line" }

    $sensitive = Test-SensitiveFiles -RepositoryPath $cfg.local_path -StatusLines $statusLines
    if (@($sensitive.BlockedFiles).Count -gt 0) {
        foreach ($f in $sensitive.BlockedFiles) { Write-ErrorFa "فایل حساس/مسدود (commit نخواهد شد): $f" }
        return @{ Key = $key; Status = 'Skipped'; CommitSha = $null; Reason = 'فایل حساس در تغییرات پیدا شد.' }
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
        return @{ Key = $key; Status = 'DryRun'; CommitSha = $null; Reason = $null }
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
            return @{ Key = $key; Status = 'Skipped'; CommitSha = $null; Reason = 'توسط کاربر لغو شد (تأیید commit).' }
        }
    }

    $pushResult = Invoke-GitCommitAndPush -RepositoryPath $cfg.local_path -PathsToStage $pathsToStage -CommitMessage $effectiveMessage -RemoteName $cfg.remote -Branch $cfg.branch
    $sha = $pushResult.CommitSha
    Write-Success "Push موفق بود. commit: $sha"

    if (-not $ProjectInfo.IsDeployable) {
        Write-Warn 'تغییرات commit/push شدند اما این پروژه هنوز برای Deploy واقعی پیکربندی کامل ندارد — Deploy انجام نمی‌شود:'
        foreach ($r in $ProjectInfo.BlockingReasons) { Write-Warn "  - $r" }
        return @{ Key = $key; Status = 'Succeeded'; CommitSha = $sha; Reason = 'فقط git push انجام شد؛ Deploy تا تکمیل پیکربندی انجام نمی‌شود.' }
    }

    if ($cfg.build_mode -eq 'disabled') {
        return @{ Key = $key; Status = 'Succeeded'; CommitSha = $sha; Reason = 'build_mode=disabled — فقط git push انجام شد.' }
    }

    if (-not $NonInteractive) {
        $confirmDeploy = Read-Host "آیا commit «$($sha.Substring(0,12))» روی سرور Deploy شود؟ (y/N)"
        if ($confirmDeploy -notmatch '^(y|yes|بله)$') {
            return @{ Key = $key; Status = 'Succeeded'; CommitSha = $sha; Reason = 'Push انجام شد؛ Deploy توسط کاربر لغو شد.' }
        }
    }

    Write-Step "Build ($($cfg.build_mode))"
    $jobId = (Get-Date -Format 'yyyyMMddHHmmss') + '-' + $sha.Substring(0, 12)
    $workspaceBase = Join-Path $env:TEMP 'SimpleDeployer\builds'
    $workspace = New-BuildWorkspace -BaseDir $workspaceBase -ProjectKey $key -JobId $jobId
    $remoteTempArchive = "/tmp/simpledeploy-${key}-${jobId}.zip"
    $remoteTempScript = "/tmp/simpledeploy-activate-${jobId}.sh"
    $knownHostsPath = Get-KnownHostsPath -ConfiguredPath $SshCfg.known_hosts_path

    try {
        Export-GitArchiveToWorkspace -RepositoryPath $cfg.local_path -CommitSha $sha -DestinationDir $workspace.ProjectDir
        Write-Success 'کد دقیقاً از همان commit استخراج شد (working tree اصلی دست‌نخورده باقی ماند).'

        if ($cfg.build_mode -eq 'artifact') {
            Resolve-ComposerPathDependencies -RepositoryPath $cfg.local_path -WorkspaceRoot $workspace.Root -WorkspaceProjectDir $workspace.ProjectDir

            if (Test-Path -LiteralPath (Join-Path $workspace.ProjectDir 'composer.json')) {
                $composerCommand = Resolve-CommandPrefix -ConfiguredValue $cfg.composer_command -DefaultExecutable 'composer' -PhpBinary $cfg.php_binary
                $composerRan = Invoke-IsolatedComposerInstall -WorkspaceProjectDir $workspace.ProjectDir -ComposerCommand $composerCommand
                if ($composerRan.Ran) { Write-Success 'composer install (ایزوله، --no-scripts) کامل شد.' }
            }

            if (Test-Path -LiteralPath (Join-Path $workspace.ProjectDir 'package.json')) {
                $npmCommand = Resolve-CommandPrefix -ConfiguredValue $cfg.npm_command -DefaultExecutable 'npm'
                $npmRan = Invoke-IsolatedNpmBuild -WorkspaceProjectDir $workspace.ProjectDir -NpmCommand $npmCommand
                if ($npmRan.RanBuild) { Write-Success 'npm run build (ایزوله) کامل شد.' }
            }
        } elseif ($cfg.build_mode -eq 'files-only') {
            Write-Info 'build_mode=files-only — فقط فایل‌های working tree فعلی (بدون build) بسته‌بندی می‌شوند.'
        }
        # git-only: the git-archived tracked source alone (already in the
        # workspace) is exactly what gets packaged — no extra step needed.

        $artifactPath = Join-Path $workspace.Root 'artifact.zip'
        $artifact = New-DeploymentArtifact -WorkspaceProjectDir $workspace.ProjectDir -OutputZipPath $artifactPath
        Write-Success "Artifact ساخته شد ($([math]::Round($artifact.SizeBytes / 1KB, 1)) KB، checksum $($artifact.ChecksumSha256.Substring(0,16))...)"

        # Not every server accepts SSH on the default port 22 (both real
        # VPS targets audited for this tool use 9011) — server_port is
        # optional in config.json and defaults to 22 only when genuinely
        # absent/unset, never silently assumed otherwise.
        $serverPort = if ($cfg.PSObject.Properties['server_port'] -and $cfg.server_port) { [int]$cfg.server_port } else { 22 }

        Write-Step 'انتقال به سرور'
        Invoke-ScpUpload -SshKeyPath $cfg.ssh_key_path -KnownHostsPath $knownHostsPath -LocalFilePath $artifact.Path `
            -ServerUser $cfg.server_user -ServerHost $cfg.server_host -RemoteDestinationPath $remoteTempArchive -ServerPort $serverPort `
            -TimeoutSeconds ([Math]::Max(300, [int]($artifact.SizeBytes / 1MB) * 5))
        Write-Success 'انتقال Artifact کامل شد.'

        $activateScriptLocal = Join-Path $ScriptRoot 'scripts\activate-release.sh'
        Invoke-ScpUpload -SshKeyPath $cfg.ssh_key_path -KnownHostsPath $knownHostsPath -LocalFilePath $activateScriptLocal `
            -ServerUser $cfg.server_user -ServerHost $cfg.server_host -RemoteDestinationPath $remoteTempScript -ServerPort $serverPort
        Write-Success 'انتقال اسکریپت فعال‌سازی کامل شد.'

        Write-Step 'فعال‌سازی روی سرور'
        # --php-binary below is a LINUX server path (e.g. /usr/bin/php8.3),
        # read from `remote_php_binary` — deliberately a SEPARATE config
        # field from `php_binary`/`composer_command`, which are WINDOWS
        # paths (e.g. C:\laragon\bin\php\...\php.exe) used only for the
        # local, isolated build step above. Conflating the two would send
        # a Windows path to the Linux `artisan migrate` invocation (or vice
        # versa) and fail outright on whichever machine got the wrong one.
        $preservePathsCsv = ($cfg.preserve_paths -join ',')
        $remoteCommandParts = @(
            'bash', (ConvertTo-PosixShellQuotedString $remoteTempScript),
            '--project-path', (ConvertTo-PosixShellQuotedString $cfg.server_path),
            '--uploaded-archive', (ConvertTo-PosixShellQuotedString $remoteTempArchive),
            '--checksum', (ConvertTo-PosixShellQuotedString $artifact.ChecksumSha256),
            '--commit-sha', (ConvertTo-PosixShellQuotedString $sha),
            '--activation-mode', (ConvertTo-PosixShellQuotedString $cfg.activation_mode),
            '--preserve-paths', (ConvertTo-PosixShellQuotedString $preservePathsCsv),
            '--retention-count', (ConvertTo-PosixShellQuotedString ([string]$cfg.retention_count)),
            '--run-migrations', (ConvertTo-PosixShellQuotedString $(if ($cfg.run_migrations) { 'true' } else { 'false' })),
            '--php-binary', (ConvertTo-PosixShellQuotedString $(if ($cfg.PSObject.Properties['remote_php_binary'] -and $cfg.remote_php_binary) { $cfg.remote_php_binary } else { 'php' })),
            '--health-check-url', (ConvertTo-PosixShellQuotedString $(if (Test-PlaceholderValue $cfg.health_check_url) { '' } else { $cfg.health_check_url })),
            '--database-backup', (ConvertTo-PosixShellQuotedString $(if ($cfg.database_backup) { 'true' } else { 'false' }))
        )
        $remoteCommand = ($remoteCommandParts -join ' ') + '; STATUS=$?; rm -f ' + (ConvertTo-PosixShellQuotedString $remoteTempArchive) + ' ' + (ConvertTo-PosixShellQuotedString $remoteTempScript) + '; exit $STATUS'

        $activateResult = Invoke-SshRemoteCommand -SshKeyPath $cfg.ssh_key_path -KnownHostsPath $knownHostsPath `
            -ServerUser $cfg.server_user -ServerHost $cfg.server_host -RemoteCommand $remoteCommand -ServerPort $serverPort -TimeoutSeconds 600

        if ($activateResult.StdOut) { Write-Host $activateResult.StdOut }
        if ($activateResult.ExitCode -ne 0) {
            throw "فعال‌سازی روی سرور با خطا مواجه شد (exit $($activateResult.ExitCode)):`n$($activateResult.StdErr)"
        }
        Write-Success 'Deploy با موفقیت کامل شد.'

        return @{ Key = $key; Status = 'Succeeded'; CommitSha = $sha; Reason = $null }
    } catch {
        return @{ Key = $key; Status = 'Failed'; CommitSha = $sha; Reason = $_.Exception.Message }
    } finally {
        try {
            Remove-DirectoryTreeGuarded -Path $workspace.Root -ExpectedFloor $workspaceBase
        } catch {
            Write-Warn "پاک‌سازی workspace موقت ناموفق بود: $($_.Exception.Message)"
        }
    }
}
