$ErrorActionPreference = 'Stop'
$RepoRoot = (Resolve-Path (Join-Path $PSScriptRoot '..\..')).Path
$ScriptUnderTest = Join-Path $RepoRoot 'deployment-local-agent\upgrade-1.4.7.ps1'
$PinnedCommit = 'a12869c445d3cc5a0b89dc78b8479617ecac42ce'
$failures = @()
$passCount = 0

function Check([string]$description, [bool]$condition) {
    if ($condition) { $script:passCount++; return }
    $script:failures += $description
}

function Expect-Throws([string]$description, [scriptblock]$block) {
    $threw = $false
    try { & $block | Out-Null } catch { $threw = $true }
    Check $description $threw
}

function New-FakeInstall([string]$Root, [string]$Version) {
    $agent = Join-Path $Root 'agent'
    New-Item -ItemType Directory -Path (Join-Path $agent 'lib') -Force | Out-Null
    # Seeded from the CURRENT package tree purely as filler content for the
    # simulated "already installed" side of the gate - only the version
    # string matters for these tests. The PAYLOAD side (what actually gets
    # installed) always comes from the real pinned historical commit via
    # the script itself, never from this fixture.
    Copy-Item -LiteralPath (Join-Path $RepoRoot 'deployment-local-agent\agent\agent.php') -Destination $agent
    (Get-Content -LiteralPath (Join-Path $agent 'agent.php') -Raw).Replace("LOCAL_AGENT_VERSION = '1.5.0'", "LOCAL_AGENT_VERSION = '$Version'") |
        Set-Content -LiteralPath (Join-Path $agent 'agent.php') -NoNewline
    Copy-Item -LiteralPath (Join-Path $RepoRoot 'deployment-local-agent\agent\heartbeat-watchdog.php') -Destination $agent
    Copy-Item -Path (Join-Path $RepoRoot 'deployment-local-agent\agent\lib\*.php') -Destination (Join-Path $agent 'lib')
    Set-Content -LiteralPath (Join-Path $agent 'config.php') -Value "<?php return ['marker' => 'test-config-$(New-Guid)'];"
    return $agent
}

$root = Join-Path ([System.IO.Path]::GetTempPath()) ('local-agent-147-' + [Guid]::NewGuid().ToString('N'))
try {
    New-Item -ItemType Directory -Path $root -Force | Out-Null

    # --- 1: rejects wrong baselines, accepts exactly 1.4.6 ---
    foreach ($badVersion in @('1.4.5', '1.4.7', '1.5.0')) {
        $install = Join-Path $root "reject-$badVersion"
        New-FakeInstall $install $badVersion | Out-Null
        $state = Join-Path $root "state-reject-$badVersion"
        Expect-Throws "rejects active version $badVersion" {
            & $ScriptUnderTest -Action DryRun -RunId "reject-$badVersion" -InstallDir $install -StateRoot $state -TestMode
        }
    }

    $goodInstall = Join-Path $root 'good'
    New-FakeInstall $goodInstall '1.4.6' | Out-Null
    $goodState = Join-Path $root 'good-state'
    $dryOut = & $ScriptUnderTest -Action DryRun -RunId 'dry1' -InstallDir $goodInstall -StateRoot $goodState -TestMode
    Check 'accepts exactly 1.4.6 and dry-runs cleanly' ($LASTEXITCODE -eq 0 -and $dryOut -match 'DRY_RUN_OK')
    Check 'dry-run output names target 1.4.7' ($dryOut -match 'target=1\.4\.7')
    Check 'dry-run output names the pinned historical commit' ($dryOut -match [regex]::Escape($PinnedCommit))

    # --- 2: DryRun is non-mutating ---
    $preTreeHash = (Get-ChildItem -LiteralPath $goodInstall -Recurse -File | Sort-Object FullName |
        ForEach-Object { (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash }) -join '|'
    & $ScriptUnderTest -Action DryRun -RunId 'dry2' -InstallDir $goodInstall -StateRoot $goodState -TestMode | Out-Null
    $postTreeHash = (Get-ChildItem -LiteralPath $goodInstall -Recurse -File | Sort-Object FullName |
        ForEach-Object { (Get-FileHash -LiteralPath $_.FullName -Algorithm SHA256).Hash }) -join '|'
    Check 'dry-run does not modify the installed tree' ($preTreeHash -eq $postTreeHash)
    Check 'dry-run does not restart the task' (-not (Test-Path -LiteralPath (Join-Path $goodState 'restart.log')))
    Check 'dry-run cleans up its own staging dir' (-not (Test-Path -LiteralPath (Join-Path $goodState 'dry1\staging')))
    Check 'dry-run cleans up its own historical-extraction dir' (-not (Test-Path -LiteralPath (Join-Path $goodState 'dry1\historical-1.4.7')))

    # --- 3: historical payload is genuinely tied to the pinned commit ---
    # Verified via an independent, freshly-extracted `git archive` of the
    # same pinned commit (not by trusting the script's own output) and
    # hashing that file directly - sidesteps any line-ending fragility in
    # console-captured `git show` text.
    $candidateManifest = Get-Content -LiteralPath (Join-Path $goodState 'dry1\CANDIDATE-SHA256.json') -Raw | ConvertFrom-Json
    $verifyDir = Join-Path $root 'verify-extract'
    New-Item -ItemType Directory -Path $verifyDir -Force | Out-Null
    $verifyZip = Join-Path $verifyDir 'p.zip'
    $psi = New-Object System.Diagnostics.ProcessStartInfo
    $psi.FileName = 'git'
    $psi.Arguments = "archive --format=zip $PinnedCommit -- deployment-local-agent/agent"
    $psi.WorkingDirectory = $RepoRoot
    $psi.RedirectStandardOutput = $true
    $psi.UseShellExecute = $false
    $psi.CreateNoWindow = $true
    $verifyProc = [System.Diagnostics.Process]::Start($psi)
    $verifyOutStream = [System.IO.File]::Create($verifyZip)
    $verifyProc.StandardOutput.BaseStream.CopyTo($verifyOutStream)
    $verifyOutStream.Close()
    $verifyProc.WaitForExit()
    Expand-Archive -LiteralPath $verifyZip -DestinationPath $verifyDir -Force
    $trueHash = (Get-FileHash -LiteralPath (Join-Path $verifyDir 'deployment-local-agent\agent\agent.php') -Algorithm SHA256).Hash.ToLowerInvariant()
    Check 'staged agent.php hash matches a fresh extraction of the pinned commit' ($candidateManifest.'agent.php' -eq $trueHash)

    # --- 4: pinned commit's agent.php is genuinely NOT current HEAD's ---
    $currentHeadVersion = (Select-String -LiteralPath (Join-Path $RepoRoot 'deployment-local-agent\agent\agent.php') -Pattern "LOCAL_AGENT_VERSION = '([^']+)'").Matches[0].Groups[1].Value
    $pinnedVersion = (& git -C $RepoRoot show "${PinnedCommit}:deployment-local-agent/agent/agent.php" | Select-String -Pattern "LOCAL_AGENT_VERSION = '([^']+)'").Matches[0].Groups[1].Value
    Check 'pinned historical commit reports 1.4.7, distinct from current package version' ($pinnedVersion -eq '1.4.7' -and $currentHeadVersion -ne '1.4.7')

    # --- 5: corrupt/stale extraction dir is rejected, not silently reused ---
    $corruptRunId = 'corrupt-extract'
    $corruptRunRoot = Join-Path $goodState $corruptRunId
    New-Item -ItemType Directory -Path (Join-Path $corruptRunRoot 'historical-1.4.7') -Force | Out-Null
    Set-Content -LiteralPath (Join-Path $corruptRunRoot 'historical-1.4.7\bogus.txt') -Value 'not a real payload'
    Expect-Throws 'refuses to reuse a pre-existing/corrupt historical-extraction directory' {
        & $ScriptUnderTest -Action DryRun -RunId $corruptRunId -InstallDir $goodInstall -StateRoot $goodState -TestMode
    }

    # --- 6: full Activate + config preservation + composer-relevant marker preserved ---
    $configBefore = Get-Content -LiteralPath (Join-Path $goodInstall 'agent\config.php') -Raw
    $configHashBefore = (Get-FileHash -LiteralPath (Join-Path $goodInstall 'agent\config.php') -Algorithm SHA256).Hash
    $actOut = & $ScriptUnderTest -Action Activate -RunId 'activate1' -InstallDir $goodInstall -StateRoot $goodState -TestMode
    Check 'activation succeeds' ($LASTEXITCODE -eq 0 -and $actOut -match 'ACTIVATION_OK')
    Check 'installed version is now exactly 1.4.7' (
        (Select-String -LiteralPath (Join-Path $goodInstall 'agent\agent.php') -Pattern "LOCAL_AGENT_VERSION = '([^']+)'").Matches[0].Groups[1].Value -eq '1.4.7'
    )
    $configAfter = Get-Content -LiteralPath (Join-Path $goodInstall 'agent\config.php') -Raw
    $configHashAfter = (Get-FileHash -LiteralPath (Join-Path $goodInstall 'agent\config.php') -Algorithm SHA256).Hash
    Check 'config.php content is byte-identical after activation' ($configBefore -eq $configAfter -and $configHashBefore -eq $configHashAfter)
    Check 'activation restarted the task exactly once' ((Get-Content -LiteralPath (Join-Path $goodState 'restart.log')).Count -eq 1)
    Check 'trusted-workspace hardening is present post-activation' (
        (Select-String -LiteralPath (Join-Path $goodInstall 'agent\lib\WorkspaceSourcePreparer.php') -Pattern 'assertTrustedPersistentWorkspace' -Quiet)
    )

    # --- 7: corrupt backup manifest is rejected on rollback ---
    $backupHashPath = Join-Path $goodState 'activate1\backup\SHA256.json'
    $tamperedManifest = Get-Content -LiteralPath $backupHashPath -Raw | ConvertFrom-Json
    $firstKey = @($tamperedManifest.PSObject.Properties)[0].Name
    $tamperedManifest.$firstKey = ('0' * 64)
    $realManifestJson = Get-Content -LiteralPath $backupHashPath -Raw
    $tamperedManifest | ConvertTo-Json | Set-Content -LiteralPath $backupHashPath -Encoding utf8
    $attestationBad = Join-Path $root 'attestation-corrupt.json'
    Set-Content -LiteralPath $attestationBad -Value '{"run_id":"activate1","policy_required":false}'
    $attestationBadHash = (Get-FileHash -LiteralPath $attestationBad -Algorithm SHA256).Hash.ToLowerInvariant()
    Expect-Throws 'refuses rollback when the backup manifest has been tampered with' {
        & $ScriptUnderTest -Action Rollback -RunId 'activate1' -InstallDir $goodInstall -StateRoot $goodState -RollbackAttestation $attestationBad -RollbackAttestationSha256 $attestationBadHash -TestMode
    }
    Set-Content -LiteralPath $backupHashPath -Value $realManifestJson -Encoding utf8

    # --- 8: rollback refuses invalid/mismatched attestation evidence ---
    Expect-Throws 'rollback refuses a missing attestation file' {
        & $ScriptUnderTest -Action Rollback -RunId 'activate1' -InstallDir $goodInstall -StateRoot $goodState -RollbackAttestation (Join-Path $root 'nope.json') -RollbackAttestationSha256 ('a' * 64) -TestMode
    }
    $attestationWrongRun = Join-Path $root 'attestation-wrong-run.json'
    Set-Content -LiteralPath $attestationWrongRun -Value '{"run_id":"some-other-run","policy_required":false}'
    $attestationWrongRunHash = (Get-FileHash -LiteralPath $attestationWrongRun -Algorithm SHA256).Hash.ToLowerInvariant()
    Expect-Throws 'rollback refuses an attestation for a different run_id' {
        & $ScriptUnderTest -Action Rollback -RunId 'activate1' -InstallDir $goodInstall -StateRoot $goodState -RollbackAttestation $attestationWrongRun -RollbackAttestationSha256 $attestationWrongRunHash -TestMode
    }
    $attestationPolicyOn = Join-Path $root 'attestation-policy-on.json'
    Set-Content -LiteralPath $attestationPolicyOn -Value '{"run_id":"activate1","policy_required":true}'
    $attestationPolicyOnHash = (Get-FileHash -LiteralPath $attestationPolicyOn -Algorithm SHA256).Hash.ToLowerInvariant()
    Expect-Throws 'rollback refuses an attestation asserting policy_required=true' {
        & $ScriptUnderTest -Action Rollback -RunId 'activate1' -InstallDir $goodInstall -StateRoot $goodState -RollbackAttestation $attestationPolicyOn -RollbackAttestationSha256 $attestationPolicyOnHash -TestMode
    }
    $attestationOk = Join-Path $root 'attestation-ok.json'
    Set-Content -LiteralPath $attestationOk -Value '{"run_id":"activate1","policy_required":false}'
    Expect-Throws 'rollback refuses a checksum that does not match the attestation file itself' {
        & $ScriptUnderTest -Action Rollback -RunId 'activate1' -InstallDir $goodInstall -StateRoot $goodState -RollbackAttestation $attestationOk -RollbackAttestationSha256 ('f' * 64) -TestMode
    }

    # --- 9: legitimate rollback restores exact prior 1.4.6 state ---
    $attestationOkHash = (Get-FileHash -LiteralPath $attestationOk -Algorithm SHA256).Hash.ToLowerInvariant()
    $rollbackOut = & $ScriptUnderTest -Action Rollback -RunId 'activate1' -InstallDir $goodInstall -StateRoot $goodState -RollbackAttestation $attestationOk -RollbackAttestationSha256 $attestationOkHash -TestMode
    Check 'legitimate rollback succeeds' ($LASTEXITCODE -eq 0 -and $rollbackOut -match 'ROLLBACK_OK')
    Check 'rollback restores exactly 1.4.6' (
        (Select-String -LiteralPath (Join-Path $goodInstall 'agent\agent.php') -Pattern "LOCAL_AGENT_VERSION = '([^']+)'").Matches[0].Groups[1].Value -eq '1.4.6'
    )
    $configAfterRollback = Get-Content -LiteralPath (Join-Path $goodInstall 'agent\config.php') -Raw
    Check 'rollback restores config.php byte-identical to pre-upgrade' ($configAfterRollback -eq $configBefore)
    Check 'repeated rollback does not cause an extra restart' ((Get-Content -LiteralPath (Join-Path $goodState 'restart.log')).Count -eq 2)

    if ($failures.Count -eq 0) {
        Write-Output "Local Agent 1.4.6->1.4.7 bridge: $passCount passed, 0 failed. ALL PASS"
    } else {
        Write-Output "Local Agent 1.4.6->1.4.7 bridge: $passCount passed, $($failures.Count) failed."
        foreach ($f in $failures) { Write-Output "  FAIL: $f" }
        exit 1
    }
} finally {
    if (Test-Path -LiteralPath $root) { Remove-Item -LiteralPath $root -Recurse -Force -ErrorAction SilentlyContinue }
}
