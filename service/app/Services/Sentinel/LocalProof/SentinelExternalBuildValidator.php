<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof;

use RuntimeException;

final class SentinelExternalBuildValidator
{
    public const DISABLED_REASON = 'external_build_handoff_disabled';

    public function validate(string $externalBuildRoot, SentinelProofRunConfig $config, SentinelLocalProofFilesystem $filesystem): never
    {
        throw new RuntimeException(self::DISABLED_REASON);
    }

    public function integrate(string $externalBuildRoot, string $workspacePath, array $outputInventory): never
    {
        throw new RuntimeException(self::DISABLED_REASON);
    }
}