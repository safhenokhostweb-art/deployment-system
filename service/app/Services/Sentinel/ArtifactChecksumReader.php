<?php

namespace App\Services\Sentinel;

/**
 * READ-ONLY source of an independent artifact checksum observation —
 * a fresh SHA-256 read of the artifact bytes themselves (the Hub's
 * `ArtifactService` already performs exactly such a physical integrity
 * re-hash before handing artifacts out; a Sentinel adapter reuses that
 * read path without writing anything).
 *
 * Returning null means the checksum could not be independently
 * observed (-> UNKNOWN). A checksum that differs from the recorded
 * Artifact checksum is a FAILED verdict, never a warning.
 */
interface ArtifactChecksumReader
{
    public function read(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?SentinelArtifactObservation;
}
