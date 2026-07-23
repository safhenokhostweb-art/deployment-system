<?php

namespace App\Events;

use App\Models\OperationJob;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 4-K: dispatched when an Agent reports an operation as failed
 * (OperationJobService::reportFailure()).
 */
class OperationFailed
{
    use Dispatchable;

    public function __construct(
        public readonly OperationJob $operationJob,
    ) {
    }
}
