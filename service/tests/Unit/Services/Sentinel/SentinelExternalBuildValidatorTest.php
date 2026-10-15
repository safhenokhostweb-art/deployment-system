<?php

declare(strict_types=1);

namespace Tests\Unit\Services\Sentinel;

use App\Services\Sentinel\LocalProof\SentinelExternalBuildValidator;
use App\Services\Sentinel\LocalProof\SentinelLocalProofFilesystem;
use App\Services\Sentinel\LocalProof\SentinelProofRunConfig;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class SentinelExternalBuildValidatorTest extends TestCase
{
    private string $sourceRoot;
    private SentinelProofRunConfig $config;
    private SentinelExternalBuildValidator $validator;
    private SentinelLocalProofFilesystem $filesystem;

    protected function setUp(): void
    {
        $this->sourceRoot = sys_get_temp_dir() . '/SentinelDisabledSource-' . bin2hex(random_bytes(8));
        if (!mkdir($this->sourceRoot . '/.git', 0700, true) && !is_dir($this->sourceRoot . '/.git')) {
            throw new RuntimeException('test_fixture_source_root_unavailable');
        }
        $this->config = SentinelProofRunConfig::create($this->sourceRoot, str_repeat('a', 40), 'main', 18570, 18571);
        $this->filesystem = new SentinelLocalProofFilesystem($this->config);
        $this->validator = new SentinelExternalBuildValidator();
    }

    protected function tearDown(): void
    {
        foreach ([$this->config->root, $this->sourceRoot] as $root) {
            if (!is_dir($root)) {
                continue;
            }
            $items = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($items as $item) {
                $item->isDir() ? rmdir($item->getPathname()) : unlink($item->getPathname());
            }
            rmdir($root);
        }
    }

    public function test_unsigned_handoff_is_rejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(SentinelExternalBuildValidator::DISABLED_REASON);
        $this->validator->validate('unsigned-handoff', $this->config, $this->filesystem);
    }

    public function test_caller_controlled_public_key_cannot_enable_handoff(): void
    {
        $previousKey = getenv('SENTINEL_EXTERNAL_BUILD_PUBLIC_KEY');
        putenv('SENTINEL_EXTERNAL_BUILD_PUBLIC_KEY=caller-controlled-key');
        try {
            $this->expectException(RuntimeException::class);
            $this->expectExceptionMessage(SentinelExternalBuildValidator::DISABLED_REASON);
            $this->validator->validate('forged-key-handoff', $this->config, $this->filesystem);
        } finally {
            if ($previousKey === false) {
                putenv('SENTINEL_EXTERNAL_BUILD_PUBLIC_KEY');
            } else {
                putenv('SENTINEL_EXTERNAL_BUILD_PUBLIC_KEY=' . $previousKey);
            }
        }
    }

    public function test_signed_looking_handoff_is_still_rejected_without_a_trusted_signer(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(SentinelExternalBuildValidator::DISABLED_REASON);
        $this->validator->validate('signed-looking-handoff', $this->config, $this->filesystem);
    }

    public function test_integration_is_disabled(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage(SentinelExternalBuildValidator::DISABLED_REASON);
        $this->validator->integrate('any-root', 'any-workspace', ['manifest.json']);
    }
}