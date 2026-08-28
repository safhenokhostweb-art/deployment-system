<# Minimal Local Agent 1.5.0 upgrade with an exact 1.4.7 backup. #>
[CmdletBinding()]
param(
    [ValidateSet('DryRun','Activate','Rollback')][string]$Action = 'DryRun',
    [Parameter(Mandatory=$true)][ValidatePattern('^[A-Za-z0-9._-]+$')][string]$RunId,
    [string]$InstallDir = 'C:\ProgramData\DeploymentLocalAgent',
    [string]$StateRoot = '',
    [string]$RollbackAttestation = '',
    [string]$RollbackAttestationSha256 = '',
    [switch]$TestMode
)
$ErrorActionPreference = 'Stop'
$PackageDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$SourceDir = Join-Path $PackageDir 'agent'
$AgentDir = Join-Path $InstallDir 'agent'
if (-not $StateRoot) { $StateRoot = Join-Path $InstallDir 'storage\upgrades' }
$RunRoot = Join-Path $StateRoot $RunId
$Backup = Join-Path $RunRoot 'backup'
$Stage = Join-Path $RunRoot 'staging'
$Done = Join-Path $RunRoot ($Action.ToLowerInvariant() + '.done')

function Get-AgentVersion([string]$Path) {
    $m = Select-String -Path $Path -Pattern "LOCAL_AGENT_VERSION = '([^']+)'" | Select-Object -First 1
    if (-not $m) { throw 'Agent version could not be read.' }
    $m.Matches[0].Groups[1].Value
}
function Get-TreeHashes([string]$Root) {
    $result = [ordered]@{}
    Get-ChildItem -LiteralPath $Root -File -Recurse | Sort-Object FullName | ForEach-Object {
        $relative = $_.FullName.Substring($Root.Length).TrimStart('\','/').Replace('\','/')
        $result[$relative] = (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash.ToLowerInvariant()
    }
    $result
}
function Restart-Once {
    $restartMarker = Join-Path $RunRoot ($Action.ToLowerInvariant() + '.restart.done')
    if (Test-Path -LiteralPath $restartMarker) { return }
    if ($TestMode) {
        Add-Content -LiteralPath (Join-Path $StateRoot 'restart.log') -Value $RunId
    } else {
        schtasks /End /TN DeploymentLocalAgent 2>$null | Out-Null
        schtasks /Run /TN DeploymentLocalAgent | Out-Null
        if ($LASTEXITCODE -ne 0) { throw 'Local Agent task restart failed.' }
    }
    New-Item -ItemType File -Path $restartMarker -Force | Out-Null
}

if (-not (Test-Path -LiteralPath (Join-Path $AgentDir 'config.php'))) { throw 'Existing Local Agent config.php is required.' }
if ($Action -eq 'Rollback') {
    if (Test-Path -LiteralPath $Done) { Write-Output "ROLLBACK_ALREADY_DONE run_id=$RunId"; exit 0 }
    if (-not (Test-Path -LiteralPath $RollbackAttestation) -or $RollbackAttestationSha256 -notmatch '^[0-9a-f]{64}$') { throw 'A hashed Hub rollback attestation is required.' }
    if ((Get-FileHash -LiteralPath $RollbackAttestation -Algorithm SHA256).Hash.ToLowerInvariant() -ne $RollbackAttestationSha256) { throw 'Hub rollback attestation checksum mismatch.' }
    $attestation = Get-Content -LiteralPath $RollbackAttestation -Raw | ConvertFrom-Json
    if ($attestation.run_id -ne $RunId -or $attestation.policy_required -ne $false) { throw 'Hub rollback attestation does not match this run or policy-off state.' }
    $hashPath = Join-Path $Backup 'SHA256.json'
    if (-not (Test-Path -LiteralPath $hashPath)) { throw 'Exact Local Agent backup is missing.' }
    $expected = Get-Content -LiteralPath $hashPath -Raw | ConvertFrom-Json
    $actual = Get-TreeHashes (Join-Path $Backup 'runtime')
    $expectedProperties = @($expected.PSObject.Properties)
    if ($expectedProperties.Count -ne $actual.Count) { throw 'Local Agent backup checksum verification failed.' }
    foreach ($property in $expectedProperties) {
        if (-not $actual.Contains($property.Name) -or $actual[$property.Name] -ne [string]$property.Value) { throw 'Local Agent backup checksum verification failed.' }
    }
    $failed = Join-Path $RunRoot 'failed-1.5.0'
    if (Test-Path -LiteralPath $failed) { throw 'Failed-runtime preservation path already exists.' }
    Move-Item -LiteralPath $AgentDir -Destination $failed
    Copy-Item -LiteralPath (Join-Path $Backup 'runtime') -Destination $AgentDir -Recurse
    if ((Get-AgentVersion (Join-Path $AgentDir 'agent.php')) -ne '1.4.7') { throw 'Rollback did not restore Local Agent 1.4.7.' }
    Restart-Once
    New-Item -ItemType File -Path $Done -Force | Out-Null
    Write-Output "ROLLBACK_OK run_id=$RunId restored_version=1.4.7 backup=$Backup"
    exit 0
}

if ((Get-AgentVersion (Join-Path $AgentDir 'agent.php')) -ne '1.4.7') { throw 'Active Local Agent must be 1.4.7.' }
if ((Get-AgentVersion (Join-Path $SourceDir 'agent.php')) -ne '1.5.0') { throw 'Candidate Local Agent must be 1.5.0.' }
if (Test-Path -LiteralPath $Backup) { throw 'This run_id already owns a backup.' }
New-Item -ItemType Directory -Path $Backup -Force | Out-Null
Copy-Item -LiteralPath $AgentDir -Destination (Join-Path $Backup 'runtime') -Recurse
$backupHashes = Get-TreeHashes (Join-Path $Backup 'runtime')
$backupHashes | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $Backup 'SHA256.json') -Encoding utf8
@{ run_id=$RunId; source_version='1.4.7'; target_version='1.5.0' } | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $Backup 'metadata.json') -Encoding utf8

Copy-Item -LiteralPath (Join-Path $Backup 'runtime') -Destination $Stage -Recurse
Copy-Item -LiteralPath (Join-Path $SourceDir 'agent.php') -Destination $Stage -Force
Copy-Item -LiteralPath (Join-Path $SourceDir 'heartbeat-watchdog.php') -Destination $Stage -Force
Get-ChildItem -LiteralPath (Join-Path $SourceDir 'lib') -File -Filter '*.php' | ForEach-Object { Copy-Item -LiteralPath $_.FullName -Destination (Join-Path $Stage 'lib') -Force }
if ((Get-AgentVersion (Join-Path $Stage 'agent.php')) -ne '1.5.0') { throw 'Staged Local Agent version is invalid.' }
if ((Get-FileHash -LiteralPath (Join-Path $AgentDir 'config.php')).Hash -ne (Get-FileHash -LiteralPath (Join-Path $Stage 'config.php')).Hash) { throw 'config.php changed in staging.' }
Get-TreeHashes $Stage | ConvertTo-Json | Set-Content -LiteralPath (Join-Path $RunRoot 'CANDIDATE-SHA256.json') -Encoding utf8

if ($Action -eq 'DryRun') {
    Remove-Item -LiteralPath $Stage -Recurse -Force
    New-Item -ItemType File -Path $Done -Force | Out-Null
    Write-Output "DRY_RUN_OK run_id=$RunId baseline=1.4.7 target=1.5.0 backup=$Backup"
    exit 0
}
if (Test-Path -LiteralPath $Done) { Write-Output "ACTIVATION_ALREADY_DONE run_id=$RunId"; exit 0 }
$old = Join-Path $RunRoot 'pre-activation'
Move-Item -LiteralPath $AgentDir -Destination $old
Move-Item -LiteralPath $Stage -Destination $AgentDir
Restart-Once
New-Item -ItemType File -Path $Done -Force | Out-Null
Write-Output "ACTIVATION_OK run_id=$RunId old_version=1.4.7 new_version=1.5.0 backup=$Backup"
