<?php

namespace App\Services\Sentinel;

use Illuminate\Container\Container;
use InvalidArgumentException;

/**
 * SentinelVerifier — the fail-closed comparator for the Deployment
 * Proof chain:
 *
 *   Local Source -> Commit SHA -> Artifact -> Hub Release -> Agent ->
 *   Server Release -> Active Runtime -> Public Health
 *
 * It compares EXPECTED values against OBSERVED values across every
 * link and returns exactly one of three verdicts:
 *
 *   VERIFIED  every link present and matching
 *   FAILED    at least one link is proven wrong
 *   UNKNOWN   evidence incomplete / uninterpretable (never a pass)
 *
 * Fail-closed aggregation: one FAILED check makes the whole verdict
 * FAILED; short of that, any UNKNOWN check makes the verdict UNKNOWN;
 * VERIFIED requires every single check to pass. An evidence document
 * that is missing a required field, carries an unsupported version, or
 * has no expected release to be attributed to can never come back
 * VERIFIED.
 *
 * Deliberately framework-free and side-effect free: no database, no
 * HTTP, no Production flow, no UI. Phase 1 verifies documents only —
 * collectors that observe the live runtime come in a later phase.
 *
 * File-level verification (additive, feature-flagged): when enabled
 * via the deployment.sentinel.file_verification_enabled config, the verifier
 * performs strict comparison of the artifact's file manifest against
 * the Server Agent's observed file manifest.
 */
final class SentinelVerifier
{
    /**
     * Verify an evidence document against the expectation it is supposed
     * to answer.
     *
     * @param  array<string, mixed>  $expectation  context supplied by the
     *     caller. Must contain `release_id` for a VERIFIED verdict —
     *     evidence that cannot be attributed to the release under
     *     scrutiny is UNKNOWN by definition. Optional keys:
     *     project_key, environment, commit_sha,
     *     artifact_checksum_sha256, agent_id, deployment_id.
     */
    public function verify(SentinelEvidenceContract $evidence, array $expectation = []): SentinelVerificationResult
    {
        $this->assertExpectationShape($expectation);

        // File-level verification enabled check. The class is framework-free by
        // design (see docblock): under Laravel the flag is read from the config
        // repository exactly as before; outside a booted application (standalone
        // verifier runs, raw isolation tests) there is no repository and the
        // documented opt-in default — disabled — applies. Never enabled implicitly.
        $container = Container::getInstance();
        $fileVerificationEnabled = $container->bound('config')
            && $container->make('config')->get('deployment.sentinel.file_verification_enabled', false) === true;

        $checks = [
            'evidence_version' => $this->checkVersion($evidence),
            'contract_completeness' => $this->checkCompleteness($evidence),
            'commit_match' => $this->checkCommit($evidence, $expectation),
            'artifact_checksum_match' => $this->checkArtifactChecksum($evidence, $expectation),
            'release_identity' => $this->checkReleaseIdentity($evidence, $expectation),
            'agent_identity' => $this->checkAgentIdentity($evidence, $expectation),
            'runtime_release_path' => $this->checkRuntimeReleasePath($evidence, $expectation),
            'runtime_marker_match' => $this->checkRuntimeMarker($evidence),
            'health_response' => $this->checkHealth($evidence),
            'recorded_checks' => $this->checkRecordedChecks($evidence),
            'verified_at' => $this->checkVerifiedAt($evidence),
            'expectation_alignment' => $this->checkExpectationAlignment($evidence, $expectation),
        ];

        // File-level checks (additive, feature-flagged)
        if ($fileVerificationEnabled) {
            $checks['file_manifest_hash_match'] = $this->checkFileManifestHash($evidence, $expectation);
            $checks['file_manifest_entry_count_match'] = $this->checkFileManifestEntryCount($evidence, $expectation);
            $checks['file_manifest_total_size_match'] = $this->checkFileManifestTotalSize($evidence, $expectation);
            $checks['file_comparison'] = $this->checkFileComparison($evidence, $expectation);
        }

        return SentinelVerificationResult::make(
            $this->aggregate($checks),
            $checks,
            $evidence->hash(),
            $expectation,
        );
    }

    /**
     * Convenience wrapper for raw arrays.
     *
     * @param  array<string, mixed>  $evidence
     * @param  array<string, mixed>  $expectation
     */
    public function verifyArray(array $evidence, array $expectation = []): SentinelVerificationResult
    {
        return $this->verify(SentinelEvidenceContract::fromArray($evidence), $expectation);
    }

    /**
     * Fail-closed aggregation:
     *   any FAIL    -> FAILED
     *   any UNKNOWN -> UNKNOWN
     *   else        -> VERIFIED
     *
     * With a final belt-and-braces guard: VERIFIED is downgraded to
     * UNKNOWN if the version or completeness check is not an explicit
     * pass, so no future edit can accidentally weaken those two.
     *
     * @param  array<string, array{status: string, detail?: string}>  $checks
     */
    private function aggregate(array $checks): string
    {
        $hasFail = false;
        $hasUnknown = false;

        foreach ($checks as $check) {
            $status = $check['status'] ?? SentinelEvidenceContract::CHECK_STATUS_UNKNOWN;
            if ($status === SentinelEvidenceContract::CHECK_STATUS_FAIL) {
                $hasFail = true;
            } elseif ($status !== SentinelEvidenceContract::CHECK_STATUS_PASS) {
                $hasUnknown = true;
            }
        }

        if ($hasFail) {
            return SentinelVerificationResult::FAILED;
        }

        if ($hasUnknown) {
            return SentinelVerificationResult::UNKNOWN;
        }

        $versionOk = ($checks['evidence_version']['status'] ?? null) === SentinelEvidenceContract::CHECK_STATUS_PASS;
        $completeOk = ($checks['contract_completeness']['status'] ?? null) === SentinelEvidenceContract::CHECK_STATUS_PASS;

        return ($versionOk && $completeOk)
            ? SentinelVerificationResult::VERIFIED
            : SentinelVerificationResult::UNKNOWN;
    }

    /**
     * @param  array<string, mixed>  $expectation
     */
    private function assertExpectationShape(array $expectation): void
    {
        foreach (array_keys($expectation) as $key) {
            if (! is_string($key) || ! in_array($key, ['project_key', 'environment', 'release_id', 'commit_sha', 'artifact_checksum_sha256', 'agent_id', 'deployment_id'], true)) {
                throw new InvalidArgumentException(sprintf(
                    "sentinel expectation field '%s' is not part of the verification schema.",
                    is_string($key) ? $key : gettype($key),
                ));
            }
        }
    }

    /** @return array{status: string, detail: string} */
    private function checkVersion(SentinelEvidenceContract $evidence): array
    {
        $version = $evidence->version();

        if ($version === null) {
            return $this->unknown('evidence_version is missing.');
        }

        if ($version !== SentinelEvidenceContract::VERSION) {
            return $this->unknown("unsupported evidence version '{$version}' — only ".SentinelEvidenceContract::VERSION.' can be judged.');
        }

        return $this->pass('evidence declares '.SentinelEvidenceContract::VERSION.'.');
    }

    /** @return array{status: string, detail: string} */
    private function checkCompleteness(SentinelEvidenceContract $evidence): array
    {
        $missing = array_keys($evidence->missingRequiredFields());

        if ($missing === []) {
            return $this->pass('all required evidence fields are present.');
        }

        return $this->unknown('incomplete evidence — missing: '.implode(', ', $missing).'.');
    }

    /**
     * @param  array<string, mixed>  $expectation
     *
     * @return array{status: string, detail: string}
     */
    private function checkCommit(SentinelEvidenceContract $evidence, array $expectation): array
    {
        $expected = $evidence->get('expected_commit_sha');
        $observed = $evidence->get('observed_commit_sha');

        if (! $this->isSha($expected) || ! $this->isSha($observed)) {
            return $this->unknown('expected or observed commit SHA is missing or malformed.');
        }

        $expected = strtolower((string) $expected);
        $observed = strtolower((string) $observed);

        if (! hash_equals($expected, $observed)) {
            return $this->fail("observed commit {$observed} does not match expected commit {$expected}.");
        }

        $wanted = $expectation['commit_sha'] ?? null;
        if ($wanted !== null) {
            if (! $this->isSha($wanted)) {
                return $this->unknown('expected commit SHA supplied for verification is malformed.');
            }
            if (! hash_equals($expected, strtolower((string) $wanted))) {
                return $this->fail("stale evidence — built for commit {$expected} but verification asked about {$wanted}.");
            }
        }

        return $this->pass("commit chain matches at {$observed}.");
    }

    /**
     * @param  array<string, mixed>  $expectation
     *
     * @return array{status: string, detail: string}
     */
    private function checkArtifactChecksum(SentinelEvidenceContract $evidence, array $expectation): array
    {
        $expected = $evidence->get('artifact_checksum_sha256');
        $observed = $evidence->get('observed_artifact_checksum_sha256');

        if (! $this->isSha256($expected) || ! $this->isSha256($observed)) {
            return $this->unknown('expected or observed artifact checksum is missing or malformed.');
        }

        $expected = strtolower((string) $expected);
        $observed = strtolower((string) $observed);

        if (! hash_equals($expected, $observed)) {
            return $this->fail("observed artifact checksum {$observed} does not match expected {$expected}.");
        }

        $wanted = $expectation['artifact_checksum_sha256'] ?? null;
        if ($wanted !== null) {
            if (! $this->isSha256($wanted)) {
                return $this->unknown('expected artifact checksum supplied for verification is malformed.');
            }
            if (! hash_equals($expected, strtolower((string) $wanted))) {
                return $this->fail("stale evidence — built for artifact checksum {$expected} but verification asked about {$wanted}.");
            }
        }

        return $this->pass('artifact checksum matches.');
    }

    /**
     * @param  array<string, mixed>  $expectation
     *
     * @return array{status: string, detail: string}
     */
    private function checkReleaseIdentity(SentinelEvidenceContract $evidence, array $expectation): array
    {
        $release = $evidence->get('release_id');
        $observed = $evidence->get('observed_release_id');

        if (! is_string($release) || $release === '' || ! is_string($observed) || $observed === '') {
            return $this->unknown('release identity is unknown — release_id or observed_release_id missing.');
        }

        if (! hash_equals($release, $observed)) {
            return $this->fail("active runtime reports release {$observed} but evidence is about release {$release}.");
        }

        $wanted = $expectation['release_id'] ?? null;
        if (! is_string($wanted) || $wanted === '') {
            return $this->unknown('no expected release supplied — evidence cannot be attributed to any release.');
        }

        if (! hash_equals($release, $wanted)) {
            return $this->fail("stale evidence — built for release {$release} but verification asked about {$wanted}.");
        }

        return $this->pass("release identity matches at {$release}.");
    }

    /**
     * @param  array<string, mixed>  $expectation
     *
     * @return array{status: string, detail: string}
     */
    private function checkAgentIdentity(SentinelEvidenceContract $evidence, array $expectation): array
    {
        $agent = $evidence->get('agent_id');
        $version = $evidence->get('agent_version');

        if (! is_string($agent) || $agent === '' || ! is_string($version) || $version === '') {
            return $this->unknown('agent identity is unknown — agent_id or agent_version missing.');
        }

        $wanted = $expectation['agent_id'] ?? null;
        if ($wanted !== null && (! is_string($wanted) || ! hash_equals($agent, $wanted))) {
            return $this->fail("evidence came from agent {$agent} but verification expected agent {$wanted}.");
        }

        return $this->pass("agent {$agent} at version {$version}.");
    }

    /** @return array{status: string, detail: string} */
    private function checkRuntimeReleasePath(SentinelEvidenceContract $evidence, array $expectation): array
    {
        $path = $evidence->get('runtime_release_path');
        $deploymentId = $expectation['deployment_id'] ?? null;

        if (! is_string($path) || $path === '' || ! is_string($deploymentId) || $deploymentId === '') {
            return $this->unknown('active runtime release path or expected deployment id is unknown.');
        }

        if (! SentinelReleasePath::isExpectedRelativePath($path, $deploymentId)) {
            return $this->fail('active runtime release path is not the exact expected relative path.');
        }

        return $this->pass('active runtime reports the exact expected relative release path.');
    }

    /** @return array{status: string, detail: string} */
    private function checkRuntimeMarker(SentinelEvidenceContract $evidence): array
    {
        $expected = $evidence->get('expected_runtime_marker');
        $observed = $evidence->get('runtime_marker');

        if (! is_string($expected) || $expected === '' || ! is_string($observed) || $observed === '') {
            return $this->unknown('runtime marker is unknown — expected or observed marker missing.');
        }

        if (! hash_equals($expected, $observed)) {
            return $this->fail("active runtime marker '{$observed}' does not match expected '{$expected}'.");
        }

        return $this->pass('runtime marker matches.');
    }

    /** @return array{status: string, detail: string} */
    private function checkHealth(SentinelEvidenceContract $evidence): array
    {
        $endpoint = $evidence->get('health_endpoint');
        $observed = $evidence->get('health_status');
        $expected = $evidence->get('expected_health_status');
        $expected = is_int($expected) ? $expected : SentinelEvidenceContract::DEFAULT_EXPECTED_HEALTH_STATUS;

        if (! is_string($endpoint) || $endpoint === '') {
            return $this->unknown('health endpoint is unknown.');
        }

        if (! is_int($observed)) {
            return $this->unknown('health status was never observed.');
        }

        if ($observed !== $expected) {
            return $this->fail("health endpoint {$endpoint} returned HTTP {$observed}, expected {$expected}.");
        }

        return $this->pass("health endpoint {$endpoint} returned HTTP {$observed}.");
    }

    /** @return array{status: string, detail: string} */
    private function checkRecordedChecks(SentinelEvidenceContract $evidence): array
    {
        $checks = $evidence->get('checks');

        if (! is_array($checks) || $checks === []) {
            return $this->unknown('no recorded checks in evidence.');
        }

        $failDetail = null;
        $unknownDetail = null;

        foreach ($checks as $id => $check) {
            $status = is_array($check) ? ($check['status'] ?? null) : null;

            if ($status === SentinelEvidenceContract::CHECK_STATUS_FAIL) {
                $failDetail ??= "recorded check '{$id}' failed.";
            } elseif ($status !== SentinelEvidenceContract::CHECK_STATUS_PASS) {
                $unknownDetail ??= "recorded check '{$id}' is not a pass.";
            }
        }

        if ($failDetail !== null) {
            return $this->fail($failDetail);
        }

        if ($unknownDetail !== null) {
            return $this->unknown($unknownDetail);
        }

        return $this->pass(count($checks).' recorded checks all passed.');
    }

    /** @return array{status: string, detail: string} */
    private function checkVerifiedAt(SentinelEvidenceContract $evidence): array
    {
        if (! $evidence->hasParseableVerifiedAt()) {
            return $this->unknown('verified_at is missing or unparseable.');
        }

        return $this->pass('evidence carries a parseable verified_at timestamp.');
    }

    /**
     * @param  array<string, mixed>  $expectation
     *
     * @return array{status: string, detail: string}
     */
    private function checkExpectationAlignment(SentinelEvidenceContract $evidence, array $expectation): array
    {
        $problems = [];
        $known = true;

        foreach (['project_key', 'environment'] as $key) {
            if (! array_key_exists($key, $expectation)) {
                continue;
            }

            $wanted = $expectation[$key];
            $have = $evidence->get($key);

            if (! is_string($wanted) || $wanted === '') {
                return $this->unknown("expectation {$key} is missing.");
            }
            if (! is_string($have) || $have === '') {
                $known = false;

                continue;
            }
            if (! hash_equals($have, $wanted)) {
                $problems[] = "evidence {$key} '{$have}' does not match expected '{$wanted}'";
            }
        }

        if ($problems !== []) {
            return $this->fail(implode('; ', $problems).'.');
        }

        if (! $known) {
            return $this->unknown('evidence project/environment context is missing.');
        }

        return $this->pass('evidence context aligns with the expectation.');
    }

    // ------------------------------------------------------------------
    // File-level verification checks (feature-flagged)
    // ------------------------------------------------------------------

    /**
     * Check file manifest hash match.
     * Returns UNKNOWN when feature is disabled or data is missing.
     * Returns FAIL when hashes don't match.
     */
    private function checkFileManifestHash(SentinelEvidenceContract $evidence, array $expectation): array
    {
        $expected = $evidence->get('expected_file_manifest_hash');
        $observed = $evidence->get('observed_file_manifest_hash');

        if (! is_string($expected) || $expected === '' || ! is_string($observed) || $observed === '') {
            return $this->unknown('expected or observed file manifest hash is missing.');
        }

        $expected = strtolower((string) $expected);
        $observed = strtolower((string) $observed);

        if (! hash_equals($expected, $observed)) {
            return $this->fail("observed file manifest hash {$observed} does not match expected {$expected}.");
        }

        return $this->pass('file manifest hash matches.');
    }

    /**
     * Check file manifest entry count match.
     * Returns UNKNOWN when feature is disabled or data is missing.
     * Returns FAIL when counts don't match.
     */
    private function checkFileManifestEntryCount(SentinelEvidenceContract $evidence, array $expectation): array
    {
        $expected = $evidence->get('expected_file_manifest_entry_count');
        $observed = $evidence->get('observed_file_manifest_entry_count');

        if (! is_int($expected) || ! is_int($observed)) {
            return $this->unknown('expected or observed file manifest entry count is missing.');
        }

        if ($expected !== $observed) {
            return $this->fail("observed file manifest entry count {$observed} does not match expected {$expected}.");
        }

        return $this->pass('file manifest entry count matches.');
    }

    /**
     * Check file manifest total size match.
     * Returns UNKNOWN when feature is disabled or data is missing.
     * Returns FAIL when sizes don't match.
     */
    private function checkFileManifestTotalSize(SentinelEvidenceContract $evidence, array $expectation): array
    {
        $expected = $evidence->get('expected_file_manifest_total_size');
        $observed = $evidence->get('observed_file_manifest_total_size');

        if (! is_int($expected) || ! is_int($observed)) {
            return $this->unknown('expected or observed file manifest total size is missing.');
        }

        if ($expected !== $observed) {
            return $this->fail("observed file manifest total size {$observed} does not match expected {$expected}.");
        }

        return $this->pass('file manifest total size matches.');
    }

    /**
     * Check detailed file comparison via SentinelFileComparisonService.
     * Returns UNKNOWN when data is missing, or comparison is incomplete/stale.
     * Returns FAIL when missing or mismatched required files are found.
     * Returns PASS when all files match.
     */
    private function checkFileComparison(SentinelEvidenceContract $evidence, array $expectation): array
    {
        // A recorded file_comparison check from the collector is authoritative for
        // the detailed-comparison status: an explicitly recorded unknown ("file
        // observation incomplete") must survive even when the manifest hash check
        // independently fails — the hash mismatch still fails the overall verdict
        // through file_manifest_hash_match. Only when nothing was recorded do we
        // recompute the comparison from the manifest metadata.
        $recorded = $evidence->get('checks');
        if (is_array($recorded) && is_array($recorded['file_comparison'] ?? null)) {
            $status = $recorded['file_comparison']['status'] ?? null;
            $recordedDetail = $recorded['file_comparison']['detail'] ?? null;
            $detail = is_string($recordedDetail) && $recordedDetail !== ''
                ? $recordedDetail
                : 'recorded file comparison check.';
            return match ($status) {
                SentinelEvidenceContract::CHECK_STATUS_PASS => $this->pass($detail),
                SentinelEvidenceContract::CHECK_STATUS_FAIL => $this->fail($detail),
                default => $this->unknown($detail),
            };
        }

        $fileComparisonScope = $evidence->get('file_comparison_scope') ?? 'full';

        $expectedManifestHash = $evidence->get('expected_file_manifest_hash');
        $observedManifestHash = $evidence->get('observed_file_manifest_hash');
        $expectedEntryCount = $evidence->get('expected_file_manifest_entry_count');
        $observedEntryCount = $evidence->get('observed_file_manifest_entry_count');
        $expectedTotalSize = $evidence->get('expected_file_manifest_total_size');
        $observedTotalSize = $evidence->get('observed_file_manifest_total_size');

        if (! is_string($expectedManifestHash) || $expectedManifestHash === ''
            || ! is_string($observedManifestHash) || $observedManifestHash === ''
            || ! is_int($expectedEntryCount) || ! is_int($observedEntryCount)
            || ! is_int($expectedTotalSize) || ! is_int($observedTotalSize)) {
            return $this->unknown('file comparison requires complete manifest metadata on both sides.');
        }

        $comparison = new SentinelFileComparisonService(
            [],
            [],
            $expectedManifestHash,
            $observedManifestHash,
            $expectedEntryCount,
            $observedEntryCount,
            $expectedTotalSize,
            $observedTotalSize,
            $fileComparisonScope
        );

        $verdict = $comparison->verdict();

        return match ($verdict) {
            'PASS' => $this->pass("file comparison scope '{$fileComparisonScope}': all files match (manifest hash verified)."),
            'FAIL' => $this->fail("file comparison scope '{$fileComparisonScope}': manifest hash mismatch detected."),
            default => $this->unknown("file comparison scope '{$fileComparisonScope}': {$verdict}."),
        };
    }

    private function isSha(mixed $value): bool
    {
        return is_string($value) && preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/i', $value) === 1;
    }

    private function isSha256(mixed $value): bool
    {
        return is_string($value) && preg_match('/^[0-9a-f]{64}$/i', $value) === 1;
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function pass(string $detail): array
    {
        return ['status' => SentinelEvidenceContract::CHECK_STATUS_PASS, 'detail' => $detail];
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function fail(string $detail): array
    {
        return ['status' => SentinelEvidenceContract::CHECK_STATUS_FAIL, 'detail' => $detail];
    }

    /**
     * @return array{status: string, detail: string}
     */
    private function unknown(string $detail): array
    {
        return ['status' => SentinelEvidenceContract::CHECK_STATUS_UNKNOWN, 'detail' => $detail];
    }
}
