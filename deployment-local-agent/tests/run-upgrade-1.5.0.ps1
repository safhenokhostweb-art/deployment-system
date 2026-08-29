$ErrorActionPreference = 'Stop'
$root = Join-Path ([System.IO.Path]::GetTempPath()) ('local-agent-upgrade-' + [Guid]::NewGuid().ToString('N'))

function New-FakeInstall([string]$InstallRoot) {
    $fakeAgent = Join-Path $InstallRoot 'agent'
    New-Item -ItemType Directory -Path (Join-Path $fakeAgent 'lib') -Force | Out-Null
    Copy-Item -LiteralPath 'deployment-local-agent\agent\agent.php' -Destination $fakeAgent
    (Get-Content -LiteralPath (Join-Path $fakeAgent 'agent.php') -Raw).Replace("LOCAL_AGENT_VERSION = '1.5.0'", "LOCAL_AGENT_VERSION = '1.4.7'") | Set-Content -LiteralPath (Join-Path $fakeAgent 'agent.php') -NoNewline
    Copy-Item -LiteralPath 'deployment-local-agent\agent\heartbeat-watchdog.php' -Destination $fakeAgent
    Copy-Item -Path 'deployment-local-agent\agent\lib\*.php' -Destination (Join-Path $fakeAgent 'lib')
    Set-Content -LiteralPath (Join-Path $fakeAgent 'config.php') -Value "<?php return ['marker' => '$(New-Guid)'];"
    return $fakeAgent
}

function Get-TreeFingerprint([string]$Path) {
    (Get-ChildItem -LiteralPath $Path -Recurse -File | Sort-Object FullName | ForEach-Object {
        (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash
    }) -join '|'
}

try {
    $install = Join-Path $root 'install'
    $agent = New-FakeInstall $install
    $state = Join-Path $root 'state'
    & 'deployment-local-agent\upgrade-1.5.0.ps1' -Action DryRun -RunId local-dry-run -InstallDir $install -StateRoot $state -TestMode
    if (Test-Path -LiteralPath (Join-Path $state 'stop.log')) { throw 'Dry-run stopped the task.' }
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

    # A real live PID in agent.pid must block activation before AgentDir is moved.
    $lockedInstall = Join-Path $root 'locked-install'
    $lockedAgent = New-FakeInstall $lockedInstall
    $lockedState = Join-Path $root 'locked-state'
    $blocker = Start-Process -FilePath 'powershell.exe' -ArgumentList '-NoProfile', '-Command', 'Start-Sleep -Seconds 60' -PassThru -WindowStyle Hidden
    try {
        New-Item -ItemType Directory -Path (Join-Path $lockedAgent 'storage') -Force | Out-Null
        Set-Content -LiteralPath (Join-Path $lockedAgent 'storage\agent.pid') -Value $blocker.Id -NoNewline
        $lockedTreeBefore = Get-TreeFingerprint $lockedAgent
        $blocked = $false
        try {
            & 'deployment-local-agent\upgrade-1.5.0.ps1' -Action Activate -RunId locked -InstallDir $lockedInstall -StateRoot $lockedState -TestMode -StopTimeoutSeconds 2 | Out-Null
        } catch {
            $blocked = $_.Exception.Message -match 'refusing to swap a live directory'
        }
        if (-not $blocked) { throw 'Activation did not fail closed while the recorded agent PID remained alive.' }
        if (-not (Test-Path -LiteralPath $lockedAgent)) { throw 'Blocked activation moved the live AgentDir.' }
        if (Test-Path -LiteralPath (Join-Path $lockedState 'locked\pre-activation')) { throw 'Blocked activation created pre-activation content.' }
        if ((Get-TreeFingerprint $lockedAgent) -ne $lockedTreeBefore) { throw 'Blocked activation changed the live AgentDir tree.' }
    } finally {
        Stop-Process -Id $blocker.Id -Force -ErrorAction SilentlyContinue
    }

    # Happy path must record a stop before completing the swap/start sequence.
    $orderedInstall = Join-Path $root 'ordered-install'
    $orderedAgent = New-FakeInstall $orderedInstall
    $orderedState = Join-Path $root 'ordered-state'
    & 'deployment-local-agent\upgrade-1.5.0.ps1' -Action Activate -RunId ordered -InstallDir $orderedInstall -StateRoot $orderedState -TestMode | Out-Null
    if ((Get-Content -LiteralPath (Join-Path $orderedState 'stop.log')) -notcontains 'ordered') { throw 'Activation did not record the pre-swap stop.' }
    if ((Get-Content -LiteralPath (Join-Path $orderedState 'restart.log')) -notcontains 'ordered') { throw 'Activation did not record the post-swap start.' }
    if (-not (Select-String -LiteralPath (Join-Path $orderedAgent 'agent.php') -Quiet -Pattern "LOCAL_AGENT_VERSION = '1.5.0'")) { throw 'Ordered activation version mismatch.' }

    # A post-swap failure must restore the exact 1.4.7 tree and start it again.
    $rollbackInstall = Join-Path $root 'automatic-rollback-install'
    $rollbackAgent = New-FakeInstall $rollbackInstall
    $rollbackState = Join-Path $root 'automatic-rollback-state'
    $rollbackRunRoot = Join-Path $rollbackState 'automatic-rollback'
    New-Item -ItemType Directory -Path $rollbackRunRoot -Force | Out-Null
    Set-Content -LiteralPath (Join-Path $rollbackRunRoot 'TEST-FORCE-POST-SWAP-FAILURE') -Value '1'
    $rollbackTreeBefore = Get-TreeFingerprint $rollbackAgent
    $rolledBack = $false
    try {
        & 'deployment-local-agent\upgrade-1.5.0.ps1' -Action Activate -RunId automatic-rollback -InstallDir $rollbackInstall -StateRoot $rollbackState -TestMode | Out-Null
    } catch {
        $rolledBack = $_.Exception.Message -match 'automatically rolled back to 1.4.7'
    }
    if (-not $rolledBack) { throw 'Injected post-swap failure did not report automatic rollback.' }
    if ((Get-TreeFingerprint $rollbackAgent) -ne $rollbackTreeBefore) { throw 'Automatic rollback did not restore the exact 1.4.7 tree.' }
    if ((Get-Content -LiteralPath (Join-Path $rollbackState 'restart.log')) -notcontains 'automatic-rollback') { throw 'Automatic rollback did not restart the task.' }
    if (-not (Test-Path -LiteralPath (Join-Path $rollbackRunRoot 'failed-activation-partial\agent.php'))) { throw 'Automatic rollback did not preserve failed 1.5.0 content.' }

    Write-Output 'Local Agent 1.5.0 staging/activation/rollback/live-swap safety: ALL PASS'
} finally {
    if (Test-Path -LiteralPath $root) { Remove-Item -LiteralPath $root -Recurse -Force }
}
