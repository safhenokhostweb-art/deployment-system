<?php

namespace App\Services\Sentinel;

/**
 * The OBSERVED runtime side — what the runtime actually reports right
 * now: which agent answered, which release the active `current` path
 * resolves to, which marker it carries and which commit is live.
 *
 * Every field is nullable: a source that could not answer leaves it
 * null, which the collector records as an `unknown` check. Missing
 * observation can never become VERIFIED.
 *
 * File-level fields are additive and feature-flagged. When the feature
 * is disabled, they remain null and the verifier treats them as
 * UNKNOWN (never FAIL). When enabled, the verifier performs strict
 * comparison via SentinelFileComparisonService.
 */
final class SentinelRuntimeSnapshot
{
    public function __construct(
        public readonly ?string $agentId,
        public readonly ?string $agentVersion,
        /** Release the active runtime resolves to, as reported by the agent/runtime reader. */
        public readonly ?string $observedReleaseId,
        /** Commit SHA observed inside the active release (e.g. from its immutable release manifest). */
        public readonly ?string $observedCommitSha,
        /** Resolved absolute path of the active release (`current` symlink target). */
        public readonly ?string $currentReleasePath,
        /** Marker observed inside the active release. */
        public readonly ?string $runtimeMarker,
        /** ISO-8601 timestamp of when this observation was made; drives the freshness check. */
        public readonly ?string $observedAt,
        /** File manifest hash from the agent's file observation (feature-flagged). */
        public readonly ?string $fileManifestHash = null,
        /** Number of entries in the observed file manifest. */
        public readonly ?int $fileManifestEntryCount = null,
        /** Total size of files in the observed file manifest. */
        public readonly ?int $fileManifestTotalSize = null,
        /** Status of the file observation: 'verified', 'incomplete', 'mismatch', 'unreadable', 'disabled', 'manifest_missing', etc. */
        public readonly ?string $fileObservationStatus = null,
    ) {
    }
}
