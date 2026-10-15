<?php

namespace Tests\Unit\Services\Sentinel;

use App\Services\Sentinel\SentinelEvidenceContract;
use App\Services\Sentinel\SentinelReleasePath;
use App\Services\Sentinel\SentinelVerifier;
use PHPUnit\Framework\TestCase;

class SentinelReleasePathTest extends TestCase
{
    public function test_only_the_exact_relative_path_for_the_expected_deployment_passes(): void
    {
        self::assertTrue(SentinelReleasePath::isExpectedRelativePath('releases/dep-0001', 'dep-0001'));

        foreach ([
            'dep-0001',
            '/releases/dep-0001',
            'C:/releases/dep-0001',
            'releases/../dep-0001',
            'alternate/releases/dep-0001',
            'releases/dep-0001/extra',
            'releases/dep-00010',
            'releases/DEP-0001',
        ] as $path) {
            self::assertFalse(SentinelReleasePath::isExpectedRelativePath($path, 'dep-0001'), $path);
        }

        foreach (['../dep-0001', 'dep/0001', 'dep.0001', '', 'x'.str_repeat('a', 128)] as $deploymentId) {
            self::assertFalse(SentinelReleasePath::isExpectedRelativePath('releases/'.$deploymentId, $deploymentId), $deploymentId);
        }
    }

    public function test_verifier_fails_closed_for_each_noncanonical_path(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/fixtures/sentinel-verifiable.json'), true, 512, JSON_THROW_ON_ERROR);
        $verifier = new SentinelVerifier();

        foreach (['dep-0001', '/srv/releases/dep-0001', 'other/releases/dep-0001', 'releases/../dep-0001', 'releases/dep-0001/extra'] as $path) {
            $evidence = SentinelEvidenceContract::fromArray(array_replace($fixture, ['runtime_release_path' => $path]));
            $result = $verifier->verify($evidence, [
                'project_key' => 'deployment-system',
                'environment' => 'staging',
                'release_id' => 'rel-0001',
                'deployment_id' => 'dep-0001',
                'commit_sha' => $fixture['expected_commit_sha'],
                'artifact_checksum_sha256' => $fixture['artifact_checksum_sha256'],
            ]);

            self::assertSame('FAILED', $result->result(), $path);
            self::assertSame('fail', $result->statusOf('runtime_release_path'), $path);
        }
    }

    public function test_verifier_is_inconclusive_when_expected_deployment_id_is_missing(): void
    {
        $fixture = json_decode((string) file_get_contents(__DIR__.'/fixtures/sentinel-verifiable.json'), true, 512, JSON_THROW_ON_ERROR);
        $result = (new SentinelVerifier())->verify(SentinelEvidenceContract::fromArray($fixture), [
            'project_key' => 'deployment-system',
            'environment' => 'staging',
            'release_id' => 'rel-0001',
        ]);

        self::assertSame('UNKNOWN', $result->result());
        self::assertSame('unknown', $result->statusOf('runtime_release_path'));
    }
}
