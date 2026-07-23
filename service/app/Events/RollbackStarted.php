<?php

namespace App\Events;

use App\Models\Deployment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Phase 4-J Task 2 (completion): dispatched the instant a rollback is
 * queued (RollbackService::rollbackTo()/rollbackTargetTo()), mirroring
 * DeploymentStarted's role for a normal deploy. Purely instrumentation —
 * added at an existing call site, no business logic changed.
 */
class RollbackStarted
{
    use Dispatchable;

    public function __construct(
        public readonly Deployment $deployment,
    ) {
    }
}
