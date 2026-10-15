<?php

namespace App\Services\Sentinel;

use DateTimeImmutable;
use InvalidArgumentException;
use LocalAgent\ArtifactFileManifest;
use LocalAgent\SafeFilesystem;

/**
 * SentinelEvidenceCollector — the read-only orchestrator of one
 * evidence collection run.
 *
 * Chain covered:
 *
 *   Hub Release/Artifact (ReleaseExpectationProvider)
 *   -> artifact bytes   (ArtifactChecksumReader)
 *   -> runtime truth    (AgentRuntimeReader)
 *   -> public health    (IndependentHealthProbe)
 *   -> SentinelEvidenceContract
 *   -> SentinelVerifier (existing, untouched)
 *   -> VERIFIED | FAILED | UNKNOWN
 *
 * Invariants:
 *
 *  - READ-ONLY by construction: this class issues no database writes,
 *    no outbound requests of its own (probing is delegated to the
 *    injected probe), no jobs, no webhooks, no git operations. All
 *    four sources are consulted through narrow interfaces whose only
 *    verbs are read/expect/probe.
 *  - Multi-source: a verdict is never derived from a single source.
 *    Every run consults expectation, artifact checksum, runtime and
 *    health; a source that cannot answer degrades its link to
 *    `unknown`, which fail-closed aggregation turns into UNKNOWN.
 *  - Expected vs observed stay strictly separated when the evidence
 *    document is assembled (expected_* from the expectation provider,
 *    everything else from observations).
 *  - Exceptions thrown by adapters (SentinelSourceUnavailableException
 *    or anything else: timeout, connection failure) are swallowed into
 *    a null observation — a predictable UNKNOWN, never a crash and
 *    never a pass. Adapter messages are never echoed into evidence,
 *    reasons or outcome.
 *  - If collected values cannot be represented as sentinel.v1 (e.g.
 *    credential-shaped content), the run fails closed to UNKNOWN with
 *    a generic reason instead of leaking the value.
 *  - No source data is trusted for the verdict: the existing verifier
 *    recomputes everything from the evidence document.
 */
final class SentinelEvidenceCollector implements SentinelEvidenceCollection
{
    private readonly ReleaseExpectationProvider $expectations;
    private readonly AgentRuntimeReader $runtime;
    private readonly ArtifactChecksumReader $artifacts;
    private readonly IndependentHealthProbe $health;
    private readonly SentinelVerifier $verifier;

    public function __construct(
        ReleaseExpectationProvider $expectations,
        AgentRuntimeReader $runtime,
        ArtifactChecksumReader $artifacts,
        IndependentHealthProbe $health,
        ?SentinelVerifier $verifier = null,
    ) {
        $this->expectations = $expectations;
        $this->runtime = $runtime;
        $this->artifacts = $artifacts;
        $this->health = $health;
        $this->verifier = $verifier ?? new SentinelVerifier();
    }

    public function collect(SentinelCollectionTarget $target): SentinelCollectionOutcome
    {
        // ---- expected side (authoritative Hub records) ----------------
        $expectation = $this->attempt(fn (): ?SentinelReleaseExpectation => $this->expectations->expectation($target));
        $expectation = $expectation instanceof SentinelReleaseExpectation ? $expectation : null;

        // ---- observed side (independent sources) ----------------------
        $artifact = null;
        $snapshot = null;
        $probe = null;

        if ($expectation !== null) {
            $observedArtifact = $this->attempt(fn (): ?SentinelArtifactObservation => $this->artifacts->read($target, $expectation));
            $observedRuntime = $this->attempt(fn (): ?SentinelRuntimeSnapshot => $this->runtime->read($target, $expectation));
            $observedHealth = $this->attempt(fn (): ?SentinelHealthObservation => $this->health->probe($target, $expectation));

            $artifact = $observedArtifact instanceof SentinelArtifactObservation ? $observedArtifact : null;
            $snapshot = $observedRuntime instanceof SentinelRuntimeSnapshot ? $observedRuntime : null;
            $probe = $observedHealth instanceof SentinelHealthObservation ? $observedHealth : null;
        }

        // ---- file-level comparison (feature-flagged) ------------------
        $fileComparisonCheck = $this->checkFileComparison($expectation, $snapshot, $artifact);

        // ---- recorded collection checks (static, secret-free details) -
        $checks = [
            'release_available' => $this->checkReleaseAvailable($expectation),
            'artifact_available' => $this->checkArtifactAvailable($expectation),
            'artifact_checksum_source' => $this->checkArtifactChecksumSource($artifact),
            'agent_identity' => $this->checkAgentIdentity($snapshot),
            'runtime_marker_received' => $this->checkRuntimeMarkerReceived($snapshot),
            'current_path_received' => $this->currentPathReceived($snapshot),
            'current_path_matches' => $this->currentPathMatches($expectation, $snapshot),
            'deployed_release_matches' => $this->deployedReleaseMatches($expectation, $snapshot),
            'deployed_commit_matches' => $this->deployedCommitMatches($expectation, $snapshot),
            'health_probe' => $this->checkHealthProbe($expectation, $probe),
            'freshness' => $this->checkFreshness($target, $snapshot),
            'attribution' => $this->checkAttribution($target, $expectation),
        ];

        // Add file-level check if feature is enabled
        if (config('sentinel.file_verification_enabled', false) === true) {
            $checks['file_comparison'] = $fileComparisonCheck;
        }

        // ---- assemble expected vs observed, strictly separated --------
        $evidenceInput = [
            'evidence_version' => SentinelEvidenceContract::VERSION,
            'project_key' => $expectation?->projectKey ?? $target->projectKey,
            'environment' => $expectation?->environment ?? $target->environment,
            'expected_commit_sha' => $expectation?->commitSha,
            'observed_commit_sha' => $snapshot?->observedCommitSha,
            'artifact_id' => $expectation?->artifactId,
            'artifact_checksum_sha256' => $expectation?->artifactChecksumSha256,
            'observed_artifact_checksum_sha256' => $artifact?->checksumSha256,
            'release_id' => $expectation?->releaseId ?? $target->releaseId,
            'observed_release_id' => $snapshot?->observedReleaseId,
            'agent_id' => $snapshot?->agentId,
            'agent_version' => $snapshot?->agentVersion,
            'runtime_release_path' => $snapshot?->currentReleasePath,
            'expected_runtime_marker' => $expectation?->expectedRuntimeMarker,
            'runtime_marker' => $snapshot?->runtimeMarker,
            'health_endpoint' => $expectation?->healthEndpoint,
            'expected_health_status' => $expectation?->expectedHealthStatus,
            'health_status' => $probe?->status,
            'verified_at' => $target->collectedAt,
            'checks' => $checks,
            'result' => null,
        ];

        // Add file-level fields (feature-flagged)
        if (config('sentinel.file_verification_enabled', false) === true) {
            $evidenceInput['expected_file_manifest_hash'] = $expectation?->expectedFileManifestHash;
            $evidenceInput['observed_file_manifest_hash'] = $snapshot?->fileManifestHash;
            $evidenceInput['expected_file_manifest_entry_count'] = $expectation?->expectedFileManifestEntryCount;
            $evidenceInput['observed_file_manifest_entry_count'] = $snapshot?->fileManifestEntryCount;
            $evidenceInput['expected_file_manifest_total_size'] = $expectation?->expectedFileManifestTotalSize;
            $evidenceInput['observed_file_manifest_total_size'] = $snapshot?->fileManifestTotalSize;
            $evidenceInput['file_comparison_scope'] = $expectation?->fileComparisonScope ?? 'full';
        }

        try {
            $evidence = SentinelEvidenceContract::fromArray($evidenceInput);
        } catch (InvalidArgumentException) {
            // Fail closed: credential-shaped or otherwise unrepresentable
            // source data never enters evidence, and the generic reason
            // above is the only thing reported.
            return SentinelCollectionOutcome::unrepresentable($target->verifierExpectation());
        }

        // ---- verdict: only the existing verifier issues it ------------
        $verifyExpectation = $target->verifierExpectation();
        if ($expectation?->agentId !== null && $expectation->agentId !== '') {
            $verifyExpectation['agent_id'] = $expectation->agentId;
        }

        if ($expectation?->deploymentPublicId !== null) {
            $verifyExpectation['deployment_id'] = $expectation->deploymentPublicId;
        }

        $result = $this->verifier->verify($evidence, $verifyExpectation);

        return SentinelCollectionOutcome::captured($evidence, $result);
    }

    /**
     * Fail-closed source invocation: any failure (explicit
     * unavailability, timeout, connection error, adapter bug) becomes
     * an unobserved source. The adapter's message is never read.
     */
    private function attempt(callable $source): mixed
    {
        try {
            return $source();
        } catch (SentinelSourceUnavailableException) {
            return null;
        } catch (\Throwable) {
            return null;
        }
    }

    // ------------------------------------------------------------------
    // Recorded collection checks
    // ------------------------------------------------------------------

    /** @return array{status: string, detail: string} */
    private function checkReleaseAvailable(?SentinelReleaseExpectation $expectation): array
    {
        return $expectation !== null
            ? $this->pass('expected release resolved from authoritative records.')
            : $this->unknown('no expected release found for this target.');
    }

    /** @return array{status: string, detail: string} */
    private function checkArtifactAvailable(?SentinelReleaseExpectation $expectation): array
    {
        if ($expectation === null) {
            return $this->unknown('expected release context unavailable.');
        }

        return $expectation->hasArtifact()
            ? $this->pass('authoritative artifact recorded for this release.')
            : $this->unknown('no authoritative artifact recorded for this release.');
    }

    /** @return array{status: string, detail: string} */
    private function checkArtifactChecksumSource(?SentinelArtifactObservation $artifact): array
    {
        if ($artifact === null || ! is_string($artifact->checksumSha256) || preg_match('/^[0-9a-f]{64}$/i', $artifact->checksumSha256) !== 1) {
            return $this->unknown('independent artifact checksum observation unavailable.');
        }

        return $this->pass('artifact checksum independently observed.');
    }

    /** @return array{status: string, detail: string} */
    private function checkAgentIdentity(?SentinelRuntimeSnapshot $snapshot): array
    {
        if ($snapshot === null || ! is_string($snapshot->agentId) || $snapshot->agentId === '' || ! is_string($snapshot->agentVersion) || $snapshot->agentVersion === '') {
            return $this->unknown('agent identity was not observed.');
        }

        return $this->pass('agent identity observed from the runtime source.');
    }

    /** @return array{status: string, detail: string} */
    private function checkRuntimeMarkerReceived(?SentinelRuntimeSnapshot $snapshot): array
    {
        if ($snapshot === null || ! is_string($snapshot->runtimeMarker) || $snapshot->runtimeMarker === '') {
            return $this->unknown('runtime marker was not received.');
        }

        return $this->pass('runtime marker received from the runtime source.');
    }

    /** @return array{status: string, detail: string} */
    private function currentPathReceived(?SentinelRuntimeSnapshot $snapshot): array
    {
        if ($snapshot === null || ! is_string($snapshot->currentReleasePath) || $snapshot->currentReleasePath === '') {
            return $this->unknown('active current release path was not received.');
        }

        return $this->pass('active current release path received.');
    }

    /**
     * The active `current` path must equal the canonical relative
     * releases/{deploymentId} contract exactly.
     *
     * @return array{status: string, detail: string}
     */
    private function currentPathMatches(?SentinelReleaseExpectation $expectation, ?SentinelRuntimeSnapshot $snapshot): array
    {
        $path = $snapshot?->currentReleasePath;
        $deploymentId = $expectation?->deploymentPublicId;

        if (! is_string($path) || $path === '' || ! is_string($deploymentId) || $deploymentId === '') {
            return $this->unknown('cannot compare the current path without an observed path and an expected deployment id.');
        }

        if (SentinelReleasePath::isExpectedRelativePath($path, $deploymentId)) {
            return $this->pass('active current path resolves to the expected release directory.');
        }

        return $this->fail('active current path is not the exact expected relative release path.');
    }

    /** @return array{status: string, detail: string} */
    private function deployedReleaseMatches(?SentinelReleaseExpectation $expectation, ?SentinelRuntimeSnapshot $snapshot): array
    {
        $expected = $expectation?->releaseId;
        $observed = $snapshot?->observedReleaseId;

        if (! is_string($expected) || $expected === '' || ! is_string($observed) || $observed === '') {
            return $this->unknown('observed active release identity was not reported.');
        }

        return hash_equals($expected, $observed)
            ? $this->pass('active runtime reports the expected release.')
            : $this->fail('active runtime reports a different release than expected.');
    }

    /** @return array{status: string, detail: string} */
    private function deployedCommitMatches(?SentinelReleaseExpectation $expectation, ?SentinelRuntimeSnapshot $snapshot): array
    {
        $expected = $expectation?->commitSha;
        $observed = $snapshot?->observedCommitSha;

        $valid = static fn (mixed $sha): bool => is_string($sha)
            && preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/i', $sha) === 1;

        if (! $valid($expected) || ! $valid($observed)) {
            return $this->unknown('observed active commit was not reported.');
        }

        return hash_equals(strtolower((string) $expected), strtolower((string) $observed))
            ? $this->pass('active runtime runs the expected commit.')
            : $this->fail('active runtime runs a different commit than expected.');
    }

    /** @return array{status: string, detail: string} */
    private function checkHealthProbe(?SentinelReleaseExpectation $expectation, ?SentinelHealthObservation $probe): array
    {
        if ($expectation === null) {
            return $this->unknown('expected release context unavailable.');
        }

        if (! $expectation->hasHealthEndpoint()) {
            return $this->unknown('no public health endpoint configured for this release.');
        }

        if ($probe === null || ! is_int($probe->status)) {
            return $this->unknown('independent health probe unavailable.');
        }

        if ($probe->status !== $expectation->expectedHealthStatus) {
            return $this->fail("independent health probe observed HTTP {$probe->status}, expected {$expectation->expectedHealthStatus}.");
        }

        return $this->pass("independent health probe observed HTTP {$probe->status}.");
    }

    /** @return array{status: string, detail: string} */
    private function checkFreshness(SentinelCollectionTarget $target, ?SentinelRuntimeSnapshot $snapshot): array
    {
        if ($snapshot === null || ! is_string($snapshot->observedAt) || $snapshot->observedAt === '') {
            return $this->unknown('runtime observation timestamp missing.');
        }

        try {
            $observedAt = new DateTimeImmutable($snapshot->observedAt);
            $collectedAt = new DateTimeImmutable($target->collectedAt);
        } catch (\Throwable) {
            return $this->unknown('runtime observation timestamp unparseable.');
        }

        $age = $collectedAt->getTimestamp() - $observedAt->getTimestamp();

        if ($age < -60) {
            return $this->unknown('runtime observation timestamp lies in the future.');
        }

        if ($age > $target->maxAgeSeconds) {
            return $this->unknown("runtime observation is stale ({$age}s old, max {$target->maxAgeSeconds}s).");
        }

        return $this->pass("runtime observation is fresh ({$age}s old).");
    }

    /**
     * Evidence must belong to the exact project/environment/release the
     * operator asked about.
     *
     * @return array{status: string, detail: string}
     */
    private function checkAttribution(SentinelCollectionTarget $target, ?SentinelReleaseExpectation $expectation): array
    {
        if ($expectation === null) {
            return $this->unknown('expected release context unavailable.');
        }

        $mismatches = [];
        if (! hash_equals($target->projectKey, $expectation->projectKey)) {
            $mismatches[] = 'project_key';
        }
        if (! hash_equals($target->environment, $expectation->environment)) {
            $mismatches[] = 'environment';
        }
        if (! hash_equals($target->releaseId, $expectation->releaseId)) {
            $mismatches[] = 'release_id';
        }

        if ($mismatches !== []) {
            return $this->fail('collected evidence is attributed to a different '.implode(' and ', $mismatches).' than requested.');
        }

        return $this->pass('collected evidence is attributed to the requested project, environment and release.');
    }

    /**
     * Check file-level comparison between expected artifact manifest and observed runtime manifest.
     * This is the core of the file-level verification capability.
     *
     * @return array{status: string, detail: string}
     */
    private function checkFileComparison(?SentinelReleaseExpectation $expectation, ?SentinelRuntimeSnapshot $snapshot, ?SentinelArtifactObservation $artifact): array
    {
        if (! config('sentinel.file_verification_enabled', false)) {
            return $this->unknown('file verification is disabled.');
        }

        if ($expectation === null) {
            return $this->unknown('expected release context unavailable for file comparison.');
        }

        if (! $expectation->hasFileManifest()) {
            return $this->unknown('artifact file manifest not available for comparison.');
        }

        if ($snapshot === null) {
            return $this->unknown('runtime snapshot unavailable for file comparison.');
        }

        // Check file observation status
        $observationStatus = $snapshot->fileObservationStatus ?? 'unknown';
        if ($observationStatus !== 'verified') {
            return $this->unknown("file observation status: {$observationStatus}.");
        }

        $observedManifestHash = $snapshot->fileManifestHash;
        $observedEntryCount = $snapshot->fileManifestEntryCount;
        $observedTotalSize = $snapshot->fileManifestTotalSize;

        if (! is_string($observedManifestHash) || $observedManifestHash === ''
            || ! is_int($observedEntryCount)
            || ! is_int($observedTotalSize)) {
            return $this->unknown('observed file manifest metadata incomplete.');
        }

        $expectedManifestHash = $expectation->expectedFileManifestHash;
        $expectedEntryCount = $expectation->expectedFileManifestEntryCount;
        $expectedTotalSize = $expectation->expectedFileManifestTotalSize;
        $fileComparisonScope = $expectation->fileComparisonScope ?? 'full';

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
            'PASS' => $this->pass('file manifest hash matches — all files verified.'),
            'FAIL' => $this->fail("file manifest hash mismatch: expected {$expectedManifestHash}, observed {$observedManifestHash}."),
            default => $this->unknown("file comparison scope '{$fileComparisonScope}': {$verdict}."),
        };
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
