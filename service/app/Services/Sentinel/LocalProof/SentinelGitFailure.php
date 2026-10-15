<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof;

use RuntimeException;
use Throwable;

final class SentinelGitFailure extends RuntimeException
{
    public function __construct(
        string $failureCode,
        public readonly array $gitMetadata,
        ?Throwable $previous = null,
    ) {
        parent::__construct($failureCode, 0, $previous);
    }
}
