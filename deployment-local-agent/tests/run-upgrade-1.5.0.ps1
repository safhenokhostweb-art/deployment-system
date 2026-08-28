$ErrorActionPreference = 'Stop'
$root = Join-Path ([System.IO.Path]::GetTempPath()) ('local-agent-upgrade-' + [Guid]::NewGuid().ToString('N'))
try {
    $install = Join-Path $root 'install'
    $agent = Join-Path $install 'agent'
    New-Item -ItemType Directory -Path (Join-Path $agent 'lib') -Force | Out-Null
    Copy-Item -LiteralPath 'deployment-local-agent\agent\agent.php' -Destination $agent
    (Get-Content -LiteralPath (Join-Path $agent 'agent.php') -Raw).Replace("LOCAL_AGENT_VERSION = '1.5.0'", "LOCAL_AGENT_VERSION = '1.4.7'") | Set-Content -LiteralPath (Join-Path $agent 'agent.php') -NoNewline
    Copy-Item -LiteralPath 'deployment-local-agent\agent\heartbeat-watchdog.php' -Destination $agent
    Copy-Item -Path 'deployment-local-agent\agent\lib\*.php' -Destination (Join-Path $agent 'lib')
    Set-Content -LiteralPath (Join-Path $agent 'config.php') -Value '<?php return [];'
    $state = Join-Path $root 'state'
    & 'deployment-local-agent\upgrade-1.5.0.ps1' -Action DryRun -RunId local-dry-run -InstallDir $install -StateRoot $state -TestMode
    if (Test-Path -LiteralPath (Join-Path $state 'restart.log')) { throw 'Dry-run restarted the task.' }
    & 'deployment-local-agent\upgrade-1.5.0.ps1' -Action Activate -RunId local-activate -InstallDir $install -StateRoot $state -TestMode
    if (@(Get-Content -LiteralPath (Join-Path $state 'restart.log')).Count -ne 1) { throw 'Activation restart count is not one.' }
    $attestation = Join-Path $root 'rollback-attestation.json'
    Set-Content -LiteralPath $attestation -Value '{"run_id":"local-activate","policy_required":false}'
    $attestationHash = (Get-FileHash -LiteralPath $attestation -Algorithm SHA256).Hash.ToLowerInvariant()
    & 'deployment-local-agent\upgrade-1.5.0.ps1' -Action Rollback -RunId local-activate -InstallDir $install -StateRoot $state -RollbackAttestation $attestation -RollbackAttestationSha256 $attestationHash -TestMode
    & 'deployment-local-agent\upgrade-1.5.0.ps1' -Action Rollback -RunId local-activate -InstallDir $install -StateRoot $state -RollbackAttestation $attestation -RollbackAttestationSha256 $attestationHash -TestMode
    if (@(Get-Content -LiteralPath (Join-Path $state 'restart.log')).Count -ne 2) { throw 'Repeated rollback caused an extra restart.' }
    if (-not (Select-String -LiteralPath (Join-Path $agent 'agent.php') -Quiet -Pattern "LOCAL_AGENT_VERSION = '1.4.7'")) { throw 'Rollback version mismatch.' }
    Write-Output 'Local Agent 1.5.0 staging/activation/rollback: ALL PASS'
} finally {
    if (Test-Path -LiteralPath $root) { Remove-Item -LiteralPath $root -Recurse -Force }
}
