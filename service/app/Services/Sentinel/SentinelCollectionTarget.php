<?php

namespace App\Services\Sentinel;

use DateTimeImmutable;
use InvalidArgumentException;

/**
 * What the operator is asking Sentinel to prove right now: "is THIS
 * release of THIS project in THIS environment the one running, and
 * healthy?".
 *
 * It carries the verification scope (never any credential) and the
 * time/freshness parameters of the collection run:
 *
 *  - collected_at: the single timestamp the whole run is anchored to.
 *    Tests (and auditors) pin it so repeated collection of unchanged
 *    sources is byte-for-byte reproducible.
 *  - max_age_seconds: how old a runtime observation may be before the
 *    evidence counts as stale (-> UNKNOWN, never VERIFIED).
 */
final class SentinelCollectionTarget
{
    public readonly string $projectKey;
    public readonly string $environment;
    public readonly string $releaseId;
    public readonly string $collectedAt;
    public readonly int $maxAgeSeconds;
    public readonly ?string $deploymentPublicId;

    public function __construct(
        string $projectKey,
        string $environment,
        string $releaseId,
        ?string $collectedAt = null,
        int $maxAgeSeconds = 300,
        ?string $deploymentPublicId = null,
    ) {
        $projectKey = trim($projectKey);
        $environment = trim($environment);
        $releaseId = trim($releaseId);

        if ($projectKey === '' || $environment === '' || $releaseId === '') {
            throw new InvalidArgumentException('A Sentinel collection target requires non-empty project_key, environment and release_id.');
        }

        $collectedAt ??= gmdate('Y-m-d\TH:i:s\Z');

        try {
            new DateTimeImmutable($collectedAt);
        } catch (\Throwable) {
            throw new InvalidArgumentException('A Sentinel collection target requires an ISO-8601 collected_at timestamp.');
        }

        if ($maxAgeSeconds < 0) {
            throw new InvalidArgumentException('A Sentinel collection target requires a non-negative max_age_seconds.');
        }

        $this->projectKey = $projectKey;
        $this->environment = $environment;
        $this->releaseId = $releaseId;
        $this->collectedAt = $collectedAt;
        $this->maxAgeSeconds = $maxAgeSeconds;
        $this->deploymentPublicId = $deploymentPublicId !== null && trim($deploymentPublicId) !== '' ? trim($deploymentPublicId) : null;
    }

    /**
     * The expectation this run hands to the existing verifier: the
     * scope the operator asked about. A mismatch between this and what
     * the sources actually report is a FAILED verdict, not a silent
     * re-scope.
     *
     * @return array<string, string>
     */
    public function verifierExpectation(): array
    {
        $expectation = [
            'project_key' => $this->projectKey,
            'environment' => $this->environment,
            'release_id' => $this->releaseId,
        ];

        if ($this->deploymentPublicId !== null) {
            $expectation['deployment_id'] = $this->deploymentPublicId;
        }

        return $expectation;
    }
}
