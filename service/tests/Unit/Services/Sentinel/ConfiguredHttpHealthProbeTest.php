<?php

namespace Tests\Unit\Services\Sentinel;

use App\Services\Sentinel\ConfiguredHttpHealthProbe;
use App\Services\Sentinel\CurlPinnedHealthProbeTransport;
use App\Services\Sentinel\HealthProbeAddressResolver;
use App\Services\Sentinel\PinnedHealthProbeTransport;
use App\Services\Sentinel\SentinelCollectionTarget;
use App\Services\Sentinel\SentinelReleaseExpectation;
use PHPUnit\Framework\TestCase;

class ConfiguredHttpHealthProbeTest extends TestCase
{
    private const PUBLIC_IP = '93.184.216.34';

    public function test_probe_is_disabled_without_explicit_valid_allowlist_policy(): void
    {
        $resolver = new FakeProbeResolver([self::PUBLIC_IP]);
        $transport = new FakeProbeTransport(200);
        $probe = new ConfiguredHttpHealthProbe($resolver, $transport, [
            'enabled' => true,
            'allowed_hosts' => [],
            'allowed_schemes' => ['https'],
            'allowed_ports' => [443],
        ]);

        self::assertNull($probe->probe($this->target(), $this->expectation()));
        self::assertSame(0, $resolver->calls);
        self::assertSame(0, $transport->calls);
    }

    public function test_rejects_unsafe_literal_and_hostname_destinations_offline(): void
    {
        $cases = [
            'localhost' => ['http://localhost/health', ['127.0.0.1']],
            'IPv4 loopback' => ['http://127.0.0.1/health', ['127.0.0.1']],
            'IPv6 loopback' => ['http://[::1]/health', ['::1']],
            'RFC1918' => ['http://192.168.10.20/health', ['192.168.10.20']],
            'IPv6 ULA' => ['http://[fd00::1]/health', ['fd00::1']],
            'IPv4-mapped IPv6' => ['http://[::ffff:127.0.0.1]/health', ['::ffff:127.0.0.1']],
            'NAT64 special range' => ['http://[64:ff9b::a00:1]/health', ['64:ff9b::a00:1']],
            'link-local' => ['http://169.254.10.2/health', ['169.254.10.2']],
            'metadata address' => ['http://169.254.169.254/latest/meta-data/', ['169.254.169.254']],
            'metadata hostname' => ['http://metadata.google.internal/computeMetadata/v1/', ['169.254.169.254']],
            'IPv4 multicast' => ['http://224.0.0.1/health', ['224.0.0.1']],
            'unspecified address' => ['http://0.0.0.0/health', ['0.0.0.0']],
            'IPv6 link-local' => ['http://[fe80::1]/health', ['fe80::1']],
            'IPv6 multicast' => ['http://[ff02::1]/health', ['ff02::1']],
            'private DNS answer' => ['https://health.example.test/health', ['10.0.0.4']],
            'mixed A and AAAA answers' => ['https://health.example.test/health', [self::PUBLIC_IP, 'fd00::2']],
        ];

        foreach ($cases as $label => [$url, $answers]) {
            [$probe, $transport] = $this->probe($url, $answers, $this->hostsFor($url));
            self::assertNull($probe->probe($this->target(), $this->expectation($url)), $label);
            self::assertSame(0, $transport->calls, $label.' must be rejected before connection.');
        }
    }

    public function test_rejects_invalid_url_policy_and_credentials(): void
    {
        $cases = [
            ['ftp://health.example.test/check', 'https', 443, []],
            ['https://health.example.test:8443/check', 'https', 443, []],
            ['https://user:pass@health.example.test/check', 'https', 443, []],
            ['https://health.example.test/check?secret=value', 'https', 443, []],
        ];

        foreach ($cases as [$url, $scheme, $port, $hosts]) {
            [$probe, $transport] = $this->probe($url, [self::PUBLIC_IP], $hosts ?: $this->hostsFor($url), [$scheme], [$port]);
            self::assertNull($probe->probe($this->target(), $this->expectation($url)), $url);
            self::assertSame(0, $transport->calls, $url);
        }

        [$probe, $transport] = $this->probe('https://health.example.test/check', [self::PUBLIC_IP], ['health.example.test'], ['ftp'], [443]);
        self::assertNull($probe->probe($this->target(), $this->expectation('https://health.example.test/check')));
        self::assertSame(0, $transport->calls);
    }

    public function test_redirect_is_observed_but_never_followed_and_timeout_failure_is_unknown(): void
    {
        [$redirectProbe, $redirectTransport] = $this->probe('https://health.example.test/check', [self::PUBLIC_IP], ['health.example.test'], ['https'], [443], 302);
        $redirect = $redirectProbe->probe($this->target(), $this->expectation('https://health.example.test/check'));
        self::assertSame(302, $redirect?->status);
        self::assertSame(1, $redirectTransport->calls, 'Only one request may be made; redirects are never followed.');

        [$timeoutProbe] = $this->probe('https://health.example.test/check', [self::PUBLIC_IP], ['health.example.test'], ['https'], [443], new \RuntimeException('timeout'));
        self::assertNull($timeoutProbe->probe($this->target(), $this->expectation('https://health.example.test/check')));
    }

    public function test_validated_dns_answer_is_pinned_even_if_resolver_would_later_change(): void
    {
        $resolver = new FakeProbeResolver([self::PUBLIC_IP], [['127.0.0.1']]);
        $transport = new FakeProbeTransport(200);
        $probe = new ConfiguredHttpHealthProbe($resolver, $transport, $this->policy(['health.example.test']));

        $observation = $probe->probe($this->target(), $this->expectation('https://health.example.test/check'));

        self::assertSame(200, $observation?->status);
        self::assertSame(1, $resolver->calls, 'The hostname is resolved once for this request.');
        self::assertSame(self::PUBLIC_IP, $transport->address, 'The connection receives the validated address to pin.');
    }

    public function test_malformed_or_missing_destination_policy_fails_closed(): void
    {
        foreach ([[], ['enabled' => true], $this->policy(['health.example.test'], ['allowed_ports' => ['443']])] as $policy) {
            $resolver = new FakeProbeResolver([self::PUBLIC_IP]);
            $transport = new FakeProbeTransport(200);
            $probe = new ConfiguredHttpHealthProbe($resolver, $transport, $policy);
            self::assertNull($probe->probe($this->target(), $this->expectation('https://health.example.test/check')));
            self::assertSame(0, $transport->calls);
        }
    }

    public function test_real_transport_has_strict_time_limits_and_redirects_disabled(): void
    {
        self::assertSame(2, CurlPinnedHealthProbeTransport::CONNECT_TIMEOUT_SECONDS);
        self::assertSame(5, CurlPinnedHealthProbeTransport::TOTAL_TIMEOUT_SECONDS);
        $options = CurlPinnedHealthProbeTransport::requestOptions('health.example.test', 443, self::PUBLIC_IP);
        self::assertSame(2, $options['connect_timeout']);
        self::assertSame(5, $options['timeout']);
        self::assertFalse($options['allow_redirects']);
        self::assertSame(["health.example.test:443:".self::PUBLIC_IP], $options['curl'][CURLOPT_RESOLVE]);
        self::assertSame('*', $options['curl'][CURLOPT_NOPROXY]);

        $ipv6 = CurlPinnedHealthProbeTransport::requestOptions('health.example.test', 443, '2001:4860:4860::8888');
        self::assertSame(['health.example.test:443:[2001:4860:4860::8888]'], $ipv6['curl'][CURLOPT_RESOLVE]);
    }

    private function probe(string $url, array $answers, array $hosts, array $schemes = ['https'], array $ports = [443], mixed $response = 200): array
    {
        $resolver = new FakeProbeResolver($answers);
        $transport = new FakeProbeTransport($response);
        $policy = $this->policy($hosts, ['allowed_schemes' => $schemes, 'allowed_ports' => $ports]);
        return [new ConfiguredHttpHealthProbe($resolver, $transport, $policy), $transport];
    }

    private function policy(array $hosts, array $override = []): array
    {
        return array_replace([
            'enabled' => true,
            'allowed_hosts' => $hosts,
            'allowed_schemes' => ['https'],
            'allowed_ports' => [443],
        ], $override);
    }

    private function hostsFor(string $url): array
    {
        $host = parse_url($url, PHP_URL_HOST);
        return is_string($host) ? [strtolower(trim($host, '[]'))] : [];
    }

    private function target(): SentinelCollectionTarget
    {
        return new SentinelCollectionTarget('project', 'staging', 'release', '2026-10-14T00:00:00Z', 300, 'dep-0001');
    }

    private function expectation(string $url = 'https://health.example.test/check'): SentinelReleaseExpectation
    {
        return new SentinelReleaseExpectation('project', 'staging', 'release', str_repeat('a', 40), null, null, 'marker', 'dep-0001', $url, 200);
    }
}

final class FakeProbeResolver implements HealthProbeAddressResolver
{
    public int $calls = 0;
    public function __construct(private array $answers, private array $nextAnswers = []) {}
    public function resolve(string $host): array
    {
        $this->calls++;
        if ($this->calls > 1 && $this->nextAnswers !== []) {
            return array_shift($this->nextAnswers);
        }
        return $this->answers;
    }
}

final class FakeProbeTransport implements PinnedHealthProbeTransport
{
    public int $calls = 0;
    public ?string $address = null;
    public function __construct(private mixed $response) {}
    public function get(string $url, string $host, int $port, string $address): int
    {
        $this->calls++;
        $this->address = $address;
        if ($this->response instanceof \Throwable) { throw $this->response; }
        return $this->response;
    }
}
