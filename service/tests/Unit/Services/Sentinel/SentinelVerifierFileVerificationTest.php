<?php

namespace Tests\Unit\Services\Sentinel;

use App\Services\Sentinel\SentinelEvidenceContract;
use App\Services\Sentinel\SentinelVerificationResult;
use App\Services\Sentinel\SentinelVerifier;
use Tests\TestCase;

/**
 * SentinelVerifier file-level verification tests — covers the
 * additive file-level checks when the feature flag is enabled.
 */
class SentinelVerifierFileVerificationTest extends TestCase
{
    private SentinelVerifier $verifier;

    protected function setUp(): void
    {
        parent::setUp();
        $this->verifier = new SentinelVerifier();
    }

    private function baseEvidence(array $overrides = []): SentinelEvidenceContract
    {
        $base = [
            'evidence_version' => 'sentinel.v1',
            'project_key' => 'test-project',
            'environment' => 'staging',
            'expected_commit_sha' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0',
            'observed_commit_sha' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0',
            'artifact_id' => 'art-0001',
            'artifact_checksum_sha256' => str_repeat('0', 64),
            'observed_artifact_checksum_sha256' => str_repeat('0', 64),
            'release_id' => 'rel-0001',
            'observed_release_id' => 'rel-0001',
            'agent_id' => 'agent-0001',
            'agent_version' => '1.4.2',
            'runtime_release_path' => 'releases/dep-0001',
            'expected_runtime_marker' => 'release:dep-0001|commit:a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0',
            'runtime_marker' => 'release:dep-0001|commit:a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0',
            'health_endpoint' => 'https://example.com/health',
            'expected_health_status' => 200,
            'health_status' => 200,
            'verified_at' => '2026-10-15T10:00:00+00:00',
            'checks' => [
                'test_check' => ['status' => 'pass', 'detail' => 'ok'],
            ],
            'result' => null,
        ];

        return SentinelEvidenceContract::fromArray(array_replace($base, $overrides));
    }

    private function baseExpectation(array $overrides = []): array
    {
        return array_replace([
            'project_key' => 'test-project',
            'environment' => 'staging',
            'release_id' => 'rel-0001',
            'deployment_id' => 'dep-0001',
            'commit_sha' => 'a1b2c3d4e5f6a7b8c9d0e1f2a3b4c5d6e7f8a9b0',
            'artifact_checksum_sha256' => str_repeat('0', 64),
        ], $overrides);
    }

    // ------------------------------------------------------------------
    // File manifest hash match
    // ------------------------------------------------------------------

    public function test_file_manifest_hash_match_passes(): void
    {
        $hash = 'a'.str_repeat('0', 63);

        $evidence = $this->baseEvidence([
            'expected_file_manifest_hash' => $hash,
            'observed_file_manifest_hash' => $hash,
        ]);

        // Mock config to enable file verification
        config(['sentinel.file_verification_enabled' => true]);

        $result = $this->verifier->verify($evidence, $this->baseExpectation());

        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_PASS, $result->statusOf('file_manifest_hash_match'));
    }

    public function test_file_manifest_hash_mismatch_fails(): void
    {
        $evidence = $this->baseEvidence([
            'expected_file_manifest_hash' => 'a'.str_repeat('0', 63),
            'observed_file_manifest_hash' => 'b'.str_repeat('0', 63),
        ]);

        config(['sentinel.file_verification_enabled' => true]);

        $result = $this->verifier->verify($evidence, $this->baseExpectation());

        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_FAIL, $result->statusOf('file_manifest_hash_match'));
        $this->assertSame(SentinelVerificationResult::FAILED, $result->result());
    }

    public function test_file_manifest_hash_missing_is_unknown(): void
    {
        $evidence = $this->baseEvidence([
            'expected_file_manifest_hash' => null,
            'observed_file_manifest_hash' => 'a'.str_repeat('0', 63),
        ]);

        config(['sentinel.file_verification_enabled' => true]);

        $result = $this->verifier->verify($evidence, $this->baseExpectation());

        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_UNKNOWN, $result->statusOf('file_manifest_hash_match'));
        $this->assertSame(SentinelVerificationResult::UNKNOWN, $result->result());
    }

    // ------------------------------------------------------------------
    // File manifest entry count match
    // ------------------------------------------------------------------

    public function test_file_manifest_entry_count_match_passes(): void
    {
        $evidence = $this->baseEvidence([
            'expected_file_manifest_entry_count' => 100,
            'observed_file_manifest_entry_count' => 100,
        ]);

        config(['sentinel.file_verification_enabled' => true]);

        $result = $this->verifier->verify($evidence, $this->baseExpectation());

        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_PASS, $result->statusOf('file_manifest_entry_count_match'));
    }

    public function test_file_manifest_entry_count_mismatch_fails(): void
    {
        $evidence = $this->baseEvidence([
            'expected_file_manifest_entry_count' => 100,
            'observed_file_manifest_entry_count' => 99,
        ]);

        config(['sentinel.file_verification_enabled' => true]);

        $result = $this->verifier->verify($evidence, $this->baseExpectation());

        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_FAIL, $result->statusOf('file_manifest_entry_count_match'));
        $this->assertSame(SentinelVerificationResult::FAILED, $result->result());
    }

    // ------------------------------------------------------------------
    // File manifest total size match
    // ------------------------------------------------------------------

    public function test_file_manifest_total_size_match_passes(): void
    {
        $evidence = $this->baseEvidence([
            'expected_file_manifest_total_size' => 1024000,
            'observed_file_manifest_total_size' => 1024000,
        ]);

        config(['sentinel.file_verification_enabled' => true]);

        $result = $this->verifier->verify($evidence, $this->baseExpectation());

        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_PASS, $result->statusOf('file_manifest_total_size_match'));
    }

    public function test_file_manifest_total_size_mismatch_fails(): void
    {
        $evidence = $this->baseEvidence([
            'expected_file_manifest_total_size' => 1024000,
            'observed_file_manifest_total_size' => 1023999,
        ]);

        config(['sentinel.file_verification_enabled' => true]);

        $result = $this->verifier->verify($evidence, $this->baseExpectation());

        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_FAIL, $result->statusOf('file_manifest_total_size_match'));
        $this->assertSame(SentinelVerificationResult::FAILED, $result->result());
    }

    // ------------------------------------------------------------------
    // File comparison check (from recorded collection check)
    // ------------------------------------------------------------------

    public function test_file_comparison_recorded_pass(): void
    {
        $evidence = $this->baseEvidence([
            'expected_file_manifest_hash' => 'a'.str_repeat('0', 63),
            'observed_file_manifest_hash' => 'a'.str_repeat('0', 63),
            'expected_file_manifest_entry_count' => 100,
            'observed_file_manifest_entry_count' => 100,
            'expected_file_manifest_total_size' => 1024000,
            'observed_file_manifest_total_size' => 1024000,
            'file_comparison_scope' => 'full',
            'checks' => [
                'file_comparison' => ['status' => 'pass', 'detail' => 'all files match (manifest hash verified)'],
            ],
        ]);

        config(['sentinel.file_verification_enabled' => true]);

        $result = $this->verifier->verify($evidence, $this->baseExpectation());

        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_PASS, $result->statusOf('file_comparison'));
    }

    public function test_file_comparison_recorded_fail(): void
    {
        $evidence = $this->baseEvidence([
            'expected_file_manifest_hash' => 'a'.str_repeat('0', 63),
            'observed_file_manifest_hash' => 'b'.str_repeat('0', 63),
            'expected_file_manifest_entry_count' => 100,
            'observed_file_manifest_entry_count' => 100,
            'expected_file_manifest_total_size' => 1024000,
            'observed_file_manifest_total_size' => 1024000,
            'file_comparison_scope' => 'full',
            'checks' => [
                'file_comparison' => ['status' => 'fail', 'detail' => 'file manifest hash mismatch: expected a..., observed b...'],
            ],
        ]);

        config(['sentinel.file_verification_enabled' => true]);

        $result = $this->verifier->verify($evidence, $this->baseExpectation());

        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_FAIL, $result->statusOf('file_comparison'));
        $this->assertSame(SentinelVerificationResult::FAILED, $result->result());
    }

    public function test_file_comparison_recorded_unknown(): void
    {
        $evidence = $this->baseEvidence([
            'expected_file_manifest_hash' => 'a'.str_repeat('0', 63),
            'observed_file_manifest_hash' => 'b'.str_repeat('0', 63), // Different - hash check fails
            'expected_file_manifest_entry_count' => 100,
            'observed_file_manifest_entry_count' => 100,
            'expected_file_manifest_total_size' => 1024000,
            'observed_file_manifest_total_size' => 1024000,
            'file_comparison_scope' => 'full',
            'checks' => [
                'file_comparison' => ['status' => 'unknown', 'detail' => 'file observation incomplete'],
            ],
        ]);

        config(['sentinel.file_verification_enabled' => true]);

        $result = $this->verifier->verify($evidence, $this->baseExpectation());

        // Hash mismatch makes file_manifest_hash_match FAIL
        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_FAIL, $result->statusOf('file_manifest_hash_match'));
        // But file_comparison check reflects recorded unknown
        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_UNKNOWN, $result->statusOf('file_comparison'));
        // Overall result is FAILED due to hash mismatch
        $this->assertSame(SentinelVerificationResult::FAILED, $result->result());
    }

    // ------------------------------------------------------------------
    // Feature flag disabled - file checks should not run
    // ------------------------------------------------------------------

    public function test_file_verification_disabled_by_default(): void
    {
        $evidence = $this->baseEvidence();

        // Default config has file_verification_enabled = false
        $result = $this->verifier->verify($evidence, $this->baseExpectation());

        // File-level checks should not be in the checks array
        $checks = $result->checks();
        $this->assertArrayNotHasKey('file_manifest_hash_match', $checks);
        $this->assertArrayNotHasKey('file_manifest_entry_count_match', $checks);
        $this->assertArrayNotHasKey('file_manifest_total_size_match', $checks);
        $this->assertArrayNotHasKey('file_comparison', $checks);
    }

    public function test_file_verification_explicitly_disabled(): void
    {
        config(['sentinel.file_verification_enabled' => false]);

        $evidence = $this->baseEvidence([
            'expected_file_manifest_hash' => 'a'.str_repeat('0', 63),
            'observed_file_manifest_hash' => 'b'.str_repeat('0', 63), // Different!
        ]);

        $result = $this->verifier->verify($evidence, $this->baseExpectation());

        // File-level checks should not run when disabled
        $checks = $result->checks();
        $this->assertArrayNotHasKey('file_manifest_hash_match', $checks);
        // Should still be VERIFIED if everything else passes
        $this->assertSame(SentinelVerificationResult::VERIFIED, $result->result());
    }

    // ------------------------------------------------------------------
    // Manifest hash matches but detailed comparison missing
    // ------------------------------------------------------------------

    public function test_manifest_hash_match_implies_pass(): void
    {
        $hash = 'a'.str_repeat('0', 63);

        $evidence = $this->baseEvidence([
            'expected_file_manifest_hash' => $hash,
            'observed_file_manifest_hash' => $hash,
            'expected_file_manifest_entry_count' => 100,
            'observed_file_manifest_entry_count' => 100,
            'expected_file_manifest_total_size' => 1024000,
            'observed_file_manifest_total_size' => 1024000,
            'file_comparison_scope' => 'full',
            'checks' => [], // No recorded file_comparison check
        ]);

        config(['sentinel.file_verification_enabled' => true]);

        $result = $this->verifier->verify($evidence, $this->baseExpectation());

        // When manifest hashes match, file_comparison should pass
        $this->assertSame(SentinelEvidenceContract::CHECK_STATUS_PASS, $result->statusOf('file_comparison'));
        $checks = $result->checks();
        $this->assertStringContainsString('manifest hash verified', $checks['file_comparison']['detail'] ?? '');
    }
}