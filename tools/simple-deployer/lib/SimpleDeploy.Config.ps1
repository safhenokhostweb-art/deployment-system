<#
.SYNOPSIS
    Config loading + per-project validation. A project whose deploy-critical
    fields (server_host/server_user/ssh_key_path, or branch/remote/server_path
    for a project that hasn't been audited) are still CHANGE_ME placeholders
    is marked NOT deployable — SimpleDeploy refuses to touch it beyond
    showing that fact, never guessing a value on the operator's behalf.
#>

Set-StrictMode -Version Latest

<#
.OUTPUTS
    Hashtable: @{ Key; Config; IsDeployable; BlockingReasons (string[]) }
#>
function Get-ProjectDeployability {
    param(
        [Parameter(Mandatory = $true)][string]$ProjectKey,
        [Parameter(Mandatory = $true)]$ProjectConfig
    )

    $reasons = New-Object System.Collections.Generic.List[string]

    $requiredFields = @('local_path', 'branch', 'remote', 'server_path')
    foreach ($field in $requiredFields) {
        $value = $ProjectConfig.$field
        if (Test-PlaceholderValue -Value $value) {
            $reasons.Add("فیلد '$field' برای پروژه '$ProjectKey' تنظیم نشده است.")
        }
    }

    foreach ($field in @('server_host', 'server_user', 'ssh_key_path')) {
        $value = $ProjectConfig.$field
        if (Test-PlaceholderValue -Value $value) {
            $reasons.Add("فیلد '$field' برای پروژه '$ProjectKey' هنوز مقدار CHANGE_ME دارد — بدون این مقدار امکان اتصال SSH/SCP وجود ندارد.")
        }
    }

    # retention_count is a NUMBER consumed as bash arithmetic inside
    # activate-release.sh ("$((RETENTION_COUNT + 1))"); a placeholder
    # string like "***" left in this field would not fail cleanly here —
    # it would reach the server and break bash arithmetic AFTER a
    # deploy has already activated. Catch it at preflight instead, the
    # same way a placeholder server_host is caught.
    if ($ProjectConfig.PSObject.Properties['retention_count']) {
        $retentionValue = $ProjectConfig.retention_count
        $retentionAsString = if ($null -eq $retentionValue) { $null } else { [string]$retentionValue }
        if ((Test-PlaceholderValue -Value $retentionAsString) -or ($retentionAsString -notmatch '^\d+$')) {
            $reasons.Add("فیلد 'retention_count' برای پروژه '$ProjectKey' یک عدد معتبر نیست ('$retentionAsString') — این فیلد باید قبل از Deploy با یک عدد واقعی جایگزین شود.")
        }
    }

    # run_migrations/database_backup are BOOLEANS consumed via PowerShell
    # truthiness (`if ($cfg.run_migrations) { 'true' } else { 'false' }`).
    # A placeholder string like "***" left in one of these fields is NOT
    # caught by that truthiness check — a non-empty string is truthy in
    # PowerShell, so "***" would silently be treated as `run_migrations =
    # true` and `php artisan migrate --force` would actually run on the
    # server with no real confirmation this was ever intended. Require a
    # genuine JSON boolean (a real .NET [bool] after ConvertFrom-Json) for
    # both fields — anything else blocks deploy instead of guessing.
    foreach ($field in @('run_migrations', 'database_backup')) {
        if ($ProjectConfig.PSObject.Properties[$field]) {
            $fieldValue = $ProjectConfig.$field
            if ($fieldValue -isnot [bool]) {
                $reasons.Add("فیلد '$field' برای پروژه '$ProjectKey' یک مقدار boolean واقعی (true/false) نیست — تا مشخص نشدن صریح آن، Deploy انجام نمی‌شود.")
            }
        }
    }

    $buildMode = [string]$ProjectConfig.build_mode
    if ($buildMode -eq 'disabled') {
        $reasons.Add("build_mode پروژه '$ProjectKey' روی 'disabled' تنظیم شده — طبق پیکربندی، Deploy برای این پروژه غیرفعال است.")
    } elseif ($buildMode -notin @('artifact', 'git-only', 'files-only', 'disabled')) {
        $reasons.Add("مقدار build_mode پروژه '$ProjectKey' ('$buildMode') نامعتبر است — باید یکی از artifact/git-only/files-only/disabled باشد.")
    }

    if (-not (Test-Path -LiteralPath $ProjectConfig.local_path -ErrorAction SilentlyContinue)) {
        $reasons.Add("مسیر local_path پروژه '$ProjectKey' روی این سیستم پیدا نشد: $($ProjectConfig.local_path)")
    }

    return @{
        Key             = $ProjectKey
        Config          = $ProjectConfig
        IsDeployable    = ($reasons.Count -eq 0)
        BlockingReasons = $reasons.ToArray()
    }
}

function Get-EnabledProjects {
    param([Parameter(Mandatory = $true)]$Config)

    $result = New-Object System.Collections.Generic.List[object]
    foreach ($property in $Config.projects.PSObject.Properties) {
        if ($property.Name.StartsWith('_')) { continue }
        $projectConfig = $property.Value
        if ($projectConfig.enabled -eq $true) {
            $result.Add((Get-ProjectDeployability -ProjectKey $property.Name -ProjectConfig $projectConfig))
        }
    }
    return $result.ToArray()
}

<#
.SYNOPSIS
    Adapter: extracts the "Application" half (code/repository/build
    settings) of one today's-flat project config entry into its own
    object — see docs/SimpleDeploy-Architecture-Task3.md-equivalent
    design (Task 3 report). config.json itself is NOT changed by this —
    every existing flat entry is read exactly as it is today; this only
    reshapes it in memory for the new Build/Deploy split in
    SimpleDeploy.Orchestration.ps1.

.NOTES
    source_subdirectory is a NEW, OPTIONAL field (absent in every
    existing config.json entry today, so it reads as $null everywhere
    unless explicitly added) — purely preparatory per this Task: nothing
    in SimpleDeploy.Build.ps1 reads or acts on it yet.
#>
function ConvertTo-ApplicationProfile {
    param(
        [Parameter(Mandatory = $true)][string]$ApplicationKey,
        [Parameter(Mandatory = $true)]$ProjectConfig
    )

    return [PSCustomObject]@{
        ApplicationKey     = $ApplicationKey
        DisplayName        = $ApplicationKey
        LocalPath          = $ProjectConfig.local_path
        Branch             = $ProjectConfig.branch
        Remote             = $ProjectConfig.remote
        ProjectType        = if ($ProjectConfig.PSObject.Properties['project_type']) { $ProjectConfig.project_type } else { $null }
        SourceSubdirectory = if ($ProjectConfig.PSObject.Properties['source_subdirectory']) { $ProjectConfig.source_subdirectory } else { $null }
        BuildMode          = $ProjectConfig.build_mode
        ActivationMode     = $ProjectConfig.activation_mode
        ComposerCommand    = $ProjectConfig.composer_command
        NpmCommand         = $ProjectConfig.npm_command
        PhpBinary          = $ProjectConfig.php_binary
        PreservePaths      = $ProjectConfig.preserve_paths
        RetentionCount     = $ProjectConfig.retention_count
        RunMigrations      = $ProjectConfig.run_migrations
        DatabaseBackup     = $ProjectConfig.database_backup
        ArtifactValidation = if ($ProjectConfig.PSObject.Properties['artifact_validation']) { $ProjectConfig.artifact_validation } else { $null }
    }
}

<#
.SYNOPSIS
    Adapter: extracts the "Deployment Target" half (destination server)
    of one today's-flat project config entry. Every existing real
    project has EXACTLY ONE implied target today — this produces that
    one target with -TargetKey defaulting to "<ApplicationKey>-default"
    and -Source "legacy-flat-config" so it stays distinguishable from a
    target genuinely added later through a real multi-target source
    (e.g. a future Registry sync) — see Task 3 report Section 5's
    "manual vs synced Target must stay distinguishable" requirement.
#>
function ConvertTo-DeploymentTarget {
    param(
        [Parameter(Mandatory = $true)][string]$ApplicationKey,
        [Parameter(Mandatory = $true)]$ProjectConfig,
        [Parameter(Mandatory = $false)][string]$TargetKey = $null
    )

    if (-not $TargetKey) { $TargetKey = "$ApplicationKey-default" }

    $serverPort = if ($ProjectConfig.PSObject.Properties['server_port'] -and $ProjectConfig.server_port) { [int]$ProjectConfig.server_port } else { 22 }

    return [PSCustomObject]@{
        TargetKey         = $TargetKey
        ApplicationKey    = $ApplicationKey
        Domain            = $null
        DisplayName       = $TargetKey
        LifecycleStatus   = 'active'
        DeploymentChannel = 'stable'
        ServerHost        = $ProjectConfig.server_host
        ServerPort        = $serverPort
        ServerUser        = $ProjectConfig.server_user
        ServerPath        = $ProjectConfig.server_path
        SshKeyPath        = $ProjectConfig.ssh_key_path
        HealthCheckUrl    = $ProjectConfig.health_check_url
        RemotePhpBinary   = if ($ProjectConfig.PSObject.Properties['remote_php_binary']) { $ProjectConfig.remote_php_binary } else { $null }
        Enabled           = $true
        IsTestTarget      = $false
        Source            = 'legacy-flat-config'
    }
}

<#
.SYNOPSIS
    Convenience wrapper — one flat legacy project config entry in, one
    ApplicationProfile + its one (default) Target out. Never called by
    the existing Invoke-ProjectDeploy backward-compat path itself (which
    still reads $cfg directly to stay as close as possible to its
    already-proven-correct original code) — this exists for the NEW
    multi-target path and for direct testing of the conversion itself.

.OUTPUTS
    Hashtable: @{ ApplicationProfile; Targets (array, currently always 1 element) }
#>
function ConvertFrom-LegacyProjectConfig {
    param(
        [Parameter(Mandatory = $true)][string]$ApplicationKey,
        [Parameter(Mandatory = $true)]$ProjectConfig
    )

    return @{
        ApplicationProfile = ConvertTo-ApplicationProfile -ApplicationKey $ApplicationKey -ProjectConfig $ProjectConfig
        Targets            = @(ConvertTo-DeploymentTarget -ApplicationKey $ApplicationKey -ProjectConfig $ProjectConfig)
    }
}
