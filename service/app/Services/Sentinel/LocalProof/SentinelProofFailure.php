<?php

declare(strict_types=1);

namespace App\Services\Sentinel\LocalProof;

use RuntimeException;
use Throwable;

final class SentinelProofFailure extends RuntimeException
{
    public function __construct(public readonly string $failureStage, Throwable $previous)
    {
        parent::__construct('proof_step_failed', 0, $previous);
    }
}
