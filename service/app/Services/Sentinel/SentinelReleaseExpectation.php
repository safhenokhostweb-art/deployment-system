<?php

namespace App\Services\Sentinel;

/**
 * The EXPECTED side of the chain — everything the Hub believes should
 * be true, read from authoritative records (Release, Artifact,
 * Deployment, Project health config).
 *
 * Never mixed with observed values: SentinelEvidenceContract keeps
 * expected_* and observed_* fields strictly apart, and this object is
 * the only producer of the expected half.
 *
 * File-level fields are additive and feature-flagged. When the feature
 * is disabled, they remain null.
 */
final class SentinelReleaseExpectation
{
    public function __construct(
        public readonly string $projectKey,
        public readonly string $environment,
        public readonly string $releaseId,
        /** Expected commit (the Release's commit_hash). */
        public readonly string $commitSha,
        /** Authoritative Artifact record id for this release, if one exists. */
        public readonly ?string $artifactId,
        /** Checksum recorded on the authoritative Artifact record. */
        public readonly ?string $artifactChecksumSha256,
        /**
         * The runtime marker the active release must carry. Protocol
         * owner is the provider; phase-3 default format is
         * `release:{deployment_public_id}|commit:{commit_sha}` matching
         * the Phase-1 fixture.
         */
        public readonly string $expectedRuntimeMarker,
        /** Deployment public_id — the directory name under `releases/` that `current` must resolve to. */
        public readonly ?string $deploymentPublicId,
        /** Public health endpoint configured for this project/environment, if any. */
        public readonly ?string $healthEndpoint,
        public readonly int $expectedHealthStatus,
        /** The agent that is expected to serve this release, when known. */
        public readonly ?string $agentId = null,
        /** File manifest hash from the artifact record (feature-flagged). */
        public readonly ?string $expectedFileManifestHash = null,
        /** Number of entries in the artifact's file manifest. */
        public readonly ?int $expectedFileManifestEntryCount = null,
        /** Total size of files in the artifact's file manifest. */
        public readonly ?int $expectedFileManifestTotalSize = null,
        /** File comparison scope (e.g., 'full', 'source_only'). */
        public readonly ?string $fileComparisonScope = null,
    ) {
    }

    public function hasArtifact(): bool
    {
        return $this->artifactId !== null && $this->artifactChecksumSha256 !== null;
    }

    public function hasHealthEndpoint(): bool
    {
        return $this->healthEndpoint !== null && $this->healthEndpoint !== '';
    }

    public function hasFileManifest(): bool
    {
        return $this->expectedFileManifestHash !== null
            && $this->expectedFileManifestEntryCount !== null
            && $this->expectedFileManifestTotalSize !== null;
    }
}
