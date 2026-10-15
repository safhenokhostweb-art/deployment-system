<?php

namespace App\Services\Sentinel;

/**
 * INDEPENDENT public health probe: Sentinel hits the public endpoint
 * itself and reports the HTTP status it observed — never an agent's
 * claim, never a dashboard message, never an HTTP 200 taken on faith
 * (the observed status is always compared against the expected status
 * for the release under scrutiny).
 *
 * Distinct from `App\Services\HealthCheck\HealthCheckService`, which
 * is bound to a DeploymentTask and WRITES a `DeploymentHealthCheck`
 * row plus an audit entry — unusable for a strictly read-only
 * collector. A Sentinel probe performs a plain GET and writes nothing.
 *
 * Returning null (or throwing SentinelSourceUnavailableException) on
 * timeout/connection failure maps to UNKNOWN.
 */
interface IndependentHealthProbe
{
    public function probe(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?SentinelHealthObservation;
}
