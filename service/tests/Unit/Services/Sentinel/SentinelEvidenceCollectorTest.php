<?php

namespace Tests\Unit\Services\Sentinel;

use App\Services\Sentinel\AgentRuntimeReader;
use App\Services\Sentinel\ArtifactChecksumReader;
use App\Services\Sentinel\IndependentHealthProbe;
use App\Services\Sentinel\ReleaseExpectationProvider;
use App\Services\Sentinel\SentinelArtifactObservation;
use App\Services\Sentinel\SentinelCollectionOutcome;
use App\Services\Sentinel\SentinelCollectionTarget;
use App\Services\Sentinel\SentinelEvidenceCollector;
use App\Services\Sentinel\SentinelHealthObservation;
use App\Services\Sentinel\SentinelReleaseExpectation;
use App\Services\Sentinel\SentinelRuntimeSnapshot;
use App\Services\Sentinel\SentinelSourceUnavailableException;
use Tests\TestCase;
use ReflectionClass;
use ReflectionMethod;
use RuntimeException;

/**
 * Sentinel phase-2: read-only evidence collection over injected source
 * abstractions, verified by the untouched Phase-1 verifier.
 *
 * All sources here are fakes — no database, no network, no Production,
 * no credential, no active agent, no webhook. The fourteen mandatory
 * scenarios:
 *
 *   1  all realistic sources agree               -> VERIFIED
 *   2  unknown agent                            -> UNKNOWN
 *   3  runtime marker missing                   -> UNKNOWN
 *   4  current path missing                     -> UNKNOWN
 *   5  different commit                         -> FAILED
 *   6  different checksum                       -> FAILED
 *   7  different release                        -> FAILED
 *   8  health failure                           -> FAILED
 *   9  stale evidence                           -> UNKNOWN
 *   10 wrong project/environment                -> FAILED
 *   11 no secret in evidence or exceptions
 *   12 no DB write / network mutation
 *   13 timeout/connection failure -> predictable verdict
 *   14 repeated collection -> canonical, stable output
 */
class SentinelEvidenceCollectorTest extends TestCase
{
    private const COLLECTED_AT = '2026-10-06T12:00:00+00:00';
    private const MAX_AGE_SECONDS = 300;

    /** @var array<string, mixed> */
    private array $fixture;

    protected function setUp(): void
    {
        parent::setUp();
        $decoded = json_decode((string) file_get_contents(__DIR__.'/fixtures/sentinel-verifiable.json'), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);
        $this->fixture = $decoded;
    }

    // ------------------------------------------------------------------
    // 1 — everything agrees
    // ------------------------------------------------------------------

    public function test_all_sources_agree_is_verified(): void
    {
        [$collector, $expectations, $runtime, $artifacts, $probe] = $this->collector();

        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::VERIFIED, $outcome->verdict());
        $this->assertNotNull($outcome->evidence());
        $this->assertSame([], $outcome->result()->reasons());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $outcome->result()->evidenceHash());

        // Multi-source: the verdict consulted every source, not one.
        $this->assertSame(1, $expectations->calls);
        $this->assertSame(1, $runtime->calls);
        $this->assertSame(1, $artifacts->calls);
        $this->assertSame(1, $probe->calls);

        // Expected and observed remain separate fields even when equal.
        $evidence = $outcome->evidence()->toArray();
        $this->assertArrayHasKey('expected_commit_sha', $evidence);
        $this->assertArrayHasKey('observed_commit_sha', $evidence);
        $this->assertArrayHasKey('artifact_checksum_sha256', $evidence);
        $this->assertArrayHasKey('observed_artifact_checksum_sha256', $evidence);
        $this->assertArrayHasKey('expected_runtime_marker', $evidence);
        $this->assertArrayHasKey('runtime_marker', $evidence);
    }

    // ------------------------------------------------------------------
    // 2 — unknown agent
    // ------------------------------------------------------------------

    public function test_unknown_agent_is_unknown(): void
    {
        [$collector] = $this->collector(['snapshot' => null]);

        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::UNKNOWN, $outcome->verdict());
        $this->assertFalse($outcome->result()->isFailed());
        $this->assertSame('unknown', $outcome->result()->statusOf('agent_identity'));
    }

    // ------------------------------------------------------------------
    // 3 — runtime marker missing
    // ------------------------------------------------------------------

    public function test_missing_runtime_marker_is_unknown(): void
    {
        [$collector] = $this->collector(['snapshot' => $this->snapshot(['runtimeMarker' => null])]);

        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::UNKNOWN, $outcome->verdict());
        $this->assertSame('unknown', $outcome->result()->statusOf('runtime_marker_match'));
    }

    // ------------------------------------------------------------------
    // 4 — current path missing
    // ------------------------------------------------------------------

    public function test_missing_current_path_is_unknown(): void
    {
        [$collector] = $this->collector(['snapshot' => $this->snapshot(['currentReleasePath' => null])]);

        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::UNKNOWN, $outcome->verdict());
        $this->assertSame('unknown', $outcome->result()->statusOf('runtime_release_path'));
    }

    // ------------------------------------------------------------------
    // 5 — different commit
    // ------------------------------------------------------------------

    public function test_different_commit_is_failed(): void
    {
        [$collector] = $this->collector([
            'snapshot' => $this->snapshot(['observedCommitSha' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef']),
        ]);

        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::FAILED, $outcome->verdict());
        $this->assertSame('fail', $outcome->result()->statusOf('commit_match'));
    }

    // ------------------------------------------------------------------
    // 6 — different checksum
    // ------------------------------------------------------------------

    public function test_different_checksum_is_failed(): void
    {
        [$collector] = $this->collector([
            'artifact' => new SentinelArtifactObservation(str_repeat('0', 63).'1', self::COLLECTED_AT),
        ]);

        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::FAILED, $outcome->verdict());
        $this->assertSame('fail', $outcome->result()->statusOf('artifact_checksum_match'));
    }

    // ------------------------------------------------------------------
    // 7 — different release
    // ------------------------------------------------------------------

    public function test_different_release_is_failed(): void
    {
        [$collector] = $this->collector([
            'snapshot' => $this->snapshot(['observedReleaseId' => 'rel-9999']),
        ]);

        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::FAILED, $outcome->verdict());
        $this->assertSame('fail', $outcome->result()->statusOf('release_identity'));
    }

    // ------------------------------------------------------------------
    // 8 — health failure
    // ------------------------------------------------------------------

    public function test_health_failure_is_failed(): void
    {
        [$collector] = $this->collector([
            'health' => new SentinelHealthObservation(503, self::COLLECTED_AT),
        ]);

        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::FAILED, $outcome->verdict());
        $this->assertSame('fail', $outcome->result()->statusOf('health_response'));
    }

    // ------------------------------------------------------------------
    // 9 — stale evidence
    // ------------------------------------------------------------------

    public function test_stale_evidence_is_unknown(): void
    {
        [$collector] = $this->collector([
            'snapshot' => $this->snapshot(['observedAt' => '2026-10-06T11:00:00+00:00']),
        ]);

        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::UNKNOWN, $outcome->verdict());
        $this->assertFalse($outcome->result()->isVerified());

        $freshness = $outcome->evidence()->toArray()['checks']['freshness'];
        $this->assertSame('unknown', $freshness['status']);
        $this->assertStringContainsString('stale', $freshness['detail']);
    }

    // ------------------------------------------------------------------
    // 10 — wrong project/environment attribution
    // ------------------------------------------------------------------

    public function test_wrong_environment_is_failed(): void
    {
        [$collector] = $this->collector([
            'expectation' => $this->expectation(['environment' => 'production']),
        ]);

        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::FAILED, $outcome->verdict());
        $this->assertSame('fail', $outcome->result()->statusOf('expectation_alignment'));
        $this->assertStringContainsString('environment', implode(' ', $outcome->result()->reasons()));
    }

    // ------------------------------------------------------------------
    // 11 — no secret in evidence or exceptions
    // ------------------------------------------------------------------

    public function test_secret_from_a_source_never_reaches_evidence_or_exceptions(): void
    {
        // Case A: a credential-shaped value in a source value makes the
        // contract refuse to build; the collector fails closed to
        // UNKNOWN without echoing anything.
        [$collector] = $this->collector([
            'snapshot' => $this->snapshot(['runtimeMarker' => 'Bearer abcdefgh12345678']),
        ]);

        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::UNKNOWN, $outcome->verdict());
        $this->assertNull($outcome->evidence());

        $serialized = $outcome->toJson();
        $this->assertStringNotContainsString('abcdefgh12345678', $serialized);
        $this->assertStringNotContainsString('Bearer', $serialized);

        // Case B: a credentialed health endpoint is sanitized on the way
        // into evidence — query credentials and userinfo never enter.
        [$collector] = $this->collector([
            'expectation' => $this->expectation([
                'health_endpoint' => 'https://user:pass123@staging.example.com/health?token=SUPERSECRET#frag',
            ]),
        ]);

        $outcome = $collector->collect($this->target());

        $this->assertNotNull($outcome->evidence());
        $this->assertSame('https://staging.example.com/health', $outcome->evidence()->get('health_endpoint'));

        $serialized = $outcome->toJson();
        $this->assertStringNotContainsString('pass123', $serialized);
        $this->assertStringNotContainsString('SUPERSECRET', $serialized);
    }

    // ------------------------------------------------------------------
    // 12 — no DB write, no network mutation
    // ------------------------------------------------------------------

    public function test_collector_performs_no_db_writes_or_network_mutations(): void
    {
        // Every source interface exposes only read verbs.
        $interfaces = [
            ReleaseExpectationProvider::class,
            AgentRuntimeReader::class,
            ArtifactChecksumReader::class,
            IndependentHealthProbe::class,
        ];

        foreach ($interfaces as $interface) {
            $methods = (new ReflectionClass($interface))->getMethods();
            $this->assertNotEmpty($methods, "{$interface} must declare its read method");

            foreach ($methods as $method) {
                $this->assertDoesNotMatchRegularExpression(
                    '/^(create|store|save|update|delete|insert|write|send|post|put|patch|dispatch|publish|approve|deploy|build|upload|push|merge|commit)/i',
                    $method->getName(),
                    "{$interface}::{$method->getName()} must be read-only",
                );
            }
        }

        // The collector's public surface is construction plus collect().
        $public = array_map(
            static fn (ReflectionMethod $m): string => $m->getName(),
            (new ReflectionClass(SentinelEvidenceCollector::class))->getMethods(ReflectionMethod::IS_PUBLIC),
        );
        sort($public);
        $this->assertSame(['__construct', 'collect'], $public);

        // Code (comments stripped) contains no write/network primitives.
        $source = (string) file_get_contents(__DIR__.'/../../../../app/Services/Sentinel/SentinelEvidenceCollector.php');
        $code = $this->stripComments($source);

        foreach (['DB::', 'Http::', 'Artisan::', 'curl_', 'fsockopen', 'file_put_contents', 'file_get_contents', '->save(', '::create(', '::insert(', '::update(', '::delete(', 'dispatch('] as $token) {
            $this->assertStringNotContainsString($token, $code, "collector code must not contain '{$token}'");
        }

        // And a run only ever advances the fakes' read counters.
        [$collector, $expectations, $runtime, $artifacts, $probe] = $this->collector();
        $collector->collect($this->target());

        $this->assertSame(1, $expectations->calls);
        $this->assertSame(1, $runtime->calls);
        $this->assertSame(1, $artifacts->calls);
        $this->assertSame(1, $probe->calls);
    }

    // ------------------------------------------------------------------
    // 13 — timeout / connection failure -> predictable verdict
    // ------------------------------------------------------------------

    public function test_timeouts_and_connection_failures_become_unknown(): void
    {
        [$collector] = $this->collector([
            'snapshot_throws' => new SentinelSourceUnavailableException('agent connection refused on port 443'),
            'artifact_throws' => new RuntimeException('cURL error 28: Operation timed out after 30000 ms'),
        ]);

        // No exception escapes the collector.
        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::UNKNOWN, $outcome->verdict());
        $this->assertFalse($outcome->result()->isVerified());

        // Adapter messages are never echoed into the outcome.
        $serialized = $outcome->toJson();
        $this->assertStringNotContainsString('cURL error 28', $serialized);
        $this->assertStringNotContainsString('connection refused', $serialized);
    }

    // ------------------------------------------------------------------
    // 14 — repeated collection is canonical and stable
    // ------------------------------------------------------------------

    public function test_repeated_collection_is_canonical_and_stable(): void
    {
        $target = $this->target();

        [$collectorA] = $this->collector();
        [$collectorB] = $this->collector();

        $first = $collectorA->collect($target);
        $second = $collectorB->collect($target);

        $this->assertSame($first->verdict(), $second->verdict());
        $this->assertSame($first->evidence()->toJson(), $second->evidence()->toJson());
        $this->assertSame($first->evidence()->hash(), $second->evidence()->hash());
        $this->assertSame($first->toArray(), $second->toArray());

        // Canonical form is recursively key-sorted regardless of the
        // order sources filled values in.
        $decoded = json_decode($first->evidence()->toJson(), true);
        $this->assertSame(array_keys($first->evidence()->toArray()), array_keys($decoded));
    }

    // ------------------------------------------------------------------
    // Fail-closed extras beyond the fourteen scenarios
    // ------------------------------------------------------------------

    public function test_missing_expected_release_is_unknown(): void
    {
        [$collector, $expectations, $runtime, $artifacts, $probe] = $this->collector(['expectation' => null]);

        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::UNKNOWN, $outcome->verdict());
        $this->assertSame('unknown', $outcome->result()->statusOf('contract_completeness'));

        $releaseAvailable = $outcome->evidence()->toArray()['checks']['release_available'];
        $this->assertSame('unknown', $releaseAvailable['status']);
        $this->assertStringContainsString('no expected release', $releaseAvailable['detail']);

        // Without an expectation there is nothing safe to read downstream.
        $this->assertSame(1, $expectations->calls);
        $this->assertSame(0, $runtime->calls);
        $this->assertSame(0, $artifacts->calls);
        $this->assertSame(0, $probe->calls);
    }

    public function test_wrong_agent_identity_is_failed(): void
    {
        [$collector] = $this->collector([
            'expectation' => $this->expectation(['agent_id' => 'agent-0002']),
        ]);

        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::FAILED, $outcome->verdict());
        $this->assertSame('fail', $outcome->result()->statusOf('agent_identity'));
    }

    public function test_current_path_mismatch_is_failed(): void
    {
        [$collector] = $this->collector([
            'snapshot' => $this->snapshot([
                'currentReleasePath' => '/var/www/deployment-system/releases/dep-9999',
            ]),
        ]);

        $outcome = $collector->collect($this->target());

        $this->assertSame(SentinelCollectionOutcome::FAILED, $outcome->verdict());
        $this->assertSame('fail', $outcome->result()->statusOf('recorded_checks'));

        $pathCheck = $outcome->evidence()->toArray()['checks']['current_path_matches'];
        $this->assertSame('fail', $pathCheck['status']);
        $this->assertStringContainsString('not the exact expected relative release path', $pathCheck['detail']);
    }

    public function test_only_exact_relative_release_path_passes_collector_check(): void
    {
        foreach (['dep-0001', '/srv/releases/dep-0001', 'other/releases/dep-0001', 'releases/../dep-0001', 'releases/dep-0001/extra'] as $path) {
            [$collector] = $this->collector(['snapshot' => $this->snapshot(['currentReleasePath' => $path])]);
            $outcome = $collector->collect($this->target());
            self::assertSame('fail', $outcome->evidence()->toArray()['checks']['current_path_matches']['status'], $path);
        }

        [$collector] = $this->collector(['snapshot' => $this->snapshot(['currentReleasePath' => 'releases/dep-0001'])]);
        self::assertSame('pass', $collector->collect($this->target())->evidence()->toArray()['checks']['current_path_matches']['status']);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    private function target(array $overrides = []): SentinelCollectionTarget
    {
        return new SentinelCollectionTarget(
            $overrides['project_key'] ?? $this->fixture['project_key'],
            $overrides['environment'] ?? $this->fixture['environment'],
            $overrides['release_id'] ?? $this->fixture['release_id'],
            $overrides['collected_at'] ?? self::COLLECTED_AT,
            $overrides['max_age_seconds'] ?? self::MAX_AGE_SECONDS,
            $overrides['deployment_public_id'] ?? 'dep-0001',
        );
    }

    private function expectation(array $overrides = []): SentinelReleaseExpectation
    {
        $f = $this->fixture;

        return new SentinelReleaseExpectation(
            projectKey: $overrides['project_key'] ?? $f['project_key'],
            environment: $overrides['environment'] ?? $f['environment'],
            releaseId: $overrides['release_id'] ?? $f['release_id'],
            commitSha: $overrides['commit_sha'] ?? $f['expected_commit_sha'],
            artifactId: array_key_exists('artifact_id', $overrides) ? $overrides['artifact_id'] : $f['artifact_id'],
            artifactChecksumSha256: array_key_exists('artifact_checksum_sha256', $overrides) ? $overrides['artifact_checksum_sha256'] : $f['artifact_checksum_sha256'],
            expectedRuntimeMarker: $overrides['expected_runtime_marker'] ?? $f['expected_runtime_marker'],
            deploymentPublicId: $overrides['deployment_public_id'] ?? 'dep-0001',
            healthEndpoint: array_key_exists('health_endpoint', $overrides) ? $overrides['health_endpoint'] : $f['health_endpoint'],
            expectedHealthStatus: $overrides['expected_health_status'] ?? $f['expected_health_status'],
            agentId: $overrides['agent_id'] ?? null,
        );
    }

    private function snapshot(array $overrides = []): SentinelRuntimeSnapshot
    {
        $f = $this->fixture;

        return new SentinelRuntimeSnapshot(
            agentId: array_key_exists('agentId', $overrides) ? $overrides['agentId'] : $f['agent_id'],
            agentVersion: array_key_exists('agentVersion', $overrides) ? $overrides['agentVersion'] : $f['agent_version'],
            observedReleaseId: array_key_exists('observedReleaseId', $overrides) ? $overrides['observedReleaseId'] : $f['observed_release_id'],
            observedCommitSha: array_key_exists('observedCommitSha', $overrides) ? $overrides['observedCommitSha'] : $f['observed_commit_sha'],
            currentReleasePath: array_key_exists('currentReleasePath', $overrides) ? $overrides['currentReleasePath'] : $f['runtime_release_path'],
            runtimeMarker: array_key_exists('runtimeMarker', $overrides) ? $overrides['runtimeMarker'] : $f['runtime_marker'],
            observedAt: $overrides['observedAt'] ?? self::COLLECTED_AT,
        );
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array{0: SentinelEvidenceCollector, 1: FakeReleaseExpectationProvider, 2: FakeAgentRuntimeReader, 3: FakeArtifactChecksumReader, 4: FakeIndependentHealthProbe}
     */
    private function collector(array $config = []): array
    {
        $expectation = array_key_exists('expectation', $config)
            ? $config['expectation']
            : $this->expectation();
        $snapshot = array_key_exists('snapshot', $config)
            ? $config['snapshot']
            : $this->snapshot();
        $artifact = array_key_exists('artifact', $config)
            ? $config['artifact']
            : new SentinelArtifactObservation($this->fixture['observed_artifact_checksum_sha256'], self::COLLECTED_AT);
        $health = array_key_exists('health', $config)
            ? $config['health']
            : new SentinelHealthObservation($this->fixture['health_status'], self::COLLECTED_AT);

        $expectations = new FakeReleaseExpectationProvider($expectation, $config['expectation_throws'] ?? null);
        $runtime = new FakeAgentRuntimeReader($snapshot, $config['snapshot_throws'] ?? null);
        $artifacts = new FakeArtifactChecksumReader($artifact, $config['artifact_throws'] ?? null);
        $probe = new FakeIndependentHealthProbe($health, $config['health_throws'] ?? null);

        $collector = new SentinelEvidenceCollector($expectations, $runtime, $artifacts, $probe);

        return [$collector, $expectations, $runtime, $artifacts, $probe];
    }

    private function stripComments(string $source): string
    {
        $code = '';

        foreach (token_get_all($source) as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT, T_WHITESPACE], true)) {
                    continue;
                }
                $code .= $token[1];

                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}

// ---------------------------------------------------------------------------
// Fake read-only adapters (in-memory, zero side effects)
// ---------------------------------------------------------------------------

final class FakeReleaseExpectationProvider implements ReleaseExpectationProvider
{
    public int $calls = 0;

    public function __construct(
        private readonly ?SentinelReleaseExpectation $returns,
        private readonly ?\Throwable $throws = null,
    ) {
    }

    public function expectation(SentinelCollectionTarget $target): ?SentinelReleaseExpectation
    {
        $this->calls++;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->returns;
    }
}

final class FakeAgentRuntimeReader implements AgentRuntimeReader
{
    public int $calls = 0;

    public function __construct(
        private readonly ?SentinelRuntimeSnapshot $returns,
        private readonly ?\Throwable $throws = null,
    ) {
    }

    public function read(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?SentinelRuntimeSnapshot
    {
        $this->calls++;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->returns;
    }
}

final class FakeArtifactChecksumReader implements ArtifactChecksumReader
{
    public int $calls = 0;

    public function __construct(
        private readonly ?SentinelArtifactObservation $returns,
        private readonly ?\Throwable $throws = null,
    ) {
    }

    public function read(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?SentinelArtifactObservation
    {
        $this->calls++;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->returns;
    }
}

final class FakeIndependentHealthProbe implements IndependentHealthProbe
{
    public int $calls = 0;

    public function __construct(
        private readonly ?SentinelHealthObservation $returns,
        private readonly ?\Throwable $throws = null,
    ) {
    }

    public function probe(SentinelCollectionTarget $target, SentinelReleaseExpectation $expectation): ?SentinelHealthObservation
    {
        $this->calls++;

        if ($this->throws !== null) {
            throw $this->throws;
        }

        return $this->returns;
    }
}
