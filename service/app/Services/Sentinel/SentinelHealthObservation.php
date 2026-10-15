<?php

namespace App\Services\Sentinel;

/**
 * Result of an INDEPENDENT public health probe performed by Sentinel
 * itself (no agent report, no dashboard message). `status` is the HTTP
 * status actually observed; null means the probe could not complete
 * (timeout, connection failure) and must map to UNKNOWN.
 */
final class SentinelHealthObservation
{
    public function __construct(
        public readonly ?int $status,
        public readonly ?string $observedAt = null,
    ) {
    }
}
