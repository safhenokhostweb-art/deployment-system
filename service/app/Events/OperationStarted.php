<?php

namespace App\Events;

use App\Models\OperationJob;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 4-K: dispatched the instant an Agent claims a queued
 * OperationJob (OperationJobService::claimNext()).
 */
class OperationStarted
{
    use Dispatchable;

    public function __construct(
        public readonly OperationJob $operationJob,
    ) {
    }
}
