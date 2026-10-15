<?php

namespace App\Services\Sentinel;

interface PinnedHealthProbeTransport
{
    /** Makes one bounded GET while pinning the connection to the supplied validated address. */
    public function get(string $url, string $host, int $port, string $address): int;
}
