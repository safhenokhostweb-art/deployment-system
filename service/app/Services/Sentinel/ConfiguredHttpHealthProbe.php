<?php

namespace App\Services\Sentinel;

use Throwable;

/** Bounded independent GET probe; redirects and credential-bearing URLs are rejected. */
final class ConfiguredHttpHealthProbe implements IndependentHealthProbe
{
    /** @param array<string, mixed> $policy */
    public function __construct(
        private readonly HealthProbeAddressResolver $resolver,
        private readonly PinnedHealthProbeTransport $transport,
        private readonly array $policy,
    ) {
    }

    public function probe(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?SentinelHealthObservation
    {
        $url = $expectation->healthEndpoint;
        if (! $this->validPolicy() || ! is_string($url) || $url === '' || strlen($url) > 2048) {
            return null;
        }

        try {
            $parts = parse_url($url);
        } catch (Throwable) {
            return null;
        }
        if (! is_array($parts) || ! in_array(strtolower((string) ($parts['scheme'] ?? '')), ['http', 'https'], true)
            || empty($parts['host']) || isset($parts['user']) || isset($parts['pass'])
            || isset($parts['query']) || isset($parts['fragment'])) {
            return null;
        }

        $scheme = strtolower((string) $parts['scheme']);
        $host = strtolower(rtrim(trim((string) $parts['host'], '[]'), '.'));
        $port = isset($parts['port']) ? (int) $parts['port'] : ($scheme === 'https' ? 443 : 80);
        if (! in_array($scheme, $this->policy['allowed_schemes'], true)
            || ! in_array($port, $this->policy['allowed_ports'], true)
            || ! in_array($host, $this->normalizedAllowedHosts(), true)
            || ! $this->validHost($host)) {
            return null;
        }

        try {
            $addresses = $this->resolver->resolve($host);
        } catch (Throwable) {
            return null;
        }
        if ($addresses === []) {
            return null;
        }

        $addresses = array_values(array_unique($addresses));
        foreach ($addresses as $address) {
            if (! is_string($address) || ! $this->isPublicAddress($address)) {
                return null;
            }
        }

        try {
            // Pick one validated public answer; CURLOPT_RESOLVE pins curl's
            // actual connection to it, closing the lookup/use DNS race.
            $status = $this->transport->get($url, $host, $port, $addresses[0]);

            return new SentinelHealthObservation($status);
        } catch (Throwable) {
            return null;
        }
    }

    private function validPolicy(): bool
    {
        if (($this->policy['enabled'] ?? false) !== true) {
            return false;
        }

        foreach (['allowed_hosts', 'allowed_schemes', 'allowed_ports'] as $key) {
            if (! isset($this->policy[$key]) || ! is_array($this->policy[$key]) || $this->policy[$key] === []) {
                return false;
            }
        }

        foreach ($this->policy['allowed_schemes'] as $scheme) {
            if (! is_string($scheme) || ! in_array($scheme, ['http', 'https'], true)) {
                return false;
            }
        }
        foreach ($this->policy['allowed_ports'] as $port) {
            if (! is_int($port) || $port < 1 || $port > 65535) {
                return false;
            }
        }

        return true;
    }

    /** @return list<string> */
    private function normalizedAllowedHosts(): array
    {
        $hosts = [];
        foreach ($this->policy['allowed_hosts'] ?? [] as $host) {
            if (is_string($host)) {
                $hosts[] = strtolower(rtrim(trim($host, '[]'), '.'));
            }
        }

        return $hosts;
    }

    private function validHost(string $host): bool
    {
        if (filter_var($host, FILTER_VALIDATE_IP) !== false) {
            return true;
        }

        if (strlen($host) > 253 || $host === '' || str_contains($host, '..')) {
            return false;
        }

        foreach (explode('.', $host) as $label) {
            if (preg_match('/\A[a-z0-9](?:[a-z0-9-]{0,61}[a-z0-9])?\z/i', $label) !== 1) {
                return false;
            }
        }

        return true;
    }

    private function isPublicAddress(string $address): bool
    {
        if (filter_var($address, FILTER_VALIDATE_IP) === false
            || filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE) === false) {
            return false;
        }

        if (filter_var($address, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6) !== false) {
            $packed = inet_pton($address);
            // Only ordinary global-unicast 2000::/3 addresses are eligible.
            // This rejects mapped, compatible, NAT64, link-local, multicast,
            // and other special-use IPv6 address spaces before curl connects.
            return is_string($packed) && (ord($packed[0]) & 0xE0) === 0x20;
        }

        return true;
    }
}
