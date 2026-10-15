<?php

namespace Tests\Unit\Services\Sentinel;

use App\Services\Sentinel\SentinelEvidenceContract;
use App\Services\Sentinel\SentinelVerificationResult;
use App\Services\Sentinel\SentinelVerifier;
use InvalidArgumentException;
use Tests\TestCase;

/**
 * Sentinel phase-1 vertical slice: contract + verifier, fully
 * independent of UI, database, network and every Production flow.
 *
 * Covers the twelve mandatory scenarios:
 *   1  all evidence correct                    -> VERIFIED
 *   2  wrong commit                            -> FAILED
 *   3  wrong artifact checksum                 -> FAILED
 *   4  wrong runtime marker                    -> FAILED
 *   5  health status != expected               -> FAILED
 *   6  incomplete evidence                     -> UNKNOWN
 *   7  unknown release_id                      -> UNKNOWN
 *   8  unknown agent identity                  -> UNKNOWN
 *   9  secrets never appear in evidence output
 *   10 stable ordering and version
 *   11 hash changes when any field changes
 *   12 stale evidence rejected for a new release
 */
class SentinelVerifierTest extends TestCase
{
    private SentinelVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new SentinelVerifier();
    }

    // ------------------------------------------------------------------
    // 1 — everything matches
    // ------------------------------------------------------------------

    public function test_all_evidence_correct_is_verified(): void
    {
        $evidence = $this->evidence();
        $result = $this->verifier->verify($evidence, $this->expectation());

        $this->assertSame(SentinelVerificationResult::VERIFIED, $result->result());
        $this->assertTrue($result->isVerified());
        $this->assertFalse($result->isFailed());
        $this->assertFalse($result->isUnknown());

        foreach ($result->checks() as $id => $check) {
            $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_PASS, $check['status'], "check '{$id}' must pass for VERIFIED");
        }

        $this->assertSame([], $result->reasons());
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $result->evidenceHash());

        // Stamping is immutable: the source document stays untouched.
        $stamped = $result->stamp($evidence);
        $this->assertSame(SentinelEvidenceContract::RESULT_VERIFIED, $stamped->get('result'));
        $this->assertNull($evidence->get('result'));
        $this->assertNotSame($evidence->hash(), $stamped->hash());
    }

    // ------------------------------------------------------------------
    // 2 — wrong commit
    // ------------------------------------------------------------------

    public function test_wrong_commit_fails(): void
    {
        $result = $this->verifier->verify(
            $this->evidence(['observed_commit_sha' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef']),
            $this->expectation(),
        );

        $this->assertSame(SentinelVerificationResult::FAILED, $result->result());
        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_FAIL, $result->statusOf('commit_match'));
        $this->assertStringContainsString('does not match expected commit', implode(' ', $result->reasons()));
    }

    // ------------------------------------------------------------------
    // 3 — wrong artifact checksum
    // ------------------------------------------------------------------

    public function test_wrong_artifact_checksum_fails(): void
    {
        $result = $this->verifier->verify(
            $this->evidence(['observed_artifact_checksum_sha256' => str_repeat('0', 63).'1']),
            $this->expectation(),
        );

        $this->assertSame(SentinelVerificationResult::FAILED, $result->result());
        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_FAIL, $result->statusOf('artifact_checksum_match'));
    }

    // ------------------------------------------------------------------
    // 4 — wrong runtime marker
    // ------------------------------------------------------------------

    public function test_wrong_runtime_marker_fails(): void
    {
        $result = $this->verifier->verify(
            $this->evidence(['runtime_marker' => 'release:rel-9999|commit:deadbeefdeadbeefdeadbeefdeadbeefdeadbeef']),
            $this->expectation(),
        );

        $this->assertSame(SentinelVerificationResult::FAILED, $result->result());
        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_FAIL, $result->statusOf('runtime_marker_match'));
    }

    // ------------------------------------------------------------------
    // 5 — health status is not the expected 200
    // ------------------------------------------------------------------

    public function test_non_200_health_status_fails(): void
    {
        $result = $this->verifier->verify(
            $this->evidence(['health_status' => 503]),
            $this->expectation(),
        );

        $this->assertSame(SentinelVerificationResult::FAILED, $result->result());
        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_FAIL, $result->statusOf('health_response'));
        $this->assertStringContainsString('HTTP 503', implode(' ', $result->reasons()));
    }

    // ------------------------------------------------------------------
    // 6 — incomplete evidence
    // ------------------------------------------------------------------

    public function test_incomplete_evidence_is_unknown_not_failed(): void
    {
        $result = $this->verifier->verify(
            SentinelEvidenceContract::fromArray($this->fixture('sentinel-incomplete.json')),
            $this->expectation(),
        );

        $this->assertSame(SentinelVerificationResult::UNKNOWN, $result->result());
        $this->assertTrue($result->isUnknown());
        $this->assertFalse($result->isVerified());
        $this->assertFalse($result->isFailed());
        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_UNKNOWN, $result->statusOf('contract_completeness'));
        $this->assertStringContainsString('incomplete evidence', implode(' ', $result->reasons()));

        // UNKNOWN and FAILED are genuinely different verdicts.
        $this->assertNotSame(SentinelVerificationResult::FAILED, SentinelVerificationResult::UNKNOWN);
    }

    // ------------------------------------------------------------------
    // 7 — release id unknown
    // ------------------------------------------------------------------

    public function test_unknown_release_id_is_unknown(): void
    {
        $result = $this->verifier->verify(
            $this->evidence([], ['release_id']),
            $this->expectation(),
        );

        $this->assertSame(SentinelVerificationResult::UNKNOWN, $result->result());
        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_UNKNOWN, $result->statusOf('release_identity'));
        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_UNKNOWN, $result->statusOf('contract_completeness'));
    }

    // ------------------------------------------------------------------
    // 8 — agent identity unknown
    // ------------------------------------------------------------------

    public function test_unknown_agent_identity_is_unknown(): void
    {
        $result = $this->verifier->verify(
            $this->evidence(['agent_id' => null, 'agent_version' => null]),
            $this->expectation(),
        );

        $this->assertSame(SentinelVerificationResult::UNKNOWN, $result->result());
        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_UNKNOWN, $result->statusOf('agent_identity'));
    }

    // ------------------------------------------------------------------
    // 9 — secrets never appear in evidence
    // ------------------------------------------------------------------

    public function test_credentialed_health_endpoint_is_sanitized(): void
    {
        $evidence = $this->evidence([
            'health_endpoint' => 'https://user:s3cr3tPASS@staging.example.com/health?token=SUPERSECRETTOKEN#frag',
        ]);

        $json = $evidence->toJson();

        $this->assertSame('https://staging.example.com/health', $evidence->get('health_endpoint'));
        $this->assertStringNotContainsString('s3cr3tPASS', $json);
        $this->assertStringNotContainsString('SUPERSECRETTOKEN', $json);
        $this->assertStringNotContainsString('#frag', $json);

        // The sanitized endpoint is still fully usable evidence.
        $result = $this->verifier->verify($evidence, $this->expectation());
        $this->assertSame(SentinelVerificationResult::VERIFIED, $result->result());
    }

    public function test_secret_bearing_field_names_are_rejected(): void
    {
        $base = $this->fixture('sentinel-verifiable.json');
        $base['api_token'] = 'sk-live-XYZ123456789';

        try {
            SentinelEvidenceContract::fromArray($base);
            $this->fail('a credential-named field must be rejected outright');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('api_token', $e->getMessage());
            $this->assertStringNotContainsString('sk-live-XYZ123456789', $e->getMessage());
        }
    }

    public function test_secret_shaped_values_are_rejected(): void
    {
        $base = $this->fixture('sentinel-verifiable.json');
        $base['checks']['auth_trace'] = [
            'status' => 'pass',
            'detail' => 'Bearer abcdefgh12345678 was presented',
        ];

        try {
            SentinelEvidenceContract::fromArray($base);
            $this->fail('credential-shaped content must be rejected');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('credential-shaped', $e->getMessage());
            $this->assertStringNotContainsString('abcdefgh12345678', $e->getMessage());
        }
    }

    // ------------------------------------------------------------------
    // 10 — stable ordering and version
    // ------------------------------------------------------------------

    public function test_evidence_ordering_and_version_are_stable(): void
    {
        $canonical = $this->evidence();

        $this->assertSame(SentinelEvidenceContract::VERSION, $canonical->get('evidence_version'));
        $this->assertTrue($canonical->isSupportedVersion());

        // Key order in the input must not influence the document.
        $shuffled = SentinelEvidenceContract::fromArray($this->shuffleKeys($this->fixture('sentinel-verifiable.json')));
        $this->assertSame($canonical->toArray(), $shuffled->toArray());
        $this->assertSame($canonical->toJson(), $shuffled->toJson());
        $this->assertSame($canonical->hash(), $shuffled->hash());

        // Serialization is deterministic and round-trips losslessly.
        $this->assertSame($canonical->toJson(), $canonical->toJson());
        $reloaded = SentinelEvidenceContract::fromJson($canonical->toJson());
        $this->assertSame($canonical->hash(), $reloaded->hash());
        $this->assertSame($canonical->toArray(), $reloaded->toArray());

        // Canonical JSON is recursively key-sorted.
        $decoded = json_decode($canonical->toJson(), true);
        $this->assertSame($this->sortedKeys($canonical->toArray()), array_keys($decoded));
    }

    // ------------------------------------------------------------------
    // 11 — hash covers every field
    // ------------------------------------------------------------------

    public function test_hash_changes_when_any_field_changes(): void
    {
        $base = $this->fixture('sentinel-verifiable.json');
        $baseHash = SentinelEvidenceContract::fromArray($base)->hash();

        $mutations = [
            'evidence_version' => 'sentinel.v2',
            'project_key' => 'other-project',
            'environment' => 'production',
            'expected_commit_sha' => '0000000000000000000000000000000000000001',
            'observed_commit_sha' => '0000000000000000000000000000000000000002',
            'artifact_id' => 'art-0002',
            'artifact_checksum_sha256' => str_repeat('0', 63).'1',
            'observed_artifact_checksum_sha256' => str_repeat('0', 63).'2',
            'release_id' => 'rel-0002',
            'observed_release_id' => 'rel-0002',
            'agent_id' => 'agent-0002',
            'agent_version' => '1.5.1',
            'runtime_release_path' => '/var/www/deployment-system/releases/dep-0002',
            'expected_runtime_marker' => 'release:rel-0002|commit:0000000000000000000000000000000000000001',
            'runtime_marker' => 'release:rel-0002|commit:0000000000000000000000000000000000000001',
            'health_endpoint' => 'https://staging.example.com/healthz',
            'expected_health_status' => 201,
            'health_status' => 201,
            'checks' => ['source_provenance' => ['status' => 'pass', 'detail' => 'mutated detail']],
            'verified_at' => '2026-10-06T11:00:00+00:00',
            'result' => 'FAILED',
        ];

        $this->assertSame(
            array_keys($mutations),
            array_intersect(array_keys($mutations), SentinelEvidenceContract::ALLOWED_FIELDS),
            'the mutation table must cover fields that exist in the contract',
        );

        foreach ($mutations as $field => $value) {
            $mutated = SentinelEvidenceContract::fromArray(array_replace($base, [$field => $value]));
            $this->assertNotSame(
                $baseHash,
                $mutated->hash(),
                "changing '{$field}' must change the evidence hash",
            );
        }
    }

    // ------------------------------------------------------------------
    // 12 — stale evidence for a new release
    // ------------------------------------------------------------------

    public function test_stale_evidence_for_new_release_is_not_accepted(): void
    {
        // Evidence was produced for rel-0001; the release under scrutiny
        // today is rel-0002.
        $result = $this->verifier->verify(
            $this->evidence(),
            $this->expectation(['release_id' => 'rel-0002']),
        );

        $this->assertSame(SentinelVerificationResult::FAILED, $result->result());
        $this->assertFalse($result->isVerified());
        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_FAIL, $result->statusOf('release_identity'));
        $this->assertStringContainsString('stale evidence', implode(' ', $result->reasons()));
    }

    // ------------------------------------------------------------------
    // Fail-closed guarantees beyond the twelve scenarios
    // ------------------------------------------------------------------

    public function test_a_stored_verified_result_never_fakes_a_green(): void
    {
        // A document that CLAIMS VERIFIED but whose observed commit is
        // wrong must still verify as FAILED.
        $evidence = $this->evidence(['observed_commit_sha' => 'deadbeefdeadbeefdeadbeefdeadbeefdeadbeef'])
            ->withResult(SentinelEvidenceContract::RESULT_VERIFIED);

        $this->assertSame(SentinelEvidenceContract::RESULT_VERIFIED, $evidence->get('result'));

        $result = $this->verifier->verify($evidence, $this->expectation());

        $this->assertSame(SentinelVerificationResult::FAILED, $result->result());
    }

    public function test_verification_without_an_expected_release_is_unknown(): void
    {
        $result = $this->verifier->verify($this->evidence());

        $this->assertSame(SentinelVerificationResult::UNKNOWN, $result->result());
        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_UNKNOWN, $result->statusOf('release_identity'));
    }

    public function test_recorded_failed_check_fails_the_verdict(): void
    {
        $evidence = $this->evidence([
            'checks' => [
                'source_provenance' => ['status' => 'pass', 'detail' => 'ok'],
                'runtime_activation' => ['status' => 'fail', 'detail' => 'marker mismatch observed on disk'],
            ],
        ]);

        $result = $this->verifier->verify($evidence, $this->expectation());

        $this->assertSame(SentinelVerificationResult::FAILED, $result->result());
        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_FAIL, $result->statusOf('recorded_checks'));
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * @return array<string, mixed>
     */
    private function fixture(string $name): array
    {
        $decoded = json_decode((string) file_get_contents(__DIR__.'/fixtures/'.$name), true, 512, JSON_THROW_ON_ERROR);

        $this->assertIsArray($decoded, "fixture {$name} must be a JSON object");

        return $decoded;
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @param  array<int, string>    $remove
     */
    private function evidence(array $overrides = [], array $remove = []): SentinelEvidenceContract
    {
        $base = $this->fixture('sentinel-verifiable.json');

        foreach ($remove as $field) {
            unset($base[$field]);
        }

        return SentinelEvidenceContract::fromArray(array_replace($base, $overrides));
    }

    /**
     * @param  array<string, mixed>  $overrides
     *
     * @return array<string, string>
     */
    private function expectation(array $overrides = []): array
    {
        return array_replace([
            'project_key' => 'deployment-system',
            'environment' => 'staging',
            'release_id' => 'rel-0001',
            'deployment_id' => 'dep-0001',
            'commit_sha' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0',
            'artifact_checksum_sha256' => '9f86d081884c7d659a2feaa0c55ad015a3bf4f1b2b0b822cd15d6c15b0f00a08',
        ], $overrides);
    }

    /**
     * @param  array<int|string, mixed>  $array
     *
     * @return array<int|string, mixed>
     */
    private function shuffleKeys(array $array): array
    {
        $keys = array_keys($array);
        shuffle($keys);

        $out = [];
        foreach ($keys as $key) {
            $value = $array[$key];
            $out[$key] = is_array($value) ? $this->shuffleKeys($value) : $value;
        }

        return $out;
    }

    /**
     * @param  array<int|string, mixed>  $array
     *
     * @return array<int, int|string>
     */
    private function sortedKeys(array $array): array
    {
        $keys = array_keys($array);
        sort($keys, SORT_STRING);

        return $keys;
    }
}
