<?php

namespace App\Services\Sentinel;

final class SystemHealthProbeAddressResolver implements HealthProbeAddressResolver
{
    public function resolve(string $host): array
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return [$host];
        }

        $records = @dns_get_record($host, DNS_A | DNS_AAAA);
        if (! is_array($records) || $records === []) {
            return [];
        }

        $addresses = [];
        foreach ($records as $record) {
            $address = $record['ip'] ?? $record['ipv6'] ?? null;
            if (is_string($address) && $address !== '') {
                $addresses[] = $address;
            }
        }

        return array_values(array_unique($addresses));
    }
}
