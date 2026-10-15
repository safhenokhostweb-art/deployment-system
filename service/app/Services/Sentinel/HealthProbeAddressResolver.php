<?php

namespace App\Services\Sentinel;

interface HealthProbeAddressResolver
{
    /** @return list<string> Resolved A and AAAA addresses; empty means failure. */
    public function resolve(string $host): array;
}
