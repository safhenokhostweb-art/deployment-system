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
