$ErrorActionPreference = 'Stop'
$taskPreviousRoot = 'C:/Users/Farshad-IT/AppData/Local/Temp/SentinelProof/2f606c65-a4c9-48af-8646-de4ae6f12d49'
$taskNewRoot = 'C:/Users/Farshad-IT/AppData/Local/Temp/SentinelProof/0114172b-dc0d-4c96-b137-6bb9e599f997'
$taskPreviousReport = Get-Content -LiteralPath "$taskPreviousRoot/evidence/report.json" -Raw | ConvertFrom-Json
$taskReport = Get-Content -LiteralPath "$taskNewRoot/evidence/report.json" -Raw | ConvertFrom-Json
if ($taskReport.FINAL_RESULT -ne 'FAILED_LOCAL_ONLY' -or $taskReport.REAL_LOCAL_RUNTIME_VERIFIED -ne 'NO') {
    throw 'Unexpected run result; review must be regenerated from actual observations.'
}
foreach ($taskProperty in $taskPreviousReport.PSObject.Properties) {
    if ($null -eq $taskReport.PSObject.Properties[$taskProperty.Name]) {
        $taskReport | Add-Member -NotePropertyName $taskProperty.Name -NotePropertyValue $null
    }
}
$taskBeforePath = Join-Path $PSScriptRoot 'preserved-run-before.json'
$taskAfterPath = Join-Path $PSScriptRoot 'preserved-run-after.json'
$taskBeforeHash = (Get-FileHash -LiteralPath $taskBeforePath -Algorithm SHA256).Hash
$taskAfterHash = (Get-FileHash -LiteralPath $taskAfterPath -Algorithm SHA256).Hash
$taskInventory = Get-Content -LiteralPath $taskBeforePath -Raw | ConvertFrom-Json
$taskInvalidFingerprints = @($taskInventory.PSObject.Properties | Where-Object {
    $null -ne $_.Value.PSObject.Properties['sha256'] -and $_.Value.sha256 -notmatch '^[0-9a-f]{64}$'
})
if ($taskInvalidFingerprints.Count -ne 0) { throw 'Incomplete preserved-run fingerprint; cannot assert preservation.' }
$taskExtras = [ordered]@{
    PREVIOUS_RUN_ID = $taskPreviousReport.RUN_ID
    PREVIOUS_RUN_ROOT = $taskPreviousRoot
    PREVIOUS_FAILURE_CAUSE_STATUS = 'NOT_PROVABLE_FROM_PRESERVED_EVIDENCE'
    PREVIOUS_FAILURE_ANALYSIS = [ordered]@{
        exception_discarded_by = @('SentinelLocalProofOrchestrator::step -> proof_step_failed', 'SentinelLocalProofOrchestrator::execute -> proof_execution_failed')
        isolated_database_counts = @{projects=0; releases=0; artifacts=0; deployments=0; local_agents=1; heartbeat_nonces=0}
        bootstrap_or_migrations_reached = $false
        project_save_without_bootstrap_component_test = 'PASS (in-memory diagnostic fixture; not runtime proof)'
        artifact_git_tree_byte_audit = (Get-Content -LiteralPath (Join-Path $PSScriptRoot 'artifact-tree-audit.json') -Raw | ConvertFrom-Json)
    }
    PREVIOUS_RUN_PRESERVATION = [ordered]@{
        file_inventory_count = ($taskInventory.PSObject.Properties | Measure-Object).Count
        before_inventory_sha256 = $taskBeforeHash.ToLowerInvariant()
        after_inventory_sha256 = $taskAfterHash.ToLowerInvariant()
        identical_content_and_paths = ($taskBeforeHash -eq $taskAfterHash)
        old_run_writes_performed = $false
        independent_native_byte_verification = (Get-Content -LiteralPath (Join-Path $PSScriptRoot 'preserved-run-native-verification.json') -Raw | ConvertFrom-Json)
    }
    NEW_FAILURE_CAUSE_STATUS = 'INNER_GIT_EXCEPTION_WAS_DISCARDED; PRECISE_NATIVE_CAUSE_NOT_PROVABLE'
    NEW_RUN_STOPPED_AT_FIRST_FAILURE = 'YES'
    DIAGNOSTIC_FIXES = @(
        'Preserve step/execute previous exceptions and attempted state/model-save stage.',
        'Safely report required exception fields; deny arbitrary text, SQL bindings, credentials and absolute paths.',
        'Sanitize runtime router errors and persist runtime initialization exit code.',
        'Preserve evidence document contract and expose actual verifier outcome for final reporting.',
        'After new-run failure only: preserve previous exception in ExactCommitExporter readEntries catch. This correction was not used by the stopped run.'
    )
    FUNCTIONAL_WORKAROUND_OR_FALLBACK = 'NONE'
    TEST_RESULTS = @(
        @{name='Sentinel suite before run'; tests=76; passed=75; assertions=829; skipped=1},
        @{name='Final changed isolation/diagnostic tests (also repeated after exporter diagnostic correction)'; tests=21; passed=20; assertions=325; skipped=1},
        @{name='Heartbeat/auth feature tests'; tests=17; passed=17; assertions=36; skipped=0},
        @{name='Native Windows symlink helper'; native_links_verified=2; rejection_checks_passed=13; blocked=0},
        @{name='Changed PHP syntax checks and git diff whitespace check'; result='PASS'}
    )
    CHAIN_VERIFICATION = [ordered]@{
        exact_source_export='FAILED'; build='NOT_REACHED'; artifact='NOT_REACHED'; isolated_hub='NOT_REACHED'
        release='NOT_REACHED'; deployment='NOT_REACHED'; runtime='NOT_REACHED'; health='NOT_REACHED'
        local_agent='NOT_REACHED'; hmac_heartbeat='NOT_REACHED'; runtime_snapshot_persistence='NOT_REACHED'
        AgentRuntimeEvidenceReader='NOT_REACHED'; SentinelEvidenceCollector='NOT_REACHED'; SentinelVerifier='NOT_REACHED'
    }
    HMAC_VERIFIED = 'NO'
    SNAPSHOT_PERSISTED = 'NO'
    RELEASE_PUBLIC_ID = $null
    ARTIFACT_PUBLIC_ID = $null
    DEPLOYMENT_PUBLIC_ID = $null
    RUNTIME_ROOT = $null
    CURRENT_TARGET = $null
    CURRENT_IS_NATIVE_SYMLINK = 'NOT_REACHED'
    CURRENT_TARGET_MATCH = 'NOT_REACHED'
    MANIFEST_PATH = $null
    RUNTIME_MARKER_PATH = $null
    HEALTH_RELEASE_STATUS = $null
    HEALTH_LIVE_STATUS = $null
    SNAPSHOT_SEQUENCE = $null
    SNAPSHOT_SEQUENCE_MONOTONIC = 'NOT_REACHED'
    READER_RESULT = 'NOT_REACHED'
    COLLECTOR_RESULT = 'NOT_REACHED'
    VERIFIER_RESULT = 'NOT_REACHED'
    SENTINEL_STATE = 'NOT_STARTED'
    SENTINEL_EVIDENCE = $null
    SENTINEL_VERIFICATION = $null
    ADDITIONAL_FRESH_RUN_EXECUTED = 'NO'
    REVIEW_DOCUMENT = 'tools/sentinel-proof/diagnostic-review.md'
}
foreach ($taskEntry in $taskExtras.GetEnumerator()) {
    $taskReport | Add-Member -NotePropertyName $taskEntry.Key -NotePropertyValue $taskEntry.Value -Force
}
$taskOutput = Join-Path $PSScriptRoot 'final-review.json'
$taskReport | ConvertTo-Json -Depth 30 | Set-Content -LiteralPath $taskOutput -Encoding UTF8
Write-Output "FINAL_REVIEW=$taskOutput"
Write-Output "PREVIOUS_RUN_INVENTORY_MATCH=$($taskBeforeHash -eq $taskAfterHash)"
Write-Output 'REAL_LOCAL_RUNTIME_VERIFIED=NO'
