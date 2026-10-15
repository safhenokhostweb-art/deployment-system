<?php

namespace App\Services\Sentinel;

/**
 * The OBSERVED artifact side: an independent SHA-256 read of the
 * artifact bytes (e.g. a physical re-hash of the stored file), as
 * opposed to the checksum merely recorded on the Artifact row.
 *
 * A null observation (source unavailable, file unreadable, source
 * threw) maps to an `unknown` check — never to a pass.
 */
final class SentinelArtifactObservation
{
    public function __construct(
        public readonly ?string $checksumSha256,
        public readonly ?string $observedAt = null,
    ) {
    }
}
