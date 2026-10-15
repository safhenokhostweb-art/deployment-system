<?php

namespace App\Services\Sentinel;

/**
 * READ-ONLY source of the expected side of the chain: resolves a
 * collection target to everything the Hub authoritatively believes
 * about that release (commit, Artifact checksum, expected runtime
 * marker, deployment id, health endpoint, expected agent).
 *
 * Implementations must only read (Eloquent reads, config reads). They
 * must never queue jobs, create rows, send webhooks or mutate any
 * state. Returning null means "no expected release exists for this
 * target" — the collector maps that to UNKNOWN, never to a pass.
 */
interface ReleaseExpectationProvider
{
    public function expectation(SentinelCollectionTarget $target): ?SentinelReleaseExpectation;
}
