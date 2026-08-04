<#
.SYNOPSIS
    Tests: Get-SimpleDeployConfig / Get-EnabledProjects / Get-ProjectDeployability
    — multiple enabled/disabled projects, and correct config-required
    (CHANGE_ME placeholder) detection.
#>

$ScriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$ToolRoot = Split-Path -Parent $ScriptDir
. (Join-Path $ScriptDir 'TestHelpers.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Common.ps1')
. (Join-Path $ToolRoot 'lib\SimpleDeploy.Config.ps1')

$tempDir = New-TestTempDirectory -Prefix 'sdtest-config'
$localPathA = Join-Path $tempDir 'project-a'
$localPathB = Join-Path $tempDir 'project-b'
New-Item -ItemType Directory -Path $localPathA -Force | Out-Null
New-Item -ItemType Directory -Path $localPathB -Force | Out-Null

$configJson = @"
{
    "projects": {
        "project-a": {
            "enabled": true,
            "local_path": "$($localPathA.Replace('\', '\\'))",
            "branch": "main",
            "remote": "origin",
            "build_mode": "artifact",
            "server_path": "/var/www/project-a",
            "server_host": "CHANGE_ME_SERVER_HOST",
            "server_user": "deploy",
            "ssh_key_path": "C:\\keys\\id_ed25519"
        },
        "project-b-disabled": {
            "enabled": false,
            "local_path": "$($localPathB.Replace('\', '\\'))",
            "branch": "main",
            "remote": "origin",
            "build_mode": "artifact",
            "server_path": "/var/www/project-b",
            "server_host": "1.2.3.4",
            "server_user": "deploy",
            "ssh_key_path": "C:\\keys\\id_ed25519"
        },
        "project-c-fully-configured": {
            "enabled": true,
            "local_path": "$($localPathB.Replace('\', '\\'))",
            "branch": "main",
            "remote": "origin",
            "build_mode": "artifact",
            "server_path": "/var/www/project-c",
            "server_host": "1.2.3.4",
            "server_user": "deploy",
            "ssh_key_path": "C:\\keys\\id_ed25519"
        }
    }
}
"@
$configPath = Join-Path $tempDir 'config.json'
$configJson | Set-Content -LiteralPath $configPath -Encoding UTF8

$config = Get-SimpleDeployConfig -ConfigPath $configPath
Assert-That 'config loads and exposes the projects object' ($null -ne $config.projects)

$enabled = Get-EnabledProjects -Config $config
Assert-That 'only enabled=true projects are returned (2 of 3)' (@($enabled).Count -eq 2)
Assert-That 'the disabled project is excluded' (-not (@($enabled) | Where-Object { $_.Key -eq 'project-b-disabled' }))

$projectA = @($enabled) | Where-Object { $_.Key -eq 'project-a' }
Assert-That 'project-a is present among enabled projects' ($null -ne $projectA)
Assert-That 'project-a is NOT deployable (server_host is still CHANGE_ME)' ($projectA.IsDeployable -eq $false)
Assert-That 'project-a blocking reasons mention server_host' (($projectA.BlockingReasons -join ' ') -match 'server_host')

$projectC = @($enabled) | Where-Object { $_.Key -eq 'project-c-fully-configured' }
Assert-That 'a fully-configured project IS deployable' ($projectC.IsDeployable -eq $true)
Assert-That 'a fully-configured project has zero blocking reasons' (@($projectC.BlockingReasons).Count -eq 0)

# Test-PlaceholderValue direct checks
Assert-That 'Test-PlaceholderValue: empty string is a placeholder' (Test-PlaceholderValue -Value '')
Assert-That 'Test-PlaceholderValue: null is a placeholder' (Test-PlaceholderValue -Value $null)
Assert-That 'Test-PlaceholderValue: CHANGE_ME-prefixed is a placeholder' (Test-PlaceholderValue -Value 'CHANGE_ME_FOO')
Assert-That 'Test-PlaceholderValue: a real value is not a placeholder' (-not (Test-PlaceholderValue -Value '1.2.3.4'))

# Nonexistent local_path must also block deployability
$configJson2 = $configJson.Replace($localPathA.Replace('\', '\\'), 'C:\\this\\path\\does\\not\\exist\\at\\all')
$configPath2 = Join-Path $tempDir 'config2.json'
$configJson2 | Set-Content -LiteralPath $configPath2 -Encoding UTF8
$config2 = Get-SimpleDeployConfig -ConfigPath $configPath2
$enabled2 = Get-EnabledProjects -Config $config2
$projectA2 = @($enabled2) | Where-Object { $_.Key -eq 'project-a' }
Assert-That 'a nonexistent local_path blocks deployability' ($projectA2.IsDeployable -eq $false)
Assert-That 'the reason mentions the missing path' (($projectA2.BlockingReasons -join ' ') -match 'local_path|پیدا نشد')

# Missing config file entirely -> clear, thrown error (not a silent empty result)
$threw = $false
try {
    Get-SimpleDeployConfig -ConfigPath (Join-Path $tempDir 'does-not-exist.json') | Out-Null
} catch {
    $threw = $true
    Assert-That 'missing config.json error mentions config.example.json' ($_.Exception.Message -match 'config.example.json')
}
Assert-That 'loading a missing config file throws' $threw

Remove-TestTempDirectory -Path $tempDir
Write-TestSummaryAndExit -SuiteName 'Test-Config'
