<?php

namespace App\Events;

use App\Models\Deployment;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Task 7 (Phase 3-D): notification foundation only — no listener is
 * registered for any event in this file. Dispatching them is a no-op
 * until a future phase attaches a real provider (email/Slack/etc.).
 */
class DeploymentStarted
{
    use Dispatchable;

    public function __construct(
        public readonly Deployment $deployment,
    ) {
    }
}
